# **DATABASE.md – Blomstra Insights Database Schema** 

Document version: 1.0.0\
Status: CANONICAL\
Applies to: All database tables and storage mechanisms used by Blomstra Insights\
Last verified against commit: (to be filled)\
Source files: `global-reference-data.php`, `blomstra-index-alerts.php`, `blomstra-index-utilities.php
`Effective date: 2026-09-09

***


## **Document Control** 

|                         |                                                                                                                                                            |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Field                   | Value                                                                                                                                                      |
| Document                | `DATABASE.md` v1.0.0                                                                                                                                       |
| Verified against commit | (to be filled)                                                                                                                                             |
| Source files            | `global-reference-data.php` (table creation, snapshot storage), `blomstra-index-alerts.php` (alerts table), `blomstra-index-utilities.php` (history table) |
| Method                  | Code-first reconstruction (Stage 2) — every table and column below traces to a specific `dbDelta()` or `ALTER TABLE` statement in the code.                |
| Status                  | Complete for current code. Items in §9 are open questions, not implementation gaps.                                                                        |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## **Table of Contents** 

1. Overview

2. WordPress Core Tables Used

3. Custom Table: `wp_blomstra_index_history`

4. Custom Table: `wp_blomstra_alerts`

5. Custom Table: `wp_blomstra_historical_data`

6. Custom Table: `wp_blomstra_cache_jobs`

7. Option Keys Reference

8. Transient Keys Reference

9. Schema Migrations

10. Backup & Restore

11. Open Questions

12. Corrections to Prior Documentation

***


## **1. Overview**

\[D] The Blomstra Insights platform uses a hybrid storage approach:

|                                        |                              |                                                                                 |
| -------------------------------------- | ---------------------------- | ------------------------------------------------------------------------------- |
| Storage Type                           | Purpose                      | Examples                                                                        |
| WordPress Options (`wp_options`)       | Persistent key-value storage | Composite data, pillar data, credentials, configuration, state machine pointers |
| WordPress Transients (`wp_transients`) | Cache with expiration        | API response caches, cron locks, cooldowns, flags                               |
| Custom Tables                          | Structured data with indexes | Historical snapshots, alerts, historical data cache, job status                 |

\[N] All custom tables are created on `admin_init` via `dbDelta()` with version-gated re-installs. No manual migration is required at deploy time — visiting any wp-admin page triggers schema creation/updates.

***


## **2. WordPress Core Tables Used** 

### **2.1** `wp_options` **– Persistent Key-Value Storage** 

\[D] Used for data that must persist across requests and survive cache clears.

|                 |                                                                                                  |
| --------------- | ------------------------------------------------------------------------------------------------ |
| Characteristics | Value                                                                                            |
| Storage         | Persistent (does not expire)                                                                     |
| Update method   | `update_option()` / `get_option()`                                                               |
| Use cases       | Composite data, pillar data, credentials, configuration, state machine pointers, status tracking |
| Autoload        | Set to `'no'` for most large datasets (prevents memory bloat on every page load)                 |

\[N] All `blomstra_*` and `{slug}_*` options should have `$autoload = false` unless explicitly justified (small config options may autoload for performance).


### **2.2** `wp_transients` **– Cache with Expiration** 

\[D] Used for data that can be regenerated and should expire after a set time.

|                 |                                                                   |
| --------------- | ----------------------------------------------------------------- |
| Characteristics | Value                                                             |
| Storage         | Expires after TTL                                                 |
| Update method   | `set_transient()` / `get_transient()`                             |
| Use cases       | API response caches, cron locks, cooldowns, flags                 |
| TTL patterns    | 24h (country list), 1 week (API data), 5-30 min (locks/cooldowns) |

\[N] Transients are not guaranteed to persist — they may be evicted by object cache plugins (Redis, Memcached). Critical data should not be stored exclusively in transients.

***


## **3. Custom Table:** `wp_blomstra_index_history` 

\[D] Stores historical snapshots of index data for trend visualization and rank comparison.


### **3.1 Schema** 

    sql
    CREATE TABLE wp_blomstra_index_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        index_slug VARCHAR(40) NOT NULL,
        iso3 VARCHAR(3) NOT NULL,
        snapshot_period VARCHAR(7) NOT NULL,  -- YYYY-MM
        composite_score DECIMAL(6,2) DEFAULT NULL,
        rank_value SMALLINT UNSIGNED DEFAULT NULL,
        coverage_type VARCHAR(10) DEFAULT NULL,
        pillars_json LONGTEXT DEFAULT NULL,
        recorded_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_slug_iso_period (index_slug, iso3, snapshot_period),
        KEY idx_slug_period (index_slug, snapshot_period)
    );


