# DATA.md – Blomstra Insights Reference Data Layer

Document version: 1.0.0\
Status: CANONICAL\
Applies to: `src/shared/global-reference-data.php`\
Last verified against commit: (to be filled)\
Source files: `global-reference-data.php` (v2.9.1, 5,167 lines, \~76 functions)\
Effective date: 2026-09-09

***


## Document Control

| Field                   | Value                                                                                 |
| :---------------------- | :------------------------------------------------------------------------------------ |
| Document                | `DATA.md` v1.0.0                                                                      |
| Verified against commit | (to be filled)                                                                        |
| Source files            | `global-reference-data.php` (v2.9.1) – L1 Reference Data Layer                        |
| Method                  | Code-first reconstruction (Stage 2). No prior documentation consulted while drafting. |
| Status                  | Complete for current code. Items in §14 are open questions, not implementation gaps.  |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## Table of Contents

1. Overview

2. API Credential Management

3. Simple Full-Refresh Sources

4. Comtrade → HHI: The Collection Engine

5. EIA → Energy: The Collection Engine

6. World Bank Indicators

7. IMF WEO Indicators

8. Historical Data Caching

9. Shared Snapshot & History Storage

10. Error Classification

11. Cron Schedule

12. Caching Summary

13. Admin UI

14. Open Questions

15. Corrections to Prior Documentation

***


## 1. Overview

\[D] The Reference Data Layer (L1) is the foundation of the Blomstra Insights platform. It acquires, caches, and maintains external reference data from public APIs. No index-specific logic exists in this layer — it only knows "fetch indicator X" or "fetch HHI for year Y."

\[N] L1 MUST NOT:

- Contain index-specific methodology (e.g., pillar aggregation)

- Render HTML

- Depend on L2 or L3

- Know about specific indices

\[D] L1 is implemented in `global-reference-data.php` (v2.9.1) and includes:

| Component                 | Purpose                                                            |
| :------------------------ | :----------------------------------------------------------------- |
| API credential management | `blomstra_get_api_credential()`, `blomstra_save_api_credentials()` |
| Country list              | `blomstra_get_global_country_list()`                               |
| Comtrade reporter map     | `blomstra_get_comtrade_reporter_map()`                             |
| Maritime (LSCI) data      | `blomstra_get_maritime_raw()`, `blomstra_get_maritime_value()`     |
| HHI (Comtrade) data       | State machine with pointer, staging, promotion                     |
| EIA energy data           | State machine with pointer, staging, promotion                     |
| World Bank indicators     | Batch fetching, pointer-sequenced                                  |
| IMF WEO indicators        | Simple full-refresh                                                |
| Historical data caching   | Table-backed, year-specific fetchers                               |
| Cron handlers             | Locked, self-rescheduling                                          |

\[D] Five external data sources, plus two supporting reference lookups:

| Source                             | Purpose                               | Fetch pattern                                    |
| :--------------------------------- | :------------------------------------ | :----------------------------------------------- |
| World Bank Country API             | Global country/ISO3 list              | Simple full-refresh, cached                      |
| UN Comtrade Reporters              | Reporter-code ↔ ISO3 map              | Simple full-refresh, cached                      |
| World Bank WDI — Maritime (LSCI)   | Maritime connectivity raw values      | Simple full-refresh w/ retry, cached             |
| UN Comtrade                        | Import trade data → HHI               | Chunked, stateful, quota-aware collection engine |
| EIA (US Energy Information Admin.) | Energy consumption/production by fuel | Chunked, stateful, quota-aware collection engine |
| World Bank WDI — 13 indicators     | Governance/macro indicators           | Per-indicator batch, pointer-sequenced           |
| IMF WEO (DataMapper)               | 6 macro indicators                    | Simple full-refresh, no chunking                 |

\[N] All long-running operations use resumable state machines with pointers. All failures are logged.

***


## 2. API Credential Management

### 2.1 Credential Sources

\[D] `blomstra_get_api_credential($source, $field)` reads from:

1. Database option `blomstra_api_credentials` (admin-configurable) — takes precedence

2. Constants (fallback) – `COMTRADE_PRIMARY_KEY` and `EIA_API_KEY`


### 2.2 Credential Storage

\[D] `blomstra_save_api_credentials($credentials)` stores sanitized credentials in `blomstra_api_credentials` option:

