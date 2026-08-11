# Reference Data API

> **File:** `src/shared/blomstra-index-utilities.php`  
> **Standard:** BMS-1.0.0

---

## Overview

The Reference Data layer is a shared PHP snippet loaded before any index backend. It provides:

- Global country lists
- Batch API fetchers (World Bank, IMF, EIA, Comtrade)
- Mathematical utilities (percentiles, standard deviation, CAGR)
- Source tracking and quality scoring
- Rank builders (full and partial index)
- Validation functions

This document describes the public API of the shared utilities.

---

## Country Lists

### `blomstra_get_global_country_list()`

Returns all World Bank member countries as `iso3 => name`.

**Returns:** `array` — e.g., `array('USA' => 'United States', 'CHN' => 'China', ...)`

**Cache:** Transient (`blomstra_country_list`) with 7-day TTL.

**Fallback:** If WB API fails, returns a hardcoded minimal list of major economies.

---

## Batch Fetchers

### `blomstra_fetch_wb_indicator_batch( $code, $source = null, $force = false )`

Fetches a World Bank indicator for all countries.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$code` | string | required | WB indicator code (e.g., `NY.GDP.MKTP.KD.ZG`) |
| `$source` | int | null | WB source ID (e.g., `3` for WGI) |
| `$force` | bool | false | Bypass cache and refetch |

**Returns:** `array( 'USA' => array('value' => 2.5, 'year' => 2023, 'source' => 'WB_WDI'), ... )`

**Cache:** Transient with 24-hour TTL.

---

### `blomstra_fetch_imf_indicator_batch( $code, $force = false )`

Fetches IMF WEO historical data.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$code` | string | required | IMF indicator code (e.g., `GGXWDG_NGDP`) |
| `$force` | bool | false | Bypass cache |

**Returns:** Same shape as WB batch.

---

### `blomstra_fetch_imf_forecast_batch( $code, $horizon = 1, $force = false )`

Fetches IMF WEO forecast data.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$code` | string | required | IMF indicator code |
| `$horizon` | int | 1 | Years ahead (1 = next year) |
| `$force` | bool | false | Bypass cache |

**Returns:** Same shape as historical batch.

---

### `blomstra_refresh_eia_raw_data( $iso3_list )`

Fetches EIA energy consumption and production data.

| Parameter | Type | Description |
|---|---|---|
| `$iso3_list` | array | Array of ISO3 country codes |

**Returns:**
```php
array(
    'consumption' => array( '4411' => array('USA' => 500.2, ...), ... ),
    'production'  => array( '4411' => array('USA' => 450.1, ...), ... ),
)
```

**Fuel IDs:** `4411` (coal), `4413` (gas), `4415` (petroleum), `4417` (nuclear), `4418` (renewables)

---

### `blomstra_get_eia_raw_data()`

Returns cached EIA data from the last `blomstra_refresh_eia_raw_data()` call.

**Storage:** Option key `blomstra_eia_raw_data`

---

### `blomstra_refresh_comtrade_hhi_data( $year = null, $iso3_list = null )`

Fetches UN Comtrade import data and computes HHI values.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$year` | int | last year | Target year for trade data |
| `$iso3_list` | array | all countries | Countries to compute |

**Side effects:**
- Updates `blomstra_comtrade_hhi_data` option with results
- Updates `blomstra_hhi_refresh_summary` with run diagnostics

**Returns:** `array( 'USA' => array('value' => 1250, 'year' => 2023, 'source' => 'Comtrade'), ... )`

---

### `blomstra_get_comtrade_hhi_data()`

Returns cached HHI data.

**Storage:** Option key `blomstra_comtrade_hhi_data`

---

## Math Utilities

### `blomstra_compute_percentile_ranks_safe( $values, $winsorize = 0.0 )`

Computes percentile ranks for an associative array.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$values` | array | required | `iso3 => raw_value` |
| `$winsorize` | float | 0.0 | Fraction to trim at tails (0.01 = 1%) |

**Returns:** `array( 'USA' => 45.23, ... )`

**Features:**
- Handles ties via average rank
- Safe for single-element arrays (returns 50.0)
- Never returns null

---

### `blomstra_compute_stddev( $values, $sample = true )`

Standard deviation.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$values` | array | required | Numeric array |
| `$sample` | bool | true | Use sample stddev (N-1) |

**Returns:** float or null if < 2 values

---

