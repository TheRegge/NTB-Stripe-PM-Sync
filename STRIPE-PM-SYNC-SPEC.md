# NTB Insiders – Stripe Payment Method Sync Patch

## Technical Specification (Revised)

---

## 1. Background

On the NTB Insiders site (WooCommerce + WooCommerce Subscriptions + WooCommerce Stripe Gateway), the following issue occurs:

When a user receives a replacement card from their bank with:

- The same card number
- A new expiration date

and they use **"Add Payment Method"**, Stripe creates a new Payment Method object.

However:

- WooCommerce continues displaying the older expiration date.
- Multiple Stripe Payment Methods accumulate with the same fingerprint.
- Users repeatedly add or delete cards, creating confusion.
- Deletion appears not to work, even though Stripe records are being removed correctly (the "user illusion").

### Root Cause (Confirmed via Code Audit)

The WooCommerce Stripe Gateway plugin (10.0.1) **already has fingerprint-based deduplication**. The bug is a metadata refresh omission in the existing deduplication code:

1. **Deduplication exists** at `add_token_to_user()` in `woocommerce-gateway-stripe/includes/payment-tokens/class-wc-stripe-payment-tokens.php` (line 553). It calls `get_duplicate_token()` (lines 785-805) which compares fingerprints via `is_equal_payment_method()`.

2. **The metadata refresh is missing.** When a duplicate is found (lines 554-562), the code conditionally updates only the `token` field (Stripe PM ID). It **never** updates `expiry_month`, `expiry_year`, `last4`, or `card_type`:

   ```php
   // Lines 554-562 — only updates token ID, not metadata
   if ( $found_token ) {
       if ( ! in_array( $found_token->get_token(), $payment_method_ids, true ) ) {
           $customer->clear_cache();
           $found_token->set_token( $payment_method->id );
           $found_token->save();
       }
       return $found_token;
   }
   ```

3. **The condition prevents even the token ID update** when both old and new PMs exist in Stripe. The check `! in_array( $found_token->get_token(), $payment_method_ids, true )` is FALSE when the old PM is still in Stripe (which it always is right after adding a new card).

4. **Token creation is lazy.** WC tokens are not created during the "Add Payment Method" action. They are created/synced on page load via the `woocommerce_get_customer_payment_tokens` filter in `woocommerce_get_customer_upe_payment_tokens()` (lines 264-384).

### How the "User Illusion" Works

Step-by-step trace after user adds same card with new expiry:

1. User submits Add Payment Method. Stripe creates `pm_new` (exp 01/30).
2. `woocommerce_stripe_add_payment_method` action fires. No WC token is created yet.
3. Browser redirects to `/my-account/payment-methods/`.
4. `WC_Payment_Tokens::get_customer_tokens()` triggers the Stripe plugin's sync filter.
5. Sync fetches all Stripe PMs: `[pm_old (exp 01/28), pm_new (exp 01/30)]`.
6. `pm_old` is already in stored tokens — skipped.
7. `pm_new` is not in stored tokens — `add_token_to_user(pm_new)` is called.
8. `get_duplicate_token()` finds the existing WC token (fingerprint match).
9. Since `pm_old` IS in the payment method IDs list, the token ID is NOT updated.
10. Old token is returned unchanged — user sees exp 01/28.

When the user then deletes the card from WC:
- WC deletes the token (pointing to `pm_old`). Stripe detaches `pm_old`.
- On next page load, sync finds `pm_new` with no matching WC token.
- Since the old token was deleted, `get_duplicate_token()` finds no match.
- A **new** WC token is created with `pm_new`'s metadata (exp 01/30).
- User finally sees the correct expiry — but thinks deletion "fixed" a display bug.

---

## 2. Goals of the Patch

The patch must:

1. Ensure WC token metadata (expiry_month, expiry_year, last4, card_type) always reflects the most recent Stripe PM for each fingerprint.
2. Ensure subscription `_stripe_source_id` stays in sync when the token's Stripe PM ID changes.
3. Ensure WooCommerce always displays the most recent expiration date.
4. Ensure deletion behavior remains consistent and predictable.
5. Avoid modifying core WooCommerce or Stripe plugin files.
6. Be implemented as a standalone WordPress plugin.
7. Be safe for production.
8. Not require upgrading PHP or core plugins.

