# Phase 2 — Parcels and config

Status: **complete**.

## What was built

- `src/Modules/ShippoFulfillment/ParcelMapper.php` — named parcel definitions (`blt_sce_shippo_parcels` option: id, name, length/width/height, distance unit, weight, mass unit), a SKU → parcel map (`blt_sce_shippo_sku_parcel_map`), and an optional default parcel (`blt_sce_shippo_default_parcel_id`). `resolve(array $skus)` returns exactly one parcel, `null` with `multi_parcel = true` if the order's SKUs resolve to more than one distinct parcel, or `null` with a reason if nothing resolves and there's no default — multi-package orders are explicitly out of scope for v1, so ambiguity always routes to the review queue rather than guessing (`LabelPurchaser` acts on this directly).
- **Weight clarification:** the spec calls the parcel's weight field "optional tare weight," but Shippo's parcel schema requires one `weight` value representing the whole packed parcel, and this build does no per-SKU weight summation (not part of the spec, and SureCart's line-item/price/variant schema has no weight field to sum in the first place). The `weight` field is documented in the settings UI as "the fully packed weight for this parcel type," which is the only way it can function against Shippo's API.
- `src/Modules/ShippoFulfillment/ServiceSelector.php` — `blt_sce_shippo_service_rules` option: a strategy (`cheapest` / `fastest` / `priority`) and an ordered allowed-service-token list. Cheapest/fastest apply within the allowed set (or all returned rates, if the list is empty); priority always picks the first allowed token with any rate, regardless of price/speed.
- Ship-from address: `SettingsPage::ship_from_address()` (`blt_sce_shippo_ship_from` option) — built directly in Shippo's own address field names (`street1`/`street2`/`zip`/…) since it's consumed only by `ShippoClient`, unlike the destination address which comes from SureCart and needs field-name translation (see tasks/00-discovery.md §C).
- JSON export/import: `SettingsPage`'s Export/Import tab serializes every module-config option (parcels, SKU map, service rules, guardrails, ship-from address, reconciliation threshold) to JSON and can re-import it — explicitly **excludes** the Shippo API token, which is entered per-site. This lets a working setup replicate across client sites without re-entry, per spec.

## Admin

All of the above is configured on the "Parcels & Mapping", "Service Rules", and "General" tabs of `src/Admin/SettingsPage.php` — plain server-rendered forms (no build step, no JS framework), each tab independently POSTed and nonce-checked.
