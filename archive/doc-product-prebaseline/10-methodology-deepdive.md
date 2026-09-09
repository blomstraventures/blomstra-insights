# Methodology Deep Dive

> **Applies to:** SERI v4.2.1+, SIVI v2.0.0+
> **Standard:** BMS-1.0.0

---

## Core Philosophy

Blomstra indices are **structural, not predictive**. They measure the current state of a country's foundations and systems, not the probability of a specific crisis. The methodology is designed to be:

1. **Fully transparent** -- every score traceable to a public data source
2. **Reproducible** -- same code + same data = same scores
3. **Comparative** -- scores are percentile ranks within the global cross-section, not absolute thresholds
4. **Honest about uncertainty** -- partial indices get rank ranges, not false precision

---

## Percentile Normalization

### Why Percentiles?

Most risk indices use fixed thresholds (e.g., "debt > 90% of GDP = high risk"). This has two problems:

1. **Secular drift:** Global debt has risen everywhere since the 1990s. A 90% threshold that was "crisis level" in 2000 is now "normal" for advanced economies.
2. **Context blindness:** A 5% inflation rate means different things in Switzerland (unusual) versus Argentina (extremely low).

**Percentile ranks solve both.** A country at the 90th percentile for debt is in the top 10% most indebted countries **right now**, regardless of absolute level.

### How It Works

![Percentile Normalization](../assets/diagram-06-percentile-normalization.png)

```
1. Collect raw values for all countries: { USA: 120.5, JPN: 260.3, ... }
2. Sort ascending
3. Assign ranks (handling ties via average rank)
4. Convert to percentile: (rank - 0.5) / N * 100
```

**Example:**

| Country | Raw Debt/GDP | Rank (of 100) | Percentile |
|---|---|---|---|
| Singapore | 30 | 10 | 9.5 |
| USA | 120 | 75 | 74.5 |
| Japan | 260 | 99 | 98.5 |

### Inversion

Not all indicators point in the same direction:

| Indicator | Raw Direction | Vulnerability Direction |
|---|---|---|
| Government debt/GDP | Higher = more debt | Higher = more vulnerable |
| Reserve months | Higher = more reserves | Higher = less vulnerable -> **invert** |
| Maritime connectivity | Higher = more connected | Higher = less vulnerable -> **invert** |
| Rule of law (WGI) | Higher = better governance | Higher = less vulnerable -> **invert** |

Inversion is applied **before** percentile computation:

```php
// Governance: invert WGI (high WGI = low vulnerability)
$values[$iso3] = 100 - $row['value'];

// Maritime: invert connectivity
$maritime_vulnerability_pct[$iso3] = 100 - $connectivity_pct[$iso3];
```

### Winsorization

Outliers can distort percentile ranks. SERI winsorizes inflation at 1%:

```php
$percentiles['inflation'] = blomstra_compute_percentile_ranks_safe( $values, 0.01 );
```

This caps extreme outliers (e.g., hyperinflation at 10,000%) at the 1st/99th percentile, preventing them from compressing all other countries into a narrow band.

---

## Pillar Aggregation

### Weighted Average

Within each pillar, indicators are aggregated by their defined weights:

```php
$gov_weighted_sum = 0;
$gov_weight_total = 0;
foreach ( $gov_weights as $ind => $w ) {
    if ( isset( $percentiles[$ind][$iso3] ) ) {
        $gov_weighted_sum += $percentiles[$ind][$iso3] * $w;
        $gov_weight_total += $w;
    }
}
$pillar_score = $gov_weighted_sum / $gov_weight_total;
```

### Minimum Requirements

Each pillar has a minimum threshold:

| Pillar | Min Indicators | Min Weight | Index |
|---|---|---|---|
| Governance | 3/3 | 100% | SERI |
| Macro | 4/5 | 80% | SERI |
| External | 3/4 | 60% | SERI |
| Fiscal | 2/3 | 70% | SERI |
| Energy | 1/1 | 100% | SIVI |
| HHI | 1/1 | 100% | SIVI |
| Maritime | 1/1 | 100% | SIVI |

If a country does not meet the minimum for a pillar, that pillar is null for that country.

---

## Composite Aggregation

### Full Index (All Pillars Present)

```php
$weighted_sum = 0;
$total_weight = 0;
foreach ( $all_pillars as $p ) {
    $weighted_sum += $available_pillars[$p] * $composite_weights[$p];
    $total_weight += $composite_weights[$p];
}
$composite = $weighted_sum / $total_weight;
```

### Partial Index (Missing One Pillar)

Weights are **re-normalized** over available pillars:

```php
$available_weight = 0;
foreach ( $available_pillars as $p => $score ) {
    $available_weight += $composite_weights[$p];
}
$weighted_sum = 0;
foreach ( $available_pillars as $p => $score ) {
    $weighted_sum += $score * ( $composite_weights[$p] / $available_weight );
}
$composite = $weighted_sum;
```

**Example:** If energy is missing and weights are 33/33/34, the remaining two pillars are re-weighted to 50/50.

---

## Rank Assignment

![Rank Assignment](../assets/diagram-07-rank-assignment.png)

### Full Index Ranks

Countries with all pillars get a **definitive rank**:

```php
arsort( $full_composites ); // Descending: highest vulnerability = #1
$rank = 1;
foreach ( $full_composites as $iso3 => $score ) {
    $full_rank_map[$iso3] = $rank++;
}
```

### Partial Index Ranks (OECD/JRC Compliant)

Partial countries get a **projected rank range** because their true rank depends on the unknown value of the missing pillar.