### **3.2 Field Descriptions** 

|                   |                   |                                                                   |
| ----------------- | ----------------- | ----------------------------------------------------------------- |
| Field             | Type              | Description                                                       |
| `id`              | BIGINT UNSIGNED   | Auto-increment primary key                                        |
| `index_slug`      | VARCHAR(40)       | Index identifier (e.g., 'sivi')                                   |
| `iso3`            | VARCHAR(3)        | 3-letter country code                                             |
| `snapshot_period` | VARCHAR(7)        | Period in YYYY-MM format (e.g., '2024-01')                        |
| `composite_score` | DECIMAL(6,2)      | Composite score at snapshot time (0-100)                          |
| `rank_value`      | SMALLINT UNSIGNED | Definitive rank or best estimate                                  |
| `coverage_type`   | VARCHAR(10)       | `'full'` or `'partial'`                                           |
| `pillars_json`    | LONGTEXT          | JSON of pillar scores, DQI, vintage summary, and all other fields |
| `recorded_at`     | DATETIME          | When the row was inserted/updated                                 |


### **3.3 Keys & Indexes** 

|                       |         |                                       |                                       |
| --------------------- | ------- | ------------------------------------- | ------------------------------------- |
| Key                   | Type    | Columns                               | Purpose                               |
| `PRIMARY`             | Primary | `(id)`                                | Row identity                          |
| `idx_slug_iso_period` | Unique  | `(index_slug, iso3, snapshot_period)` | Prevents duplicates; enables upsert   |
| `idx_slug_period`     | Index   | `(index_slug, snapshot_period)`       | Fast history queries per index/period |


### **3.4 Write Operations** 

\[D] `blomstra_index_snapshot_save($index_slug, $countries, $custom_period = null)`:

    sql
    INSERT INTO wp_blomstra_index_history
        (index_slug, iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json, recorded_at)
    VALUES (%s, %s, %s, %f, %d, %s, %s, %s)
    ON DUPLICATE KEY UPDATE
        composite_score = VALUES(composite_score),
        rank_value = VALUES(rank_value),
        coverage_type = VALUES(coverage_type),
        pillars_json = VALUES(pillars_json),
        recorded_at = VALUES(recorded_at)

\[N] Uses `INSERT ... ON DUPLICATE KEY UPDATE` — re-saving the same index/country/period overwrites rather than duplicating.


### **3.5 Read Operations** 

\[D] `blomstra_index_snapshot_get_history($index_slug, $iso3 = null)`:

    sql
    -- All countries for an index
    SELECT iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json
    FROM wp_blomstra_index_history
    WHERE index_slug = %s
    ORDER BY iso3 ASC, snapshot_period ASC;

    -- Single country
    SELECT iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json
    FROM wp_blomstra_index_history
    WHERE index_slug = %s AND iso3 = %s
    ORDER BY snapshot_period ASC;


### **3.6** `pillars_json` **Structure** 

\[D] Contains all data not stored in first-class columns:

    json
    {
        "energy": 65.0,
        "hhi": 55.0,
        "maritime": 70.0,
        "dqi_energy": 85.5,
        "dqi_hhi": 90.2,
        "dqi_maritime": 72.1,
        "composite_dqi": 82.6,
        "vintage_summary": "Energy: 2024, HHI: 2023, Maritime: 2024"
    }

\[N] The exact shape of `pillars_json` is index-specific and determined by `blomstra_build_flat_snapshot_row()`.


### **3.7 Lifecycle** 

\[D] Rows are inserted on every successful composite build (live or historical). The `recorded_at` timestamp is updated on every save (including overwrites).

***


## **4. Custom Table:** `wp_blomstra_alerts` 
\[D] Stores alert records for change detection between builds.


### **4.1 Schema** 

    sql
    CREATE TABLE wp_blomstra_alerts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        index_slug VARCHAR(40) NOT NULL,
        iso3 VARCHAR(3) DEFAULT NULL,
        country_name VARCHAR(100) DEFAULT NULL,
        previous_rank INT DEFAULT NULL,
        current_rank INT DEFAULT NULL,
        previous_score DECIMAL(6,2) DEFAULT NULL,
        current_score DECIMAL(6,2) DEFAULT NULL,
        rank_delta INT DEFAULT NULL,
        score_delta DECIMAL(6,2) DEFAULT NULL,
        previous_pillars LONGTEXT DEFAULT NULL,
        current_pillars LONGTEXT DEFAULT NULL,
        alert_reason VARCHAR(100) DEFAULT NULL,
        log_type VARCHAR(20) DEFAULT 'alert',
        triggered_at DATETIME NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_slug (index_slug),
        KEY idx_iso3 (iso3),
        KEY idx_triggered_at (triggered_at)
    );