php

    [
        'comtrade' => ['subscription_key' => '...'],
        'eia' => ['api_key' => '...'],
    ]

\[D] Both key and value are sanitized (`sanitize_key`, `sanitize_text_field` + `trim`) before persisting.


### 2.3 Credential Retrieval

\[D] `blomstra_get_all_api_credentials()` returns merged credentials from options + constants:

| Source   | Credential         | Constant               |
| :------- | :----------------- | :--------------------- |
| Comtrade | `subscription_key` | `COMTRADE_PRIMARY_KEY` |
| EIA      | `api_key`          | `EIA_API_KEY`          |

\[N] World Bank and IMF endpoints require no credential. Missing credentials cause fetchers to fail gracefully with logged errors.

***


## 3. Simple Full-Refresh Sources

### 3.1 Country List

\[D] `blomstra_get_global_country_list($force = false)`:

| Property | Value                                                                        |
| :------- | :--------------------------------------------------------------------------- |
| Endpoint | `https://api.worldbank.org/v2/country?format=json&per_page=300&page={$page}` |
| Cache    | Transient `blomstra_global_country_list`, 24h                                |
| Return   | `["ISO3" => "Name"]`                                                         |

\[N] Cached only if the full page range was reached — a partial fetch is deliberately not cached, so a transient failure doesn't poison the list for a full day.

php

    $reached_end = ($total_pages !== null && $page > $total_pages);
    if (!empty($names) && $reached_end) {
        set_transient($cache_key, $names, DAY_IN_SECONDS);
    }

\[D] On any failure, falls back to the stale cache (if it exists) or returns an empty array.


### 3.2 Comtrade Reporter Map

\[D] `blomstra_get_comtrade_reporter_map($force = false)`:

| Property | Value                                                              |
| :------- | :----------------------------------------------------------------- |
| Endpoint | `https://comtradeapi.un.org/files/v1/app/reference/Reporters.json` |
| Cache    | Transient `blomstra_comtrade_reporters`, 1 week                    |
| Return   | `["ISO3" => reporterCode]`                                         |

\[N] The function:

- Skips entries where `isGroup === true`

- Skips expired entries (`entryExpiredDate` is set)

- Stores the first valid entry per ISO3

\[D] Every outcome (success, empty, bad shape, network error) is recorded to `blomstra_comtrade_reporters_debug` for admin diagnosis.


### 3.3 Maritime LSCI

\[D] `blomstra_get_maritime_raw($force = false, $attempt = 1)`:

| Property  | Value                                                                                                             |
| :-------- | :---------------------------------------------------------------------------------------------------------------- |
| Indicator | `IS.SHP.GCNW.XQ`                                                                                                  |
| Endpoint  | `https://api.worldbank.org/v2/country/all/indicator/IS.SHP.GCNW.XQ?format=json&per_page=20000&date={start}:{end}` |
| Cache     | Transient `blomstra_maritime_raw`, 1 week                                                                         |
| Return    | `["ISO3" => ["value" => float, "year" => int]]`                                                                   |

\[D] Keeps the most recent year per country if multiple years are returned. Up to 2 attempts with backoff (`3 × attempt` seconds) on network error or 5xx.

\[D] Falls back to stale cache on any failure path — this endpoint never returns a hard error to its caller, only degrades to whatever's cached.

\[D] `blomstra_get_maritime_value($iso3)` checks `blomstra_is_landlocked()`:

php

    if (function_exists('blomstra_is_landlocked') && blomstra_is_landlocked($iso3)) {
        return ['value' => 0.0, 'is_landlocked' => true, 'year' => null];
    }

\[N] This is a structural zero — the value is methodologically correct, not missing data.

***


## 4. Comtrade → HHI: The Collection Engine

\[N] This is the most complex acquisition path and the primary place the engineering invariants live.


### 4.1 Constants

| Constant                              | Value                            | Purpose                              |
| :------------------------------------ | :------------------------------- | :----------------------------------- |
| `BLOMSTRA_HHI_CHUNK_SIZE`             | 50                               | Countries per API call               |
| `BLOMSTRA_HHI_LOOKBACK`               | 4                                | Years to look back if data missing   |
| `BLOMSTRA_HHI_MAX_ATTEMPTS`           | 4                                | Max retry attempts per country       |
| `BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED`   | `__BLOMSTRA_QUOTA_EXHAUSTED__`   | Sentinel value for quota hit         |
| `BLOMSTRA_COMTRADE_PERMANENT_FAILURE` | `__BLOMSTRA_PERMANENT_FAILURE__` | Sentinel value for permanent failure |


