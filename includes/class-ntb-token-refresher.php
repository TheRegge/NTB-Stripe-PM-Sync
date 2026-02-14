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

			// Determine new metadata values.
			$new_pm_id     = $payment_method_object->id;
			$new_exp_month = str_pad( $payment_method_object->card->exp_month, 2, '0', STR_PAD_LEFT );
			$new_exp_year  = (string) $payment_method_object->card->exp_year;
			$new_last4     = $payment_method_object->card->last4;
			$new_card_type = $this->derive_card_type( $payment_method_object );

			// Store old values for comparison and logging.
			$old_pm_id     = $matching_token->get_token();
			$old_exp_month = $matching_token->get_expiry_month();
			$old_exp_year  = $matching_token->get_expiry_year();
			$old_last4     = $matching_token->get_last4();
			$old_card_type = $matching_token->get_card_type();

			// Check if any field actually differs.
			$needs_update = (
				$old_pm_id !== $new_pm_id
				|| $old_exp_month !== $new_exp_month
				|| $old_exp_year !== $new_exp_year
				|| $old_last4 !== $new_last4
				|| $old_card_type !== $new_card_type
			);

			// Auto-idle: if all 5 fields match and auto-idle is enabled, upstream fixed it.
			if ( defined( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE' ) && NTB_STRIPE_PM_SYNC_AUTO_IDLE ) {
				if ( ! $needs_update ) {
					$this->log( 'Auto-idle: upstream appears to have fixed metadata refresh — skipping mutations' );
					return;
				}
			}

			if ( ! $needs_update ) {
				$this->log( 'Token already up to date for fingerprint ' . $fingerprint );
				return;
			}

			$this->log(
				'Fingerprint match detected for user ' . $user_id
				. ' — old PM: ' . $old_pm_id . ', new PM: ' . $new_pm_id
				. ', fingerprint: ' . $fingerprint
			);

			// Step A: Try to update subscriptions FIRST, before changing the token PM ID.
			$subs_updated  = true;
			$pm_id_changed = ( $old_pm_id !== $new_pm_id );

			if ( $pm_id_changed ) {
				$stripe_customer_id = isset( $payment_method_object->customer )
					? $payment_method_object->customer
					: '';

				try {
					$subs_updated = $this->update_subscriptions(
						$user_id,
						$old_pm_id,
						$new_pm_id,
						$stripe_customer_id
					);
				} catch ( \Exception $e ) {
					$subs_updated = false;
					$this->log_error( 'Subscription update threw exception: ' . $e->getMessage() );
				}
			}

			// Step B: Always update display metadata (even if subscription update failed).
			$matching_token->set_expiry_month( $payment_method_object->card->exp_month );
			$matching_token->set_expiry_year( $payment_method_object->card->exp_year );
			$matching_token->set_last4( $new_last4 );
			$matching_token->set_card_type( $new_card_type );

			// Step C: Only change the token PM ID if subscriptions were successfully updated.
			if ( $pm_id_changed ) {
				if ( $subs_updated ) {
					$matching_token->set_token( $new_pm_id );
					$this->log( 'Token PM ID updated from ' . $old_pm_id . ' to ' . $new_pm_id );
				} else {
					$this->log_error(
						'Skipping token PM ID change from ' . $old_pm_id . ' to ' . $new_pm_id
						. ' because subscription update failed — display metadata updated, PM ID retained for renewal safety'
					);
				}
			}

			// Step D: Single save after all field updates.
			$matching_token->save();

			$this->log(
				'Token #' . $matching_token->get_id() . ' updated'
				. ' — expiry: ' . $old_exp_month . '/' . $old_exp_year . ' → ' . $new_exp_month . '/' . $new_exp_year
				. ', last4: ' . $old_last4 . ' → ' . $new_last4
				. ', card_type: ' . $old_card_type . ' → ' . $new_card_type
			);

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
	 * Updates _stripe_source_id on all active subscriptions that reference the old PM.
	 *
	 * @param int    $user_id             WordPress user ID.
	 * @param string $old_pm_id           Old Stripe PM ID (e.g., pm_xxx).
	 * @param string $new_pm_id           New Stripe PM ID.
	 * @param string $stripe_customer_id  Stripe customer ID (e.g., cus_xxx).
	 * @return bool True on full success, false if any subscription could not be updated.
	 */
	private function update_subscriptions( $user_id, $old_pm_id, $new_pm_id, $stripe_customer_id ) {
		if ( $old_pm_id === $new_pm_id ) {
			return true;
		}

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
			if ( $sub_source_id !== $old_pm_id ) {
				continue;
			}

			$sub_id = $subscription->get_id();

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

				$this->log( 'Updated subscription #' . $sub_id . ' _stripe_source_id from ' . $old_pm_id . ' to ' . $new_pm_id );
			} catch ( \Exception $e ) {
				$this->log_error( 'Failed to update subscription #' . $sub_id . ': ' . $e->getMessage() );
				$all_succeeded = false;
			}
		}

		return $all_succeeded;
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