---

## 3. Non-Goals

The patch will NOT:

- Add a UI "Edit Card" button.
- Build a parallel sync engine (the Stripe plugin already syncs PMs to tokens).
- Detach old Stripe Payment Methods (deferred to v2 with subscription safety audit).
- Hook into, block, or modify WooCommerce "Delete payment method" actions. Deletion remains entirely managed by WooCommerce core and the Stripe plugin. This plugin is a metadata refresh + subscription meta safety patch only.
- Modify Stripe API internals.
- Change subscription billing logic.
- Perform bulk destructive cleanup automatically without validation.
- Alter WooCommerce core behavior outside Stripe synchronization.

---

## 4. Architecture Overview

This will be implemented as a regular WordPress plugin:

```
wp-content/plugins/ntb-stripe-pm-sync/
  ntb-stripe-pm-sync.php              — Plugin header, bootstrap, hook registration
  includes/class-ntb-token-refresher.php  — Core logic: metadata refresh + subscription update
  includes/class-ntb-admin-diagnostics.php — Admin diagnostic page
```

### Why a Regular Plugin (Not MU)

- Easier deployment and rollback.
- No direct server-level FTP dependency.
- Safer for client maintenance.
- Cleaner isolation and versioning.

---

## 5. Functional Design

### 5.1 Trigger Points (Confirmed Hooks)

**Primary Hook — `woocommerce_stripe_add_payment_method`**

- **Purpose:** Eagerly update WC token metadata when a user adds a card with the same fingerprint.
- **Parameters:** `$user_id` (int), `$payment_method_object` (stdClass — full Stripe PM object).
- **Fires at:**
  - `class-wc-stripe-upe-payment-gateway.php:1532` — Add Payment Method page
  - `class-wc-stripe-upe-payment-gateway.php:1758` — Checkout with "save payment method"
  - `class-wc-stripe-upe-payment-gateway.php:2881` — Subscription payment retry
  - `class-wc-stripe-upe-payment-gateway.php:3058` — UPE redirect flow
- **Priority:** 9 (must run before the Stripe subscriptions trait handler at priority 10, and before the page redirect/lazy token sync).
- **Important:** The Stripe subscriptions trait hooks the same action at priority 10 (`trait-wc-stripe-subscriptions.php:64`) to handle the "Update all subscriptions" checkbox. Our hook at priority 9 ensures the token metadata and subscription `_stripe_source_id` are updated before the Stripe trait's handler runs.

**Secondary Hook — `woocommerce_get_customer_payment_tokens`**

