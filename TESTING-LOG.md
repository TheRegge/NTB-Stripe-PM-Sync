# NTB Stripe PM Sync – Phase 5 Testing Log

## Test Environment
- **Date:** 2026-02-15
- **WordPress:** Local (Local by Flywheel)
- **WooCommerce:** 9.9.6
- **Stripe Gateway:** 10.0.1
- **WooCommerce Subscriptions:** 6.1.0
- **PHP:** 7.4.30
- **Plugin Version:** 1.0.0
- **Stripe Mode:** Test Mode
- **Test Card:** 4242424242424242

---

## Pre-Test Setup

- [x] Stripe Test Mode confirmed active
- [x] `NTB_STRIPE_PM_SYNC_DEBUG` defined as `true` in `wp-config.php`
- [x] Kill switch confirmed ON (WooCommerce > Stripe PM Sync)
- [x] Test customer account identified (user ID and email recorded)
- [x] WooCommerce logs accessible (WooCommerce > Status > Logs) — no ntb-stripe-pm-sync entries yet (expected)

### Environment Details
- Test customer user ID: 38603
- Test customer email: test-subscriber-rollover@example.com
- Stripe customer ID: _pending_ (will be recorded after first card add)

---

## Baseline State (before Scenario A)

| Field | Value |
|-------|-------|
| Token ID | 13286 |
| Stripe PM ID | `pm_1StEAEFDD8mnQ7h3CjivUQML` |
| Fingerprint | `2taSYVAm68EvQ93s` |
| Last4 | 4242 |
| Card Type | visa |
| WC Expiry | 01/2028 |
| Stripe Expiry | 01/2028 |
| Stale? | OK |
| Default? | Yes |

**Subscription #1033368:** active, `_stripe_source_id` = `pm_1StEAEFDD8mnQ7h3CjivUQML`, PM in Stripe = Yes, Matches WC Token = Yes

Everything is in sync. One existing Visa 4242 token with expiry 01/2028, one active subscription pointing to the same PM.

---

## Scenario A – Expiry Update (Primary Flow)

**Goal:** Confirm the core flow works — adding a replacement card with a new expiry updates the WC token metadata and PM ID.

### Steps & Results

**A1 — Added card 4242 with expiry 01/30 (from 01/28)**
- Frontend showed 01/30 immediately after redirect ✓

**A2 — Diagnostics check:**
- Token ID: 13286 (same, no duplicate) ✓
- PM ID changed: `pm_1StEAEFDD8mnQ7h3CjivUQML` → `pm_1T19NLFDD8mnQ7h35s3f3h1E` ✓
- Fingerprint unchanged: `2taSYVAm68EvQ93s` ✓
- WC Expiry: 01/2030 ✓
- Stripe Expiry: 01/2030 ✓
- Stale: OK ✓
- **Subscription #1033368: `_stripe_source_id` NOT updated** — still `pm_1StEAEFDD8mnQ7h3CjivUQML` ✗
- Matches WC Token: No ✗

**A3 — Log analysis:**
- Line 1: `Secondary hook: refreshed token #13286 (PM: pm_1T19NLFDD8mnQ7h35s3f3h1E) metadata from Stripe`
- Line 2: `Token already up to date for fingerprint 2taSYVAm68EvQ93s`

### Bug Found: Timing Issue

**Root cause:** The primary hook calls `WC_Payment_Tokens::get_tokens()` to find the fingerprint match. This triggers the `woocommerce_get_customer_payment_tokens` filter, which runs the Stripe plugin's sync (priority 10, updates token PM ID) and then our secondary hook (priority 11, fixes metadata). By the time `get_tokens()` returns, the token is fully updated, so the primary hook sees "already up to date" and exits without updating subscriptions.

**Impact:** Token metadata is correctly updated (by secondary hook), but subscription `_stripe_source_id` is never updated because the primary hook's subscription update logic is never reached.

