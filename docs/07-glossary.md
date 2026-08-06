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
| **Coverage Type** | Classification of data completeness: `full` (all pillars), `partial` (minimum met but not all), or excluded (< minimum). |
| **Definitive Rank** | An exact ordinal position (e.g., #14) assigned only to Full-Index countries. |
| **Dispatcher Mode** | One of `central` / `central_cached` / `api` / `auto` — the parameter every pillar-refresh function takes, controlling whether it calls Reference Data, reads Reference Data's cache only, calls its own fallback API directly, or tries central first with silent fallback. |
| **Excluded Panel** | The frontend's collapsible list of countries with too little data to score, shown only when the API response's `excluded_detail` is non-empty. |
| **Fallback Path** | Data flow where the index backend makes its own API calls because central cache is empty or failed. |
| **Freshness Gate** | The check the daily cron runs before building anything — skips the build entirely if any pillar's data is missing or older than the freshness threshold (10 days), rather than publishing a composite built on stale data. |
| **Full Index** | A country with real data for all pillars. Receives a definitive rank. |
| **HHI** | Herfindahl-Hirschman Index. Measures market concentration. In Blomstra, applied to import partner concentration (0–10000 scale). |
| **Injection Simulation** | Method for projecting rank ranges for Partial-Index countries by simulating the missing pillar at five percentile points (0, 10, 50, 90, 100). Only cleanly defined for a single missing pillar — see [09-methodology-deepdive.md](09-methodology-deepdive.md). |
| **LSCI** | Liner Shipping Connectivity Index. World Bank maritime metric. Higher = better connected. |
| **Partial Index** | A country missing one or more pillars but meeting the minimum threshold. Receives a projected rank range, not a definitive rank. |
| **Percentile Rank** | A 0–100 score representing where a country sits in the global distribution for a given pillar. |
| **Pillar** | A single data dimension (e.g., Energy Dependency, Supplier Concentration, Maritime Exposure). |
| **Projected Rank Range** | A rank interval (e.g., #38–#52*) reported for Partial-Index countries, with 80% and theoretical bounds. |
| **QBTU** | Quadrillion British Thermal Units. EIA energy unit. |
| **Reference Data** | The shared PHP layer that collects, caches, and serves raw data from external APIs to all index backends. |
| **Snapshot** | A monthly historical record stored in `wp_blomstra_index_history`. One row per (index, country, month), upserted — not appended — within a given month. |
| **Structural Zero** | A known-zero value that is real data, not missing data. Example: landlocked countries have LSCI = 0. |
| **Watchlist** | A user's saved list of countries, persisted per-index in `localStorage` under `biw_watchlist_{slug}`. Isolated per index — never shared or leaked between different index widgets. |
| **WPCode** | WordPress plugin used to inject PHP, JS, and CSS snippets without editing theme files. |
| **80% Plausible Range** | The rank range derived from injecting the missing pillar at the 10th and 90th percentiles. Represents "likely" rank if the missing data were known. |
| **Theoretical Bound** | The rank range derived from injecting the missing pillar at the 0th and 100th percentiles. Represents absolute best/worst case. |