- **Purpose:** Catch-all for existing users with stale metadata who haven't re-added their card.
- **Parameters:** `$tokens` (array of WC_Payment_Token), `$user_id` (int), `$gateway_id` (string).
- **Fires at:** `class-wc-payment-tokens.php:88` — every call to `WC_Payment_Tokens::get_customer_tokens()`.
- **Priority:** 11 (runs AFTER the Stripe plugin's sync at priority 10).
- **Note:** The Stripe plugin's sync at priority 10 calls `WC_Stripe_Customer::get_all_payment_methods()` which caches results in a WordPress transient. The secondary hook can read this cached data at near-zero cost.

**Hooks NOT used (and why):**

| Hook | Reason Not Used |
|------|-----------------|
| `woocommerce_payment_token_added` | Does not exist; the correct WC core hook is `woocommerce_new_payment_token`, but it doesn't fire for the same-fingerprint case (no new token is created) |
| `woocommerce_stripe_payment_method_added` | Does not exist |
| `template_redirect` | Would duplicate the Stripe plugin's existing sync work; adds unnecessary Stripe API calls |

---

### 5.2 Metadata Refresh Algorithm

**Primary Hook Logic** (on `woocommerce_stripe_add_payment_method`):

1. Guard: return early if:
   - Kill switch is off: `get_option( 'ntb_stripe_pm_sync_enabled', 'yes' ) !== 'yes'`
   - `$payment_method_object->type !== 'card'` or fingerprint is absent.
2. Retrieve existing WC tokens for the user:
   ```php
   WC_Payment_Tokens::get_tokens([
       'user_id'    => $user_id,
       'gateway_id' => 'stripe',
       'limit'      => 100,
   ]);
   ```
   **Important:** Use `get_tokens()` with explicit limit, NOT `get_customer_tokens()` which is subject to the `posts_per_page` limit (default 10) and can silently fail for users with many saved methods.
3. Find the existing WC token matching the new PM's fingerprint:
   ```php
   foreach ( $tokens as $token ) {
       if ( method_exists( $token, 'get_fingerprint' )
           && $token->get_fingerprint() === $payment_method_object->card->fingerprint ) {
           // Found duplicate
       }
   }
   ```
4. If a matching token is found and any field differs (expiry_month, expiry_year, last4, card_type, or PM ID):
   - Store old values: `$old_pm_id = $token->get_token()`, old expiry, etc.
   - **Step A — Attempt subscription update FIRST (if PM ID differs):**
     - If `$old_pm_id !== $payment_method_object->id`, call `update_subscriptions()` (see Section 5.3).
     - Record whether the subscription update succeeded (`$subs_updated = true/false`).
   - **Step B — Always update display metadata** (regardless of subscription update outcome):
     - `set_expiry_month()`, `set_expiry_year()`, `set_last4()`, `set_card_type()`
   - **Step C — Conditionally update token PM ID:**
     - Only call `set_token( $payment_method_object->id )` if `$subs_updated` is true, or if the PM ID did not change.
     - If `$subs_updated` is false, log a warning and retain the old PM ID (conservative PM ID rule — see Section 6).
   - **Step D — Save once:** `$token->save()` (single save after all field updates).
5. Wrap the entire handler in a try/catch block. Log all actions and exceptions.

**Secondary Hook Logic** (on `woocommerce_get_customer_payment_tokens` at priority 11):

1. Guard: skip if:
   - Kill switch is off: `get_option( 'ntb_stripe_pm_sync_enabled', 'yes' ) !== 'yes'`
   - `$gateway_id` is set and is not a Stripe gateway, or if no card tokens exist.
2. Use a static array keyed by `$user_id` so the secondary hook runs at most once per user per request.
3. Build a `WC_Stripe_Customer` for the user and call `get_all_payment_methods(['card'])`. This uses the Stripe plugin's transient cache — if the Stripe sync just ran at priority 10, this is a cache hit (no API call). **If the cache is cold** (transient expired, first load after cache clear, or a different code path), **this call WILL make a Stripe API call.** Wrap the call in a try/catch: if the fetch fails for any reason (API error, timeout, authentication issue), log the error and return `$tokens` unchanged. No exceptions may bubble up and no metadata is modified on failure. Work is bounded: one API call max per request, tokens limited to 100.
4. Build a map: `stripe_pm_id => pm_object`.
5. For each WC card token in `$tokens`:
   - Look up the token's Stripe PM in the map.
   - Compare the full metadata set: `expiry_month`, `expiry_year`, `last4`, `card_type`. If any field differs from the Stripe PM's data, update all four metadata fields on the token and save.
6. Return the (potentially modified) `$tokens` array.

**Note:** The secondary hook refreshes the same four metadata fields as the primary hook (`expiry_month`, `expiry_year`, `last4`, `card_type`). It does NOT change the token's `token` field (Stripe PM ID) or update subscription meta — those mutations are only performed by the primary hook, which has the full context of the newly-added PM.

---

### 5.3 Subscription Safety

**Critical architecture detail:** Subscriptions store payment method references in three independent locations:

| Location | Key | Description |
|----------|-----|-------------|
| WC_Payment_Token table | `token` field | The WC token's Stripe PM ID |
| Subscription post meta | `_stripe_source_id` | Independent copy of the Stripe PM ID |
| Renewal order post meta | `_stripe_source_id` | Copied from subscription at renewal creation |

Changing a WC token's `token` field does **NOT** propagate to subscription or renewal order meta. If a subscription references `pm_old` via `_stripe_source_id` and `pm_old` is later detached or invalidated, the renewal will fail.

**When the primary hook changes a token's PM ID from `pm_old` to `pm_new`:**

1. Enumerate the user's subscriptions via `wcs_get_users_subscriptions( $user_id )`:
   - For each subscription, check `$subscription->get_meta( '_stripe_source_id', true )` against `$old_pm_id`.
   - Filter to active statuses: `active`, `on-hold`, `pending` (these are the values returned by `$subscription->get_status()`, without the `wc-` prefix)
2. For each matching subscription:
   - Use `WC_Subscriptions_Change_Payment_Gateway::update_payment_method()` if available (at `class-wc-subscriptions-change-payment-gateway.php:489-558`), passing:
     ```php
     [
         'post_meta' => [
             '_stripe_source_id'   => [ 'value' => $new_pm_id ],
             '_stripe_customer_id' => [ 'value' => $stripe_customer_id ],
         ],
     ]
     ```
   - Fall back to direct meta update if the class is not available.
3. Log each subscription update.

**Reference files:**
- Subscription payment meta structure: `trait-wc-stripe-subscriptions.php:714-721`
- Renewal PM resolution: `abstract-wc-stripe-payment-gateway.php:1010-1057`
- Subscription token update: `class-wcs-payment-tokens.php:30-70`

---

### 5.4 Kill Switch

A WordPress option `ntb_stripe_pm_sync_enabled` controls whether the plugin's hooks perform any mutations. Default: `'yes'` (enabled).

- **All hook handlers** (primary and secondary) must check this option at entry and early-return if disabled.
- The admin diagnostics page (see Section 4) must display the current enabled/disabled status and provide a nonce-protected checkbox to toggle it.
- When disabled, the plugin remains loaded (hooks still registered) but every handler exits immediately. No token metadata is updated, no subscriptions are touched. This allows hot-disable without deactivating the plugin.
- When re-enabled, normal behavior resumes on the next trigger.

---

### 5.5 Auto-Idle Mode (Optional)

A constant `NTB_STRIPE_PM_SYNC_AUTO_IDLE` (default `false`) enables automatic detection of whether the upstream Stripe plugin has fixed the metadata refresh bug.

When `NTB_STRIPE_PM_SYNC_AUTO_IDLE` is `true`:

1. Before performing mutations, the primary hook runs a lightweight runtime check:
   - After finding the duplicate WC token by fingerprint, compare **all five fields**: `expiry_month`, `expiry_year`, `last4`, `card_type`, AND the token's PM ID (`$token->get_token()` vs `$payment_method_object->id`).
   - Only skip if **ALL** metadata fields already match the incoming PM's data **AND** the token PM ID already equals the incoming PM ID. This means the upstream Stripe plugin has fixed both the metadata refresh and the PM ID update. Log `"Auto-idle: upstream appears to have fixed metadata refresh — skipping mutations"` and return without changes.
   - If **any** field differs (including PM ID), proceed normally — the bug is still present for that field.

2. The secondary hook performs a similar check: if every WC card token's four display metadata fields (`expiry_month`, `expiry_year`, `last4`, `card_type`) already match the corresponding Stripe PM's data, log idle status and skip. (The secondary hook does not compare PM IDs — it only manages display metadata.)

3. **The kill switch always takes precedence.** If the kill switch is OFF, the plugin is disabled regardless of auto-idle status. If the kill switch is ON and auto-idle detects the upstream fix, the plugin logs and idles but does not disable itself — it will re-engage if a mismatch is detected later.

4. The admin diagnostics page must display the auto-idle status: whether the constant is defined, and whether the plugin is currently idling.

---

## 6. Data Safety Rules

The patch must:

- Never detach Stripe PMs in v1 (deferred to v2 with full subscription audit).
- Never detach a Stripe PM that is referenced by any subscription's `_stripe_source_id`.
- Never hook into, block, or alter WooCommerce "Delete payment method" actions. Deletion is out of scope for v1.
- Always update subscription `_stripe_source_id` **before** changing a WC token's Stripe PM ID.
- **Conservative PM ID change rule:** If the subscription update fails (exception, WCS API unavailable, or any error), do NOT change the token's `token` field (Stripe PM ID). Still update the display metadata (expiry_month, expiry_year, last4, card_type) and log a warning. This ensures the token continues to reference the same PM that subscriptions expect, avoiding renewal failures. The PM ID update is retried on the next trigger.
- Use `WC_Payment_Tokens::get_tokens()` with explicit limit (100), not `get_customer_tokens()` which is subject to the `posts_per_page` limit.
- Never delete the currently default Payment Method.
- Never delete the only remaining Payment Method.
- Wrap all Stripe API interactions and subscription updates in try/catch blocks.

---

## 7. Backward Compatibility

The patch must:

- Work with WooCommerce 9.9.3
- Work with WooCommerce Stripe Gateway 10.0.1 (uses Stripe API version 2024-06-20)
- Work with WooCommerce Subscriptions 6.1.0
- Support PHP 7.4

Avoid:

- Typed properties
- PHP 8+ syntax (union types, named arguments, match expressions)
- Strict return types

API access:

- Use `WC_Stripe_API::request()` for Stripe API calls (handles authentication and API versioning automatically). Do NOT use the Stripe PHP SDK directly.
- Use `WC_Stripe_Customer::get_all_payment_methods()` for fetching customer PMs (handles transient caching via `PAYMENT_METHODS_TRANSIENT_KEY`).

Safety guards:

- Check `class_exists( 'WooCommerce' )` before loading plugin logic.
- Check `class_exists( 'WC_Stripe' )` before loading plugin logic.
- Check `class_exists( 'WC_Stripe_Payment_Token_CC' )` before using fingerprint methods.
- Check `function_exists( 'wcs_get_users_subscriptions' )` before subscription queries.
- Check `class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )` and `method_exists()` before calling `update_payment_method()`.

---

## 8. Logging

Enable debug logging:

- Custom log channel via `wc_get_logger()`, source: `ntb-stripe-pm-sync`
- Behind constant: `NTB_STRIPE_PM_SYNC_DEBUG` (default false)

Log level: `debug` (via `$logger->debug()`)

Log events:

- Fingerprint match detected (old PM ID, new PM ID, fingerprint)
- Token metadata updated (old values → new values for expiry_month, expiry_year, last4, card_type; old PM ID → new PM ID if changed)
- Subscription `_stripe_source_id` updated (subscription ID, old PM ID → new PM ID)
- Secondary hook: Stripe PM fetch source (transient hit vs. cache-cold API call), and result (metadata refreshed, already up-to-date, or fetch failed)
- Any exceptions or guard-clause exits (log at `error` level)

---

## 9. Existing Duplicate Cleanup Strategy

For users who already have stale WC token metadata:

### Behavior After Patch Activation

**No manual action required in most cases.**

- **New card additions (going forward):** The primary hook immediately updates token metadata when a user adds a card with the same fingerprint. The user sees the correct expiry on redirect.
- **Existing stale metadata:** The secondary hook fixes stale metadata on the user's next visit to `/my-account/payment-methods/`. It reads cached Stripe PM data (from the Stripe plugin's sync that runs at priority 10) and refreshes any mismatched metadata (expiry_month, expiry_year, last4, card_type).
- **Admin visibility:** The diagnostic page shows per-user token health (WC expiry vs. Stripe expiry mismatches).

