# NTB Stripe PM Sync – Implementation Plan

## Status Legend
- [ ] Not started
- [~] In progress
- [x] Complete
- [!] Blocked / Needs decision

---

## Phase 1 – Bootstrap & Safety Guards
- [x] Create plugin header file (`ntb-stripe-pm-sync.php`)
- [x] Add ABSPATH guard
- [x] Define constants (`NTB_STRIPE_PM_SYNC_VERSION`, `NTB_STRIPE_PM_SYNC_DIR`)
- [x] Add dependency checks on `plugins_loaded` (`WooCommerce`, `WC_Stripe`)
- [x] Require and instantiate class files
- [x] Add namespace
- [x] Create stub `includes/class-ntb-token-refresher.php`
- [x] Create stub `includes/class-ntb-admin-diagnostics.php`
- Notes: Stubs have empty constructors so plugin can activate without errors.

## Phase 2 – Primary Hook Logic
- [x] Register primary hook at priority 9 (`woocommerce_stripe_add_payment_method`)
- [x] Implement `log()` and `log_error()` helpers
- [x] Implement `on_payment_method_added()` with guard clauses
- [x] Implement fingerprint match logic
- [x] Implement metadata comparison and update (Steps A-D)
- [x] Implement auto-idle check
- [x] Implement conservative PM ID rule
- [x] Implement `update_subscriptions()` method
- [x] Add try/catch wrapping
- Notes: Added `derive_card_type()` helper to safely handle null coalescing chain for PHP 7.4 compatibility.

## Phase 3 – Secondary Hook Logic
- [x] Register secondary hook at priority 11 (`woocommerce_get_customer_payment_tokens`)
- [x] Implement `refresh_stale_metadata()` with guard clauses
- [x] Add static per-user guard
- [x] Implement Stripe PM fetch (cached via `WC_Stripe_Customer`)
- [x] Implement metadata diff check and update
- [x] Add auto-idle check for secondary hook
- [x] Add try/catch wrapping
- Notes: Reuses `derive_card_type()` from Phase 2. Gateway guard checks `strpos($gateway_id, 'stripe') !== 0` only when `$gateway_id` is non-empty.

## Phase 4 – Admin Diagnostics Page
- [x] Register WooCommerce submenu page
- [x] Implement kill switch toggle UI with nonce handling
- [x] Implement plugin status display section
- [x] Implement user lookup form (ID or email)
- [x] Implement token diagnostics table with staleness indicators
- [x] Implement subscription diagnostics table
- [x] Add proper escaping and capability checks
- Notes: Uses PRG (Post-Redirect-Get) pattern for kill switch form. `derive_card_type()` duplicated from Token_Refresher for staleness comparison (small helper, not worth abstracting). Subscription table gracefully hidden when WCS not active.

## Phase 5 – Testing Verification
- [x] Validate Scenario A (expiry update)
- [x] Validate Scenario B (multiple attempts)
- [x] Validate Scenario C (subscription propagation)
- [x] Validate Scenario D (different card numbers)
- [x] Validate Scenario E (stale metadata secondary hook)
- [x] Validate Scenario F (delete behavior)
- [x] Validate Scenario G (admin diagnostics page)
- [x] Validate Scenario H (kill switch behavior)
- [x] Validate Scenario I (conservative PM ID rule)
- [x] Validate Scenario J (plugin deactivation safety)
- Notes: All 10 scenarios PASS. Scenario I code-verified (not live-tested). Scenario A required 3 fix iterations to resolve a timing/execution-order bug. See TESTING-LOG.md for full details.

---

## Progress Log

### 2026-02-14
- Created IMPLEMENTATION-PLAN.md
- **Phase 1 complete:**
  - `ntb-stripe-pm-sync.php`: Plugin header, ABSPATH guard, constants, `plugins_loaded` hook with WooCommerce + WC_Stripe dependency checks, class file loading and instantiation
  - `includes/class-ntb-token-refresher.php`: Stub with `NTB\StripePMSync` namespace and empty `Token_Refresher` class
  - `includes/class-ntb-admin-diagnostics.php`: Stub with `NTB\StripePMSync` namespace and empty `Admin_Diagnostics` class
- Files changed: `ntb-stripe-pm-sync.php`, `includes/class-ntb-token-refresher.php`, `includes/class-ntb-admin-diagnostics.php`, `IMPLEMENTATION-PLAN.md`
- Risks observed: None
- Next planned step: Phase 2 – Primary Hook Logic (awaiting approval)

