# Shared Utilities Layer — Implementation Guide
> **Tier:** T3 — Implementation
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** `src/shared/blomstra-index-utilities.php` (v1.1.3)
> **Last updated:** 2026-08-26
> **SSOT For:** Safe extraction, percentile computation, source tracking, statistical functions, partial-rank projection
> **Depends on:** `02-contracts.md` (T2 C1 raw data shape, C2 pillar data shape)

---

## 1. Module Overview

`blomstra-index-utilities.php` is L2. It operates on pure PHP arrays and scalars — no WordPress dependencies, no `get_option()`, no transients. It is testable outside WordPress and portable to other platforms.

**Design principle:** Every function is **idempotent** and **pure** (no side effects) unless explicitly documented otherwise.

---

## 2. Static Country Classifications

### 2.1 `blomstra_is_landlocked($iso3)`

Checks if a country is landlocked.

**Algorithm:**
1. Check `blomstra_landlocked_override` option first (admin-updated list).
2. Fall back to `BLOMSTRA_LANDLOCKED_ISO3` constant (44 countries, hardcoded).

**Returns:** `bool`

**Source:** List manually maintained against UN OHRLLS data. Last verified date stored in `BLOMSTRA_LANDLOCKED_SOURCE_DATE`.

### 2.2 `blomstra_is_stale($pillar, $threshold = null)`

Checks if a data pillar or the landlocked list is stale.

**Default thresholds:**

| Pillar | Threshold |
|---|---|
| `wb_indicators` | 7 days |
| `eia` | 7 days |
| `hhi` | 7 days |
| `maritime` | 7 days |
| `imf` | 7 days |
| `countries` | 30 days |
| `reporters` | 30 days |
| `landlocked` | 6 months |

**Returns:** `bool`

---

## 3. Safe Data Extraction

### 3.1 `blomstra_safe_numeric($value, $default = null)`

Safely extracts a numeric value. Replaces the dangerous `empty()` pattern that misses `0.0`.

```php
// DANGEROUS — misses 0.0:
if (empty($val)) { /* ... */ }

// SAFE:
$num = blomstra_safe_numeric($val);
```

**Returns:** `float|null`

### 3.2 `blomstra_safe_string($value, $default = null)`

Safely extracts a non-empty string.

**Returns:** `string|null`

### 3.3 `blomstra_safe_array_get($array, $key, $subkey = null, $default = null)`

Safely retrieves a nested array value.

```php
$val = blomstra_safe_array_get($data, 'USA', 'value', 0.0);
```

**Returns:** `mixed`

---

## 4. Timeseries Sanitization

### 4.1 `blomstra_sanitize_timeseries($data, $min_obs = 2, $min_span = 1)`

Cleans a year → value timeseries.

**Algorithm:**
1. Filter non-numeric values.
2. Check minimum observations (`$min_obs`).
3. Sort chronologically.
4. Check year span (`newest - oldest >= $min_span`).

**Returns:** Clean, sorted array or empty array if constraints fail.

### 4.2 `blomstra_timeseries_bounds($data)`

Extracts boundary values from a sanitized timeseries.

**Returns:**
```php
array(
    'oldest_year'  => 2015,
    'newest_year'  => 2024,
    'oldest_value' => 2.1,
    'newest_value' => 3.5,
    'span'         => 9,
    'observations' => 10,
)
```

### 4.3 `blomstra_compute_cagr($data)`

Compound Annual Growth Rate from a timeseries.

**Formula:** `((newest / oldest) ^ (1 / span)) - 1`

**Returns:** `float|null` (percentage)

### 4.4 `blomstra_compute_cagr_from_values($oldest, $newest, $span)`

CAGR from explicit values.

**Returns:** `float|null`

### 4.5 `blomstra_compute_stddev($values, $sample = true)`

Standard deviation with population vs sample clarity.

**Parameters:**
| Param | Type | Default | Description |
|---|---|---|---|
| `$values` | array | — | Numeric values |
| `$sample` | bool | `true` | Use sample stddev (`n-1`) instead of population (`n`) |

**Returns:** `float|null`

---

## 5. Per-Indicator Source Tracking

### 5.1 `blomstra_track_source(&$sources, $iso3, $indicator, $source, $scope = null, $year = null)`

Records provenance for an indicator value.

```php
$sources = array();
blomstra_track_source($sources, 'USA', 'gov_debt', 'IMF_WEO', 'general_gov', '2023');
// $sources['USA']['gov_debt'] = array('source' => 'IMF_WEO', 'scope' => 'general_gov', 'year' => '2023');
```

### 5.2 `blomstra_pillar_source_summary($sources, $iso3, $indicators)`

Determines the dominant source for a pillar.

**Returns:**
```php
array(
    'primary_source' => 'IMF_WEO',
    'primary_scope'  => 'general_gov',
    'scope_mixed'    => false,
    'breakdown'      => array('gov_debt' => array('source' => 'IMF_WEO', ...)),
    'source_counts'  => array('IMF_WEO' => 2, 'WB_WDI' => 1),
)
```