**Method:**
1. Compute the known pillars' weighted contribution
2. Inject the missing pillar at percentiles 0, 10, 50, 90, 100
3. Compute the hypothetical composite for each injection
4. Look up each hypothetical composite in the full-index sorted array
5. Report:
   - **Best estimate:** Median injection (50th percentile)
   - **80% plausible range:** 10th-90th percentile injection
   - **Theoretical bounds:** 0th-100th percentile injection

**Why this is correct:**
- It does not fabricate data
- It does not exclude the country
- It communicates uncertainty honestly
- It follows OECD/JRC guidelines for composite indicators with missing data

**Example output:**

```json
{
  "rank_display": {
    "is_definitive": false,
    "best_estimate": 45,
    "range_80_low": 38,
    "range_80_high": 52,
    "theoretical_low": 12,
    "theoretical_high": 89,
    "string_format": "#38-#52*"
  }
}
```

---

## Structural Zeros

A **structural zero** is a zero value that is methodologically correct, not a missing data placeholder.

### SIVI Maritime

Landlocked countries have no maritime connectivity by geography, not by data absence. They receive:

```php
'maritime_connectivity' => array(
    'value' => 0.0,
    'source' => 'Structural zero -- landlocked',
)
```

This is **not** treated as missing data. The country can still achieve a Full Index score because the zero is a real, meaningful value.

**Why this matters:** If landlocked countries were excluded from maritime, they would all be forced into Partial Index status, artificially deflating their vulnerability scores (since maritime vulnerability would not be counted).

---

## Forward Pressure (SERI Only)

### Concept

Forward pressure measures whether IMF WEO forecasts suggest a country's structural risk is likely to **deteriorate**, **improve**, or remain **stable**.

### Method

1. Fetch current IMF estimates and 1-year forecasts for 6 indicators:
   - GDP growth, inflation, current account, government debt, government balance, unemployment
2. Compute delta: `forecast - current`
3. For "good" indicators (growth, current account, balance), negate the delta (improvement = negative delta)
4. Convert deltas to percentile ranks across all countries
5. Average the percentile ranks
6. Classify:
   - Average delta > 0.5 -> "Deteriorating"
   - Average delta < -0.5 -> "Improving"
   - Otherwise -> "Stable"

**Limitation:** Forward pressure requires at least 4 of 6 forecast indicators. Countries with sparse IMF coverage get null.

---

## Sensitivity Testing

### Purpose

Sensitivity testing answers: *"How much do rankings change if we shift pillar weights?"* This is essential for:

1. **Robustness validation** -- if rankings are stable across weight changes, the index is robust
2. **Policy analysis** -- e.g., "what if fiscal stress matters more than governance?"
3. **Research transparency** -- demonstrating that no single pillar dominates

### Method

1. Define preset weight schemes (e.g., "fiscal-heavy": 60/15/15/10)
2. Build composite with custom weights
3. Store scenario separately (never overwrites baseline)
4. Compute Spearman correlation (rho) between scenario ranks and baseline ranks
5. Identify "top mover" -- country with largest absolute rank change

### Interpretation

| rho | Interpretation |
|---|---|
| 1.0 | Identical order -- the reweighted pillar carries no independent information |
| 0.9-0.99 | Minor reshuffle -- the reweighted pillar is largely redundant |
| 0.7-0.89 | Moderate reshuffle -- the reweighted pillar carries some unique information |
| 0.5-0.69 | Major reshuffle -- the reweighted pillar is highly influential and independent |
| <0.5 | Radical reshuffle -- the index is unstable under this weighting |

**A healthy index has "light" scenarios >= 0.9 and "heavy" scenarios between 0.7-0.85.**

---

## Data Quality Scoring

### Source Hierarchy

| Tier | Source Type | Example |
|---|---|---|
| 1 | Primary, recent | IMF WEO general government debt (current year) |
| 2 | Primary, older | IMF WEO general government debt (2 years old) |
| 3 | Fallback, recent | WB WDI central government debt (current year) |
| 4 | Fallback, older | WB WDI central government debt (2 years old) |
| 5 | Derived | 5-year CAGR from WB history |

### Quality Score Algorithm

```php
function blomstra_pillar_quality_score( $all_sources, $iso3, $indicators ) {
    $scores = array();
    foreach ( $indicators as $ind ) {
        $sources = $all_sources[$iso3][$ind] ?? array();
        if ( empty( $sources ) ) {
            $scores[] = 0;
        } else {
            $latest = end( $sources );
            if ( $latest['source'] === 'primary' && $latest['year'] >= current_year - 1 ) {
                $scores[] = 3;
            } elseif ( $latest['source'] === 'primary' ) {
                $scores[] = 2;
            } else {
                $scores[] = 1;
            }
        }
    }
    return round( array_sum( $scores ) / count( $scores ) );
}
```

**Scale:** 0 = no data, 1 = poor, 2 = mixed, 3 = good.

---

## Methodology Notes by Index

### SERI

![SERI Pillars](../assets/diagram-04-seri-pillars.png)

> "Fiscal pillar uses IMF WEO general government debt as primary, World Bank central government as fallback. Debt trajectory uses CAGR with 4+ years required for 'good' quality. GNI growth is NOT imputed from GDP. External debt uses only DT.DOD.DECT.GN.ZS (stock % GNI) -- no fallback is used because no equivalent stock indicator exists."

### SIVI

![SIVI Pillars](../assets/diagram-05-sivi-pillars.png)

> "Energy dependency is computed as a consumption-weighted average across 5 fuel types (coal, natural gas, petroleum, nuclear, renewables). Countries with no EIA data for any fuel are excluded from the energy pillar. HHI is computed from UN Comtrade import partner concentration. Maritime connectivity uses World Bank LSCI; landlocked countries receive a structural zero of 0.0."
