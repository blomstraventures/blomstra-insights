# ARCHITECTURE.md – Blomstra Insights System Architecture

Document version: 1.0.0\
Status: CANONICAL\
Applies to: Entire Blomstra Insights system\
Last verified against commit: (to be filled)\
Source files: All `src/` files\
Effective date: 2026-09-09

***


## Document Control

| Field                   | Value                                                                                                                                                                                                                                                  |
| :---------------------- | :----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Document                | `ARCHITECTURE.md` v1.0.0                                                                                                                                                                                                                               |
| Verified against commit | (to be filled)                                                                                                                                                                                                                                         |
| Source files            | All `src/` files – complete repository architecture                                                                                                                                                                                                    |
| Method                  | Code-first reconstruction (Stage 2). Cross-referencing `DATA.md` (L1) and `SIVI.md` (L3) rather than re-deriving their detail. New ground covered here: L2 shared utilities (orchestration-level), L4 frontend, and the cross-cutting alert subsystem. |
| Status                  | Complete for current code (SIVI as the only live index). Items in §14 are open questions, not implementation gaps.                                                                                                                                     |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## Table of Contents

1. System Identity

2. The Four-Layer Model

3. Layer Invariants

4. Repository Map

5. File Responsibilities

6. Bootstrap Sequence

7. Cross-Layer Communication

8. Data Flow

9. Technology Stack

10. L2 Shared Utilities – Orchestration Role

11. Cross-Cutting: The Alert Subsystem

12. L4 Frontend Engine – Structural Summary

13. The Five Engineering Invariants – Consolidated

14. Open Questions

15. Corrections to Prior Documentation

***


## 1. System Identity

\[D] Blomstra Insights is a WordPress plugin that builds, stores, and serves country-level composite indices (SIVI live; SERI/GPRI future work) from public international data sources, plus an alerting subsystem and an interactive frontend widget engine for displaying them.

The system is not merely a frontend or a collection of individual indices – it is an integrated research-data-index infrastructure.

\[D] This document describes the architecture as it exists at the declared verification commit. It is the canonical source for architectural rules and layer boundaries.

***


## 2. The Four-Layer Model

text

    ┌─────────────────────────────────────────────────────────────────────────────┐
    │                                                                             │
    │  L4 – FRONTEND                                                              │
    │  ─────────────                                                             │
    │  Generic widget engine, config-driven                                      │
    │  Reads data-biw-* attributes only — no index-specific logic               │
    │  Renders dashboards, tables, maps, drawers, comparisons                   │
    │                                                                             │
    │  Files: index-frontend-engine.js, index-frontend-styles.css,               │
    │         index-frontend-utility.js                                          │
    │                                                                             │
    ├─────────────────────────────────────────────────────────────────────────────┤
    │                                                                             │
    │  L3 – INDEX / DOMAIN                                                        │
    │  ───────────────                                                            │
    │  Index-specific logic (SIVI, SERI, GPRI, etc.)                            │
    │  Builds composites, manages history, exposes REST, admin UI               │
    │  Calls the shared builder — never computes its own statistics             │
    │                                                                             │
    │  Files: src/indices/sivi/sivi-backend.php, sivi-shortcode.php             │
    │         (Future: src/indices/seri/, src/indices/gpri/)                    │
    │                                                                             │
    ├─────────────────────────────────────────────────────────────────────────────┤
    │                                                                             │
    │  L2 – SHARED UTILITIES                                                      │
    │  ───────────────────                                                        │
    │  Pure PHP math + cross-index services                                      │
    │  Percentiles, DQI, CAGR, Spearman, Cronbach's α, bootstrap CI             │
    │  Winsorization, timeseries sanitization                                    │
    │  Partial-rank projection, rank display helpers                             │
    │  Generic index builder orchestrator                                         │
    │  Data Quality Index (DQI)                                                  │
    │  Alert subsystem (cross-cutting)                                           │
    │                                                                             │
    │  Files: src/shared/blomstra-index-utilities.php                            │
    │         src/shared/blomstra-index-alerts.php                               │
    │                                                                             │
    ├─────────────────────────────────────────────────────────────────────────────┤
    │                                                                             │
    │  L1 – REFERENCE DATA                                                        │
    │  ───────────────                                                            │
    │  External data acquisition, caching, state machines                        │
    │  Staging→promotion with coverage thresholds                                │
    │  Cron handlers, lock management                                            │
    │  API credential management                                                 │
    │  Historical data caching                                                   │
    │  Shared snapshot & history storage                                         │
    │                                                                             │
    │  Files: src/shared/global-reference-data.php                               │
    │                                                                             │
    └─────────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
                        ┌─────────────────────┐
                        │  EXTERNAL APIs      │
                        │  ───────────        │
                        │  World Bank WDI/WGI │
                        │  IMF WEO            │
                        │  UN Comtrade        │
                        │  EIA                │
                        └─────────────────────┘

