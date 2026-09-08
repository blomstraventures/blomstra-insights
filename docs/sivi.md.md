# **SIVI.md – Sovereign Infrastructure Vulnerability Index** {#h.ysh38rdbalww}

Document version: 1.0.0\
Status: CANONICAL\
Applies to: `src/indices/sivi/
`Verified against commit: (to be filled)\
Source files: `sivi-backend.php` (SIVI\_VERSION 3.3.0), `sivi-shortcode.php`, `blomstra-index-utilities.php
`Effective date: 2026-09-09

***


## **Document Control** {#h.jydrrb7p1c7j}

|                         |                                                                                                                                             |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Field                   | Value                                                                                                                                       |
| Document                | `SIVI.md` v1.0.0                                                                                                                            |
| Verified against commit | (to be filled)                                                                                                                              |
| Source files            | `src/indices/sivi/sivi-backend.php` (SIVI\_VERSION 3.3.0), `src/indices/sivi/sivi-shortcode.php`, `src/shared/blomstra-index-utilities.php` |
| Method                  | Code-first reconstruction — every statement traces to a specific function or constant in the files above                                    |
| Status                  | Complete for current code. Items in §16 are open research questions, not implementation gaps                                                |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## **Table of Contents** {#h.yoc0x6zarzlx}

1. Overview

2. Conceptual Framework

3. Pillar Specifications

4. Data Acquisition

5. Normalization

6. Aggregation

7. Ranking

8. Partial Index Logic

9. Data Quality Index (DQI)

10. Historical Snapshots

11. Sensitivity Testing

12. Refresh Architecture

13. Build Lifecycle

14. REST API

15. Admin UI

16. Versioning

17. Open Questions

***


## **1. Overview** {#h.bzr1cx64i98u}

\[D] SIVI (`sivi_*` functions, `SIVI_OPTION_KEY = 'sivi_composite_index'`) is a three-pillar composite score per country. SIVI\_VERSION = '3.3.0', standard version BMS-1.1.0. Higher score = higher vulnerability; rank #1 is the _most_ vulnerable country (stated verbatim in the shortcode's methodology text).

