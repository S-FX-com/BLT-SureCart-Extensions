# CLAUDE.md

Guidance for Claude Code (or any AI agent) working in this repository.

## What this is

**BLT SureCart Extensions** — an umbrella extension plugin for SureCart (a WordPress e-commerce plugin), built by S-FX.com Small Business Solutions for deployment across multiple client sites. Private plugin: distributed via private GitHub Releases + `plugin-update-checker`, never the WordPress.org directory.

Slug: `blt-surecart-extensions` · PHP prefix: `blt_sce_` · Text domain: `blt-surecart-extensions` · Namespace: `BLT\SCE`.

This build ships one module: **Shippo Fulfillment** — purchases a Shippo shipping label after a SureCart order is paid, writes tracking back to SureCart, and keeps shipment status in sync. It does **not** touch checkout rate calculation or order totals — that's explicitly out of scope, permanently, not just for this build.

## Before you touch anything: read `tasks/00-discovery.md`

Every SureCart hook/endpoint/field name and every Shippo endpoint/field name this plugin uses was verified against real documentation before being written — recorded in `tasks/00-discovery.md` with quoted evidence. **Never invent a hook, event, endpoint, or field name.** If you need one that isn't already verified there, go verify it the same way (fetch the real docs, quote the exact source) before writing code against it — don't guess from convention or memory, even a confident-seeming one. Several "obvious" guesses turned out wrong during this build (e.g. SureCart's fulfillment schema has no `carrier` field at all; Shippo's rate `amount` is a decimal-dollar string, not cents; PressShip is a WordPress.org SVN tool, not a GitHub-releases tool).

## Architecture

```
blt-surecart-extensions.php   Bootstrap: constants, Composer autoload, Action Scheduler require, activation hooks
src/Plugin.php                 Singleton: module registry, textdomain, Scheduler/UpdateChecker init
src/Modules/                   ModuleRegistry + ModuleInterface — each module is independently enable/disable-able
  ShippoFulfillment/            The one module in this build
    Module.php                  ALL WordPress hooks for this module live here — everything else below is hook-free
    LabelPurchaser.php           Orchestrates the purchase flow (Action Scheduler job)
    ParcelMapper.php              SKU -> parcel resolution
    ServiceSelector.php           Rate selection strategy
    Guardrails.php                Every §5 safety check
    StatusSync.php                Shippo status <-> local status <-> SureCart shipment_status mapping
    ReviewQueue.php                Held-shipment queries + manual-purchase enqueue
src/Api/
  ShippoClient.php               All Shippo HTTP, logged, timed out, never called outside a job
  SureCartGateway.php             SureCart PHP models (Order, Fulfillment) — also synchronous, also job-only
src/Rest/ShippoWebhookController.php   Tracking webhook receiver + security
src/Admin/                       Settings, Modules, Shipments list table, Review Queue, Site Health — all server-rendered, no build step
src/Db/                          Schema (dbDelta) + ShipmentRepository (all $wpdb access lives here, nowhere else)
src/Support/                     Logger, Scheduler (Action Scheduler wrapper), Money (decimal-safe cents), UpdateChecker
```

## Non-negotiable rules (from the build spec — still apply to any future change)

1. **All external HTTP (Shippo, SureCart PHP models) happens inside an Action Scheduler job.** Never in a customer-facing request, never in an admin page render. `SureCartGateway`'s model calls are synchronous/blocking by SureCart's own design — that's fine inside a job, never fine anywhere else.
2. **Money is integer cents, no floats.** Shippo returns rate amounts as decimal-dollar strings (`"5.50"`) — always convert through `Support\Money::decimal_string_to_cents()`, never `(int) ($x * 100)`.
3. **Idempotency is DB-enforced**, not just application logic — the UNIQUE key on `surecart_order_id` in `Db\Schema` is the actual defense against a double-purchased label. Don't add a second write path that bypasses `ShipmentRepository::find_or_create_for_order()`.
4. **`auto_purchase` defaults off.** Any new purchase path must still route through `Guardrails` and land in the review queue when guardrails aren't satisfied or auto-purchase is off.
5. **The kill switch (`Guardrails::is_halted()`) is an absolute stop** — it must be checked first, before anything else, in any code path that could result in a Shippo purchase, and it is never bypassable (not even by an explicit manual admin action, unlike a guardrail hold).
6. Nonce + capability check on every admin action; sanitize input, escape output; every string user-facing goes through `__()`/`esc_html__()` with the `blt-surecart-extensions` text domain.
7. Uninstall never destroys shipment history unless the site owner has explicitly opted in (`SettingsPage::OPT_DELETE_ON_UNINSTALL`).

## Adding a second module

Register it in `Plugin::init()` alongside `ShippoFulfillmentModule`. It needs its own slug, its own admin submenu(s) under `blt-sce-modules`, and its own `unmet_requirements()`. Nothing in `ModuleRegistry` needs to change — that's the point of it.

## Local dev / CI

- `composer install` — vendors `yahnis-elsts/plugin-update-checker` and `woocommerce/action-scheduler` (gitignored; rebuilt fresh in CI from the committed `composer.lock`).
- `php -l` every file you touch — there's no PHPUnit test suite in this build (a real WordPress install + SureCart + Shippo test-mode account would be needed to test any of this end-to-end; see the manual test matrix in the original build spec / `tasks/*.md`).
- `.github/workflows/release.yml` builds a zip and publishes a GitHub Release whenever the `Version:` header in `blt-surecart-extensions.php` changes on `main` — bump that header (and the `readme.txt` Stable tag) together when you want a release to go out.
