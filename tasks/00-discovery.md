# Phase 0 — Discovery Findings

Status: **complete**. Every question in spec §3 is answered below from primary sources (SureCart developer docs + OpenAPI specs, Shippo developer docs + OpenAPI spec, plugin-update-checker README, PressShip README). Sources are cited inline. Two items remain genuinely unverifiable from docs alone (flagged as such) and are handled defensively in code rather than guessed.

---

## A. The order-paid trigger (§3.1)

**Use the WP hook, not a webhook.** Confirmed hook:

```php
add_action( 'surecart/order_updated', function ( $order, $data ) {
    // $order is \SureCart\Models\Order — the full model object, not just an ID.
    // $data is the raw event payload (object).
}, 10, 2 );
```

There is **no dedicated "paid" or "fulfillment needed" hook** — `surecart/order_updated` fires on every order update, and SureCart's own documented example is to check `$order->status === 'paid'` inside the generic handler. That's exactly what `Module::on_order_updated()` does (see §04). SureCart's Orders actions-and-filters page documents exactly five hooks total: `order_created`, `order_updated`, `charge_created`, `refund_created`, `invoice_created` — no filters. (Source: `documentation/actions-filters/orders.md`.) We did not check Purchases/Checkout/Subscriptions hook pages for a fulfillment-specific hook; the llms.txt category index gives no indication one exists, but this is UNVERIFIED-as-absent rather than confirmed absent.

