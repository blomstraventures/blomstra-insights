# Reference Data Functions

> **File:** `src/shared/blomstra-index-utilities.php`
> **Standard:** BMS-1.0.0

---

## Country Lists

### `blomstra_get_global_country_list()`

Returns an associative array of `iso3 => name` for all World Bank member countries (excluding aggregates like WLD).

**Returns:** `array( 'USA' => 'United States', ... )`

**Fallback:** If the shared utility is not available, each index has its own `sivi_get_global_country_list_fallback()` that queries the WB API directly.

---

## Batch Fetchers

### `blomstra_fetch_wb_indicator_batch( $code, $source = null, $force = false )`

Fetches a single World Bank indicator for all countries via the shared cache.

| Param | Type | Description |
|---|---|---|
| `$code` | string | WB indicator code (e.g., `NY.GDP.MKTP.KD.ZG`) |
| `$source` | int | WB source ID (e.g., `3` for WGI) |
| `$force` | bool | Bypass cache and refetch |

**Returns:** `array( 'USA' => array('value' => 2.5, 'year' => 2023, 'source' => 'WB_WDI'), ... )`

---

### `blomstra_fetch_imf_indicator_batch( $code, $force = false )`

Fetches IMF WEO historical data for all countries.

| Param | Type | Description |
|---|---|---|
| `$code` | string | IMF indicator code (e.g., `GGXWDG_NGDP`) |
| `$force` | bool | Bypass cache |

**Returns:** `array( 'USA' => array('value' => 120.5, 'year' => '2023', 'source' => 'IMF_WEO'), ... )`

---

### `blomstra_fetch_imf_forecast_batch( $code, $horizon = 1, $force = false )`

Fetches IMF WEO forecast data.

| Param | Type | Description |
|---|---|---|
| `$code` | string | IMF indicator code |
| `$horizon` | int | Years ahead (1 = next year) |
| `$force` | bool | Bypass cache |

**Returns:** Same shape as historical batch.

---

### `blomstra_refresh_eia_raw_data( $iso3_list )`

Fetches EIA energy data (consumption + production) for a list of countries.

| Param | Type | Description |
|---|---|---|
| `$iso3_list` | array | Array of ISO3 codes |

**Returns:** `array( 'consumption' => array(fuel_id => array(iso3 => value)), 'production' => array(...) )`

---

### `blomstra_refresh_comtrade_hhi_data( $year = null, $iso3_list = null )`

Fetches UN Comtrade import data and computes HHI values.

| Param | Type | Description |
|---|---|---|
| `$year` | int | Target year (defaults to last year) |
| `$iso3_list` | array | Target countries |

**Returns:** `array( 'USA' => array('value' => 1250, 'year' => 2023, 'source' => 'Comtrade'), ... )`

**Side effect:** Updates `blomstra_hhi_refresh_summary` option with run diagnostics.

---

## Math Utilities

### `blomstra_compute_percentile_ranks_safe( $values, $winsorize = 0.0 )`

Computes percentile ranks for an associative array of `iso3 => value`.

| Param | Type | Description |
|---|---|---|
| `$values` | array | `iso3 => raw_value` |
| `$winsorize` | float | Fraction to winsorize at tails (0.0 = none, 0.01 = 1%) |

**Returns:** `array( 'USA' => 45.23, ... )`

**Features:**
- Handles ties via average rank
- Never returns null for valid inputs
- Safe for single-country arrays (returns 50.0)

---

### `blomstra_compute_stddev( $values, $sample = true )`

Standard deviation.

---

### `blomstra_compute_cagr( $timeseries )`

Compound annual growth rate for an associative array of `year => value`.

**Returns:** float or null if insufficient data.

---

### `blomstra_safe_numeric( $value )`

Safely converts a value to float, returning null for non-numeric inputs.

---

### `blomstra_sanitize_timeseries( $years, $min_obs = 4, $max_gap = 2 )`

Cleans a time series by removing gaps and ensuring minimum observations.

| Param | Type | Description |
|---|---|---|
| `$years` | array | `year => value` |
| `$min_obs` | int | Minimum observations required |
| `$max_gap` | int | Maximum allowed gap between consecutive years |

**Returns:** Filtered array or empty array if invalid.

---

## Source Tracking

### `blomstra_track_source( &$sources, $iso3, $indicator, $source_name, $scope, $year = null )`

Records provenance for an indicator value.

```php
$sources = array();
blomstra_track_source( $sources, 'USA', 'gov_debt', 'IMF_WEO', 'general_gov', '2023' );
// $sources['USA']['gov_debt'] = array( array('source' => 'IMF_WEO', 'scope' => 'general_gov', 'year' => '2023') );
```

---

### `blomstra_pillar_quality_score( $all_sources, $iso3, $indicators )`

Computes a 0-3 quality score for a pillar based on source freshness and primary vs fallback usage.

| Param | Type | Description |
|---|---|---|
| `$all_sources` | array | Merged sources array from all pillars |
| `$iso3` | string | Country code |
| `$indicators` | array | List of indicator names in this pillar |

**Returns:** `int` (0-3)

**Scoring:**
- 3: All indicators from primary sources, recent years
- 2: Mixed primary and fallback
- 1: Mostly fallback or old data
- 0: No data

---

### `blomstra_pillar_source_summary( $sources, $iso3, $indicators )`

Returns a structured breakdown of which source provided each indicator.

**Returns:** `array( 'breakdown' => array(...), 'scope_mixed' => bool )`

---

## Rank Builders

### `blomstra_rank_in_full_index( $score, $full_composites_sorted )`

Returns the 1-based rank of a score within the sorted full-index array.

---

### `blomstra_build_full_rank_display( $rank )`

Returns a rank display object for a full-index country.

**Returns:** `array( 'is_definitive' => true, 'best_estimate' => $rank, ... )`

---

### `blomstra_build_partial_rank_display( $ranks_by_injection )`

Returns a rank display object for a partial-index country.

**Parameter:** `array( 0 => rank_at_0pct, 10 => rank_at_10pct, 50 => ..., 90 => ..., 100 => ... )`

**Returns:** `array( 'is_definitive' => false, 'best_estimate' => ..., 'range_80_low' => ..., ... )`

---

## Validation

### `blomstra_validate_pillar_thresholds( $defs, $weights )`

Validates that pillar definition thresholds are consistent with weight definitions.

**Returns:** `array( 'valid' => bool, 'mismatches' => array(...) )`

Called on `init` by every index. If invalid and `WP_DEBUG` is true, the index calls `wp_die()`.

---

## Merge Utilities

### `blomstra_merge_with_fallback( $primary, $fallback, &$sources, $indicator, $primary_name, $fallback_name, $primary_scope, $fallback_scope )`

Merges two data arrays with fallback tracking.

**Returns:** Merged values array. Updates `$sources` to track which source was used per country.

---

## Snapshot

### `blomstra_index_snapshot_save( $index_key, $snapshot )`

Saves a lightweight snapshot of the current composite for historical comparison.

| Param | Type | Description |
|---|---|---|
| `$index_key` | string | Short key (e.g., `'seri'`, `'sivi'`) |
| `$snapshot` | array | `iso3 => array('composite_score', 'rank', 'coverage_type', ...)` |

---

## Cron Status

### `blomstra_update_cron_status( $key, $status, $message, $count = 0 )`

Updates the shared cron status log.

**Stores in:** `blomstra_cron_status` option.

**Shape:** `array( 'seri' => array('status' => 'success', 'last_run' => '...', 'message' => '...', 'count' => 182), ... )`
