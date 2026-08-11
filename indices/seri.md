# SERI API

> **Endpoint:** `GET /wp-json/blomstra/v1/geo-economic-risk-index`  
> **Legacy:** `GET /wp-json/blomstra/v1/geo-economic-risk-index` (identical)  
> **Version:** SERI v4.2.1  
> **Standard:** BMS-1.0.0

---

## Endpoint

```
GET https://yoursite.com/wp-json/blomstra/v1/geo-economic-risk-index
```

**Authentication:** None (public endpoint)  
**Rate limiting:** None (served from WordPress option cache)  
**Cache headers:** Set by WordPress object cache if configured

---

## Response Schema

### Top Level

```json
{
  "version": "4.2.1",
  "last_updated": "2026-08-10 08:00:00",
  "reference_vintage": "2026",
  "weo_vintage": "April 2026",
  "min_pillars_required": 3,
  "total_countries": 182,
  "excluded_countries": 14,
  "excluded_detail": {
    "XXX": "Insufficient pillar coverage: 2/4 pillars available."
  },
  "weights": {
    "governance": 25,
    "macro": 25,
    "external": 25,
    "fiscal": 25
  },
  "methodology_note": "Fiscal pillar uses IMF WEO general government debt...",
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
  "geri_structural": 42.35,
  "coverage": "full",
  "pillars_missing": [],
  "data_freshness": {
    "governance": { "year": 2023, "source": "WGI" },
    "macro": {
      "gni_year": 2023, "gni_source": "WB_WDI",
      "inflation_year": 2023, "inflation_source": "WB_WDI",
      "unemployment_year": 2023, "unemployment_source": "WB_WDI",
      "gdp_volatility_window": "5 years",
      "gdp_volatility_observations": 5,
      "gdp_volatility_years": "2019,2020,2021,2022,2023",
      "inflation_volatility_window": "5 years",
      "inflation_volatility_observations": 5,
      "inflation_volatility_years": "2019,2020,2021,2022,2023"
    },
    "external": {
      "reserves_year": 2023, "reserves_source": "WB_WDI",
      "debt_year": 2023, "debt_source": "WB_WDI",
      "current_account_year": 2023, "current_account_source": "WB_WDI"
    },
    "fiscal": {
      "debt_year": 2023, "debt_source": "IMF_WEO",
      "balance_year": 2023, "balance_source": "IMF_WEO",
      "trajectory_oldest_year": 2019,
      "trajectory_newest_year": 2023,
      "trajectory_span": 4,
      "trajectory_observations": 5,
      "trajectory_quality": "good"
    }
  },
  "data_quality": {
    "governance": 3,
    "macro": 3,
    "external": 3,
    "fiscal": 3
  },
  "fiscal_source_summary": {
    "gov_debt_source": "IMF_WEO",
    "gov_balance_source": "IMF_WEO",
    "debt_trajectory_source": "WB_WDI_derived",
    "sources_mixed": false,
    "has_trajectory": true,
    "trajectory_quality": "good"
  },
  "measurement_flags": {
    "gni_is_gdp_fallback": false,
    "fiscal_scope_mixed": false,
    "trajectory_quality": "good",
    "trajectory_observations": 5,
    "trajectory_span_years": 4,
    "coverage_ratio": 1.0,
    "is_definitive": true,
    "missing_pillars": []
  },
  "governance_percentile": 38.2,
  "macro_percentile": 45.1,
  "external_percentile": 41.3,
  "fiscal_percentile": 44.8,
  "geri_forward_pressure": 52.1,
  "forward_direction": "Stable",
  "forward_delta_avg": 0.12,
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
  "pillars": {
    "governance": { "score": 38.2, "weight": 25 },
    "macro": { "score": 45.1, "weight": 25 },
    "external": { "score": 41.3, "weight": 25 },
    "fiscal": { "score": 44.8, "weight": 25 }
  }
}
```

### Partial Index Example

```json
{
  "iso3": "XYZ",
  "name": "Example Country",
  "geri_structural": 55.2,
  "coverage": "partial",
  "pillars_missing": ["fiscal"],
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

---

## Error Responses

### 404 — Not Built

```json
{
  "code": "no_data",
  "message": "Index has not been generated yet.",
  "data": { "status": 404 }
}
```

**Resolution:** Visit the admin page and click "Build Index from Cache."

---

## Data Sources

| Pillar | Primary | Fallback |
|---|---|---|
| Governance | World Bank WGI (source=3) | Direct WB API |
| Macro | World Bank WDI | Direct WB API |
| External | World Bank WDI | Direct WB API |
| Fiscal | IMF WEO | World Bank WDI (central gov) |
| Forward Pressure | IMF WEO forecasts | — |

---

## Update Frequency

- **Daily cron:** Refreshes pillar data, rebuilds composite
- **Weekly cron:** Full refresh with force=true
- **Manual:** Admin can trigger any pillar or full build at any time

---

## Changelog

| Version | Date | Changes |
|---|---|---|
| 4.2.1 | 2026-08 | Fixed JSON preset buttons, defensive checks, fisc_sources array safety, coverage calculation |
| 4.2.0 | 2026-08 | BMS-1.0.0 migration: async callbacks, cron safeguards, sensitivity testing, scenario-safe storage |
| 3.x | 2026-07 | GERI era — basic 4-pillar composite |
