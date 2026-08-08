# Index Template

> **Before writing a single line of backend code for a new index, complete Gate 0 below.**
> This gate is a compliance checkpoint, not a suggestion. It exists because GERI shipped with placeholder partial-rank logic, fabricated indicators, and dead async code — all because the developer skipped the reference-implementation audit.

---

## Gate 0: Reference Implementation Audit (MUST PASS)

Search the repository for existing solutions to your problem. If any of these patterns already exist, you MUST reuse them unless your index's methodology document explicitly overrides them with a documented justification.

| Pattern | Where to look | Reuse? |
|---------|-------------|--------|
| Partial coverage / rank uncertainty | `src/indices/cii/cii-backend.php` — `cii_build_composite()` | MUST reuse BMS-002 |
| Structural zero handling | `blomstra_get_maritime_value()` in Reference Data | MUST reuse BMS-002 |
| Data provenance pattern | `blomstra_track_source()` in `index-utilities.php` | MUST reuse BMS-001 |
| Forecast / structural separation | `src/indices/geri/geri-backend.php` — Forward Pressure layer | MUST reuse BMS-006 |
| Historical trajectory / volatility | `blomstra_compute_cagr()` + `blomstra_sanitize_timeseries()` in `index-utilities.php` | MUST reuse BMS-005 |
| Cron safety (build lock, freshness gate) | `src/indices/cii/cii-backend.php` | MUST reuse pattern from `01-architecture.md` |
| Snapshot history | `blomstra_index_snapshot_save()` in Reference Data | MUST reuse |
| HHI / concentration | `blomstra_refresh_comtrade_hhi_data()` in Reference Data | MUST reuse if applicable |
| Normalization (percentile ranking) | `blomstra_compute_percentile_ranks_safe()` in `index-utilities.php` | MUST reuse BMS-003 |
| Safe numeric extraction | `blomstra_safe_numeric()` in `index-utilities.php` | MUST reuse |
| Fallback merging with provenance | `blomstra_merge_with_fallback()` in `index-utilities.php` | MUST reuse BMS-001 |
| Definition drift detection | `blomstra_validate_pillar_thresholds()` in `index-utilities.php` | MUST reuse BMS-004 |
| Version / vintage | `src/indices/geri/geri-backend.php` | MUST reuse BMS-005 |

**If you need to deviate:** Document the deviation in `src/indices/{slug}/docs/deviations.md` using the template in [`deviations.md`](deviations.md).

---

## Phase 1: Define the research question

What are we measuring? Why does it matter? What would falsify this index?

- [ ] Research question stated in one sentence
- [ ] Hypothesis: what should this index predict or explain?
- [ ] Falsifiability: what observation would prove this index wrong?

---

## Phase 2: Pillar design

### Coverage design (MUST answer all of these before backend work)

| Question | Your answer |
|----------|-------------|
| How many pillars? | |
| Minimum required? | |
| What happens with exactly 1 missing? | Use BMS-002 generalized injection algorithm |
| What happens with 2+ missing? | Excluded (BMS-002 assumes exactly 1 missing for rank simulation) |
| Is partial scoring permitted? | Yes / No |
| Is partial ranking permitted? | Yes (with uncertainty) / No |
| How is uncertainty represented? | P10–P90 + theoretical bounds (BMS-002) |
| What is definitive vs provisional? | Full = definitive; Partial = provisional |

### Structural zero check

For each indicator, answer: **"Can this indicator legitimately be zero?"**

| Indicator | Can be zero? | If yes, is it structural? |
|-----------|-------------|--------------------------|
| | | |

If an indicator can be structurally zero, it MUST be handled per BMS-002 (not treated as missing). Use `blomstra_safe_numeric()` to preserve zeros.

---

## Phase 3: Indicator selection

For each indicator, document:

| Pillar | Indicator | Source | Indicator ID | Unit | Directionality | Can be zero? | Fallback? | Scope? |
|--------|-----------|--------|--------------|------|-----------------|-------------|-----------|--------|
| | | | | | | | | |

**Directionality:** Does higher = more risk, or higher = less risk? This determines whether the indicator is inverted before percentile ranking.

**Fallback:** If primary source is missing, what is the acceptable fallback (per Source Hierarchy in [`02-data-flow.md`](02-data-flow.md))? Document prohibited fallbacks explicitly. Use `blomstra_merge_with_fallback()` for all fallback logic.

**Scope:** What fiscal/administrative scope does this indicator cover? (e.g., `central_gov`, `general_gov`, `federal_only`). Mixed scope within a pillar is a measurement non-equivalence risk.

---

## Phase 4: Data collection architecture

### Centralized or index-specific?

| Data source | Reusable by other indices? | Belongs in Reference Data? | Belongs in index? |
|-------------|---------------------------|---------------------------|-----------------|
| | | | |

**Rule:** Raw collection, pagination, chunking, and caching belong in Reference Data. Methodology-specific transformations (weighting, inversion, composite formula) belong in the index. Safe extraction and sanitization belong in `index-utilities.php`.

### Provenance requirements (BMS-001)

Every indicator MUST carry:
- `value` — the raw or normalized value
- `year` — observation year
- `source` — human-readable source name
- `data_type` — `observed`, `estimated`, `forecast`, `structural_zero`
- `retrieval_date` — when this value was fetched
- `vintage` — dataset version if applicable
- `status` — `valid`, `stale`, `fallback`, `missing`
- `scope` — fiscal/administrative scope if applicable

**Implementation:** Use `blomstra_track_source()` to build the provenance map, then attach it to the pillar data storage.

---

## Phase 5: One pillar fully done before starting the next

Not because pillars are inherently sequential — because debugging two simultaneously-incomplete pillars makes it ambiguous which one caused an odd composite result.