\[D] Purpose (from the shortcode's public methodology string): "A country-level assessment of exposure, dependency, and systemic weakness," combining Energy Dependency, Supplier Concentration, and Maritime Exposure.


### **1.1 The Three Pillars** {#h.kigollp3arr}

|          |          |                                                                                    |
| -------- | -------- | ---------------------------------------------------------------------------------- |
| Pillar   | Weight   | What It Measures                                                                   |
| Energy   | 33.3333% | Dependency on imported energy, consumption-weighted across five fuel types         |
| HHI      | 33.3333% | Supplier concentration (import partner concentration)                              |
| Maritime | 33.3334% | Maritime connectivity and access (inverted: high connectivity = low vulnerability) |

\[N] Minimum pillars required: 2 of 3 for a composite score (`SIVI_MIN_PILLARS_REQUIRED = 2`). Countries with fewer than 2 pillars are excluded entirely.

File location: `src/indices/sivi/sivi-backend.php`

***


## **2. Conceptual Framework** {#h.c1qtjns0oqcu}

### **2.1 The Vulnerability Construct** {#h.uui4t9tjvqbg}

SIVI measures exposure to disruption across infrastructure systems that are essential for economic and social functioning:

|          |                      |                                                                             |
| -------- | -------------------- | --------------------------------------------------------------------------- |
| Pillar   | Exposure Type        | Mechanism                                                                   |
| Energy   | Supply dependency    | Import reliance means external shocks can disrupt energy availability       |
| HHI      | Supply concentration | Few suppliers means disruption to any one supplier affects the whole system |
| Maritime | Access dependency    | Limited maritime connectivity constrains trade and supply chain resilience  |

SIVI does not measure:

- Resilience (ability to recover) – that is SERI's domain

- Geopolitical event risk – that is GPRI's domain

- Absolute infrastructure quality – that's a different construct


### **2.2 Directionality** {#h.mdilpiezhtg5}

All pillars are oriented so that higher score = higher vulnerability:

|                              |                                        |                             |
| ---------------------------- | -------------------------------------- | --------------------------- |
| Pillar                       | Raw Direction                          | Vulnerability Direction     |
| Energy dependency            | Higher dependency = more vulnerable    | Same                        |
| HHI (supplier concentration) | Higher concentration = more vulnerable | Same                        |
| Maritime connectivity        | Higher connectivity = less vulnerable  | Inverted (100 - percentile) |


### **2.3 Why Percentile Ranks** {#h.c1aq2k1iucv8}

SIVI uses percentile ranks rather than absolute thresholds because:

1. Secular drift: Global averages change over time; absolute thresholds become outdated

2. Context blindness: The same absolute value means different things in different contexts

3. Comparative positioning: Percentile ranks show where a country stands right now relative to peers

***


## **3. Pillar Specifications** {#h.1094vdzg8hvw}

### **3.1 Pillar Definitions** {#h.5pqltb7g3iay}

\[D] Defined in `sivi_get_pillar_weights()` and `sivi_get_pillar_defs()`:

|            |                        |                          |                                   |                         |
| ---------- | ---------------------- | ------------------------ | --------------------------------- | ----------------------- |
| Pillar key | Name                   | Indicator                | Source                            | Weight within pillar    |
| `energy`   | Energy Dependency      | `energy_dependency`      | EIA                               | 100% (single-indicator) |
| `hhi`      | Supplier Concentration | `supplier_concentration` | UN Comtrade                       | 100% (single-indicator) |
| `maritime` | Maritime Connectivity  | `maritime_connectivity`  | World Bank WDI (`IS.SHP.GCNW.XQ`) | 100% (single-indicator) |

\[D] Each pillar currently has exactly one indicator carrying its full internal weight — the multi-indicator-per-pillar machinery in the generic builder exists but isn't exercised by SIVI today.


### **3.2 Composite (Cross-Pillar) Weights** {#h.m24qyalbjojx}

\[D] Default: `energy = 33.3333`, `hhi = 33.3333`, `maritime = 33.3334` (`sivi_get_composite_weights()`).

\[D] Overridable via option `sivi_custom_composite_weights` — accepted only if the three weights sum to 100 within 0.01 tolerance; an invalid custom set is discarded (`delete_option`) and silently falls back to defaults, with an `error_log` entry.


### **3.3 Energy Pillar** {#h.2eqko2vasu35}

Weight: 33.3333%\
Source: U.S. Energy Information Administration (EIA)\
Indicator: Consumption‑weighted energy dependency


#### Data Source {#h.5eo2hceo67ks}

API Endpoint: `https://api.eia.gov/v2/international/data/
`Product IDs: 4411 (Coal), 4413 (Natural Gas), 4415 (Petroleum), 4417 (Nuclear), 4418 (Renewables)\
Activity IDs: 2 (Consumption), 1 (Production)\
Unit: QBTU (Quadrillion British Thermal Units)


#### Calculation {#h.pprx5lshme6j}

\[D] `sivi_eia_aggregate_energy_dependency()` computes, per country, a consumption-share-weighted average of per-fuel dependency across five EIA fuel categories:

    text
    fuel_dependency  = (consumption − production) / consumption × 100      [per fuel]
    country_value    = Σ( fuel_dependency_f × (consumption_f / total_consumption) )   over fuels f with usable data

\[D] A fuel is included only if both consumption is present and non-zero, and production is present (including explicit `confirmed_zero`, distinguished in output via a `note` field). A country with no usable fuel data or zero total consumption gets `value: null`, not zero.


#### Winsorization {#h.5pzcaql8mf1}

\[D] Energy pillar uses 0% winsorization – no capping of extreme values.


#### Notes {#h.6r0qrmewcpjb}

- Countries with no EIA data for any fuel are excluded from the energy pillar (pillar is null for that country)

- The representative year used is the most recent year with consumption data across fuels

***


### **3.4 HHI Pillar (Supplier Concentration)** {#h.ek2t5gld9bx1}

Weight: 33.3333%\
Source: UN Comtrade\
Indicator: Herfindahl‑Hirschman Index (HHI) of import partner concentration


#### Data Source {#h.ja44dmh4g275}

API Endpoint: `https://comtradeapi.un.org/data/v1/get/C/A/HS
`Flow Code: M (Imports)\
Cmd Code: TOTAL\
Partner Code: All partners (0 = world total, 1-9 = regional aggregates)


#### Reporter Codes {#h.4isndkjy5qvh}

SIVI uses the UN Comtrade reporter map (`blomstra_get_comtrade_reporter_map`) to convert ISO3 country codes to numeric reporter codes:

- USA → 842, DEU → 276, CHN → 156, etc.

Lookback: If data for the target year is unavailable, the system looks back up to 4 years (`BLOMSTRA_HHI_LOOKBACK = 4`).


#### Calculation {#h.lu35yyq34knj}

\[D] Not computed inside SIVI at all — SIVI only _reads_ the already-computed HHI from the shared L1 cache (`blomstra_get_comtrade_hhi_data()`), populated by `global-reference-data.php`'s `blomstra_refresh_comtrade_hhi_data()`), via `sivi_merge_hhi_into_pillar()`.

\[D] The underlying HHI formula (for completeness, from L1): `HHI = Σ(partner_import_share²) × 10000`, scale 0–10000, computed per reporter-year from UN Comtrade import rows, clamped to `[0, 10000]`.


#### Winsorization {#h.nvzkncah8lwi}

\[D] HHI pillar uses 0% winsorization – values are bounded by construction (0-10000).


#### Notes {#h.8m7sfokkn1vx}

- Countries without a Comtrade reporter code are excluded from the HHI pillar

- Countries with no trade data (world total missing or no partner values) are excluded

- The representative year is the actual year of the trade data (may be earlier than the target year due to lookback)

***


### **3.5 Maritime Pillar** {#h.iz8uiirdg0ul}

Weight: 33.3334%\
Source: World Bank WDI (World Development Indicators)\
Indicator: LSCI – Liner Shipping Connectivity Index


#### Data Source {#h.906s52jux4bm}

API Endpoint: `https://api.worldbank.org/v2/country/all/indicator/IS.SHP.GCNW.XQ
`Coverage: 20-year range from current year


#### Calculation {#h.490lenomy31k}

\[D] `sivi_refresh_maritime_pillar()` reads World Bank LSCI values from the shared L1 cache (`blomstra_get_maritime_raw()`).

Inversion:

    text
    connectivity_percentile = blomstra_compute_percentile_ranks_safe(connectivity_values)
    maritime_vulnerability = 100 - connectivity_percentile

This ensures higher vulnerability scores correspond to lower connectivity.


#### Structural Zero (Landlocked Countries) {#h.j6sq8fgd4a82}

\[D] Landlocked countries (`sivi_is_landlocked()` → delegates to `blomstra_is_landlocked()`) get an explicit structural zero (`value: 0.0`, source `"Structural zero — landlocked"`) rather than being treated as missing data. This is not treated as missing — the zero is real and meaningful. Landlocked countries are scored in the Full Index, not the Partial Index.

\[D] Countries with neither an LSCI value nor landlocked status get `value: null`.


#### Winsorization {#h.t6sv0k8qh2ag}

\[D] Maritime pillar uses 1% winsorization – caps extreme outliers (thin/erratic LSCI data for small or remote nations).

***


## **4. Data Acquisition** {#h.xmn9l1dc24nc}

### **4.1 Pillar Refresh Functions** {#h.debcm6xgqwms}

Each pillar has its own refresh function that reads from the L1 reference data cache:

|          |                                  |                                    |
| -------- | -------------------------------- | ---------------------------------- |
| Pillar   | Function                         | L1 Source                          |
| Energy   | `sivi_refresh_energy_pillar()`   | `blomstra_get_eia_raw_data()`      |
| HHI      | `sivi_refresh_hhi_pillar()`      | `blomstra_get_comtrade_hhi_data()` |
| Maritime | `sivi_refresh_maritime_pillar()` | `blomstra_get_maritime_raw()`      |


### **4.2 Energy Data Flow** {#h.a82sru8gf6yv}

    text
    External trigger (admin button / cron)
        │
        ▼
    sivi_refresh_energy_pillar()
        │
        ▼
    blomstra_get_eia_raw_data()  ← L1 cache
        │
        ▼
    sivi_eia_aggregate_energy_dependency()
        │
        ▼
    sivi_persist_energy_results()
        │
        ▼
    sivi_energy_data (option)
    sivi_energy_meta (option)

\[D] Source data comes from the shared L1 cache (`blomstra_get_eia_raw_data()`), never fetched directly by SIVI. `sivi_refresh_energy_pillar()` pulls the raw consumption/production arrays, aggregates, and persists via `sivi_persist_energy_results()` into `SIVI_ENERGY_KEY`, with per-country provenance tracked via `blomstra_track_source()` and a 12-hour transient cache per ISO3.


### **4.3 HHI Data Flow** {#h.5nrrm8vo4gtb}

    text
    External trigger (admin button / cron)
        │
        ▼
    sivi_refresh_hhi_pillar()
        │
        ▼
    blomstra_get_comtrade_hhi_data()  ← L1 cache
        │
        ▼
    sivi_merge_hhi_into_pillar()
        │
        ▼
    sivi_hhi_data (option)
    sivi_hhi_meta (option)


### **4.4 Maritime Data Flow** {#h.dv6vnc6ivnjh}

    text
    External trigger (admin button / cron)
        │
        ▼
    sivi_refresh_maritime_pillar()
        │
        ▼
    blomstra_get_maritime_raw()  ← L1 cache
        │
        ├─ For landlocked countries → structural zero
        └─ For others → value from cache
        │
        ▼
    sivi_maritime_data (option)
    sivi_maritime_meta (option)


### **4.5 Pillar Storage Shape** {#h.5vw5d9engga4}

Each pillar is stored as:

    php
    [
        'data' => [
            'USA' => [
                'value'        => float,        // raw value before normalization
                'source'       => string,       // e.g., 'EIA', 'Comtrade'
                'data_year'    => int|null,     // actual year of data
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


### **4.6 Meta Storage** {#h.g0hmmzw4z2xk}

Each pillar has a meta option:

    php
    [
        'last_fetched' => 'YYYY-MM-DD HH:MM:SS',
    ]

    sivi_energy_meta

    sivi_hhi_meta

    sivi_maritime_meta


### **4.7 Direct API Fallback** {#h.xdr7kfwnuixk}

\[N] All three pillars are populated exclusively from L1 shared caches — SIVI performs no direct external API calls of its own for live builds (only the fallback country-list fetcher, `sivi_get_global_country_list_fallback()`, calls World Bank directly, and only if the shared `blomstra_get_global_country_list()` is unavailable).

***


## **5. Normalization** {#h.6u8s18xozowf}

### **5.1 Percentile Computation** {#h.1rj8iebzfdg5}

\[D] `blomstra_compute_percentile_ranks_safe()`: values are winsorized (§5.2), then ranked with mean-rank tie handling, converted to percentiles as:

    text
    percentile = ((rank − 0.5) / n) × 100

where `n` is the count of countries with numeric data for that pillar (not the global country count). Tied values receive the average of their tied rank positions.


### **5.2 Winsorization Settings** {#h.iuyj45nd7npc}

\[D] Per-pillar winsorization percentages, applied only when `n ≥ 10`:

|          |               |                                                 |
| -------- | ------------- | ----------------------------------------------- |
| Pillar   | Winsorization | Rationale                                       |
| Energy   | 0%            | Extremes are real and meaningful                |
| HHI      | 0%            | Bounded by construction (0-10000)               |
| Maritime | 1%            | Thin/erratic LSCI data for small/remote nations |


### **5.3 Directionality (Post-Percentile Transform)** {#h.mbwll1j1e91v}

\[N] Maritime's raw percentile is inverted (`100 − pct`) before entering the composite, since higher LSCI (more connected) means _lower_ vulnerability — the only pillar needing this transform, applied via `post_percentile_transform['maritime']` in `sivi_get_generic_config()`. Energy and HHI percentiles are used as-is (higher raw value already means higher vulnerability for both).


### **5.4 Directionality Summary** {#h.j07rqek5ni6p}

|          |                                |                                                |
| -------- | ------------------------------ | ---------------------------------------------- |
| Pillar   | Raw → Percentile               | Percentile → Vulnerability                     |
| Energy   | Higher raw = higher percentile | Same – high percentile = high vulnerability    |
| HHI      | Higher raw = higher percentile | Same – high percentile = high vulnerability    |
| Maritime | Higher raw = higher percentile | Inverted – high percentile = low vulnerability |

***


## **6. Aggregation** {#h.1458p3loev3f}

### **6.1 Per-Pillar Aggregation** {#h.3w6nqgq0u40f}

Each pillar has a single indicator (no within-pillar aggregation needed):

|          |                         |                      |
| -------- | ----------------------- | -------------------- |
| Pillar   | Indicator               | Weight Within Pillar |
| Energy   | energy\_dependency      | 100%                 |
| HHI      | supplier\_concentration | 100%                 |
| Maritime | maritime\_connectivity  | 100%                 |


### **6.2 Composite Aggregation (Full Coverage)** {#h.gkf65p2k7qvm}

\[D] For a country with `k` present pillars:

    text
    composite_score = round( Σ(percentile_i × weight_i) / Σ(weight_i), 1 )

A weighted average, not re-normalized to sum-to-100 unless all present pillars' weights already do (in SIVI's case they always do, since all three composite weights sum to \~100).

Example (all three pillars present):

    text
    composite = (energy_score × 0.333333) + (hhi_score × 0.333333) + (maritime_score × 0.333334)


### **6.3 Composite Aggregation (Partial Coverage)** {#h.mxsymwmtjaz2}

When only two pillars are present (minimum required = 2):

    text
    available_weight = weight_pillar1 + weight_pillar2
    composite = (score_pillar1 × weight_pillar1 + score_pillar2 × weight_pillar2) / available_weight

Example: If Energy is missing and weights are 33.33/33.33/33.34:

    text
    available_weight = 33.33 + 33.34 = 66.67
    composite = (hhi_score × 33.33 + maritime_score × 33.34) / 66.67


### **6.4 Coverage Classification** {#h.4qhywtx61bh}

|                 |           |                      |
| --------------- | --------- | -------------------- |
| Pillars Present | Coverage  | Rank Type            |
| 3 of 3          | `full`    | Definitive rank      |
| 2 of 3          | `partial` | Projected rank range |
| < 2 of 3        | Excluded  | No rank              |

***


## **7. Ranking** {#h.v2yhitr6hwuq}

### **7.1 Full Index (Definitive Rank)** {#h.k34k0pppnrqa}

\[D] Countries with all three pillars present receive a definitive rank:

    text
    sort countries by composite_score descending
    rank = position (1 = most vulnerable)

Tie handling: average rank for tied scores.


### **7.2 Partial Index (Projected Rank Range)** {#h.tndtjgr63u6l}

\[D] For partial-coverage countries, since the generic builder's condition (`pillar_count ≥ 3 AND min_required ≥ pillar_count − 1`) is met for SIVI, a hypothetical rank range is computed by injecting five candidate values (0, 10, 50, 90, 100) for the missing pillar and recomputing the composite at each point (`blomstra_project_partial_rank_composite()`), then ranking each hypothetical composite against the full-coverage distribution:

    text
    hypothetical_composite(point) = ( Σ known_pillar_i × weight_i  +  point × weight_missing ) / total_weight

\[D] Display format (`blomstra_build_partial_rank_display()`): `best_estimate` = rank at injection point 50; `range_80_low/high` = ranks at injection points 10/90; `theoretical_low/high` = ranks at 0/100. Rendered as `"#Low–High*"`. A full-coverage country's rank is definitive: `"#N"` (`blomstra_build_full_rank_display()`).


### **7.3 Rank Display Helpers** {#h.5jgg8j52lpxz}

    php
    blomstra_build_full_rank_display($rank)      // For full-index countries
    blomstra_build_partial_rank_display($ranks_by_injection) // For partial-index countries

Output (Partial):

    php
    [
        'is_definitive' => false,
        'best_estimate' => 45,      // at 50th percentile injection
        'range_80_low'  => 38,      // at 10th percentile injection
        'range_80_high' => 52,      // at 90th percentile injection
        'theoretical_low' => 12,    // at 0th percentile injection
        'theoretical_high' => 89,   // at 100th percentile injection
        'string_format' => '#38-#52*',
    ]

***


## **8. Partial Index Logic** {#h.du0natw2dbt}

### **8.1 When Partial Index Is Applied** {#h.vbfxohsdg3pv}

\[N] A country is in the partial index when:

- At least 2 of 3 pillars have data (`SIVI_MIN_PILLARS_REQUIRED = 2`)

- But not all 3 pillars have data


### **8.2 The OECD/JRC Injection Method** {#h.cna531hcylpz}

Why this method:

- Does not fabricate data

- Does not exclude the country

- Communicates uncertainty honestly

- Follows OECD/JRC guidelines for composite indicators with missing data

Mechanism:

    php
    blomstra_project_partial_rank_composite(
        $known_pillars,       // ['energy' => 65.0, 'maritime' => 40.0]
        $missing_pillar,      // 'hhi'
        $injected_values_by_point, // [0 => 0, 10 => 10, 50 => 50, 90 => 90, 100 => 100]
        $pillar_weights       // ['energy' => 33.3333, 'hhi' => 33.3333, 'maritime' => 33.3334]
    )

Returns: Hypothetical composites at each injection point.


### **8.3 What Partial Index Means** {#h.ph3vzb2a8ijn}

- Best estimate: Rank if missing pillar were at global median (50th percentile)

- 80% plausible range: Rank if missing pillar were at 10th or 90th percentile

- Theoretical bounds: Rank if missing pillar were at 0th or 100th percentile

Display: Countries with partial coverage show `#38-#52*` instead of a single definitive rank.

***


## **9. Data Quality Index (DQI)** {#h.qtw94o630ygu}

\[N] DQI is explicitly stated in the shortcode's public methodology text as "disclosed as a confidence metric only — it does not affect the score." Confirmed in code: DQI is computed and attached to output but never enters the composite-score formula in §6.


### **9.1 Per-Pillar DQI** {#h.cb1aq5f2c5td}

\[D] `blomstra_compute_dqi($data_year, $current_year, $max_lag)`:

    text
    lag = current_year − data_year
    DQI = 100                          if lag < 0   (future-dated data)
    DQI = 0                            if lag ≥ max_lag
    DQI = round((1 − lag/max_lag) × 100, 1)   otherwise   (linear decay)

\[D] Max acceptable lag per pillar:

|          |                                   |
| -------- | --------------------------------- |
| Pillar   | Max Lag                           |
| Energy   | 3 years (`SIVI_MAX_LAG_ENERGY`)   |
| HHI      | 3 years (`SIVI_MAX_LAG_HHI`)      |
| Maritime | 5 years (`SIVI_MAX_LAG_MARITIME`) |


### **9.2 Composite DQI** {#h.r4holpt4vkyd}

\[D] `blomstra_compute_composite_dqi()`: weighted average of the pillars that have a non-null DQI, weighted by the same composite pillar weights.

    text
    composite_dqi = Σ(pillar_dqi × pillar_weight) / Σ(pillar_weight)

Where pillar\_weight is the actual weight used (re-normalized if partial coverage).


### **9.3 DQI Interpretation** {#h.6tzxd85ii3lr}

|         |                                |
| ------- | ------------------------------ |
| DQI     | Meaning                        |
| 70-100% | Fresh data (≤ 1 year lag)      |
| 40-69%  | Acceptable data (2-3 year lag) |
| < 40%   | Stale data (> 3 year lag)      |

Note: DQI is a confidence metric only – it does not affect the score.


### **9.4 Vintage Summary** {#h.8qwi9dxc9wzb}

\[D] A human-readable summary of data years per pillar:

    text
    "Energy: 2024, HHI: 2023, Maritime: 2024"

***


## **10. Historical Snapshots** {#h.85x4kqn91mrm}

### **10.1 Overview** {#h.gfkuwopomm5f}

SIVI maintains historical snapshots in the `wp_blomstra_index_history` table.

Purpose:

- Score trends (frontend history chart)

- Rank changes over time

- Data quality comparison


### **10.2 Snapshot Row Shape (Flat)** {#h.4nsx4rbacmj}

\[N] All snapshots use the canonical flat shape from `blomstra_build_flat_snapshot_row()`:

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

\[N] Invariant: Every snapshot MUST use `blomstra_build_flat_snapshot_row()`. Hand‑built rows are forbidden. Both the live build (inside `blomstra_build_index_composite()`) and the historical backfill (`sivi_build_historical_snapshot()`) build their snapshot row via this shared helper — the v3.3.0 fix that eliminated a shape divergence between live and backfilled history rows.


### **10.3 Historical Backfill** {#h.knsz9mvoa6uo}

    php
    sivi_build_historical_snapshot($year)

\[D] Reconstructs a full point-in-time build for a past year using year-specific fetchers:

    blomstra_fetch_eia_for_year

    blomstra_fetch_hhi_for_year

    blomstra_fetch_maritime_for_year

\[D] These fetchers are memoized per call so each is fetched at most once per backfill run. They retry only countries still missing at each earlier year (matching the pattern of `blomstra_fetch_maritime_for_year`).

\[D] Uses the same generic builder and config shape as the live build (`skip_snapshot = true`, since the backfill saves its own row with the correct per-year period key rather than the generic builder's default "now" period).

\[D] Historical snapshots are saved to the shared history table via `blomstra_index_snapshot_save('sivi', $rows, "{$year}-01")` — one row per year, dated to January of that year.


### **10.4 Backfill Range** {#h.s7wql364ogx8}

\[D] Minimum backfill year: `SIVI_BACKFILL_MIN_YEAR = 2004`. Range is admin-configurable (`sivi_backfill_range_start/end` options), validated on save (start ≤ end, start ≥ 2004).

|                  |                                                    |
| ---------------- | -------------------------------------------------- |
| Default          | Cap                                                |
| Previous 5 years | Capped at 2004 (due to maritime data availability) |


### **10.5 Backfill Status Tracking** {#h.um869mx9ekif}

\[D] Backfill runs one year per cron invocation (`sivi_backfill_year_cron` action → `sivi_backfill_year_cron_callback`), gated by a `sivi_backfill_lock` transient; each year's outcome is tracked per-year (`sivi_backfill_status` option):

    php
    [
        2024 => [
            'status'        => 'not_started'|'success'|'partial'|'failed',
            'countries'     => 182,
            'last_attempt'  => '2026-09-09 08:00:00',
            'error'         => null,
        ],
    ]

\[D] `sivi_backfill_check_completion()` clears the lock once every year in the configured range has reached a terminal state (`success`, `partial`, or `failed`).

***


## **11. Sensitivity Testing** {#h.kp5q81iesca9}

### **11.1 Overview** {#h.wnlj7e7bni1c}

\[D] `sensitivity_enabled = true` for SIVI. Sensitivity testing measures how rankings change when pillar weights are altered.

Purpose:

- Robustness validation

- Policy analysis ("what if energy dependency matters more?")

- Research transparency


### **11.2 Bootstrap Confidence Intervals** {#h.by314n3n5pk5}

\[D] Applied only to full-coverage countries via `blomstra_bootstrap_ci()`:

- Requires ≥10 countries with full pillar data, else returns `null` for all.

- 1,000 bootstrap draws (`n_bootstrap = 1000`).

- Each draw perturbs each pillar's weight by a random factor in \[-10%, +10%] (`wp_rand(-1000,1000)/10000`), re-normalizes, and recomputes each country's composite.

- Reports the point estimate plus the 95% interval (`ci_level = 0.95`) taken as the empirical 2.5th/97.5th percentile of the 1,000 draws.

\[N] This is a weight-sensitivity interval, not a statistical sampling-uncertainty interval; it answers "how much would this country's score move if the pillar weights were slightly different," not "how confident are we in the underlying data."


### **11.3 Preset Weight Schemes** {#h.sd8ajpge73tj}

|                |        |       |          |
| -------------- | ------ | ----- | -------- |
| Preset         | Energy | HHI   | Maritime |
| Baseline       | 33.33  | 33.33 | 33.34    |
| Energy-heavy   | 70     | 15    | 15       |
| Energy-light   | 10     | 45    | 45       |
| HHI-heavy      | 15     | 70    | 15       |
| HHI-light      | 45     | 10    | 45       |
| Maritime-heavy | 15     | 15    | 70       |
| Maritime-light | 45     | 45    | 10       |


### **11.4 Scenario Build** {#h.m2hezq1apvg2}

    php
    sivi_build_composite('scenario', null, $custom_composite_weights)

\[N] Scenario builds never write to production. They are stored under `sivi_composite_index_scenario_{id}`.


### **11.5 Scenario Comparison** {#h.etol5h6z2s09}

Scenarios are compared to baseline using:

|                          |                                           |
| ------------------------ | ----------------------------------------- |
| Metric                   | Method                                    |
| Spearman correlation (ρ) | `blomstra_spearman_correlation()`         |
| Top mover                | Country with largest absolute rank change |

Spearman Correlation Interpretation:

|             |                                                            |
| ----------- | ---------------------------------------------------------- |
| ρ           | Interpretation                                             |
| 0.90 - 1.00 | Highly robust – rankings stable                            |
| 0.70 - 0.89 | Moderately robust – pillar carries independent information |
| < 0.70      | Low robustness – sensitive to weighting                    |

***


## **12. Refresh Architecture** {#h.qdvpbyk0ig4u}

### **12.1 Auto-Refresh Triggers** {#h.nvsw86ekkqwb}

\[D] SIVI listens to reference-data refresh events:

    php
    add_action('blomstra_cron_eia_weekly_event', 'sivi_maybe_auto_refresh_after_rd', 30);
    add_action('blomstra_cron_hhi_weekly_event', 'sivi_maybe_auto_refresh_after_rd', 30);
    add_action('blomstra_cron_maritime_weekly_event', 'sivi_maybe_auto_refresh_after_rd', 30);

\[D] When any of these events fire, SIVI schedules a single debounced refresh 60 seconds later, guarded by a 5-minute transient (`sivi_auto_refresh_queued`) so three near-simultaneous upstream completions don't trigger three redundant rebuilds.

    php
    wp_schedule_single_event(time() + 60, SIVI_AUTO_REFRESH_HOOK);


### **12.2 Auto-Refresh Flow** {#h.h13y7b6y4pjn}

    text
    Reference-data event (EIA/HHI/Maritime completed)
        │
        ▼
    sivi_maybe_auto_refresh_after_rd()
        │
        ▼
    wp_schedule_single_event(time() + 60, 'sivi_auto_refresh_cron')
        │
        ▼
    sivi_auto_refresh_callback()
        │
        ├─ Check build lock (prevent duplicate runs)
        ├─ sivi_refresh_energy_pillar()
        ├─ sivi_refresh_hhi_pillar()
        ├─ sivi_refresh_maritime_pillar()
        ├─ sivi_build_composite('cron')
        └─ Update cron status


### **12.3 Daily Cron** {#h.7ruuzeg8wnkz}

\[D] Daily cron at 03:00 UTC (`SIVI_AUTO_REFRESH_HOOK`, scheduled on `init` if not already scheduled) runs `sivi_auto_refresh_callback()`, which refreshes all three pillars from their L1 caches, aborts with a logged error if any pillar refresh errors, and only then rebuilds the composite (context `'cron'`).


### **12.4 Build Lock** {#h.mcqz99rkxq2m}

\[D] Build lock: transient `sivi_build_lock` (or generically `{slug}_build_lock`), TTL `SIVI_LOCK_TTL = 30 minutes`.

    php
    $lock_key = $slug . '_build_lock';
    $lock = get_transient($lock_key);
    if ($lock !== false && (time() - (int)$lock) < $lock_ttl) {
        return new WP_Error('build_in_progress');
    }
    set_transient($lock_key, time(), $lock_ttl);

\[D] A `register_shutdown_function` safety net clears the lock even on a fatal PHP error mid-build, so a crash can't leave the index permanently locked.

\[D] A rebuild in progress (manual build lock held) causes the cron callback to skip and log, not queue or retry.

***


## **13. Build Lifecycle** {#h.mgeszmwk6eop}

### **13.1 The Generic Builder Pattern** {#h.6nk7iuxqijzr}

\[N] SIVI never computes its own statistics — `sivi_build_composite()` always delegates to the shared generic orchestrator `blomstra_build_index_composite()` via `sivi_get_generic_config()`. This is Invariant #3 (universe-aware promotion) and Invariant #1 (bookkeeping key isolation) from the engineering rules, and both are confirmed correctly implemented here:

- \[N] Bookkeeping key isolation (confirmed): the generic builder's internal working copy lives at `sivi_composite_internal` (and `..._staging` during a build); the public, frontend-facing option is `SIVI_OPTION_KEY = 'sivi_composite_index'`, written only by `sivi_build_composite()` after reshaping the generic output — the generic builder itself never touches `SIVI_OPTION_KEY`.

- \[N] Canonical snapshot row (confirmed): both the live build (inside `blomstra_build_index_composite()`) and the historical backfill (`sivi_build_historical_snapshot()`) build their snapshot row via the same shared `blomstra_build_flat_snapshot_row()` helper — the v3.3.0 fix that eliminated a shape divergence between live and backfilled history rows.


### **13.2 Build Steps** {#h.fmiknlro2msp}

\[D] Build steps in order:

1. Acquire country list via `blomstra_get_global_country_list()`

2. Per-pillar raw fetch (fails the whole build with an exception if any fetcher returns non-array)

3. Winsorize + percentile-rank each pillar (`blomstra_compute_percentile_ranks_safe`)

4. Apply directional transform (maritime inversion)

5. Per-country coverage/composite/exclusion decision

6. Full-coverage ranking

7. Partial-coverage rank projection (`blomstra_project_partial_rank_composite`)

8. DQI + vintage summary attachment

9. Sensitivity (optional)

10. Benchmark correlation (optional)

11. Reshape to SIVI's public schema

12. Coverage-drop safety check

13. Alert firing

14. Persist to `SIVI_OPTION_KEY`

15. Snapshot save via `blomstra_index_snapshot_save()`


### **13.3 Build-Failure Safety Guard (Auto-Rollback)** {#h.sjdss37qr36k}

\[N] Before promoting a new build over the existing one, the generic builder compares country counts:

    php
    if ($new_count < 0.8 * $prev_count && $new_count < 50) {
        // Preserve old composite
        set_transient($slug . '_auto_build_failed', 'yes', DAY_IN_SECONDS);
        error_log("{$slug}: Automated build failed – new count ({$new_count}) vs previous ({$prev_count}). Keeping old composite.");
        return $old_composite;
    }

If the new build's count is both less than 80% of the previous build's count and under 50 countries absolute, the new build is discarded and the old composite is kept and returned instead, with an error logged and a `{slug}_auto_build_failed` transient set for 24 hours. This guards against a partial/degraded upstream fetch silently truncating the public index.


### **13.4 Alerts** {#h.wvp2f0ymar3e}

\[D] After a successful build with a pre-existing prior composite, `blomstra_fire_index_alerts()` is called comparing old vs. new country data and metadata; alert count is logged. (Full alert-system behavior is out of scope for this document — see `OPERATIONS.md`.)

***


## **14. REST API** {#h.jw433d9aam43}

### **14.1 Endpoint** {#h.t5ircheu5avt}

    text
    GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index

\[D] Public (`__return_true`), returns the full `SIVI_OPTION_KEY` option verbatim, or a 404 `no_data` error if no build has ever run. Response header: `Cache-Control: public, max-age=3600`.


### **14.2 Legacy Redirect** {#h.ox3orsl8rnjm}

The old endpoint redirects to the canonical endpoint:

    text
    GET /wp-json/blomstra/v1/critical-infrastructure-index
    → 301 redirect to /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index


### **14.3 Response Shape** {#h.rb7y95kr56ie}

Top-Level Fields:

|                       |        |                                                |
| --------------------- | ------ | ---------------------------------------------- |
| Field                 | Type   | Description                                    |
| `version`             | string | SIVI methodology version                       |
| `last_updated`        | string | Build timestamp (UTC)                          |
| `total_countries`     | int    | Number of countries in `countries` object      |
| `excluded`            | int    | Number of countries excluded                   |
| `excluded_detail`     | object | Map of `iso3 => reason` for excluded countries |
| `weights`             | object | Pillar weights used for this build             |
| `methodology_url`     | string | URL to methodology page                        |
| `methodology_summary` | string | Brief methodology summary                      |
| `footnote`            | string | Extended methodology note                      |
| `countries`           | object | Map of `iso3 => country_data`                  |
| `_meta`               | object | Build metadata                                 |

Country Object (`countries[iso3]`):

|                                     |              |                                   |
| ----------------------------------- | ------------ | --------------------------------- |
| Field                               | Type         | Description                       |
| `iso3`                              | string       | 3-letter country code             |
| `name`                              | string       | Country name                      |
| `sivi_structural`                   | float        | Composite score (0-100)           |
| `coverage`                          | string       | `"full"` or `"partial"`           |
| `rank_display`                      | object       | Rank information (see below)      |
| `energy_dependency_percentile`      | float        | Energy pillar percentile          |
| `energy_dependency_raw`             | float        | Raw dependency value              |
| `supplier_concentration_percentile` | float        | HHI pillar percentile             |
| `supplier_concentration_raw`        | float        | Raw HHI value (0-10000)           |
| `maritime_vulnerability_percentile` | float        | Maritime vulnerability percentile |
| `maritime_connectivity_raw`         | float        | Raw LSCI value                    |
| `is_landlocked`                     | bool         | Whether country is landlocked     |
| `pillars_used`                      | int          | Number of pillars with data       |
| `pillars_missing`                   | array        | List of missing pillars           |
| `composite_dqi`                     | float\|null  | Data Quality Index                |
| `vintage_summary`                   | string\|null | Data years per pillar             |
| `sensitivity_interval`              | object\|null | Weight-perturbation interval      |
| `pillars`                           | object       | Per-pillar scores and weights     |

Rank Display Object:

    php
    [
        'is_definitive' => true,
        'best_estimate' => 45,
        'range_80_low' => 45,     // only for partial
        'range_80_high' => 45,    // only for partial
        'theoretical_low' => 45,  // only for partial
        'theoretical_high' => 45, // only for partial
        'string_format' => '#45',
    ]


### **14.4 Frontend Integration** {#h.mcfj8x5wzhdb}

\[D] The shortcode injects configuration for the frontend widget:

    html
    <div class="biw"
         data-biw-slug="sivi"
         data-biw-endpoint="/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index"
         data-biw-names-endpoint="/wp-json/blomstra/v1/country-names"
         data-biw-title="Sovereign Infrastructure Vulnerability Index"
         data-biw-subtitle="A country-level assessment of exposure, dependency, and systemic weakness"
         data-biw-eyebrow="Strategic Intelligence"
         data-biw-score-key="sivi_structural"
         data-biw-score-label="Vulnerability Score"
         data-biw-coverage-key="coverage"
         data-biw-band-thresholds="25,50,75"
         data-biw-band-labels="Low,Medium,High,Extreme"
         data-biw-pillars='[{"key":"energy_dependency_percentile",...}]'
         data-biw-methodology="..."
         data-biw-view="dashboard"
         data-biw-year-min="2004"
         data-biw-year-max="2026">
    </div>

\[N] Per Invariant #5 (config-driven frontend), the frontend engine reads all of the above from these attributes; no SIVI-specific field name is hardcoded in `index-frontend-engine.js` except as a documented fallback default.

***


## **15. Admin UI** {#h.ce02jansjac0}

### **15.1 Navigation** {#h.90he00fnhwec}

    text
    Blomstra Insights Tools (menu)
        └── SIVI Index (submenu)

Menu position: Submenu under `blomstra-insights-tools`


### **15.2 Dashboard Sections** {#h.5ln185q4115t}

|                            |                                                        |
| -------------------------- | ------------------------------------------------------ |
| Section                    | Content                                                |
| Pillar Status Cards        | Energy, HHI, Maritime – count, freshness status        |
| Coverage Breakdown         | Full/Partial/Excluded counts                           |
| Data Source Status         | Auto-refresh schedule, last run                        |
| Custom Composite Weights   | Sliders for pillar weights (must sum to 100)           |
| Pillar Data Layer          | Fetch/Flush buttons per pillar                         |
| Composite Build            | Build from cache, Flush All                            |
| Historical Backfill Range  | Configure start/end years                              |
| Historical Backfill Status | Table per year with status, countries, error           |
| Sensitivity Testing        | Preset buttons, custom JSON, scenario comparison table |
| Benchmark Correlation      | Upload external comparator JSON                        |
| Preview Tables             | 10 Most Vulnerable, 10 Least Vulnerable, Excluded      |


### **15.3 Action Buttons** {#h.3m76299hu6y4}

|                        |                                                           |
| ---------------------- | --------------------------------------------------------- |
| Button                 | Purpose                                                   |
| Fetch (Sync)           | Reads L1 cache, updates pillar data                       |
| Flush                  | Deletes pillar cache                                      |
| Build Index from Cache | Builds composite from existing pillar data                |
| Flush ALL Caches       | Deletes all pillar caches and composite (destructive)     |
| Build Scenario         | Builds custom-weight scenario (does not touch production) |
| Delete Scenario        | Removes scenario                                          |
| Backfill All Years     | Schedules historical backfill for range                   |
| Retry                  | Retries a specific backfill year                          |

***


## **16. Versioning** {#h.6x4mxm3fohau}

### **16.1 Methodology Version** {#h.c03k7movvktj}

\[D] SIVI methodology version is defined by:

    php
    define('SIVI_VERSION', '3.3.0');

Version policy:

- Major (4.0.0): Methodological change (e.g., new pillar, new weighting scheme)

- Minor (3.4.0): New indicator or significant methodology refinement

- Patch (3.3.1): Bug fix, no methodology change


### **16.2 Software Version** {#h.uv6eisvw6ldx}

\[D] SIVI software version is the same as the methodology version (for now):

    php
    define('SIVI_VERSION', '3.3.0');

Note: This should be separate from the methodology version in the future. A software change does not necessarily mean a methodology change.


### **16.3 Standard Version** {#h.4yk8i6k7n45}

\[D] SIVI declares conformance to the Blomstra Methodology Standard:

    php
    'standard_version' => 'BMS-1.1.0'

***


## **17. Open Questions** {#h.w0xmczd6hizx}

\[D] These are unresolved methodology/product decisions, not code defects — confirmed still open by inspecting the code (no policy for them exists anywhere in `sivi-backend.php` or the shared utilities beyond the current defaults):

|   |                                                                                                                                                                                             |                                                                           |
| - | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| # | Question                                                                                                                                                                                    | Notes                                                                     |
| 1 | Minimum coverage – Is `SIVI_MIN_PILLARS_REQUIRED = 2` (of 3) the scientifically right coverage floor, or should it be 3?                                                                    | Currently 2/3 pillars for SIVI                                            |
| 2 | Winsorization asymmetry – Should the 1% winsorization on maritime extend to energy/HHI, or is their distribution shape different enough to justify leaving them unwinsorized?               | —                                                                         |
| 3 | DQI influence – Should DQI ever influence scoring/ranking (weighting or exclusion), rather than remaining disclosure-only, as it is today?                                                  | Currently disclosure-only                                                 |
| 4 | Uncertainty representation – Should the bootstrap sensitivity interval (§11) be supplemented with a genuine data-uncertainty interval, since it currently only measures weight sensitivity? | —                                                                         |
| 5 | Reference population – What country set defines percentile distributions?                                                                                                                   | Currently all World Bank members via `blomstra_get_global_country_list()` |
| 6 | Historical comparability – How should methodological changes affect comparison between index editions?                                                                                      | —                                                                         |

These belong in a `research/` methodology track for explicit resolution, not silent adjustment in code — consistent with the Documentation Constitution's normative/descriptive separation.

***


## **End of SIVI Specification** {#h.791ybxouehu6}

Status: CANONICAL\
Next steps: Production of `OPERATIONS.md`, `FRONTEND.md`, and remaining supporting documents.
