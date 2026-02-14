# Claude Code Implementation Prompt — NTB Stripe PM Sync Plugin

You are Claude Code. Implement the NTB Stripe PM Sync plugin based on this prompt and the companion `STRIPE-PM-SYNC-SPEC.md` in the same directory.

---

## Goal

Create a small regular WordPress plugin that patches the **metadata refresh omission** in WooCommerce Stripe Gateway's fingerprint deduplication. When a customer adds a card with the same card number (Stripe fingerprint) but a new expiration date, the plugin ensures:

1. The WC token's metadata (expiry_month, expiry_year, last4, card_type) is updated to match the newest Stripe PM.
2. The WC token's `token` field (Stripe PM ID) is updated to point to the newest PM.
3. Any active subscription whose `_stripe_source_id` references the old PM is updated to the new PM.
4. Existing users with stale metadata get it refreshed on their next visit to the payment methods page.

---

## Confirmed Root Cause (Do Not Re-Investigate)

The bug is in the WooCommerce Stripe Gateway plugin's existing fingerprint deduplication code. You do NOT need to explore or re-investigate the codebase — the root cause is confirmed and all relevant code references are provided below.

**Location:** `woocommerce-gateway-stripe/includes/payment-tokens/class-wc-stripe-payment-tokens.php`

**Function:** `add_token_to_user()` (lines 549-661)

**What it does right:** At line 553, it calls `get_duplicate_token()` (lines 785-805) which loops through existing WC tokens and compares fingerprints via `is_equal_payment_method()`. If a match is found, it reuses the existing token (correct — one WC token per fingerprint).

**What it does wrong:** Lines 554-562 — when a duplicate is found:

- It only conditionally updates the `token` field (Stripe PM ID), and only when the old PM no longer exists in Stripe.
- It **never** updates `expiry_month`, `expiry_year`, `last4`, or `card_type`.
- Result: the WC token displays stale expiry data even though Stripe has a newer PM.

**Token creation is lazy:** WC tokens are NOT created during the "Add Payment Method" action. They are created/synced when the payment methods page loads, via the `woocommerce_get_customer_payment_tokens` filter in `woocommerce_get_customer_upe_payment_tokens()` (lines 264-384).

---

## Important Constraints

- Must NOT modify WooCommerce core or Stripe plugin files.
- Must NOT build a parallel sync engine — hook into the existing Stripe plugin infrastructure.
- Must NOT detach Stripe PMs in v1 (deferred to v2 with full subscription safety audit).
- Must NOT hook into, block, or modify WooCommerce "Delete payment method" actions. Deletion is entirely managed by WooCommerce core and the Stripe plugin. This plugin is a metadata refresh + subscription meta safety patch only.
- Must update subscription `_stripe_source_id` when a token's Stripe PM ID changes.
- **Conservative PM ID rule:** If the subscription update fails or WCS APIs are unavailable, do NOT change the token's Stripe PM ID. Still update display metadata (expiry_month, expiry_year, last4, card_type) and log a warning. This avoids orphaning subscriptions from their referenced PM.
- Must be deployable as a normal plugin zip via wp-admin.
- Must not require modifying theme code.
- Must be safe: no destructive DB ops, no background jobs that can break renewals.
- Must never delete the currently default Payment Method or the only remaining Payment Method.
- Must wrap all Stripe API interactions, token operations, and subscription updates in try/catch blocks — never let plugin errors break the Add Payment Method or payment methods display flows.
- Must work for existing users who already have stale metadata.
- PHP 7.4 compatible: no typed properties, no union types, no named arguments, no match expressions, no strict return types.

---

## Plugin File Structure

```
wp-content/plugins/ntb-stripe-pm-sync/
  ntb-stripe-pm-sync.php                  — Plugin header, bootstrap, hook registration
  includes/class-ntb-token-refresher.php   — Core: primary + secondary hooks, subscription updater, logging
  includes/class-ntb-admin-diagnostics.php — Admin diagnostic page
```

Namespace: `NTB\StripePMSync`

---

## Implementation Plan

### Step 1: Plugin Bootstrap (`ntb-stripe-pm-sync.php`)

