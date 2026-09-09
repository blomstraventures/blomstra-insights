# **BLOMSTRA INSIGHTS – UNIFIED SYSTEM SPECIFICATION**

Version: 1.0.0\
Status: CANONICAL (derived from current codebase)\
Last verified against repository: (to be filled)\
Purpose: Single source of truth for architecture, data, indices, frontend, operations, and development rules.

***
**Audit Status:** ✅ Verified against current codebase. See `AUDIT.md` for detailed verification.
`Branch: `main`
Version: `Phase 6b`

## **Table of Contents**

1. System Identity & Scope

2. Documentation Authority

3. Normative Language

4. Architectural Model

5. Layer Responsibilities

6. Repository Structure

7. Architectural Invariants

8. Reference Data Layer (L1)

9. Shared Utilities Layer (L2)

10. Index Layer (L3) – SIVI

11. Frontend Layer (L4)

12. Alert System

13. Data Provenance & Quality

14. Operations

15. Engineering & Development Rules

16. Versioning & Change Management

17. Open Questions

18. Documentation Governance

***


## **1. System Identity & Scope**

Blomstra Insights is a research and data platform that:

- Acquires reference data from public APIs (World Bank, IMF, UN Comtrade, EIA)

- Computes composite vulnerability/resilience indices (currently SIVI)

- Exposes data via REST API and WordPress shortcodes

- Provides a reusable, config‑driven frontend widget for visualization and interaction

The platform is not merely a frontend or a collection of individual indices – it is an integrated research‑data‑index infrastructure.

***


## **2. Documentation Authority**

The following hierarchy applies:

|                       |                                   |                                                               |
| --------------------- | --------------------------------- | ------------------------------------------------------------- |
| Level                 | Authority                         | Use                                                           |
| 1. Code               | Current executable implementation | Primary source for describing existing behaviour              |
| 2. Tests              | Verified test suites              | Evidence of intended/supported behaviour                      |
| 3. This Specification | This document                     | Canonical rules, contracts, and invariants                    |
| 4. Public Methodology | External‑facing documents         | Research methodology for users and researchers                |
| 5. Historical Docs    | Archived old corpus               | Provenance only – never authoritative for current development |

Rule: Historical documents may never override current code, tests, or this specification.

***


## **3. Normative Language**

|                      |                                                                                        |
| -------------------- | -------------------------------------------------------------------------------------- |
| Term                 | Meaning                                                                                |
| MUST / SHALL         | Mandatory requirement                                                                  |
| MUST NOT / SHALL NOT | Prohibited behaviour                                                                   |
| SHOULD               | Strong recommendation; deviation requires documented justification                     |
| MAY                  | Optional capability                                                                    |
| CURRENT              | Describes behaviour present in the repository – not automatically a future requirement |
| PROPOSED             | Design or methodological decision not yet established                                  |
| UNRESOLVED           | Issue requiring explicit technical/scientific/product decision                         |

***


## **4. Architectural Model**

### **4.1 The Four‑Layer Stack**

    text
    ┌─────────────────────────────────────────────┐
    │  L4 – FRONTEND                              │
    │  Generic widget engine, config-driven       │
    │  Reads data-biw-* attributes, fetches REST  │
    │  Renders dashboards, tables, maps, drawers  │
    ├─────────────────────────────────────────────┤
    │  L3 – INDEX / DOMAIN                        │
    │  Index-specific logic (SIVI, SERI, etc.)   │
    │  Builds composites, manages history,        │
    │  exposes REST endpoints, admin UI           │
    ├─────────────────────────────────────────────┤
    │  L2 – SHARED UTILITIES                      │
    │  Pure PHP math: percentiles, DQI, CAGR,     │
    │  Spearman, Cronbach's α, bootstrap CI,      │
    │  provenance tracking, quality scoring       │
    ├─────────────────────────────────────────────┤
    │  L1 – REFERENCE DATA                        │
    │  External data acquisition, caching,        │
    │  state machines, staging→promotion,         │
    │  cron handlers, lock management             │
    └─────────────────────────────────────────────┘
            │
            ▼
       External APIs (WB, IMF, Comtrade, EIA)

Invariant: No layer may depend on a layer above it. L4 → L3 → L2 → L1 → External APIs.


### **4.2 Dependency Rules**

|       |                       |                                        |
| ----- | --------------------- | -------------------------------------- |
| Layer | Depends On            | Must Not Depend On                     |
| L4    | L3 REST API           | L1, L2 directly; hardcoded field names |
| L3    | L2 functions, L1 data | L4; other indices                      |
| L2    | L1 data (as arrays)   | WordPress (except where noted); L3; L4 |
| L1    | External APIs         | L2, L3, L4; index-specific methodology |

***


## **5. Layer Responsibilities**

### **5.1 L1 – Reference Data**

File: `src/shared/global-reference-data.php`

|                                            |                                                                                                               |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| Responsibility                             | Key Functions                                                                                                 |
| API credential management                  | `blomstra_get_api_credential`, `blomstra_get_all_api_credentials`, `blomstra_save_api_credentials`            |
| Country list acquisition                   | `blomstra_get_global_country_list`                                                                            |
| Comtrade reporter map                      | `blomstra_get_comtrade_reporter_map`                                                                          |
| Maritime data                              | `blomstra_get_maritime_raw`, `blomstra_get_maritime_value`                                                    |
| HHI data acquisition (state machine)       | `blomstra_refresh_comtrade_hhi_data`, `blomstra_cron_handle_hhi`                                              |
| EIA data acquisition (state machine)       | `blomstra_process_eia_activity`, `blomstra_cron_handle_eia`                                                   |
| World Bank indicators                      | `blomstra_fetch_wb_indicator_batch`, `blomstra_refresh_wb_indicators`                                         |
| IMF WEO indicators                         | `blomstra_fetch_imf_generic`, `blomstra_refresh_imf_indicators`                                               |
| Historical year‑specific fetchers          | `blomstra_fetch_eia_for_year`, `blomstra_fetch_hhi_for_year`, `blomstra_fetch_maritime_for_year`              |
| Staging→promotion with coverage thresholds | Embedded in each refresh function                                                                             |
| Cron lock transients                       | `set_transient` / `get_transient` in cron handlers                                                            |
| Snapshot history table                     | `blomstra_index_history_maybe_install`, `blomstra_index_snapshot_save`, `blomstra_index_snapshot_get_history` |

L1 MUST NOT:

- Contain index‑specific methodology (e.g., pillar aggregation)

- Render HTML

- Depend on L2 or L3

***


### **5.2 L2 – Shared Utilities**

File: `src/shared/blomstra-index-utilities.php`

|                                    |                                                                                                                                 |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Responsibility                     | Key Functions                                                                                                                   |
| Safe data extraction               | `blomstra_safe_numeric`, `blomstra_safe_string`, `blomstra_safe_array_get`                                                      |
| Timeseries sanitization & CAGR     | `blomstra_sanitize_timeseries`, `blomstra_timeseries_bounds`, `blomstra_compute_cagr`, `blomstra_compute_cagr_from_values`      |
| Standard deviation                 | `blomstra_compute_stddev`                                                                                                       |
| Winsorization                      | `blomstra_winsorize`                                                                                                            |
| Percentile ranks with tie handling | `blomstra_compute_percentile_ranks_safe`                                                                                        |
| Provenance tracking                | `blomstra_track_source`, `blomstra_pillar_source_summary`                                                                       |
| Data quality flags & scores        | `blomstra_data_quality_flag`, `blomstra_pillar_quality_score`                                                                   |
| Statistical functions              | `blomstra_spearman_correlation`, `blomstra_cronbach_alpha`, `blomstra_bootstrap_ci`, `blomstra_benchmark_correlate`             |
| Partial‑rank projection            | `blomstra_project_partial_rank_composite`                                                                                       |
| Rank display helpers               | `blomstra_build_full_rank_display`, `blomstra_build_partial_rank_display`                                                       |
| Data Quality Index (DQI)           | `blomstra_compute_dqi`, `blomstra_compute_composite_dqi`                                                                        |
| Canonical flat snapshot row        | `blomstra_build_flat_snapshot_row` – single function every index must use for snapshots                                         |
| Generic index builder              | `blomstra_build_index_composite` – orchestrates percentile, aggregation, ranking, scenario safety, auto‑rollback, snapshot save |
| Landlocked check                   | `blomstra_is_landlocked`                                                                                                        |
| Staleness check                    | `blomstra_is_stale`                                                                                                             |
| Pillar validation                  | `blomstra_validate_pillar_thresholds`                                                                                           |
| Fallback merging                   | `blomstra_merge_with_fallback`, `blomstra_merge_priority_layers`                                                                |

L2 MUST NOT:

- Depend on WordPress (no `get_option`, `update_option`, transients) – exceptions: the generic builder is L3-boundary and uses WordPress options for persistence.

- Render HTML

- Know about specific indices (except as configuration passed to the generic builder)

***


### **5.3 L3 – Index / Domain**

File: `src/indices/sivi/sivi-backend.php` (current); future indices will follow the same pattern.

|                                                            |                                                                                         |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Responsibility                                             | Key Functions / Components                                                              |
| Define pillar weights and defs                             | `sivi_get_pillar_weights`, `sivi_get_pillar_defs`, `sivi_get_composite_weights`         |
| Refresh pillars from L1                                    | `sivi_refresh_energy_pillar`, `sivi_refresh_hhi_pillar`, `sivi_refresh_maritime_pillar` |
| Provide config for generic builder                         | `sivi_get_generic_config`                                                               |
| Build composite (calls generic builder, transforms output) | `sivi_build_composite`                                                                  |
| Scenario storage                                           | `sivi_store_scenario`, `sivi_list_scenarios`, `sivi_delete_scenario`                    |
| Historical backfill                                        | `sivi_build_historical_snapshot` – uses `blomstra_build_flat_snapshot_row`              |
| REST endpoint registration                                 | `/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index`                     |
| Shortcode registration                                     | `[blomstra_sivi_index]`                                                                 |
| Admin UI                                                   | `sivi_render_admin_page`                                                                |
| Init validation                                            | `sivi_initialize` (calls `blomstra_validate_pillar_thresholds`)                         |
| Health checks                                              | `sivi_check_upstream_health`, `sivi_get_backfill_status`, `sivi_update_backfill_status` |

L3 MUST NOT:

- Render the frontend directly; only provide data via REST

- Depend on other indices

- Bypass L2 for statistical operations

- Hardcode frontend field names

***


### **5.4 L4 – Frontend**

Files: `src/frontend/index-frontend-engine.js`, `index-frontend-styles.css`, `index-frontend-utility.js`

|                       |                                                                                                |
| --------------------- | ---------------------------------------------------------------------------------------------- |
| Responsibility        | Implementation                                                                                 |
| Widget discovery      | `.biw[data-biw-slug]:not([data-biw-initialized])`                                              |
| Configuration reading | All `data-biw-*` attributes (see §11.2)                                                        |
| Data loading          | Fetch index endpoint, country names, history endpoint                                          |
| State management      | `state` object: records, filtered, sort, watchlist, compare, selected country, year, dark mode |
| Views                 | Dashboard (map, charts, cards), Table (sortable, filterable)                                   |
| Country drawer        | Score, rank, delta, DQI, pillar bars, radar, history sparkline, sensitivity, provenance        |
| Country comparison    | Radar + table (up to 4 countries)                                                              |
| Group comparison      | Block modal with radar, summary cards, detailed table, CSV export                              |
| Watchlist             | localStorage, keyed by slug                                                                    |
| CSV export            | Exports current filtered/sorted data                                                           |
| Sharing               | X (Twitter), LinkedIn, copy link                                                               |
| Dark mode             | Toggled, stored in localStorage                                                                |
| Map                   | D3 + topojson, year slider, layer selection, zoom controls                                     |
| Dependencies          | D3, topojson (loaded on demand)                                                                |

L4 MUST NOT:

- Call L1 or L2 directly – only consume L3 REST endpoints

- Hardcode field names – all data access via `scoreKey`, `coverageKey`, `pillars` from config

- Depend on index‑specific logic

***


## **6. Repository Structure**

Current verified structure:

    text
    src/
    ├── frontend/
    │   ├── index-frontend-engine.js      # L4 – main widget engine (4.1.8)
    │   ├── index-frontend-styles.css     # L4 – styles (4.1.8)
    │   └── index-frontend-utility.js     # L4 – ISO3 lookup, country groups
    ├── indices/
    │   └── sivi/
    │       ├── sivi-backend.php          # L3 – SIVI domain logic (3.3.0)
    │       └── sivi-shortcode.php        # L3 – shortcode registration
    └── shared/
        ├── blomstra-index-alerts.php     # Alert system (2.1.0)
        ├── blomstra-index-utilities.php  # L2 – pure utilities (1.6.0)
        └── global-reference-data.php     # L1 – data acquisition (2.9.1)

Future indices (SERI, GPRI) will be placed under `src/indices/{slug}/` following the same pattern as SIVI.

***


## **7. Architectural Invariants (Normative)**

These rules are mandatory for all current and future development.

|    |                                                                                                                                                                                                                                                                                     |                                                                                      |
| -- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| #  | Invariant                                                                                                                                                                                                                                                                           | Failure Mode                                                                         |
| 1  | State machines for long fetches – All long‑running data acquisition must use a resumable pointer (HHI, EIA, WB). The pointer must include pending work, attempts, and metadata.                                                                                                     | Timeouts lose progress; duplicate runs corrupt data.                                 |
| 2  | Staging→atomic promotion – Production data must never be overwritten directly. Data must be written to staging, validated against coverage threshold (≥80% of expected), then promoted atomically. On failure, old production is preserved.                                         | Partial/empty data corrupts live index; no rollback.                                 |
| 3  | Snapshot row shape – Every live build and historical backfill MUST use `blomstra_build_flat_snapshot_row()`. Hand‑built rows are forbidden. The shape is flat: `composite_score`, `rank`, `coverage_type`, pillar scores as bare keys, `dqi_*`, `composite_dqi`, `vintage_summary`. | Backfilled years render blank in frontend history chart (as happened before v1.6.0). |
| 4  | Internal key isolation – The generic builder uses `{slug}_composite_internal`; the index backend writes `{slug}_composite_index`. These keys must never be the same.                                                                                                                | Alerts stop firing; lock flags lost.                                                 |
| 5  | Scenario safety – Any build with custom weights (`$custom_weights !== null` or `$custom_composite_weights !== null`) MUST NOT write to production. Scenarios are stored under `{slug}_composite_index_scenario_{id}`.                                                               | Sensitivity tests overwrite live composite; data loss.                               |
| 6  | Frontend config‑driven – All data access in frontend MUST use `scoreKey`, `coverageKey`, `pillars` from `data-biw-*`. Literal field names in JavaScript are bugs. Utility globals (`BIW_*`) must load before the main engine.                                                       | Empty CSV columns, broken sort, silent failures for new indices.                     |
| 7  | Auto‑rollback – A cron‑triggered build that produces fewer than 80% of the previous build's country count (and fewer than 50 countries) must preserve the old composite, log the failure, and set a transient alert.                                                                | Single API outage wipes live index.                                                  |
| 8  | Quota partial promotion – If data acquisition hits quota/rate limit, it MUST promote whatever has been successfully staged before stopping. Discarding staged data is prohibited.                                                                                                   | Successful data lost; full restart wasted (REF‑BUG‑1).                               |
| 9  | Universe‑aware promotion gate – A pillar's promotion threshold must be judged against that pillar's own fetchable universe (e.g., EIA chunk success ratio, HHI fetchable countries), not the full global list.                                                                      | Narrow‑coverage pillars (Nuclear) never promote (REF‑BUG‑2).                         |
| 10 | Provenance – Every indicator value MUST have source, scope, and year tracked via `blomstra_track_source`.                                                                                                                                                                           | Data quality and DQI cannot be computed; reproducibility lost.                       |

***


## **8. Reference Data Layer (L1)**

### **8.1 State Machine Pattern**

All long‑running fetchers follow a resumable state machine.

Pointer Structure (general):

    php
    [
        'scope'      => string,       // what is being fetched
        'pending'    => array(),      // work remaining
        'completed'  => array(),      // work finished (optional)
        'attempts'   => array(),      // retry counters per item
        'started_at' => 'ISO8601',
        'metadata'   => array(),      // specialization‑specific
    ]

Specializations:

|               |                                |                                            |
| ------------- | ------------------------------ | ------------------------------------------ |
| Fetcher       | Pointer Key                    | Metadata                                   |
| HHI           | `blomstra_hhi_refresh_pointer` | `target_year`, `pending_iso3s`, `attempts` |
| EIA           | `blomstra_eia_refresh_pointer` | `fuel_index`, `activity`, `failed_fuels`   |
| WB Indicators | `blomstra_wb_refresh_pointer`  | `next_index`, `started_at`                 |


### **8.2 Staging → Promotion**

Flow:

    text
    Fetcher writes → STAGING (option or transient)
                        │
                        ▼
             [VALIDATION: coverage ≥ 80%?]
                   /              \
                YES               NO
                 │                 │
                 ▼                 ▼
       copy to PRODUCTION    discard STAGING
       delete STAGING          keep old PRODUCTION
       delete pointer          log validation failure
       update cron status      set retryable flag

Thresholds:

|                |                                                               |                     |
| -------------- | ------------------------------------------------------------- | ------------------- |
| Fetcher        | Expected Universe                                             | Threshold           |
| HHI            | `fetchable_countries` (countries with Comtrade reporter code) | ≥ 80% of fetchable  |
| EIA (per fuel) | Chunk‑level API success ratio for that fuel's own calls       | ≥ 0.8 success ratio |
| IMF / WB       | Non‑empty data                                                | Data exists         |


### **8.3 Cron Handlers & Locks**

|               |                                        |        |
| ------------- | -------------------------------------- | ------ |
| Handler       | Lock Key                               | TTL    |
| HHI           | `blomstra_hhi_refresh_in_progress`     | 30 min |
| EIA           | `blomstra_eia_refresh_in_progress`     | 30 min |
| WB Indicators | `blomstra_wb_refresh_in_progress`      | 30 min |
| IMF           | `blomstra_imf_weekly_in_progress`      | 10 min |
| Maritime      | `blomstra_maritime_weekly_in_progress` | 10 min |
| Countries     | `blomstra_countries_async_in_progress` | 10 min |
| Reporters     | `blomstra_reporters_async_in_progress` | 10 min |

Duplicate prevention: If lock exists and is within TTL, the cron handler logs "Already running – skipping duplicate" and exits.

***


## **9. Shared Utilities Layer (L2)**

### **9.1 Percentile Computation**

    php
    blomstra_compute_percentile_ranks_safe($values, $winsor_pct = 0.0)

Algorithm:

1. Filter non‑numeric values.

2. Apply winsorization if `$winsor_pct > 0`.

3. Sort ascending.

4. Assign ranks with average rank for ties.

5. Convert to percentile: `((rank - 0.5) / N) * 100`.

Return: `["ISO3" => float]` (0‑100). Higher value = higher percentile.


### **9.2 Winsorization**

    php
    blomstra_winsorize($values, $winsor_pct = 0.0)

Caps values at symmetric percentiles. Applied only when `$winsor_pct > 0` and `count($values) >= 10`.


### **9.3 Data Quality Index (DQI)**

    php
    blomstra_compute_dqi($data_year, $current_year, $max_lag)

Formula: `max(0, (1 - lag / max_lag) * 100)` where `lag = current_year - data_year`.

- If `lag < 0`: DQI = 100 (future data)

- If `lag >= max_lag`: DQI = 0

- Otherwise: linear decay

Composite DQI: weighted average of pillar DQIs.


### **9.4 Canonical Snapshot Row**

    php
    blomstra_build_flat_snapshot_row(
        $composite_score,
        $rank,
        $coverage_type,
        $pillar_scores,       // pillar_key => percentile score
        $pillar_dqi = [],     // pillar_key => DQI
        $composite_dqi = null,
        $vintage_summary = null
    )

Output shape (flat):

    php
    [
        'composite_score' => float,
        'rank'            => int|null,
        'coverage_type'   => 'full'|'partial',
        'energy'          => float,
        'hhi'             => float,
        'maritime'        => float,
        'dqi_energy'      => float|null,
        'dqi_hhi'         => float|null,
        'dqi_maritime'    => float|null,
        'composite_dqi'   => float|null,
        'vintage_summary' => string|null,
    ]

Invariant: Every index's live build and historical backfill MUST use this function. Hand‑built rows are forbidden.


### **9.5 Generic Index Builder**

    php
    blomstra_build_index_composite($config, $context)

Contexts:

- `'manual'` – admin‑triggered build; writes to production if validation passes.

- `'cron'` – auto‑refresh; writes to production with auto‑rollback safeguard.

- `'historical'` – backfill; does not write to production; returns snapshot data.

- `'scenario'` – custom weight test; does not write to production.

Auto‑rollback logic (cron only):

- If new build has fewer than 80% of previous country count (and new count < 50):

  - Preserve old composite

  - Set transient `{slug}_auto_build_failed`

  - Log error

  - Return old composite

Scenario safety:

- If `$context === 'scenario'` or custom weights are provided, no `update_option()` is called on the production key.

***


## **10. Index Layer (L3) – SIVI**

### **10.1 SIVI Pillars**

|          |          |                                                         |                |                        |
| -------- | -------- | ------------------------------------------------------- | -------------- | ---------------------- |
| Pillar   | Weight   | Indicator                                               | Source         | Inversion              |
| Energy   | 33.3333% | Energy dependency (consumption‑weighted across 5 fuels) | EIA            | No                     |
| HHI      | 33.3333% | Supplier concentration (HHI of import partners)         | UN Comtrade    | No                     |
| Maritime | 33.3334% | Maritime connectivity (LSCI)                            | World Bank WDI | Yes (100 - percentile) |

Minimum pillars required: 2 of 3 for a composite score.


### **10.2 SIVI Storage**

Pillar storage (`sivi_energy_data`, `sivi_hhi_data`, `sivi_maritime_data`):

    php
    [
        'data' => [
            'USA' => [
                'value'        => float,
                'source'       => string,
                'data_year'    => int|null,
                'last_updated' => 'YYYY-MM-DD HH:MM:SS',
            ],
        ],
        'sources' => [
            'USA' => [
                'indicator_name' => [
                    ['source' => 'EIA', 'scope' => 'national', 'year' => 2024],
                ],
            ],
        ],
    ]

Composite storage (`sivi_composite_index`):

    php
    [
        'version'         => '3.3.0',
        'last_updated'    => 'YYYY-MM-DD HH:MM:SS',
        'total_countries' => 182,
        'excluded'        => 14,
        'excluded_detail' => ['ISO3' => ['reason' => '...', 'pillars_present' => 2, 'pillars_missing' => ['energy']]],
        'weights'         => ['energy' => 33.3333, 'hhi' => 33.3333, 'maritime' => 33.3334],
        'countries' => [
            'USA' => [
                'iso3'       => 'USA',
                'name'       => 'United States',
                'sivi_structural' => 42.35,
                'coverage'   => 'full'|'partial',
                'rank_display' => [
                    'is_definitive' => true|false,
                    'best_estimate' => int,
                    'range_80_low'  => int|null,
                    'range_80_high' => int|null,
                    'theoretical_low' => int|null,
                    'theoretical_high' => int|null,
                    'string_format' => 'string',
                ],
                'energy_dependency_percentile' => float,
                'supplier_concentration_percentile' => float,
                'maritime_vulnerability_percentile' => float,
                'composite_dqi'   => float|null,
                'vintage_summary' => string|null,
                'sensitivity_interval' => ['point' => float, 'ci_low' => float, 'ci_high' => float]|null,
                'pillars' => [
                    'energy' => ['score' => float, 'weight' => float],
                    'hhi'    => ['score' => float, 'weight' => float],
                    'maritime' => ['score' => float, 'weight' => float],
                ],
            ],
        ],
        '_meta' => [
            'built_at'            => 'YYYY-MM-DD HH:MM:SS',
            'source'              => 'manual|cron|historical|scenario',
            'status'              => 'valid',
            'standard_version'    => 'BMS-1.1.0',
            'methodology_version' => '3.3.0',
            'dqi_config' => ['energy_max_lag' => 3, 'hhi_max_lag' => 3, 'maritime_max_lag' => 5, 'reference_year' => 2026],
        ],
    ]


### **10.3 SIVI Refresh Architecture**

Flow:

    text
    External trigger (admin button / cron / reference-data event)
        │
        ▼
    sivi_refresh_energy_pillar()   → reads L1 EIA cache, stores in sivi_energy_data
    sivi_refresh_hhi_pillar()      → reads L1 HHI cache, stores in sivi_hhi_data
    sivi_refresh_maritime_pillar() → reads L1 maritime cache, stores in sivi_maritime_data
        │
        ▼
    sivi_build_composite($context)
        │
        ▼
    blomstra_build_index_composite($config, $context)
        │
        ├─ computes percentiles per pillar
        ├─ aggregates by country
        ├─ assigns ranks (full or partial)
        ├─ computes DQI
        ├─ saves snapshot via blomstra_index_snapshot_save()
        └─ writes to sivi_composite_index (if not scenario)

Auto‑refresh: SIVI listens to `blomstra_cron_eia_weekly_event`, `blomstra_cron_hhi_weekly_event`, `blomstra_cron_maritime_weekly_event` and schedules `sivi_auto_refresh_cron` after reference data refreshes.


### **10.4 SIVI Historical Backfill**

    php
    sivi_build_historical_snapshot($year)

- Uses year‑specific fetchers: `blomstra_fetch_eia_for_year`, `blomstra_fetch_hhi_for_year`, `blomstra_fetch_maritime_for_year`

- These fetchers retry only the countries still missing at each earlier year (matching the pattern of `blomstra_fetch_maritime_for_year`).

- Snapshot rows are built via `blomstra_build_flat_snapshot_row()` with the correct per‑year period key.

- Status tracked in `sivi_backfill_status` option.

Backfill range: Configurable per index via `{slug}_backfill_range_start` / `{slug}_backfill_range_end`. Default: previous 5 years (capped at 2004 for SIVI due to maritime data availability).


### **10.5 SIVI REST Endpoint**

    text
    GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index

Permission: Public (`__return_true`)\
Cache: `Cache-Control: public, max-age=3600
`Error: Returns `WP_Error` with `no_data` if index not built.

***


## **11. Frontend Layer (L4)**

### **11.1 Widget Initialization**

The frontend engine discovers and initializes widgets automatically:

    javascript
    document.querySelectorAll('.biw[data-biw-slug]:not([data-biw-initialized])')

Each widget creates a `BlomstraIndexWidget` instance, isolated from others.


### **11.2 Configuration Contract (**`data-biw-*`**)**

|                             |          |                              |                                                                     |
| --------------------------- | -------- | ---------------------------- | ------------------------------------------------------------------- |
| Attribute                   | Required | Purpose                      | Example                                                             |
| `data-biw-slug`             | Yes      | Index identifier             | `sivi`                                                              |
| `data-biw-endpoint`         | Yes      | Primary REST endpoint        | `/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index` |
| `data-biw-names-endpoint`   | No       | Country names endpoint       | `/wp-json/blomstra/v1/country-names`                                |
| `data-biw-history-endpoint` | No       | History endpoint             | `/wp-json/blomstra/v1/index-history/sivi`                           |
| `data-biw-score-key`        | Yes      | Score field name in response | `sivi_structural`                                                   |
| `data-biw-coverage-key`     | Yes      | Coverage field name          | `coverage`                                                          |
| `data-biw-view`             | No       | Initial view                 | `dashboard` or `table`                                              |
| `data-biw-pillars`          | No       | Pillar configuration         | `[{"key":"energy","label":"Energy","color":"#60a5fa"}]`             |
| `data-biw-band-thresholds`  | No       | Score thresholds             | `25,50,75`                                                          |
| `data-biw-band-labels`      | No       | Band labels                  | `Low,Medium,High,Extreme`                                           |
| `data-biw-score-label`      | No       | Score display label          | `Vulnerability Score`                                               |
| `data-biw-methodology`      | No       | Methodology HTML             | `...`                                                               |
| `data-biw-year-min`         | No       | Minimum year for slider      | `2004`                                                              |
| `data-biw-year-max`         | No       | Maximum year for slider      | `2026`                                                              |
| `data-biw-block-groups`     | No       | Enabled country groups       | `["G7","BRICS"]`                                                    |


### **11.3 Views**

|           |                                                                                                      |
| --------- | ---------------------------------------------------------------------------------------------------- |
| View      | Description                                                                                          |
| Dashboard | Summary cards, world map (D3), donut chart, histogram, scatter plot, top movers, year slider         |
| Table     | Sortable, searchable, filterable table with ranks, scores, pillars, watchlist, comparison checkboxes |


### **11.4 Client‑Side State**

    javascript
    state = {
        all: [],              // all countries
        filtered: [],         // filtered/sorted countries
        names: {},            // country names
        history: {},          // historical snapshots
        indexMeta: null,      // index metadata
        view: 'dashboard',    // current view
        sortKey: 'rank',      // current sort column
        sortAsc: false,       // sort direction
        showWatchlistOnly: false,
        selectedCountry: null,
        compareList: [],      // up to 4 countries
        isDark: false,
        selectedYear: maxYear,
        mapLayer: 'score',    // 'score' or pillar key
    }


### **11.5 Features**

|                    |                                                                                         |
| ------------------ | --------------------------------------------------------------------------------------- |
| Feature            | Implementation                                                                          |
| Watchlist          | `localStorage`, keyed by slug                                                           |
| Country comparison | Up to 4 countries; radar + table                                                        |
| Group comparison   | Pre‑defined groups (G7, BRICS, EU, etc.); modal with radar, stats, CSV export           |
| CSV Export         | Exports current filtered/sorted data                                                    |
| Sharing            | X, LinkedIn, copy link                                                                  |
| Dark mode          | Toggled, stored in `localStorage`                                                       |
| Map                | D3 + topojson; year slider; layer selection; zoom controls                              |
| Country drawer     | Score, rank, delta, DQI, pillar bars, radar, history sparkline, sensitivity, provenance |

***


## **12. Alert System**

### **12.1 Overview**

The alert system monitors changes after each index rebuild and sends notifications via email, webhook, and/or Slack.

File: `src/shared/blomstra-index-alerts.php`


### **12.2 Key Functions**

|                                     |                                                                   |
| ----------------------------------- | ----------------------------------------------------------------- |
| Function                            | Purpose                                                           |
| `blomstra_alert_detect_changes()`   | Compares old vs new data; detects rank, score, and pillar changes |
| `blomstra_alert_store_records()`    | Stores alerts in `wp_blomstra_alerts` table                       |
| `blomstra_fire_index_alerts()`      | Orchestrator – detects, stores, queues background delivery        |
| `blomstra_deliver_pending_alerts()` | Cron handler – sends queued alerts                                |
| `blomstra_alert_cleanup()`          | Deletes old alerts, trims per index                               |


### **12.3 Alert Flow**

    text
    Composite built
        │
        ▼
    blomstra_fire_index_alerts($slug, $new_data, $old_data)
        │
        ├─ Check config (enabled, cooldown)
        ├─ Detect changes
        ├─ Store alerts in DB (if enabled)
        ├─ Queue notifications via transient + cron
        └─ (if storing disabled) send synchronously

Cooldown: 5 minutes (configurable) – prevents duplicate alerts for the same change set.


### **12.4 Admin UI**

- Configuration: enable/disable, email, webhook URL, Slack URL, retention days, max per index

- Alert management: cleanup, flush per index, bulk delete

- System log: info, warnings, errors, config changes

- Diagnostics: test alert, table health

***


## **13. Data Provenance & Quality**

### **13.1 Provenance Tracking**

Every indicator value must be tracked via `blomstra_track_source`:

    php
    blomstra_track_source($sources, $iso3, $indicator, $source, $scope, $year);

Controlled vocabulary for source:

|                   |                                                  |
| ----------------- | ------------------------------------------------ |
| Token             | Meaning                                          |
| `EIA`             | U.S. Energy Information Administration           |
| `WB_WDI`          | World Bank World Development Indicators          |
| `WB_WGI`          | World Bank Worldwide Governance Indicators       |
| `IMF_WEO`         | IMF World Economic Outlook                       |
| `UN_COMTRADE`     | UN Comtrade                                      |
| `WB_LSCI`         | World Bank Liner Shipping Connectivity Index     |
| `structural_zero` | Methodologically correct zero (e.g., landlocked) |


### **13.2 Data Quality Flags**

    php
    blomstra_data_quality_flag($sources, $iso3, $indicator, $current_year)

Returns:

- `available`: bool

- `staleness_years`: int|null

- `source`: string

- `scope`: string

- `quality`: `'good'` | `'aged'` | `'stale'` | `'missing'`

- `year`: int|null

Quality levels:

- `good`: staleness ≤ 1 year

- `aged`: staleness 2–3 years

- `stale`: staleness > 3 years

- `missing`: no data


### **13.3 Pillar Quality Score**

    php
    blomstra_pillar_quality_score($sources, $iso3, $indicators, $current_year)

Returns:

- `coverage_pct`: float (0–100)

- `avg_staleness`: float|null

- `quality_counts`: `['good' => int, 'aged' => int, 'stale' => int, 'missing' => int]`

***


## **14. Operations**

### **14.1 Cron Schedule**

|                   |                                            |           |                  |
| ----------------- | ------------------------------------------ | --------- | ---------------- |
| Pillar            | Hook                                       | Frequency | Lock TTL         |
| Countries         | `blomstra_cron_countries_async_event`      | Weekly    | 10 min           |
| Reporters         | `blomstra_cron_reporters_async_event`      | Weekly    | 10 min           |
| Maritime          | `blomstra_cron_maritime_weekly_event`      | Weekly    | 10 min           |
| HHI               | `blomstra_cron_hhi_weekly_event`           | Weekly    | 30 min           |
| EIA               | `blomstra_cron_eia_weekly_event`           | Weekly    | 30 min           |
| WB Indicators     | `blomstra_cron_wb_indicators_weekly_event` | Weekly    | 30 min           |
| IMF               | `blomstra_cron_imf_weekly_event`           | Weekly    | 10 min           |
| SIVI Auto‑Refresh | `sivi_auto_refresh_cron`                   | Daily     | (via build lock) |
| Alerts Cleanup    | `blomstra_alert_cleanup_daily`             | Daily     | —                |


### **14.2 Health States**

|             |      |                                |                              |
| ----------- | ---- | ------------------------------ | ---------------------------- |
| State       | Icon | Meaning                        | Action                       |
| `success`   | 🟢   | Last run completed, data fresh | None                         |
| `partial`   | 🟡   | Some data missing              | Monitor; retry if persistent |
| `running`   | 🔵   | Currently executing            | Wait                         |
| `stuck`     | 🔴   | Lock exists but run timed out  | Clear lock, retry            |
| `error`     | 🔴   | Permanent failure              | Investigate logs             |
| `retryable` | 🟠   | Transient failure              | Wait for next cron           |
| `stale`     | 🟡   | Exceeds freshness threshold    | Refresh recommended          |
| `never-run` | ⚪    | No data, no pointer, no lock   | Initialize                   |


### **14.3 Emergency Procedures**

|                    |                        |                                                           |
| ------------------ | ---------------------- | --------------------------------------------------------- |
| Situation          | Command                | Effect                                                    |
| Stuck lock         | Flush pillar cache     | Deletes lock transient + pointer                          |
| Corrupt staging    | Flush pillar cache     | Deletes staging option                                    |
| Need clean slate   | Emergency Flush All    | Deletes ALL L1 caches, pointers, logs                     |
| Index build failed | Rebuild from cache     | Triggers `build_composite(manual)` without re‑fetching L1 |
| Suspect API outage | API Diagnostic Sandbox | Tests single target without batch execution               |

***


## **15. Engineering & Development Rules**

### **15.1 Mandatory Rules (Normative)**

1. No undocumented public function. Every function that is not `private` must have PHPDoc/JSDoc.

2. No direct external API call from frontend. L4 must only call L3 REST endpoints.

3. No cross‑index dependency. Each index backend is self‑contained.

4. No production overwrite before validation. Staging→promotion invariant (see §7).

5. No scenario may mutate production data. Scenario builds write only to scenario keys.

6. No missing value may silently become zero. Distinguish observed zero, structural zero, missing, invalid, excluded.

7. Every indicator must have provenance. Source, scope, year via `blomstra_track_source`.

8. Every methodological change requires an index‑version change. Methodology version must be bumped.

9. Every REST contract change requires documentation update. Contracts must stay in sync.

10. Every architectural exception requires a recorded deviation. Logged with rationale and review date.


### **15.2 Naming Conventions**

|            |                            |                                |
| ---------- | -------------------------- | ------------------------------ |
| Scope      | Pattern                    | Example                        |
| Functions  | `{prefix}_{verb}_{noun}()` | `sivi_refresh_energy_pillar()` |
| Constants  | `{PREFIX}_{NAME}`          | `SIVI_ENERGY_KEY`              |
| Options    | `{prefix}_{descriptor}`    | `sivi_energy_data`             |
| Transients | `{prefix}_{pillar}_{iso3}` | `sivi_energy_USA`              |
| Hooks      | `{prefix}_{action}`        | `sivi_async_fetch_energy`      |

***


## **16. Versioning & Change Management**

### **16.1 Version Tracks**

|                           |                                           |                      |
| ------------------------- | ----------------------------------------- | -------------------- |
| Track                     | Changes When                              | Example              |
| Documentation version     | Doc structure/content changes             | v1.0.0               |
| Index methodology version | An index's scientific calculation changes | SIVI v3.3.0 → v4.0.0 |
| Software release          | Code changes, regardless of methodology   | insights-wp v3.1.2   |
| Reference data version    | Data state/release where applicable       | (varies)             |

These versions MUST NOT be conflated.


### **16.2 Change Classification**

|       |                                                                                            |                                             |
| ----- | ------------------------------------------------------------------------------------------ | ------------------------------------------- |
| Type  | Definition                                                                                 | Example                                     |
| Patch | No externally meaningful behaviour change                                                  | Typo fix, comment                           |
| Minor | Backward‑compatible functionality                                                          | New indicator; new admin option             |
| Major | Breaking change to contracts, architecture, methodology, data semantics, or interpretation | New pillar weight scheme; REST shape change |

Methodological changes must receive explicit research review before implementation.

***


## **17. Open Questions (Unresolved)**

These are research/product decisions that require explicit resolution before the relevant sections can become normative.

|   |                                                                                                                                               |                                                                                    |
| - | --------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| # | Question                                                                                                                                      | Notes                                                                              |
| 1 | Temporal methodology – What observation‑age/reference‑period policy should SIVI use?                                                          | Rolling window, reference‑period approach, freshness weighting, or another method? |
| 2 | Pillar‑level temporal filtering – Should freshness be applied as exclusion, quality attribute, weighting mechanism, or something else?        | —                                                                                  |
| 3 | Minimum coverage – What coverage ratio is scientifically acceptable for a composite score?                                                    | Currently 2/3 pillars for SIVI; is that sufficient?                                |
| 4 | Reference population – What country set defines percentile distributions?                                                                     | All World Bank members? Exclude small states?                                      |
| 5 | Historical comparability – How should methodological changes affect comparison between index editions?                                        | Should v3.3.0 and v4.0.0 be comparable?                                            |
| 6 | Uncertainty representation – Should SIVI publish uncertainty intervals, confidence/quality scores, rank stability, or another representation? | —                                                                                  |
| 7 | Data‑quality model – How should completeness, freshness, source quality, and methodological uncertainty be combined or displayed?             | —                                                                                  |

These should be resolved in the `research/` track, not silently in engineering.

***


## **18. Documentation Governance**

### **18.1 The Constitution**

1. This specification is the sole canonical system authority.

2. Documentation must never describe behaviour that the code does not implement.

3. Any change to architecture, contracts, methodology, data semantics, or operational behaviour requires a documentation update in the same change set as the code.

4. Historical documentation is archived, never silently kept as if current.

5. No document may claim normative authority unless explicitly marked CANONICAL.

6. Normative and descriptive statements must always be distinguishable (see §3).

7. Every canonical release must state the exact commit SHA it was verified against.

8. No AI or developer may treat archived documentation as a current requirement.


### **18.2 Document Types**

|            |                                                            |
| ---------- | ---------------------------------------------------------- |
| Status     | Meaning                                                    |
| DRAFT      | Being constructed or reviewed                              |
| CANONICAL  | Approved source of truth                                   |
| REFERENCE  | Supporting technical detail consistent with canonical spec |
| PUBLIC     | External methodology/documentation                         |
| HISTORICAL | Superseded documentation retained for provenance           |
| DEPRECATED | Still present but scheduled for removal                    |


### **18.3 Required Supporting Documents**

Once this master specification is approved, the following will be produced:

- `BLOMSTRA-ARCHITECTURE.md` – Detailed layer boundaries, dependencies, bootstrap sequence

- `BLOMSTRA-DATA.md` – Reference‑data acquisition, storage, provenance, validation, refresh lifecycle

- `BLOMSTRA-SIVI.md` – Complete scientific definition and mathematical specification of SIVI

- `BLOMSTRA-FRONTEND.md` – Generic widget architecture, config contract, state model, views

- `BLOMSTRA-API.md` – Public and internal REST interfaces

- `BLOMSTRA-OPERATIONS.md` – Cron, locks, failures, alerts, monitoring, deployment, recovery

- `BLOMSTRA-DEVELOPMENT.md` – Coding rules, invariants, testing, documentation, change control

- `BLOMSTRA-PUBLIC-METHODOLOGY.md` – External‑facing methodology

***


## **End of Specification**

Status: CANONICAL DRAFT – derived from current codebase.\
Next steps: Full verification against repository; expand supporting documents; final review.

***

_This document supersedes all pre‑unification architecture, contract, implementation, and engineering‑standard documents. Historical documents are retained solely for provenance and migration context._