### 4.2 HHI Formula

\[D] `blomstra_compute_hhi_from_batch_rows()` computes HHI from raw trade rows:

text

    HHI = Σ( (partner_import_value / world_total_import_value)² ) × 10000, clamped to [0, 10000]

- Uses Comtrade's `partnerCode = 0` row as the world-total denominator

- Excludes self-trade (`partnerCode == reporterCode`)

- Excludes non-positive partner values

- Skips a reporter entirely if no world-total row or no valid partner values were returned


### 4.3 State Machine (Pointer)

\[D] A persistent pointer (`blomstra_hhi_refresh_pointer` option):

php

    [
        'target_year'    => 2024,
        'pending_iso3s'  => ['ZWE', 'ZMB', 'UGA', ...],
        'attempts'       => ['ZWE' => 1, 'ZMB' => 0],
        'started_at'     => '2026-09-09 08:00:00',
    ]

\[D] A fresh pass starts (resets pending to all fetchable countries) when:

- `$force` is true

- The pointer is empty

- The pointer's `target_year` doesn't match the requested year

\[D] Countries are processed in chunks of `BLOMSTRA_HHI_CHUNK_SIZE = 50`. For each chunk, up to `BLOMSTRA_HHI_LOOKBACK = 4` years back from target are tried, stopping early once every country in the chunk has data.


### 4.4 Chunk Fetching

\[D] `blomstra_comtrade_fetch_partner_imports_batch($reporter_codes, $year, $attempt = 1)`:

Endpoint:

text

    https://comtradeapi.un.org/data/v1/get/C/A/HS
        ?reporterCode={codes}
        &period={year}
        &cmdCode=TOTAL
        &flowCode=M
        &motCode=0
        &partner2Code=0
        &customsCode=C00
        &subscription-key={key}
        &page={page}
        &limit=500

Smart Pagination:

- Fetches page 1

- Tracks which reporters have `partnerCode === 0` (world total)

- If not all reporters have world total, fetches more pages

- Stops early if all reporters have world total

Retry Logic:

- Up to 3 attempts on network/5xx errors

- HTTP 429 with parseable "retry in N seconds" (N ≤ 90, ≤ 2 attempts) waits and retries

- Otherwise, the whole chunk is marked `QUOTA_FAILURE`

\[N] On `QUOTA_FAILURE`, the entire remaining chunk loop stops immediately (does not continue hammering further chunks against an exhausted key).

\[D] A 403 is `PERMANENT_FAILURE` (bad/expired key) and also stops that chunk's fetch loop, but doesn't halt the whole run.


### 4.5 Per-Country State Machine

\[D] Each country transitions through states:

| State               | Meaning                                                 | Action                                     |
| :------------------ | :------------------------------------------------------ | :----------------------------------------- |
| `SUCCESS_WITH_DATA` | HHI computed successfully                               | Write to results, remove from pending      |
| `NO_DATA`           | Empty responses through whole lookback                  | Write null result, remove from pending     |
| `NO_REPORTER`       | ISO3 not in Comtrade reporter map                       | Write null result, skip immediately        |
| `QUOTA_FAILURE`     | Rate limit hit during chunk                             | Skip all remaining countries this run      |
| `PERMANENT_FAILURE` | HTTP 403 or auth error                                  | Write null result, remove from pending     |
| `RETRYABLE_FAILURE` | Network/HTTP 5xx error, retries remain                  | Keep in pending, increment attempt counter |
| `UNRESOLVED`        | Max retries exhausted (`BLOMSTRA_HHI_MAX_ATTEMPTS = 4`) | Write null result, remove from pending     |
| `PENDING`           | Still in queue                                          | Keep in pointer for next run               |

\[D] Attempt counters persist in the pointer between cron runs, not just within one run.


### 4.6 Staging → Promotion (Invariants)

\[N] Production data is never overwritten directly:

1. Results are written to staging: `blomstra_comtrade_hhi_data_staging`

2. After all chunks complete, staging is validated

3. If validation passes, staging is atomically promoted to production: `blomstra_comtrade_hhi_data`

4. If validation fails, staging is discarded, and production data is preserved