**Fix needed:** Two changes:
1. Add a static flag to prevent secondary hook from running during primary hook's `get_tokens()` call
2. Handle the case where Stripe's dedup already updated the token PM ID (find old PMs by fingerprint in Stripe, update subscriptions referencing them)

### Fix Applied

**Changes to `includes/class-ntb-token-refresher.php`:**

1. **Added `private static $in_primary_hook = false;`** — static flag on the class
2. **Before `get_tokens()` call in primary hook:**
   - Set `$in_primary_hook = true` to suppress secondary hook
   - Register a priority-1 capture filter on `woocommerce_get_customer_payment_tokens` that records each token's PM ID *before* Stripe's sync (priority 10) can modify them
3. **After `get_tokens()` returns:**
   - Remove capture filter, reset `$in_primary_hook = false`
4. **`$old_pm_id` assignment** now reads from the captured map instead of `$matching_token->get_token()`, so it reflects the pre-sync value
5. **Secondary hook** (`refresh_stale_metadata()`) returns early when `$in_primary_hook` is true

**How the fix works:**
- Primary hook fires → sets flag → registers priority-1 capture
- `get_tokens()` triggers filter chain:
  - Priority 1 (our capture): records original PM IDs from DB
  - Priority 10 (Stripe sync): updates token PM ID in memory + DB
  - Priority 11 (our secondary hook): sees flag, returns early (no premature metadata fix)
- `get_tokens()` returns → flag cleared
- Primary hook compares captured `$old_pm_id` (pre-sync) vs `$new_pm_id` → detects change
- Subscription update runs with correct old→new PM IDs
- Display metadata updated, token PM ID updated, single save

### Scenario A — Re-test #1 (after first fix)

**A4 — Added card 4242 with expiry 02/31:**
- Token metadata updated: PM `pm_1T19mCFDD8mnQ7h3t04GP5ux`, expiry 02/2031 ✓
- Subscription #1033368: `_stripe_source_id` **still** `pm_1StEAEFDD8mnQ7h3CjivUQML` ✗

**Root cause of persisting failure:** The capture fix correctly recorded the token's pre-sync PM ID (`pm_1T19NLFDD8mnQ7h35s3f3h1E`), but the subscription is stuck on the very **first** PM (`pm_1StEAEFDD8mnQ7h3CjivUQML`) — a PM that predates even the captured one, because the original test (before any fix) never updated the subscription. `update_subscriptions()` matched on `$old_pm_id` only, so the subscription (pointing to an even older PM) was silently skipped.

### Second Fix Applied

**Additional changes to `includes/class-ntb-token-refresher.php`:**

1. **Added `$fingerprint` parameter to `update_subscriptions()`** — enables matching older PMs beyond just the immediately previous one
2. **New matching logic in subscription loop:**
   - If `_stripe_source_id === $new_pm_id` → skip (already correct)
   - If `_stripe_source_id === $old_pm_id` → update (direct match, fast path)
   - Otherwise → call `pm_has_fingerprint()` via Stripe API to check if the PM has the same fingerprint → update if yes
3. **New `pm_has_fingerprint()` helper method** — retrieves a PM from Stripe via `WC_Stripe_API::request()` (`GET /v1/payment_methods/{pm_id}`) and checks `$response->card->fingerprint`. Works for detached PMs. Results cached per-request with static array.

### Scenario A — Re-tests #2–#6 (after second fix)

**A5–A9 — Five more tests with different expiry dates:**
- Token metadata updated correctly each time ✓
- Subscription #1033368: `_stripe_source_id` **still stuck** on `pm_1StEAEFDD8mnQ7h3CjivUQML` ✗
- Logs show identical 2-line pattern every time:
  1. `Secondary hook: refreshed token #13286 (PM: pm_xxx) metadata from Stripe`
  2. `Token already up to date for fingerprint 2taSYVAm68EvQ93s`

### Deeper Root Cause Found (Third Analysis)

**Not an OPcache issue.** Diagnostic mu-plugin confirmed:
- OPcache is not even loaded on this PHP 7.4 installation
- File on disk has all fixes (verified via `file_get_contents()` + `strpos()`)
- PHP reads from disk on every request — no caching involved

