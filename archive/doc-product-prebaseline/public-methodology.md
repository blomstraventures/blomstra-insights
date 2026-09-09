---
title: "Blomstra Insights Methodology | SERI & SIVI Index Computation"
description: "Transparent, reproducible methodology for the Sovereign Economic Resilience Index (SERI) and Sovereign Infrastructure Vulnerability Index (SIVI). Percentile-rank normalization, OECD/JRC-compliant partial-index handling, and fully traceable public data sources."
keywords: "sovereign resilience index, infrastructure vulnerability index, economic risk ranking, country risk score, percentile normalization, composite indicator methodology, UN Comtrade HHI, World Bank governance data, IMF WEO fiscal data"
author: "Blomstra Insights Research Team"
date: "2026-08-11"
version: "BMS-1.0.0"
---

# Blomstra Insights Methodology

> **Transparent. Reproducible. Citable.**
>
> Every score is traceable to a public data source. No black-box models. No hidden weights.

---

## What Is Blomstra Insights?

**Blomstra Insights** is a multi-index strategic intelligence platform that measures sovereign resilience and infrastructure vulnerability through fully transparent, academically rigorous composite indicators.

Unlike traditional risk ratings that rely on opaque expert judgment or proprietary models, Blomstra indices are:

- **Fully traceable** — every score maps to a public data point from the World Bank, IMF, UN Comtrade, or EIA
- **Reproducible** — same inputs + published methodology = identical outputs
- **Comparative** — percentile-rank normalization ensures scores reflect relative standing, not arbitrary thresholds
- **Honest about uncertainty** — countries with incomplete data receive projected rank ranges, not false precision

---

## The Three-Layer Risk Framework

![Three-Layer Risk Model](assets/diagram-01-three-layer-risk-model.png)

Blomstra Insights does not produce a single "risk score." Instead, it offers **three complementary lenses** that interact to reveal a country's total risk profile:

| Layer | Index | Question It Answers | Update Frequency |
|-------|-------|-------------------|------------------|
| **Structural Foundations** | **SERI** | How capable is the state of absorbing shocks? | Quarterly / Annual |
| **System Vulnerability** | **SIVI** | How exposed are essential infrastructure systems? | Quarterly / Annual |
| **Event Risk** | **GPRI** *(planned)* | Is the neighborhood on fire? | Monthly / Event-driven |

A country's total risk is the **interaction** of these layers, not their sum.

---

## SERI — Sovereign Economic Resilience Index

![SERI Pillars](assets/diagram-04-seri-pillars.png)

**SERI (v4.2.1)** measures a country's capacity to absorb economic and political shocks across four structural pillars:

### 1. Governance (25%)
Drawn from the World Bank's **Worldwide Governance Indicators (WGI)**:
- Rule of Law
- Control of Corruption
- Political Stability & Absence of Violence

*High governance scores indicate low vulnerability. Values are inverted before aggregation.*

### 2. Macro Stability (25%)
Drawn from the World Bank's **World Development Indicators (WDI)**:
- GNI per capita growth
- Inflation rate
- Unemployment
- GDP growth volatility (5-year standard deviation)
- Inflation volatility (5-year standard deviation)

### 3. External Vulnerability (25%)
Drawn from the World Bank **WDI**:
- Foreign reserves (months of import cover)
- External debt stock (% of GNI)
- Current account balance (% of GDP)

*High reserves and low debt indicate low vulnerability. Reserve and debt indicators are directionally adjusted.*

### 4. Fiscal Stress (25%)
Primary source: **IMF World Economic Outlook (WEO)**. Fallback: World Bank WDI.
- General government debt (% of GDP)
- General government balance (% of GDP)
- Debt trajectory (5-year compound annual growth rate)

*A country must have data for at least 3 of 4 pillars to receive a SERI composite score.*

---

## SIVI — Sovereign Infrastructure Vulnerability Index

![SIVI Pillars](assets/diagram-05-sivi-pillars.png)

**SIVI (v2.0.0)** measures exposure to disruption across three essential infrastructure systems:

### 1. Energy Dependency (33.33%)
Drawn from the **U.S. Energy Information Administration (EIA)**:
- Consumption-weighted dependency across five fuel types: coal, natural gas, petroleum, nuclear, and renewables
- Higher dependency on imported energy = higher vulnerability

### 2. Supplier Concentration / HHI (33.33%)
Drawn from **UN Comtrade** bilateral import data:
- Herfindahl-Hirschman Index (HHI) of import partner concentration
- Scale: 0–10,000. Higher concentration = higher vulnerability
- Computed from all reported import partners for each country

### 3. Maritime Connectivity (33.34%)
Drawn from the World Bank **Liner Shipping Connectivity Index (LSCI)**:
- Higher connectivity = lower vulnerability (inverted in the composite)
- **Landlocked countries** receive a structural zero of 0.0 — this is a real geographic constraint, not missing data

*A country must have data for at least 2 of 3 pillars to receive a SIVI composite score.*

---

## Core Methodology

### Percentile-Rank Normalization

