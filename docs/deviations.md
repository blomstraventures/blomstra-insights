# Known Deviations & Issues

> **Last updated:** 2026-08-10
> **Applies to:** SERI v4.2.1, SIVI v2.0.0

---

## Active Issues

### 1. SIVI Partial Rank Normalization Bug

**Status:** Critical -- fix required before next build
**Location:** `sivi_build_composite()`, partial rank injection
**Description:** Partial rank injection does not normalize by total weight. Missing pillar injection produces composites ~100x too large, causing all partial countries to rank #1.
**Fix:** Divide hypothetical composite by `array_sum($pillar_weight_by_name)`.
**See:** Architecture migration guide, Issue #1

### 2. SIVI Energy/HHI Meta Keys Not Updated

**Status:** Critical -- breaks admin freshness display
**Location:** `sivi_persist_energy_results()`, `sivi_refresh_hhi_pillar_fallback()`
**Description:** Energy and HHI fetch functions persist data but never update `SIVI_ENERGY_META_KEY` or `SIVI_HHI_META_KEY`. Admin freshness bar shows "Never" for these pillars.
**Fix:** Add `update_option(SIVI_ENERGY_META_KEY, ...)` and `update_option(SIVI_HHI_META_KEY, ...)` at end of each persistence path.
**See:** Architecture migration guide, Issue #2

### 3. SIVI `energy_is_structural_zero` Flag Is Dead Code

**Status:** Medium -- cosmetic cleanup
**Location:** `sivi_build_composite()`, measurement flags
**Description:** Landlocked status affects maritime, not energy. This flag always returns `false`.
**Fix:** Replace with `maritime_is_structural_zero` or remove.
**See:** Architecture migration guide, Issue #3

### 4. SIVI Missing `data_freshness` and `pillars` Structure

**Status:** Medium -- API shape inconsistency
**Location:** `sivi_build_composite()` country output
**Description:** SERI includes `data_freshness` and `pillars` objects per country. SIVI omits them. Frontend widgets expecting SERI shape will fail on SIVI.
**Fix:** Add both structures to SIVI country output.
**See:** Architecture migration guide, Issue #4

### 5. HHI Checkpoint Does Not Merge Sources

**Status:** Medium -- crash recovery gap
**Location:** `sivi_refresh_hhi_pillar_fallback()`, checkpoint closure
**Description:** The checkpoint function merges data but not sources. A crash mid-run loses source provenance for already-completed chunks.
**Fix:** Pass `$sources` into closure and merge.
**See:** Architecture migration guide, Issue #5

---

## Inherited from SERI

### 6. Partial Rank Logic Ignores Custom Weights

**Status:** Low -- academic precision
**Location:** Both SERI and SIVI
**Description:** Partial rank injection assumes equal weighting for the missing pillar. Under custom weights, the "best estimate" and 80% range are slightly inaccurate.
**Impact:** Low. Partial ranks are already projections.
**Note:** SIVI is actually closer to correct than SERI after Issue #1 is fixed.

---

## Intentional Deviations

### 7. REST Endpoint Names Do Not Match Index Names

**SERI:** Endpoint is `/geo-economic-risk-index` (legacy GERI name)
**SIVI:** Endpoint is `/sovereign-infrastructure-vulnerability-index` (current name)
**Reason:** Backward compatibility. Changing SERI's endpoint would break existing integrations. A redirect or alias is preferred over a breaking change.

### 8. SIVI Admin Page Title Uses "SIVI" Not Full Name

**Location:** Admin menu registration
**Reason:** Space constraints in WordPress admin sidebar. Full name is displayed in the page header.

### 9. `excluded_countries` vs `excluded` Key Name

**SERI:** Uses `excluded_countries` (legacy)
**SIVI:** Uses `excluded` (BMS-1.0.0 standard)
**Reason:** SERI retained legacy key name to avoid breaking existing API consumers. SIVI adopted the standard.

### 10. SERI Composite Key Still Uses `GERI_OPTION_KEY`

**Location:** `define( 'GERI_OPTION_KEY', 'blomstra_geo_economic_risk_index' )`
**Reason:** Changing the option key would lose all historical data and break existing API consumers. The constant name was kept even though the index was renamed to SERI.

---

## Resolved Issues

| Issue | Resolution | Version |
|---|---|---|
| GERI -> SERI rename | Completed | SERI v4.2.1 |
| CII -> SIVI rename | Completed | SIVI v2.0.0 |
| Scenario-safe builder | Implemented | BMS-1.0.0 |
| Async callbacks | Implemented | BMS-1.0.0 |
| Cron safeguards | Implemented | BMS-1.0.0 |
| Sensitivity testing | Implemented | BMS-1.0.0 |
| Source tracking | Implemented | BMS-1.0.0 |
| Data quality scores | Implemented | BMS-1.0.0 |

---

## Deferred Work

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
**Blockers:** Need to stabilize API shape across all indices
**Timeline:** After GPRI launch

### Frontend Widget Engine v2

**Status:** Conceptual
**Features:** Drag-and-drop sensitivity sliders, real-time rank updates, export to CSV/PDF
**Timeline:** 2027