---

## 6. Definition Validation

### 6.1 `blomstra_validate_pillar_thresholds($defs, $engine)`

Validates that pillar definitions match engine thresholds.

**Checks:**
- Pillar exists in both defs and engine.
- `min_required` matches.
- `min_weight` matches.
- Indicator weights sum to ~100%.

**Returns:**
```php
array(
    'valid'      => true,
    'mismatches' => array(),
)
```

Called on `init` by every index. If invalid and `WP_DEBUG` is true, the index calls `wp_die()`.

---

## 7. Percentile Computation with Outlier Handling

### 7.1 `blomstra_winsorize($values, $winsor_pct = 0.0)`

Caps values outside a symmetric winsorization threshold.

**Parameters:**
| Param | Type | Default | Description |
|---|---|---|---|
| `$values` | array | — | `iso3 => numeric_value` |
| `$winsor_pct` | float | `0.0` | Winsorization level (`0.01` = 1st/99th percentile) |

**Returns:** Same keys, values capped where `winsor_pct > 0` and `n >= 10`.

**Algorithm:**
1. Sort values.
2. Calculate cutoff index: `max(1, round(n × winsor_pct))`.
3. Set lower bound = value at cutoff index.
4. Set upper bound = value at `n - cutoff` index.
5. Cap all values to `[lower_bound, upper_bound]`.

### 7.2 `blomstra_compute_percentile_ranks_safe($values, $winsor_pct = 0.0)`

Computes percentile ranks with optional winsorization.

**Convention:** High value = high percentile.

**Algorithm:**
1. Filter non-numeric values.
2. Apply winsorization if requested.
3. Sort ascending.
4. Assign ranks with **average rank for ties**.
5. Convert to percentile: `((rank - 0.5) / n) × 100`.

**Returns:** `array('USA' => 45.23, ...)`

**Features:**
- Handles ties via average rank.
- Never returns null for valid inputs.
- Safe for single-country arrays (returns 50.0).

---

## 8. Fallback Merging with Provenance

### 8.1 `blomstra_merge_with_fallback($primary, $fallback, &$sources, $indicator, $primary_label, $fallback_label, $primary_scope = null, $fallback_scope = null)`

Merges primary and fallback datasets with full provenance tracking.

**Algorithm:**
1. Copy all valid primary values to result.
2. Track primary source for each.
3. For each fallback value, skip if already in result.
4. Copy valid fallback values, track fallback source.

**Returns:** Merged `iso3 => value` array. Updates `$sources` by reference.

### 8.2 `blomstra_merge_priority_layers($layers, &$sources, $indicator)`

Merges multiple fallback layers in priority order.

**Parameters:**
| Param | Type | Description |
|---|---|---|
| `$layers` | array | Array of `array('data' => [...], 'label' => '...', 'scope' => '...')` |
| `$sources` | array | Reference — source tracking array |
| `$indicator` | string | Indicator name |

**Returns:** Merged `iso3 => value` array.

---

## 9. Data Quality Flags

### 9.1 `blomstra_data_quality_flag($sources, $iso3, $indicator, $current_year = null)`

Generates a quality flag for a country-indicator pair.

**Returns:**
```php
array(
    'available'       => true,
    'staleness_years' => 2,      // null if no year info
    'source'          => 'IMF_WEO',
    'scope'           => 'general_gov',
    'quality'         => 'aged',  // 'good' | 'aged' | 'stale' | 'missing'
    'year'            => 2023,
)
```

**Quality levels:**

| Level | Condition |
|---|---|
| `good` | Data available, staleness ≤ 1 year |
| `aged` | Data available, staleness 2–3 years |
| `stale` | Data available, staleness > 3 years |
| `missing` | No data available |

### 9.2 `blomstra_pillar_quality_score($sources, $iso3, $indicators, $current_year = null)`

Computes a composite quality score for a country's pillar.

**Returns:**
```php
array(
    'coverage_pct'    => 75.0,    // % of indicators present
    'avg_staleness'   => 1.5,     // average years stale
    'quality_counts'  => array('good' => 2, 'aged' => 1, 'stale' => 0, 'missing' => 1),
    'indicators'      => array('gov_debt', 'gov_balance', 'debt_trajectory'),
)
```

---

## 10. Statistical / Research-Credibility Layer

### 10.1 Spearman Rank Correlation

#### `blomstra_spearman_correlation($x, $y)`

Spearman's rho with correct tied-value (fractional mid-rank) handling.

**Parameters:**
| Param | Type | Description |
|---|---|---|
| `$x` | array | First series of values |
| `$y` | array | Second series, same length/order |