![Percentile Normalization](assets/diagram-06-percentile-normalization.png)

Most risk indices use fixed thresholds (e.g., "debt > 90% of GDP = crisis"). This creates two problems:

1. **Secular drift** — global debt has risen everywhere since the 1990s; a 90% threshold that signaled crisis in 2000 is now normal for advanced economies
2. **Context blindness** — a 5% inflation rate means something very different in Switzerland versus Argentina

**Blomstra solves both with percentile ranks.** Every raw indicator value is converted to its position within the global cross-section:

```
Percentile = (Rank − 0.5) / N × 100
```

A country at the **90th percentile** for government debt is in the top 10% most indebted countries **right now** — regardless of absolute level. This makes the index:

- **Secular-drift-proof** — scores remain comparable across decades
- **Context-aware** — automatically adjusts for global macro conditions
- **Fully reproducible** — any researcher can replicate the ranking with the same data

### Inversion Rules

Not all indicators point in the same direction. We invert "good" indicators so that **higher percentile = higher vulnerability** across all pillars:

| Indicator | Raw Direction | Vulnerability Direction |
|---|---|---|
| Government debt/GDP | Higher = more debt | Higher = more vulnerable |
| Reserve months | Higher = more reserves | **Inverted** — higher = less vulnerable |
| Maritime connectivity | Higher = more connected | **Inverted** — higher = less vulnerable |
| Rule of law (WGI) | Higher = better governance | **Inverted** — higher = less vulnerable |

### Winsorization

Extreme outliers (e.g., hyperinflation > 10,000%) can compress all other countries into a narrow band. SERI winsorizes inflation at the 1st and 99th percentiles to preserve distributional shape without letting outliers dominate.

---

## Partial Indices & Honest Uncertainty

![Rank Assignment](assets/diagram-07-rank-assignment.png)

Many countries lack complete data for all pillars. Rather than exclude them or fabricate missing values, Blomstra follows **OECD / JRC guidelines** for composite indicators with missing data:

