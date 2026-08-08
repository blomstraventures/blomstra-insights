# Data Flow

![Data Pipeline](../assets/diagram-02-data-pipeline.png)

---

## Overview

Data flows through four stages: **Raw Collection → Central Cache → Index Builder → REST Output**. The weekly cron handles stages 1–2. The daily cron handles stages 3–4. Fallback engines exist in every index backend for first-run or failure scenarios.

---

## Stage 1: Raw Collection (Weekly Cron)

### EIA Energy
- **Source:** EIA API v2 /international/data/
- **Fuels:** 5 product IDs (Coal, Natural Gas, Petroleum, Nuclear, Renewables)
- **Activities:** Production (1) + Consumption (2)
- **Chunk size:** 25 countries per batch
- **Retry:** 3 attempts with exponential backoff
- **Output shape:** `blomstra_eia_raw_data['consumption'][fuel_id][iso3] = qbtu`

### Comtrade HHI
- **Source:** UN Comtrade API v1 /get/C/A/HS
- **Chunk size:** 50 reporter codes per batch
- **Lookback:** 4 years (current year → current year − 4)
- **Pagination:** Up to 20 pages per chunk, stops early when all reporters have `partnerCode=0`
- **Quota guard:** Detects HTTP 429/403, parses retry-after, or marks quota exhausted
- **Output shape:** `blomstra_comtrade_hhi_data[iso3] = {value, year, source}`

### World Bank Maritime
- **Source:** WDI indicator `IS.SHP.GCNW.XQ`
- **Window:** 20 years of data
- **Landlocked:** Structural zero (not missing data)
- **Output shape:** `blomstra_maritime_raw[iso3] = {value, year}`

### World Bank Indicators (WDI/WGI)
- **Source:** World Bank API v2 /country/all/indicator/{code}
- **Pagination:** `per_page=20000` for single-call bulk fetch
- **Missing-value semantics:** Null values in JSON response = genuinely missing
- **Structural zero:** Not applicable for these indicators (no WB indicator has a meaningful structural zero)
- **Output shape:** `blomstra_wb_indicator_cache[{code}][iso3] = {value, year, source}`
- **Provenance fields:** `value`, `year`, `source`, `data_type`, `retrieval_date`

### IMF WEO Indicators
- **Source:** IMF DataMapper API v1 /{code}
- **Coverage:** Historical estimates + T+1 forecasts
- **Vintage:** IMF WEO publication month/year (e.g., "April 2026")
- **Output shape:** `blomstra_imf_cache[{code}][iso3] = {value, year, source, weo_vintage}`
- **Critical rule:** IMF forecasts MUST NOT be used as fallback for missing structural data. See BMS-006.

---

## Stage 2: Central Cache

Reference Data stores raw results as WordPress options (not transients — these are the source of truth):

| Option Key | Data Type | TTL |
|---|---|---|
| `blomstra_eia_raw_data` | `{consumption: {}, production: {}}` | Overwritten on refresh |
| `blomstra_comtrade_hhi_data` | `{iso3: {value, year, source}}` | Overwritten on refresh |
| `blomstra_maritime_raw` | `{iso3: {value, year}}` | Transient, 1 week |
| `blomstra_wb_indicator_cache` | `{code: {iso3: {value, year, source, ...}}}` | Overwritten on refresh |
| `blomstra_imf_cache` | `{code: {iso3: {value, year, source, weo_vintage}}}` | Overwritten on refresh |

---

## Stage 3: Index Builder (Daily Cron)

### Step 1: Extract Raw Values
Reads the pillar options. Extracts non-null `value` fields. Applies **data-state taxonomy** (see BMS-002 in [`10-engineering-research-standards.md`](10-engineering-research-standards.md)).

### Step 2: Percentile Rank
```php
cii_compute_percentile_ranks($values_by_iso3)
```
- Sorts values ascending
- Assigns average rank for ties
- Converts to 0–100 percentile: `((rank - 0.5) / n) * 100`

See BMS-003 for the full normalization standard.

### Step 3: Weighted Composite
```php
$composite = (Σ present_pillar.value × present_pillar.weight) / (Σ present_pillar.weight)
```

Dividing by the sum of only the *present* pillars' weights means the weights are implicitly renormalized to sum to 1 for whatever subset of pillars a country actually has.

### Step 4: Coverage Check
- **Full Index:** all pillars present (N/N)
- **Partial Index:** ≥ `MIN_PILLARS_REQUIRED` but < total pillars
- **Excluded:** < `MIN_PILLARS_REQUIRED`. Not scored. No fabricated fill-in.

### Step 5: Rank Assignment
See [diagram](../assets/diagram-03-rank-assignment.png) and [`03-api-contract.md`](03-api-contract.md) for the response schema, or [`09-methodology-deepdive.md`](09-methodology-deepdive.md) for the CII case study, or [`10-engineering-research-standards.md`](10-engineering-research-standards.md) BMS-002 for the generalized N-pillar algorithm.

### Step 6: Snapshot Save
```php
blomstra_index_snapshot_save('cii', $countries);
```
Upserts one row per (index_slug, iso3, YYYY-MM) into `wp_blomstra_index_history`.

