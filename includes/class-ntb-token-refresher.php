<?php
namespace NTB\StripePMSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Token_Refresher {

	public function __construct() {
		// Primary: fires when user adds a PM via UPE.
		// Priority 9 to run before Stripe's subscriptions trait at priority 10.
		add_action( 'woocommerce_stripe_add_payment_method', [ $this, 'on_payment_method_added' ], 9, 2 );

		// Secondary: fires when token list is retrieved.
		// Priority 11 to run after Stripe plugin's sync at priority 10.
		add_filter( 'woocommerce_get_customer_payment_tokens', [ $this, 'refresh_stale_metadata' ], 11, 3 );
	}

	/**
	 * Primary hook handler.
	 *
	 * Runs when a user adds a payment method. If the new PM shares a fingerprint
	 * with an existing WC token, updates the token's metadata and (safely) its
	 * Stripe PM ID, plus any affected subscription _stripe_source_id references.
	 *
	 * Subscription updates run unconditionally on fingerprint match because the
	 * Stripe gateway and our secondary hook may have already updated the token's
	 * PM ID and metadata before this action fires. Subscriptions store
	 * _stripe_source_id independently and must always be checked.
	 *
	 * @param int      $user_id                WordPress user ID.
	 * @param \stdClass $payment_method_object  Full Stripe PM object.
	 */
	public function on_payment_method_added( $user_id, $payment_method_object ) {
		try {
			// Guard: kill switch.
			if ( get_option( 'ntb_stripe_pm_sync_enabled', 'yes' ) !== 'yes' ) {
				$this->log( 'Kill switch is off — skipping' );
				return;
			}

			// Guard: must be a card.
			if ( ! isset( $payment_method_object->type ) || $payment_method_object->type !== 'card' ) {
				return;
			}

			// Guard: must have a fingerprint.
			if ( empty( $payment_method_object->card->fingerprint ) ) {
				$this->log( 'No fingerprint on incoming PM — skipping' );
				return;
			}

			// Guard: WC_Stripe_Payment_Token_CC must exist for fingerprint methods.
			if ( ! class_exists( 'WC_Stripe_Payment_Token_CC' ) ) {
				$this->log( 'WC_Stripe_Payment_Token_CC class not found — skipping' );
				return;
			}

			$fingerprint = $payment_method_object->card->fingerprint;
			$new_pm_id   = $payment_method_object->id;

			// Retrieve existing WC tokens for this user (explicit limit, not get_customer_tokens).
			$tokens = \WC_Payment_Tokens::get_tokens( [
				'user_id'    => $user_id,
				'gateway_id' => 'stripe',
				'limit'      => 100,
			] );

			// Find the existing token that matches the incoming PM's fingerprint.
			$matching_token = null;
			foreach ( $tokens as $token ) {
				if ( $token instanceof \WC_Stripe_Payment_Token_CC
					&& method_exists( $token, 'get_fingerprint' )
					&& $token->get_fingerprint() === $fingerprint
				) {
					$matching_token = $token;
					break;
				}
			}

			if ( ! $matching_token ) {
				$this->log( 'No matching token found for fingerprint ' . $fingerprint . ' — Stripe sync will create a new token' );
				return;
			}

			$this->log(
				'Fingerprint match on token #' . $matching_token->get_id()
				. ' for user ' . $user_id
				. ' — new PM: ' . $new_pm_id
				. ', fingerprint: ' . $fingerprint
			);

			// Determine new metadata values from the incoming PM.
			$new_exp_month = str_pad( $payment_method_object->card->exp_month, 2, '0', STR_PAD_LEFT );
			$new_exp_year  = (string) $payment_method_object->card->exp_year;
			$new_last4     = $payment_method_object->card->last4;
			$new_card_type = $this->derive_card_type( $payment_method_object );

			// Step A: Update subscriptions that reference any older PM for this card.
			// This runs unconditionally because the Stripe gateway updates the
			// token PM ID and our secondary hook fixes metadata before this action
			// fires — so the token may already be fully up to date, but
			// subscriptions still point to an old PM.
			$stripe_customer_id = isset( $payment_method_object->customer )
				? $payment_method_object->customer
				: '';

			$subs_updated = true;
			try {
				$subs_updated = $this->update_subscriptions(
					$user_id,
					$new_pm_id,
					$stripe_customer_id,
					$fingerprint
				);
			} catch ( \Exception $e ) {
				$subs_updated = false;
				$this->log_error( 'Subscription update threw exception: ' . $e->getMessage() );
			}

			// Step B: Update display metadata if stale (idempotent — may already
			// be correct if the secondary hook ran earlier in this request).
			$old_exp_month = $matching_token->get_expiry_month();
			$old_exp_year  = $matching_token->get_expiry_year();
			$old_last4     = $matching_token->get_last4();
			$old_card_type = $matching_token->get_card_type();

			$metadata_changed = (
				$old_exp_month !== $new_exp_month
				|| $old_exp_year !== $new_exp_year
				|| $old_last4 !== $new_last4
				|| $old_card_type !== $new_card_type
			);

			if ( $metadata_changed ) {
				$matching_token->set_expiry_month( $payment_method_object->card->exp_month );
				$matching_token->set_expiry_year( $payment_method_object->card->exp_year );
				$matching_token->set_last4( $new_last4 );
				$matching_token->set_card_type( $new_card_type );
			}

			// Step C: Update token PM ID if different (conservative rule — only
			// change if subscription updates succeeded).
			$pm_id_changed = ( $matching_token->get_token() !== $new_pm_id );

			if ( $pm_id_changed ) {
				if ( $subs_updated ) {
					$matching_token->set_token( $new_pm_id );
					$this->log( 'Token PM ID updated to ' . $new_pm_id );
				} else {
					$this->log_error(
						'Skipping token PM ID change to ' . $new_pm_id
						. ' because subscription update failed — display metadata updated, PM ID retained for renewal safety'
					);
				}
			}

			// Step D: Save if anything changed.
			if ( $metadata_changed || $pm_id_changed ) {
				$matching_token->save();
				$this->log(
					'Token #' . $matching_token->get_id() . ' saved'
					. ' — expiry: ' . $old_exp_month . '/' . $old_exp_year . ' → ' . $new_exp_month . '/' . $new_exp_year
					. ', last4: ' . $old_last4 . ' → ' . $new_last4
					. ', card_type: ' . $old_card_type . ' → ' . $new_card_type
				);
			} else {
				$this->log( 'Token #' . $matching_token->get_id() . ' metadata already up to date' );

				// Auto-idle: token metadata + PM ID both correct already. If
				// subscriptions also needed no updates, upstream may have fixed it.
				if ( defined( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE' ) && NTB_STRIPE_PM_SYNC_AUTO_IDLE ) {
					$this->log( 'Auto-idle: token already correct — upstream may have fixed the refresh bug' );
				}
			}

		} catch ( \Exception $e ) {
			$this->log_error( 'Primary hook exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Secondary hook handler.
	 *
	 * Catches existing stale metadata for users who haven't re-added their card.
	 * Runs after the Stripe plugin's sync at priority 10, reads the cached Stripe
	 * PM data, and refreshes any mismatched display metadata on WC tokens.
	 *
	 * Does NOT change token PM IDs or update subscription meta — those mutations
	 * are only performed by the primary hook.
	 *
	 * @param array  $tokens     Array of WC_Payment_Token objects.
	 * @param int    $user_id    WordPress user ID.
	 * @param string $gateway_id Gateway ID being queried.
	 * @return array The (potentially modified) tokens array.
	 */
	public function refresh_stale_metadata( $tokens, $user_id, $gateway_id ) {
		try {
			// Guard: kill switch.
			if ( get_option( 'ntb_stripe_pm_sync_enabled', 'yes' ) !== 'yes' ) {
				return $tokens;
			}

			// Guard: if a specific gateway is queried, it must be Stripe.
			if ( ! empty( $gateway_id ) && strpos( $gateway_id, 'stripe' ) !== 0 ) {
				return $tokens;
			}

			// Guard: must have tokens to check.
			if ( empty( $tokens ) ) {
				return $tokens;
			}

			// Guard: once per user per request (function-level static).
			static $refreshed = [];
			if ( isset( $refreshed[ $user_id ] ) ) {
				return $tokens;
			}
			$refreshed[ $user_id ] = true;

			// Guard: must have at least one card token worth checking.
			$has_card_tokens = false;
			foreach ( $tokens as $token ) {
				if ( $token instanceof \WC_Stripe_Payment_Token_CC ) {
					$has_card_tokens = true;
					break;
				}
			}
			if ( ! $has_card_tokens ) {
				return $tokens;
			}

			// Guard: WC_Stripe_Customer must exist for PM fetching.
			if ( ! class_exists( 'WC_Stripe_Customer' ) ) {
				return $tokens;
			}

			// Fetch Stripe PMs (uses transient cache from Stripe plugin's sync).
			$customer = new \WC_Stripe_Customer( $user_id );

			try {
				$stripe_pms = $customer->get_all_payment_methods( [ 'card' ] );
			} catch ( \Exception $e ) {
				$this->log_error( 'Secondary hook: failed to fetch PMs — ' . $e->getMessage() );
				return $tokens;
			}

			if ( empty( $stripe_pms ) ) {
				return $tokens;
			}

			// Build PM map: stripe_pm_id => pm_object.
			$pm_map = [];
			foreach ( $stripe_pms as $pm ) {
				if ( isset( $pm->id ) ) {
					$pm_map[ $pm->id ] = $pm;
				}
			}

			$auto_idle_enabled = defined( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE' ) && NTB_STRIPE_PM_SYNC_AUTO_IDLE;
			$any_updated       = false;

			// Check each WC card token against Stripe data.
			foreach ( $tokens as $token ) {
				if ( ! ( $token instanceof \WC_Stripe_Payment_Token_CC ) ) {
					continue;
				}

				$token_pm_id = $token->get_token();
				if ( ! isset( $pm_map[ $token_pm_id ] ) ) {
					continue;
				}

				$pm = $pm_map[ $token_pm_id ];

				// Prepare Stripe values for comparison (padded/cast to match WC storage format).
				$stripe_exp_month = str_pad( $pm->card->exp_month, 2, '0', STR_PAD_LEFT );
				$stripe_exp_year  = (string) $pm->card->exp_year;
				$stripe_last4     = $pm->card->last4;
				$stripe_card_type = $this->derive_card_type( $pm );

				$needs_refresh = (
					$token->get_expiry_month() !== $stripe_exp_month
					|| $token->get_expiry_year() !== $stripe_exp_year
					|| $token->get_last4() !== $stripe_last4
					|| $token->get_card_type() !== $stripe_card_type
				);

				if ( ! $needs_refresh ) {
					continue;
				}

				$any_updated = true;

				$token->set_expiry_month( $pm->card->exp_month );
				$token->set_expiry_year( $pm->card->exp_year );
				$token->set_last4( $stripe_last4 );
				$token->set_card_type( $stripe_card_type );
				$token->save();

				$this->log(
					'Secondary hook: refreshed token #' . $token->get_id()
					. ' (PM: ' . $token_pm_id . ') metadata from Stripe'
				);
			}

			if ( $auto_idle_enabled && ! $any_updated ) {
				$this->log( 'Secondary hook auto-idle: all token metadata already matches Stripe — idling' );
			}

		} catch ( \Exception $e ) {
			$this->log_error( 'Secondary hook exception: ' . $e->getMessage() );
		}

		return $tokens;
	}

	/**
	 * Updates _stripe_source_id on all active subscriptions where the current
	 * PM has the same card fingerprint as the incoming PM.
	 *
	 * Uses fingerprint matching exclusively (via Stripe API) so it catches
	 * subscriptions stuck on any older PM for the same physical card, not just
	 * the immediately previous one.
	 *
	 * @param int    $user_id             WordPress user ID.
	 * @param string $new_pm_id           New Stripe PM ID.
	 * @param string $stripe_customer_id  Stripe customer ID (e.g., cus_xxx).
	 * @param string $fingerprint         Card fingerprint for matching.
	 * @return bool True on full success, false if any subscription could not be updated.
	 */
	private function update_subscriptions( $user_id, $new_pm_id, $stripe_customer_id, $fingerprint ) {
		// WooCommerce Subscriptions not installed — no subscriptions to update, no-op success.
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			$this->log( 'WooCommerce Subscriptions not available — skipping subscription update' );
			return true;
		}

		$subscriptions = wcs_get_users_subscriptions( $user_id );
		$all_succeeded = true;

		foreach ( $subscriptions as $subscription ) {
			// Only update active, on-hold, or pending subscriptions.
			if ( ! $subscription->has_status( [ 'active', 'on-hold', 'pending' ] ) ) {
				continue;
			}

			$sub_source_id = $subscription->get_meta( '_stripe_source_id', true );
			$sub_id        = $subscription->get_id();

			// Already points to the new PM — nothing to do.
			if ( empty( $sub_source_id ) || $sub_source_id === $new_pm_id ) {
				continue;
			}

			// Verify via Stripe API that this subscription's PM has the same fingerprint.
			if ( ! $this->pm_has_fingerprint( $sub_source_id, $fingerprint ) ) {
				continue;
			}

			$this->log(
				'Subscription #' . $sub_id . ' references PM ' . $sub_source_id
				. ' with matching fingerprint — updating to ' . $new_pm_id
			);

			try {
				// Preferred: use WC Subscriptions API for proper audit trail.
				if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )
					&& method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'update_payment_method' )
				) {
					\WC_Subscriptions_Change_Payment_Gateway::update_payment_method(
						$subscription,
						'stripe',
						[
							'post_meta' => [
								'_stripe_source_id'   => [ 'value' => $new_pm_id ],
								'_stripe_customer_id' => [ 'value' => $stripe_customer_id ],
							],
						]
					);
				} else {
					// Fallback: direct meta update.
					$subscription->update_meta_data( '_stripe_source_id', $new_pm_id );
					if ( $stripe_customer_id ) {
						$subscription->update_meta_data( '_stripe_customer_id', $stripe_customer_id );
					}
					$subscription->save();
				}

				$this->log( 'Updated subscription #' . $sub_id . ' _stripe_source_id from ' . $sub_source_id . ' to ' . $new_pm_id );
			} catch ( \Exception $e ) {
				$this->log_error( 'Failed to update subscription #' . $sub_id . ': ' . $e->getMessage() );
				$all_succeeded = false;
			}
		}

		return $all_succeeded;
	}

	/**
	 * Checks whether a Stripe PM has the given fingerprint.
	 *
	 * Used to identify older PMs for the same physical card when a subscription
	 * is stuck on a PM that predates the current one.
	 *
	 * @param string $pm_id       Stripe PM ID to check.
	 * @param string $fingerprint Expected card fingerprint.
	 * @return bool True if the PM exists in Stripe and has the matching fingerprint.
	 */
	private function pm_has_fingerprint( $pm_id, $fingerprint ) {
		static $cache = [];

		$cache_key = $pm_id . ':' . $fingerprint;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( ! class_exists( 'WC_Stripe_API' ) ) {
			$cache[ $cache_key ] = false;
			return false;
		}

		try {
			$response = \WC_Stripe_API::request( [], 'payment_methods/' . $pm_id, 'GET' );

			if ( is_wp_error( $response ) || ! empty( $response->error ) ) {
				$this->log( 'Could not retrieve PM ' . $pm_id . ' from Stripe for fingerprint check' );
				$cache[ $cache_key ] = false;
				return false;
			}

			$result            = isset( $response->card->fingerprint ) && $response->card->fingerprint === $fingerprint;
			$cache[ $cache_key ] = $result;
			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Fingerprint check failed for PM ' . $pm_id . ': ' . $e->getMessage() );
			$cache[ $cache_key ] = false;
			return false;
		}
	}

	/**
	 * Derive the card type string from a Stripe PM object.
	 *
	 * Follows the same priority chain as the Stripe plugin:
	 * display_brand → networks.preferred → brand.
	 *
	 * @param \stdClass $pm Stripe PM object.
	 * @return string Lowercase card type.
	 */
	private function derive_card_type( $pm ) {
		$brand = '';

		if ( ! empty( $pm->card->display_brand ) ) {
			$brand = $pm->card->display_brand;
		} elseif ( isset( $pm->card->networks->preferred ) && ! empty( $pm->card->networks->preferred ) ) {
			$brand = $pm->card->networks->preferred;
		} elseif ( ! empty( $pm->card->brand ) ) {
			$brand = $pm->card->brand;
		}

		return strtolower( $brand );
	}

	/**
	 * Log a debug message to the WooCommerce logger.
	 *
	 * Only outputs when NTB_STRIPE_PM_SYNC_DEBUG is defined and true.
	 *
	 * @param string $message Log message.
	 */
	private function log( $message ) {
		if ( ! defined( 'NTB_STRIPE_PM_SYNC_DEBUG' ) || ! NTB_STRIPE_PM_SYNC_DEBUG ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->debug( $message, [ 'source' => 'ntb-stripe-pm-sync' ] );
	}

	/**
	 * Log an error message to the WooCommerce logger.
	 *
	 * Only outputs when NTB_STRIPE_PM_SYNC_DEBUG is defined and true.
	 *
	 * @param string $message Error message.
	 */
	private function log_error( $message ) {
		if ( ! defined( 'NTB_STRIPE_PM_SYNC_DEBUG' ) || ! NTB_STRIPE_PM_SYNC_DEBUG ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->error( $message, [ 'source' => 'ntb-stripe-pm-sync' ] );
	}
}
