<?php
namespace NTB\StripePMSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Diagnostics {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_action( 'admin_init', [ $this, 'handle_form_submission' ] );
	}

	/**
	 * Registers the diagnostics page as a WooCommerce submenu item.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			'Stripe PM Sync Diagnostics',
			'Stripe PM Sync',
			'manage_woocommerce',
			'ntb-stripe-pm-sync',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handles the kill switch toggle form submission (PRG pattern).
	 */
	public function handle_form_submission() {
		if ( ! isset( $_POST['ntb_stripe_pm_sync_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ntb_stripe_pm_sync_nonce'] ) ), 'ntb_stripe_pm_sync_toggle' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled = isset( $_POST['ntb_stripe_pm_sync_enabled'] ) ? 'yes' : 'no';
		update_option( 'ntb_stripe_pm_sync_enabled', $enabled );

		wp_safe_redirect( add_query_arg( 'ntb_saved', '1', admin_url( 'admin.php?page=ntb-stripe-pm-sync' ) ) );
		exit;
	}

	/**
	 * Renders the full diagnostics page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled      = get_option( 'ntb_stripe_pm_sync_enabled', 'yes' );
		$auto_idle_on = defined( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE' ) && NTB_STRIPE_PM_SYNC_AUTO_IDLE;
		$debug_on     = defined( 'NTB_STRIPE_PM_SYNC_DEBUG' ) && NTB_STRIPE_PM_SYNC_DEBUG;

		echo '<div class="wrap">';
		echo '<h1>Stripe PM Sync Diagnostics</h1>';

		echo '<p>The NTB Stripe PM Sync plugin addresses a bug in the WooCommerce Stripe Gateway. '
			. 'When a customer adds a replacement card with the same card number '
			. '(for example, a renewed card with a new expiry date), the Stripe Gateway fails to update '
			. 'the stored expiry date, leaving customers seeing outdated card details. '
			. 'This plugin automatically corrects that.</p>';
		echo '<p>The plugin runs in the background — no action is needed from you or your customers. '
			. 'This page lets you check the plugin\'s status and look up individual customers\' card data '
			. 'if you need to investigate an issue.</p>';

		// Success notice after save.
		if ( isset( $_GET['ntb_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}

		// --- Plugin Status ---
		echo '<h2>Plugin Status</h2>';
		echo '<table class="widefat striped" style="max-width:500px;">';
		echo '<tbody>';
		echo '<tr><td><strong>Plugin Version</strong></td><td>' . esc_html( NTB_STRIPE_PM_SYNC_VERSION ) . '</td></tr>';
		echo '<tr><td><strong>Kill Switch</strong></td><td>';
		if ( $enabled === 'yes' ) {
			echo '<span style="color:green;font-weight:bold;">ON</span> (plugin active)';
		} else {
			echo '<span style="color:red;font-weight:bold;">OFF</span> (plugin disabled)';
		}
		echo '</td></tr>';
		echo '<tr><td><strong>Auto-Idle</strong></td><td>';
		if ( defined( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE' ) ) {
			echo $auto_idle_on ? 'Enabled' : 'Defined but false';
		} else {
			echo 'Not defined';
		}
		echo '</td></tr>';
		echo '<tr><td><strong>Debug Logging</strong></td><td>';
		if ( defined( 'NTB_STRIPE_PM_SYNC_DEBUG' ) ) {
			echo $debug_on ? 'ON' : 'Defined but false';
		} else {
			echo 'OFF (not defined)';
		}
		echo '</td></tr>';
		echo '</tbody>';
		echo '</table>';

		// --- Kill Switch Toggle ---
		echo '<h2>Kill Switch</h2>';
		echo '<p>When enabled, the plugin actively keeps card details in sync with Stripe. '
			. 'Uncheck and save to disable it — the plugin will stay installed but won\'t make any changes. '
			. 'This is useful for troubleshooting or if the upstream bug gets fixed. '
			. 'Disabling does not delete any data.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ntb_stripe_pm_sync_toggle', 'ntb_stripe_pm_sync_nonce' );
		echo '<label>';
		echo '<input type="checkbox" name="ntb_stripe_pm_sync_enabled" value="1"' . checked( $enabled, 'yes', false ) . ' /> ';
		echo 'Enable NTB Stripe PM Sync';
		echo '</label>';
		echo '<br /><br />';
		submit_button( 'Save', 'primary', 'ntb_submit', false );
		echo '</form>';

		// --- User Diagnostics ---
		echo '<hr />';
		echo '<h2>User Diagnostics</h2>';
		echo '<p>Enter a customer\'s WordPress user ID or email address to view their saved card data. '
			. 'The token table compares what WooCommerce has stored against what Stripe reports. '
			. 'Rows marked <strong>STALE</strong> indicate a mismatch — the plugin will fix these '
			. 'automatically on the customer\'s next visit to their payment methods page.</p>';
		echo '<p>If WooCommerce Subscriptions is active, a subscriptions table will also appear showing '
			. 'whether each subscription is pointing to a valid card in Stripe.</p>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="ntb-stripe-pm-sync" />';
		$ntb_user_input = isset( $_GET['ntb_user'] ) ? sanitize_text_field( wp_unslash( $_GET['ntb_user'] ) ) : '';
		echo '<label>User ID or Email: ';
		echo '<input type="text" name="ntb_user" value="' . esc_attr( $ntb_user_input ) . '" placeholder="e.g. 42 or user@example.com" />';
		echo '</label> ';
		submit_button( 'Look Up', 'secondary', 'ntb_lookup', false );
		echo '</form>';

		if ( ! empty( $ntb_user_input ) ) {
			$this->render_user_diagnostics( $ntb_user_input );
		}

		echo '</div>';
	}

	/**
	 * Renders token and subscription diagnostics for a specific user.
	 *
	 * @param string $input User ID or email address.
	 */
	private function render_user_diagnostics( $input ) {
		// Resolve user.
		if ( is_numeric( $input ) ) {
			$user_id = absint( $input );
			$user    = get_user_by( 'id', $user_id );
		} else {
			$user    = get_user_by( 'email', $input );
			$user_id = $user ? $user->ID : 0;
		}

		if ( ! $user ) {
			echo '<div class="notice notice-error"><p>User not found.</p></div>';
			return;
		}

		echo '<p>Showing diagnostics for: <strong>' . esc_html( $user->display_name ) . '</strong>'
			. ' (ID: ' . esc_html( $user_id ) . ', ' . esc_html( $user->user_email ) . ')</p>';

		// Fetch WC tokens.
		$tokens = \WC_Payment_Tokens::get_tokens( [
			'user_id'    => $user_id,
			'gateway_id' => 'stripe',
			'limit'      => 100,
		] );

		// Fetch Stripe PMs.
		$pm_map       = [];
		$stripe_error = '';

		if ( class_exists( 'WC_Stripe_Customer' ) ) {
			$customer = new \WC_Stripe_Customer( $user_id );
			try {
				$stripe_pms = $customer->get_all_payment_methods( [ 'card' ] );
				if ( ! empty( $stripe_pms ) ) {
					foreach ( $stripe_pms as $pm ) {
						if ( isset( $pm->id ) ) {
							$pm_map[ $pm->id ] = $pm;
						}
					}
				}
			} catch ( \Exception $e ) {
				$stripe_error = $e->getMessage();
			}
		} else {
			$stripe_error = 'WC_Stripe_Customer class not available.';
		}

		if ( $stripe_error ) {
			echo '<div class="notice notice-warning"><p>Could not fetch Stripe PMs: ' . esc_html( $stripe_error ) . '</p></div>';
		}

		// --- Token Table ---
		echo '<h3>Payment Tokens</h3>';

		if ( empty( $tokens ) ) {
			echo '<p>No Stripe tokens found for this user.</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>Token ID</th>';
			echo '<th>Stripe PM ID</th>';
			echo '<th>Fingerprint</th>';
			echo '<th>Last4</th>';
			echo '<th>Card Type</th>';
			echo '<th>WC Expiry</th>';
			echo '<th>Stripe Expiry</th>';
			echo '<th>Stale?</th>';
			echo '<th>Default?</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $tokens as $token ) {
				$token_id   = $token->get_id();
				$pm_id      = $token->get_token();
				$last4      = $token->get_last4();
				$card_type  = $token->get_card_type();
				$wc_exp     = $token->get_expiry_month() . '/' . $token->get_expiry_year();
				$is_default = $token->is_default() ? 'Yes' : 'No';

				$fingerprint = '';
				if ( $token instanceof \WC_Stripe_Payment_Token_CC
					&& method_exists( $token, 'get_fingerprint' )
				) {
					$fingerprint = $token->get_fingerprint();
				}

				// Compare against Stripe data.
				$stripe_exp = "\xE2\x80\x94"; // em-dash
				$is_stale   = false;

				if ( isset( $pm_map[ $pm_id ] ) ) {
					$pm               = $pm_map[ $pm_id ];
					$stripe_exp_month = str_pad( $pm->card->exp_month, 2, '0', STR_PAD_LEFT );
					$stripe_exp_year  = (string) $pm->card->exp_year;
					$stripe_exp       = $stripe_exp_month . '/' . $stripe_exp_year;
					$stripe_last4     = $pm->card->last4;
					$stripe_card_type = $this->derive_card_type( $pm );

					if (
						$token->get_expiry_month() !== $stripe_exp_month
						|| $token->get_expiry_year() !== $stripe_exp_year
						|| $last4 !== $stripe_last4
						|| $card_type !== $stripe_card_type
					) {
						$is_stale = true;
					}
				} elseif ( ! empty( $pm_map ) ) {
					$stripe_exp = 'PM not found in Stripe';
				}

				$row_style = $is_stale ? ' style="background-color:#fce4e4;"' : '';

				echo '<tr' . $row_style . '>';
				echo '<td>' . esc_html( $token_id ) . '</td>';
				echo '<td><code>' . esc_html( $pm_id ) . '</code></td>';
				echo '<td><code>' . esc_html( $fingerprint ) . '</code></td>';
				echo '<td>' . esc_html( $last4 ) . '</td>';
				echo '<td>' . esc_html( $card_type ) . '</td>';
				echo '<td>' . esc_html( $wc_exp ) . '</td>';
				echo '<td>' . esc_html( $stripe_exp ) . '</td>';
				echo '<td>';
				if ( $is_stale ) {
					echo '<span style="color:red;font-weight:bold;">STALE</span>';
				} else {
					echo 'OK';
				}
				echo '</td>';
				echo '<td>' . esc_html( $is_default ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		}

		// --- Subscriptions Table ---
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			echo '<p><em>WooCommerce Subscriptions not active — subscription diagnostics unavailable.</em></p>';
			return;
		}

		echo '<h3>Subscriptions</h3>';

		$subscriptions = wcs_get_users_subscriptions( $user_id );

		if ( empty( $subscriptions ) ) {
			echo '<p>No subscriptions found for this user.</p>';
			return;
		}

		// Build lookup sets for the "Matches WC Token?" and "PM in Stripe?" columns.
		$wc_token_pm_ids = [];
		foreach ( $tokens as $token ) {
			$wc_token_pm_ids[] = $token->get_token();
		}
		$stripe_pm_ids = array_keys( $pm_map );

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>Subscription</th>';
		echo '<th>Status</th>';
		echo '<th>_stripe_source_id</th>';
		echo '<th>PM in Stripe?</th>';
		echo '<th>Matches WC Token?</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $subscriptions as $subscription ) {
			$sub_id    = $subscription->get_id();
			$status    = $subscription->get_status();
			$source_id = $subscription->get_meta( '_stripe_source_id', true );

			$pm_in_stripe = ! empty( $source_id ) && in_array( $source_id, $stripe_pm_ids, true )
				? 'Yes' : 'No';
			$matches_wc   = ! empty( $source_id ) && in_array( $source_id, $wc_token_pm_ids, true )
				? 'Yes' : 'No';

			$edit_url = admin_url( 'post.php?post=' . $sub_id . '&action=edit' );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $sub_id ) . '</a></td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>';
			if ( $source_id ) {
				echo '<code>' . esc_html( $source_id ) . '</code>';
			} else {
				echo "\xE2\x80\x94"; // em-dash
			}
			echo '</td>';
			echo '<td>' . esc_html( $pm_in_stripe ) . '</td>';
			echo '<td>' . esc_html( $matches_wc ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Derives a card type string from a Stripe PM object.
	 * PHP 7.4 safe — same logic as Token_Refresher::derive_card_type().
	 *
	 * @param object $pm Stripe payment method object.
	 * @return string Lowercase card brand.
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
}