**The real problem: execution order within the Stripe gateway's add-card flow.**

When a user adds a card, the Stripe gateway calls something that triggers the `woocommerce_get_customer_payment_tokens` filter BEFORE firing the `woocommerce_stripe_add_payment_method` action. This means:

1. Stripe gateway processes the add → internally triggers `woocommerce_get_customer_payment_tokens`
2. Priority 10 (Stripe sync): Updates token PM ID to new PM
3. Priority 11 (our secondary hook): Detects stale metadata → fixes it
4. Stripe gateway fires `woocommerce_stripe_add_payment_method` action
5. Our primary hook fires → token already has new PM ID + new metadata
6. All 5 comparison fields match → "Token already up to date" → subscription update never runs

**Additionally:** The capture filter at priority 1 on `woocommerce_get_customer_payment_tokens` never fired because `WC_Payment_Tokens::get_tokens()` does NOT apply this filter (only `get_customer_payment_tokens()` does). The `$in_primary_hook` flag was also ineffective for the same reason.

### Third Fix Applied (Complete Rewrite)

**Changes to `includes/class-ntb-token-refresher.php`:**

1. **Removed** `$in_primary_hook` static flag — ineffective (`get_tokens()` doesn't trigger the filter)
2. **Removed** capture filter (priority 1 on `woocommerce_get_customer_payment_tokens`) — `get_tokens()` doesn't fire it
3. **Decoupled** subscription updates from metadata staleness — primary hook ALWAYS scans subscriptions on fingerprint match, regardless of whether token metadata is stale
4. **Simplified** `update_subscriptions()` — removed `$old_pm_id` parameter, uses fingerprint-only matching via `pm_has_fingerprint()` for all subscriptions
5. **Metadata update** is now idempotent — checks and updates only if needed, doesn't gate subscription logic
6. **Auto-idle** moved to after metadata check (only fires if metadata was already correct AND subscriptions needed no updates)

**How it works now:**
- Primary hook fires → finds matching token by fingerprint
- Step A: Scans ALL active subscriptions. For each, checks if `_stripe_source_id` has the same fingerprint via Stripe API. If yes and not already pointing to new PM → update.
- Step B: Updates display metadata if stale (may be a no-op if secondary hook already fixed it).
- Step C: Updates token PM ID if different (conservative rule: only if Step A succeeded).
- Step D: Saves token if anything changed.

### Scenario A — Re-test #3 (after third fix) ✓

**A10 — Added card 4242 with expiry 06/34:**

**Diagnostics:**
- Token ID: 13286 (same, no duplicate) ✓
- PM ID: `pm_1T1B9eFDD8mnQ7h3gV1m88QT` ✓
- Fingerprint: `2taSYVAm68EvQ93s` (unchanged) ✓
- WC Expiry: 06/2034 ✓
- Stripe Expiry: 06/2034 ✓
- Stale: OK ✓
- Subscription #1033368: `_stripe_source_id` = `pm_1T1B9eFDD8mnQ7h3gV1m88QT` ✓
- PM in Stripe: Yes ✓
- Matches WC Token: Yes ✓

**Log analysis (19:38:59):**
1. `Secondary hook: refreshed token #13286 (PM: pm_1T1B9eFDD8mnQ7h3gV1m88QT) metadata from Stripe` — secondary hook fixed metadata (expected, runs before primary)
2. `Fingerprint match on token #13286 for user 38603 — new PM: pm_1T1B9eFDD8mnQ7h3gV1m88QT, fingerprint: 2taSYVAm68EvQ93s` — primary hook found match
3. `Subscription #1033368 references PM pm_1StEAEFDD8mnQ7h3CjivUQML with matching fingerprint — updating to pm_1T1B9eFDD8mnQ7h3gV1m88QT` — fingerprint API check confirmed old PM belongs to same card
4. `Updated subscription #1033368 _stripe_source_id from pm_1StEAEFDD8mnQ7h3CjivUQML to pm_1T1B9eFDD8mnQ7h3gV1m88QT` — subscription updated
5. `Token #13286 metadata already up to date` — metadata was already correct (secondary hook handled it)

### Scenario A Result: PASS ✓

---

## Scenario B – Multiple Attempts

**Goal:** Adding the same card 3 times with different expiries results in only 1 WC token showing the latest expiry.

### Steps & Results

**Starting state:** Token #13286, PM `pm_1T1B9eFDD8mnQ7h3gV1m88QT`, expiry 06/2034, subscription matches.

**B1 — Added card 4242 with expiry 07/35:**
- Token ID: 13286 (same, no duplicate) ✓
- PM ID: `pm_1T1EMfFDD8mnQ7h3h4HoGuM1` ✓
- WC Expiry: 07/2035, Stale: OK ✓
- Subscription #1033368: updated to `pm_1T1EMfFDD8mnQ7h3h4HoGuM1` ✓

**B2 — Added card 4242 with expiry 08/36:**
- Token ID: 13286 (same, no duplicate) ✓
- PM ID: `pm_1T1EOVFDD8mnQ7h36FPPhH4X` ✓
- WC Expiry: 08/2036, Stale: OK ✓
- Subscription #1033368: updated to `pm_1T1EOVFDD8mnQ7h36FPPhH4X` ✓

**B3 — Added card 4242 with expiry 09/37:**
- Token ID: 13286 (same, no duplicate) ✓
- PM ID: `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k` ✓
- WC Expiry: 09/2037, Stale: OK ✓
- Subscription #1033368: updated to `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k` ✓

**Log pattern (all 3 identical):** secondary hook refreshes metadata → primary hook finds fingerprint match → subscription updated via Stripe API fingerprint check → token metadata already up to date.

### Scenario B Result: PASS ✓

---

## Scenario C – Subscription PM Propagation

**Goal:** When a card is re-added with a new expiry, the subscription's `_stripe_source_id` is updated to the new PM.

### Steps & Results

Covered by Scenarios A and B. Across 4 consecutive card additions (A10, B1, B2, B3), the subscription `_stripe_source_id` was updated each time to the latest PM via fingerprint-based matching. The chain:
- `pm_1StEAEFDD8mnQ7h3CjivUQML` → `pm_1T1B9eFDD8mnQ7h3gV1m88QT` (A10)
- → `pm_1T1EMfFDD8mnQ7h3h4HoGuM1` (B1)
- → `pm_1T1EOVFDD8mnQ7h36FPPhH4X` (B2)
- → `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k` (B3)

### Scenario C Result: PASS ✓

---

## Scenario D – Different Card Numbers (No Interference)

**Goal:** Adding cards with different numbers creates separate tokens with no cross-contamination.

### Steps & Results

**Starting state:** 1 token (#13286, Visa 4242, expiry 09/2037), subscription pointing to it.

**D1 — Added Mastercard 5555 5555 5555 4444 with expiry 12/30:**
- New token created: #13287 ✓
- Token #13287: PM `pm_1T1EVMFDD8mnQ7h3FZbsljwv`, last4 4444, mastercard, 12/2030, Stale: OK ✓
- Token #13286 (Visa): unchanged — PM `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k`, 09/2037, Stale: OK ✓
- Different fingerprints: `2taSYVAm68EvQ93s` (Visa) vs `2t1NWj6hnmco6ai3` (MC) ✓
- Subscription #1033368: still points to Visa PM — no interference ✓
- Log: `Fingerprint match on token #13287` — primary hook ran, found match on MC's own fingerprint, no subscription referenced it, metadata already correct ✓

### Scenario D Result: PASS ✓

---

## Scenario E – Stale Metadata (Secondary Hook)

**Goal:** The secondary hook auto-corrects stale metadata when a user visits the payment methods page.

### Steps & Results

**Setup:** Manually set token #13286 expiry to 01/2025 in the database (actual Stripe expiry is 09/2037) to simulate stale metadata without going through the add-card flow.

**E1 — Diagnostics before fix (stale state):**
- Token #13286: WC Expiry 01/2025, Stripe Expiry 09/2037 ✓ (mismatch confirmed)
- Stale: **STALE** (red row) ✓
- Subscription #1033368: `_stripe_source_id` = `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k`, PM in Stripe = Yes, Matches WC Token = Yes ✓

**E2 — Test customer visited payment methods page (frontend):**
- Log line 33: `Secondary hook: refreshed token #13286 (PM: pm_1T1EQmFDD8mnQ7h3ahm1FQ5k) metadata from Stripe`
- No subscription update attempted (expected — secondary hook only fixes display metadata)

**E3 — Diagnostics after fix:**
- Token #13286: WC Expiry 09/2037, Stripe Expiry 09/2037 ✓ (match restored)
- Stale: **OK** ✓
- PM ID unchanged: `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k` ✓ (secondary hook does not change PM IDs)
- Subscription #1033368: unchanged, still pointing to same PM ✓

### Scenario E Result: PASS ✓

---

## Scenario F – Delete Behavior

**Goal:** Deleting a payment method works cleanly with no interference from the plugin.

### Steps & Results

**Starting state:** 2 tokens — #13286 (Visa 4242, 09/2037) and #13287 (Mastercard 4444, 12/2030). Subscription #1033368 points to Visa PM.

**F1 — Deleted Mastercard (token #13287) from frontend payment methods page:**
- Delete completed without visible errors ✓
- No plugin log entries generated during delete (expected — plugin doesn't hook into delete actions) ✓

**F2 — Diagnostics after delete:**
- Token #13287 gone ✓
- Token #13286 (Visa): unchanged — PM `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k`, 09/2037, Stale: OK ✓
- Subscription #1033368: unchanged — `_stripe_source_id` = `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k`, PM in Stripe: Yes, Matches WC Token: Yes ✓

### Scenario F Result: PASS ✓

---

## Scenario G – Admin Diagnostics Page

**Goal:** The diagnostics page displays accurate data for a looked-up user.

### Steps & Results

Diagnostics page has been the primary verification tool throughout all prior scenarios. Formal checklist:

- [x] User lookup by ID (38603) — verified across Scenarios A–F ✓
- [x] User lookup by email (`test-subscriber-rollover@example.com`) — returns identical data ✓
- [x] Token table: PM ID, fingerprint, last4, card type, WC expiry, Stripe expiry, stale/OK, default ✓
- [x] Subscription table: sub ID (linked), status, `_stripe_source_id`, PM in Stripe, Matches WC Token ✓
- [x] STALE detection: red row with bold "STALE" label (Scenario E) ✓
- [x] Multiple tokens displayed correctly (Scenarios D–F showed 2 tokens) ✓
- [x] Explanatory text present: page intro, kill switch description, user diagnostics description ✓
- [x] Plugin status table: version 1.0.0, kill switch ON, auto-idle, debug logging status ✓
- [x] Invalid user lookup shows "User not found" notice (not tested explicitly but code-verified) ✓

### Scenario G Result: PASS ✓

---

## Scenario H – Kill Switch

**Goal:** When the kill switch is OFF, the plugin does not update metadata. When ON, it does.

### Steps & Results

**H1 — Disabled kill switch (unchecked, saved):**
- Plugin Status shows Kill Switch: **OFF** (red, "plugin disabled") ✓
- Checkbox unchecked ✓

**H2 — Added card 4242 with expiry 10/38 (kill switch OFF):**
- Log line 34: `Kill switch is off — skipping` ✓
- Token #13286: PM ID changed to `pm_1T1E1bFDD8mnQ7h3OTZoIJ2w` (Stripe's own dedup) but WC Expiry stayed 09/2037 ✓
- Stripe Expiry: 10/2038 — mismatch, Stale: **STALE** ✓
- Subscription #1033368: still pointing to old PM `pm_1T1EQmFDD8mnQ7h3ahm1FQ5k` — not updated ✓
- Matches WC Token: No ✓

**H3 — Re-enabled kill switch (checked, saved), added card 4242 with expiry 10/38:**
- Log lines 35–39: secondary hook refreshed → fingerprint match → subscription updated → metadata already up to date ✓
- Token #13286: PM `pm_1T1EoQFDD8mnQ7h3XHTsTv1Z`, WC Expiry 10/2038, Stripe Expiry 10/2038, Stale: OK ✓
- Subscription #1033368: updated to `pm_1T1EoQFDD8mnQ7h3XHTsTv1Z`, PM in Stripe: Yes, Matches WC Token: Yes ✓

### Scenario H Result: PASS ✓

---

## Scenario I – Conservative PM ID Rule

**Goal:** If subscription updates fail, display metadata is still updated but the token PM ID is NOT changed.

### Steps & Results

**Code-verified** (not live-tested — would require injecting an artificial failure into the Stripe API or subscription update path).

**Code analysis of `on_payment_method_added()` in `class-ntb-token-refresher.php`:**

1. **Lines 109–120:** `$subs_updated` defaults to `true`. `update_subscriptions()` is wrapped in try/catch — if it throws, `$subs_updated = false`.
2. **Lines 136–141:** Display metadata (expiry, last4, card_type) is updated **unconditionally** if stale — not gated on `$subs_updated`.
3. **Lines 143–157:** Token PM ID change is explicitly gated:
   - `if ( $subs_updated )` → change PM ID
   - `else` → log error, retain old PM ID for renewal safety
4. **Lines 159–176:** Token saved if either metadata or PM ID changed, ensuring display metadata is persisted even if PM ID is held back.

**Conservative rule is correctly implemented:**
- Subscription failure → display metadata updated, PM ID retained → subscriptions continue renewing with the old PM they still reference
- Subscription success → everything updated (normal path, verified across Scenarios A–C and H)

### Scenario I Result: PASS ✓ (code-verified)

---

## Scenario J – Plugin Deactivation Safety

**Goal:** Deactivating the plugin causes no errors and no data loss.

### Steps & Results

**J1 — Deactivated plugin (Plugins > Deactivate):**
- No PHP errors, no white screen ✓
- WooCommerce > Stripe PM Sync page is gone (expected) ✓

**J2 — Frontend payment methods page (plugin deactivated):**
- Customer payment methods page loads without errors ✓
- No plugin interference — site continues to function normally ✓

**J3 — Reactivated plugin:**
- Plugin reactivated successfully ✓
- Diagnostics page restored ✓
- Token #13286: PM `pm_1T1EoQFDD8mnQ7h3XHTsTv1Z`, 10/2038, Stale: OK ✓
- Subscription #1033368: same PM, PM in Stripe: Yes, Matches WC Token: Yes ✓
- All data intact — no data loss from deactivation/reactivation cycle ✓

### Scenario J Result: PASS ✓

---

## Summary

| Scenario | Result | Notes |
|----------|--------|-------|
| A – Expiry Update | PASS | Required 3 fix iterations (timing/execution-order bug) |
| B – Multiple Attempts | PASS | 3 consecutive adds, single token, PM + subscription updated each time |
| C – Subscription Propagation | PASS | Covered by A + B; 4-step PM chain all successful |
| D – Different Card Numbers | PASS | Separate tokens, different fingerprints, no interference |
| E – Stale Metadata | PASS | Secondary hook auto-corrected on page visit |
| F – Delete Behavior | PASS | No plugin interference, remaining token + subscription untouched |
| G – Admin Diagnostics | PASS | All columns accurate; email + ID lookup both work |
| H – Kill Switch | PASS | OFF: skipped all updates; ON: resumed and synced correctly |
| I – Conservative PM ID Rule | PASS | Code-verified: PM ID gated on subscription success |
| J – Plugin Deactivation | PASS | Clean deactivate/reactivate, no errors, no data loss |
