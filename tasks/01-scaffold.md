# Phase 1 — Scaffold

Status: **complete**.

## What was built

- `blt-surecart-extensions.php` — bootstrap: constants, Composer autoloader, Action Scheduler require, `plugins_loaded` boot, activation/deactivation hooks. Declares `Requires Plugins: surecart` (WP 6.5+ native dependency header, keyed on SureCart's real WordPress.org slug `surecart`) as the primary activation gate; `Plugin::dependencies_met()` is a defensive secondary check for older WP versions.
- `src/Plugin.php` — singleton bootstrap: wires the module registry, loads the textdomain, initializes `Scheduler` and `UpdateChecker`.
- `src/Modules/ModuleInterface.php` / `ModuleRegistry.php` — each module declares a slug, label, description, other-module dependencies, and environment `unmet_requirements()`. The registry resolves boot order by dependency, stores enabled/disabled state in one option (`blt_sce_enabled_modules`), and only calls a module's `boot()` when it's enabled *and* its requirements are satisfied. Disabling a module simply means its `boot()` is never called that request — no hooks are ever registered, so there's nothing to unhook.
- `src/Db/Schema.php` + `ShipmentRepository.php` — the `{prefix}blt_sce_shipments` table exactly as specified (UNIQUE on `surecart_order_id`, enforced at the DB level via `INSERT IGNORE`, not just in application logic), plus a second `{prefix}blt_sce_logs` table (shipment-scoped structured log, backing the admin log viewer and the "every API call logged" engineering rule).
- `src/Support/Logger.php`, `Scheduler.php`, `Money.php`, `UpdateChecker.php` — cross-cutting infrastructure with no WordPress-hook side effects of their own (Scheduler is a thin wrapper over Action Scheduler's procedural API; Money does decimal-safe cents conversion since Shippo returns dollar-decimal strings).

## Activation guards

- SureCart active: `Requires Plugins` header (native WP gate) + `class_exists('\SureCart\SureCart')` / `is_plugin_active('surecart/surecart.php')` fallback.
- Minimum version: **not hard-enforced**. See tasks/00-discovery.md's closing note — no live SureCart install was available to test compatibility across versions, so pinning a floor would itself be an invented number. `BLT_SCE_SURECART_VERSION_AT_BUILD_TIME` records SureCart's real published version (4.6.2, WP.org stable tag) as of this build for reference only.

## Settings storage

- Per-site options (`wp_options`), never network-wide.
- Shippo API token: `BLT_SCE_SHIPPO_API_TOKEN` wp-config constant takes precedence; falls back to an option (autoload disabled) if not defined. Never localized to JS, never readable below `manage_options` (Settings page checks capability before rendering; the option itself carries no special protection beyond that, matching how WordPress options normally work — the point is nothing reads it into a JS payload).
- Self-hosted updates: `src/Support/UpdateChecker.php`, see tasks/00-discovery.md §E.

## Local schema

Implemented exactly as specified in the build doc — see `src/Db/Schema.php`. No columns added or removed.