\[N] Layer invariants (confirmed by code inspection, not merely aspirational):

- L1 does not know about indices. `global-reference-data.php` has no reference to `sivi_*`, `seri_*`, or any index slug — it exposes raw, index-agnostic getters.

- L2 has no external HTTP calls and no WordPress admin-page rendering. `blomstra-index-utilities.php`'s generic builder takes pillar data as input via a config's fetcher references — it never calls `wp_remote_get()` itself.

- L3 never computes its own statistics. Every index's `{slug}_build_composite()` delegates percentile ranking, winsorization, DQI, sensitivity, and ranking to L2's generic builder — confirmed for SIVI in `SIVI.md` §13.

- L4 is entirely config-driven. The frontend engine reads `data-biw-slug`, `-endpoint`, `-score-key`, `-coverage-key`, `-pillars` (a JSON array), `-band-thresholds`, `-year-min/max`, etc. from the widget's root element — no index-specific field name is hardcoded except as a documented fallback default.

***


## 3. Layer Invariants

### 3.1 L1 – Reference Data Invariants

\[N] L1 MUST NOT:

- Contain index‑specific methodology (e.g., pillar aggregation)

- Render HTML

- Depend on L2 or L3

- Know about specific indices

\[D] L1 currently:

- Stores API credentials in `wp_options`

- Caches data in transients and persistent options

- Uses state machines for long-running fetches

- Promotes data from staging to production with validation

- Tracks cron health in `blomstra_cron_status`


### 3.2 L2 – Shared Utilities Invariants

\[N] L2 MUST NOT:

- Depend on WordPress (no `get_option`, `update_option`, transients, `wp_*`) – EXCEPTION: the generic builder (`blomstra_build_index_composite`) is L3-boundary and uses WordPress persistence

- Render HTML

- Know about specific indices (except as configuration passed to the generic builder)

\[D] L2 currently:

- Is pure PHP with no WordPress dependencies (except the generic builder)

- Operates on arrays and scalars

- Is portable and testable outside WordPress

- Contains all statistical and mathematical logic


### 3.3 L3 – Index Layer Invariants

\[N] L3 MUST NOT:

- Render the frontend directly; only provide data via REST

- Depend on other indices

- Bypass L2 for statistical operations

- Hardcode frontend field names

\[D] L3 currently:

- Defines pillar weights and defs

- Calls the generic builder for all statistical operations

- Transforms generic output to index-specific shape

- Manages scenarios (custom weight builds)

- Handles historical backfill

- Exposes REST endpoints

- Provides admin UI


### 3.4 L4 – Frontend Invariants

\[N] L4 MUST NOT:

- Call L1 or L2 directly – only consume L3 REST endpoints

- Hardcode field names – all data access via `scoreKey`, `coverageKey`, `pillars` from config

- Depend on index‑specific logic

\[D] L4 currently:

- Is a generic, config-driven widget engine

- Reads all configuration from `data-biw-*` attributes

- Fetches data via REST endpoints

