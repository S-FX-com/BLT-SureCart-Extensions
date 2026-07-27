# Phase 3 — Label purchase

Status: **complete**.

## Flow (`src/Modules/ShippoFulfillment/LabelPurchaser.php`)

1. `Module::on_order_updated()` fires on `surecart/order_updated` (tasks/00-discovery.md §A). It checks `$order->status === 'paid'` itself (SureCart has no dedicated hook), then calls `ShipmentRepository::find_or_create_for_order()` — an `INSERT IGNORE` against the UNIQUE `surecart_order_id` key, so a duplicate firing can never create a second row. Only a row that is still in its just-created `pending` state triggers an Action Scheduler enqueue; anything already quoted/held/purchased/failed is left alone so repeat `order_updated` firings (refunds, unrelated edits) can't re-trigger a guardrail hold or re-attempt a failed purchase.
2. `LabelPurchaser::process()` — the job itself — re-checks `shippo_transaction_id` before doing anything, so even if the same job were somehow enqueued twice, only one ever reaches Shippo.
3. Kill switch and token/mode-match are checked before the attempt counter increments — a halted or misconfigured site never burns down its retry budget.
4. Order → shipping context via `SureCartGateway` (PHP models, confirmed synchronous — fine inside a job, not fine in a request).
5. SKUs → parcel via `ParcelMapper`; unresolvable/multi-parcel → review queue.
6. Address validated via Shippo (`GET /v2/addresses/validate`); destination checked against the country allowlist and military-address setting.
7. `POST /shipments` (`async: false`) → rates quoted and persisted; `ServiceSelector` picks one.
8. Guardrails (§5) evaluated on the picked rate; a manual "Purchase now" click (from the Review Queue) is the only thing that bypasses a guardrail hold — the kill switch and token/mode check are never bypassable, even manually.
9. If auto-purchase is off and this isn't a manual click: stops here, status `quoted`.
10. `POST /transactions` → label purchased, persisted (`shippo_transaction_id`, `tracking_number`, `tracking_url`, `label_url`, `carrier`, `service_token`, `amount_cents`).
11. SureCart fulfillment created (see tasks/04). A failure at this specific step never rolls back or re-attempts the purchase — the label is real money already spent — it's logged loudly for manual follow-up instead.

## Retries

Bounded at `LabelPurchaser::MAX_ATTEMPTS` (5), exponential backoff (`60 * 2^attempt`, capped at 1 hour) via `Scheduler::schedule_single()`. After the ceiling, the row is marked `failed` and stops — never an infinite retry against a paid API. A misconfigured token (mode mismatch) fails immediately without consuming retry attempts, since retrying won't fix a config problem.

## Void

`LabelPurchaser::void()` — admin-only, explicit action (never automatic on refund/cancel — spec requires a deliberate action). Calls `POST /refunds/`, marks the local row `voided`, and best-effort updates the SureCart fulfillment (see tasks/04 for why this is inherently approximate).

## Idempotency proof points

- DB-level UNIQUE constraint on `surecart_order_id` (not just application logic).
- `shippo_transaction_id IS NOT NULL` short-circuits `process()` before any HTTP call.
- Manual retry (`LabelPurchaser::retry()`) explicitly resets `attempts` and `status` — a failed shipment never silently resumes on its own.
