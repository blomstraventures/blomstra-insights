# Blomstra Engineering & Research Standards

> **Layer B: How Blomstra measures things.**
> These standards apply to every Blomstra index unless the index's methodology document explicitly overrides them with a documented justification.
> Every deviation MUST be recorded in `src/indices/{slug}/docs/deviations.md`.
> This document is versioned. Indices declare which version they conform to in `_meta.standard_version`.

**Current version:** BMS-1.0.0

---

## BMS-001: Data Provenance Standard

### Purpose
Every data point in every Blomstra index must be traceable: where it came from, when it was retrieved, what version, and whether it was transformed.

### Rules

1. **Every indicator MUST carry provenance metadata.** The minimum required fields are:
   - `value` — the raw or normalized value
   - `year` — observation year (the year the data point represents, not the retrieval year)
   - `source` — human-readable source name (e.g., "World Bank WDI", "IMF WEO April 2026")
   - `data_type` — one of: `observed`, `estimated`, `forecast`, `structural_zero`
   - `retrieval_date` — ISO date when this value was fetched
   - `vintage` — dataset version if applicable (e.g., "April 2026" for IMF WEO)
   - `status` — one of: `valid`, `stale`, `fallback`, `missing`

2. **Provenance MUST be exposed in the API output.** The `data_freshness` object in the per-country response is mandatory, not optional.

3. **Fallback values MUST be flagged.** If a value comes from a fallback source (e.g., GDP growth used when GNI growth is missing), the status MUST be `fallback` and the source MUST indicate the fallback path.

4. **Structural zeros MUST be explicitly marked.** A value of exactly `0.0` is NOT automatically `missing`. It MUST carry `data_type: structural_zero` if it represents a known real-world zero.

### Reference Implementation
GERI's `data_freshness` object in `geri_build_composite()`.

---

## BMS-002: Missing Data & Partial Coverage Standard

### Purpose
Define how Blomstra handles incomplete data: when to exclude, when to simulate, when to project, and when a zero is actually data.

### Data-State Taxonomy

Every value that enters an index builder MUST carry a data state:

| State | Meaning | Scoring Treatment |
|-------|---------|-------------------|
| `observed` | Real published value | Use as-is |
| `structural_zero` | Known real-world zero | Use as-is (0 is valid) |
| `estimated` | Source identifies as estimate | Use as-is, flag provenance |
| `forecast` | Forward-looking projection | Quarantine to forecast layer only (BMS-006) |
| `stale` | Exceeds acceptable age | Do not use; trigger refresh |
| `unavailable` | Cannot currently be obtained | Treat as missing |
| `not_applicable` | Indicator genuinely doesn't apply | Treat as missing (NOT zero) |
| `collection_failure` | Technical/API failure | Treat as missing; log for retry |
| `missing` | No usable observation | Do not fabricate |

**Critical rule:** A value of exactly `0.0` from a source is NOT automatically `missing`. It MUST be classified based on domain knowledge. Example: a landlocked country has LSCI = 0. This is a `structural_zero`, not `missing`.

### Coverage Levels

| Level | Name | Condition | Rank | Score |
|-------|------|-----------|------|-------|
| Level 1 | Full | All required pillars available | Definitive | Definitive |
| Level 2 | Partial | Minimum pillars available (typically N-1 of N) | Projected range | Best estimate |
| Level 3 | Insufficient | Below minimum pillars | None | None |
| Level 4 | Structural zero | Known real-world zero | Valid | Valid |
| Level 5 | Missing | No reliable observation | None | None |

### Partial Coverage: Generalized N-Pillar Algorithm

For any index with N pillars, equal or declared weights, and `MIN_PILLARS_REQUIRED = N-1`:

**Assumption:** This algorithm assumes exactly **one** missing pillar. If 2+ pillars are missing, the country is Level 3 (Insufficient) and receives no score or rank.

**Algorithm:**

