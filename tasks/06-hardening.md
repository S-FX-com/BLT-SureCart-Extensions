# Phase 6 — Hardening

Status: **complete**, via an independent code-review audit against the fully-built plugin (not a self-review) plus PHPCS WordPress-Extra.

## PHPCS

`vendor/bin/phpcs --standard=phpcs.xml.dist src blt-surecart-extensions.php uninstall.php` — **0 errors, 0 warnings** (WordPress-Extra + PHPCompatibilityWP, testVersion 7.4-). `phpcbf` handled all formatting; a handful of real findings required manual fixes (see below).

## Real issues found and fixed

An independent audit pass (fresh read of every file, adversarial — not "does this look okay" but "what's actually wrong") found the following, all fixed before this was committed:

| Severity | Issue | Fix |
|---|---|---|
| Critical | `ShippoWebhookController::verify_request()` computed the HMAC signature check but discarded its boolean result, unconditionally returning `true` — the advertised HMAC layer provided zero actual protection. | Return `maybe_verify_hmac()`'s result directly. |
| High | The GET-triggered "Purchase now" action in `ReviewQueuePage::render()` processed the action but never redirected afterward — reloading the page could resubmit the same click and enqueue a second purchase job for the same shipment before the first had a chance to flip its status. | Redirect (PRG pattern) after handling the action, matching `Module::handle_shipment_row_action()`'s existing pattern. |
| High | The live Shippo API token and the webhook HMAC secret were both rendered into `value="..."` on their settings fields on every page load — full plaintext in page source/history/autofill, contradicting the adjacent "never sent to the browser" claim. | Both fields render empty with a masked placeholder hint (token: last 4 characters only); a blank submit now means "keep the existing value" rather than "clear it." |
| Medium | The nonce action string for shipment row actions (`blt_sce_shipment_action_{id}`) didn't include the verb, so a nonce obtained for one action (e.g. Retry) could be replayed against a different action (e.g. Void) on the same shipment. Same issue in `ModulesPage` (one nonce shared across every module's toggle). | Scoped nonces to `{action}_{id}` and `{action}_{slug}` respectively. |
| Medium | `SettingsPage::save_import()` wrote every recognized key from a pasted JSON blob straight into `update_option()` with no shape validation, unlike every other `save_*` method in the file. | Added a per-option expected-PHP-type map; mismatched types are skipped and reported back, not silently stored. |
| Medium | The fulfillment-notification email body was a raw, untranslated string. | Wrapped in `__()` with a translators comment. |
| Low | Double-escaped static em dash (`esc_html('&#8212;')`) rendered literally instead of as an em dash in the Review Queue table. | Only escape the dynamic branch. |
| Low | `_n()`/`__()` used inconsistently for two adjacent countable Site Health strings. | Both now use `_n()`. |
| Low | `Support\Money::decimal_string_to_cents()` truncated a 3rd+ decimal digit instead of rounding (e.g. `"12.567"` → 1256¢ instead of 1257¢). | Rewrote using integer milli-cent math with half-up rounding — still no float arithmetic. |

A real correctness bug was also caught independently while re-reading the purchase flow (not from the audit, from a second pass on the same code): `LabelPurchaser::void()` checked only for a transport-level `WP_Error` from the refund call, never Shippo's own `status` field in a 200 OK response — a `status: ERROR` refund (e.g. "shipment already used") would have been silently recorded as `voided` locally. Fixed to treat `ERROR` as a real failure. Separately, the attempt counter was incrementing on *every* job run — including guardrail holds and auto-purchase-off stops that never touch Shippo at all — meaning a shipment sitting in the review queue could exhaust its retry budget and get marked `failed` purely from repeated manual "Purchase now" clicks, with no actual API failures involved. Moved the increment to immediately before the first external call in the pipeline (the SureCart order fetch), so only genuine transient-failure retries consume the budget.

## What PHP 8.4 caught that 7.4-targeted code review didn't

Running against this environment's live PHP 8.4 surfaced one implicit-nullable-parameter deprecation (`array $body = null` → `?array $body = null` in `ShippoClient::request()`) that `testVersion 7.4-` compatibility checking doesn't flag, since the syntax is valid (if implicitly deprecated) there. Worth periodically running the real target PHP version, not just PHPCompatibility static analysis.

## Known limitation

No live WordPress + SureCart + Shippo test-mode environment was available in this build environment, so none of the above was exercised end-to-end at runtime — only via static analysis (`php -l`, PHPCS), and manual + independent-agent code review. The manual test matrix from the build spec still needs to be run against a real staging site before this ships to a client.

## Uninstall

`uninstall.php` only drops tables/options if `SettingsPage::OPT_DELETE_ON_UNINSTALL` was explicitly opted into (Settings → Export/Import → Danger zone). Default: uninstalling leaves all shipment history and settings intact.