### **4.2 Field Descriptions** 

|                    |                 |                                                                                                                  |
| ------------------ | --------------- | ---------------------------------------------------------------------------------------------------------------- |
| Field              | Type            | Description                                                                                                      |
| `id`               | BIGINT UNSIGNED | Auto-increment primary key                                                                                       |
| `index_slug`       | VARCHAR(40)     | Index identifier (e.g., 'sivi')                                                                                  |
| `iso3`             | VARCHAR(3)      | 3-letter country code                                                                                            |
| `country_name`     | VARCHAR(100)    | Country name for display                                                                                         |
| `previous_rank`    | INT             | Rank before change                                                                                               |
| `current_rank`     | INT             | Rank after change                                                                                                |
| `previous_score`   | DECIMAL(6,2)    | Score before change                                                                                              |
| `current_score`    | DECIMAL(6,2)    | Score after change                                                                                               |
| `rank_delta`       | INT             | Change in rank (positive = worse)                                                                                |
| `score_delta`      | DECIMAL(6,2)    | Change in score (positive = more vulnerable)                                                                     |
| `previous_pillars` | LONGTEXT        | JSON of pillar scores before                                                                                     |
| `current_pillars`  | LONGTEXT        | JSON of pillar scores after                                                                                      |
| `alert_reason`     | VARCHAR(100)    | Comma-separated reasons: 'rank\_change', 'score\_change', 'pillar\_change\_{pillar}', 'new\_country', 'excluded' |
| `log_type`         | VARCHAR(20)     | 'alert', 'info', 'warning', 'error', 'config'                                                                    |
| `triggered_at`     | DATETIME        | When the alert was triggered                                                                                     |
| `sent_at`          | DATETIME        | When the alert was delivered (null until delivered)                                                              |


### **4.3 Keys & Indexes** 

|                    |         |                  |                          |
| ------------------ | ------- | ---------------- | ------------------------ |
| Key                | Type    | Columns          | Purpose                  |
| `PRIMARY`          | Primary | `(id)`           | Row identity             |
| `idx_slug`         | Index   | `(index_slug)`   | Filter alerts by index   |
| `idx_iso3`         | Index   | `(iso3)`         | Filter alerts by country |
| `idx_triggered_at` | Index   | `(triggered_at)` | Time-based cleanup       |


### **4.4 Write Operations** 

\[D] `blomstra_alert_store_records($index_slug, $changes)` inserts one row per change detected.


### **4.5 Read Operations** 

\[D] `blomstra_alerts_render_page()` queries the table for the admin UI with pagination and filters.


### **4.6 Cleanup** 
\[D] `blomstra_alert_cleanup()`:

    sql
    -- Delete by age
    DELETE FROM wp_blomstra_alerts
    WHERE log_type = 'alert'
    AND triggered_at < NOW() - INTERVAL %d DAY;

    -- Trim per index to max rows
    DELETE FROM wp_blomstra_alerts
    WHERE log_type = 'alert'
    AND index_slug = %s
    AND id NOT IN (
        SELECT id FROM (
            SELECT id FROM wp_blomstra_alerts
            WHERE log_type = 'alert' AND index_slug = %s
            ORDER BY triggered_at DESC
            LIMIT %d
        ) AS keep
    );

\[N] Only `log_type = 'alert'` rows are pruned. System logs (`info`, `warning`, `error`, `config`) persist indefinitely.

***


## **5. Custom Table:** `wp_blomstra_historical_data` 

\[D] Caches historical data values for backfill operations. Used exclusively by year-specific fetchers.


### **5.1 Schema** 

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
        UNIQUE KEY idx_source_indicator_fuel_iso3_year (source, indicator, fuel, iso3, year),
        KEY idx_source_year (source, year),
        KEY idx_iso3 (iso3)
    );


### **5.2 Field Descriptions** 

|              |                   |                                                         |
| ------------ | ----------------- | ------------------------------------------------------- |
| Field        | Type              | Description                                             |
| `id`         | BIGINT UNSIGNED   | Auto-increment primary key                              |
| `source`     | VARCHAR(20)       | Data source: 'eia', 'comtrade', 'maritime', 'imf', 'wb' |
| `indicator`  | VARCHAR(50)       | Indicator code or name                                  |
| `fuel`       | VARCHAR(20)       | Fuel code (for EIA data only)                           |
| `iso3`       | CHAR(3)           | Country code                                            |
| `year`       | SMALLINT UNSIGNED | Data year                                               |
| `value`      | DECIMAL(12,4)     | Numeric value                                           |
| `meta`       | JSON              | Additional metadata (period, reporter\_code, etc.)      |
| `fetched_at` | DATETIME          | When the row was inserted                               |


