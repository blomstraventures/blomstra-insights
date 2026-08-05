# API Contract

All Blomstra index endpoints MUST conform to this schema. This prevents field-name drift between indices 2 and 10.

---

## Endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/wp-json/blomstra/v1/{index-key}` | public | Live composite data |
| GET | `/wp-json/blomstra/v1/country-names` | public | ISO3 → name map |
| GET | `/wp-json/blomstra/v1/index-history/{slug}` | public | Historical snapshots |
| GET | `/wp-json/blomstra/v1/index-history/{slug}?iso3=USA` | public | Single-country history |

---

## Composite Response Schema

### Top-Level Fields

```json
{
  "version": "3.1.2",
  "last_updated": "2026-08-05 12:00:00",
  "total_countries": 187,
  "excluded": 23,
  "excluded_detail": { ... },
  "methodology_url": "https://blomstrainsights.com/methodology/cii",
  "methodology_summary": "string",
  "footnote": "string",
  "global_averages_informational_only": { ... },
  "weights": { ... },
  "_meta": { ... },
  "countries": { ... }
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `version` | string | Yes | Semantic version of the index backend |
| `last_updated` | string | Yes | ISO datetime of last composite build |
| `total_countries` | int | Yes | Number of scored countries (Full + Partial) |
| `excluded` | int | Yes | Number of countries with insufficient data |
| `excluded_detail` | object | Yes | ISO3 → `{reason, pillars_present, pillars_missing}` |
| `methodology_url` | string | Yes | Link to full methodology page |
| `methodology_summary` | string | Yes | One-paragraph summary |
| `footnote` | string | Yes | Important caveats (e.g. partial rank disclaimer) |
| `global_averages_informational_only` | object | Yes | Raw value means, never used in scoring |
| `weights` | object | Yes | Pillar name → weight mapping |
| `_meta` | object | Yes | `{built_at, source, status}` |
| `countries` | object | Yes | ISO3 → country data |

### Per-Country Schema

```json
{
  "YEM": {
    "composite_score": 94.2,
    "coverage_type": "full",
    "rank": 1,
    "rank_display": {
      "is_definitive": true,
      "best_estimate": 1,
      "range_80_low": 1,
      "range_80_high": 1,
      "theoretical_low": 1,
      "theoretical_high": 1,
      "string_format": "#1"
    },
    "energy_dependency_percentile": 98.5,
    "energy_dependency_raw": 87.3,
    "supplier_concentration_percentile": 92.1,
    "supplier_concentration_raw": 7845.2,
    "hhi_source": "Comtrade",
    "maritime_connectivity_percentile": 12.4,
    "maritime_vulnerability_percentile": 87.6,
    "maritime_connectivity_raw": 1.2,
    "maritime_source": "World Bank WDI (IS.SHP.GCNW.XQ)",
    "is_landlocked": false,
    "pillars_used": 3,
    "pillars_missing": [],
    "last_updated": "2026-08-05 12:00:00"
  }
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `composite_score` | float | Yes | Final 0–100 score |
| `coverage_type` | string | Yes | `"full"` or `"partial"` |
| `rank` | int\|null | Yes | Definitive rank (null for partial) |
| `rank_display` | object | Yes | See below |
| `{pillar}_percentile` | float\|null | Yes | Normalized pillar score |
| `{pillar}_raw` | float\|null | No | Raw pre-normalized value |
| `pillars_used` | int | Yes | Count of pillars with real data |
| `pillars_missing` | array | Yes | Names of missing pillars |
| `last_updated` | string | Yes | ISO datetime |

### Rank Display Schema

**Full Index (definitive):**
```json
{
  "is_definitive": true,
  "best_estimate": 14,
  "range_80_low": 14,
  "range_80_high": 14,
  "theoretical_low": 14,
  "theoretical_high": 14,
  "string_format": "#14"
}
```

**Partial Index (projected):**
```json
{
  "is_definitive": false,
  "best_estimate": 45,
  "range_80_low": 38,
  "range_80_high": 52,
  "theoretical_low": 22,
  "theoretical_high": 71,
  "string_format": "#38–#52*"
}
```

### _meta Schema

```json
{
  "built_at": "2026-08-05 12:00:00",
  "source": "manual|cron_central_cached|cron",
  "status": "valid"
}
```

---

## History Response Schema

```json
{
  "YEM": [
    {
      "period": "2026-07",
      "composite_score": 93.8,
      "rank": 1,
      "coverage_type": "full",
      "pillars": { ... }
    },
    {
      "period": "2026-08",
      "composite_score": 94.2,
      "rank": 1,
      "coverage_type": "full",
      "pillars": { ... }
    }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `period` | string | `YYYY-MM` snapshot period |
| `composite_score` | float\|null | Score at that period |
| `rank` | int\|null | Rank at that period |
| `coverage_type` | string\|null | `"full"` or `"partial"` |
| `pillars` | object | Full pillar data from that snapshot |

---

## Error Responses

| Status | Code | Message | When |
|---|---|---|---|
| 404 | `no_data` | "Index not built yet." | Composite option empty |
| 500 | `build_error` | "Composite build failed: ..." | Exception during build |

---

## Versioning Rules

1. **Backend version** (`version` field) follows SemVer: `MAJOR.MINOR.PATCH`
2. **Breaking schema changes** require MAJOR bump and frontend compatibility review
3. **New optional fields** are MINOR bumps
4. **Bug fixes** are PATCH bumps
5. **All indices must expose `_meta.built_at`** for freshness tracking
