# Glossary

| Term | Definition |
|---|---|
| **Band** | A score category (Low, Medium, High, Extreme) defined by thresholds. Used for color-coding and filtering. |
| **BIW** | Short for "Blomstra Index Widget" — the CSS/JS class prefix (`.biw`, `data-biw-*`) used throughout the shared frontend engine. |
| **Build Lock** | A short-TTL transient (5 minutes) preventing two composite builds from running concurrently. Self-healing — a lock older than its TTL is treated as stale and cleared automatically, no manual intervention needed. |
| **Central Cache** | Reference Data's stored options (`blomstra_eia_raw_data`, `blomstra_comtrade_hhi_data`, etc.). The primary data source for index backends. |
| **Central Path** | Data flow where the index backend calls Reference Data functions. No direct API calls. |
| **Chunk** | A batch of countries processed in one API request. EIA uses 25, Comtrade uses 50. |
| **CII / CIV** | CII is the current name in code (function names, REST slug, shortcode) for the platform's first index. CIV (Critical Infrastructure Vulnerability Index) is the intended eventual name — the rename is deferred, not yet done. See the [README](../README.md). |
| **Composite** | The final weighted score combining all pillars into a single 0–100 number. |
| **Collection Failure** | A technical or API failure that prevented data retrieval. Distinct from "missing" because a retry may succeed. |
| **Confirmed Zero** | A value of exactly zero that is verified by the source as a real observation, not a null placeholder. EIA uses this distinction. |
| **Coverage Ratio** | `pillars_used / total_pillars`. A 3/4 index has coverage ratio 0.75. |
| **Coverage Type** | Classification of data completeness: `full` (all pillars), `partial` (minimum met but not all), or excluded (< minimum). |
| **Data Freshness** | Per-indicator metadata: observation year, source, retrieval date, and status. See BMS-001. |
| **Data Provenance** | The complete chain of custody for a data point: where it came from, when it was retrieved, what version, and whether it was transformed. |
| **Definitive Rank** | An exact ordinal position (e.g., #14) assigned only to Full-Index countries. |
| **Deviation** | A documented, justified departure from a Blomstra Research Standard. Every deviation MUST be recorded in `src/indices/{slug}/docs/deviations.md`. |
| **Dispatcher Mode** | One of `central` / `central_cached` / `api` / `auto` — the parameter every pillar-refresh function takes, controlling whether it calls Reference Data, reads Reference Data's cache only, calls its own fallback API directly, or tries central first with silent fallback. |
| **Estimated** | A value that the source itself identifies as an estimate rather than an observed fact. |
| **Excluded Panel** | The frontend's collapsible list of countries with too little data to score, shown only when the API response's `excluded_detail` is non-empty. |
| **Fallback Path** | Data flow where the index backend makes its own API calls because central cache is empty or failed. |
| **Forecast** | A forward-looking projection (e.g., IMF WEO T+1). MUST be quarantined from structural layers. See BMS-006. |
| **Freshness Gate** | The check the daily cron runs before building anything — skips the build entirely if any pillar's data is missing or older than the freshness threshold (10 days), rather than publishing a composite built on stale data. |
| **Full Index** | A country with real data for all pillars. Receives a definitive rank. |
| **HHI** | Herfindahl-Hirschman Index. Measures market concentration. In Blomstra, applied to import partner concentration (0–10000 scale). |
| **Indicator** | A single measurable variable (e.g., GDP growth, inflation). One or more indicators form a pillar. |
| **Injection Simulation** | Method for projecting rank ranges for Partial-Index countries by simulating the missing pillar at five percentile points (0, 10, 50, 90, 100). See BMS-002. |
| **Is Definitive** | Boolean flag: `true` if the country's rank is based on complete data, `false` if projected from partial data. |
| **LSCI** | Liner Shipping Connectivity Index. World Bank maritime metric. Higher = better connected. |
| **Methodology Architecture** | Layer 4 of the Blomstra system: the institutional standards that govern how all indices measure things. See [`10-engineering-research-standards.md`](10-engineering-research-standards.md). |
| **Missing** | No usable observation exists for this indicator/country. Do not fabricate. |
| **Not Applicable** | The indicator genuinely does not apply to this country (e.g., maritime connectivity for a landlocked country is NOT "not applicable" — it's a structural zero). |
| **Normalized Indicator** | An indicator converted to a comparable scale (e.g., 0–100 percentile rank) before entering composite calculation. |
| **Observed** | A real published value from an authoritative source. |
| **Partial Index** | A country missing one or more pillars but meeting the minimum threshold. Receives a projected rank range, not a definitive rank. |
| **Percentile Rank** | A 0–100 score representing where a country sits in the global distribution for a given pillar. See BMS-003. |
| **Pillar** | A single data dimension composed of one or more indicators (e.g., Energy Dependency, Supplier Concentration, Maritime Exposure, Macro Stability). |
| **Projected Rank Range** | A rank interval (e.g., #38–#52*) reported for Partial-Index countries, with 80% and theoretical bounds. |
| **QBTU** | Quadrillion British Thermal Units. EIA energy unit. |
| **Raw Indicator** | The pre-normalized value in its original unit (e.g., GDP growth in percent, HHI in 0–10000). |
| **Reference Data** | The shared PHP layer that collects, caches, and serves raw data from external APIs to all index backends. |
| **Reference Implementation** | An existing index or function that solves a problem in a standard way. Future indices SHOULD reuse it unless they document a deviation. |
| **Renormalization** | Adjusting weights so that available pillars sum to 1.0 within a subset. See BMS-004. |
| **Snapshot** | A monthly historical record stored in `wp_blomstra_index_history`. One row per (index, country, month), upserted — not appended — within a given month. |
| **Source Hierarchy** | The precedence rules when multiple sources exist for the same conceptual indicator. Primary → Acceptable fallback → Prohibited fallback → Separate layer. See [`02-data-flow.md`](02-data-flow.md). |
| **Standard Version** | The version of Layer B (Research Standards) that an index build conforms to. Exposed in `_meta.standard_version`. |
| **Stale** | A value that exists but exceeds the methodology's acceptable age threshold. Do not use in scoring. |
| **Structural Zero** | A known-zero value that is real data, not missing data. Example: landlocked countries have LSCI = 0. See BMS-002. |
| **Theoretical Bound** | The rank range derived from injecting the missing pillar at the 0th and 100th percentiles. Represents absolute best/worst case. |
| **Unavailable** | Expected data cannot currently be obtained. Treat as missing. |
| **Vintage** | The specific publication or revision of a dataset (e.g., "IMF WEO April 2026"). See BMS-005. |
| **Volatility** | The standard deviation of an indicator over a historical window (typically 5 years). See BMS-005. |
| **Watchlist** | A user's saved list of countries, persisted per-index in `localStorage` under `biw_watchlist_{slug}`. Isolated per index — never shared or leaked between different index widgets. |
| **WPCode** | WordPress plugin used to inject PHP, JS, and CSS snippets without editing theme files. |
| **80% Plausible Range** | The rank range derived from injecting the missing pillar at the 10th and 90th percentiles. Represents "likely" rank if the missing data were known. |