\[N] Universe-aware promotion gate (REF-BUG-2 fix): staging is promoted only if `staged_count ≥ max(1, total_fetchable_countries × 0.8)` — using the count of countries with a Comtrade reporter code, not the global country list, as the denominator.

\[N] Partial-progress promotion on quota exhaustion (REF-BUG-1 fix): if quota runs out mid-run, whatever has already been staged this run is promoted to production before the function returns — the old behavior discarded the staging option entirely, permanently losing every country that had _already succeeded_ in that same run.

\[D] All chunk-level progress (results, pointer, staging option) is persisted after every chunk, not just at the end — a mid-run crash or timeout loses at most one chunk's worth of work, not the whole run.

***


## 5. EIA → Energy: The Collection Engine

\[N] Structurally parallel to HHI, with one additional dimension: fuel × activity (5 fuels × {consumption, production} = 10 independent per-fuel-per-activity fetch cycles).


### 5.1 Constants

| Constant                        | Value     | Purpose                |
| :------------------------------ | :-------- | :--------------------- |
| `BLOMSTRA_EIA_FUEL_PRODUCT_IDS` | 4411-4418 | 5 fuel product IDs     |
| `BLOMSTRA_EIA_ACTIVITY_PROD`    | 1         | Production activity    |
| `BLOMSTRA_EIA_ACTIVITY_CONS`    | 2         | Consumption activity   |
| `BLOMSTRA_EIA_CHUNK_SIZE`       | 50        | Countries per API call |
| `BLOMSTRA_EIA_MAX_ATTEMPTS`     | 3         | Max retry attempts     |
| `BLOMSTRA_EIA_UNIT`             | QBTU      | Unit (Quadrillion BTU) |

Fuel IDs:

| ID   | Fuel                        |
| :--- | :-------------------------- |
| 4411 | Coal                        |
| 4413 | Natural gas                 |
| 4415 | Petroleum and other liquids |
| 4417 | Nuclear                     |
| 4418 | Renewables and other        |


### 5.2 State Machine (Pointer)

\[D] A persistent pointer (`blomstra_eia_refresh_pointer` option):

php

    [
        'fuel_index'   => 2,        // 0=Coal, 1=Gas, 2=Petroleum, 3=Nuclear, 4=Renewables
        'activity'     => 'consumption',  // 'consumption' or 'production'
        'started_at'   => '2026-09-09 08:00:00',
        'failed_fuels' => [
            '4415' => ['permanent' => false, 'retries' => 1],
        ],
    ]

\[D] Sequences one fuel-activity pair per cron tick after that pair's chunks are fully processed.


### 5.3 Fuel/Activity Processing

\[D] `blomstra_process_eia_activity($fuel_index, $activity, $iso3_list, &$failed_fuels)`:

Flow:

1. Check if fuel is in `failed_fuels` – if permanent failure, skip and advance pointer

2. Chunk countries into groups of `BLOMSTRA_EIA_CHUNK_SIZE` (50)

3. For each chunk, fetch via `blomstra_eia_fetch_activity_batch()`

4. Track chunk outcomes: ok, empty, retryable, permanent, quota

5. Write to staging: `blomstra_eia_raw_data_staging`

6. Determine if pointer should advance

\[D] `blomstra_eia_fetch_activity_batch($country_codes, $activity_id, $product_id, $attempt = 1)`:

Endpoint:

text

    https://api.eia.gov/v2/international/data/
        ?api_key={key}
        &facets[activityId][]={activity_id}
        &facets[productId][]={product_id}
        &facets[unit][]=QBTU
        &frequency=annual
        &data[]=value
        &facets[countryRegionId][]={country_codes}
        &length=5000

\[D] HTTP 429 → `quota_exhausted` (does not retry within the call); 403/401 → `permanent_failure`; 404 → `permanent_failure` (treated as "the endpoint changed").

\[N] Quota-stop-immediately (REF-BUG-5 fix): on the first `quota_exhausted` chunk, the loop breaks immediately rather than continuing to call an already-rate-limited key for the remaining chunks.


### 5.4 Promotion Gate

\[N] Universe-aware promotion gate (REF-BUG-2 fix): promotion of a fuel's staged data to production requires `chunk_success_ratio ≥ 0.8` where:

text

    chunk_success_ratio = (chunks_attempted − failed − retryable − permanent − quota) / chunks_attempted

