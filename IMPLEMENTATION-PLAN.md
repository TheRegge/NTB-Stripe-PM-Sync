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
- [ ] Register primary hook at priority 9 (`woocommerce_stripe_add_payment_method`)
- [ ] Implement `log()` and `log_error()` helpers
- [ ] Implement `on_payment_method_added()` with guard clauses
- [ ] Implement fingerprint match logic
- [ ] Implement metadata comparison and update (Steps A-D)
- [ ] Implement auto-idle check
- [ ] Implement conservative PM ID rule
- [ ] Implement `update_subscriptions()` method
- [ ] Add try/catch wrapping
- Notes:

## Phase 3 – Secondary Hook Logic
- [ ] Register secondary hook at priority 11 (`woocommerce_get_customer_payment_tokens`)
- [ ] Implement `refresh_stale_metadata()` with guard clauses
- [ ] Add static per-user guard
- [ ] Implement Stripe PM fetch (cached via `WC_Stripe_Customer`)
- [ ] Implement metadata diff check and update
- [ ] Add auto-idle check for secondary hook
- [ ] Add try/catch wrapping
- Notes:

## Phase 4 – Admin Diagnostics Page
- [ ] Register WooCommerce submenu page
- [ ] Implement kill switch toggle UI with nonce handling
- [ ] Implement plugin status display section
- [ ] Implement user lookup form (ID or email)
- [ ] Implement token diagnostics table with staleness indicators
- [ ] Implement subscription diagnostics table
- [ ] Add proper escaping and capability checks
- Notes:

## Phase 5 – Testing Verification
- [ ] Validate Scenario A (expiry update)
- [ ] Validate Scenario B (multiple attempts)
- [ ] Validate Scenario C (subscription propagation)
- [ ] Validate Scenario D (different card numbers)
- [ ] Validate Scenario E (stale metadata secondary hook)
- [ ] Validate Scenario F (delete behavior)
- [ ] Validate Scenario G (admin diagnostics page)
- [ ] Validate Scenario H (kill switch behavior)
- [ ] Validate Scenario I (conservative PM ID rule)
- [ ] Validate Scenario J (plugin deactivation safety)
- Notes:

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