- Manages client-side state per instance

- Supports multiple widgets on the same page

- Loads D3 and topojson on demand

***


## 4. Repository Map

| Path                                                      | Layer              | Responsibility                                                                                                                                                                                                                    |
| :-------------------------------------------------------- | :----------------- | :-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/shared/global-reference-data.php`                    | L1                 | External API acquisition, caching, historical backfill cache, shared snapshot/history table — see `DATA.md`                                                                                                                       |
| `src/shared/blomstra-index-utilities.php`                 | L2                 | Generic composite builder, percentile/winsorization math, DQI, bootstrap sensitivity, benchmark correlation, flat snapshot row builder, landlocked list                                                                           |
| `src/shared/blomstra-index-alerts.php`                    | L2 (cross-cutting) | Change detection between builds, alert delivery (email/webhook/Slack), alert log storage — see §11                                                                                                                                |
| `src/indices/sivi/sivi-backend.php`, `sivi-shortcode.php` | L3                 | SIVI's pillar definitions, refresh/build orchestration, REST route, admin UI, shortcode/frontend contract — see `SIVI.md`                                                                                                         |
| `src/indices/seri/seri-backend.php`, `seri-shortcode.php` | L3 (future)        | SERI — future work, not covered by this documentation pass                                                                                                                                                                        |
| `src/frontend/index-frontend-engine.js`                   | L4                 | The widget runtime — dashboard/table rendering, filtering, D3-based visualizations, historical lookups                                                                                                                            |
| `src/frontend/index-frontend-styles.css`                  | L4                 | Widget styling                                                                                                                                                                                                                    |
| `src/frontend/index-frontend-utility.js`                  | L4                 | Shared static lookup tables: numeric-ISO→ISO3 map, named country groups (G7, G20, BRICS, BRICS+, EU, ASEAN, GCC, Nordic, Baltic) — exposed globally for any widget instance to use in filtering                                   |
| `scripts/generate-docs.php`                               | Tooling            | Publishing pipeline: parses PHPDoc/JSDoc comments and Markdown into a browsable HTML doc site and an `api.json` manifest. Not a documentation-freshness mechanism — it republishes whatever is already written, correct or stale. |

***


## 5. File Responsibilities

### 5.1 `src/shared/global-reference-data.php` (L1)

| Responsibility                             | Key Functions                                                                                                       |
| :----------------------------------------- | :------------------------------------------------------------------------------------------------------------------ |
| API credential management                  | `blomstra_get_api_credential()`, `blomstra_save_api_credentials()`                                                  |
| Country list acquisition                   | `blomstra_get_global_country_list()`                                                                                |
| Comtrade reporter map                      | `blomstra_get_comtrade_reporter_map()`                                                                              |
| Maritime data                              | `blomstra_get_maritime_raw()`, `blomstra_get_maritime_value()`                                                      |
| HHI data acquisition (state machine)       | `blomstra_refresh_comtrade_hhi_data()`, `blomstra_cron_handle_hhi()`                                                |
| EIA data acquisition (state machine)       | `blomstra_process_eia_activity()`, `blomstra_cron_handle_eia()`                                                     |
| World Bank indicators                      | `blomstra_fetch_wb_indicator_batch()`, `blomstra_cron_handle_wb_indicators()`                                       |
| IMF WEO indicators                         | `blomstra_fetch_imf_generic()`, `blomstra_cron_handle_imf()`                                                        |
| Historical year‑specific fetchers          | `blomstra_fetch_eia_for_year()`, `blomstra_fetch_hhi_for_year()`, `blomstra_fetch_maritime_for_year()`              |
| Staging→promotion with coverage thresholds | Embedded in each refresh function                                                                                   |
| Cron lock transients                       | `set_transient()` / `get_transient()` in cron handlers                                                              |
| Snapshot history table                     | `blomstra_index_history_maybe_install()`, `blomstra_index_snapshot_save()`, `blomstra_index_snapshot_get_history()` |


### 5.2 `src/shared/blomstra-index-utilities.php` (L2)

| Responsibility                     | Key Functions                                                                                                               |
| :--------------------------------- | :-------------------------------------------------------------------------------------------------------------------------- |
| Safe data extraction               | `blomstra_safe_numeric()`, `blomstra_safe_string()`, `blomstra_safe_array_get()`                                            |
| Timeseries sanitization & CAGR     | `blomstra_sanitize_timeseries()`, `blomstra_timeseries_bounds()`, `blomstra_compute_cagr()`                                 |
| Standard deviation                 | `blomstra_compute_stddev()`                                                                                                 |
| Winsorization                      | `blomstra_winsorize()`                                                                                                      |
| Percentile ranks with tie handling | `blomstra_compute_percentile_ranks_safe()`                                                                                  |
| Provenance tracking                | `blomstra_track_source()`, `blomstra_pillar_source_summary()`                                                               |
| Data quality flags & scores        | `blomstra_data_quality_flag()`, `blomstra_pillar_quality_score()`                                                           |
| Statistical functions              | `blomstra_spearman_correlation()`, `blomstra_cronbach_alpha()`, `blomstra_bootstrap_ci()`, `blomstra_benchmark_correlate()` |
| Partial‑rank projection            | `blomstra_project_partial_rank_composite()`                                                                                 |
| Rank display helpers               | `blomstra_build_full_rank_display()`, `blomstra_build_partial_rank_display()`                                               |
| Data Quality Index (DQI)           | `blomstra_compute_dqi()`, `blomstra_compute_composite_dqi()`                                                                |
| Canonical flat snapshot row        | `blomstra_build_flat_snapshot_row()`                                                                                        |
| Generic index builder              | `blomstra_build_index_composite()`                                                                                          |
| Landlocked check                   | `blomstra_is_landlocked()`                                                                                                  |
| Staleness check                    | `blomstra_is_stale()`                                                                                                       |
| Pillar validation                  | `blomstra_validate_pillar_thresholds()`                                                                                     |
| Fallback merging                   | `blomstra_merge_with_fallback()`, `blomstra_merge_priority_layers()`                                                        |


### 5.3 `src/shared/blomstra-index-alerts.php` (L2, Cross-Cutting)

| Responsibility   | Key Functions                       |
| :--------------- | :---------------------------------- |
| Change detection | `blomstra_alert_detect_changes()`   |
| Storage          | `blomstra_alert_store_records()`    |
| Orchestration    | `blomstra_fire_index_alerts()`      |
| Delivery         | `blomstra_deliver_pending_alerts()` |
| Cleanup          | `blomstra_alert_cleanup()`          |
| Admin UI         | `blomstra_alerts_render_page()`     |


### 5.4 `src/indices/sivi/sivi-backend.php` (L3)

| Responsibility      | Key Components                                                                                |
| :------------------ | :-------------------------------------------------------------------------------------------- |
| Pillar definitions  | `sivi_get_pillar_weights()`, `sivi_get_pillar_defs()`, `sivi_get_composite_weights()`         |
| Pillar refresh      | `sivi_refresh_energy_pillar()`, `sivi_refresh_hhi_pillar()`, `sivi_refresh_maritime_pillar()` |
| Composite build     | `sivi_build_composite()` → calls generic builder                                              |
| Scenario storage    | `sivi_store_scenario()`, `sivi_list_scenarios()`, `sivi_delete_scenario()`                    |
| Historical backfill | `sivi_build_historical_snapshot()`                                                            |
| REST endpoint       | `/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index`                           |
| Admin UI            | `sivi_render_admin_page()`                                                                    |
| Init validation     | `sivi_initialize()`                                                                           |


### 5.5 `src/indices/sivi/sivi-shortcode.php` (L3)

| Responsibility | Implementation                           |
| :------------- | :--------------------------------------- |
| Shortcode      | `[blomstra_sivi_index]`                  |
| Pillar config  | JSON array passed via `data-biw-pillars` |
| Year range     | Dynamic from history table               |


### 5.6 `src/frontend/index-frontend-engine.js` (L4)

| Responsibility        | Implementation                                        |
| :-------------------- | :---------------------------------------------------- |
| Widget discovery      | `.biw[data-biw-slug]:not([data-biw-initialized])`     |
| Configuration reading | All `data-biw-*` attributes                           |
| Data loading          | Fetch endpoints via `fetch()`                         |
| State management      | `BlomstraIndexWidget` instance state                  |
| Views                 | Dashboard (map, charts), Table (sortable, filterable) |
| Features              | Watchlist, comparison, CSV, sharing, dark mode        |


### 5.7 `src/frontend/index-frontend-styles.css` (L4)

| Responsibility  | Implementation               |
| :-------------- | :--------------------------- |
| Theming         | CSS custom properties        |
| Dark/light mode | `.biw-light` class           |
| Namespacing     | All classes prefixed `.biw-` |
| Responsive      | Media breakpoints            |


### 5.8 `src/frontend/index-frontend-utility.js` (L4)

| Responsibility | Implementation                                  |
| :------------- | :---------------------------------------------- |
| ISO3 lookup    | `window.BIW_ISO3_LOOKUP`, `window.BIW_GET_ISO3` |
| Country groups | `window.BIW_COUNTRY_GROUPS`                     |

***


## 6. Bootstrap Sequence

### 6.1 WordPress `init` Hook

\[D] The following events occur on `init`:

| Priority | Component         | Action                                                  |
| :------- | :---------------- | :------------------------------------------------------ |
| 5        | L1 Reference Data | `global-reference-data.php` loads, defines constants    |
| 5        | L2 Utilities      | `blomstra-index-utilities.php` loads, defines constants |
| 10       | L3 SIVI           | `sivi_initialize()` validates pillar definitions        |
| 10       | L3 SIVI           | Auto-refresh cron scheduled (if not already)            |
| 10       | L1 Reference Data | All cron schedules registered                           |
| 10       | L1 Alerts         | Cleanup cron scheduled (if not already)                 |


### 6.2 WordPress `admin_init` Hook

\[D] The following events occur on `admin_init`:

| Component         | Action                                       |
| :---------------- | :------------------------------------------- |
| L1 Reference Data | Admin actions processed (fetch, flush, etc.) |
| L1 Alerts         | Alert table created/updated                  |
| L1 Reference Data | History table created/updated                |
| L3 SIVI           | Legacy CII admin redirect                    |


### 6.3 WordPress `rest_api_init` Hook

\[D] The following REST endpoints are registered:

| Endpoint                                                    | Component         |
| :---------------------------------------------------------- | :---------------- |
| `/blomstra/v1/sovereign-infrastructure-vulnerability-index` | L3 SIVI           |
| `/blomstra/v1/index-history/{slug}`                         | L1 Reference Data |
| `/blomstra/v1/country-names`                                | L1 Reference Data |


### 6.4 WordPress `admin_menu` Hook

\[D] The following admin pages are registered:

| Page                    | Component         |
| :---------------------- | :---------------- |
| Blomstra Insights Tools | L1 Reference Data |
| SIVI Index              | L3 SIVI           |
| Alerts                  | L1 Alerts         |

***


## 7. Cross-Layer Communication

### 7.1 L1 → L2 Communication

\[D] L1 passes data as arrays to L2:

php

    // L1 provides raw data
    $raw_energy = blomstra_get_eia_raw_data();

    // L2 computes percentiles
    $percentiles = blomstra_compute_percentile_ranks_safe($raw_energy['consumption']);

\[N] L2 receives arrays from L1, never L1 functions.


### 7.2 L2 → L3 Communication

\[D] L3 calls L2 functions directly:

php

    // L3 calls L2
    $generic_output = blomstra_build_index_composite($config, $context);

    // L3 calls L2 functions for quality
    $dqi = blomstra_compute_dqi($data_year, $current_year, $max_lag);

\[N] L2 does not know about L3 (except via configuration passed to the generic builder).


### 7.3 L3 → L4 Communication

\[D] L3 exposes data via REST:

text

    L3 (sivi_composite_index) → REST endpoint → L4 (fetch)

\[N] L4 receives JSON from L3, never L3 functions.


### 7.4 L4 → L3 Communication

\[D] L4 makes HTTP requests to L3 REST endpoints:

javascript

    fetch('/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index')
        .then(r => r.json())
        .then(data => { /* ... */ });

\[N] L4 calls L3 via REST only.

***


## 8. Data Flow

### 8.1 End-to-End Flow

text

    ┌─────────────────────────────────────────────────────────────────────────────┐
    │                                                                             │
    │  1. EXTERNAL API CALL                                                       │
    │     ───────────────                                                         │
    │     wp_remote_get() → EIA/Comtrade/WB/IMF                                  │
    │                                                                             │
    │  2. L1 DATA ACQUISITION                                                     │
    │     ──────────────────                                                      │
    │     global-reference-data.php → fetches, caches, promotes                  │
    │     blomstra_comtrade_hhi_data, blomstra_eia_raw_data, etc.                │
    │                                                                             │
    │  3. L2 STATISTICAL COMPUTATION                                              │
    │     ──────────────────────────                                              │
    │     blomstra-index-utilities.php → percentiles, DQI, CAGR, etc.            │
    │                                                                             │
    │  4. L3 COMPOSITE BUILD                                                      │
    │     ──────────────────                                                      │
    │     sivi_build_composite() → calls generic builder, reshapes output        │
    │     sivi_composite_index → stored in wp_options                            │
    │                                                                             │
    │  5. L3 SNAPSHOT SAVE                                                        │
    │     ──────────────────                                                      │
    │     blomstra_index_snapshot_save() → wp_blomstra_index_history table       │
    │                                                                             │
    │  6. L3 ALERT TRIGGER                                                        │
    │     ──────────────────                                                      │
    │     blomstra_fire_index_alerts() → wp_blomstra_alerts table               │
    │     → email/webhook/Slack                                                   │
    │                                                                             │
    │  7. L4 FRONTEND RENDER                                                      │
    │     ──────────────────                                                      │
    │     index-frontend-engine.js → fetch REST → render                         │
    │                                                                             │
    └─────────────────────────────────────────────────────────────────────────────┘


### 8.2 Build Flow (Detailed)

\[D] The composite build flow in L3:

text

    sivi_build_composite($context)
        │
        ▼
    sivi_get_generic_config()
        │
        ▼
    blomstra_build_index_composite($config, $context)
        │
        ├─ Acquire country list (L1)
        ├─ Fetch pillar raw data (L1)
        ├─ Compute percentiles (L2)
        ├─ Apply winsorization (L2)
        ├─ Apply directional transform (L2)
        ├─ Aggregate composite (L2)
        ├─ Assign ranks (L2)
        ├─ Compute DQI (L2)
        ├─ Save snapshot (L1)
        └─ Persist to internal key (L2/L3 boundary)
        │
        ▼
    SIVI reshapes output
        │
        ▼
    (If not scenario) update_option(SIVI_OPTION_KEY, $output)
        │
        ▼
    blomstra_fire_index_alerts() (L1)

***


## 9. Technology Stack

### 9.1 Backend

| Technology | Version | Purpose                                               |
| :--------- | :------ | :---------------------------------------------------- |
| PHP        | 7.4+    | Primary backend language                              |
| WordPress  | 6.0+    | CMS, REST API, admin UI                               |
| MySQL      | 5.7+    | Data persistence (options, transients, custom tables) |


### 9.2 Frontend

| Technology | Version | Purpose                                  |
| :--------- | :------ | :--------------------------------------- |
| JavaScript | ES6     | Widget engine, maps, charts              |
| D3         | 7.8.5   | Map rendering, charts (loaded on demand) |
| Topojson   | 3.x     | Map data parsing                         |
| CSS3       | —       | Styling with CSS custom properties       |


### 9.3 External APIs

| API            | Purpose                              |
| :------------- | :----------------------------------- |
| World Bank WDI | Macro, external, maritime indicators |
| World Bank WGI | Governance indicators                |
| IMF WEO        | Fiscal indicators, forecasts         |
| UN Comtrade    | Trade data (HHI computation)         |
| EIA            | Energy production/consumption data   |


### 9.4 Persistence

| Storage                       | Purpose                                          |
| :---------------------------- | :----------------------------------------------- |
| `wp_options`                  | Composite data, pillar data, credentials, status |
| `wp_transients`               | Cached API data, locks                           |
| `wp_blomstra_index_history`   | Historical snapshots                             |
| `wp_blomstra_alerts`          | Alert records                                    |
| `wp_blomstra_historical_data` | Historical data cache                            |
| `wp_blomstra_cache_jobs`      | Cache job status                                 |

***


## 10. L2 Shared Utilities – Orchestration Role

\[D] `blomstra_build_index_composite($config)` (fully detailed operationally in `SIVI.md` §4–9, since SIVI is the only current caller) is the single generic pipeline every index runs through:

text

    acquire → winsorize → percentile-rank → directional transform → coverage/exclusion decision → composite → ranking (full + partial-projection) → DQI → sensitivity (optional) → benchmark correlation (optional) → build-failure safety check → alert firing → persist → snapshot

\[N] This function is the sole place composite-index math is implemented. An index adding its own percentile or ranking logic outside this function would violate the L2/L3 boundary and is a design defect, not a valid extension point.

***


## 11. Cross-Cutting: The Alert Subsystem (`blomstra-index-alerts.php`)

\[D] Sits at L2 but is cross-cutting rather than purely mathematical — it's invoked by L3 (`blomstra_fire_index_alerts()`, called from the generic builder after a successful build with pre-existing prior data) and delivers externally (email, webhook, Slack), which L2's other math functions never do.

\[D] Pipeline:

text

    blomstra_alert_detect_changes() → diff new vs. old per-country data
        │
        ▼
    blomstra_alert_store_records() → persists the diff
        │
        ▼
    blomstra_build_alert_payload() → shapes a notification
        │
        ▼
    delivered via blomstra_alert_send_email() / _webhook() / _slack()

\[D] Gated by `blomstra_get_alert_config()`. `blomstra_deliver_pending_alerts()` and `blomstra_alert_cleanup_cron()` handle deferred delivery and log retention respectively. Full delivery-channel configuration and failure semantics belong in `OPERATIONS.md`, not here — this document only establishes where the subsystem sits structurally.

***


## 12. L4 Frontend Engine – Structural Summary

\[D] `index-frontend-engine.js` (3,208 lines) is a single self-booting script:

text

    boot() scans .biw[data-biw-slug]:not([data-biw-initialized])
        │
        ▼
    instantiates BlomstraIndexWidget(root) per match
        │
        ▼
    marks each initialized to prevent double-boot

\[D] D3 and TopoJSON are lazy-loaded (`ensureD3()`, `ensureTopojson()`) only if the widget actually needs map rendering.

\[D] Per-instance state is read entirely from the root element's `data-biw-*` attributes at construction time — `slug`, `endpoint`, `namesEndpoint`, `historyEndpoint`, `scoreKey`, `coverageKey`, `view`, `pillars` (JSON), `bandThresholds`/`bandLabels`, `minYear`/`maxYear`, plus display strings (`title`, `subtitle`, `eyebrow`, `methodology`).

\[D] Render surfaces confirmed in code:

- Dashboard shell and table shell (`renderDashboardShell`/`renderTableShell`)

- Summary donut (`renderDonut`)

- Extremes panel (`renderExtremes`)

- Histogram (`renderHistogram`)

- Scatter plot (`renderScatter`)

- All driven by the same fetched dataset (`loadData()` → `fetchJSON()`), with historical year lookups layered on top for the trend/comparison views.

\[D] `index-frontend-utility.js` (25 lines) is intentionally tiny and static: a numeric-ISO-code → ISO3 lookup table and a fixed set of named country groupings, both exposed globally so any widget on the page can filter by group without each widget re-declaring the data.

***


## 13. The Five Engineering Invariants – Consolidated

Each is independently confirmed by direct code inspection (see the cited section for the concrete evidence), collected here as the canonical list since they cut across L1–L4:

| #  | Invariant                                                                                                                                          | Confirmed At                                                     |
| :- | :------------------------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------- |
| 1  | Internal bookkeeping key isolation – a builder's lock/staging key must never equal the key its output is persisted under                           | `SIVI.md` §13 (`sivi_composite_internal` vs. `SIVI_OPTION_KEY`)  |
| 2  | Snapshot row integrity – every live build and historical backfill uses the one shared snapshot-row function                                        | `SIVI.md` §10 (`blomstra_build_flat_snapshot_row()`, v3.3.0 fix) |
| 3  | Universe-aware promotion gate – a pillar/source's promotion threshold is judged against its own fetchable universe, never the global country count | `DATA.md` §4.3, §5.2 (HHI and EIA, REF-BUG-2)                    |
| 4  | Partial-progress promotion on quota exhaustion – staged data is promoted before returning, never discarded wholesale                               | `DATA.md` §4.3 (REF-BUG-1)                                       |
| 5  | Config-driven frontend – no hardcoded field names in the engine                                                                                    | `FRONTEND.md` §3 (`data-biw-*` attribute contract)               |

\[N] These five are MUST-level rules for any future index or refactor, not implementation notes. A future L1 fetcher, L2 builder variant, or L4 widget mode that violates one of these should be treated as a defect to fix before merge, not a stylistic choice.

***


## 14. Open Questions

\[D] These are architectural decisions that require explicit resolution:

| #  | Question                                                                                              | Notes                                     |
| :- | :---------------------------------------------------------------------------------------------------- | :---------------------------------------- |
| 1  | SERI migration – When will SERI be migrated to this architecture?                                     | Currently planned, not started            |
| 2  | GPRI planning – When will GPRI be designed?                                                           | Conceptual stage                          |
| 3  | OpenAPI spec – Should we generate an OpenAPI specification from the code?                             | Would help API consumers                  |
| 4  | CI/CD – When will GitHub Actions be set up for testing?                                               | Currently not configured                  |
| 5  | Containerization – Should the system be containerized for deployment?                                 | Not currently required                    |
| 6  | Testing infrastructure – What testing framework and coverage should be established?                   | Not defined                               |
| 7  | Monitoring integration – Should we integrate with external monitoring (e.g., New Relic, Sentry)?      | Currently only admin UI and `error_log()` |
| 8  | Alert escalation – Should critical failures trigger external alerts even if alert system is disabled? | Currently disabled by default             |

***


## 15. Corrections to Prior Documentation

\[D] No contradictions found between this document and `BLOMSTRA-SPECIFICATION.md`'s architectural sections — the four-layer model, layer invariants, and five engineering invariants as stated there match what code inspection confirms here. This document differs mainly in structure: it defers index-specific and source-specific detail to `SIVI.md`/`DATA.md` rather than inlining it, and adds:

| Addition                                   | Source                                      |
| :----------------------------------------- | :------------------------------------------ |
| L4 structural summary (§12)                | `index-frontend-engine.js` code inspection  |
| Alert subsystem structural placement (§11) | `blomstra-index-alerts.php` code inspection |
| Detailed file responsibilities (§5)        | Complete source audit                       |
| Cross-layer communication patterns (§7)    | Code call graph analysis                    |
| Repository map (§4)                        | Directory structure audit                   |

***


## End of Architecture Specification

Status: CANONICAL