\[N] This uses that fuel's own chunk outcomes, never a comparison against the global \~200-country list. The prior implementation compared a single fuel's country coverage against 80% of _all_ countries; for a fuel with genuinely narrow real-world coverage (e.g., Nuclear, realistically \~30 countries), that threshold was mathematically unsatisfiable.

\[N] `advance_pointer` requires `chunks_attempted ≥ total_chunks` for that fuel — a quota-truncated run stays on the same fuel next cron tick instead of prematurely advancing and leaving the untried chunks permanently unfetched.


### 5.5 Failed Fuel Handling

\[D] A fuel gets marked permanently failed after 3 retry _cycles_ (not attempts — cycles, i.e. cron ticks) via `failed_fuels`, and is then skipped on future ticks.

***


## 6. World Bank Indicators

### 6.1 Indicator List

\[D] Defined in `BLOMSTRA_WB_INDICATORS`:

| Indicator           | Source     | Name                  |
| :------------------ | :--------- | :-------------------- |
| `GOV_WGI_RL.SC`     | 3 (WGI)    | Rule of Law           |
| `GOV_WGI_CC.SC`     | 3 (WGI)    | Control of Corruption |
| `GOV_WGI_PV.SC`     | 3 (WGI)    | Political Stability   |
| `NY.GNP.MKTP.KD.ZG` | null (WDI) | GNI growth            |
| `NY.GNP.PCAP.KD.ZG` | null (WDI) | GNI per capita growth |
| `NY.GDP.MKTP.KD.ZG` | null (WDI) | GDP growth            |
| `FP.CPI.TOTL.ZG`    | null (WDI) | Inflation             |
| `SL.UEM.TOTL.ZS`    | null (WDI) | Unemployment          |
| `FI.RES.TOTL.MO`    | null (WDI) | Reserves (months)     |
| `DT.DOD.DECT.GN.ZS` | null (WDI) | External debt         |
| `BN.CAB.XOKA.GD.ZS` | null (WDI) | Current account       |
| `GC.DOD.TOTL.GD.ZS` | null (WDI) | Government debt       |
| `GC.NLD.TOTL.GD.ZS` | null (WDI) | Government balance    |


### 6.2 Fetching

\[D] `blomstra_fetch_wb_indicator_batch($code, $source = null, $force = false)`:

WGI Path (source=3):

- Fetches with `source=3` parameter

- No `mrnev` fallback

WDI Path (source=null):

- First tries `mrnev=1` (most recent non-empty value)

- If empty, falls back to 10-year date range


### 6.3 Caching

\[D] Cached in transient `blomstra_wb_indicator_{md5}` with TTL = 1 week. Staging pattern: writes to `_tmp` transient, then promotes to production.


### 6.4 WB Pointer

\[D] `blomstra_wb_refresh_pointer` stores the next index to process:

php

    ['next_index' => 7, 'started_at' => '2026-09-09 08:00:00']


### 6.5 Cron Handler

\[D] `blomstra_cron_handle_wb_indicators()`:

1. Acquires lock `blomstra_wb_refresh_in_progress` (TTL = 30 min)

2. Processes 3 indicators per run (`$batch_size = 3`)

3. Updates pointer after each indicator

4. Schedules next run if not complete

***


## 7. IMF WEO Indicators

### 7.1 Indicator List

\[D] Defined in `BLOMSTRA_IMF_INDICATORS`:

| Code          | Name                               |
| :------------ | :--------------------------------- |
| `NGDP_RPCH`   | GDP growth (real)                  |
| `PCPIPCH`     | Inflation (consumer prices)        |
| `BCA_NGDPD`   | Current account balance (% of GDP) |
| `GGXWDG_NGDP` | Government debt (% of GDP)         |
| `GGXCNL_NGDP` | Government balance (% of GDP)      |
| `LUR`         | Unemployment rate                  |


### 7.2 Fetching

\[D] `blomstra_fetch_imf_generic($code, $force, $forecast_horizon = null, $target_year = null)`:

Endpoint:

text

    https://www.imf.org/external/datamapper/api/v1/{code}

Data Selection:

- Historical (no horizon): Latest actual year (≤ current\_year) if available; otherwise, earliest forecast year

- Forecast (horizon provided): Target year = current\_year + horizon


### 7.3 ISO3 Mapping