**What the patch does NOT do for existing data:**

- Does not detach old Stripe PMs (deferred to v2).
- Does not remove duplicate WC tokens (the Stripe plugin's existing deduplication already prevents duplicates).
- Does not run bulk cleanup on activation — metadata is refreshed per-user as they visit the payment methods page.

---

## 10. UI Strategy

We will NOT add:

- An "Edit Card" button

Reason:

Stripe best practice = Add new card → Make default → Delete old.

The patch ensures this workflow works cleanly and visibly.

---

## 11. Testing Plan

### Scenario A – Expiry Update (Primary Flow)

1. Add test card 4242424242424242 exp 01/28.
2. Add same card exp 01/30.
3. Confirm:
   - Woo shows 01/30 immediately after redirect.
   - WC token in DB has updated `expiry_month` and `expiry_year`.
   - Token's `token` field points to the new Stripe PM ID.

---

### Scenario B – Multiple Attempts

1. Add same card 3 times with different expiries.
2. Confirm:
   - Only 1 WC token exists for that fingerprint.
   - Woo metadata reflects the newest expiry.

---

### Scenario C – Subscription PM Propagation

1. Create a subscription using card 4242 exp 01/28.
2. Note the subscription's `_stripe_source_id` value (e.g., `pm_old`).
3. Add same card exp 01/30.
4. Confirm:
   - Subscription's `_stripe_source_id` updated to new PM ID.
   - WC token updated with new expiry and PM ID.

---

### Scenario D – Different Card Numbers (No Interference)

1. Add Visa 4242424242424242.
2. Add Mastercard 5555555555554444.
3. Confirm:
   - Both cards appear separately.
   - No unintended merging.

---

### Scenario E – Existing Stale User (Secondary Hook)

1. In the DB, manually set a WC token's `expiry_year` to an incorrect value.
2. Visit `/my-account/payment-methods/`.
3. Confirm:
   - Secondary hook detects mismatch and refreshes all 4 metadata fields (expiry_month, expiry_year, last4, card_type) from Stripe cache.
   - Display shows correct metadata.

---

### Scenario F – Delete Behavior

1. Delete a payment method from My Account.
2. Confirm:
   - Stripe PM is detached (handled by Stripe plugin, not our patch).
   - WC token is removed.
   - No ghost entries remain.
   - If deleted PM was referenced by a subscription, WC Subscriptions' own `maybe_update_subscriptions_payment_meta()` handles reassignment.

---

### Scenario G – Admin Diagnostics Page

1. Navigate to WooCommerce > Stripe PM Sync in wp-admin.
2. Look up a test user by ID or email.
3. Confirm:
   - Token table shows correct data (PM ID, fingerprint, expiry, last4, default status).
   - Staleness indicator highlights mismatches between WC expiry and Stripe expiry.
   - Subscriptions table shows `_stripe_source_id` for each subscription.

---

### Scenario H – Kill Switch

1. Navigate to WooCommerce > Stripe PM Sync in wp-admin.
2. Toggle the "Enabled" checkbox OFF and save.
3. Add same card with new expiry.
4. Confirm:
   - WC token metadata is NOT updated (old expiry persists).
   - Logs show "Kill switch is off — skipping" (if debug enabled).
5. Toggle back ON.
6. Add same card with new expiry again.
7. Confirm:
   - WC token metadata IS updated.

---

### Scenario I – Conservative PM ID Rule

1. Simulate a subscription update failure (e.g., temporarily remove the `WC_Subscriptions_Change_Payment_Gateway` class check, or test with WooCommerce Subscriptions deactivated while a subscription record still references a PM).
2. Add same card with new expiry.
3. Confirm:
   - WC token display metadata (expiry_month, expiry_year, last4, card_type) IS updated.
   - WC token `token` field (Stripe PM ID) is NOT changed.
   - A warning is logged indicating the PM ID change was skipped due to subscription update failure.
   - Subscription `_stripe_source_id` still references the old PM (unchanged).

---

### Scenario J – Plugin Deactivation Safety

1. Deactivate the NTB Stripe PM Sync plugin.
2. Confirm:
   - No errors on payment methods page.
   - No data loss.
   - Behavior reverts to stock WooCommerce Stripe Gateway behavior (stale expiry may reappear for future additions, but existing data is preserved).

---

## 12. Deployment Plan

1. Develop on Local.
2. Test against Stripe Test Mode.
3. Verify:
   - Expiry update issue resolved (Scenario A).
   - Subscription propagation works (Scenario C).
   - No interference with different cards (Scenario D).
   - Delete behavior clean (Scenario F).
4. Zip plugin.
5. Upload to Staging.
6. Monitor logs (`ntb-stripe-pm-sync` channel in WooCommerce > Status > Logs).
7. Deploy to Production.

---

## 13. Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| Subscription renewal fails after PM ID change | CRITICAL | Always update `_stripe_source_id` on all active subscriptions before changing token PM ID. If subscription update fails, do NOT change the token PM ID — still update display metadata and log a warning. |
| Race condition with Stripe plugin's sync | MEDIUM | Primary hook runs at priority 9 during POST (before Stripe subscriptions trait at priority 10 and before page load sync); secondary hook runs after at priority 11; use static flag for once-per-request |
| `posts_per_page` limit blocks token retrieval | MEDIUM | Use `WC_Payment_Tokens::get_tokens()` with explicit limit 100, not the filtered `get_customer_tokens()` |
| Stripe API call overhead in secondary hook | LOW | Uses transient cache from Stripe plugin's sync; one API call max if cache is cold |
| Plugin conflicts with future Stripe plugin update that fixes this bug | LOW | All operations are idempotent; if Stripe fixes metadata refresh, our hook becomes a no-op |
| Stripe API error during PM fetch | LOW | Wrap all calls in try/catch; log errors; degrade gracefully (stale expiry displayed) |
| Unexpected plugin interaction | LOW | Isolated namespace (`NTB\StripePMSync`) + documented hooks + guard clauses |

---

## 14. Estimate Impact

The existing estimate covers:

- Token metadata refresh (expiry synchronization)
- Subscription `_stripe_source_id` propagation
- Deletion confusion resolution
- Admin diagnostic page

No additional scope required unless:

- Client requests PM detachment (v2 scope)
- Client requests UI changes
- Client requests bulk historical audit with automated fixes

---

## 15. Success Criteria

The patch is successful when:

- Adding a replacement card updates expiry immediately in WC display.
- WooCommerce token metadata matches the most recent Stripe PM for each fingerprint.
- Subscription `_stripe_source_id` is updated when the token's PM ID changes.
- Deletion behaves predictably (no "user illusion").
- No subscription renewal issues occur.
- Admin diagnostic page accurately reports token health per user.

---

## 16. Retirement / Sunset Plan

This plugin is designed as a temporary patch. Once the WooCommerce Stripe Gateway plugin fixes the metadata refresh omission upstream, this plugin should be retired. The kill switch and auto-idle features (Sections 5.4 and 5.5) support a controlled retirement process.

### Sunset Playbook

1. **Upgrade the stack.** Update WooCommerce, WooCommerce Stripe Gateway, WooCommerce Subscriptions, and PHP to their latest stable versions per standard upgrade procedures.

2. **Observe with patch still active.** Leave the NTB Stripe PM Sync plugin enabled for at least one billing cycle after the upgrade. Monitor logs for:
   - If `NTB_STRIPE_PM_SYNC_AUTO_IDLE` is enabled: look for `"Auto-idle: upstream appears to have fixed metadata refresh"` log entries.
   - If not: manually test Scenario A (add same card with new expiry) and verify if WC shows the correct expiry without the patch needing to intervene.

3. **Confirm upstream fix.** Verify in the upgraded Stripe plugin source code that `add_token_to_user()` now updates `expiry_month`, `expiry_year`, `last4`, and `card_type` when a fingerprint duplicate is found. Check the release notes or changelog.

4. **Leave patch on to normalize stale metadata.** Keep the plugin enabled for an additional period to allow existing users with stale metadata to have it corrected via the secondary hook as they visit their payment methods page. The admin diagnostic page can track how many users still have stale data.

5. **Disable via kill switch.** Use the diagnostics page to toggle the kill switch OFF. The plugin stays installed and loaded but performs no mutations. Monitor for any issues.

6. **Keep installed but disabled for rollback.** Leave the plugin installed (kill switch OFF) for at least one full billing cycle. If any metadata issues resurface, flip the kill switch back ON.

7. **Uninstall.** Once confident the upstream fix handles all cases, deactivate and delete the plugin via wp-admin. No cleanup is needed — the plugin does not create custom database tables or persistent data beyond the `ntb_stripe_pm_sync_enabled` option (which can be left or deleted via `delete_option`).

### Version-Gating Note

The plugin does not version-check the Stripe gateway plugin. It does not break or change behavior based on the Stripe plugin version. If the upstream fix ships, the plugin's operations become idempotent no-ops (metadata already matches). The auto-idle feature (Section 5.5) provides optional explicit detection.

---

## 17. Implementation Notes for Claude Code

When generating implementation:

- Do NOT modify Woo core files.
- Do NOT modify Stripe plugin files.
- Do NOT build a parallel sync engine — hook into the existing Stripe plugin infrastructure.
- Do NOT detach Stripe PMs in v1.
- Use `WC_Stripe_API` and `WC_Stripe_Customer` classes for Stripe API access (NOT the Stripe PHP SDK directly).
- Namespace plugin as:
  ```
  NTB\StripePMSync
  ```
- Plugin file structure:
  - `ntb-stripe-pm-sync.php` — Plugin header, dependency checks, hook registration, class loading
  - `includes/class-ntb-token-refresher.php` — Core class: primary hook handler, secondary hook handler, subscription updater, logging
  - `includes/class-ntb-admin-diagnostics.php` — Admin diagnostic page under WooCommerce menu

### Key Classes/Methods to Reuse

| Class/Method | File | Purpose |
|---|---|---|
| `WC_Stripe_Payment_Token_CC` | `woocommerce-gateway-stripe/includes/payment-tokens/class-wc-stripe-cc-payment-token.php` | Token class with fingerprint trait; use `get_fingerprint()` |
| `WC_Stripe_Payment_Tokens::get_duplicate_token()` | `woocommerce-gateway-stripe/includes/payment-tokens/class-wc-stripe-payment-tokens.php:785` | Reference for fingerprint matching logic |
| `WC_Stripe_API::get_payment_method()` | `woocommerce-gateway-stripe/includes/class-wc-stripe-api.php:468` | Fetch single PM from Stripe |
| `WC_Stripe_Customer::get_all_payment_methods()` | `woocommerce-gateway-stripe/includes/class-wc-stripe-customer.php:811` | Fetch all customer PMs (uses transient cache) |
| `WC_Stripe_API::detach_payment_method_from_customer()` | `woocommerce-gateway-stripe/includes/class-wc-stripe-api.php:528` | For v2 PM detachment (not used in v1) |
| `WC_Subscriptions_Change_Payment_Gateway::update_payment_method()` | `woocommerce-subscriptions/.../class-wc-subscriptions-change-payment-gateway.php:489` | Proper subscription payment method update |
| `WC_Payment_Tokens::get_tokens()` | `woocommerce/includes/class-wc-payment-tokens.php:33` | Token query (use with explicit limit) |

### Key Hook Signatures

```php
// Primary hook — fires when user adds a PM
do_action( 'woocommerce_stripe_add_payment_method', int $user_id, stdClass $payment_method_object );
// $payment_method_object contains: ->id, ->type, ->card->fingerprint, ->card->exp_month, ->card->exp_year, ->card->last4, ->card->brand, ->card->display_brand, ->card->networks->preferred, ->customer

// Secondary hook — fires when token list is retrieved
$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array $tokens, int $user_id, string $gateway_id );

// Stripe plugin registers its sync at priority 10 on this filter.
// Our plugin should register at priority 11.
```

### DB Schema Reference

**Table: `{prefix}woocommerce_payment_tokens`**
- `token_id` (bigint, PK), `gateway_id` (varchar), `token` (text — the Stripe PM ID), `user_id` (bigint), `type` (varchar), `is_default` (tinyint)

**Table: `{prefix}woocommerce_payment_tokenmeta`**
- Stores: `last4`, `expiry_year`, `expiry_month`, `card_type`, `fingerprint` (as meta keys for each token)

**Subscription post meta:**
- `_stripe_source_id` — The Stripe PM ID used for renewals (independent of WC token)
- `_stripe_customer_id` — The Stripe Customer ID

---

**End of Specification**