### **5.3 Keys & Indexes** 
|                                       |         |                                         |                                     |
| ------------------------------------- | ------- | --------------------------------------- | ----------------------------------- |
| Key                                   | Type    | Columns                                 | Purpose                             |
| `PRIMARY`                             | Primary | `(id)`                                  | Row identity                        |
| `idx_source_indicator_fuel_iso3_year` | Unique  | `(source, indicator, fuel, iso3, year)` | Prevents duplicates; enables upsert |
| `idx_source_year`                     | Index   | `(source, year)`                        | Fast queries by source/year         |
| `idx_iso3`                            | Index   | `(iso3)`                                | Fast queries by country             |


### **5.4 Cache-If-Settled Rule** 

\[N] A year is only cached if `$year ≤ current_year - 2`. Recent years are never persisted to this cache, since they may still be revised upstream — re-fetched fresh every time until they age past the 2-year settling window.


### **5.5 Write Operations** 

\[D] `blomstra_get_historical_data()` inserts rows via `wpdb->insert()` after fetching missing data.


### **5.6 Read Operations** 

\[D] `blomstra_get_historical_data()` queries the table before making API calls:

    sql
    SELECT iso3, value, meta
    FROM wp_blomstra_historical_data
    WHERE source = %s
    AND indicator = %s
    AND fuel IS NULL
    AND year = %d
    AND iso3 IN (...);

***


## **6. Custom Table:** `wp_blomstra_cache_jobs` 

\[D] Tracks the status of historical cache jobs (one row per source-year pair).


### **6.1 Schema** 

    sql
    CREATE TABLE wp_blomstra_cache_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(20) NOT NULL,
        year SMALLINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        countries_cached INT DEFAULT 0,
        error_message TEXT DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        attempts INT DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY idx_source_year (source, year),
        KEY idx_status (status),
        KEY idx_year (year)
    );


### **6.2 Field Descriptions** 
|                    |                   |                                                         |
| ------------------ | ----------------- | ------------------------------------------------------- |
| Field              | Type              | Description                                             |
| `id`               | BIGINT UNSIGNED   | Auto-increment primary key                              |
| `source`           | VARCHAR(20)       | Data source: 'eia', 'comtrade', 'maritime', 'imf', 'wb' |
| `year`             | SMALLINT UNSIGNED | Target year                                             |
| `status`           | VARCHAR(20)       | 'pending', 'running', 'success', 'failed', 'skipped'    |
| `countries_cached` | INT               | Number of countries successfully cached                 |
| `error_message`    | TEXT              | Error message if failed                                 |
| `started_at`       | DATETIME          | When job started                                        |
| `completed_at`     | DATETIME          | When job completed                                      |
| `attempts`         | INT               | Number of retry attempts                                |


### **6.3 Keys & Indexes** 

|                   |         |                  |                     |
| ----------------- | ------- | ---------------- | ------------------- |
| Key               | Type    | Columns          | Purpose             |
| `PRIMARY`         | Primary | `(id)`           | Row identity        |
| `idx_source_year` | Unique  | `(source, year)` | Prevents duplicates |
| `idx_status`      | Index   | `(status)`       | Filter by status    |
| `idx_year`        | Index   | `(year)`         | Filter by year      |


### **6.4 Lifecycle** 

\[D] `blomstra_cache_job_update($source, $year, $status, $countries, $error)` updates the job status. Jobs are created by `blomstra_hist_cache_bulk` admin action or individual retry actions.


### **6.5 Read Operations** 

\[D] `blomstra_cache_job_get_status($source, $year)` and `blomstra_cache_job_get_all()` for admin UI.

***


## **7. Option Keys Reference** 

\[D] All persistent options used by the system:


### **7.1 Composite & Index Data** 

|                                      |                                       |                                    |                                   |
| ------------------------------------ | ------------------------------------- | ---------------------------------- | --------------------------------- |
| Option Key                           | Purpose                               | Written By                         | Read By                           |
| `sivi_composite_index`               | Current SIVI composite data           | `sivi_build_composite()`           | REST endpoint, admin UI           |
| `sivi_composite_internal`            | Generic builder internal working copy | `blomstra_build_index_composite()` | Generic builder (internal)        |
| `sivi_composite_index_scenario_{id}` | Scenario builds                       | `sivi_store_scenario()`            | `sivi_list_scenarios()`, admin UI |