---

## Stage 4: REST Output

The composite is served as JSON at the index's registered REST endpoint. The frontend fetches this plus `/country-names` and `/index-history/{slug}` in parallel.

---

## Fallback Path

When central cache is empty (first run, or after flush):

1. **Admin test buttons** — "Direct API" triggers the index's own fallback engine in isolation
2. **Auto mode** — silently falls back if central returns empty
3. **Complete parity** — fallback engines use identical chunk sizes, retry logic, and checkpointing as Reference Data

The fallback exists so no single point of failure can take an index down.

---

## Universal Data-State Rules

Every value that enters an index builder MUST carry a data state. This is not optional metadata — it determines how the value is treated in scoring.

| State | Meaning | Scoring Treatment |
|-------|---------|-------------------|
| `observed` | Real published value | Use as-is |
| `structural_zero` | Known real-world zero | Use as-is (0 is valid) |
| `estimated` | Source identifies as estimate | Use as-is, flag provenance |
| `forecast` | Forward-looking value | Quarantine to forecast layer only |
| `stale` | Exceeds acceptable age | Do not use; trigger refresh |
| `unavailable` | Cannot currently be obtained | Treat as missing |
| `not_applicable` | Indicator doesn't apply | Treat as missing (not zero) |
| `collection_failure` | Technical/API failure | Treat as missing; log for retry |
| `missing` | No usable observation | Do not fabricate |

**Critical rule:** A value of exactly `0.0` from a source is **NOT automatically `missing`**. It MUST be classified based on domain knowledge. See BMS-002.

---

## Source Hierarchy Standard

When multiple sources exist for the same conceptual indicator, this hierarchy determines precedence:

| Tier | Use | Example |
|------|-----|---------|
| **Primary** | The authoritative source for this indicator | World Bank WDI for GDP growth |
| **Acceptable fallback** | A closely related indicator that measures the same concept | GNI growth → GDP growth (GERI macro fallback) |
| **Prohibited fallback** | A different concept that happens to share a name | IMF T+1 forecast → World Bank historical (structural layer) |
| **Separate layer** | Related but conceptually distinct — never mixed | IMF forecast → Forward Pressure layer only |

**Rule:** A fallback MUST be documented in the index's `deviations.md` with justification. A prohibited fallback used anyway is a methodology bug, not a data gap.

---

## Adding a New External API to Reference Data

Before adding a function, the developer MUST document:

| Field | Required | Example (WB LSCI) |
|-------|----------|-------------------|
| Provider | Yes | World Bank WDI |
| API endpoint | Yes | `api.worldbank.org/v2/country/all/indicator/IS.SHP.GCNW.XQ` |
| Dataset/indicator ID | Yes | `IS.SHP.GCNW.XQ` |
| Unit | Yes | Liner Shipping Connectivity Index (unitless) |
| Directionality | Yes | Higher = better connectivity → invert for vulnerability |
| Country key / ISO mapping | Yes | `countryiso3code` |
| Observation year field | Yes | `date` |
| Current vs historical semantics | Yes | Most recent year per country (`mrnev=1`) |
| Revision/vintage behavior | Yes | None (WB WDI revised in place) |
| Missing-value semantics | Yes | Null = missing; landlocked = structural zero |
| Rate limits | Yes | ~100 req/min practical |
| Pagination | Yes | `per_page=20000` sufficient for single call |
| Retry strategy | Yes | 1 automatic retry, 3s sleep |
| Cache duration | Yes | 1 week transient |
| Refresh cadence | Yes | Weekly (Monday 02:00 UTC) |
| Provenance fields | Yes | `value`, `year`, `source` |
| Fallback policy | Yes | Index-level direct API fallback if central cache empty |
| Reusable by multiple indices? | Yes | Yes — any index needing maritime connectivity |
| Transformation belongs in... | Yes | Reference Data: raw LSCI value. Index: inversion to vulnerability. |

**The checklist:**

1. **Source definition** — Document all fields above in a comment block above the function
2. **Fetch function with retry/backoff** — Use `wp_remote_get()` with timeout, retry loop, and exponential backoff
3. **Chunking/pagination** — If the API returns paginated results, loop until exhausted
4. **Per-chunk checkpointing** — Save partial progress after every chunk into the option/transient
5. **Call logging from day one** — Write to `{source}_call_log` option on every request
6. **Debug info option** — Store HTTP code, body snippet, parsed count in `{source}_debug`
7. **Admin sandbox integration** — Add a test button to the Reference Data admin page
8. **Staggered cron registration** — Register on a day that doesn't conflict with existing weekly crons

See [`08-reference-data-functions.md`](08-reference-data-functions.md) for the concrete example of onboarding World Bank LSCI.

---

## What to read next

- How this gets served and rendered → [`04-frontend-engine.md`](04-frontend-engine.md)
- The functions this methodology consumes → [`08-reference-data-functions.md`](08-reference-data-functions.md)
- The exact response schema → [`03-api-contract.md`](03-api-contract.md)
- The Blomstra-wide research standards → [`10-engineering-research-standards.md`](10-engineering-research-standards.md)
