# NTB Stripe PM Sync

**Version:** 1.0.0
**Requires PHP:** 7.4
**Tested up to:** WordPress 6.x, WooCommerce 9.9.x
**License:** GPL-2.0-or-later

Patches a metadata refresh bug in WooCommerce Stripe Gateway's fingerprint deduplication. When a customer adds a replacement card with the same number (e.g., a renewed card with a new expiry date), the Stripe Gateway reuses the existing token but never updates the stored expiry, last4, or card type. This plugin fixes that automatically.

## The Problem

WooCommerce Stripe Gateway 10.0.1 uses card fingerprints to deduplicate payment methods. When a customer adds a card that matches an existing token's fingerprint, the gateway correctly reuses the token but **fails to refresh its display metadata** (expiry month/year, last4, card type). Customers then see outdated card details on the My Account > Payment Methods page, and subscription renewals may reference stale Stripe PM IDs.

## Requirements

- WordPress 5.0+
- WooCommerce 7.0+
- WooCommerce Stripe Gateway 8.0+
- PHP 7.4+

**Optional:** WooCommerce Subscriptions 4.0+ (for subscription `_stripe_source_id` updates)

## Installation

1. Download the latest release zip from the `releases/` directory (or build it yourself — see [Building](#building) below).
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Activate the plugin.

No configuration is required. The plugin works automatically in the background.

## How It Works

The plugin registers two hooks that run alongside the Stripe Gateway:

### Primary Hook — Card Addition

Fires at priority 9 on `woocommerce_stripe_add_payment_method` (before Stripe's own handler at priority 10). When a customer adds a card:

1. Finds the existing WC token with a matching card fingerprint.
2. Updates any active WooCommerce Subscriptions to point to the new Stripe PM ID.
3. Refreshes the token's display metadata (expiry, last4, card type).
4. Updates the token's Stripe PM ID — **only if** subscription updates succeeded (conservative PM ID rule).

### Secondary Hook — Stale Metadata Catch-Up

Fires at priority 11 on `woocommerce_get_customer_payment_tokens` (after Stripe's sync at priority 10). Catches stale metadata for customers who haven't re-added their card by comparing WC token data against the Stripe API. Runs once per user per request.

This hook updates display metadata only — it does not change PM IDs or subscription references.

## Configuration

The plugin works out of the box with no configuration. The following optional constants can be added to `wp-config.php`:

### Debug Logging

```php
define( 'NTB_STRIPE_PM_SYNC_DEBUG', true );
```

Enables detailed logging to WooCommerce > Status > Logs (log source: `ntb-stripe-pm-sync`). Logs every mutation, guard-clause exit, and error. Useful for troubleshooting.

### Auto-Idle Detection

```php
define( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE', true );
```

Enables detection of whether the upstream bug has been fixed. When active, the plugin logs a message if it finds that token metadata is already correct (suggesting the Stripe Gateway may have been patched).

### Kill Switch

The plugin can be disabled without deactivating it. Go to **WooCommerce > Stripe PM Sync** in the admin menu and uncheck the kill switch. This stops all hook processing while keeping the plugin installed. No data is deleted.

## Admin Diagnostics

A diagnostics page is available at **WooCommerce > Stripe PM Sync**. It shows:

- **Plugin status** — version, kill switch state, debug/auto-idle configuration.
- **Kill switch toggle** — enable or disable the plugin without deactivating it.
- **User diagnostics** — enter a customer's user ID or email to compare their WC token data against Stripe. Rows marked **STALE** indicate a metadata mismatch that will be corrected on the customer's next visit.

If WooCommerce Subscriptions is active, a subscriptions table also appears showing whether each subscription's `_stripe_source_id` points to a valid Stripe PM and matches a WC token.

## Building

A build script is included to create a distribution-ready zip file.

### Prerequisites

- Bash shell (macOS, Linux, or WSL)
- `rsync` and `zip` (pre-installed on macOS and most Linux distributions)

### Create a Release

```bash
bash build.sh
```

This will:

1. Extract the version from the plugin header.
2. Copy only production files to a temporary directory (excluding dev files, specs, and build artifacts).
3. Create a zip at `releases/ntb-stripe-pm-sync-{version}.zip` with the proper folder structure for WordPress.

### Upload to WordPress

1. Run `bash build.sh` to generate the zip.
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the zip file from the `releases/` directory.
4. If updating, WordPress will prompt you to replace the existing version — confirm to proceed.

## Frequently Asked Questions

**Does this plugin modify the Stripe Gateway or WooCommerce core?**
No. It hooks into standard WooCommerce and Stripe Gateway actions/filters without modifying any core files.

**What happens if I deactivate the plugin?**
Nothing destructive. Token metadata may become stale again if the upstream bug still exists, but no data is lost. Subscriptions retain whatever `_stripe_source_id` was last set.

**Is this plugin safe for sites without WooCommerce Subscriptions?**
Yes. Subscription-related code is fully guarded with `function_exists` checks. The plugin works with or without WooCommerce Subscriptions installed.

**Will this plugin be needed forever?**
No. This is a temporary patch. Once the upstream bug in WooCommerce Stripe Gateway is fixed, the plugin can be disabled via the kill switch and eventually deactivated.

## Changelog

### 1.0.0
- Initial release.
- Primary hook: metadata + PM ID refresh on card addition with subscription safety.
- Secondary hook: stale metadata catch-up from Stripe API data.
- Admin diagnostics page with kill switch and per-user token/subscription inspection.
