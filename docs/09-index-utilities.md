# Index Utilities

> **Layer A: Platform Infrastructure — Data Integrity & Processing**
>
> This document describes the shared processing layer that sits between Reference Data (raw API collection) and individual index backends (methodology-specific transformations).
>
> For the data ingestion layer, see [`08-reference-data-functions.md`](08-reference-data-functions.md).
> For the research standards that govern how these utilities are used, see [`11-engineering-research-standards.md`](11-engineering-research-standards.md).

---

## Architecture: Two-Layer Shared Stack

Blomstra uses two distinct shared layers:

| Layer | File | Responsibility | When data moves through it |
|-------|------|----------------|---------------------------|
| **Ingestion** | `global-reference-data.php` | Fetch, cache, paginate, retry, rate-limit | API → WordPress option/transient |
| **Integrity** | `index-utilities.php` | Validate, sanitize, transform, track provenance, detect drift | Raw option → Index builder |

**Rule:** Ingestion never applies methodology-specific logic. Integrity never makes API calls.

**Analogy:** Ingestion is the shipping dock. Integrity is quality control before the assembly line.

---

## The Four Usage Rules

Every index backend MUST obey these rules. Violations are treated as bugs, not style preferences.

| # | Rule | Violation | Consequence |
|---|------|-----------|-------------|
| 1 | **Never use `empty()` on numeric data.** | `empty(0.0)` returns `true` | Valid zeros (0% inflation, balanced budget, 0 debt) are treated as missing |
| 2 | **Never assume API returns chronological order.** | `end($years) - reset($years)` | Trajectory signs reverse when API returns descending years |
| 3 | **Never merge fallback data without tracking.** | Manual `if (!isset) $val = $fallback` | Measurement non-equivalence; mixed sources invisible to consumers |
| 4 | **Never let defs and engine thresholds drift.** | `min_required: 3` in defs, `>= 2` in builder | Validation and execution disagree; partial coverage logic becomes unpredictable |

---

## Function Reference

### 1. Safe Data Extraction

#### `blomstra_safe_numeric($value, $default = null)`

Extract a numeric value safely. Replaces the dangerous `empty()` pattern on numeric fields.

**Parameters:**
- `$value` — Raw value from API or cache.
- `$default` — Value to return if null, non-numeric, or not set. Default `null`.

**Returns:** `float|null`

**Example:**
```php
// WRONG — treats 0.0 as missing
if ( empty( $row['value'] ) ) { $val = null; }

// CORRECT
$val = blomstra_safe_numeric( $row['value'] );
if ( $val === null ) { /* genuinely missing */ }
```

**Used by:** All pillar fetchers, composite builders, percentile loops.

**BMS linkage:** BMS-001 (structural zeros), BMS-002 (data-state taxonomy).

---

#### `blomstra_safe_string($value, $default = null)`

Extract a non-empty string safely.

**Returns:** `string|null`

---

#### `blomstra_safe_array_get($array, $key, $subkey = null, $default = null)`

Safely retrieve a nested array value without triggering undefined-index notices.

**Example:**
```php
$year = blomstra_safe_array_get( $row, 'date', null, null );
$source = blomstra_safe_array_get( $meta, 'provenance', 'source', 'unknown' );
```

---

### 2. Timeseries Sanitization

#### `blomstra_sanitize_timeseries($data, $min_obs = 2, $min_span = 1)`

Clean and validate a year => value timeseries.

**What it does:**
1. Removes non-numeric values
2. Sorts chronologically (oldest → newest) by year key
3. Enforces minimum observation count
4. Enforces minimum year span

**Parameters:**
- `$data` — Raw array `[ year => value ]`.
- `$min_obs` — Minimum numeric observations required. Default `2`.
- `$min_span` — Minimum year span (`newest - oldest`). Default `1`.

**Returns:** Clean, sorted array. Empty if constraints fail.

