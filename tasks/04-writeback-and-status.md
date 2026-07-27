# Phase 4 — Write-back and status sync

Status: **complete**. Scope was set entirely by the tasks/00-discovery.md §B findings, per the build spec's own instruction not to build this before confirming what SureCart accepts.

## Write-back (`SureCartGateway::create_fulfillment()` / `update_fulfillment()`)

- `POST /v1/fulfillments` with `order`, `fulfillment_items[]` (`line_item` + `quantity`), and `trackings[]` (`number` + `url`) — the only confirmed-writable tracking fields.
- **No `carrier` field exists anywhere on the fulfillment/tracking schema.** Carrier is stored and shown only in this plugin's own local `shipments` table and admin UI; SureCart's own order screen will show a tracking link without a carrier label from us. This is a hard API limitation, not an oversight.
- `shipment_status` is sent best-effort on every create/update (structurally part of the writable schema, but undocumented whether SureCart honors it — see discovery §B item 2). Nothing in this plugin ever depends on SureCart actually applying it; our own `blt_sce_shipments.status` is authoritative everywhere in our own logic and UI.

## Status sync (`StatusSync.php`)

One documented mapping table, `SHIPPO_TO_LOCAL_STATUS`, covers Shippo's confirmed-exhaustive tracking statuses (`PRE_TRANSIT`, `TRANSIT`, `DELIVERED`, `RETURNED`, `FAILURE`; `UNKNOWN` deliberately never downgrades a known status):

| Shippo `tracking_status.status` | Local status |
|---|---|
| `PRE_TRANSIT` | `shipped` |
| `TRANSIT` | `in_transit` |
| `DELIVERED` | `delivered` |
| `RETURNED` | `exception` (SureCart push: `returned`) |
| `FAILURE` | `exception` (SureCart push: `failed`) |

A second table, `LOCAL_TO_SURECART_SHIPMENT_STATUS`, maps our local status back to SureCart's fulfillment `shipment_status` enum for the best-effort push. Neither the happy path nor `exception`/`returned` is skipped, per spec.

## Webhook registration

Shippo webhook registration is **account-wide**, not per-shipment (discovery §D) — resolved as a single `POST /webhooks` call (`event: track_updated`), idempotency-guarded in `ShippoClient::ensure_webhook_registered()` by listing existing webhooks first (Shippo's own registration is explicitly *not* idempotent on URL, unlike SureCart's). Runs from a daily Action Scheduler job (`Module::ENSURE_WEBHOOK_HOOK`), never inline. For Shippo-purchased labels, no further per-shipment registration is needed — tracking starts automatically once the account webhook exists.

## REST route (`Rest/ShippoWebhookController.php`)

`POST /wp-json/blt-sce/v1/shippo-tracking` — see the security discussion in `SettingsPage`'s "Tracking webhook security" section and tasks/00-discovery.md §D for why URL-token + optional IP allowlist are the default mechanisms and HMAC is optional/best-effort.

## Reconciliation sweep

`StatusSync::reconcile()`, every 15 minutes via Action Scheduler (`StatusSync::RECONCILE_HOOK`). Pulls shipments stuck in a non-terminal status (anything but `delivered`/`voided`/`failed`) for longer than the configured threshold (default 6 hours) and re-checks each via `GET /tracks/{carrier}/{tracking_number}` directly — this is what makes the data trustworthy when a webhook is missed, and is not made redundant by anything found in discovery (SureCart never independently re-polls tracking state).

One caveat worth flagging: the carrier slug for that GET call is derived from the stored `service_token`'s prefix (e.g. `usps` from `usps_priority`) since Shippo's Rate object has no separate explicit carrier-token field distinct from the display name (`provider`, e.g. `"USPS"`) — this is a reasonable, commonly-used heuristic, not a documented field, and is called out here rather than presented as verified.
