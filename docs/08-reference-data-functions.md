# Reference Data Functions

**File:** `global-reference-data.php`

This is the shared PHP layer that collects, caches, and serves raw data from external APIs to all index backends. Before writing any new collection code, check here — if a function already exists for your data source, reuse it rather than duplicating.

> **Relationship to Index Utilities:**
> Reference Data (`global-reference-data.php`) is the **ingestion layer**: it fetches, caches, paginates, and retries.
> [`09-index-utilities.md`](09-index-utilities.md) describes the **integrity layer**: it validates, sanitizes, transforms, and tracks provenance.
> 
> **Rule:** Ingestion never applies methodology-specific logic. Integrity never makes API calls.

---

## Shared Utilities

### `blomstra_get_global_country_list()`
Returns an associative array `iso3 => name` for all countries. Used by every index backend to iterate over the full universe.

### `blomstra_compute_percentile_ranks($values_by_iso3)`
Takes an associative array `iso3 => raw_value`, returns `iso3 => percentile_rank`.

- Sorts values ascending
- Assigns average rank for ties
- Converts to 0–100: `((rank - 0.5) / n) * 100`

**Used by:** Every index backend for normalization. See BMS-003.

> **Note:** For new indices, prefer `blomstra_compute_percentile_ranks_safe()` from `index-utilities.php`, which adds winsorization and stricter tie handling. The Reference Data version is retained for backward compatibility.

### `blomstra_rank_in_full_index($score, $full_composites_sorted_desc)`
Takes a composite score and an array of Full-Index composite scores sorted descending. Returns the 1-based competition rank (ties get the best available rank).

**Signature:**
```php
function blomstra_rank_in_full_index($score, $full_composites_sorted_desc) {
    // $full_composites_sorted_desc: array of floats, descending
    // Returns: int (1-based rank)
}
```

**Used by:** Partial-coverage rank injection. See BMS-002.

### `blomstra_build_partial_rank_display($ranks_by_injection)`
Takes an array of ranks from injection simulation and returns the standard `rank_display` object.

**Signature:**
```php
function blomstra_build_partial_rank_display($ranks_by_injection) {
    // $ranks_by_injection: [0 => int, 10 => int, 50 => int, 90 => int, 100 => int]
    // Returns: {
    //   is_definitive: false,
    //   best_estimate: int,
    //   range_80_low: int,
    //   range_80_high: int,
    //   theoretical_low: int,
    //   theoretical_high: int,
    //   string_format: "#38–#52*"
    // }
}
```

**Used by:** Every index backend for Partial Index output. See BMS-002.

### `blomstra_build_full_rank_display($rank)`
Takes a definitive rank and returns the standard `rank_display` object where all fields equal that rank.

**Used by:** Full Index countries.

### `blomstra_index_snapshot_save($slug, $countries)`
Upserts one row per (index_slug, iso3, YYYY-MM) into `wp_blomstra_index_history`.

**Used by:** Every index backend after successful composite build.

---

## Data Collection Functions

### `blomstra_get_maritime_raw()`
- **Source:** World Bank WDI `IS.SHP.GCNW.XQ`
- **Returns:** `{iso3: {value, year}}`
- **Structural zero handling:** Landlocked countries receive `value: 0`, `year: null`, marked as structural zero
- **Cache:** 1-week transient

### `blomstra_refresh_comtrade_hhi_data()`
- **Source:** UN Comtrade API
- **Chunk size:** 50 reporter codes
- **Lookback:** 4 years
- **Quota guard:** Detects HTTP 429/403, marks exhausted if retry-after > 24h
- **Returns:** `{iso3: {value, year, source}}`
- **Cache:** Option `blomstra_comtrade_hhi_data`

### `blomstra_get_eia_raw_data()`
- **Source:** EIA API v2
- **Fuels:** 5 product IDs × 2 activities
- **Chunk size:** 25 countries
- **Returns:** `{consumption: {fuel_id: {iso3: qbtu}}, production: {...}}`
- **Confirmed zero:** EIA returns explicit `0` for some cells; this is preserved as real data
- **Cache:** Option `blomstra_eia_raw_data`

### `blomstra_fetch_wb_indicator_batch($code, $source, $force)`
- **Source:** World Bank API v2
- **Pagination:** `per_page=20000` bulk fetch
- **Returns:** `{iso3: {value, year, source, data_type, retrieval_date}}`
- **Cache:** Option `blomstra_wb_indicator_cache[{code}]`
- **Provenance:** Includes `data_type` (observed/estimated), `retrieval_date`, `source`