**Returns:** `float` (Spearman's rho, or 0 if fewer than 2 paired observations)

**Algorithm:**
1. Assign fractional mid-ranks to each series (handling ties).
2. Compute sum of squared rank differences: `Σd²`.
3. Apply formula: `ρ = 1 - (6Σd²) / (n(n²-1))`.

**Use case:** Correlating index scores with external benchmarks (e.g., WEF GCI, OECD country risk).

### 10.2 Cronbach's Alpha

#### `blomstra_cronbach_alpha($indicator_matrix)`

Internal consistency reliability statistic.

**Parameters:**
| Param | Type | Description |
|---|---|---|
| `$indicator_matrix` | array | Array of indicators; each element is an array of that indicator's values, one per country, in the SAME country order across all indicators |

**Returns:** `float|null` (Alpha rounded to 3 decimals, or null if matrix is too small, not rectangular, or has zero total variance)

**Interpretation:**

| α | Interpretation |
|---|---|
| α ≥ 0.9 | Excellent |
| 0.8 ≤ α < 0.9 | Good |
| 0.7 ≤ α < 0.8 | Acceptable |
| 0.6 ≤ α < 0.7 | Questionable |
| α < 0.6 | Poor |

**Use case:** Validating that indicators within a pillar measure the same underlying construct.

### 10.3 Weight-Perturbation Sensitivity Interval

#### `blomstra_bootstrap_ci($pillar_values_by_country, $pillar_weights, $n_bootstrap = 1000, $ci_level = 0.95)`

**Methodological honesty note:** This is a **robustness/sensitivity interval** built by resampling pillar weights within ±10%, NOT a classical statistical bootstrap over indicator-level measurement error. Label it as "95% weight-sensitivity interval", not "95% confidence interval".

**Parameters:**
| Param | Type | Default | Description |
|---|---|---|---|
| `$pillar_values_by_country` | array | — | `iso3 => array(pillar_name => percentile_value)` |
| `$pillar_weights` | array | — | `pillar_name => weight` |
| `$n_bootstrap` | int | `1000` | Number of resamples |
| `$ci_level` | float | `0.95` | CI level (e.g., 0.95 for 95%) |

**Returns:**
```php
array(
    'USA' => array('point' => 42.35, 'ci_low' => 38.12, 'ci_high' => 46.78),
    ...
)
```

**Algorithm:**
1. Compute point estimate using original weights.
2. For each bootstrap iteration:
   a. Perturb each weight by `factor = 1 + (rand(-1000, 1000) / 10000)` (±10%).
   b. Ensure weights remain non-negative.
   c. Compute composite score with perturbed weights.
3. Sort all draws per country.
4. Extract percentiles at `(1-ci_level)/2` and `1 - (1-ci_level)/2`.

**Use case:** Testing whether composite scores are robust to small changes in pillar weights.

### 10.4 Benchmark Correlation

#### `blomstra_benchmark_correlate($index_scores, $benchmark_scores)`

Correlates this index's scores with an external comparator.

**Parameters:**
| Param | Type | Description |
|---|---|---|
| `$index_scores` | array | `iso3 => this index's score` |
| `$benchmark_scores` | array | `iso3 => external comparator's score` |

**Returns:**
```php
array(
    'n'   => 150,     // overlapping countries
    'rho' => 0.723,   // Spearman's rho
)
```

**Returns null** if fewer than 5 overlapping countries.

**Use case:** Validating index construct validity against established indices (e.g., WEF GCI, World Bank CPIA, IMF Vulnerability Exercise).

---

## 11. Partial-Rank Composite Projection

### 11.1 `blomstra_project_partial_rank_composite($known_pillar_values, $missing_pillar, $injected_values_by_point, $pillar_weights)`

Computes hypothetical composite scores at OECD/JRC injection points for a partial-coverage country.

**Parameters:**
| Param | Type | Description |
|---|---|---|
| `$known_pillar_values` | array | `pillar_name => percentile` for all pillars EXCEPT `$missing_pillar` |
| `$missing_pillar` | string | Name of the missing pillar |
| `$injected_values_by_point` | array | `injection_point => hypothetical_value` |
| `$pillar_weights` | array | `pillar_name => weight` for ALL pillars |

**Returns:** `array(0 => composite_at_0pct, 10 => composite_at_10pct, ...)`

**Algorithm:**
1. Compute weighted sum of known pillars.
2. For each injection point, add `(injected_value × missing_weight) / total_weight`.
3. Return hypothetical composites on the same 0–100 scale.

**Use case:** When a country is missing one pillar, this shows what its composite score would be if that pillar were at the 0th, 10th, 50th, 90th, or 100th percentile. This creates the **projected rank range** displayed in the frontend for partial-coverage countries.

**Example:**
```php
$known = array('energy' => 65.0, 'maritime' => 40.0);
$missing = 'hhi';
$injected = array(0 => 0, 10 => 10, 50 => 50, 90 => 90, 100 => 100);
$weights = array('energy' => 33.33, 'hhi' => 33.33, 'maritime' => 33.34);

$projected = blomstra_project_partial_rank_composite($known, $missing, $injected, $weights);
// Returns: array(0 => 35.0, 10 => 38.3, 50 => 51.7, 90 => 65.0, 100 => 68.3)
```