**Example:**
```php
$raw = array( 2024 => 45, 2020 => 30, 2022 => 'N/A', 2021 => 32 );
$clean = blomstra_sanitize_timeseries( $raw, 4, 2 );
// Result: [ 2020 => 30, 2021 => 32, 2024 => 45 ]
// 2022 removed (non-numeric), span = 4 (passes)
```

**Critical:** This prevents the trajectory sign-flip bug that occurs when APIs return data in descending year order.

**BMS linkage:** BMS-005 (temporal data standard).

---

#### `blomstra_timeseries_bounds($data)`

Extract boundary values and metadata from a sanitized timeseries.

**Returns:**
```php
array(
    'oldest_year'  => 2020,
    'newest_year'  => 2024,
    'oldest_value' => 30.0,
    'newest_value' => 45.0,
    'span'         => 4,
    'observations' => 3,
)
```

**Returns `null`** if input is empty.

---

#### `blomstra_compute_cagr($data)`

Compute Compound Annual Growth Rate from a sanitized timeseries.

**Formula:** `((newest / oldest) ^ (1 / span)) - 1`

**Returns:** CAGR as percentage, or `null` if:
- Insufficient data
- Oldest value is zero (division by zero)
- Span < 1 year

**Example:**
```php
$debt_ts = array( 2020 => 30, 2021 => 35, 2022 => 42, 2023 => 50 );
$cagr = blomstra_compute_cagr( $debt_ts ); // Returns ~18.3%
```

**Why CAGR instead of absolute difference:**
- A +20pp trajectory at 30% debt = +67% CAGR (catastrophic)
- A +20pp trajectory at 130% debt = +15% CAGR (concerning but manageable)
- Absolute differences treat these as identical risk signals. They are not.

**BMS linkage:** BMS-005 (trajectory normalization).

---

#### `blomstra_compute_cagr_from_values($oldest, $newest, $span)`

Compute CAGR from explicit values when you already have bounds.

**Returns:** `float|null`

---

#### `blomstra_compute_stddev($values, $sample = true)`

Compute standard deviation of a numeric array.

**Parameters:**
- `$values` — Numeric array.
- `$sample` — Use sample stddev (`n-1`) instead of population (`n`). Default `true`.

**Returns:** `float|null` (null if fewer than 2 values).

---

### 3. Per-Indicator Source Tracking

#### `blomstra_track_source(&$sources, $iso3, $indicator, $source, $scope = null, $year = null)`

Record the provenance of every indicator value at the indicator level, not the pillar level.

**Parameters:**
- `$sources` — Reference to source tracking array.
- `$iso3` — Country code.
- `$indicator` — Indicator name (e.g., `'gov_debt'`).
- `$source` — Source label (e.g., `'WB_WDI'`, `'IMF_WEO'`).
- `$scope` — Optional scope label (e.g., `'central_gov'`, `'general_gov'`).
- `$year` — Optional data vintage year.

**Example:**
```php
$sources = array();
blomstra_track_source( $sources, 'USA', 'gov_debt', 'WB_WDI', 'central_gov', '2023' );
blomstra_track_source( $sources, 'USA', 'gov_balance', 'IMF_WEO', 'general_gov', '2024' );
```

**BMS linkage:** BMS-001 (data provenance standard).

---

#### `blomstra_pillar_source_summary($sources, $iso3, $indicators)`

Determine the dominant source, scope, and whether scope is mixed for a pillar.

**Returns:**
```php
array(
    'primary_source' => 'WB_WDI',
    'primary_scope'  => 'central_gov',
    'scope_mixed'    => false,
    'breakdown'      => array(
        'gov_debt'    => array( 'source' => 'WB_WDI', 'scope' => 'central_gov', 'year' => '2023' ),
        'gov_balance' => array( 'source' => 'IMF_WEO', 'scope' => 'general_gov', 'year' => '2024' ),
    ),
    'source_counts'  => array( 'WB_WDI' => 1, 'IMF_WEO' => 1 ),
)
```