### `blomstra_compute_cagr( $timeseries )`

Compound annual growth rate.

| Parameter | Type | Description |
|---|---|---|
| `$timeseries` | array | `year => value` |

**Returns:** float percentage (e.g., `5.2` for 5.2% annual growth) or null

---

### `blomstra_safe_numeric( $value )`

Safely converts a value to float.

**Returns:** float or null if not numeric

---

### `blomstra_sanitize_timeseries( $years, $min_obs = 4, $max_gap = 2 )`

Cleans a time series.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$years` | array | required | `year => value` |
| `$min_obs` | int | 4 | Minimum observations required |
| `$max_gap` | int | 2 | Maximum allowed year gap |

**Returns:** Filtered array or empty array

---

## Source Tracking

### `blomstra_track_source( &$sources, $iso3, $indicator, $source_name, $scope, $year = null )`

Records provenance.

```php
$sources = array();
blomstra_track_source( $sources, 'USA', 'gov_debt', 'IMF_WEO', 'general_gov', '2023' );
```

**Updates:** `$sources[$iso3][$indicator][] = array('source' => 'IMF_WEO', 'scope' => 'general_gov', 'year' => '2023')`

---

### `blomstra_pillar_quality_score( $all_sources, $iso3, $indicators )`

Quality score (0–3).

| Parameter | Type | Description |
|---|---|---|
| `$all_sources` | array | Merged sources from all pillars |
| `$iso3` | string | Country code |
| `$indicators` | array | Indicator names in this pillar |

**Returns:** int (0–3)

---

### `blomstra_pillar_source_summary( $sources, $iso3, $indicators )`

Structured breakdown.

**Returns:**
```php
array(
    'breakdown' => array(
        'gov_debt' => array('source' => 'IMF_WEO', 'scope' => 'general_gov', 'year' => '2023'),
    ),
    'scope_mixed' => false,
)
```

---

## Rank Builders

### `blomstra_rank_in_full_index( $score, $full_composites_sorted )`

1-based rank of a score within sorted full-index array.

**Returns:** int

---

### `blomstra_build_full_rank_display( $rank )`

Rank display object for full-index country.

**Returns:**
```php
array(
    'is_definitive' => true,
    'best_estimate' => $rank,
    'range_80_low' => $rank,
    'range_80_high' => $rank,
    'theoretical_low' => $rank,
    'theoretical_high' => $rank,
    'string_format' => '#' . $rank,
)
```

---

### `blomstra_build_partial_rank_display( $ranks_by_injection )`

Rank display object for partial-index country.

| Parameter | Type | Description |
|---|---|---|
| `$ranks_by_injection` | array | `array(0 => rank, 10 => rank, 50 => rank, 90 => rank, 100 => rank)` |

**Returns:** Partial rank display object

---

## Validation

### `blomstra_validate_pillar_thresholds( $defs, $weights )`

Validates consistency between pillar definitions and weight definitions.

**Returns:**
```php
array(
    'valid' => true,
    'mismatches' => array(),
)
```

**Checks:**
- Every indicator in `$defs` exists in `$weights`
- `min_required` ≤ number of indicators
- `min_weight` ≤ sum of indicator weights

---

## Merge Utilities

### `blomstra_merge_with_fallback( $primary, $fallback, &$sources, $indicator, $primary_name, $fallback_name, $primary_scope, $fallback_scope )`

Merges primary and fallback data with source tracking.

**Returns:** Merged values array

**Side effect:** Updates `$sources` to document which source was used per country.

---

## Snapshot

### `blomstra_index_snapshot_save( $index_key, $snapshot )`

Saves lightweight historical snapshot.

| Parameter | Type | Description |
|---|---|---|
| `$index_key` | string | Short key (`'seri'`, `'sivi'`) |
| `$snapshot` | array | `iso3 => array('composite_score', 'rank', 'coverage_type', ...)` |

**Storage:** Option key `blomstra_snapshot_{$index_key}`

---

## Cron Status

### `blomstra_update_cron_status( $key, $status, $message, $count = 0 )`

Updates shared cron log.

**Storage:** Option key `blomstra_cron_status`

**Shape:**
```php
array(
    'seri' => array('status' => 'success', 'last_run' => '...', 'message' => '182 countries scored.', 'count' => 182),
    'sivi' => array('status' => 'success', 'last_run' => '...', 'message' => '...', 'count' => ...),
)
```
