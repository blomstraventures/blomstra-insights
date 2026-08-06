# API Reference — `global-reference-data.php`

> **File:** `src/reference-data/global-reference-data.php`  
> **Version:** 2.3  
> **Purpose:** Centralised reference data layer shared across all Blomstra indices (CII, future indices).  
> **Load Order:** Must run **before** any downstream index tool.  

---

## Table of Contents

1. [Constants](#constants)
2. [Landlocked Utilities](#landlocked-utilities)
3. [World Bank Country List](#world-bank-country-list)
4. [Comtrade Reporter Map](#comtrade-reporter-map)
5. [Maritime LSCI (World Bank)](#maritime-lsci-world-bank)
6. [HHI — Comtrade Collection Engine](#hhi--comtrade-collection-engine)
7. [EIA — Raw Energy Data](#eia--raw-energy-data)
8. [System & API Health](#system--api-health)
9. [Cron Scheduling & Health](#cron-scheduling--health)
10. [Admin Actions (PRG Pattern)](#admin-actions-prg-pattern)
11. [Admin UI Render](#admin-ui-render)
12. [Snapshot History (Database)](#snapshot-history-database)
13. [REST Endpoints](#rest-endpoints)
14. [Data Flow & Dependencies](#data-flow--dependencies)

---

## Constants

| Constant | Value | Purpose |
|---|---|---|
| `BLOMSTRA_LANDLOCKED_ISO3` | Array of 44 ISO3 codes | UN-OHRLLS landlocked country list. Used to assign structural-zero maritime scores. |
| `BLOMSTRA_COMTRADE_REPORTER_URL` | `https://comtradeapi.un.org/files/v1/app/reference/Reporters.json` | Source for ISO3 → numeric reporter code mapping. |
| `BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL` | `WEEK_IN_SECONDS` | Transient TTL for reporter map cache. |
| `BLOMSTRA_MARITIME_INDICATOR_CODE` | `IS.SHP.GCNW.XQ` | World Bank Liner Shipping Connectivity Index indicator code. |
| `BLOMSTRA_MARITIME_CACHE_TTL` | `WEEK_IN_SECONDS` | Transient TTL for maritime raw data cache. |
| `BLOMSTRA_COMTRADE_BASE_URL` | `https://comtradeapi.un.org/data/v1/get/C/A/HS` | Comtrade bulk data API base URL. |
| `BLOMSTRA_HHI_CHUNK_SIZE` | `50` | Max reporters per batch request. |
| `BLOMSTRA_HHI_LOOKBACK` | `4` | Years to look back if current-year data missing. |
| `BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED` | `'__BLOMSTRA_QUOTA_EXHAUSTED__'` | Sentinel value returned when quota/rate-limit hit. |
| `BLOMSTRA_EIA_BASE_URL` | `https://api.eia.gov/v2/international/data/` | EIA international energy data API base. |
| `BLOMSTRA_EIA_FUEL_PRODUCT_IDS` | `['4411'=>'Coal', '4413'=>'Natural gas', ...]` | EIA product ID → fuel name mapping (5 fuels). |
| `BLOMSTRA_EIA_ACTIVITY_PROD` | `'1'` | EIA activity ID for production. |
| `BLOMSTRA_EIA_ACTIVITY_CONS` | `'2'` | EIA activity ID for consumption. |
| `BLOMSTRA_EIA_UNIT` | `'QBTU'` | Quadrillion BTU — standard unit for aggregation. |
| `BLOMSTRA_EIA_CHUNK_SIZE` | `25` | Max countries per EIA batch request. |
| `BLOMSTRA_HISTORY_DB_VERSION` | `'1.0'` | DB schema version for snapshot history table. |

---

## Landlocked Utilities

### `blomstra_is_landlocked( $iso3 )`

Checks if a country is landlocked using the UN-OHRLLS list.

| Param | Type | Description |
|---|---|---|
| `$iso3` | `string` | 3-letter ISO country code. |

**Returns:** `bool`

**Used by:** `blomstra_get_maritime_value()` — returns structural zero for landlocked states.

---

## World Bank Country List

### `blomstra_get_global_country_list( $force = false )`

Fetches the full country list from the World Bank API, paginated (`per_page=300`). Filters out entries with `region.id === 'NA'` (non-country aggregates like "World", "High income", etc.).

| Param | Type | Default | Description |
|---|---|---|---|
| `$force` | `bool` | `false` | If `true`, deletes transient cache before fetching. |

**Returns:** `array` — `['AFG' => 'Afghanistan', ...]` keyed by ISO3.

**Caching:** WordPress transient (`blomstra_global_country_list`), TTL = `DAY_IN_SECONDS`.

**Error handling:** Returns partial or empty array on `WP_Error` or malformed JSON. Does not throw.

---

## Comtrade Reporter Map

### `blomstra_get_comtrade_reporter_map( $force = false )`

Fetches the UN Comtrade reporter reference JSON and builds an ISO3 → numeric reporter code map. Skips group entries (`isGroup`). Prefers non-expired entries when duplicates exist.

| Param | Type | Default | Description |
|---|---|---|---|
| `$force` | `bool` | `false` | If `true`, deletes transient cache before fetching. |

**Returns:** `array` — `['USA' => 842, ...]`

**Caching:** WordPress transient (`blomstra_comtrade_reporters`), TTL = `WEEK_IN_SECONDS`.

**Debug:** Writes fetch diagnostics to option `blomstra_comtrade_reporters_debug`.

---

## Maritime LSCI (World Bank)

### `blomstra_get_maritime_raw( $force = false, $attempt = 1 )`

Fetches 20 years of LSCI data from the World Bank API for all countries. Returns the **most recent non-null value per country**.

| Param | Type | Default | Description |
|---|---|---|---|
| `$force` | `bool` | `false` | Bypass cache. |
| `$attempt` | `int` | `1` | Internal retry counter (max 2 attempts on `WP_Error`). |

**Returns:** `array` — `['USA' => ['value' => 78.4, 'year' => 2023], ...]`

**Caching:** Transient (`blomstra_maritime_raw`), TTL = `WEEK_IN_SECONDS`.

**Error handling:** Auto-retries once on network error with 3s sleep. Logs to `blomstra_maritime_fetch_debug`.

---

### `blomstra_get_maritime_value( $iso3 )`

Safe accessor for maritime data. Returns structural zero for landlocked countries regardless of cache state.

| Param | Type | Description |
|---|---|---|
| `$iso3` | `string` | ISO3 code. |

**Returns:** `array`

```php
[
    'value'         => float|null,
    'is_landlocked' => bool,
    'year'          => int|null
]
```

---

## HHI — Comtrade Collection Engine

### `blomstra_log_comtrade_call( $reporter_code, $year, $outcome, $detail )`

Appends a single call entry to the Comtrade call log. Keeps last 50 entries only (FIFO trim).

**Storage:** Option `blomstra_comtrade_call_log`.

---

### `blomstra_comtrade_fetch_partner_imports_batch( $reporter_codes, $year, $attempt = 1 )`

**The core HHI fetcher.** Queries Comtrade for import flows (`flowCode=M`, `cmdCode=TOTAL`) for a batch of reporter codes. Uses **smart pagination**: requests page 2+ only for reporters still missing `partnerCode=0` (world total) after page 1.

| Param | Type | Description |
|---|---|---|
| `$reporter_codes` | `int[]` | Array of numeric reporter codes (max 50). |
| `$year` | `int` | Target year (YYYY). |
| `$attempt` | `int` | Retry counter (max 3 attempts). |

**Returns:**
- `array` — Merged trade rows on success.
- `null` — Fatal network/API error after retries.
- `BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED` — Rate limit / quota hit (HTTP 429/403).

**Retry logic:**
- `WP_Error` → retry with exponential backoff (`3s × attempt`)
- HTTP 429/403 → parses "try again in N seconds" from body; waits and retries once if ≤90s
- HTTP 5xx → retry up to 3 times

**Safety limits:**
- Response body capped at 15 MB (aborts if exceeded)
- Max 20 pages per batch

---

### `blomstra_compute_hhi_from_batch_rows( $rows, $reporter_codes, $year )`

Computes HHI (Herfindahl-Hirschman Index) from raw Comtrade rows. Formula: `Σ(share²) × 10000`.

| Param | Type | Description |
|---|---|---|
| `$rows` | `array` | Raw Comtrade `data` array. |
| `$reporter_codes` | `int[]` | Reporters to compute for. |
| `$year` | `int` | Year label for output. |

**Logic:**
- `partnerCode=0` = world total (used as denominator)
- Positive partner values (excluding self-trade) = summands
- Skips reporters with missing world total or no partner values

**Returns:** `array` — `[842 => ['value' => 2450.50, 'year' => 2023, 'source' => 'Comtrade'], ...]`

---

### `blomstra_refresh_comtrade_hhi_data( $year = null, $iso3_list = null, $force = false )`

**Full HHI refresh orchestrator.** Iterates all countries in chunks of 50, with 4-year lookback, checkpointing, and quota handling.

| Param | Type | Default | Description |
|---|---|---|---|
| `$year` | `int\|null` | `current_year - 1` | Target year. |
| `$iso3_list` | `string[]\|null` | All countries | Subset filter. |
| `$force` | `bool` | `false` | Wipes existing data before run. |

**Execution flow:**
1. Maps ISO3 → reporter codes (skips unmapped countries)
2. For each year in lookback window (`$year` → `$year-3`):
   - Chunks pending countries into groups of 50
   - Fetches batch, computes HHI
   - Checkpoint: merges results into `blomstra_comtrade_hhi_data` option after every chunk
   - 2s sleep between chunks (rate limit politeness)
3. If quota exhausted: marks remaining as `skipped — quota exhausted this run`
4. If no data after full lookback: marks as `no data in lookback window`

**Returns:** `array` — Full ISO3 → HHI result map.

**Side effects:**
- Updates `blomstra_hhi_refresh_summary` (run metadata)
- Updates `blomstra_comtrade_call_log`
- Persists to `blomstra_comtrade_hhi_data` option

---

### `blomstra_get_comtrade_hhi_data()`

Simple accessor. Returns the full cached HHI dataset.

**Returns:** `array` — `['USA' => ['value'=>..., 'scale'=>'0-10000', ...], ...]`

---

### `blomstra_get_country_hhi_value( $iso3 )`

Single-country HHI lookup.

**Returns:** `array|null`

---

## EIA — Raw Energy Data

### `blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, $outcome, $detail )`

Same pattern as Comtrade logger, but for EIA. Keeps last 50 entries.

**Storage:** Option `blomstra_eia_call_log`.

---

### `blomstra_eia_fetch_activity_batch( $country_codes, $activity_id, $product_id, $attempt = 1 )`

Fetches one activity (production or consumption) for one fuel product across a batch of countries.

| Param | Type | Description |
|---|---|---|
| `$country_codes` | `string[]` | ISO3 codes (max 25). |
| `$activity_id` | `string` | `BLOMSTRA_EIA_ACTIVITY_PROD` or `_CONS`. |
| `$product_id` | `string` | EIA product ID (e.g. `'4415'`). |
| `$attempt` | `int` | Retry counter. |

**Returns:** `array`

```php
[
    'status' => 'ok'|'failed',
    'rows'   => [...],      // raw EIA response rows
    'error'  => null|string
]
```

**Retry logic:** Retries on `WP_Error`, HTTP 429, or HTTP 5xx (max 3 attempts, exponential backoff).

---

### `blomstra_eia_pick_latest_per_country( $rows )`

Deduplicates EIA rows to the latest `period` per `countryRegionId`.

**Returns:** `array` — `['USA' => ['value'=>123.4, 'period'=>'2023'], ...]`

---

### `blomstra_refresh_eia_raw_data( $iso3_list = null, $force = false )`

**Full EIA refresh orchestrator.** Loops through all 5 fuels, fetching consumption then production for each, chunked by 25 countries.

| Param | Type | Default | Description |
|---|---|---|---|
| `$iso3_list` | `string[]\|null` | All countries | Subset filter. |
| `$force` | `bool` | `false` | Wipes existing data before run. |

**Storage shape:**

```php
[
    'consumption' => [
        '4415' => ['USA' => 34.2, 'DEU' => 12.1, ...],  // fuel => [iso3 => qbtu]
        ...
    ],
    'production' => [
        '4415' => ['USA' => ['value'=>40.1, 'status'=>'ok'], ...],
        ...
    ]
]
```

**Note on production:** Missing countries in a successful batch are recorded as `confirmed_zero` (distinguishes "no data" from "data says zero").

**Rate limiting:** `usleep(200000)` (0.2s) between every chunk.

**Returns:** Full raw data array.

---

### `blomstra_get_eia_raw_data()`

Accessor for cached EIA dataset.

---

### `blomstra_get_eia_country_totals( $iso3 )`

Aggregates per-fuel consumption and production into totals for a single country.

**Returns:**

```php
[
    'consumption_qbtu' => float,
    'production_qbtu'  => float
]
```

---

## System & API Health

### `blomstra_check_api_keys_status()`

Checks whether `COMTRADE_PRIMARY_KEY` and `EIA_API_KEY` are defined and non-empty in `wp-config.php`.

**Returns:** `['comtrade' => bool, 'eia' => bool]`

**Used by:** Admin UI Section 0 (health card).

---

## Cron Scheduling & Health

### `blomstra_update_cron_status( $pillar, $status, $message, $count = 0 )`

Writes a status entry for a given pillar to `blomstra_cron_status` option.

| Param | Type | Description |
|---|---|---|
| `$pillar` | `string` | `'maritime'`, `'eia'`, or `'hhi'`. |
| `$status` | `string` | `'success'`, `'error'`, or `'running'`. |
| `$message` | `string` | Human-readable status message. |
| `$count` | `int` | Items fetched (for success metrics). |

---

### Cron Handlers

| Function | Hook | Schedule | Action |
|---|---|---|---|
| `blomstra_cron_handle_maritime()` | `blomstra_cron_maritime_weekly_event` | Monday 02:00 UTC | `blomstra_get_maritime_raw(true)` |
| `blomstra_cron_handle_eia()` | `blomstra_cron_eia_weekly_event` | Tuesday 02:00 UTC | `blomstra_refresh_eia_raw_data(null, true)` |
| `blomstra_cron_handle_hhi()` | `blomstra_cron_hhi_weekly_event` | Wednesday 02:00 UTC | `blomstra_refresh_comtrade_hhi_data(null, null, true)` |

### `blomstra_schedule_reference_crons()`

Registers all three weekly cron events on `init` if not already scheduled. Uses `strtotime('next Monday 02:00:00 UTC')` etc. for first-run timing.

**Hook:** `add_action('init', ...)`

---

## Admin Actions (PRG Pattern)

### `blomstra_ref_handle_early_actions()`

Handles all admin POST/GET actions **before** any output is sent (PRG = Post/Redirect/Get). Prevents form resubmission on refresh.

**Hook:** `add_action('admin_init', ...)`

**Capabilities required:** `manage_options`

**Actions handled:**

| POST Key | Nonce Action | Function Called | Redirect Param |
|---|---|---|---|
| `blomstra_ref_refresh_countries` | `blomstra_ref_refresh_countries_action` | `blomstra_get_global_country_list(true)` | `?refreshed=countries` |
| `blomstra_ref_refresh_reporters` | `blomstra_ref_refresh_reporters_action` | `blomstra_get_comtrade_reporter_map(true)` | `?refreshed=reporters` |
| `blomstra_ref_refresh_maritime` | `blomstra_ref_refresh_maritime_action` | `blomstra_get_maritime_raw(true)` | `?refreshed=maritime` |
| `blomstra_ref_refresh_hhi` | `blomstra_ref_refresh_hhi_action` | `wp_schedule_single_event(..., 'blomstra_cron_hhi_weekly_event')` | `?triggered=hhi` |
| `blomstra_ref_refresh_eia` | `blomstra_ref_refresh_eia_action` | `wp_schedule_single_event(..., 'blomstra_cron_eia_weekly_event')` | `?triggered=eia` |
| `blomstra_ref_flush_countries` | `blomstra_ref_flush_countries_action` | `delete_transient(...)` | `?flushed=countries` |
| `blomstra_ref_flush_reporters` | `blomstra_ref_flush_reporters_action` | `delete_transient(...)`, `delete_option(...)` | `?flushed=reporters` |
| `blomstra_ref_flush_maritime` | `blomstra_ref_flush_maritime_action` | `delete_transient(...)`, `delete_option(...)` | `?flushed=maritime` |
| `blomstra_ref_flush_hhi` | `blomstra_ref_flush_hhi_action` | `delete_option(...)` × 3 | `?flushed=hhi` |
| `blomstra_ref_flush_eia` | `blomstra_ref_flush_eia_action` | `delete_option(...)` × 3 | `?flushed=eia` |
| `blomstra_ref_flush` | `blomstra_ref_flush_action` | **Nuclear option** — deletes ALL transients and options | `?flushed=all` |

> **Note:** HHI and EIA refresh buttons queue a **background single-event cron** rather than running inline. This avoids HTTP timeouts for long-running batch jobs.

---

## Admin UI Render

### `blomstra_ref_render_page()`

Renders the full "Blomstra Insights Tools → Reference Data" admin page. Hooked to `add_menu_page()` / `add_submenu_page()`.

**Sections:**

| # | Section | Content |
|---|---|---|
| 0 | System & API Key Diagnostics | Comtrade key status, EIA key status, PHP memory limit, max execution time |
| 1 | Automated Weekly Cron Health | Table of 3 pillars with schedule, next run, last status, items fetched, message |
| 2 | Data Layers & Granular Cache Control | Per-dataset status, item count, Refresh/Flush buttons. Master "Emergency Flush ALL" |
| 3 | API Diagnostic Sandbox | Single-target test form (Comtrade HHI, EIA Petroleum, World Bank Maritime). Executes isolated API call and pretty-prints JSON response |
| 4 | API Call Logs & Historical Summaries | Collapsible Comtrade call log (50 entries) + HHI execution summary. Collapsible EIA call log + summary |
| 5 | Raw Debug & Dump Inspector | Collapsible `print_r()` of maritime debug and reporters debug options |

**Sandbox logic:**
- **Comtrade:** Maps ISO3 → reporter code → fetches batch of 1 → computes HHI → returns timing, row count, computed HHI, sample rows
- **EIA:** Fetches consumption for product `4415` (Petroleum) → returns status, row count, sample rows
- **Maritime:** Direct World Bank API call for single country/year → returns HTTP code and raw body

---

## Snapshot History (Database)

### Schema

Table: `{wp_prefix}_blomstra_index_history`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment PK |
| `index_slug` | `VARCHAR(40)` | e.g. `'cii'` |
| `iso3` | `VARCHAR(3)` | Country code |
| `snapshot_period` | `VARCHAR(7)` | `YYYY-MM` format |
| `composite_score` | `DECIMAL(6,2)` | Nullable |
| `rank_value` | `SMALLINT UNSIGNED` | Nullable |
| `coverage_type` | `VARCHAR(10)` | `full`, `partial`, or null |
| `pillars_json` | `LONGTEXT` | JSON-encoded remaining pillar data |
| `recorded_at` | `DATETIME` | Timestamp |

**Constraints:**
- `UNIQUE KEY idx_slug_iso_period (index_slug, iso3, snapshot_period)` — upserts within same month
- `KEY idx_slug_period (index_slug, snapshot_period)` — fast index-wide queries

### `blomstra_index_history_maybe_install()`

Idempotent table creation via `dbDelta()`. Safe to call on every `admin_init`.

### `blomstra_index_snapshot_save( $index_slug, $countries )`

Upserts one snapshot per country for the current calendar month. Extra keys in `$countries[$iso3]` (beyond `composite_score`, `rank`, `coverage_type`) are JSON-encoded into `pillars_json`.

| Param | Type | Description |
|---|---|---|
| `$index_slug` | `string` | Index identifier |
| `$countries` | `array` | ISO3 → row array |

**Returns:** `int` — number of rows written.

### `blomstra_index_snapshot_get_history( $index_slug, $iso3 = null )`

Retrieves historical snapshots, optionally filtered to one country. Ordered oldest-to-newest.

**Returns:** `array` — `['USA' => [['period'=>'2026-01', 'composite_score'=>45.2, ...], ...], ...]`

---

## REST Endpoints

All registered under namespace `blomstra/v1`.

| Route | Method | Callback | Auth | Description |
|---|---|---|---|---|
| `/country-names` | `GET` | `blomstra_get_global_country_list()` | `__return_true` (public) | ISO3 → country name map |
| `/index-history/{slug}` | `GET` | `blomstra_index_snapshot_get_history()` | `__return_true` (public) | Historical snapshots for an index. Optional `?iso3=USA` filter |

---

## Data Flow & Dependencies

### Dependency Graph

```
blomstra_get_global_country_list()
    ├── blomstra_get_comtrade_reporter_map()
    │   └── blomstra_refresh_comtrade_hhi_data()
    │       └── blomstra_comtrade_fetch_partner_imports_batch()
    │           └── blomstra_compute_hhi_from_batch_rows()
    │       └── blomstra_get_country_hhi_value()  [accessor]
    │
    ├── blomstra_get_maritime_raw()
    │   └── blomstra_get_maritime_value()
    │       └── blomstra_is_landlocked()
    │
    └── blomstra_refresh_eia_raw_data()
        └── blomstra_eia_fetch_activity_batch()
            └── blomstra_eia_pick_latest_per_country()
        └── blomstra_get_eia_country_totals()  [accessor]
```

### Cron → Data Flow

```
Monday 02:00  →  blomstra_cron_handle_maritime()
                    → blomstra_get_maritime_raw(true)
                        → transient: blomstra_maritime_raw

Tuesday 02:00 →  blomstra_cron_handle_eia()
                    → blomstra_refresh_eia_raw_data(null, true)
                        → option: blomstra_eia_raw_data

Wednesday 02:00 → blomstra_cron_handle_hhi()
                    → blomstra_refresh_comtrade_hhi_data(null, null, true)
                        → option: blomstra_comtrade_hhi_data
```

### Admin Page → Action Flow

```
User clicks "Refresh" on admin page
    → POST to admin.php (PRG)
    → blomstra_ref_handle_early_actions() (admin_init)
        → refresh function called
        → wp_safe_redirect() back to page
    → blomstra_ref_render_page() displays updated status
```

---

## External API Dependencies

| API | Endpoint | Used By | Key Required |
|---|---|---|---|
| World Bank | `api.worldbank.org/v2/country` | Country list, Maritime LSCI | No |
| UN Comtrade (Reference) | `comtradeapi.un.org/files/v1/app/reference/Reporters.json` | Reporter map | No |
| UN Comtrade (Data) | `comtradeapi.un.org/data/v1/get/C/A/HS` | HHI batch fetch | `COMTRADE_PRIMARY_KEY` |
| EIA | `api.eia.gov/v2/international/data/` | Energy raw data | `EIA_API_KEY` |

---

## WordPress Hooks Summary

| Hook | Function | Priority |
|---|---|---|
| `admin_init` | `blomstra_ref_handle_early_actions()` | default |
| `admin_menu` | `blomstra_ref_register_page()` | `5` |
| `init` | `blomstra_schedule_reference_crons()` | default |
| `rest_api_init` | Register `/country-names` | default |
| `rest_api_init` | Register `/index-history/{slug}` | default |
| `admin_init` | `blomstra_index_history_maybe_install()` | default |
| `admin_notices` | Snapshot history visibility panel | default |
| `blomstra_cron_maritime_weekly_event` | `blomstra_cron_handle_maritime()` | — |
| `blomstra_cron_eia_weekly_event` | `blomstra_cron_handle_eia()` | — |
| `blomstra_cron_hhi_weekly_event` | `blomstra_cron_handle_hhi()` | — |