\[D] `BLOMSTRA_IMF_TO_ISO3_MAP` handles non-ISO3 country codes:

| IMF Code | ISO3            |
| :------- | :-------------- |
| KSV      | XKX (Kosovo)    |
| WBG      | PSE (West Bank) |
| ZAR      | COD (Congo)     |
| ROM      | ROU (Romania)   |


### 7.4 Vintage

\[D] `blomstra_get_weo_vintage()` returns the WEO vintage string:

| Month Range       | Vintage                                                               |
| :---------------- | :-------------------------------------------------------------------- |
| April – September | `April {year}`                                                        |
| October – March   | `October {year-1}` (if month < 4) or `October {year}` (if month ≥ 10) |


### 7.5 Cron Handler

\[D] `blomstra_cron_handle_imf()`:

1. Acquires lock `blomstra_imf_weekly_in_progress` (TTL = 10 min)

2. Fetches all 6 indicators

3. Updates `blomstra_cron_status` with result

\[D] No chunking, no pointer — the entire IMF refresh is small enough to run start-to-finish in a single cron tick (300s time limit).

***


## 8. Historical Data Caching

### 8.1 Overview

\[D] The platform maintains a historical data cache for backfills in the `wp_blomstra_historical_data` table.


### 8.2 Schema

sql

    CREATE TABLE wp_blomstra_historical_data (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(20) NOT NULL,
        indicator VARCHAR(50) NOT NULL,
        fuel VARCHAR(20) DEFAULT NULL,
        iso3 CHAR(3) NOT NULL,
        year SMALLINT UNSIGNED NOT NULL,
        value DECIMAL(12,4) DEFAULT NULL,
        meta JSON DEFAULT NULL,
        fetched_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_source_indicator_fuel_iso3_year (source, indicator, fuel, iso3, year)
    );


### 8.3 Cache Wrapper

\[D] `blomstra_get_historical_data($source, $indicator, $fuel, $iso3_list, $year, $fetch_callback)`:

1. Checks the cache table for existing data

2. For missing countries, calls the fetch callback

3. Stores new data in the cache table

\[N] Cache-if-settled rule: a year is only cached if `$year ≤ current_year − 2`. Recent years are never persisted to this cache, since they may still be revised upstream — re-fetched fresh every time until they age past the 2-year settling window.

\[N] Missing values are stored as `null`, never `0`.


### 8.4 Year-Specific Fetchers

\[D] Three year-specific fetchers are used for backfills:

    blomstra_fetch_eia_for_year($year, $iso3_list = null)

\[N] For each fuel and activity:

- Tries target year, then lookback up to 5 years

- Retries only countries still missing at each earlier year (REF-BUG-3 fix)

- Records the real source year per country (REF-BUG-6 fix)

<!---->

    blomstra_fetch_hhi_for_year($year, $iso3_list = null)

\[N] For each country:

- Tries target year, then lookback up to `BLOMSTRA_HHI_LOOKBACK` (4) years

- Retries only countries still missing at each earlier year (REF-BUG-6 fix)

- Records the real source year per country

\[D] Previously had zero fallback at all for the historical path (unlike the live builder).

    blomstra_fetch_maritime_for_year($year, $iso3_list = null)

\[D] For each country:

- Tries target year, then lookback up to 10 years

- Stops when data is found or reaches 2004 (minimum year)

- Records the real source year per country


### 8.5 Cache Job Status

\[D] The `wp_blomstra_cache_jobs` table tracks per-year, per-source cache jobs:

php

    [
        'source' => 'eia',
        'year' => 2024,
        'status' => 'success'|'failed'|'pending'|'running'|'skipped',
        'countries_cached' => 182,
        'error_message' => null,
        'attempts' => 1,
    ]

\[D] `blomstra_cache_historical_job_callback($source, $year)` runs each job in the background via cron.

\[D] `blomstra_get_historical_sources()` is the registry of which sources are backfill-capable and since which year:

| Source   | Available Since | Implemented |
| :------- | :-------------- | :---------- |
| EIA      | 2000            | ✅           |
| Comtrade | 2000            | ✅           |
| Maritime | 2004            | ✅           |
| IMF      | 1990            | ✅           |
| WB       | 1990            | ✅           |

***


## 9. Shared Snapshot & History Storage

### 9.1 Schema

