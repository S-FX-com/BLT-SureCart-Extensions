# CLAUDE.md

Guidance for Claude Code (or any AI agent) working in this repository.

## What this is

**BLT SureCart Extensions** — an umbrella extension plugin for SureCart (a WordPress e-commerce plugin), built by S-FX.com Small Business Solutions for deployment across multiple client sites. Private plugin: distributed via private GitHub Releases + `plugin-update-checker`, never the WordPress.org directory.

Slug: `blt-surecart-extensions` · PHP prefix: `blt_sce_` · Text domain: `blt-surecart-extensions` · Namespace: `BLT\SCE`.

This build ships four modules, each independently toggleable from the Modules screen:

- **Shippo Fulfillment** — purchases a Shippo shipping label after a SureCart order is paid, writes tracking back to SureCart, and keeps shipment status in sync. It does **not** touch checkout rate calculation or order totals — that's explicitly out of scope, permanently, not just for this build.
- **Restrict Price by Role** — hides SureCart prices from users without an allowed WordPress role and rejects restricted prices at checkout. Consolidated from the standalone `SureCart-RestrictPriceByRole` repo; it deliberately keeps that plugin's `scrpbr_restrictions` option key so existing site data carries over with zero migration.
- **Reports** — generates a **Fulfillment Report** CSV: every order in a date range (optionally narrowed to selected products) collapsed into one row per customer with a quantity column per product variant, plus a TOTALS row for placing the manufacturing order. Read-only — it never writes to SureCart and never touches checkout. The one thing to know before changing it: **`GET /v1/orders` has no date filter and no sort parameter** (exhaustively verified in `tasks/00-discovery.md` §H), so the date range is applied in PHP against `order.created_at` and the job cannot early-exit on a date boundary. Don't "optimize" that into an early break — page order is undefined and you'd silently drop orders.
- **Make an Offer** — eBay-style offers with a Stripe-vaulted card, charged off-session on acceptance. Implemented from the `Blt-SureCart-Offers` repo's scaffold spec (that repo contained no code). After a successful charge, the offer is back-filled into SureCart as a real order via the manually-paid-checkout flow (`OrderRecorder` — ad_hoc price + create/finalize(manual)/manually_pay, all verified in `tasks/00-discovery.md` §G). Orders can't be created directly (SureCart's Orders API is list/retrieve only), and native checkouts expose no authorize-only switch, which is why the charge itself stays on the module's own Stripe integration.

## Before you touch anything: read `tasks/00-discovery.md`

Every SureCart hook/endpoint/field name and every Shippo endpoint/field name this plugin uses was verified against real documentation before being written — recorded in `tasks/00-discovery.md` with quoted evidence. **Never invent a hook, event, endpoint, or field name.** If you need one that isn't already verified there, go verify it the same way (fetch the real docs, quote the exact source) before writing code against it — don't guess from convention or memory, even a confident-seeming one. Several "obvious" guesses turned out wrong during this build (e.g. SureCart's fulfillment schema has no `carrier` field at all; Shippo's rate `amount` is a decimal-dollar string, not cents; PressShip is a WordPress.org SVN tool, not a GitHub-releases tool).

## Architecture

```
blt-surecart-extensions.php   Bootstrap: constants, Composer autoload, Action Scheduler require, activation hooks
src/Plugin.php                 Singleton: module registry, textdomain, Scheduler/UpdateChecker init
src/Modules/                   ModuleRegistry + ModuleInterface — each module is independently enable/disable-able
                               (optional boot_admin() keeps a module's settings screens reachable when its
                               requirements are unmet, so API keys can be entered)
  ShippoFulfillment/
    Module.php                  ALL WordPress hooks for this module live here — everything else below is hook-free
    LabelPurchaser.php           Orchestrates the purchase flow (Action Scheduler job)
    ParcelMapper.php              SKU -> parcel resolution
    ServiceSelector.php           Rate selection strategy
    Guardrails.php                Every §5 safety check
    StatusSync.php                Shippo status <-> local status <-> SureCart shipment_status mapping
    ReviewQueue.php                Held-shipment queries + manual-purchase enqueue
  RestrictPriceByRole/           Same pattern: Module.php owns the hooks, the rest are hook-free
    Restrictions.php              Option access + per-user evaluation (keeps standalone plugin's option key)
    AdminPage.php                 Role/price matrix screen (AJAX-loaded, under SC Extensions menu)
    Frontend.php                  3-layer frontend hiding (render_block filter, inline CSS, MutationObserver JS)
    CheckoutValidator.php         surecart/checkout/validate server-side gate
  MakeAnOffer/                   Same pattern again
    OfferPostType.php             sc_offer CPT + offer_* statuses (names from the sc-make-an-offer scaffold)
    OfferRepository.php           All sc_offer post/meta access lives here, nowhere else
    OfferManager.php              Every status transition; job handlers for capture/release/expire
    StripeService.php             Setup/verify/capture/release flows over Api\StripeClient
    Settings.php                  Single serialized option + BLT_SCE_STRIPE_SECRET_KEY / BLT_SCE_SURECART_API_TOKEN constant overrides
    ProductCatalog.php            Transient-cached SureCart product/list-price lookup for validation
    OrderRecorder.php             Back-fills accepted offers as manually-paid SureCart orders (job-only)
    EmailNotifier.php             All wp_mail() notifications, both directions
    AdminPage.php + OffersListTable.php   Offers screen, detail view, actions, settings, admin-bar badge
    Frontend.php                  [sc_make_an_offer] shortcode + Stripe.js modal form
    CounterToken.php              HMAC tokens for counter-offer email links
  Reports/                       Same pattern again
    Module.php                    Job-handler + admin hooks, nothing else
    ReportRunner.php              Action Scheduler job: pages orders, applies the date window, writes the CSV
    FulfillmentMatrix.php         The aggregation: customers x product-variants -> quantities (+ TOTALS row)
    CsvWriter.php                 UTF-8 BOM + formula-injection-safe CSV output
    ReportStorage.php             Protected uploads subdir, unguessable filenames, path validation
    ProductIndex.php              Option-cached product list for the picker (refreshed by a job, never on render)
    AdminPage.php                 Request form, reports list, nonce+capability-checked download/delete
src/Api/
  ShippoClient.php               All Shippo HTTP, logged, timed out, never called outside a job
  StripeClient.php               All Stripe HTTP, same conventions (no SDK; form-encoded; Stripe-Account aware)
  SureCartGateway.php             SureCart PHP models (Order, Fulfillment) — also synchronous, also job-only
  SureCartApiClient.php           SureCart platform REST (Bearer token) for checkout verbs the models don't document
src/Rest/
  ShippoWebhookController.php    Tracking webhook receiver + security
  OfferController.php            sc-offer/v1 customer endpoints (submit/confirm/counter-response)
src/Admin/                       Settings, Modules, Shipments list table, Review Queue, Site Health — all server-rendered, no build step
src/Db/                          Schema (dbDelta) + ShipmentRepository / ReportRepository
                                 (all $wpdb access lives here, nowhere else)
src/Support/                     Logger, Scheduler (Action Scheduler wrapper), Money (decimal-safe cents), UpdateChecker
assets/                          Per-module frontend/admin JS+CSS (vanilla, no build step)
```

**Async-rule note for Reports** (rule 1 below still governs, with no exceptions taken): report generation is entirely job-side. The admin screen renders from the local reports table and `ProductIndex`'s option-backed product cache, which an Action Scheduler job populates — so the product picker never makes a SureCart call during a page render. If you add a report that needs data the cache doesn't have, refresh the cache from a job; don't reach for SureCart in `render()`.

**Async-rule clarifications for Make an Offer** (rule 1 below still governs): the card-charging and PM-release Stripe calls run only inside Action Scheduler jobs, with an idempotency key so a re-run can't double-charge. Two narrow, deliberate exceptions run synchronously in the customer's REST request because the flow is impossible otherwise: SetupIntent creation (its client_secret must go back in the /submit response for Stripe.js) and a transient-cached (15 min/product) SureCart list-price lookup for server-side offer validation. Neither spends money.

## Non-negotiable rules (from the build spec — still apply to any future change)

1. **All external HTTP (Shippo, SureCart PHP models) happens inside an Action Scheduler job.** Never in a customer-facing request, never in an admin page render. `SureCartGateway`'s model calls are synchronous/blocking by SureCart's own design — that's fine inside a job, never fine anywhere else.
2. **Money is integer cents, no floats.** Shippo returns rate amounts as decimal-dollar strings (`"5.50"`) — always convert through `Support\Money::decimal_string_to_cents()`, never `(int) ($x * 100)`.
3. **Idempotency is DB-enforced**, not just application logic — the UNIQUE key on `surecart_order_id` in `Db\Schema` is the actual defense against a double-purchased label. Don't add a second write path that bypasses `ShipmentRepository::find_or_create_for_order()`.
4. **`auto_purchase` defaults off.** Any new purchase path must still route through `Guardrails` and land in the review queue when guardrails aren't satisfied or auto-purchase is off.
5. **The kill switch (`Guardrails::is_halted()`) is an absolute stop** — it must be checked first, before anything else, in any code path that could result in a Shippo purchase, and it is never bypassable (not even by an explicit manual admin action, unlike a guardrail hold).
6. Nonce + capability check on every admin action; sanitize input, escape output; every string user-facing goes through `__()`/`esc_html__()` with the `blt-surecart-extensions` text domain.
7. Uninstall never destroys shipment history unless the site owner has explicitly opted in (`SettingsPage::OPT_DELETE_ON_UNINSTALL`). The same opt-in — and only that opt-in — also removes generated report CSVs from `uploads/blt-sce-reports/`; anything holding customer PII must be cleaned up under it, so add new artifacts to `uninstall.php` when you create them.
8. **Any table added after 1.0.0 needs `Db\Schema::DB_VERSION` bumped.** This plugin updates in place from GitHub Releases, and WordPress does **not** re-run activation hooks on update — `Schema::maybe_upgrade()` on `plugins_loaded` is the only thing that creates a new table on an existing site. Adding a `CREATE TABLE` without bumping the version ships a module that's broken everywhere except fresh installs.

## Adding another module

Register it in `Plugin::init()` alongside the existing modules. It needs its own slug, its own admin submenu(s) under `blt-sce-modules`, and its own `unmet_requirements()`. Nothing in `ModuleRegistry` needs to change — that's the point of it. `Modules\Reports` is the most recent worked example and the smallest one to copy from: one hooks-only `Module.php`, hook-free services around it, and a job for anything that talks to SureCart.

## Local dev / CI

- `composer install` — vendors `yahnis-elsts/plugin-update-checker` and `woocommerce/action-scheduler` (gitignored; rebuilt fresh in CI from the committed `composer.lock`).
- `php -l` every file you touch — there's no PHPUnit test suite in this build (a real WordPress install + SureCart + Shippo test-mode account would be needed to test any of this end-to-end; see the manual test matrix in the original build spec / `tasks/*.md`).
- `.github/workflows/release.yml` builds a zip and publishes a GitHub Release whenever the `Version:` header in `blt-surecart-extensions.php` changes on `main` — bump that header (and the `readme.txt` Stable tag) together when you want a release to go out.