Fallback path (not used, but confirmed available if the WP hook is ever unavailable — e.g. programmatic order updates that don't fire WP actions): SureCart webhooks. `POST /v1/webhook_endpoints` with body `{"webhook_endpoint": {"url": ..., "webhook_events": [...]}}` is confirmed idempotent on `url` verbatim: *"If a webhook endpoint already exists with the same `url` then it will be updated and returned."* The real event name for a paid order is confirmed as `order.paid` (seen literally in `api-reference/events/list.md`'s example payload, attached to an order whose own `status` is `paid`). We are not building this path in v1 since the WP hook fully covers the requirement and needs no public endpoint or signature verification (spec's own stated preference).

## B. The fulfillment write-back (§3.2) — the biggest unknown, now resolved

**`POST /v1/fulfillments`**, body wrapped in a `fulfillment` object:

```
fulfillment: {
  order: "<order UUID>",
  fulfillment_items: [ { line_item: "<line item UUID>", quantity: 1 } ],
  trackings: [ { number: "<tracking number>", url: "<tracking url or null>" } ]
}
```

`PATCH /v1/fulfillments/{id}` accepts the same shape for updates (e.g. adding/replacing `trackings[]` once a label is purchased after the fulfillment was created).

Findings that change the plan:

1. **There is no `carrier` field anywhere on the fulfillment or tracking schema.** Only `number` and `url` are writable on `trackings[]`. A read-only `courier_name` exists on the *response* shape but cannot be set. **Consequence: carrier is tracked only in our own local `shipments` table**, not written back to SureCart. The admin UI shows it; SureCart's own order screen will just show a tracking link without a carrier label from us.
2. **`shipment_status` writability is UNVERIFIED.** It's structurally part of the same schema used by the create/update request body (no `readOnly` marker exists anywhere in the 556 KB spec), but no example payload ever sets it and no prose confirms the API honors a client-supplied value. Per the spec's own instruction ("do not build an elaborate status-sync engine before confirming"), **`StatusSync` attempts to set it as best-effort on every fulfillment update, and never treats a failure to change it as an error** — our local `blt_sce_shipments.status` column is the authoritative status everywhere in our own admin UI and guardrail logic. If SureCart silently ignores the field, nothing breaks; if it honors it, the order's shipment_status in SureCart's own admin will also advance.
3. **`POST /v1/trackings` does not exist.** Confirmed via the full path listing in `openapi/v1/shipping.json`: `/v1/trackings` and `/v1/trackings/{id}` are GET-only. Trackings are read-only as an independent resource and are only writable as the nested `fulfillment.trackings[]` sub-array. This matches the spec's prediction exactly.
4. **A surprise finding not anticipated by the spec:** the same `shipping.json` spec also defines a separate `/v1/shipments` resource (`shipment_response`) with real `carrier`, `tracking_number`, `tracking_url`, and `tracking_status` fields, plus a `PATCH /v1/shipments/{id}/purchase` endpoint (`rate_id` + `label_file_type`) — i.e. SureCart has its own native rate/label/tracking object model, explicitly built "for use with a shipping provider (e.g. Shippo)". **We are deliberately not using it.** The build spec (§1) explicitly puts SureCart's native live-rating/shipping system out of scope ("in development," "this plugin does not touch checkout totals"), and nothing in the docs confirms a third-party plugin can create `shipping_provider` connections or drive this resource without an admin-side provider connection SureCart itself controls. Using it would be a materially different, larger integration than what was commissioned. Flagging this for awareness: if SureCart ships this feature natively, a future module could retire much of the Shippo Fulfillment module's manual plumbing in favor of it.

Fulfillment items: exact field names are `line_item` (UUID) + `quantity` (int) — confirmed identical in create and update schemas.

## C. Reading order data (§3.3)

- **Use the PHP models**, confirmed the documented approach: `SureCart\Models\Order`, `::with([...])->find($id)` etc. Every model class is under the `SureCart\Models` namespace (confirmed ~50+ classes including `Order`, `Fulfillment`, `FulfillmentItem`, `LineItem`, `Customer`). Docs explicitly warn model calls are synchronous/blocking HTTP and should only be used somewhere blocking is acceptable — "Cron jobs and background tasks" is explicitly listed as fine, which is exactly where `LabelPurchaser` runs (Action Scheduler job).
- **Expand array**, confirmed against `orders/retrieve.md` and cross-checked against a real-world example in `documentation/admin-ui.md`:
  `['checkout', 'checkout.line_items', 'checkout.shipping_address', 'line_item.price', 'line_item.variant', 'price.product']`
  (Expansions max 2 levels deep, up to 15 total — this fits easily.)
- **SKU location:** field is exactly `sku`, and it exists on **both** the product and variant objects (not on price). *"If the Product has variants, each variant has a specific sku"* — so resolution order is: use `variant.sku` if the line item has a variant, else fall back to `price.product.sku`.
- **SureCart address schema fields** (confirmed from `openapi/v1/orders.json`'s `address_body`): `name`, `line_1`, `line_2`, `city`, `state`, `postal_code`, `country`. **These do not match Shippo's address field names** (`street1`/`street2`/`zip`) — `SureCartGateway` maps `line_1→street1`, `line_2→street2`, `postal_code→zip` explicitly; there's no `phone` or `company` field on SureCart's address at all, so those are left blank when building the Shippo `address_to` (harmless — optional on Shippo's side).
- **Order total for the guardrail percent check:** SureCart's order object itself carries no total; the total lives on the expanded `checkout.total_amount` (confirmed integer, already in cents — no float conversion needed, unlike Shippo's rate amounts).
- **Rate limits:** 150 req / 10s default, 10 req / min on "sensitive" endpoints, 60 req / min on public endpoints; 429 on excess.
- **Test/live mode:** a boolean `live_mode` field on order/checkout objects (not a header, not a key prefix). We don't gate our own behavior on this — our guardrail mode check is entirely about the *Shippo* token, per §5.
- **SureCart version:** the plugin's own `readme.txt` on WordPress.org (`plugins.svn.wordpress.org/surecart/trunk/readme.txt`) declares Stable tag `4.6.2`, Requires at least WP `6.8`, Requires PHP `7.4`, as of this build's date. This is recorded as "the version current at build time," **not** a verified-compatible floor — there was no live SureCart install available in this environment to actually exercise `surecart/order_updated`, the PHP models, or the address/line-item schema against a running site. Activation only hard-gates on SureCart being active at all (`class_exists('\SureCart\SureCart')`); confirm against a real staging site running an older SureCart version before assuming this module works below 4.6.2.

## D. Shippo specifics (§3.4)

- **`POST /shipments`** (`https://api.goshippo.com/shipments/`): requires `address_from`, `address_to`, `parcels[]`; `async: false` returns `rates[]` inline synchronously (confirmed verbatim in the response schema description). Parcel fields: `length`, `width`, `height`, `distance_unit` (`in`/`cm`), `weight`, `mass_unit` (`lb`/`kg`/`oz`/`g`) — all strings in the example payload.
- **Rate object fields** (from the full OpenAPI spec, not present in the doc page itself): `object_id`, `amount` (**string decimal dollars**, e.g. `"5.50"` — not cents, not a float; converted via a dedicated decimal-safe helper, never `(int)($x*100)`), `currency`, `provider` (carrier display name, e.g. `"USPS"`), `servicelevel.token` (e.g. `usps_priority`) + `servicelevel.name`, `estimated_days`, `attributes[]` (`CHEAPEST`/`FASTEST`/`BESTVALUE` — Shippo itself flags these, which `ServiceSelector` uses directly instead of re-deriving them).
- **`POST /transactions`**: body `{ rate: "<rate object_id>", async: false, label_file_type: "PDF", order: "<optional metadata>" }`. Response fields: `object_id` (transaction id), `tracking_number`, `label_url`, `tracking_url_provider`, `status` (`WAITING|QUEUED|SUCCESS|ERROR|REFUNDED|REFUNDPENDING|REFUNDREJECTED`), `test` (bool).
- **Auth:** header `Authorization: ShippoToken <token>`. Confirmed prefixes: live keys begin `shippo_live_`, test keys begin `shippo_test_`.
- **Rate limits:** 500 POST/PUT per minute live (50 test), 4000 GET-single per minute live (400 test), across Shipment/Rate/Transaction/Refund resources; Tracking is 750/50.
- **Address validation:** the *current* endpoint is `GET /v2/addresses/validate` with query params (`address_line_1`, `city_locality`, `state_province`, `postal_code`, `country_code` — a **different field naming scheme** than the v1 Address object used for shipments!) and returns `analysis.validation_result.value` = `valid|partially_valid|invalid` (an enum, not the legacy boolean `validation_results.is_valid`). We treat `valid` as pass, `partially_valid`/`invalid` as fail (goes to review per guardrail rules). The docs note address validation "may require separate account enablement/pricing" — this is called out because a 403/404 from this specific endpoint should be treated as "could not validate" (→ review), not a hard error.
- **Refunds/void:** `POST /refunds/` with body `{ transaction: "<transaction object_id>" }`. No literal "void" endpoint exists — refund is the only mechanism. Response `status`: `QUEUED|PENDING|SUCCESS|ERROR`.
- **Tracking webhooks — account-wide only, not per-shipment.** `POST /webhooks` with `{ event: "track_updated", url: "..." }` (also `transaction_created`, `transaction_updated`, `batch_created`, `batch_purchased`, `all`). One-time setup, not per-label. For labels purchased *through* Shippo, tracking updates start flowing automatically to the registered webhook — no separate per-shipment registration call is needed (that's only required for externally-tracked, non-Shippo-purchased shipments via `POST /tracks`, which this module never does).
- **Tracking webhook payload:** status lives at `tracking_status.status`, one of exactly **`PRE_TRANSIT, TRANSIT, DELIVERED, RETURNED, FAILURE, UNKNOWN`** — no literal `EXCEPTION` value exists. `FAILURE` is the closest analogue and is what `StatusSync` maps to our local `exception` status (see mapping table in §04). Finer detail is in a nested `tracking_status.substatus{code,text,action_required}`.
- **Webhook security — three options, in order of strength:**
  1. HMAC-SHA256 (Shippo calls this "Recommended"), but **requires emailing Shippo support to request setup** ("HMAC Webhook Setup," up to 10 business days turnaround) — not self-service, and the exact wire header name/casing could not be confirmed from docs (only a CGI-style placeholder `HTTP_SHIPPO_AUTH_SIGNATURE` was found, which is an env-var rendering, not necessarily the literal header). **UNVERIFIED: literal header name.**
  2. Self-generated URL token, configured in the Shippo dashboard, appended as a query string param on the callback URL — self-service, no support ticket needed.
  3. IP allowlist (Shippo publishes fixed outbound IPs).
  
  Because HMAC requires a manual account-level setup step we cannot automate or verify the header name for, **`ShippoWebhookController` implements options 2 and 3 (URL token + IP allowlist) as the default, self-service security**, and layers in HMAC verification only if the site owner has actually completed Shippo's manual setup and entered a shared secret in settings — done defensively (checks a documented set of *candidate* header name variants, since we can't confirm one canonical casing) and never relied upon as the sole guard.

## E. plugin-update-checker (YahnisElsts/plugin-update-checker)

- Current major version: **v5** (`v5.7` is latest at time of writing). Namespace: `YahnisElsts\PluginUpdateChecker\v5\PucFactory`. Old `Puc_v4_Factory` naming is gone.
- Integration: `PucFactory::buildUpdateChecker($repoUrl, __FILE__, $slug)`, then `->setBranch('main')`, then `->setAuthentication($token)` for a private repo, then `->getVcsApi()->enableReleaseAssets()` to pull the zip from a GitHub *Release asset* rather than a raw branch zip — exactly what our release workflow publishes.
- Minimum PHP `>=5.6.20` + `ext-json`; no documented minimum WP version.
- We bundle it via Composer (`yahnis-elsts/plugin-update-checker`) rather than a manual vendor copy, per the README's own "if you use the Composer autoloader you don't need to explicitly require the library" guidance.

## F. PressShip (Automattic/pressship) — important scope note

**PressShip is a WordPress.org SVN publishing CLI, not a GitHub-releases tool.** Confirmed from its own README: it validates `readme.txt`, runs the WordPress.org "Plugin Check" linter, packages a zip, and its `submit`/`release`/`publish` commands push through `plugins.svn.wordpress.org` — the public plugin directory's own review + SVN pipeline. It documents **no GitHub Actions integration and no GitHub Releases support at all** (confirmed by grepping its README for "GitHub Action" / `uses: Automattic` — zero matches; its own SVN-release flow is described step by step and never mentions GitHub). Its `login`/`submit`/`release` commands also require an interactive WordPress.org browser login, which doesn't work headless in CI.

**This plugin is private** — S-FX-branded, deployed to specific client sites, updated via private GitHub Releases + plugin-update-checker, never intended for the public WordPress.org directory. Submitting it there would be actively wrong.

**Resolution:** PressShip is integrated for what it's actually good for and safe to automate — `pressship verify` (readme.txt + Plugin Check linting) and `pressship pack` (zip packaging, respecting a `.pressshipignore`) as a non-blocking quality gate in CI. Its WordPress.org submit/release/publish/login commands are **not used** — the actual "create a GitHub Release" requirement is handled by a dedicated, standard GitHub Actions release job (see `.github/workflows/release.yml`), independent of PressShip. This is flagged here explicitly in case the intent was ever public WordPress.org distribution — if so, that's a different, larger conversation (public listing requires GPL-compatible bundled dependencies, no hardcoded client behavior, etc.) than what this build implements.

---

## Net effect on the build

- Order-paid trigger: `surecart/order_updated` WP hook, checked for `status === 'paid'`, per §A.
- Fulfillment write-back: tracking number + URL only, via `fulfillment.trackings[]`; carrier stays local-only; `shipment_status` set best-effort, never load-bearing.
- Reconciliation sweep (spec §4/tasks/04) remains fully justified and is **not** made redundant by anything found here — SureCart never independently re-fetches tracking state, so our own sweep is the only thing that ever re-checks a shipment if a webhook is missed.
- Webhook security defaults to URL token + IP allowlist (self-service), with optional HMAC layered in for sites that complete Shippo's manual setup.
- PressShip used for packaging/linting only; GitHub Releases handled by a dedicated workflow.

No hook, endpoint, or field name in the code that follows was invented — everything above traces to a quoted primary source.
