# SIVI API

> **Endpoint:** `GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index`  
> **Legacy:** `GET /wp-json/blomstra/v1/critical-infrastructure-index` (identical data)  
> **Version:** SIVI v2.0.0  
> **Standard:** BMS-1.0.0

---

## Endpoint

```
GET https://yoursite.com/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index
```

**Authentication:** None (public endpoint)  
**Rate limiting:** None (served from WordPress option cache)

---

## Response Schema

### Top Level

```json
{
  "version": "2.0.0",
  "last_updated": "2026-08-10 08:00:00",
  "total_countries": 182,
  "excluded": 14,
  "excluded_detail": {
    "XXX": {
      "reason": "Fewer than 2 pillars have real data — not scored (no fabricated fill-in used).",
      "pillars_present": 1,
      "pillars_missing": ["energy", "hhi"]
    }
  },
  "weights": {
    "energy": 33.3333,
    "hhi": 33.3333,
    "maritime": 33.3334
  },
  "methodology_summary": "Percentile-rank composite (Energy dependency, HHI supplier concentration, inverted Maritime connectivity). Full Index = definitive rank. Partial Index = projected rank range with 80% and theoretical bounds.",
  "footnote": "Partial ranks are projections, not definitive placements. Following OECD/JRC guidelines, we report two uncertainty intervals for countries missing a pillar: the 80% Plausible Range (simulating the missing dimension between the 10th and 90th percentile of global data) and the Theoretical Bound (0th to 100th percentile). The Best Estimate uses the global median (50th percentile) for the missing dimension. Countries with structural zeros (e.g. landlocked states with no maritime connectivity) are scored in the Full Index, not the Partial Index.",
  "countries": { ... },
  "_meta": {
    "built_at": "2026-08-10 08:00:00",
    "source": "cron",
    "status": "valid",
    "standard_version": "BMS-1.0.0"
  }
}
```

### Country Object

```json
{
  "iso3": "USA",
  "name": "United States",
  "sivi_structural": 42.35,
  "coverage": "full",
  "pillars_missing": [],
  "data_quality": {
    "energy": 3,
    "hhi": 3,
    "maritime": 3
  },
  "measurement_flags": {
    "is_landlocked": false,
    "maritime_is_structural_zero": false,
    "coverage_ratio": 1.0,
    "is_definitive": true,
    "missing_pillars": []
  },
  "rank_display": {
    "is_definitive": true,
    "best_estimate": 45,
    "range_80_low": 45,
    "range_80_high": 45,
    "theoretical_low": 45,
    "theoretical_high": 45,
    "string_format": "#45",
    "total": 182
  },
  "energy_dependency_percentile": 38.2,
  "energy_dependency_raw": 12.5,
  "supplier_concentration_percentile": 45.1,
  "supplier_concentration_raw": 1250,
  "maritime_connectivity_percentile": 58.3,
  "maritime_vulnerability_percentile": 41.7,
  "maritime_connectivity_raw": 45.2,
  "is_landlocked": false,
  "pillars_used": 3,
  "last_updated": "2026-08-10 08:00:00"
}
```

### Partial Index Example

```json
{
  "iso3": "XYZ",
  "name": "Example Country",
  "sivi_structural": 55.2,
  "coverage": "partial",
  "pillars_missing": ["hhi"],
  "rank_display": {
    "is_definitive": false,
    "best_estimate": 78,
    "range_80_low": 65,
    "range_80_high": 91,
    "theoretical_low": 23,
    "theoretical_high": 112,
    "string_format": "#65–#91*",
    "total": 182
  }
}
```

### Landlocked Country Example

```json
{
  "iso3": "CHE",
  "name": "Switzerland",
  "sivi_structural": 35.1,
  "coverage": "full",
  "maritime_connectivity_raw": 0.0,
  "maritime_vulnerability_percentile": 100.0,
  "is_landlocked": true,
  "measurement_flags": {
    "is_landlocked": true,
    "maritime_is_structural_zero": true
  }
}
```

**Note:** Switzerland receives a structural zero for maritime (0.0 connectivity) but is still scored in the Full Index because the zero is methodologically meaningful, not missing data.

---

## Error Responses

### 404 — Not Built

```json
{
  "code": "no_data",
  "message": "Index not built yet.",
  "data": { "status": 404 }
}
```

**Resolution:** Visit the admin page and click "Build Index from Cache."

---

## Data Sources

| Pillar | Primary | Fallback | Notes |
|---|---|---|---|
| Energy | EIA API (batch) | Direct EIA API | 5-fuel consumption-weighted dependency |
| HHI | UN Comtrade (batch) | Direct Comtrade API | Import partner concentration, 0–10,000 |
| Maritime | World Bank WDI (IS.SHP.GCNW.XQ) | Direct WB API | LSCI index; landlocked = structural zero |

---

## Update Frequency

- **Daily cron:** Refreshes pillar data, rebuilds composite
- **Weekly cron:** Full refresh
- **Manual:** Admin can trigger any pillar or full build

**Note:** EIA and Comtrade fetches are slow (3–10 minutes). Use async callbacks to avoid timeouts.

---

## Changelog

| Version | Date | Changes |
|---|---|---|
| 2.0.0 | 2026-08 | BMS-1.0.0 migration: renamed from CII, added async, cron safeguards, sensitivity testing, scenario-safe storage, data quality, measurement flags |
| 1.0.0 | 2026-07 | CII era — basic 3-pillar composite with flat storage |