\[D] Table `wp_blomstra_index_history` (index-agnostic):

sql

    CREATE TABLE wp_blomstra_index_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        index_slug VARCHAR(40) NOT NULL,
        iso3 VARCHAR(3) NOT NULL,
        snapshot_period VARCHAR(7) NOT NULL,  -- YYYY-MM
        composite_score DECIMAL(6,2) DEFAULT NULL,
        rank_value SMALLINT UNSIGNED DEFAULT NULL,
        coverage_type VARCHAR(10) DEFAULT NULL,
        pillars_json LONGTEXT DEFAULT NULL,
        recorded_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_slug_iso_period (index_slug, iso3, snapshot_period)
    );

\[D] Unique key: `(index_slug, iso3, snapshot_period)` — one row per country per index per period.


### 9.2 Snapshot Save

\[D] `blomstra_index_snapshot_save($index_slug, $countries, $custom_period)` — an `INSERT ... ON DUPLICATE KEY UPDATE`, so re-saving the same index/country/period overwrites rather than duplicating.

\[N] Rows are always built via the shared `blomstra_build_flat_snapshot_row()` (documented in `SIVI.md` §10.2) before being passed here.


### 9.3 Snapshot Get

\[D] `blomstra_index_snapshot_get_history($index_slug, $iso3 = null)` powers the public REST endpoint `GET /wp-json/blomstra/v1/index-history/{slug}?iso3={iso3}`.

\[D] Returns keyed by ISO3 at the top level, even when `iso3` is supplied (single-key object, not a flat array).

***


## 10. Error Classification

\[N] All errors are classified using this taxonomy:

| Code        | Name                      | Retryable            | Action                                      |
| :---------- | :------------------------ | :------------------- | :------------------------------------------ |
| `200`       | Success                   | —                    | Process data                                |
| `429`       | Quota / Rate limit        | Yes (with backoff)   | Parse "Try again in N seconds", wait N+2s   |
| `403`       | Authorization error       | No                   | Permanent failure — invalid/expired API key |
| `401`       | Authentication error      | No                   | Permanent failure — invalid API key         |
| `404`       | Not found                 | No                   | Permanent failure — endpoint changed        |
| `5xx`       | Server error              | Yes (max 3 attempts) | Exponential backoff: 2s, 4s, 8s             |
| `NETWORK`   | Network error (WP\_Error) | Yes (max 3 attempts) | Exponential backoff                         |
| `EMPTY`     | HTTP 200, zero rows       | No                   | Classify as `NO_DATA`                       |
| `OVERSIZED` | Response > 15MB           | No                   | Reject, log, retry with smaller chunk       |

\[N] Permanent failures are never retried. They are logged, the country/fuel is marked, and processing continues.

***


## 11. Cron Schedule

