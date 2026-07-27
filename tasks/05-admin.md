# Phase 5 — Admin

Status: **complete**.

- **Shipments list table** (`Admin/ShipmentsListTable.php`, rendered from `Module::render_shipments_page()`): order, status (colored), carrier, tracking (linked to the carrier URL), cost (formatted from integer cents), label download link, row actions (Purchase now / Retry / Void, each capability- and nonce-checked, shown only for the statuses where the action is valid), status filter views, search by order id/tracking number.
- **Review queue** (`Admin/ReviewQueuePage.php` + `Modules/ShippoFulfillment/ReviewQueue.php`): shipments in `review` or `quoted`, one-click "Purchase now" that enqueues an Action Scheduler job (never a blocking inline purchase from an admin request).
- **Modules page** (`Admin/ModulesPage.php`): enable/disable per module, shows unmet requirements inline (e.g. "no Shippo token configured") so a stuck module explains itself instead of just silently not running.
- **Settings page** (`Admin/SettingsPage.php`): General / Parcels & Mapping / Service Rules / Guardrails / Export-Import tabs, each independently posted and nonce-checked.
- **Failure surfacing** (`Admin/SiteHealth.php`): an `admin_notices` banner on this plugin's own screens and the dashboard when any shipment has hit `failed`, plus a WP Site Health test (`critical` on any failed shipment, `recommended` when the review queue has grown past 5 — usually means a guardrail is blocking most orders).
- **Log viewer**: `Module::render_log_viewer()`, scoped to one shipment, reading from the dedicated `blt_sce_logs` table (`Logger::for_shipment()`).