1. Standard WordPress plugin header (Plugin Name, Version 1.0.0, etc.).
2. Define constants:
   - `NTB_STRIPE_PM_SYNC_VERSION` = `'1.0.0'`
   - `NTB_STRIPE_PM_SYNC_DIR` = `plugin_dir_path( __FILE__ )`
   - Do NOT define `NTB_STRIPE_PM_SYNC_DEBUG` or `NTB_STRIPE_PM_SYNC_AUTO_IDLE` — these are user-defined in `wp-config.php`. The plugin checks `defined()` at runtime.
3. Dependency check on `plugins_loaded`:
   ```php
   if ( ! class_exists( 'WooCommerce' ) ) return;
   if ( ! class_exists( 'WC_Stripe' ) ) return;
   ```
4. Require class files from `includes/`.
5. Instantiate `NTB\StripePMSync\Token_Refresher` and `NTB\StripePMSync\Admin_Diagnostics`.

### Step 2: Token Refresher Class (`includes/class-ntb-token-refresher.php`)

**Namespace:** `NTB\StripePMSync`

**Constructor — register hooks:**

```php
// Primary: fires when user adds a PM via UPE (priority 9 to run before Stripe's subscriptions trait at priority 10)
add_action( 'woocommerce_stripe_add_payment_method', [ $this, 'on_payment_method_added' ], 9, 2 );

// Secondary: fires when token list is retrieved (after Stripe sync at priority 10)
add_filter( 'woocommerce_get_customer_payment_tokens', [ $this, 'refresh_stale_metadata' ], 11, 3 );
```

#### Method: `on_payment_method_added( $user_id, $payment_method_object )`

This is the primary hook handler. It runs when a user adds a payment method.

**Parameters received:**

- `$user_id` (int) — WordPress user ID
- `$payment_method_object` (stdClass) — Full Stripe PM object containing:
  - `->id` (string, e.g., `pm_xxx`)
  - `->type` (string, e.g., `card`)
  - `->customer` (string, e.g., `cus_xxx`)
  - `->card->fingerprint` (string)
  - `->card->exp_month` (int)
  - `->card->exp_year` (int)
  - `->card->last4` (string)
  - `->card->brand` (string)
  - `->card->display_brand` (string|null)
  - `->card->networks->preferred` (string|null)

**Logic:**

1. **Guard:** Return early if:
   - **Kill switch is off:** `get_option( 'ntb_stripe_pm_sync_enabled', 'yes' ) !== 'yes'` — log and return.
   - `$payment_method_object->type !== 'card'`
   - `empty( $payment_method_object->card->fingerprint )`
   - `! class_exists( 'WC_Stripe_Payment_Token_CC' )`

2. **Find existing WC token by fingerprint:**

   ```php
   $tokens = WC_Payment_Tokens::get_tokens([
       'user_id'    => $user_id,
       'gateway_id' => 'stripe',
       'limit'      => 100,
   ]);
   ```

   Loop through `$tokens`. For each, check:

   ```php
   if ( $token instanceof WC_Stripe_Payment_Token_CC
       && method_exists( $token, 'get_fingerprint' )
       && $token->get_fingerprint() === $payment_method_object->card->fingerprint
   ) {
       // Found matching token
   }
   ```