```php
// Step 1: Identify available and missing pillars
$available_pillars = array();
$missing_pillar = null;
foreach ($all_pillars as $p) {
    if (isset($pillar_scores[$p]) && is_numeric($pillar_scores[$p])) {
        $available_pillars[] = $p;
    } else {
        $missing_pillar = $p;
    }
}

if (count($available_pillars) < N - 1) {
    // Level 3: Insufficient
    $country['coverage_type'] = 'excluded';
    continue;
}

if (!$missing_pillar) {
    // Level 1: Full
    $country['coverage_type'] = 'full';
    $country['is_definitive'] = true;
    // ... compute definitive rank ...
    continue;
}

// Level 2: Partial — exactly one missing pillar
$known_weighted_sum = 0;
foreach ($available_pillars as $p) {
    $known_weighted_sum += $pillar_scores[$p] * $pillar_weights[$p];
}

// Step 2: Get global distribution of the missing pillar
$global_values = array();
foreach ($all_countries as $iso3 => $data) {
    if (isset($data['pillars'][$missing_pillar]) && is_numeric($data['pillars'][$missing_pillar])) {
        $global_values[] = $data['pillars'][$missing_pillar];
    }
}
sort($global_values);

// Step 3: Inject at 5 percentile points and compute hypothetical composites
$ranks_by_injection = array();
foreach (array(0, 10, 50, 90, 100) as $pct) {
    // Interpolate the p-th percentile value from the global distribution
    $n = count($global_values);
    $rank_idx = ($pct / 100) * ($n - 1);
    $low = floor($rank_idx);
    $high = ceil($rank_idx);
    if ($low == $high) {
        $injected_value = $global_values[$low] ?? 0;
    } else {
        $frac = $rank_idx - $low;
        $injected_value = $global_values[$low] * (1 - $frac) + $global_values[$high] * $frac;
    }

    // Compute hypothetical composite with FULL weights (not renormalized)
    $hypothetical = ($known_weighted_sum + $injected_value * $pillar_weights[$missing_pillar]) 
                    / array_sum($pillar_weights);

    // Find rank among real Full-Index composites
    $ranks_by_injection[$pct] = blomstra_rank_in_full_index($hypothetical, $full_composites_sorted);
}

// Step 4: Build rank display
$country['rank_display'] = blomstra_build_partial_rank_display($ranks_by_injection);
$country['is_definitive'] = false;
```

**Key properties:**
- The hypothetical composite uses the **full original weights** in the denominator. A 3/4 partial country does not get its three pillars reweighted to 33% each.
- The injection uses the **global distribution** of the missing pillar, not the country's own distribution.
- `best_estimate` = rank from P50 injection (the median scenario).
- `range_80_low/high` = ranks from P10/P90 injection (the 80% plausible range).
- `theoretical_low/high` = ranks from P0/P100 injection (absolute bounds).

**Important limitation:** This algorithm assumes exactly one missing pillar. For indices where 2+ pillars could be missing while still meeting `MIN_PILLARS_REQUIRED`, the combinatorics become complex (5^M grid for M missing pillars). In such cases, the index methodology MUST explicitly define an alternative approach and document it as a deviation.

### Structural Zero Rules

1. **Zero is not automatically missing.** A value of exactly `0.0` from a source MUST be evaluated for whether it is a structural zero.
2. **Structural zeros participate in the Full Index.** They are real observations.
3. **The decision to treat a value as structural zero MUST be documented in the index methodology.** It cannot be an ad-hoc coding decision.
4. **Examples of structural zeros:**
   - Landlocked country: LSCI = 0 (no maritime access is a real condition)
   - Country with no external debt: external debt = 0% of GNI
   - Country with no military: defense spending = 0% of GDP

### Reference Implementation
CII's partial coverage and landlocked handling in `cii-backend.php`.

---

## BMS-003: Normalization Standard

### Purpose
Ensure all indicators are comparable before entering composite calculation.

### Rules

1. **Primary method: percentile ranking.**
   ```
   Percentile Rank = ((rank - 0.5) / n) * 100
   ```
   Where `rank` is the position in ascending order and `n` is the total number of countries with data.

2. **Tie handling:** Countries with identical values receive the average of their ranks.

3. **Directionality MUST be explicit.** For each indicator, document whether higher = more risk or higher = less risk. If higher = less risk, the indicator MUST be inverted (negated) BEFORE percentile ranking.

4. **Missing values are excluded from the normalization population.** A country with missing data for an indicator does not receive percentile 0 — it receives no percentile for that indicator.

5. **Alternative methods (z-score, min-max) MAY be used with documented justification.** Any deviation from percentile ranking MUST be recorded in `deviations.md`.

### Reference Implementation
`blomstra_compute_percentile_ranks()` in Reference Data.

---

## BMS-004: Weighting Standard

### Purpose
Ensure weights are transparent, consistent, and correctly applied.

### Rules

1. **Pillar weights and indicator weights MUST be declared explicitly.** They MUST NOT be hardcoded as magic numbers in the composite builder.

