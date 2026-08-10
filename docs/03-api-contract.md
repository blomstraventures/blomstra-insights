# API Contract

> **Applies to:** SERI v4.2.1+, SIVI v2.0.0+
> **Standard:** BMS-1.0.0

---

## REST Endpoints

### SERI

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/wp-json/blomstra/v1/geo-economic-risk-index` | GET | Public | Canonical endpoint (legacy name preserved for compatibility) |

**Legacy redirects:** The old slug remains registered and returns identical data.

### SIVI

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index` | GET | Public | Canonical endpoint |
| `/wp-json/blomstra/v1/critical-infrastructure-index` | GET | Public | Legacy endpoint (backward compatibility) |

---

## Top-Level Response Fields

Every BMS-1.0.0 conformant index returns these top-level keys:

| Field | Type | Required | Description |
|---|---|---|---|
| `version` | string | yes | Index version (e.g., "4.2.1") |
| `last_updated` | string | yes | MySQL datetime, UTC |
| `total_countries` | int | yes | Number of countries in `countries` object |
| `excluded_countries` / `excluded` | int | yes | Number of countries excluded (naming varies by index legacy) |
| `excluded_detail` | object | yes | Map of `iso3 => reason` for excluded countries |
| `weights` | object | yes | Composite pillar weights used for this build |
| `methodology_note` / `methodology_summary` | string | yes | Human-readable methodology summary |
| `countries` | object | yes | Map of `iso3 => country_data` |
| `_meta` | object | yes | Build metadata: `built_at`, `source`, `status`, `standard_version` |

**SERI-specific top-level fields:**

| Field | Type | Description |
|---|---|---|
| `reference_vintage` | string | Year of reference data |
| `weo_vintage` | string | IMF WEO vintage (e.g., "April 2026") |
| `min_pillars_required` | int | Minimum pillars for inclusion (3 for SERI) |
| `forward_direction` | string | "Deteriorating", "Improving", or "Stable" (per country) |
| `geri_forward_pressure` | float | Forward pressure score (0-100) |

**SIVI-specific top-level fields:**

| Field | Type | Description |
|---|---|---|
| `footnote` | string | Extended methodology note about partial ranks and structural zeros |

---

## Country Object Schema

### Required Fields (All Indices)

| Field | Type | Description |
|---|---|---|
| `iso3` | string | 3-letter country code |
| `name` | string | Country name |
| `{index}_structural` / `composite_score` | float | The composite score (0-100) |
| `coverage` | string | `"full"` or `"partial"` |
| `pillars_missing` | array | List of missing pillar names (empty for full) |
| `data_quality` | object | Per-pillar quality scores (0-3 scale) |
| `measurement_flags` | object | Structural metadata about this country's data |
| `rank_display` | object | Rank information (see below) |
| `pillars` | object | Per-pillar `score` and `weight` |

### SERI Country Fields

| Field | Type | Description |
|---|---|---|
| `geri_structural` | float | Composite score |
| `governance_percentile` | float | Governance pillar percentile |
| `macro_percentile` | float | Macro pillar percentile |
| `external_percentile` | float | External pillar percentile |
| `fiscal_percentile` | float | Fiscal pillar percentile |
| `geri_forward_pressure` | float | Forward pressure (IMF WEO forecast delta) |
| `forward_direction` | string | Directional signal |
| `forward_delta_avg` | float | Average forecast delta |
| `data_freshness` | object | Per-indicator year and source |
| `fiscal_source_summary` | object | Debt/balance/trajectory source provenance |

### SIVI Country Fields

| Field | Type | Description |
|---|---|---|
| `sivi_structural` | float | Composite score |
| `energy_dependency_percentile` | float | Energy pillar percentile |
| `energy_dependency_raw` | float | Raw dependency percentage |
| `supplier_concentration_percentile` | float | HHI pillar percentile |
| `supplier_concentration_raw` | float | Raw HHI value (0-10,000) |
| `maritime_connectivity_percentile` | float | Maritime connectivity percentile (inverted for vulnerability) |
| `maritime_vulnerability_percentile` | float | Maritime vulnerability percentile |
| `maritime_connectivity_raw` | float | Raw LSCI value |
| `is_landlocked` | bool | Whether country is landlocked |
| `pillars_used` | int | Number of pillars with data |

---

## Rank Display Object

```json
{
  "is_definitive": true,
  "best_estimate": 45,
  "range_80_low": 45,
  "range_80_high": 45,
  "theoretical_low": 45,
  "theoretical_high": 45,
  "string_format": "#45",
  "total": 182
}
```

### For Partial Index Countries

```json
{
  "is_definitive": false,
  "best_estimate": 45,
  "range_80_low": 38,
  "range_80_high": 52,
  "theoretical_low": 12,
  "theoretical_high": 89,
  "string_format": "#38-#52*",
  "total": 182
}
```

| Field | Meaning |
|---|---|
| `best_estimate` | Rank if missing pillar were at global median (50th percentile) |
| `range_80_low/high` | Rank if missing pillar were at 10th/90th percentile |
| `theoretical_low/high` | Rank if missing pillar were at 0th/100th percentile |
| `string_format` | Human-readable rank string |
| `total` | Total countries in full index |

---

## Data Quality Object

```json
{
  "governance": 3,
  "macro": 2,
  "external": 3,
  "fiscal": 1
}
```

Scale: 0 = no data, 1 = poor (old or fallback), 2 = mixed, 3 = good (recent, primary source).

---

## Measurement Flags Object

### SERI

```json
{
  "gni_is_gdp_fallback": false,
  "fiscal_scope_mixed": false,
  "trajectory_quality": "good",
  "trajectory_observations": 5,
  "trajectory_span_years": 4,
  "coverage_ratio": 1.0,
  "is_definitive": true,
  "missing_pillars": []
}
```

### SIVI

```json
{
  "is_landlocked": false,
  "maritime_is_structural_zero": false,
  "coverage_ratio": 1.0,
  "is_definitive": true,
  "missing_pillars": []
}
```

---

## Error Responses

### Index Not Built

```json
{
  "code": "no_data",
  "message": "Index has not been generated yet.",
  "data": { "status": 404 }
}
```

### No Country List

```json
{
  "error": "No country list available"
}
```

---

## Versioning

The API is **not versioned in the URL**. Versioning is declarative inside the response:

```json
{
  "version": "4.2.1",
  "_meta": {
    "standard_version": "BMS-1.0.0"
  }
}
```

Breaking changes to the response shape will be signaled by a BMS version bump (e.g., BMS-2.0.0), not a URL version change.