### **7.2 Pillar Data** 

|                      |                          |                                  |                             |
| -------------------- | ------------------------ | -------------------------------- | --------------------------- |
| Option Key           | Purpose                  | Written By                       | Read By                     |
| `sivi_energy_data`   | Energy pillar data       | `sivi_persist_energy_results()`  | `sivi_get_generic_config()` |
| `sivi_hhi_data`      | HHI pillar data          | `sivi_merge_hhi_into_pillar()`   | `sivi_get_generic_config()` |
| `sivi_maritime_data` | Maritime pillar data     | `sivi_refresh_maritime_pillar()` | `sivi_get_generic_config()` |
| `sivi_energy_meta`   | Energy pillar metadata   | `sivi_persist_energy_results()`  | Admin UI freshness          |
| `sivi_hhi_meta`      | HHI pillar metadata      | `sivi_merge_hhi_into_pillar()`   | Admin UI freshness          |
| `sivi_maritime_meta` | Maritime pillar metadata | `sivi_refresh_maritime_pillar()` | Admin UI freshness          |


### **7.3 L1 Production Data** 

|                                      |                     |                                        |                                |
| ------------------------------------ | ------------------- | -------------------------------------- | ------------------------------ |
| Option Key                           | Purpose             | Written By                             | Read By                        |
| `blomstra_comtrade_hhi_data`         | HHI production data | `blomstra_refresh_comtrade_hhi_data()` | `sivi_merge_hhi_into_pillar()` |
| `blomstra_eia_raw_data`              | EIA raw data        | `blomstra_process_eia_activity()`      | `sivi_refresh_energy_pillar()` |
| `blomstra_comtrade_hhi_data_staging` | HHI staging         | `blomstra_refresh_comtrade_hhi_data()` | (Internal)                     |
| `blomstra_eia_raw_data_staging`      | EIA staging         | `blomstra_process_eia_activity()`      | (Internal)                     |


### **7.4 State Machine Pointers** 

|                                 |                            |                                 |                                        |
| ------------------------------- | -------------------------- | ------------------------------- | -------------------------------------- |
| Option Key                      | Purpose                    | Written By                      | Read By                                |
| `blomstra_hhi_refresh_pointer`  | HHI state machine pointer  | `blomstra_update_hhi_pointer()` | `blomstra_refresh_comtrade_hhi_data()` |
| `blomstra_eia_refresh_pointer`  | EIA state machine pointer  | `blomstra_update_eia_pointer()` | `blomstra_cron_handle_eia()`           |
| `blomstra_wb_refresh_pointer`   | WB indicator pointer       | `blomstra_update_wb_pointer()`  | `blomstra_cron_handle_wb_indicators()` |
| `sivi_backfill_status`          | Historical backfill status | `sivi_update_backfill_status()` | `sivi_get_backfill_status()`, admin UI |
| `sivi_backfill_range_start/end` | Backfill range             | Admin UI                        | `blomstra_get_index_backfill_range()`  |


### **7.5 Configuration** 

|                                 |                          |            |                                 |
| ------------------------------- | ------------------------ | ---------- | ------------------------------- |
| Option Key                      | Purpose                  | Written By | Read By                         |
| `blomstra_api_credentials`      | API credentials          | Admin UI   | `blomstra_get_api_credential()` |
| `blomstra_alert_config`         | Alert configuration      | Admin UI   | `blomstra_get_alert_config()`   |
| `sivi_custom_composite_weights` | Custom composite weights | Admin UI   | `sivi_get_composite_weights()`  |


### **7.6 Status & Logs** 
|                                   |                       |                                        |          |
| --------------------------------- | --------------------- | -------------------------------------- | -------- |
| Option Key                        | Purpose               | Written By                             | Read By  |
| `blomstra_cron_status`            | Cron health status    | `blomstra_update_cron_status()`        | Admin UI |
| `blomstra_hhi_refresh_summary`    | HHI run summary       | `blomstra_refresh_comtrade_hhi_data()` | Admin UI |
| `blomstra_eia_refresh_summary`    | EIA run summary       | `blomstra_process_eia_activity()`      | Admin UI |
| `blomstra_comtrade_call_log`      | Comtrade API call log | `blomstra_log_comtrade_call()`         | Admin UI |
| `blomstra_eia_call_log`           | EIA API call log      | `blomstra_log_eia_call()`              | Admin UI |
| `blomstra_wb_indicator_fetch_log` | WB fetch log          | `blomstra_log_wb_indicator_fetch()`    | Admin UI |
| `blomstra_imf_call_log`           | IMF call log          | `blomstra_log_imf_call()`              | Admin UI |