\[D] One custom interval registered (`weekly` = `WEEK_IN_SECONDS`, if WordPress doesn't already define one). Five weekly jobs, staggered one per day, all at 02:00 UTC:

| Day       | Event                                      | Handler                              |
| :-------- | :----------------------------------------- | :----------------------------------- |
| Monday    | `blomstra_cron_maritime_weekly_event`      | `blomstra_cron_handle_maritime`      |
| Tuesday   | `blomstra_cron_eia_weekly_event`           | `blomstra_cron_handle_eia`           |
| Wednesday | `blomstra_cron_hhi_weekly_event`           | `blomstra_cron_handle_hhi`           |
| Thursday  | `blomstra_cron_wb_indicators_weekly_event` | `blomstra_cron_handle_wb_indicators` |
| Friday    | `blomstra_cron_imf_weekly_event`           | `blomstra_cron_handle_imf`           |

\[D] EIA and HHI cron handlers are re-entrancy-guarded by a 30-minute transient lock; a tick that finds the lock held logs "already running" and exits rather than queuing. Both self-reschedule a single follow-up event 60 seconds later (`wp_schedule_single_event`) when there's more chunked work left to do.

\[D] SIVI (and, when live, other indices) listen for these five completions to trigger a debounced index rebuild — see `SIVI.md` §12.

***


## 12. Caching Summary

| Data                                | Store                                 | TTL / lifetime                             |
| :---------------------------------- | :------------------------------------ | :----------------------------------------- |
| Country list                        | transient                             | 24h (only if fully paginated)              |
| Comtrade reporter map               | transient                             | 1 week                                     |
| Maritime raw                        | transient                             | 1 week                                     |
| Comtrade HHI (production)           | option                                | until next successful refresh              |
| Comtrade HHI (staging)              | option                                | deleted at end of each run                 |
| EIA raw (production)                | option                                | until next successful per-fuel promotion   |
| EIA raw (staging)                   | option                                | deleted at end of each fuel-activity cycle |
| WB indicator (per code)             | transient                             | 1 week                                     |
| IMF indicator (per code)            | transient                             | 1 week                                     |
| Historical point values             | DB table (`blomstra_historical_data`) | permanent, only for years ≤ current − 2    |
| Index snapshots                     | DB table (`blomstra_index_history`)   | permanent                                  |
| Backfill job status                 | DB table (`blomstra_cache_jobs`)      | permanent                                  |
| Call/fetch logs (Comtrade, EIA, WB) | option, ring-buffered                 | last 50–200 entries                        |

***


## 13. Admin UI

\[D] The Reference Data admin page (`blomstra-insights-tools`) provides:

| Section                     | Content                                                                                                     |
| :-------------------------- | :---------------------------------------------------------------------------------------------------------- |
| System Health Overview      | Health percentage, per-pillar health icons (✅ success, ⚠️ partial, ❌ error, ⏳ never\_run, ❓ unknown)        |
| API Credentials             | Configure Comtrade and EIA API keys; test connection buttons                                                |
| Data Health Dashboard       | Table per pillar with status, coverage, state breakdown, last successful, next scheduled, action suggestion |
| Data Layers & Cache Control | Per-dataset refresh/flush controls                                                                          |
| Historical Cache Status     | Summary, per-year per-source status table with click-to-retry, bulk cache manager                           |
| API Diagnostic Sandbox      | Single-target testing for Comtrade, EIA, Maritime, WB, IMF                                                  |
| Emergency Controls          | Emergency Flush All Caches                                                                                  |

***


## 14. Open Questions

\[D] These are operational/architectural decisions that require explicit resolution:

| #  | Question                                                                                           | Notes                                                           |
| :- | :------------------------------------------------------------------------------------------------- | :-------------------------------------------------------------- |
| 1  | Historical cache TTL – Should historical data be cached indefinitely or have a TTL?                | Currently caches if year ≤ current\_year - 2 with no expiration |
| 2  | Comtrade lookback – Is 4 years (`BLOMSTRA_HHI_LOOKBACK`) sufficient, or should it be configurable? | Currently 4 years                                               |
| 3  | EIA lookback – Is 5 years sufficient for energy fallback?                                          | Currently 5 years                                               |
| 4  | Maritime lookback – Is 10 years (capped at 2004) sufficient?                                       | Currently 10 years                                              |
| 5  | Coverage threshold – Is 80% the right threshold for staging→promotion?                             | Currently 80% for HHI, 0.8 success ratio for EIA                |

***


## 15. Corrections to Prior Documentation

\[D] The following corrections are made from prior documentation:

| Prior Claim                                             | Correction                                                                 | Source                                                           |
| :------------------------------------------------------ | :------------------------------------------------------------------------- | :--------------------------------------------------------------- |
| HHI staging was discarded on quota exhaustion           | Staging is promoted before returning (REF-BUG-1 fix)                       | `blomstra_refresh_comtrade_hhi_data()`                           |
| EIA promotion used global country count                 | EIA uses chunk-level success ratio (REF-BUG-2 fix)                         | `blomstra_process_eia_activity()`                                |
| EIA continued hitting API after quota                   | EIA stops chunk loop on first quota (REF-BUG-5 fix)                        | `blomstra_process_eia_activity()`                                |
| Historical EIA/HHI fetchers had no per-country fallback | Both retry only missing countries at each earlier year (REF-BUG-3/6 fixes) | `blomstra_fetch_eia_for_year()`, `blomstra_fetch_hhi_for_year()` |
| Historical fetchers recorded requested year             | They record the real source year (REF-BUG-6 fix)                           | `blomstra_fetch_eia_for_year()`, `blomstra_fetch_hhi_for_year()` |
| `$force` parameter didn't work on HHI                   | `$force` now actually forces a fresh pass (REF-BUG-7 fix)                  | `blomstra_refresh_comtrade_hhi_data()`                           |

***


## End of Data Specification

Status: CANONICAL