> **Processing note:** When consuming this data, pass it through `blomstra_safe_numeric()` before using it in calculations. The API may return `0.0` for legitimate zeros.

### `blomstra_fetch_imf_forecast_batch($code, $horizon, $force)`
- **Source:** IMF DataMapper API
- **Returns:** `{iso3: {value, year, source, weo_vintage}}`
- **Cache:** Option `blomstra_imf_cache[{code}]`
- **Vintage:** Includes `weo_vintage` (e.g., "April 2026")

---

## Adding a New Reference Data Source

Before adding a function, the developer MUST document all fields below in a comment block above the function.

### Required Documentation Template

```php
/**
 * Source: {Provider Name}
 * API endpoint: {URL}
 * Dataset/indicator ID: {ID}
 * Unit: {unit}
 * Directionality: {higher = X}
 * Country key: {field name}
 * Observation year: {field name}
 * Current vs historical: {semantics}
 * Revision/vintage: {behavior}
 * Missing-value semantics: {null = missing? structural zero?}
 * Rate limits: {requests per minute}
 * Pagination: {chunk size or per_page}
 * Retry strategy: {attempts, backoff}
 * Cache duration: {TTL}
 * Refresh cadence: {cron schedule}
 * Provenance fields: {value, year, source, ...}
 * Fallback policy: {index-level direct API?}
 * Reusable by multiple indices? {yes/no}
 * Transformation belongs in: {Reference Data or Index}
 */
```

### Concrete Example: World Bank LSCI (Maritime)

```php
/**
 * Source: World Bank WDI
 * API endpoint: api.worldbank.org/v2/country/all/indicator/IS.SHP.GCNW.XQ
 * Dataset/indicator ID: IS.SHP.GCNW.XQ
 * Unit: Liner Shipping Connectivity Index (unitless, 0–100+ scale)
 * Directionality: Higher = better connectivity
 * Country key: countryiso3code
 * Observation year: date
 * Current vs historical: Most recent year per country (mrnev=1)
 * Revision/vintage: None (WB WDI revised in place, no version tracking)
 * Missing-value semantics: Null = missing; landlocked = structural zero (value 0)
 * Rate limits: ~100 req/min practical
 * Pagination: per_page=20000 sufficient for single call (~250 countries)
 * Retry strategy: 1 automatic retry, 3s sleep
 * Cache duration: 1 week transient
 * Refresh cadence: Weekly (Monday 02:00 UTC)
 * Provenance fields: value, year, source
 * Fallback policy: Index-level direct API fallback if central cache empty
 * Reusable by multiple indices? Yes — any index needing maritime connectivity
 * Transformation belongs in: Reference Data stores raw LSCI. Index inverts to vulnerability.
 */
```

### The 8-Step Checklist

1. **Source definition** — Fill the template above
2. **Fetch function with retry/backoff** — Use `wp_remote_get()` with timeout, retry loop, exponential backoff
3. **Chunking/pagination** — Loop until API returns no more pages
4. **Per-chunk checkpointing** — Save partial progress after every chunk into option/transient
5. **Call logging from day one** — Write to `{source}_call_log` on every request
6. **Debug info option** — Store HTTP code, body snippet, parsed count in `{source}_debug`
7. **Admin sandbox integration** — Add test button to Reference Data admin page
8. **Staggered cron registration** — Register on a day that doesn't conflict with existing weekly crons (Mon=Maritime, Tue=EIA, Wed=HHI, Thu=WB, Fri=IMF)

---

## Admin Sandbox

The Reference Data admin page provides:
- Per-dataset flush/refresh buttons
- Cron health dashboard (last run, status, item counts, next scheduled time)
- API Sandbox: isolated single-target testing without exhausting quotas
- Comprehensive audit logs (Comtrade, EIA, WB, IMF call logs with outcome tracking)
- Debug inspector: raw dumps for maritime, reporters, EIA, HHI, WB, IMF summaries

---

## What to read next

- Data processing & validation → [`09-index-utilities.md`](09-index-utilities.md)
- How indices consume this data → [`02-data-flow.md`](02-data-flow.md)
- The exact API contract → [`03-api-contract.md`](03-api-contract.md)
- Building a new index → [`05-index-template.md`](05-index-template.md)
- The Blomstra-wide research standards → [`11-engineering-research-standards.md`](11-engineering-research-standards.md)
