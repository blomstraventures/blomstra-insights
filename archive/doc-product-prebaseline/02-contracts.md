# Cross-Layer Contracts
> **Tier:** T2 — Contract
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** L1 (Reference Data), L2 (Utilities), L3 (Index Backend), L4 (Frontend)
> **Last updated:** 2026-08-26
> **SSOT For:** Data shapes, API response schemas, invariants, error classifications
> **Depends on:** `01-architecture.md` (T1 state machine, layer invariants)

---

## 1. Interface Boundaries

This document defines the **exact data shapes and invariants** that bind the four layers together. No layer may deviate from these contracts without a formal deviation record (see `07-operations.md` §5).

| Contract | From | To | Content |
|---|---|---|---|
| C1 — Raw Data | L1 | L2 | Shape of fetched, normalized indicator data |
| C2 — Pillar Data | L2 | L3 | Shape of percentile-ranked, quality-scored pillar data |
| C3 — REST Response | L3 | L4 | JSON schema for index endpoints |
| C4 — Widget Config | L4 | L3 (via HTML) | `data-blomstra-config` attribute schema |

---

## 2. C1 — L1 → L2 Contract: Raw Data Shape

L1 (Reference Data) promises to deliver data in this shape to L2 (Utilities).

### 2.1 Single-Indicator Dataset

```php
array(
    'USA' => array(
        'value'  => float|int,    // NEVER null, NEVER string-encoded
        'year'   => int,           // 4-digit year
        'source' => string,        // from controlled vocabulary (see §2.3)
        'scope'  => string|null,   // e.g., 'general_gov', 'national'
    ),
    'DEU' => array(...),
    ...
)
```

### 2.2 Multi-Indicator Dataset (with Provenance)

Used when L1 stores both values and source metadata (e.g., HHI, EIA).

```php
array(
    'data'    => array(
        'USA' => array('value' => 12.5, 'year' => 2024, 'source' => 'EIA', 'scope' => 'national'),
    ),
    'sources' => array(
        'USA' => array(
            'indicator_name' => array(
                array('source' => 'EIA', 'scope' => 'national', 'year' => 2024)
            )
        )
    )
)
```

### 2.3 Controlled Vocabulary: Sources

| Token | Meaning | API Origin |
|---|---|---|
| `EIA` | U.S. Energy Information Administration | `api.eia.gov` |
| `WB_WDI` | World Bank World Development Indicators | `api.worldbank.org` |
| `WB_WGI` | World Bank Worldwide Governance Indicators | `api.worldbank.org?source=3` |
| `IMF_WEO` | IMF World Economic Outlook | `imf.org/external/datamapper/api` |
| `UN_COMTRADE` | UN Comtrade | `comtradeapi.un.org` |
| `WB_LSCI` | Liner Shipping Connectivity Index (WB) | `api.worldbank.org` |

### 2.4 C1 Invariants

1. `value` is always numeric. Use `blomstra_safe_numeric()` at L1 boundary before passing to L2.
2. `year` is always a 4-digit integer.
3. Missing countries are **omitted**, not present with `null` values.
4. `source` must be a token from the controlled vocabulary. Custom sources require deviation approval.

---

## 3. C2 — L2 → L3 Contract: Pillar Data Shape

L2 (Utilities) promises to deliver pillar data to L3 (Index Backend) in this shape.

```php
array(
    'ISO3' => array(
        'score'      => float,   // 0–100 percentile (vulnerability percentile)
        'weight'     => float,   // pillar weight in composite (e.g., 33.33)
        'percentile' => float,   // same as score, explicit for clarity
        'raw_value'  => float,   // original value before normalization
        'quality'    => array(   // from blomstra_pillar_quality_score()
            'coverage_pct'   => float,   // 0–100
            'avg_staleness'  => float,   // years
            'quality_counts' => array(
                'good'    => int,
                'aged'    => int,
                'stale'   => int,
                'missing' => int,
            ),
        ),
    ),
    ...
)
```

### 3.1 C2 Invariants

1. `score` is always in `[0, 100]`. If winsorization is applied, it is applied before this contract.
2. `quality.coverage_pct` must be computed before passing to L3. L3 does not recompute coverage.
3. The array may be empty (no data). L3 handles empty pillars via partial-index logic.
4. `weight` must sum to ~100 across all pillars of an index (±0.01 tolerance).

---

## 4. C3 — L3 → L4 Contract: REST API Response Shape

L3 (Index Backend) promises to deliver this JSON shape to L4 (Frontend).

### 4.1 Index Endpoint Response

```json
{
  "version":         "string",
  "last_updated":    "ISO8601",
  "total_countries":  int,
  "excluded_countries": int,
  "weights":         {"pillar_key": float, ...},
  "countries": {
    "ISO3": {
      "iso3":            "string",
      "name":            "string",
      "composite_score": float,
      "coverage":        "full|partial|insufficient",
      "rank_display": {
        "is_definitive":  bool,
        "best_estimate":  int,
        "string_format":  "string"
      },
      "data_quality": {"pillar_key": int, ...},
      "measurement_flags": {
        "coverage_ratio":  float,
        "is_definitive":   bool,
        "missing_pillars": ["string"]
      },
      "pillars": {
        "pillar_key": {
          "score":      float,
          "weight":     float,
          "percentile": float
        }
      },
      "projected_range": {
        "0":   float,
        "10":  float,
        "50":  float,
        "90":  float,
        "100": float
      },
      "sensitivity_interval": {
        "point":   float,
        "ci_low":  float,
        "ci_high": float
      }
    }
  }
}
```

