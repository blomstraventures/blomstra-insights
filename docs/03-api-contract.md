# API Contract

All Blomstra index endpoints MUST conform to this schema. This prevents field-name drift between indices 2 and 10 — the exact failure mode the old, pre-shared-engine Geopolitical Risk widget had (`risk_score`/`militarization_score` instead of `composite_score`/pillar-percentile fields), which is why it needed its own bespoke frontend instead of the shared engine.

---

## Endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/wp-json/blomstra/v1/{index-key}` | public | Live composite data |
| GET | `/wp-json/blomstra/v1/country-names` | public | ISO3 → name map |
| GET | `/wp-json/blomstra/v1/index-history/{slug}` | public | Historical snapshots |
| GET | `/wp-json/blomstra/v1/index-history/{slug}?iso3=USA` | public | Single-country history |

All routes are currently public/unauthenticated (`permission_callback => '__return_true'`) — intentional for now, since frontend widgets need to read these without auth, but worth revisiting once a paid API tier exists.

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
| `global_averages_informational_only` | object | Yes | Raw value means — **never used in scoring**; the field name and its own `note` subfield say this explicitly, as a guardrail against a future reader (human or code) mistaking it for something used in composite math |
| `weights` | object | Yes | Pillar name → weight mapping, echoed back rather than only living in code, so a reader always sees the true current weights even if they change later |
| `_meta` | object | Yes | `{built_at, source, status, standard_version}` |
| `countries` | object | Yes | ISO3 → country data — **always a keyed object, never an array** |

### Per-Country Schema

```json
{
  "YEM": {
    "composite_score": 94.2,
    "coverage_type": "full",
    "coverage_ratio": 1.0,
    "is_definitive": true,
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
    "data_freshness": {
      "energy": { "year": 2024, "source": "EIA", "status": "observed" },
      "hhi": { "year": 2023, "source": "Comtrade", "status": "observed" },
      "maritime": { "year": 2024, "source": "World Bank WDI", "status": "observed" }
    },
    "last_updated": "2026-08-05 12:00:00"
  }
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `composite_score` | float | Yes | Final 0–100 score |
| `coverage_type` | string | Yes | `"full"` or `"partial"` |
| `coverage_ratio` | float | Yes | `pillars_used / total_pillars` (e.g., 0.75 for 3/4) |
| `is_definitive` | bool | Yes | `true` for Full Index, `false` for Partial Index |
| `rank` | int\|null | Yes | Definitive rank (null for partial — never a fabricated single number) |
| `rank_display` | object | Yes | See below. **Always include this object, even for Full Index rows** (where every field is just the same single number and `is_definitive: true`) — the frontend's rank-delta feature reads `rank_display.best_estimate` uniformly across both Full and Partial rows, and a Full-only index that omits this object silently breaks delta computation |
| `{pillar}_percentile` | float\|null | Yes | Normalized pillar score |
| `{pillar}_raw` | float\|null | No | Raw pre-normalized value |
| `{pillar}_source` | string | **MUST** for every pillar | Free-text provenance. Every pillar MUST have a source field. See BMS-001. |
| `pillars_used` | int | Yes | Count of pillars with real data |
| `pillars_missing` | array | Yes | Names of missing pillars |
| `data_freshness` | object | **MUST** | Per-pillar provenance: `{year, source, status, retrieval_date?}`. See BMS-001. |
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

> **Generalized Partial Coverage (N pillars, exactly 1 missing):**
> For any index with N pillars where `MIN_PILLARS_REQUIRED = N-1`, the Partial Index rank range is derived by:
> 1. Computing the known weighted sum from (N-1) present pillars at their real percentile values and real weights.
> 2. For the one missing pillar, injecting five candidate percentile values: `0, 10, 50, 90, 100`.
> 3. For each injected value, computing a hypothetical composite: `(known_weighted_sum + injected_value × missing_pillar_weight) / total_weight`.
> 4. Finding what rank that hypothetical score would occupy among real Full-Index countries' actual scores.
> 5. `best_estimate` = rank from injecting **50**; `range_80_low/high` = ranks from **10/90**; `theoretical_low/high` = ranks from **0/100**.
>
> See [`10-engineering-research-standards.md`](10-engineering-research-standards.md) BMS-002 for the full generalized algorithm, pseudocode, and the important note on why this assumes exactly one missing pillar.

### _meta Schema

```json
{
  "built_at": "2026-08-05 12:00:00",
  "source": "manual|cron_central_cached|cron",
  "status": "valid",
  "standard_version": "BMS-1.0.0"
}
```

| Field | Type | Description |
|---|---|---|
| `built_at` | string | ISO datetime of build completion |
| `source` | string | What triggered the build |
| `status` | string | `"valid"` or `"degraded"` (e.g., stale pillars) |
| `standard_version` | string | Layer B standard version this build conforms to |

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
| `pillars` | object | Full pillar data from that snapshot, including the complete `rank_display` object — this is what lets the frontend's delta feature compare `best_estimate` across periods even when a country's coverage type changed between them |

---

## Error Responses

| Status | Code | Message | When | Status in current code |
|---|---|---|---|---|
| 404 | `no_data` | "Index not built yet." | Composite option empty | **Implemented** |
| 500 | `build_error` | "Composite build failed: ..." | Exception during build | **Recommended** — wrap build in try/catch |
| 503 | `stale_data` | "Composite built on stale pillar data." | Freshness gate triggered | **Recommended** |

---

## Versioning Rules

1. **Backend version** (`version` field) follows SemVer: `MAJOR.MINOR.PATCH`
2. **Breaking schema changes** require MAJOR bump and frontend compatibility review
3. **New optional fields** are MINOR bumps
4. **Bug fixes** are PATCH bumps
5. **All indices must expose `_meta.built_at`** for freshness tracking
6. **All indices must declare `_meta.standard_version`** so consumers know which Layer B rules the build followed

---

## What to read next

- How the frontend consumes this shape → [`04-frontend-engine.md`](04-frontend-engine.md)
- The reasoning behind the rank-range structure → [`09-methodology-deepdive.md`](09-methodology-deepdive.md)
- The generalized standard → [`10-engineering-research-standards.md`](10-engineering-research-standards.md) BMS-002
- Building a new index against this contract → [`05-index-template.md`](05-index-template.md)