### 2026-02-14 (Phase 2)
- **Phase 2 complete:**
  - `includes/class-ntb-token-refresher.php`: Full primary hook implementation
  - Constructor registers `woocommerce_stripe_add_payment_method` at priority 9
  - `on_payment_method_added()`: Guard clauses (kill switch, type, fingerprint, class_exists), fingerprint matching via `WC_Payment_Tokens::get_tokens()` with limit 100, metadata comparison (5 fields), auto-idle check, Steps A-D (subscription update first, display metadata always, PM ID conditional, single save), outer try/catch
  - `update_subscriptions()`: Guard for WCS availability (returns true if absent), loops active/on-hold/pending subs, matches `_stripe_source_id`, uses `WC_Subscriptions_Change_Payment_Gateway::update_payment_method()` preferred path with direct meta fallback, per-subscription try/catch, returns bool for conservative PM ID rule
  - `derive_card_type()`: Safe PHP 7.4 alternative to null coalescing chain (`display_brand → networks.preferred → brand`)
  - `log()` and `log_error()`: Behind `NTB_STRIPE_PM_SYNC_DEBUG` constant, uses `wc_get_logger()` with source `ntb-stripe-pm-sync`
- Files changed: `includes/class-ntb-token-refresher.php`, `IMPLEMENTATION-PLAN.md`
- Design decision: Used explicit if/elseif chain in `derive_card_type()` instead of `??` null coalescing, because `$pm->card->networks->preferred` could throw a notice in PHP 7.4 if `networks` is null, and `??` only suppresses undefined on the final property access.
- Risks observed: None
- Next planned step: Phase 3 – Secondary Hook Logic (awaiting approval)

### 2026-02-14 (Phase 3)
- **Phase 3 complete:**
  - Constructor now also registers `woocommerce_get_customer_payment_tokens` filter at priority 11
  - `refresh_stale_metadata()`: Guard clauses (kill switch, gateway check with empty-safe `strpos`, empty tokens, card token presence check, static per-user dedup, `WC_Stripe_Customer` class check), PM fetch via `get_all_payment_methods(['card'])` in try/catch, PM map by ID, 4-field metadata comparison (padded exp_month, string exp_year, last4, card_type via `derive_card_type()`), update + save per stale token, auto-idle logging when no updates needed, outer try/catch always returning `$tokens`
  - Does NOT change token PM IDs or subscription meta (primary hook only)
- Files changed: `includes/class-ntb-token-refresher.php`, `IMPLEMENTATION-PLAN.md`
- Risks observed: None
- Next planned step: Phase 4 – Admin Diagnostics Page (awaiting approval)

### 2026-02-14 (Phase 4)
- **Phase 4 complete:**
  - `includes/class-ntb-admin-diagnostics.php`: Full admin diagnostics implementation
  - Constructor registers `admin_menu` (page registration) and `admin_init` (form handling)
  - `register_admin_page()`: WooCommerce submenu at `woocommerce` → `ntb-stripe-pm-sync`, requires `manage_woocommerce` capability
  - `handle_form_submission()`: PRG pattern — nonce verify (`ntb_stripe_pm_sync_toggle`), capability check, `update_option()`, `wp_safe_redirect()` to avoid resubmission
  - `render_page()`: Plugin status table (version, kill switch ON/OFF, auto-idle, debug logging), nonce-protected kill switch toggle form, user lookup form (ID or email via GET)
  - `render_user_diagnostics()`: Resolves user by ID or email, fetches WC tokens via `WC_Payment_Tokens::get_tokens()` (limit 100), fetches Stripe PMs via `WC_Stripe_Customer::get_all_payment_methods(['card'])` in try/catch, token table with 9 columns (Token ID, PM ID, Fingerprint, Last4, Card Type, WC Expiry, Stripe Expiry, Stale indicator, Default), red background + "STALE" label on mismatched rows, subscription table with 5 columns (Sub ID linked to edit, Status, `_stripe_source_id`, PM in Stripe?, Matches WC Token?), graceful handling when WCS not active
  - `derive_card_type()`: Duplicated from Token_Refresher (PHP 7.4-safe `display_brand → networks.preferred → brand`)
  - All output escaped with `esc_html()`, `esc_attr()`, `esc_url()`; all inputs sanitized with `sanitize_text_field()` + `wp_unslash()`
  - Read-only diagnostics — no fix/cleanup buttons in v1
- Files changed: `includes/class-ntb-admin-diagnostics.php`, `IMPLEMENTATION-PLAN.md`
- Design decisions:
  - PRG pattern for form handling avoids double-submit on browser refresh
  - `derive_card_type()` duplicated rather than extracted to shared utility — the helper is 9 lines and not worth a new abstraction layer
  - Subscription table hidden (not empty) when WCS not installed — clearer UX than showing an empty table
  - PM ID and fingerprint displayed in `<code>` tags for readability
- Risks observed: None
- Next planned step: Phase 5 – Testing Verification (manual)
