# Glossary

> **Standard:** BMS-1.0.0

---

## A

**Async callback** -- A WordPress action hook scheduled via `wp_schedule_single_event()` that runs a pillar fetch in the background. Prevents shared-host timeouts by breaking long operations into discrete tasks.

## B

**BMS (Blomstra Methodology Standard)** -- The architectural and methodological standard that all indices must follow. Current version: BMS-1.0.0. Defines storage shapes, API contracts, admin UI patterns, and cron safeguards.

**Baseline** -- The default composite build with standard weights (e.g., 25/25/25/25 for SERI, 33.33/33.33/33.34 for SIVI). All sensitivity scenarios are compared against this baseline.

## C

**Central Data / Reference Data** -- The shared `blomstra-index-utilities.php` layer that provides batch fetchers, country lists, and math utilities. Indices call this first, then fall back to direct API calls.

**Checkpoint** -- A mid-run persistence write that saves partial pillar data to the WordPress options table. Enables crash recovery for long-running fetches (e.g., Comtrade HHI).

**Composite** -- The final index score for a country, computed as a weighted average of pillar percentile scores.

**Coverage** -- `"full"` if all required pillars are present; `"partial"` if at least `MIN_PILLARS_REQUIRED` are present but not all; excluded if below minimum.

**Cron safeguard** -- The auto-rollback mechanism that keeps the previous composite if a new automated build drops below 80% of the previous country count.

## D

**Data quality score** -- A 0-3 scale per pillar indicating the freshness and reliability of source data. 3 = good (recent, primary source); 0 = no data.

**Direct API** -- Fallback fetch functions that call public APIs directly (World Bank, IMF, EIA, Comtrade) without using the shared Reference Data cache.

## E

**Excluded country** -- A country that does not meet the minimum pillar coverage threshold and therefore receives no composite score or rank.

## F

**Forward pressure** -- A SERI-specific metric derived from IMF WEO forecast deltas. Indicates whether a country's structural risk is likely to deteriorate, improve, or remain stable.

**Full index** -- A country with data for all pillars. Receives a definitive rank.

## H

**HHI (Herfindahl-Hirschman Index)** -- A measure of market concentration. In SIVI, applied to import partner concentration (0-10,000 scale). Higher = more concentrated = more vulnerable.

## I

**Indicator** -- The lowest-level data point within a pillar (e.g., "rule of law" within governance, "energy dependency" within energy).

**Injection** -- The process of simulating a missing pillar value at different percentiles (0, 10, 50, 90, 100) to compute a rank range for partial-index countries.

## L

**Landlocked structural zero** -- A SIVI-specific rule that assigns maritime connectivity = 0.0 for landlocked countries, with source flagged as "structural zero." These countries are scored in the Full Index, not the Partial Index.

## M

**Measurement flag** -- Per-country metadata documenting data anomalies, structural zeros, fallback usage, and coverage ratio.

## P

**Partial index** -- A country with at least `MIN_PILLARS_REQUIRED` pillars but not all. Receives a projected rank range instead of a definitive rank.

**Percentile rank** -- The position of a country's raw indicator value within the global cross-section, expressed as 0-100. Higher = more vulnerable (for most indicators).

**Pillar** -- A thematic dimension of the index (e.g., governance, macro, energy, maritime). Each pillar contains one or more indicators.

## R

**Rank display** -- A structured object containing definitive or projected rank information, including best estimate, 80% plausible range, and theoretical bounds.

**Reference Data** -- See "Central Data."

## S

**Scenario** -- A custom-weighted composite build used for sensitivity testing. Stored separately from the live composite and never overwrites it.

**Sensitivity testing** -- The process of rebuilding the composite with altered pillar weights to measure how much rankings change. Reported via Spearman correlation (rho) and top mover.

**Source tracking** -- The provenance system that records which API, scope (national/regional), and year produced each indicator value.

**Spearman correlation (rho)** -- A rank correlation coefficient measuring the similarity between two sets of rankings. rho = 1 means identical order; rho = 0 means no relationship.

**Standard version** -- The BMS version declared in composite output metadata (e.g., `"BMS-1.0.0"`).

**Structural zero** -- A zero value that is methodologically correct (e.g., landlocked countries have zero maritime connectivity), not a missing data placeholder.

## T

**Top mover** -- The country whose rank changes the most (in absolute terms) between a scenario and the baseline.

## V

**Vulnerability** -- The concept measured by SIVI: exposure to disruption across essential infrastructure systems. Distinct from "resilience" (capacity to absorb) and "risk" (probability of adverse events).

**Volatility** -- A derived indicator measuring the standard deviation of a time series (e.g., 5-year GDP growth volatility). Captures instability, not level.