### Full Index Countries
Countries with all pillars present receive a **definitive rank** (e.g., #45 of 182).

### Partial Index Countries
Countries with at least the minimum required pillars — but not all — receive a **projected rank range**:

| Projection | Meaning |
|---|---|
| **Best estimate** | Rank if the missing pillar were at the global median (50th percentile) |
| **80% plausible range** | Rank if the missing pillar were at the 10th or 90th percentile |
| **Theoretical bounds** | Rank if the missing pillar were at the 0th or 100th percentile |

**Example:** A partial-index country might display as **#38–#52\*** instead of a single false-precision rank.

This approach:
- **Does not fabricate data**
- **Does not exclude the country**
- **Communicates uncertainty honestly**
- **Is fully compliant with OECD/JRC composite indicator standards**

### Structural Zeros

Some zeros are real, not missing data. Landlocked countries have **zero maritime connectivity by geography**, not by data absence. These countries receive a structural zero in the maritime pillar, remain in the **Full Index**, and their vulnerability score correctly reflects this geographic constraint.

If landlocked countries were excluded from maritime instead, they would all be forced into Partial Index status — artificially deflating their vulnerability.

---

## Data Sources

All Blomstra indices rely exclusively on **public, third-party data sources**. No proprietary surveys, no expert judgment, no black-box estimation.

| Source | Data Type | Indices Using It |
|---|---|---|
| **World Bank WGI** | Governance indicators | SERI |
| **World Bank WDI** | Macro, external, maritime indicators | SERI, SIVI |
| **IMF WEO** | Fiscal debt, balance, forecasts | SERI |
| **UN Comtrade** | Bilateral trade flows, HHI computation | SIVI |
| **EIA API** | Energy consumption & production | SIVI |

**Data quality is scored per pillar, per country:**
- **3** = Good (recent, primary source)
- **2** = Mixed (primary but older, or mixed sources)
- **1** = Poor (fallback source or significantly dated)
- **0** = No data

---

## Sensitivity & Robustness Testing

Blomstra indices are tested for robustness to weight changes. We compute **Spearman rank correlations (ρ)** between the baseline composite and alternative weight scenarios:

| ρ Range | Interpretation |
|---|---|
| 0.90 – 1.00 | Highly robust — rankings are stable |
| 0.70 – 0.89 | Moderately robust — the reweighted pillar carries independent information |
| < 0.70 | Low robustness — the index is sensitive to this weighting |

A healthy index shows high stability for "light" perturbations and meaningful but not radical reshuffling for "heavy" alternative weightings.

**Sensitivity testing is mandatory for every index release.** Results are available upon request for academic verification.

---

## Forward Pressure (SERI Only)

SERI includes a **forward pressure** signal derived from IMF WEO forecasts. It measures whether a country's structural risk trajectory is likely to:

- **Deteriorate** — forecast deltas suggest worsening fundamentals
- **Improve** — forecast deltas suggest strengthening fundamentals
- **Remain Stable** — no directional signal

Forward pressure requires at least 4 of 6 forecast indicators (GDP growth, inflation, current account, government debt, government balance, unemployment). Countries with sparse IMF coverage receive no forward-pressure signal.

*Forward pressure is labeled as a projection, not a prediction. It reflects forecast deltas, not crisis probability.*

---

## How to Use & Cite Blomstra Indices

### REST API Access

Blomstra indices are available via a public REST API:

```
GET /wp-json/blomstra/v1/geo-economic-risk-index          # SERI
GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index  # SIVI
```

No API key required for read access. Responses include:
- Composite scores (0–100 percentile scale)
- Per-pillar breakdowns
- Rank displays (definitive or projected ranges)
- Data quality scores
- Measurement flags
- Source provenance

### Academic Citation

```bibtex
@techreport{blomstra2026seri,
  title={Sovereign Economic Resilience Index (SERI) v4.2.1},
  author={{Blomstra Insights Research Team}},
  year={2026},
  institution={Blomstra Ventures},
  note={Methodology standard BMS-1.0.0. Data sources: World Bank WGI/WDI, IMF WEO.},
  url={https://blomstra.com/insights}
}

@techreport{blomstra2026sivi,
  title={Sovereign Infrastructure Vulnerability Index (SIVI) v2.0.0},
  author={{Blomstra Insights Research Team}},
  year={2026},
  institution={Blomstra Ventures},
  note={Methodology standard BMS-1.0.0. Data sources: EIA, UN Comtrade, World Bank WDI.},
  url={https://blomstra.com/insights}
}
```

### Attribution Requirements

When publishing research, dashboards, or media content using Blomstra data:

1. **Cite the index version** (e.g., SERI v4.2.1, SIVI v2.0.0)
2. **Cite the methodology standard** (BMS-1.0.0)
3. **Cite the data vintage** (found in the `last_updated` and `reference_vintage` fields)
4. **Link to** `https://blomstra.com/insights` or the relevant index page

---

## Research Ethics & Transparency Commitments

| Principle | How We Deliver |
|---|---|
| **No hidden weights** | All pillar and indicator weights are published and fixed before data collection |
| **No black-box models** | Every score traces to a public API call with documented source, year, and scope |
| **No data fabrication** | Missing data is reported as missing; partial indices receive rank ranges |
| **No selective dropping** | Indicators and time windows are fixed in code; never adjusted per-country |
| **Sensitivity testing** | Every index release includes robustness validation via Spearman correlation |
| **Reproducibility** | Same code + same public data = same scores. No random seeds, no expert overrides |

---

## Frequently Asked Questions

**Q: Why percentile ranks instead of absolute thresholds?**
A: Absolute thresholds suffer from secular drift (global averages change over time) and context blindness (the same number means different things in different countries). Percentile ranks are self-calibrating and globally comparable.

**Q: Can I download the full dataset?**
A: Yes — the REST API returns all countries, all pillars, all metadata. No registration required for read access. Bulk historical snapshots are available for academic researchers upon request.

**Q: How often does the data update?**
A: SERI and SIVI refresh quarterly or when major source vintages update (e.g., new IMF WEO release). The `last_updated` field in the API response shows the exact build timestamp.

**Q: Why does my country have a rank range instead of a single rank?**
A: Your country is missing data for at least one pillar. Rather than exclude you or guess a value, we project where you would rank if the missing pillar were at different percentile levels. The **best estimate** (median injection) is the most likely single rank.

**Q: Are landlocked countries penalized in SIVI?**
A: No — they receive a **structural zero** in maritime connectivity, which is a real geographic constraint, not a data gap. This zero is treated as a valid value, so landlocked countries can still achieve Full Index status. Their composite score correctly reflects that they have no maritime exposure to protect.

**Q: What is the difference between SERI and SIVI?**
A: SERI measures **structural resilience** — the state's capacity to absorb shocks (governance, macro stability, fiscal health, external buffers). SIVI measures **system vulnerability** — exposure to disruption in essential infrastructure (energy dependency, supplier concentration, maritime connectivity). They are complementary, not competing.

**Q: How do I report an error or suggest an improvement?**
A: Contact the Blomstra Insights research team via the website. We maintain a public deviations log and welcome methodological scrutiny.

---

## Version History

| Date | Index | Change |
|---|---|---|
| 2026-08 | SERI v4.2.1 | BMS-1.0.0 conformant architecture; migrated from GERI |
| 2026-08 | SIVI v2.0.0 | BMS-1.0.0 conformant architecture; migrated from CII |
| 2026-08 | BMS-1.0.0 | Unified methodology standard introduced |
| 2026-Q4 | GPRI | Planned launch — Geopolitical Risk Index |

---

## Contact & Links

- **Website:** [blomstra.com/insights](https://blomstra.com/insights)
- **API Documentation:** Available at `/wp-json/blomstra/v1/`
- **Research Inquiries:** research@blomstra.com
- **Methodology Standard:** BMS-1.0.0

---

*Blomstra Insights. All rights reserved. Data sourced from World Bank, IMF, UN Comtrade, and EIA. Methodology published under academic transparency standards.*