### **7.7 Miscellaneous** 

|                                     |                              |                 |                             |
| ----------------------------------- | ---------------------------- | --------------- | --------------------------- |
| Option Key                          | Purpose                      | Written By      | Read By                     |
| `blomstra_landlocked_override`      | Landlocked list override     | Admin UI        | `blomstra_is_landlocked()`  |
| `blomstra_landlocked_verify_date`   | Landlocked verification date | Admin UI        | Admin UI                    |
| `blomstra_index_history_db_version` | History table version        | Table installer | Table installer             |
| `sivi_benchmark_comparator`         | Benchmark comparator data    | Admin UI        | `sivi_get_generic_config()` |

***


## **8. Transient Keys Reference** 

\[D] All transient keys used by the system:


### **8.1 Cron Locks** 

|                                        |                        |         |                                          |                                    |
| -------------------------------------- | ---------------------- | ------- | ---------------------------------------- | ---------------------------------- |
| Transient Key                          | Purpose                | TTL     | Set By                                   | Cleared By                         |
| `blomstra_hhi_refresh_in_progress`     | HHI cron lock          | 30 min  | `blomstra_cron_handle_hhi()`             | Cron handler (finally)             |
| `blomstra_eia_refresh_in_progress`     | EIA cron lock          | 30 min  | `blomstra_cron_handle_eia()`             | Cron handler (finally)             |
| `blomstra_wb_refresh_in_progress`      | WB cron lock           | 30 min  | `blomstra_cron_handle_wb_indicators()`   | Cron handler (finally)             |
| `blomstra_imf_weekly_in_progress`      | IMF cron lock          | 10 min  | `blomstra_cron_handle_imf()`             | Cron handler (finally)             |
| `blomstra_maritime_weekly_in_progress` | Maritime cron lock     | 10 min  | `blomstra_cron_handle_maritime()`        | Cron handler (finally)             |
| `blomstra_countries_async_in_progress` | Country list cron lock | 10 min  | `blomstra_cron_handle_countries_async()` | Cron handler (finally)             |
| `blomstra_reporters_async_in_progress` | Reporter map cron lock | 10 min  | `blomstra_cron_handle_reporters_async()` | Cron handler (finally)             |
| `sivi_build_lock`                      | SIVI build lock        | 30 min  | `blomstra_build_index_composite()`       | Builder (finally)                  |
| `sivi_backfill_lock`                   | Backfill lock          | 2 hours | `sivi_backfill_all` admin action         | `sivi_backfill_check_completion()` |


### **8.2 Caches** 

|                                    |                                 |          |                                        |                                        |
| ---------------------------------- | ------------------------------- | -------- | -------------------------------------- | -------------------------------------- |
| Transient Key                      | Purpose                         | TTL      | Set By                                 | Read By                                |
| `blomstra_global_country_list`     | World Bank country list         | 24h      | `blomstra_get_global_country_list()`   | `blomstra_get_global_country_list()`   |
| `blomstra_comtrade_reporters`      | Comtrade reporter map           | 1 week   | `blomstra_get_comtrade_reporter_map()` | `blomstra_get_comtrade_reporter_map()` |
| `blomstra_maritime_raw`            | Maritime LSCI data              | 1 week   | `blomstra_get_maritime_raw()`          | `blomstra_get_maritime_raw()`          |
| `blomstra_wb_indicator_{md5}`      | WB indicator data               | 1 week   | `blomstra_fetch_wb_indicator_batch()`  | `blomstra_fetch_wb_indicator_batch()`  |
| `blomstra_wb_indicator_{md5}_tmp`  | WB indicator staging            | 1 week   | `blomstra_fetch_wb_indicator_batch()`  | `blomstra_fetch_wb_indicator_batch()`  |
| `blomstra_imf_indicator_{md5}`     | IMF indicator data              | 1 week   | `blomstra_fetch_imf_generic()`         | `blomstra_fetch_imf_generic()`         |
| `blomstra_imf_indicator_{md5}_tmp` | IMF indicator staging           | 1 week   | `blomstra_fetch_imf_generic()`         | `blomstra_fetch_imf_generic()`         |
| `sivi_energy_{iso3}`               | SIVI energy per-country cache   | 12 hours | `sivi_persist_energy_results()`        | (Future use)                           |
| `sivi_maritime_{iso3}`             | SIVI maritime per-country cache | 7 days   | `sivi_refresh_maritime_pillar()`       | (Future use)                           |


