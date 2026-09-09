# Known Deviations & Issues

> **Last updated:** 2026-08-12
> **Applies to:** SERI v4.3.0, SIVI v2.1.0

---

## Active Issues

### 1. SIVI `data_freshness` Structure Missing

**Status:** Medium — API shape inconsistency (partially resolved)
**Location:** `sivi_build_composite()` country output
**Description:** SERI includes both `data_freshness` and `pillars` objects per country. As of BMS-1.1.0, SIVI now includes `pillars` (matching SERI's `{score, weight}` shape). `data_freshness` is still missing — SERI's version is bespoke to its own per-indicator field names (`rule_of_law_year`, `gni_growth_source`, etc.), and building an equivalent for SIVI requires a real design decision about what year/source metadata SIVI actually has available per pillar (energy's `blomstra_track_source()` calls currently pass a null year; HHI and maritime do carry real years). Not something to guess at.
**Fix:** Decide what SIVI's `data_freshness` shape should be per pillar, then implement against real tracked metadata.

### 2. HHI Central-Fetch Checkpoint Does Not Merge Sources

**Status:** Medium — crash recovery gap
**Location:** Reference Data, `blomstra_refresh_comtrade_hhi_data()` checkpoint closure
**Description:** The checkpoint function merges `$results` (country → value data) on each chunk but does not separately track/merge per-indicator source provenance the way `blomstra_track_source()` does elsewhere. A crash mid-run during a large HHI refresh could leave provenance incomplete for already-completed chunks, though the `source: 'Comtrade'` label is still embedded per-country in the result row itself.
**Fix:** Not yet attempted — this is in Reference Data (shared, load-bearing across both indices), and given the redeclare/direction-mismatch incidents already encountered this migration when editing shared code, this fix should be scoped and reviewed deliberately rather than made in passing.

### 3. Excluded-Detail Value Shape Differs Between Indices

**Status:** Low — known, deliberate for now
**Location:** `seri_build_composite()` vs `sivi_build_composite()`, `$excluded` array construction
**Description:** SIVI's `excluded_detail` values are structured arrays (`{reason, pillars_present, pillars_missing}`). SERI's are plain strings. An attempt during BMS-1.1.0 to standardize SERI onto SIVI's structured shape caused the admin dashboard's excluded-country table to print the literal string `"Array"` — the admin renderer for SERI's exclusion table expects a string and wasn't updated in tandem (that renderer's source wasn't available to patch alongside the schema change). Reverted to string for SERI to restore correct display.
**Fix:** Locate and update SERI's admin exclusion-table renderer to handle a structured value, then re-attempt the standardization as a single coordinated change (schema + renderer together, not sequentially).

---

## Deferred Work — Built But Not Wired In

### Research-credibility layer (BMS-1.1.0 additions)

**Status:** Functions exist in shared Utility layer; not yet called from any output or admin UI
**Location:** Shared Utility (`blomstra-index-utilities.php`)
**Description:** `blomstra_cronbach_alpha()`, `blomstra_bootstrap_ci()`, `blomstra_benchmark_correlate()`, and `blomstra_winsorize()` were added as part of BMS-1.1.0. Winsorization is wired into both indices' percentile computation (see Methodology Notes below). Cronbach's alpha, bootstrap CI, and benchmark correlation are available but not yet called anywhere — no admin "Validation" tab, no field in the composite output.
**Next step:** Wire these into a per-pillar validation report (admin-only) and/or composite output fields, per the original migration plan's Phase 4.

---

## Inherited from SERI

*(Issue 6 — Partial Rank Logic Ignores Custom Weights — resolved in BMS-1.1.0. See Resolved Issues.)*

---

## Intentional Deviations

### 4. REST Endpoint Names Do Not Match Index Names

**SERI:** Endpoint is `/geo-economic-risk-index` (legacy GERI name)
**SIVI:** Endpoint is `/sovereign-infrastructure-vulnerability-index` (current name)
**Reason:** Backward compatibility. Changing SERI's endpoint would break existing integrations. A redirect or alias is preferred over a breaking change.

### 5. SIVI Admin Page Title Uses "SIVI" Not Full Name

**Location:** Admin menu registration
**Reason:** Space constraints in WordPress admin sidebar. Full name is displayed in the page header.

### 6. `excluded_countries` vs `excluded` Key Name

**SERI:** Uses `excluded_countries` (legacy)
**SIVI:** Uses `excluded` (BMS-1.0.0 standard)
**Reason:** SERI retained legacy key name to avoid breaking existing API consumers. SIVI adopted the standard.

### 7. SERI Composite Key Still Uses `GERI_OPTION_KEY`

**Location:** `define( 'GERI_OPTION_KEY', 'blomstra_geo_economic_risk_index' )`
**Reason:** Changing the option key would lose all historical data and break existing API consumers. The constant name was kept even though the index was renamed to SERI.

### 8. Rank-Comparison Logic Is Index-Owned, Not Shared (new, BMS-1.1.0)

**Location:** `seri_build_composite()` and `sivi_build_composite()`, both compute their own ascending/descending rank comparison inline.
**Reason:** Reference Data's `blomstra_rank_in_full_index()` has a comparison direction hardcoded internally (always "higher score = better rank," matching SIVI's vulnerability convention). During BMS-1.1.0 centralization, SERI was briefly routed through this shared function with a direction *parameter* that the real function silently ignored — since the function only ever takes 2 arguments, not 3 — which inverted every partial-coverage SERI country's rank. Caught before merge, but it demonstrated that a shared function with an implicit, undocumented convention is a latent hazard once more than one index (with different conventions) depends on it. Going forward: only direction-agnostic *formatting* functions (`blomstra_build_full_rank_display()`, `blomstra_build_partial_rank_display()` — which just format an already-computed rank number) are shared. Each index owns its actual comparison logic explicitly, in its own file. A future index with its own convention gets its own three-line comparison block too — never a silent shared default.

### 9. Per-Indicator Winsorization Policy (new, BMS-1.1.0)

**Location:** `seri_get_pillar_weights()` and `sivi_get_pillar_weights()`, new `winsorize` key per indicator; consumed by `blomstra_compute_percentile_ranks_safe()` via the new `blomstra_winsorize()` function.
**Policy:** Winsorize (currently at 1%) where extreme values are more likely a data/reporting artifact than genuine signal (short time series producing noisy derived measures, small-economy base-effect spikes, thin reporting for micro-states). Do NOT winsorize where extremes are the real, meaningful story a resilience/vulnerability index exists to capture (WGI governance scores, `reserve_months`, `gov_debt`, `gov_balance`, `energy_dependency`, HHI concentration).
**Current settings:**
- SERI governance (all 3 indicators): 0% — WGI scores are bounded by construction, extremes are real.
- SERI macro (all 5 indicators, including `inflation`'s pre-existing 1%): 1% — derived volatility measures and small-economy growth/unemployment figures are artifact-prone.
- SERI external: `reserve_months` 0% (genuine crisis signal), `external_debt`/`current_account`/`gni_gdp_divergence` 1% (offshore-financial-center artifacts).
- SERI fiscal: `gov_debt`/`gov_balance` 0% (real crisis conditions), `debt_trajectory` 1% (noisy for short debt-history series).
- SIVI: `energy_dependency` 0%, `supplier_concentration` (HHI) 0% (bounded by construction), `maritime_connectivity` 1% (thin/erratic LSCI data for small or remote nations).
**Reason this is documented as a deviation rather than just "the config":** these are real methodological judgment calls, not neutral technical defaults, and the reasoning should be visible and challengeable rather than buried in code comments. `reserve_months` vs. `external_debt` both being external-vulnerability indicators but getting different treatment, or `gov_debt` and `debt_trajectory` sitting in the same pillar with different winsorization, are the calls most likely to be second-guessed by a future reviewer — worth revisiting with real winsorized-vs-unwinsorized rank-stability comparisons once there's bandwidth for it, rather than treating this as final.

---

## Resolved Issues

| Issue | Resolution | Version |
|---|---|---|
| GERI → SERI rename | Completed | SERI v4.2.1 |
| CII → SIVI rename | Completed | SIVI v2.0.0 |
| Scenario-safe builder | Implemented | BMS-1.0.0 |
| Async callbacks | Implemented | BMS-1.0.0 |
| Cron safeguards | Implemented | BMS-1.0.0 |
| Sensitivity testing | Implemented | BMS-1.0.0 |
| Source tracking | Implemented | BMS-1.0.0 |
| Data quality scores | Implemented | BMS-1.0.0 |
| SIVI partial rank normalization bug (missing division by total weight — every partial-coverage country ranked #1 regardless of data) | Fixed | BMS-1.1.0 |
| SERI partial rank ignoring custom weights (hardcoded `/4` instead of actual composite weights) | Fixed | BMS-1.1.0 |
| Spearman correlation tie-handling (both indices, `array_search`-based ranking gave tied values the same rank instead of a fractional mid-rank) | Fixed | BMS-1.1.0 |
| SIVI energy/HHI meta keys never updated (all four affected write paths — energy central, energy fallback, HHI central, HHI fallback) | Fixed | BMS-1.1.0 |
| SIVI `energy_is_structural_zero` dead code (checked wrong pillar's data with a non-matching string) | Fixed, renamed to `maritime_is_structural_zero` | BMS-1.1.0 |
| SIVI missing `pillars` object in country output | Fixed | BMS-1.1.0 |
| `_meta.standard_version` inconsistency (SIVI had it, SERI didn't) | Fixed, both now declare BMS-1.1.0 | BMS-1.1.0 |
| SERI `seri_initialize()` missing `function_exists` guard (SIVI had it, SERI didn't — would fatal if shared utilities loaded late) | Fixed | BMS-1.1.0 |
| `sivi_test_country_list()` test fixture left in production code (zero call sites) | Removed | BMS-1.1.0 |
| Duplicated `seri_spearman_correlation()` / `sivi_spearman_correlation()` local functions | Deduplicated into shared `blomstra_spearman_correlation()` | BMS-1.1.0 |
| Duplicated OECD/JRC partial-rank injection composite math (SERI and SIVI each had their own copy, with independently different bugs) | Centralized into shared `blomstra_project_partial_rank_composite()` | BMS-1.1.0 |
| Winsorization: SERI winsorized only `inflation`, SIVI winsorized nothing, both as undocumented inline exceptions | Made explicit, per-indicator, configurable — see Intentional Deviations §9 | BMS-1.1.0 |

---

## Deferred Work

### Formal regression pass

**Status:** Planned, deliberately not yet run
**Description:** Full checksum comparison of sorted full-coverage country lists (pre/post migration), verification that all partial-coverage ranks are plausible under both baseline and custom-weight scenarios, and confirmation the weekly cron cycle completes cleanly against the migrated code. Scheduled for after all BMS-1.1.0 code changes are complete and stable — this document should be checked for accuracy again once that pass runs.

### GPRI (Geopolitical Risk Index)

**Status:** Planned, not started
**Blockers:** Need to finalize data sources (UCDP, ACLED, political event databases)
**Timeline:** Q4 2026

### Geoeconomic Atlas (Paid Tier)

**Status:** Conceptual
**Blockers:** Need to build sanctions database, supply-chain chokepoint model, energy flow tracker
**Timeline:** 2027

### OpenAPI Spec

**Status:** Planned
**Blockers:** Need to stabilize API shape across all indices — the SERI/SIVI `pillars` and `data_freshness` inconsistency (Active Issue #1) is directly in scope here
**Timeline:** After GPRI launch

### Frontend Widget Engine v2

**Status:** Conceptual
**Features:** Drag-and-drop sensitivity sliders, real-time rank updates, export to CSV/PDF
**Timeline:** 2027

### Legacy GERI/CII WPCode snippets

**Status:** Inactive, not yet deleted
**Description:** Standalone legacy snippets from before the GERI→SERI and CII→SIVI renames still exist in WPCode but are deactivated. Planned for deletion once the BMS-1.1.0 migration (this document) is fully closed out, including the regression pass above.