3. **If matching token found:**
   - Store old values: `$old_pm_id = $token->get_token()`, `$old_exp_month`, `$old_exp_year`.
   - Determine the card type:
     ```php
     $card_type = strtolower(
         $payment_method_object->card->display_brand
         ?? $payment_method_object->card->networks->preferred
         ?? $payment_method_object->card->brand
     );
     ```
   - Check if anything actually changed (avoid unnecessary saves):
     ```php
     $needs_update = (
         $token->get_token() !== $payment_method_object->id
         || $token->get_expiry_month() !== (string) $payment_method_object->card->exp_month
         || $token->get_expiry_year() !== (string) $payment_method_object->card->exp_year
         || $token->get_last4() !== $payment_method_object->card->last4
         || $token->get_card_type() !== $card_type
     );
     ```
   - **Auto-idle check** (if `NTB_STRIPE_PM_SYNC_AUTO_IDLE` is defined and true):
     - If `! $needs_update` (i.e., all five fields — expiry_month, expiry_year, last4, card_type, AND token PM ID — already match the incoming PM), the upstream Stripe plugin has fixed both metadata refresh and PM ID update. Log `"Auto-idle: upstream appears to have fixed metadata refresh — skipping mutations"` and return. If ANY field differs (including PM ID), proceed normally.
   - If update needed:
     - **Step A: Try to update subscriptions FIRST, before changing the token PM ID:**
       - `$subs_updated = true;`
       - If `$old_pm_id !== $payment_method_object->id`:
         - Call `$this->update_subscriptions(...)` inside a try/catch. If it throws or returns false, set `$subs_updated = false` and log a warning.
     - **Step B: Always update display metadata** (even if subscription update failed):
       - `$token->set_expiry_month( $payment_method_object->card->exp_month )`
       - `$token->set_expiry_year( $payment_method_object->card->exp_year )`
       - `$token->set_last4( $payment_method_object->card->last4 )`
       - `$token->set_card_type( $card_type )`
     - **Step C: Only change the token PM ID if subscriptions were successfully updated (or if PM ID didn't change):**
       - If `$subs_updated` or `$old_pm_id === $payment_method_object->id`:
         - `$token->set_token( $payment_method_object->id )`
       - Else:
         - Log warning: `"Skipping token PM ID change from {$old_pm_id} to {$payment_method_object->id} because subscription update failed — display metadata updated, PM ID retained for renewal safety"`
     - `$token->save()`
     - Log the update.

   **Wrap the entire method body in a try/catch block.** Log exceptions and return gracefully — never let an error in this plugin break the Add Payment Method flow.

4. **If no matching token found:** Do nothing (the Stripe plugin's lazy sync will create a new token on the next page load).

#### Method: `update_subscriptions( $user_id, $old_pm_id, $new_pm_id, $stripe_customer_id )`

Updates `_stripe_source_id` on all active subscriptions that reference the old PM. **Returns `true` on success, `false` if any subscription could not be updated.** Throws no exceptions — catches internally and returns `false`.

**Logic:**

1. **Guard:** Return `true` (no-op success) if:
   - `! function_exists( 'wcs_get_users_subscriptions' )` — WCS not installed; no subscriptions to update.
   - `$old_pm_id === $new_pm_id`

2. **Get user's subscriptions:**

   ```php
   $subscriptions = wcs_get_users_subscriptions( $user_id );
   ```

3. **For each subscription:**
   - Skip if not in status `active`, `on-hold`, or `pending` (use `$subscription->has_status([ 'active', 'on-hold', 'pending' ])` — these are the short-form values without the `wc-` prefix).
   - Read `$sub_source_id = $subscription->get_meta( '_stripe_source_id', true )`.
   - If `$sub_source_id === $old_pm_id`:
     - **Preferred:** Use `WC_Subscriptions_Change_Payment_Gateway::update_payment_method()` if available:
       ```php
       if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )
           && method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'update_payment_method' )
       ) {
           WC_Subscriptions_Change_Payment_Gateway::update_payment_method(
               $subscription,
               'stripe',
               [
                   'post_meta' => [
                       '_stripe_source_id'   => [ 'value' => $new_pm_id ],
                       '_stripe_customer_id' => [ 'value' => $stripe_customer_id ],
                   ],
               ]
           );
       }
       ```
     - **Fallback:** Direct meta update:
       ```php
       $subscription->update_meta_data( '_stripe_source_id', $new_pm_id );
       if ( $stripe_customer_id ) {
           $subscription->update_meta_data( '_stripe_customer_id', $stripe_customer_id );
       }
       $subscription->save();
       ```
     - Log: `"Updated subscription #{$sub_id} _stripe_source_id from {$old_pm_id} to {$new_pm_id}"`

#### Method: `refresh_stale_metadata( $tokens, $user_id, $gateway_id )`

Secondary hook handler. Refreshes metadata for existing users with stale data.

**Logic:**

1. **Guards:**
   - **Kill switch:** Return `$tokens` unchanged if `get_option( 'ntb_stripe_pm_sync_enabled', 'yes' ) !== 'yes'`.
   - Return `$tokens` unchanged if `$gateway_id` is set and does not start with 'stripe' (e.g. `strpos( $gateway_id, 'stripe' ) !== 0`).
   - Return if no card tokens in the array.
   - Use a function-level static array keyed by `$user_id` so the secondary hook runs at most once per user per request (e.g. `static $refreshed = []; if ( isset( $refreshed[ $user_id ] ) ) return $tokens; $refreshed[ $user_id ] = true;`). Do not use a class static property.

2. **Fetch Stripe PMs (cached):**

   ```php
   if ( ! class_exists( 'WC_Stripe_Customer' ) ) {
       return $tokens;
   }
   $customer = new WC_Stripe_Customer( $user_id );
   try {
       $stripe_pms = $customer->get_all_payment_methods( [ 'card' ] );
   } catch ( Exception $e ) {
       $this->log( 'Secondary hook: failed to fetch PMs - ' . $e->getMessage() );
       return $tokens;
   }
   ```

   This uses the Stripe plugin's transient cache. If the Stripe sync at priority 10 just ran, the transient is warm — no API call. **If the cache is cold** (transient expired or first load after cache clear), this call WILL make a Stripe API call. The try/catch above ensures safe failure: if the fetch fails for any reason, log the error and return `$tokens` unchanged — no exceptions bubble up, no metadata is modified. Work is bounded: one API call max per request, tokens limited to 100.

3. **Build PM map:** `$pm_map[ $pm->id ] = $pm`

4. **For each WC card token:**
   - If `$token` is not a `WC_Stripe_Payment_Token_CC`, skip.
   - Look up `$pm = $pm_map[ $token->get_token() ]`.
   - If not found, skip (the token may point to a PM not in the list — not our concern).
   - Determine card type from PM: `strtolower( $pm->card->display_brand ?? $pm->card->networks->preferred ?? $pm->card->brand )`
   - Compare the full metadata set — if **any** of these differ, update all four fields:
     - `$pm->card->exp_month` vs `$token->get_expiry_month()`
     - `$pm->card->exp_year` vs `$token->get_expiry_year()`
     - `$pm->card->last4` vs `$token->get_last4()`
     - card type vs `$token->get_card_type()`
   - If update needed:
     - **Auto-idle check** (if `NTB_STRIPE_PM_SYNC_AUTO_IDLE` is true): if NO tokens needed updating across the entire loop, log idle status after the loop and skip saves.
     - `$token->set_expiry_month( $pm->card->exp_month )`
     - `$token->set_expiry_year( $pm->card->exp_year )`
     - `$token->set_last4( $pm->card->last4 )`
     - `$token->set_card_type( $card_type )`
     - `$token->save()`
     - Log the refresh.
   - **Note:** The secondary hook does NOT change the token's `token` field (PM ID) or update subscription meta. Those mutations are only performed by the primary hook which has the full context of a newly-added PM.

5. Return `$tokens`.

#### Method: `log( $message )`

```php
private function log( $message ) {
    if ( ! defined( 'NTB_STRIPE_PM_SYNC_DEBUG' ) || ! NTB_STRIPE_PM_SYNC_DEBUG ) {
        return;
    }
    $logger = wc_get_logger();
    $logger->debug( $message, [ 'source' => 'ntb-stripe-pm-sync' ] );
}
```

### Step 3: Admin Diagnostics Page (`includes/class-ntb-admin-diagnostics.php`)

**Namespace:** `NTB\StripePMSync`

**Constructor — register admin page:**

```php
add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
```

**Method: `register_admin_page()`**

Register as a WooCommerce submenu page:

```php
add_submenu_page(
    'woocommerce',
    'Stripe PM Sync Diagnostics',
    'Stripe PM Sync',
    'manage_woocommerce',
    'ntb-stripe-pm-sync',
    [ $this, 'render_page' ]
);
```

**Method: `render_page()`**

**Top section — Plugin Status & Kill Switch:**

1. Display the current plugin status:
   - Kill switch: ON / OFF (read from `get_option( 'ntb_stripe_pm_sync_enabled', 'yes' )`)
   - Auto-idle constant: defined / not defined (check `defined( 'NTB_STRIPE_PM_SYNC_AUTO_IDLE' )` and its value)
   - Debug logging: ON / OFF (check `defined( 'NTB_STRIPE_PM_SYNC_DEBUG' )` and its value)
2. Render a nonce-protected form with a checkbox to toggle the kill switch:
   ```php
   // On form submission:
   if ( isset( $_POST['ntb_stripe_pm_sync_nonce'] )
       && wp_verify_nonce( $_POST['ntb_stripe_pm_sync_nonce'], 'ntb_stripe_pm_sync_toggle' )
   ) {
       $enabled = isset( $_POST['ntb_stripe_pm_sync_enabled'] ) ? 'yes' : 'no';
       update_option( 'ntb_stripe_pm_sync_enabled', $enabled );
   }
   ```
3. Use standard WP admin notice styling for the status display.

**User diagnostics section:**

1. Accept user input: WordPress user ID or email (via GET parameter).
2. Resolve user ID from email if needed.
3. Fetch WC tokens for the user:
   ```php
   WC_Payment_Tokens::get_tokens([
       'user_id'    => $user_id,
       'gateway_id' => 'stripe',
       'limit'      => 100,
   ]);
   ```
4. Fetch Stripe PMs via `WC_Stripe_Customer::get_all_payment_methods(['card'])`.
5. Render a table showing for each WC token:
   - Token ID
   - Stripe PM ID (`token` field)
   - Fingerprint
   - Last4
   - Card type
   - Stored expiry (from WC token meta)
   - Stripe expiry (from Stripe PM, if found)
   - Staleness indicator (mismatch = highlight red)
   - Is default (yes/no)
6. Below the token table, render a subscriptions table:
   - Subscription ID
   - Status
   - `_stripe_source_id` value
   - Whether the referenced PM exists in Stripe (yes/no)
   - Whether it matches a WC token (yes/no)
7. Wrap Stripe API calls in try/catch. Display errors gracefully.
8. No "fix" or "cleanup" buttons in v1 — the diagnostics section is read-only. The only writable control is the kill switch toggle at the top.
9. Use standard WP admin CSS classes for styling (no custom CSS needed).

---

## Backwards Compatibility Checklist

Before using any class or function, check existence:

| Check                                                       | Before Using                              |
| ----------------------------------------------------------- | ----------------------------------------- |
| `class_exists( 'WooCommerce' )`                             | Any WC functionality                      |
| `class_exists( 'WC_Stripe' )`                               | Any Stripe gateway functionality          |
| `class_exists( 'WC_Stripe_Payment_Token_CC' )`              | Fingerprint methods (`get_fingerprint()`) |
| `class_exists( 'WC_Stripe_Customer' )`                      | PM fetching and caching                   |
| `function_exists( 'wcs_get_users_subscriptions' )`          | Subscription queries                      |
| `class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )` | Subscription payment method update        |
| `method_exists( $token, 'get_fingerprint' )`                | Per-token fingerprint access              |

If WooCommerce Subscriptions is not installed, the plugin should still work — it just skips the subscription update logic.

---

## Existing Users with Stale Data

**Question:** What happens for users who already have stale expiry data?

**Answer:** The secondary hook (`woocommerce_get_customer_payment_tokens` at priority 11) fixes stale metadata automatically on the user's next visit to the payment methods page. It reads cached Stripe PM data and compares against WC token metadata. No user action required — no need to re-add a card.

**Question:** Do we need a cleanup tool for already-duplicated WC tokens?

**Answer:** No. The Stripe plugin's existing `get_duplicate_token()` already prevents duplicate WC tokens for the same fingerprint. Multiple tokens per fingerprint should not exist in current installations. If they do (from older plugin versions), the admin diagnostic page will reveal them.

---

## Risk Assessment

| Risk                                                               | Severity     | Mitigation                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------ | ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Subscription `_stripe_source_id` not updated when token PM changes | **CRITICAL** | The `update_subscriptions()` method queries all active subs and updates `_stripe_source_id` before the page redirect. Uses `WC_Subscriptions_Change_Payment_Gateway::update_payment_method()` which fires proper hooks for audit trail. If subscription update fails, the token PM ID is NOT changed (conservative rule) — display metadata is still updated. |
| Race condition between primary hook and Stripe plugin sync         | MEDIUM       | Primary hook fires at priority 9 during POST (before redirect and before the Stripe subscriptions trait at priority 10). Sync runs on the next GET (page load). By the time sync runs, the token is already updated. Static flag prevents secondary hook from overwriting.                                                                                    |
| `posts_per_page` limit causes token retrieval to miss tokens       | MEDIUM       | Use `WC_Payment_Tokens::get_tokens()` with `'limit' => 100`, not `get_customer_tokens()` which applies the `woocommerce_get_customer_payment_tokens_limit` filter (default: `posts_per_page`, often 10).                                                                                                                                                      |
| Secondary hook makes extra Stripe API calls                        | LOW          | `WC_Stripe_Customer::get_all_payment_methods()` uses WordPress transient cache (`PAYMENT_METHODS_TRANSIENT_KEY`). After the Stripe sync at priority 10, the transient is warm. If cold, one API call is made (not per-token).                                                                                                                                 |
| Future Stripe plugin update fixes this bug, causing double-update  | LOW          | All metadata updates are idempotent. Setting expiry to the same value is a no-op. The plugin becomes harmless if Stripe fixes the bug.                                                                                                                                                                                                                        |
| `get_fingerprint()` method doesn't exist on a token                | LOW          | Guard with `method_exists( $token, 'get_fingerprint' )` and `$token instanceof WC_Stripe_Payment_Token_CC`.                                                                                                                                                                                                                                                   |

---

## Key Codebase References

These are the exact files and line numbers in the installed plugins. Use these references directly — do NOT re-explore the codebase.

### WooCommerce Stripe Gateway (`woocommerce-gateway-stripe/`)

| File                                                               | Key Lines | What                                                                                                                                                                                                                                |
| ------------------------------------------------------------------ | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `includes/payment-tokens/class-wc-stripe-payment-tokens.php`       | 549-661   | `add_token_to_user()` — the function with the bug                                                                                                                                                                                   |
| Same file                                                          | 554-562   | The duplicate-found branch that doesn't update metadata                                                                                                                                                                             |
| Same file                                                          | 785-805   | `get_duplicate_token()` — fingerprint comparison logic                                                                                                                                                                              |
| Same file                                                          | 264-384   | `woocommerce_get_customer_upe_payment_tokens()` — lazy sync filter                                                                                                                                                                  |
| Same file                                                          | 48-53     | Constructor hooks (priority 10 for `woocommerce_get_customer_payment_tokens`)                                                                                                                                                       |
| `includes/payment-tokens/class-wc-stripe-cc-payment-token.php`     | 1-44      | `WC_Stripe_Payment_Token_CC` class with fingerprint trait                                                                                                                                                                           |
| `includes/payment-tokens/trait-wc-stripe-fingerprint.php`          | 1-30      | `get_fingerprint()` and `set_fingerprint()` methods                                                                                                                                                                                 |
| `includes/class-wc-stripe-customer.php`                            | 811+      | `get_all_payment_methods()` with transient caching                                                                                                                                                                                  |
| Same file                                                          | 990+      | `clear_cache()` method                                                                                                                                                                                                              |
| Same file                                                          | 1010-1012 | `get_id_from_meta()` — retrieves `_stripe_customer_id` from user meta                                                                                                                                                               |
| `includes/class-wc-stripe-api.php`                                 | 468-476   | `get_payment_method()`                                                                                                                                                                                                              |
| Same file                                                          | 528-548   | `detach_payment_method_from_customer()` (for v2)                                                                                                                                                                                    |
| `includes/payment-methods/class-wc-stripe-upe-payment-gateway.php` | 1532      | `woocommerce_stripe_add_payment_method` action (Add PM page). **Note:** The Stripe subscriptions trait also hooks this action at priority 10 (`trait-wc-stripe-subscriptions.php:64`). Our plugin hooks at priority 9 to run first. |
| Same file                                                          | 1758      | Same action (checkout save)                                                                                                                                                                                                         |
| Same file                                                          | 2881      | Same action (subscription retry)                                                                                                                                                                                                    |
| Same file                                                          | 3058      | Same action (UPE redirect)                                                                                                                                                                                                          |
| `includes/compat/trait-wc-stripe-subscriptions.php`                | 64        | Subscriptions trait hooks `woocommerce_stripe_add_payment_method`                                                                                                                                                                   |
| Same file                                                          | 165-203   | `handle_upe_add_payment_method_success()` — only runs if user checks "update all subscriptions"                                                                                                                                     |
| Same file                                                          | 714-721   | Subscription payment meta structure (`_stripe_source_id`, `_stripe_customer_id`)                                                                                                                                                    |
| `includes/abstracts/abstract-wc-stripe-payment-gateway.php`        | 1010-1057 | `prepare_order_source()` — how renewals resolve which PM to charge                                                                                                                                                                  |

### WooCommerce Core (`woocommerce/`)

| File                                                         | Key Lines | What                                                                                      |
| ------------------------------------------------------------ | --------- | ----------------------------------------------------------------------------------------- |
| `includes/class-wc-payment-tokens.php`                       | 33-58     | `get_tokens()` — base query method                                                        |
| Same file                                                    | 68-89     | `get_customer_tokens()` — filtered version (subject to `posts_per_page` limit at line 84) |
| Same file                                                    | 88        | `woocommerce_get_customer_payment_tokens` filter (our secondary hook point)               |
| Same file                                                    | 200-211   | `set_users_default()` — fires `woocommerce_payment_token_set_default`                     |
| `includes/payment-tokens/class-wc-payment-token-cc.php`      | 36-41     | Token `extra_data`: last4, expiry_year, expiry_month, card_type                           |
| `includes/data-stores/class-wc-payment-token-data-store.php` | 134-139   | Token deletion (fires `woocommerce_payment_token_deleted`)                                |

### WooCommerce Subscriptions (`woocommerce-subscriptions/`)

| File                                                           | Key Lines | What                                                                       |
| -------------------------------------------------------------- | --------- | -------------------------------------------------------------------------- |
| `vendor/.../class-wc-subscriptions-change-payment-gateway.php` | 489-558   | `update_payment_method()` — proper subscription PM update with audit trail |
| `vendor/.../class-wcs-payment-tokens.php`                      | 30-70     | `update_subscription_token()` — token replacement on subscriptions         |
| `vendor/.../class-wcs-my-account-payment-methods.php`          | 101-142   | Token deletion handler for subscriptions                                   |
| `vendor/.../wcs-order-functions.php`                           | 860-875   | `wcs_copy_payment_method_to_order()` — copies sub meta to renewal order    |

---

## Testing Strategy

After implementation, manually test these scenarios in Stripe Test Mode:

1. **Expiry Update:** Add card 4242424242424242 exp 01/28, then add same card exp 01/30. WC should show 01/30 immediately.
2. **Multiple Attempts:** Add same card 3 times with different expiries. Only 1 WC token should exist for that fingerprint; WC shows the latest expiry.
3. **Subscription Propagation:** Create subscription, add same card with new expiry, verify `_stripe_source_id` updated on subscription.
4. **Different Cards:** Add Visa 4242 and Mastercard 5555555555554444. Both should appear separately.
5. **Delete Behavior:** Delete a card, verify clean removal with no ghost entries. The plugin must NOT intercept or alter this flow.
6. **Stale Metadata (Secondary Hook):** Manually edit a token's expiry in DB, visit payment methods page, verify all 4 metadata fields (expiry_month, expiry_year, last4, card_type) are corrected.
7. **Admin Diagnostics:** Navigate to WooCommerce > Stripe PM Sync, verify the kill switch toggle works (ON/OFF), look up a test user, verify the table shows correct data with staleness indicators.
8. **Kill Switch:** Toggle kill switch OFF via diagnostics page. Add same card with new expiry. Verify metadata is NOT updated. Toggle ON. Add again. Verify it IS updated.
9. **Conservative PM ID Rule:** Temporarily break subscription updates (e.g., by mocking a failure). Add same card with new expiry. Verify display metadata (expiry) IS updated but token PM ID is NOT changed. Verify log warning.
10. **Plugin Deactivation:** Deactivate the plugin, verify no errors on payment methods page.

---

## Retirement Note

This plugin is designed as a temporary patch. See `STRIPE-PM-SYNC-SPEC.md` Section 16 ("Retirement / Sunset Plan") for the full sunset playbook. The kill switch (Section 5.4) and auto-idle mode (Section 5.5) are the implementation mechanisms that support retirement. Ensure both features work correctly.

---

**End of Implementation Prompt**
