# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

NTB Stripe PM Sync is a standalone WordPress plugin that patches a metadata refresh bug in WooCommerce Stripe Gateway 10.0.1. When a customer adds a replacement card (same number, new expiry), the Stripe plugin's fingerprint deduplication reuses the existing WC token but never updates its metadata (expiry_month, expiry_year, last4, card_type). This plugin hooks in to fix that.

The plugin is a **temporary patch** with built-in kill switch and auto-idle features for eventual retirement once the upstream bug is fixed.

## Implementation Status

The plugin has detailed specs but implementation may be incomplete. Always check current file state before working. The two spec documents are the source of truth:
- `STRIPE-PM-SYNC-SPEC.md` — Full technical specification (17 sections)
- `CLAUDE-IMPLEMENTATION-PROMPT.md` — Step-by-step implementation instructions with pseudocode

## Architecture

```
ntb-stripe-pm-sync.php              — Plugin bootstrap, dependency checks, class loading
includes/class-ntb-token-refresher.php  — Core logic (two hooks + subscription updater)
includes/class-ntb-admin-diagnostics.php — WooCommerce admin submenu diagnostic page
```

**Namespace:** `NTB\StripePMSync`

### Hook Architecture (execution order matters)

| Priority | Hook | Handler | Context |
|----------|------|---------|---------|
| 9 | `woocommerce_stripe_add_payment_method` (action) | `on_payment_method_added()` | During POST when user adds a card — updates token metadata + subscription `_stripe_source_id` |
| 10 | same action | Stripe subscriptions trait | Stripe's own handler — our hook must run before this |
| 10 | `woocommerce_get_customer_payment_tokens` (filter) | Stripe plugin sync | Lazy token creation on page load |
| 11 | same filter | `refresh_stale_metadata()` | Catches existing stale metadata from cached Stripe data |

### Critical Data Flow: Subscription Safety

Subscriptions store `_stripe_source_id` independently from WC tokens. Changing a token's PM ID without updating subscription meta causes renewal failures. The **conservative PM ID rule**: if subscription update fails, update display metadata but do NOT change the token's Stripe PM ID.

Order of operations in primary hook:
1. Update subscriptions FIRST (`_stripe_source_id`)
2. Update display metadata (expiry, last4, card_type) — always
3. Update token PM ID — only if step 1 succeeded

## Engineering Standards

### PHP Compatibility

Target: **PHP 7.4**. Do not use:
- Typed properties, union types, named arguments, `match` expressions, readonly properties, attributes
- Enums, fibers, intersection types, strict return types

### Coding Style

- Defensive programming — assume external data can be missing or malformed
- Clear variable naming — intent should be obvious without comments
- Single-responsibility methods — each method does one thing
- Early returns for guard clauses — reduce nesting, improve readability

### WordPress/WooCommerce Best Practices

- **Dependency guards:** Guard all dependencies (WooCommerce + WC_Stripe + optional Subscriptions) with `class_exists`/`function_exists` checks. Never fatal on missing deps; fail soft with early returns. Required checks:
  - `class_exists('WooCommerce')` and `class_exists('WC_Stripe')` — at plugin load
  - `class_exists('WC_Stripe_Payment_Token_CC')` — before fingerprint methods
  - `class_exists('WC_Stripe_Customer')` — before PM fetching
  - `function_exists('wcs_get_users_subscriptions')` — before subscription queries
  - `class_exists('WC_Subscriptions_Change_Payment_Gateway')` + `method_exists()` — before `update_payment_method()`
  - Plugin must work gracefully without WooCommerce Subscriptions installed.
- **Input/output safety:** Sanitize and validate all input from `$_GET`/`$_POST`. Escape all output in wp-admin (`esc_html`, `esc_attr`, `wp_kses` where appropriate).
- **Nonces and capabilities:** Use nonces for any write actions (kill switch toggle) and verify capabilities (`manage_woocommerce`).
- **Performance:** Avoid heavy work on every request. Keep secondary hook bounded (max 1 Stripe fetch per user/request, tokens limited to 100).
- **Logging:** Use Woo logger (`wc_get_logger()`) with source `ntb-stripe-pm-sync` behind `NTB_STRIPE_PM_SYNC_DEBUG` constant.
- **Naming:** snake_case method names per spec; keep naming consistent; avoid over-engineering.
- **Use WP/Woo APIs:** Prefer `WC_Payment_Tokens`, `WC_Stripe_Customer`, `update_option`, `add_submenu_page` over direct SQL. No inline SQL.
- **Namespace hygiene:** Everything under `NTB\StripePMSync`. No global namespace pollution. No direct output buffering hacks.
- **No custom tables, no cron, no background processing, no destructive operations.**

### Architecture Principles

- **Idempotent updates** — setting metadata to the same value is a no-op; safe to re-run
- **Fail-safe defaults** — if anything goes wrong, degrade gracefully; stale display is acceptable, broken renewals are not
- **Explicit logging** — every mutation, guard-clause exit, and error is logged (when debug enabled)
- **Subscription safety before PM ID mutation** — never change a token's PM ID without first updating `_stripe_source_id` on all affected subscriptions
- **No destructive Stripe operations in v1** — no PM detachment, no bulk cleanup
- **No parallel sync engine** — hook into the existing Stripe plugin infrastructure

## Key Patterns

- **Kill switch:** Every handler checks `get_option('ntb_stripe_pm_sync_enabled', 'yes')` at entry
- **Token queries:** Use `WC_Payment_Tokens::get_tokens()` with explicit `'limit' => 100`, NOT `get_customer_tokens()` (subject to `posts_per_page` limit)
- **Stripe API:** Use `WC_Stripe_API::request()` and `WC_Stripe_Customer::get_all_payment_methods()` — never the Stripe PHP SDK directly
- **All handlers** wrapped in try/catch — never break the Add Payment Method or payment methods display flows
- **Secondary hook** uses a function-level `static $refreshed` array keyed by user_id (once per user per request)
- **Card type derivation:** `strtolower($pm->card->display_brand ?? $pm->card->networks->preferred ?? $pm->card->brand)`

## Constants

Defined by plugin:
- `NTB_STRIPE_PM_SYNC_VERSION` — Plugin version
- `NTB_STRIPE_PM_SYNC_DIR` — Plugin directory path

User-defined in wp-config.php (checked with `defined()` at runtime):
- `NTB_STRIPE_PM_SYNC_DEBUG` — Enables WooCommerce log output
- `NTB_STRIPE_PM_SYNC_AUTO_IDLE` — Enables upstream-fix detection

## What This Plugin Must NOT Do

- Modify WooCommerce core or Stripe plugin files
- Detach Stripe PMs (deferred to v2)
- Hook into or alter WooCommerce "Delete payment method" actions
- Build a parallel sync engine
- Delete the default or only remaining payment method
- Use the Stripe PHP SDK directly

## Environment

- WordPress site managed via Local (Local by Flywheel)
- WooCommerce 9.9.6, Stripe Gateway 10.0.1, WooCommerce Subscriptions 6.1.0
- PHP 7.4.30
- Stripe API version: 2024-06-20
- Deploy via zip upload through wp-admin

## Testing

No automated test framework. Manual testing in Stripe Test Mode using test card `4242424242424242` with varying expiry dates. Key scenarios are documented in the spec (Section 11) and implementation prompt.
