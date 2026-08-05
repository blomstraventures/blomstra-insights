# Glossary

| Term | Definition |
|---|---|
| **Band** | A score category (Low, Medium, High, Extreme) defined by thresholds. Used for color-coding and filtering. |
| **Central Cache** | Reference Data's stored options (`blomstra_eia_raw_data`, `blomstra_comtrade_hhi_data`, etc.). The primary data source for index backends. |
| **Central Path** | Data flow where the index backend calls Reference Data functions. No direct API calls. |
| **Chunk** | A batch of countries processed in one API request. EIA uses 25, Comtrade uses 50. |
| **Composite** | The final weighted score combining all pillars into a single 0–100 number. |
| **Coverage Type** | Classification of data completeness: `full` (all pillars), `partial` (minimum met but not all), or excluded (< minimum). |
| **Definitive Rank** | An exact ordinal position (e.g., #14) assigned only to Full-Index countries. |
| **Fallback Path** | Data flow where the index backend makes its own API calls because central cache is empty or failed. |
| **Full Index** | A country with real data for all pillars. Receives a definitive rank. |
| **HHI** | Herfindahl-Hirschman Index. Measures market concentration. In Blomstra, applied to import partner concentration (0–10000 scale). |
| **Injection Simulation** | Method for projecting rank ranges for Partial-Index countries by simulating the missing pillar at five percentile points (0, 10, 50, 90, 100). |
| **LSCI** | Liner Shipping Connectivity Index. World Bank maritime metric. Higher = better connected. |
| **Partial Index** | A country missing one or more pillars but meeting the minimum threshold. Receives a projected rank range, not a definitive rank. |
| **Percentile Rank** | A 0–100 score representing where a country sits in the global distribution for a given pillar. |
| **Pillar** | A single data dimension (e.g., Energy Dependency, Supplier Concentration, Maritime Exposure). |
| **Projected Rank Range** | A rank interval (e.g., #38–#52*) reported for Partial-Index countries, with 80% and theoretical bounds. |
| **QBTU** | Quadrillion British Thermal Units. EIA energy unit. |
| **Reference Data** | The shared PHP layer that collects, caches, and serves raw data from external APIs to all index backends. |
| **Snapshot** | A monthly historical record stored in `wp_blomstra_index_history`. One row per (index, country, month). |
| **Structural Zero** | A known-zero value that is real data, not missing data. Example: landlocked countries have LSCI = 0. |
| **WPCode** | WordPress plugin used to inject PHP, JS, and CSS snippets without editing theme files. |
| **80% Plausible Range** | The rank range derived from injecting the missing pillar at the 10th and 90th percentiles. Represents "likely" rank if the missing data were known. |
| **Theoretical Bound** | The rank range derived from injecting the missing pillar at the 0th and 100th percentiles. Represents absolute best/worst case. |