### **8.3 Cooldowns & Debounce** 

|                                     |                       |       |                                      |                                      |
| ----------------------------------- | --------------------- | ----- | ------------------------------------ | ------------------------------------ |
| Transient Key                       | Purpose               | TTL   | Set By                               | Read By                              |
| `blomstra_alert_cooldown_{slug}`    | Alert cooldown        | 5 min | `blomstra_fire_index_alerts()`       | `blomstra_fire_index_alerts()`       |
| `blomstra_pending_alert_ids_{slug}` | Pending alert IDs     | 5 min | `blomstra_fire_index_alerts()`       | `blomstra_deliver_pending_alerts()`  |
| `sivi_auto_refresh_queued`          | Auto-refresh debounce | 5 min | `sivi_maybe_auto_refresh_after_rd()` | `sivi_maybe_auto_refresh_after_rd()` |


### **8.4 Flags** 

|                                |                             |        |                                          |            |
| ------------------------------ | --------------------------- | ------ | ---------------------------------------- | ---------- |
| Transient Key                  | Purpose                     | TTL    | Set By                                   | Read By    |
| `sivi_auto_build_failed`       | Build failure flag          | 24h    | `blomstra_build_index_composite()`       | Admin UI   |
| `blomstra_alerts_column_added` | Alert column migration flag | 1 day  | `blomstra_alerts_add_log_type_column()`  | (Internal) |
| `blomstra_landlocked_reset`    | Landlocked reset flag       | 10 min | `blomstra_reset_landlocked` admin action | Admin UI   |
| `blomstra_api_test_result`     | API test result             | 30s    | `blomstra_test_api_credentials`          | Admin UI   |


### **8.5 Historical Backfill** 

|                                    |                          |        |                                        |                                        |
| ---------------------------------- | ------------------------ | ------ | -------------------------------------- | -------------------------------------- |
| Transient Key                      | Purpose                  | TTL    | Set By                                 | Read By                                |
| `blomstra_wb_historical_{md5}`     | WB historical data cache | 1 week | `blomstra_fetch_wb_historical_batch()` | `blomstra_fetch_wb_historical_batch()` |
| `blomstra_wb_historical_{md5}_tmp` | WB historical staging    | 1 week | `blomstra_fetch_wb_historical_batch()` | `blomstra_fetch_wb_historical_batch()` |

***


## **9. Schema Migrations** 

\[D] Table creation and migrations are triggered on `admin_init`:


### **9.1 Table Creation Hooks** 

|                               |              |                                            |                               |
| ----------------------------- | ------------ | ------------------------------------------ | ----------------------------- |
| Table                         | Hook         | Function                                   | Version Guard                 |
| `wp_blomstra_index_history`   | `admin_init` | `blomstra_index_history_maybe_install()`   | `BLOMSTRA_HISTORY_DB_VERSION` |
| `wp_blomstra_alerts`          | `admin_init` | `blomstra_alerts_maybe_install_table()`    | (Idempotent)                  |
| `wp_blomstra_historical_data` | `admin_init` | `blomstra_historical_data_maybe_install()` | (Idempotent)                  |
| `wp_blomstra_cache_jobs`      | `admin_init` | `blomstra_cache_jobs_maybe_install()`      | (Idempotent)                  |


### **9.2 Column Migrations** 

|                      |                       |              |                                                                           |
| -------------------- | --------------------- | ------------ | ------------------------------------------------------------------------- |
| Table                | Migration             | Hook         | SQL                                                                       |
| `wp_blomstra_alerts` | Add `log_type` column | `admin_init` | `ALTER TABLE ADD COLUMN log_type VARCHAR(20) DEFAULT 'alert'`             |
| `wp_blomstra_alerts` | Backfill `log_type`   | `admin_init` | `UPDATE wp_blomstra_alerts SET log_type = 'alert' WHERE log_type IS NULL` |


### **9.3 Version Gate Pattern** 

\[N] Table creation functions use a version option to prevent repeated schema checks:

    php
    function blomstra_index_history_maybe_install() {
        if (get_option('blomstra_index_history_db_version') === BLOMSTRA_HISTORY_DB_VERSION) {
            return;
        }
        // ... dbDelta() ...
        update_option('blomstra_index_history_db_version', BLOMSTRA_HISTORY_DB_VERSION);
    }

***


## **10. Backup & Restore** 

### **10.1 Tables to Back Up** 