**Critical:** `scope_mixed` flags when a pillar combines central government and general government data. This is a measurement non-equivalence warning.

---

### 4. Definition Validation

#### `blomstra_validate_pillar_thresholds($defs, $engine)`

Verify that pillar definitions match engine thresholds at runtime.

**Checks:**
- `min_required` consistency between defs and engine
- `min_weight` consistency between defs and engine
- Indicator weights sum to ~100%

**Returns:**
```php
array(
    'valid'      => false,
    'mismatches' => array(
        array(
            'pillar'    => 'fiscal',
            'field'     => 'min_required',
            'def_value' => 3,
            'eng_value' => 2,
            'issue'     => 'Definition requires 3 indicators, engine allows 2',
        ),
    ),
)
```

**Usage:** Call during index initialization. In dev mode (`WP_DEBUG`), fail hard. In production, log and continue.

**Example:**
```php
function geri_initialize() {
    $validation = blomstra_validate_pillar_thresholds(
        geri_get_pillar_defs(),
        geri_get_pillar_weights()
    );
    if ( ! $validation['valid'] ) {
        foreach ( $validation['mismatches'] as $m ) {
            error_log( 'GERI drift: ' . $m['issue'] );
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            wp_die( 'Pillar definitions inconsistent. Check error log.' );
        }
    }
}
```

**BMS linkage:** BMS-004 (weighting standard).

---

### 5. Percentile Computation with Outlier Handling

#### `blomstra_compute_percentile_ranks_safe($values, $winsor_pct = 0.0)`

Compute percentile ranks with optional winsorization and proper tie handling.

**Parameters:**
- `$values` — Associative array `[ iso3 => numeric_value ]`.
- `$winsor_pct` — Winsorization level. `0.01` = cap at 1st/99th percentile. `0` = none.

**Returns:** `[ iso3 => percentile_0_to_100 ]`

**Winsorization:** Prevents extreme outliers (Lebanon hyperinflation, Venezuela debt) from dominating the entire percentile distribution. Outliers are capped at the specified percentile boundary before ranking.

**Tie handling:** Countries with identical values receive the average of their ranks.

**Example:**
```php
$values = array( 'A' => 10, 'B' => 20, 'C' => 20, 'D' => 1000 );
$pct = blomstra_compute_percentile_ranks_safe( $values, 0.01 );
// Without winsorization, D dominates and compresses A-C into the bottom 1%
// With 1% winsorization, D is capped and A-C spread is preserved
```

**BMS linkage:** BMS-003 (normalization standard).

---

### 6. Fallback Merging with Provenance

#### `blomstra_merge_with_fallback($primary, $fallback, &$sources, $indicator, $primary_label, $fallback_label, $primary_scope = null, $fallback_scope = null)`

Merge primary and fallback datasets with full provenance tracking.

**Rules:**
1. Primary data wins when present.
2. Fallback is applied ONLY where primary is missing.
3. Every value is tracked with its source and scope.
4. Non-numeric values are rejected silently.

**Example:**
```php
$wb_debt  = array( 'USA' => 120.5, 'FRA' => 98.0 );
$imf_debt = array( 'USA' => 125.0, 'DEU' => 65.0 );
$sources  = array();

$merged = blomstra_merge_with_fallback(
    $wb_debt, $imf_debt, $sources, 'gov_debt',
    'WB_WDI', 'IMF_WEO',
    'central_gov', 'general_gov'
);
// Result: USA => 120.5 (WB), FRA => 98.0 (WB), DEU => 65.0 (IMF)
```

**BMS linkage:** BMS-001 (fallback flagging), BMS-002 (missing data).

---

#### `blomstra_merge_priority_layers($layers, &$sources, $indicator)`

Merge multiple fallback layers in priority order.

**Parameters:**
- `$layers` — Array of `[ 'data' => [...], 'label' => '...', 'scope' => '...' ]`.

**Use case:** WB primary → IMF historical fallback → UN estimate fallback.

---