- [ ] Pillar 1: fetcher complete, provenance tracked, admin test button works
- [ ] Pillar 2: fetcher complete, provenance tracked, admin test button works
- [ ] Pillar 3: fetcher complete, provenance tracked, admin test button works
- [ ] (etc.)

**Each pillar fetcher MUST:**
1. Call Reference Data first
2. Have a complete fallback path using `blomstra_merge_with_fallback()`
3. Track provenance with `blomstra_track_source()`
4. Track `_last_fetched` and `_source`
5. Have an admin test button ("Fetch from Central" and "Fetch from API Directly")
6. Be verified against at least 3 known countries
7. Use `blomstra_safe_numeric()` for all value extraction

---

## Phase 6: Composite/scoring work comes last

**Do not write composite builder code before all pillar fetchers collect real data.**

Placeholder composite logic always becomes production logic. Every time.

### Composite builder requirements

- [ ] Uses `blomstra_compute_percentile_ranks_safe()` for normalization (BMS-003)
- [ ] Applies correct directionality (inversion) per indicator
- [ ] Uses declared weights, not equal averages
- [ ] Checks coverage against real sub-weights, not `100/count()`
- [ ] Distinguishes Full vs Partial vs Excluded
- [ ] For Partial: uses real injection simulation (BMS-002), not placeholder
- [ ] Never fabricates missing data
- [ ] Surfaces `data_freshness` in API output (BMS-001)
- [ ] Surfaces `data_quality` in API output (per-indicator staleness and coverage)
- [ ] Surfaces `coverage_type`, `is_definitive`, `pillars_used`, `pillars_missing`
- [ ] Calls `blomstra_validate_pillar_thresholds()` during initialization

### Generalized Partial Coverage Algorithm (MUST implement for N-pillar indices)

For an index with N pillars, equal weights (25% each for N=4), and `MIN_PILLARS_REQUIRED = N-1`:

```php
// For each partial country (exactly 1 missing pillar):
$known_weighted_sum = 0;
foreach ($available_pillars as $p) {
    $known_weighted_sum += $pillar_scores[$p] * $pillar_weights[$p];
}

$ranks_by_injection = array();
foreach (array(0, 10, 50, 90, 100) as $pct) {
    // Get the p-th percentile value from the global distribution of the missing pillar
    $injected_value = percentile($global_distribution[$missing_pillar], $pct);

    // Compute hypothetical composite with FULL weights (not renormalized)
    $hypothetical = ($known_weighted_sum + $injected_value * $pillar_weights[$missing_pillar])
        / array_sum($pillar_weights);

    // Find rank among real Full-Index composites
    $ranks_by_injection[$pct] = blomstra_rank_in_full_index($hypothetical, $full_composites_sorted);
}

$rank_display = blomstra_build_partial_rank_display($ranks_by_injection);
```

**Critical:** The hypothetical composite uses the **full original weights** in the denominator, not a renormalized subset. A 3/4 partial country does not get its three pillars magically reweighted to 33% each.

See [`11-engineering-research-standards.md`](11-engineering-research-standards.md) BMS-002 for the complete specification.

---

## Phase 7: Cron and automation

MUST implement all four reliability mechanisms from [`01-architecture.md`](01-architecture.md):

1. [ ] Self-healing build lock (5-minute TTL transient)
2. [ ] Freshness gate (skip build if any pillar is stale)
3. [ ] Shared code path (cron and manual test call same build function)
4. [ ] Two separate health signals (`{slug}_cron_status` + `{slug}_last_wpcron_fired`)

---

## Phase 8: REST endpoint and frontend wiring

- [ ] REST endpoint registered at `/wp-json/blomstra/v1/{slug}`
- [ ] Response conforms to [`03-api-contract.md`](03-api-contract.md)
- [ ] `_meta.standard_version` declared
- [ ] Frontend shortcode uses `data-biw-slug="{slug}"`
- [ ] `data-biw-pillars` JSON matches API field names exactly
- [ ] Watchlist, rank-delta, and excluded panel all tested

---

## Phase 9: Validation

Before publication:

- [ ] Historical backtest: do scores make sense for known crisis periods?
- [ ] Sensitivity test: perturb weights ±10%, check ranking stability
- [ ] One-indicator-removal test: does removing any single indicator drastically change top/bottom 10?
- [ ] Missing-data test: verify partial coverage produces plausible ranges
- [ ] Known-country sanity check: e.g., Switzerland should not be highest-risk
- [ ] External construct validation: correlate with an existing measure (without copying it)
- [ ] Zero-handling test: verify 0.0 values are treated as data, not missing
- [ ] Source-mixing test: verify mixed-scope countries are flagged correctly

---

## Guardrails (post-build)

- [ ] Don't add indicators just because they're available
- [ ] Don't let forecasts touch the structural layer
- [ ] Don't fabricate missing variables — missing stays missing
- [ ] Don't optimize weights to make rankings "look right"
- [ ] Don't silently restructure the index mid-implementation
- [ ] Don't use `empty()` on numeric fields — use `blomstra_safe_numeric()`
- [ ] Don't assume API sort order — use `blomstra_sanitize_timeseries()`
- [ ] Don't merge fallback data manually — use `blomstra_merge_with_fallback()`
- [ ] Document every deviation from Layer B standards in `deviations.md`

---

## What to read next

- The exact API shape → [`03-api-contract.md`](03-api-contract.md)
- The Blomstra-wide research standards → [`11-engineering-research-standards.md`](11-engineering-research-standards.md)
- The shared integrity layer → [`09-index-utilities.md`](09-index-utilities.md)
- How to deploy → [`06-deployment.md`](06-deployment.md)