|                                        |          |           |                                                                   |
| -------------------------------------- | -------- | --------- | ----------------------------------------------------------------- |
| Table                                  | Priority | Frequency | Notes                                                             |
| `wp_blomstra_index_history`            | High     | Weekly    | Historical snapshots – critical for frontend history              |
| `wp_blomstra_alerts`                   | Medium   | Weekly    | Alert records – audit trail                                       |
| `wp_blomstra_historical_data`          | Medium   | Weekly    | Historical data cache – can be regenerated                        |
| `wp_blomstra_cache_jobs`               | Low      | Monthly   | Job status – can be regenerated                                   |
| `wp_options` (blomstra\_\* / sivi\_\*) | High     | Weekly    | Composite data, pillar data, credentials, configuration, pointers |


### **10.2 Backup Commands** 

Options:

    sql
    SELECT option_name, option_value FROM wp_options 
    WHERE option_name LIKE 'blomstra_%' OR option_name LIKE 'sivi_%' 
    INTO OUTFILE '/tmp/blomstra_options_backup.sql';

Custom Tables:

    bash
    mysqldump -u user -p database \
        wp_blomstra_index_history \
        wp_blomstra_alerts \
        wp_blomstra_historical_data \
        wp_blomstra_cache_jobs \
        > blomstra_tables_backup.sql


### **10.3 Restore Commands** 

    sql
    -- Restore options (be careful with conflicts)
    UPDATE wp_options SET option_value = '{backup_json}' WHERE option_name = 'sivi_composite_index';

    -- Restore tables
    mysql -u user -p database < blomstra_tables_backup.sql;

***


## **11. Open Questions** 

\[D] These are database-related decisions that require explicit resolution:

|   |                                                                                           |                                   |
| - | ----------------------------------------------------------------------------------------- | --------------------------------- |
| # | Question                                                                                  | Notes                             |
| 1 | Historical data retention – Should `wp_blomstra_historical_data` have a retention policy? | Currently permanent               |
| 2 | Index history retention – Should `wp_blomstra_index_history` be trimmed?                  | Currently permanent               |
| 3 | Alert retention – `retention_days` applies only to alert rows, not system logs            | System logs persist indefinitely  |
| 4 | Option autoload – Should large options be set to `autoload = 'no'`?                       | Currently set to `false` for most |
| 5 | Transient fallback – What happens when transients are evicted by object cache?            | Should fall back to API fetch     |
| 6 | Indexing – Are there missing indexes that would improve query performance?                | Unknown                           |

***


## **12. Corrections to Prior Documentation** 

\[D] The following corrections are made from prior documentation:

|                                          |                                                                                     |                          |
| ---------------------------------------- | ----------------------------------------------------------------------------------- | ------------------------ |
| Prior Claim                              | Correction                                                                          | Source                   |
| No database schema documentation existed | This document is the first dedicated database schema reference                      | New document             |
| Options and transients were conflated    | Distinction between persistent options and expiring transients is now clear         | Code inspection          |
| Backfill storage was undocumented        | `wp_blomstra_historical_data` and `wp_blomstra_cache_jobs` are now fully documented | Table schemas            |
| No migration documentation existed       | Table creation and column migrations are now documented                             | `admin_init` hooks       |
| No backup guidance existed               | Backup/restore commands are now documented                                          | Operational requirements |

***


## **End of Database Specification** 

Status: CANONICAL\
Next steps: All ten documents are now complete. The documentation system is ready for final review and verification.

***


## **Complete Document List** 

|    |                             |                               |           |
| -- | --------------------------- | ----------------------------- | --------- |
| #  | Document                    | Purpose                       | Status    |
| 1  | `BLOMSTRA-SPECIFICATION.md` | Master specification          | CANONICAL |
| 2  | `AUDIT.md`                  | Specification verification    | CANONICAL |
| 3  | `SIVI.md`                   | SIVI index specification      | CANONICAL |
| 4  | `OPERATIONS.md`             | Production runbook            | CANONICAL |
| 5  | `FRONTEND.md`               | Frontend engine specification | CANONICAL |
| 6  | `DATA.md`                   | Reference data layer          | CANONICAL |
| 7  | `API.md`                    | REST API contract             | CANONICAL |
| 8  | `DEVELOPMENT.md`            | Development standards         | CANONICAL |
| 9  | `ARCHITECTURE.md`           | System architecture           | CANONICAL |
| 10 | `DATABASE.md`               | Database schema               | CANONICAL |

***

_This document is part of the Blomstra Insights canonical documentation system. Supersedes all pre‑unification database documentation._