### 4.2 Field Rules

| Field | Presence | Constraints |
|---|---|---|
| `composite_score` | Always | `[0, 100]` |
| `coverage` | Always | One of `full`, `partial`, `insufficient` |
| `rank_display.best_estimate` | Always | `1` = most vulnerable, `N` = least vulnerable |
| `rank_display.is_definitive` | Always | `false` when `coverage == "partial"` |
| `projected_range` | Conditional | Present **iff** `coverage == "partial"` |
| `sensitivity_interval` | Conditional | Present **iff** bootstrap CI was computed |
| `measurement_flags.missing_pillars` | Always | Empty array when `coverage == "full"` |

### 4.3 History Endpoint Response

```json
{
  "ISO3": [
    {
      "period":          "YYYY-MM",
      "composite_score": float,
      "rank_value":      int,
      "coverage_type":   "full|partial|insufficient",
      "pillars_json":    "string"
    }
  ]
}
```

### 4.4 C3 Invariants

1. Countries with `coverage == "insufficient"` are **omitted** from `countries` but counted in `excluded_countries`.
2. `version` follows SemVer for the index backend, not the overall system.
3. `last_updated` is the build timestamp, not the data timestamp.
4. Legacy endpoints must return HTTP 301/302 to canonical endpoints.

---

## 5. C4 — L4 → L3 Contract: Widget Configuration

The WordPress shortcode injects configuration via `data-blomstra-config`:

```html
<div data-blomstra-index="sivi"
     data-blomstra-config='{
       "view": "table",
       "sort": "rank",
       "limit": 50,
       "show_delta": true,
       "enable_watchlist": true,
       "enable_modal": true,
       "pillar_breakdown": false,
       "band_filter": null
     }'>
</div>
```

### 5.1 Config Schema

| Key | Type | Default | Valid Values |
|---|---|---|---|
| `view` | string | `"table"` | `"table"`, `"grid"`, `"map"` |
| `sort` | string | `"rank"` | `"rank"`, `"score"`, `"name"`, `"delta"` |
| `limit` | int | `50` | `1` to `250` |
| `show_delta` | bool | `true` | `true`, `false` |
| `enable_watchlist` | bool | `true` | `true`, `false` |
| `enable_modal` | bool | `true` | `true`, `false` |
| `pillar_breakdown` | bool | `false` | `true`, `false` |
| `band_filter` | string | `null` | `null`, `"critical"`, `"high"`, `"elevated"`, `"moderate"`, `"low"` |

---

## 6. Error Classification Contract

All layers share a unified error taxonomy.

| Code | Name | Retryable | Layer Action |
|---|---|---|---|
| `200` | Success | — | Process data |
| `429` | Quota / Rate limit | Yes (with backoff) | Parse "Try again in N seconds", wait N+2s |
| `403` | Authorization error | **No** | Permanent failure — invalid/expired API key |
| `401` | Authentication error | **No** | Permanent failure — invalid API key |
| `404` | Not found | **No** | Permanent failure — endpoint changed |
| `5xx` | Server error | Yes (max 3 attempts) | Exponential backoff: 2s, 4s, 8s |
| `NETWORK` | Network error (WP_Error) | Yes (max 3 attempts) | Exponential backoff |
| `EMPTY` | HTTP 200, zero rows | No | Classify as `NO_DATA` |
| `OVERSIZED` | Response > 15MB | No | Reject, log, retry with smaller chunk |

**Permanent failures are never retried.** They are logged, the country/fuel is marked, and processing continues.

---

## 7. BMS-1.1.0 Conformance Predicates

Formal verification conditions for every index backend.

```php
// Predicate 1: Per-pillar provenance
assert(array_key_exists('data',    $pillar));
assert(array_key_exists('sources', $pillar));

// Predicate 2: State machine conformance
assert(get_option("blomstra_state_{$key}") !== false);
assert(in_array($state, ['idle', 'running', 'paused', 'done', 'failed', 'promoted', 'rolled_back']));

// Predicate 3: Staging coverage threshold
assert($staging_count >= max(1, $expected_count * 0.8));

// Predicate 4: Cron safeguards
assert(get_transient("blomstra_{$pillar}_refresh_in_progress") !== false || $state !== 'running');
assert($new_count >= $previous_count * 0.8);

// Predicate 5: Statistical validation
assert(blomstra_cronbach_alpha($indicator_matrix) !== null);
assert(blomstra_bootstrap_ci($pillars, $weights, 1000, 0.95) !== null);

// Predicate 6: Partial-rank projection
if ($coverage === 'partial') {
    assert(array_key_exists('projected_range', $country));
}

// Predicate 7: Admin dashboard
assert(admin_url('admin.php?page=blomstra-insights-tools') !== false);

// Predicate 8: REST endpoints
$response = wp_remote_get(rest_url('blomstra/v1/' . $slug));
assert(wp_remote_retrieve_response_code($response) === 200);
```
