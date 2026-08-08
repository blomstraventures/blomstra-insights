# CII Methodology Deep Dive

> **⚠️ This is a Layer C document — an index-specific case study.**
> For the Blomstra-wide research standards that govern how ALL indices measure things, see [`11-engineering-research-standards.md`](11-engineering-research-standards.md).
> For the shared processing utilities, see [`09-index-utilities.md`](09-index-utilities.md).
> For the generalized partial-coverage algorithm that works for any N-pillar index, see BMS-002 in [`11-engineering-research-standards.md`](11-engineering-research-standards.md).
> This document preserves CII's specific reasoning and math. Future indices should reference it for inspiration, not copy it unmodified.

---

## Step 1: Normalization (Percentile Rank)

For each raw indicator, countries are sorted by value and assigned a percentile rank from 0 (lowest) to 100 (highest).

```
Percentile Rank = ((rank - 0.5) / n) * 100
```

Where `rank` is the position in ascending order and `n` is the total number of countries with data for that indicator.

**Why percentiles?** Raw values have different scales and units. Percentiles put everything on a comparable 0–100 scale without assuming any particular distribution shape.

**Tie handling:** Countries with identical values receive the average of their ranks.

**Missing values:** Countries with no data for an indicator are excluded from that indicator's percentile calculation. They do not receive a rank of 0 — they receive no rank at all for that indicator.

See BMS-003 for the full Blomstra Normalization Standard.

> **Note:** CII was built before `blomstra_compute_percentile_ranks_safe()` existed. New indices should use the utilities version with optional winsorization.

---

## Step 2: Pillar Scoring

Each pillar combines its indicators into a single 0–100 score:

```
Pillar Score = (Σ indicator_percentile × indicator_weight) / (Σ indicator_weight)
```

Weights are renormalized to sum to 1.0 based on available indicators.

### CII Pillars

| Pillar | Indicators | Weights |
|--------|-----------|---------|
| Energy Dependency | Energy import dependency (%), Fossil fuel share (%) | 50/50 |
| Supplier Concentration | HHI of import partners | 100 |
| Maritime Exposure | LSCI (inverted) | 100 |

### Structural Zero Handling (CII Maritime)

Landlocked countries have no maritime connectivity. In CII, this is treated as a **structural zero** — a real observation of zero maritime exposure, not missing data. These countries participate in the Full Index with LSCI = 0.

This is the canonical Blomstra example of structural zero handling. See BMS-002.

> **Implementation note:** Use `blomstra_safe_numeric()` when processing maritime data to ensure `0.0` is preserved as data, not discarded as "empty."

---

## Step 3: Composite Score

```
Composite = (Σ pillar_score × pillar_weight) / (Σ pillar_weight)
```

For CII: all three pillars are equally weighted (33.33% each).

---

## Step 4: Coverage Classification

- **Full Index:** All 3 pillars present → definitive rank
- **Partial Index:** ≥ 2 pillars present → projected rank range
- **Excluded:** < 2 pillars → not scored

### Partial Index Rank Simulation (CII's 3-pillar case)

For a country missing exactly 1 pillar:

1. Compute the known weighted sum from the 2 present pillars at their real percentile values and real weights.
2. For the missing pillar, inject 5 candidate percentile values: `0, 10, 50, 90, 100`.
3. For each injected value, compute a hypothetical composite: `(known_weighted_sum + injected_value × missing_pillar_weight) / total_weight`.
4. Find what rank that hypothetical score would occupy among real Full-Index countries' actual scores.
5. `best_estimate` = rank from injecting **50**; `range_80_low/high` = ranks from **10/90**; `theoretical_low/high` = ranks from **0/100**.

**Example:**
- Known: Energy = 60 (33.3%), HHI = 80 (33.3%) → known sum = 46.67
- Missing: Maritime (33.3%)
- Inject P50 Maritime = 50 → hypothetical = (46.67 + 16.67) / 1.0 = 63.33 → rank #45
- Inject P10 Maritime = 10 → hypothetical = (46.67 + 3.33) / 1.0 = 50.00 → rank #38
- Inject P90 Maritime = 90 → hypothetical = (46.67 + 30.00) / 1.0 = 76.67 → rank #52

Result: `best_estimate: 45`, `range_80: 38–52`, `theoretical: 22–71`.

**Important limitation:** This simulation assumes exactly **one** missing pillar. With two missing pillars, the 5-point injection grid becomes a 25-point grid (5×5), and the interpretation becomes combinatorially complex. For indices with 4+ pillars, see BMS-002 for the generalized approach.

---

## Step 5: Why These Choices?

### Why percentiles instead of z-scores?
Percentiles are robust to outliers and don't assume normality. A country with an extreme raw value doesn't distort the entire distribution.

### Why equal pillar weights?
The three pillars represent conceptually distinct dimensions of vulnerability. No single dimension is considered more important than the others a priori.

### Why not fill in missing pillars with the mean?
Because that would pretend we know something we don't. The Partial Index explicitly communicates uncertainty rather than hiding it.

### Why structural zeros for landlocked countries?
Because "no maritime access" is a real structural condition, not missing information. Treating it as missing would exclude legitimate observations and bias the index.

---

## What to read next

- The generalized standards → [`11-engineering-research-standards.md`](11-engineering-research-standards.md)
- The shared processing utilities → [`09-index-utilities.md`](09-index-utilities.md)
- How this gets implemented in code → [`08-reference-data-functions.md`](08-reference-data-functions.md)
- Building a new index → [`05-index-template.md`](05-index-template.md)