### 7. Data Quality Flags

#### `blomstra_data_quality_flag($sources, $iso3, $indicator, $current_year = null)`

Generate a quality assessment for a single country-indicator pair.

**Returns:**
```php
array(
    'available'       => true,
    'staleness_years' => 2,
    'source'          => 'WB_WDI',
    'scope'           => 'central_gov',
    'quality'         => 'aged',   // good | aged | stale | missing
    'year'            => 2023,
)
```

**Quality thresholds:**
- `good` — data from current or prior year
- `aged` — 2–3 years old
- `stale` — > 3 years old
- `missing` — no data

---

#### `blomstra_pillar_quality_score($sources, $iso3, $indicators, $current_year = null)`

Compute composite quality metrics for a country's pillar.

**Returns:**
```php
array(
    'coverage_pct'     => 66.67,
    'avg_staleness'    => 1.5,
    'quality_counts'   => array( 'good' => 2, 'aged' => 0, 'stale' => 0, 'missing' => 1 ),
    'indicators'       => array(...),
)
```

**Use in API output:** Attach to `data_quality` field for transparency.

---

## Migration Guide for Existing Indices

### GERI v3.5.8 → v4.0.0

| Location | Current (v3.5.8) | v4.0.0 Replacement |
|----------|-----------------|-------------------|
| Null check | `empty($row['value'])` | `blomstra_safe_numeric($row['value']) === null` |
| Trajectory | `$newest - $oldest` | `blomstra_compute_cagr($ts)` with `min_obs=4` |
| Timeseries | Assumes sorted | `blomstra_sanitize_timeseries($raw, 4, 2)` |
| Source tracking | `$raw['fiscal_base_source'] = 'WB'` | `blomstra_track_source($sources, $iso3, 'gov_debt', 'WB_WDI', 'central_gov')` |
| Fiscal merge | Manual `if (!isset)` merge | `blomstra_merge_with_fallback($wb, $imf, $sources, ...)` |
| Validation | None | `blomstra_validate_pillar_thresholds()` on init |
| Percentiles | `blomstra_compute_percentile_ranks()` | `blomstra_compute_percentile_ranks_safe($values, 0.01)` |

### CII v2.9.23 → v3.0.0

| Location | Current | v3.0.0 Replacement |
|----------|---------|-------------------|
| Null check | `empty($value)` on maritime/HHI | `blomstra_safe_numeric($value)` |
| Source tracking | None per-indicator | `blomstra_track_source()` for each Comtrade/EIA/WB indicator |
| Validation | None | `blomstra_validate_pillar_thresholds()` on init |

---

## Integration with BMS Standards

| Utility | Primary BMS | What it enforces |
|---------|-------------|------------------|
| `blomstra_safe_numeric()` | BMS-001, BMS-002 | Structural zeros are data, not missing |
| `blomstra_sanitize_timeseries()` | BMS-005 | Chronological order, minimum span enforcement |
| `blomstra_compute_cagr()` | BMS-005 | Normalized trajectory (% change, not absolute) |
| `blomstra_track_source()` | BMS-001 | Per-indicator provenance with scope |
| `blomstra_merge_with_fallback()` | BMS-001, BMS-002 | Fallbacks are flagged, not silent |
| `blomstra_validate_pillar_thresholds()` | BMS-004 | Defs and engine stay synchronized |
| `blomstra_compute_percentile_ranks_safe()` | BMS-003 | Winsorization prevents outlier domination |
| `blomstra_data_quality_flag()` | BMS-001, BMS-005 | Staleness and coverage transparency |

---

## What to Read Next

- How data enters the system → [`08-reference-data-functions.md`](08-reference-data-functions.md)
- The standards these utilities enforce → [`11-engineering-research-standards.md`](11-engineering-research-standards.md)
- Building a new index with these utilities → [`05-index-template.md`](05-index-template.md)
- CII case study (Layer C) → [`10-methodology-deepdive.md`](10-methodology-deepdive.md)