2. **Weights MUST be exposed in the API output.** The `weights` object in the top-level response is mandatory.

3. **Renormalization for partial coverage:** When a pillar is missing, the remaining pillars' weights are implicitly renormalized by dividing by the sum of present weights. This happens automatically in the weighted average formula:
   ```
   composite = (Σ present × weight) / (Σ present weights)
   ```

4. **The composite builder MUST check coverage against real sub-weights, not `100/count()`.** A country with 2 of 3 pillars where those 2 sum to 70% weight should NOT pass a 60% threshold just because 2/3 ≈ 67%.

5. **Weight changes MUST trigger a MAJOR version bump** if they alter the interpretation of scores.

### Reference Implementation
GERI's `geri_get_pillar_defs()` and weight arrays in `geri_build_composite()`.

---

## BMS-005: Temporal Data Standard

### Purpose
Ensure time-sensitive data is handled consistently across indices.

### Rules

1. **Observation year vs. retrieval year:** These are distinct. The `year` field is when the data was observed/published. The `retrieval_date` is when Blomstra fetched it.

2. **Latest available:** For most indicators, use the most recent year available per country. Do not force all countries to the same year if data availability differs.

3. **Stale data threshold:** Data older than the methodology's acceptable age (typically 2 years for annual data) MUST be marked `status: stale` and not used in scoring.

4. **Historical windows:**
   - Volatility: 5-year window (current year − 5 to current year)
   - Trajectory: 3–5 year window
   - Minimum observations: 4 for volatility, 2 for trajectory

5. **Vintage:** Datasets with explicit versions (e.g., IMF WEO April 2026) MUST record the vintage. Datasets revised in place (e.g., World Bank WDI) record `null` or `"live"`.

6. **The `reference_vintage` field in API output MUST reflect the actual data vintage**, not a hardcoded year.

### Reference Implementation
GERI's `geri_fetch_history_5yr()`, `reference_vintage`, and `weo_vintage` handling.

---

## BMS-006: Forecast Separation Standard

### Purpose
Ensure forward-looking projections are never mixed with observed/estimated structural data.

### Rules

1. **Structural layer:** Composed of observed and estimated data only. No forecasts allowed.

2. **Forecast layer:** A separate, optional layer that presents forward-looking projections (e.g., IMF WEO T+1).

3. **IMF forecasts MUST NOT be used as fallback for missing structural data.** If World Bank historical data is missing, the value is missing. Do not substitute an IMF forecast.

4. **Forecast deltas MAY be computed** (forecast − current) and ranked cross-sectionally, but these deltas MUST be presented as a separate metric, not blended into the structural score.

5. **The forecast layer MUST be clearly labeled** in the API output and frontend.

### Reference Implementation
GERI's Forward Pressure layer in `geri-backend.php`.

---

## BMS-007: Country Universe Standard

### Purpose
Ensure consistent country inclusion/exclusion across indices.

### Rules

1. **Aggregate entities MUST be filtered out.** Regions, country groups, and historical aggregates (e.g., "World", "Sub-Saharan Africa", "High income") must not appear in the country list used for scoring.

2. **The country universe MUST be sourced from a single canonical list.** Use `blomstra_get_global_country_list()` from Reference Data.

3. **Microstates and territories MAY be excluded with documented justification.** If an index excludes countries below a population threshold, this rule MUST be documented in the methodology.

4. **Country code standard:** ISO 3166-1 alpha-3 (ISO3) is mandatory. ISO2 may be used for display but ISO3 is the canonical key.

### Reference Implementation
Governance Capture's aggregate filtering logic.

---

## Standard Versioning

This document follows SemVer:
- **MAJOR:** New standard added, or existing standard changed incompatibly
- **MINOR:** New guidance or clarification added
- **PATCH:** Typo fixes, examples added, no rule changes

Indices declare conformance in `_meta.standard_version`:
```json
{
  "_meta": {
    "standard_version": "BMS-1.0.0"
  }
}
```

When Layer B standards change:
1. Existing indices SHOULD be updated to conform to the new version
2. If an existing index cannot conform, the deviation MUST be documented
3. New indices MUST conform to the latest version

---

## What to read next

- How to build a new index using these standards → [`05-index-template.md`](05-index-template.md)
- The CII case study → [`09-methodology-deepdive.md`](09-methodology-deepdive.md)
- How to document deviations → [`deviations.md`](deviations.md)
