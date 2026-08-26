# Blomstra Insights — Unified Documentation Model (UDM)
> **Version:** UDM-1.0.0
> **Conformance:** BMS-1.1.0
> **Scope:** Reference Data (v2.7.28), Utilities (v1.1.3), Index Backend, Frontend Engine
> **Last updated:** 2026-08-26

---

## 0. Preface: Why This Document Exists

The Blomstra Insights documentation set has grown organically across multiple authoring sessions (Kimi, DeepSeek, manual edits). While technically complete, the current corpus exhibits four structural problems:

1. **Redundancy** — Staging→atomic promotion, pointer patterns, and error-handling matrices appear in 3+ documents each with slightly different emphasis.
2. **Overlap blur** — Conceptual architecture (01), data-flow narrative (02), and API reference (04) all describe the same state machine without clear ownership.
3. **Missing contracts** — No document formally defines the interface between layers (e.g., exactly what shape must a pillar return so the composite builder can consume it?).
4. **No implementation runway** — A new index developer cannot look at one document and understand what must be built; they must synthesize 01 + 02 + 03 + 05 + 07.

This **Unified Documentation Model (UDM)** solves these problems by:
- Establishing a **4-tier documentation taxonomy** with strict Single-Source-of-Truth (SSOT) rules.
- Providing a **consolidated conceptual model** that replaces the scattered architecture descriptions.
- Defining **formal cross-layer contracts** (data shapes, state machine invariants, API signatures).
- Supplying **implementation templates** for the upcoming index-backend migration.

---

## Part I: Documentation Architecture

### 1.1 The 4-Tier Taxonomy

Every Blomstra Insights document must belong to exactly one tier. No tier may duplicate content owned by another tier; it may only **reference** it.

| Tier | Purpose | Audience | Example Documents |
|---|---|---|---|
| **T1 — Conceptual** | *What* the system is and *why* it works this way. Architecture, design principles, formal models. | Architects, reviewers, grant applications | UDM Part II (this doc), Risk-Layer Model |
| **T2 — Contract** | *Exactly* what each layer promises to the next. Data shapes, function signatures, invariants, error codes. | Backend developers, API consumers | UDM Part III, REST API Spec, Cross-Layer Contracts |
| **T3 — Implementation** | *How* to build or extend. Step-by-step algorithms, code patterns, configuration. | Implementers, maintainers | Index Backend Guide, Collection Methods, State Machine Implementations |
| **T4 — Operational** | *How* to run, monitor, and debug. Cron schedules, health checks, flush procedures, deviations. | DevOps, admins, support | Health Dashboard Guide, Changelog, Deviation Log |

**SSOT Rule:** If a concept appears in multiple tiers, the **lowest-numbered tier owns it**. Higher tiers reference it via anchor links, never duplicate.

> **Example:** The staging→atomic promotion algorithm is a T1 concept (why we do it), a T2 contract (the 80% threshold invariant), a T3 implementation (the exact PHP flow in `global-reference-data.php`), and a T4 operational concern (how to flush staging when stuck). The T3 document owns the full algorithm; T1 describes the intent, T2 lists the invariant, T4 explains recovery.

### 1.2 Document Metadata Standard

Every document in the corpus must carry this header:

```markdown
# Document Title
> **Tier:** T1 / T2 / T3 / T4
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** [module list]
> **Last updated:** YYYY-MM-DD
> **SSOT For:** [concept list]
> **Depends on:** [document list]
```

### 1.3 Current Corpus Remediation Map

The existing 9 documents are remapped as follows:

| Old File | Tier | SSOT For | Action |
|---|---|---|---|
| `01-architecture.md` | T1 | Design principles, layer diagram, risk model | **Keep** as T1 entry point; strip implementation detail |
| `02-data-flow.md` | T1/T3 hybrid | Pipeline stages, fallback paths | **Split** — T1 pipeline concept stays; T3 algorithm moves to implementation guides |
| `03-state-machines-safeguards.md` | T1/T3 hybrid | Pointer pattern, safeguards | **Split** — T1 state-machine formalism stays; T3 implementation moves to reference-data impl guide |
| `04-reference-data-api.md` | T2/T3 hybrid | Function signatures, cron handlers, REST endpoints | **Restructure** — T2 contracts extracted; T3 implementation detail moved to inline PHPDoc |
| `05-shared-utilities-api.md` | T2 | Utility function contracts, statistical layer | **Keep** as T2 reference; add cross-layer contract section |
| `06-collection-methods.md` | T3/T4 hybrid | Source-specific fetch logic, scheduling | **Split** — T3 collection algorithms + T4 scheduling/health matrix |
| `07-index-backend.md` | T1/T3 hybrid | Composite builder, pillar definitions | **Restructure** — this is the next phase; needs T1 + T2 + T3 decomposition |
| `08-frontend-engine.md` | T2/T3 | Widget config, data shapes, rendering | **Restructure** — T2 response contracts + T3 rendering logic |
| `09-changelog-deviations.md` | T4 | Deviation registry, migration history | **Keep** as T4; formalize deviation taxonomy |

---

## Part II: Unified Conceptual Model (T1)

### 2.1 The Layer Cake (Consolidated)

Blomstra Insights is a **4-layer stack** with strict downward dependencies. No layer may skip an adjacent layer, and no layer may depend upward.

```
┌─────────────────────────────────────────────────────────────┐
│  L4 — FRONTEND                                              │
│  Widget engine (clash-proof, config-driven, multi-instance) │
│  Consumes: REST API (JSON)                                  │
│  Produces: DOM + localStorage                               │
├─────────────────────────────────────────────────────────────┤
│  L3 — INDEX                                                 │
│  Composite builders (SERI, SIVI, GPRI…)                     │
│  Consumes: Pillar data (PHP arrays)                         │
│  Produces: REST API responses (JSON), Snapshot DB rows      │
├─────────────────────────────────────────────────────────────┤
│  L2 — SHARED UTILITIES                                      │
│  Math, statistics, quality scoring, provenance tracking     │
│  Consumes: Raw data arrays                                  │
│  Produces: Percentiles, composites, CIs, quality flags      │
├─────────────────────────────────────────────────────────────┤
│  L1 — REFERENCE DATA                                        │
│  API fetchers, state machines, caches, cron handlers        │
│  Consumes: External APIs (WB, IMF, Comtrade, EIA)          │
│  Produces: Normalized pillar caches (options/transients)    │
└─────────────────────────────────────────────────────────────┘
```

**Invariants:**
- **L1 never knows about indices.** It does not know SERI exists. It only knows "fetch indicator X" or "fetch HHI for year Y."
- **L2 never knows about WordPress.** It operates on pure arrays and scalars. No `get_option()`, no transients.
- **L3 never renders HTML.** It produces structured arrays and JSON. Presentation is L4's job.
- **L4 never calls L1 or L2 directly.** All data enters via L3 REST endpoints.

### 2.2 The Three-Layer Risk Model (T1)

This is a **domain model**, independent of the technical layer cake above.

| Risk Layer | Question | Index | Frequency | Dependency Chain |
|---|---|---|---|---|
| **Structural Foundations** | How capable is the state of absorbing shocks? | **SERI** | Quarterly/Annual | None (base layer) |
| **System Vulnerability** | How exposed are essential infrastructure systems? | **SIVI** | Quarterly/Annual | SERI (contextual overlay) |
| **Event Risk** | Is the neighborhood on fire? | **GPRI** | Monthly/Event-driven | SERI + SIVI (trigger thresholds) |
| **Exposure Overlay** | What weapons are pointed at you? | **Geoeconomic Atlas** | Quarterly | All above (paid tier) |

**Key T1 principle:** Total risk is the **interaction** of layers, not their sum. A country with strong structural foundations (low SERI) but critical system vulnerability (high SIVI) exhibits **asymmetric resilience** — a concept the frontend renders as "stable state, fragile systems."

### 2.3 The State Machine Formalism (T1)

Every long-running operation in L1 follows a **unified state machine**. This is the single formal definition; all implementations (HHI, EIA, WB) are specializations.

```
States:  idle | running | paused | done | failed
Transitions:
  idle ──[acquire lock]──> running
  running ──[success]──> done
  running ──[exception | quota | timeout]──> failed
  running ──[timeout | quota]──> paused  (resumable only)
  failed ──[retryable && attempts < max]──> running
  failed ──[permanent || attempts >= max]──> [*]  (terminal)
  paused ──[next cron cycle]──> running  (pointer resume)
  done ──[validate coverage]──> promoted  (atomic promotion)
  done ──[coverage < threshold]──> rolled_back
```

**Invariants:**
1. **Lock invariant:** `running` state implies a lock transient exists. No lock → state is corrupt.
2. **Pointer invariant:** `paused` state implies a pointer exists in `wp_options`. No pointer → state is `idle` or `failed`.
3. **Staging invariant:** `done` state implies staging data exists. If staging is missing, the run was rolled back or never completed.
4. **Promotion invariant:** `promoted` state implies production data was atomically replaced **only after** coverage validation passed. Old production data is discarded only on success.

### 2.4 Staging → Atomic Promotion (T1)

This is a **cross-layer safety protocol** between L1 and L3.

```
Fetcher writes ──> STAGING (option or transient)
                         │
                         ▼
              [VALIDATION: coverage >= 80%?]
                    /              \
                 YES               NO
                  │                 │
                  ▼                 ▼
        copy to PRODUCTION    discard STAGING
        delete STAGING          keep old PRODUCTION
        delete pointer          log validation failure
        update cron status      set retryable flag
```

**Why 80%?** Empirically derived: below 80%, the missing data is likely systemic (API outage, auth failure) rather than sporadic (individual country gaps). The threshold prevents a half-empty dataset from corrupting the live index.

**Why atomic?** WordPress `update_option()` is not atomic across two keys, but the sequence "copy staging → production, then delete staging" is **recoverable**. If the process dies between copy and delete, the next run detects stale staging and re-validates.

### 2.5 The Pointer Pattern (T1)

A **pointer** is a resumable checkpoint. It is the mechanism by which PHP's shared-host timeout (30–90s) is defeated.

**Formal definition:**
```
Pointer := {
  scope:        string,       // what is being fetched (e.g., "hhi_2024")
  pending:      Set<ISO3>,    // work remaining
  completed:    Set<ISO3>,    // work finished (optional, for idempotency)
  attempts:     Map<ISO3, int>, // retry counters
  started_at:   ISO8601,
  metadata:     Map<string, mixed>, // specialization-specific
}
```

**Specializations:**

| Fetcher | `scope` | `pending` | `metadata` |
|---|---|---|---|
| HHI | `hhi_{year}` | ISO3s needing HHI | `target_year: int` |
| EIA | `eia_{fuel}_{activity}` | ISO3s needing fuel data | `fuel_index: int, activity: string, failed_fuels: Map` |
| WB | `wb_indicators` | Indicator codes | `next_index: int` |

**Invariant:** A pointer must be **idempotent**. Re-processing a country that was already processed must yield the same result and not corrupt the output.

---

## Part III: Cross-Layer Contracts (T2)

### 3.1 L1 → L2 Contract: Raw Data Shape

L1 (Reference Data) promises to deliver data in this shape to L2 (Utilities):

```php
// Single-indicator dataset
array(
    'USA' => array(
        'value'  => float|int,
        'year'   => int,
        'source' => string,   // e.g., 'EIA', 'WB_WDI', 'IMF_WEO'
        'scope'  => string|null, // e.g., 'general_gov', 'national'
    ),
    ...
)

// Multi-indicator dataset (with provenance)
array(
    'data'    => array('USA' => array('value' => 12.5, 'year' => 2024, ...)),
    'sources' => array(
        'USA' => array(
            'indicator_name' => array(
                array('source' => 'EIA', 'scope' => 'national', 'year' => 2024)
            )
        )
    )
)
```

**Contract invariants:**
- `value` is always numeric (never `null`, never string-encoded number). Use `blomstra_safe_numeric()`.
- `year` is always a 4-digit integer.
- `source` is drawn from the controlled vocabulary: `EIA`, `WB_WDI`, `WB_WGI`, `IMF_WEO`, `UN_COMTRADE`, `WB_LSCI`.
- Missing countries are **omitted**, not present with `null` values.

### 3.2 L2 → L3 Contract: Pillar Data Shape

L2 (Utilities) promises to deliver pillar data to L3 (Index Backend) in this shape:

```php
array(
    'iso3' => array(
        'score'     => float,   // 0–100 percentile
        'weight'    => float,   // pillar weight in composite (e.g., 33.33)
        'percentile'=> float,   // same as score, explicit for clarity
        'raw_value' => float,   // original value before normalization
        'quality'   => array(   // from blomstra_pillar_quality_score()
            'coverage_pct'  => float,
            'avg_staleness' => float,
            'quality_counts'=> array('good' => int, 'aged' => int, 'stale' => int, 'missing' => int),
        ),
    ),
    ...
)
```

**Contract invariants:**
- `score` is always in `[0, 100]`. If winsorization is applied, it is applied before this contract.
- `quality.coverage_pct` must be computed before passing to L3. L3 does not recompute coverage.
- The array may be empty (no data). L3 handles empty pillars via partial-index logic.

### 3.3 L3 → L4 Contract: REST API Response Shape

L3 (Index Backend) promises to deliver this JSON shape to L4 (Frontend):

```json
{
  "version":      "string",       // index version, e.g., "4.2.1"
  "last_updated": "ISO8601",      // build timestamp
  "total_countries": int,
  "excluded_countries": int,
  "weights":      {"pillar": float, ...},
  "countries": {
    "ISO3": {
      "iso3": "string",
      "name": "string",
      "composite_score": float,
      "coverage": "full|partial|insufficient",
      "rank_display": {
        "is_definitive": bool,
        "best_estimate": int,
        "string_format": "string"
      },
      "data_quality": {"pillar": int, ...},
      "measurement_flags": {
        "coverage_ratio": float,
        "is_definitive": bool,
        "missing_pillars": ["string"]
      },
      "pillars": {
        "pillar_name": {
          "score": float,
          "weight": float,
          "percentile": float
        }
      },
      "projected_range": {        // only if coverage == "partial"
        "0": float,
        "10": float,
        "50": float,
        "90": float,
        "100": float
      },
      "sensitivity_interval": {   // optional, from bootstrap CI
        "point": float,
        "ci_low": float,
        "ci_high": float
      }
    }
  }
}
```

**Contract invariants:**
- `composite_score` is in `[0, 100]`.
- `rank_display.best_estimate` is `1` for most vulnerable, `N` for least vulnerable.
- `coverage == "insufficient"` → country is omitted from `countries` but counted in `excluded_countries`.
- `projected_range` is present **iff** `coverage == "partial"`.
- `sensitivity_interval` is present **iff** bootstrap CI was computed for this build.

### 3.4 BMS-1.1.0 Conformance Contract

Every index backend must satisfy these 8 conformance predicates. This is the **single source of truth** for BMS-1.1.0 requirements.

| # | Predicate | Verification Method | Owner |
|---|---|---|---|
| 1 | Per-pillar storage with `data` + `sources` arrays | `assert(array_key_exists('data', $pillar) && array_key_exists('sources', $pillar))` | L3 |
| 2 | State machine for all async operations | `assert(blomstra_state_{$key} exists && follows T1 formalism)` | L1 |
| 3 | Staging → atomic promotion with coverage ≥ 80% | `assert(staging_count >= expected_count * 0.8)` | L1 |
| 4 | Cron safeguards (lock transients, auto-rollback) | `assert(lock_transient_exists() && auto_rollback_check_passed())` | L1 + L3 |
| 5 | Statistical validation (Spearman, Cronbach α, bootstrap) | `assert(blomstra_cronbach_alpha() !== null && blomstra_bootstrap_ci() !== null)` | L2 |
| 6 | Partial-rank projection for missing pillars | `assert(coverage == 'partial' => projected_range !== null)` | L3 |
| 7 | Admin Dashboard with health status + action suggestions | `assert(wp-admin page renders status table)` | L1 + L4 |
| 8 | REST endpoints with legacy redirects | `assert(endpoint returns T2 shape && legacy 301/302 works)` | L3 |

---

## Part IV: Implementation Architecture (T3)

### 4.1 File Organization (Canonical)

```
src/
├── shared/
│   ├── global-reference-data.php      # L1 — v2.7.28
│   └── blomstra-index-utilities.php   # L2 — v1.1.3
├── indices/
│   ├── _template/                     # T3 — Index implementation template
│   │   ├── index-backend.php
│   │   ├── index-shortcode.php
│   │   └── index-admin.php
│   ├── seri/
│   │   ├── seri-backend.php           # BMS-1.1.0 conformant
│   │   └── seri-shortcode.php
│   └── sivi/
│       ├── sivi-backend.php           # BMS-1.1.0 conformant
│       └── sivi-shortcode.php
└── frontend/
    ├── index-frontend-engine.js       # L4 — generic, config-driven
    └── index-frontend-styles.css
```

### 4.2 Index Backend Template (T3)

Every new index must implement this exact structure. Copy `_template/` and rename.

#### 4.2.1 Pillar Definitions

```php
// In index-backend.php
const INDEX_PILLARS = array(
    'pillar_key' => array(
        'weight'       => 33.33,           // sum of all weights = 100
        'min_required' => 2,               // minimum pillars for partial coverage
        'indicators'   => array(
            'indicator_key' => array(
                'source'    => 'WB_WDI',   // from L1 controlled vocabulary
                'code'      => 'NY.GDP.MKTP.KD.ZG',
                'inversion' => false,      // true = high raw value -> low vulnerability
                'fallback'  => null,       // or array('source' => ..., 'code' => ...)
            ),
        ),
    ),
);
```

#### 4.2.2 Build Function Signature

```php
function {slug}_build_composite(
    bool $force = false,
    string $context = 'manual',        // 'manual' | 'cron' | 'scenario'
    ?array $custom_weights = null,     // per-indicator overrides
    ?array $custom_composite_weights = null  // per-pillar overrides
): array;
```

**Scenario safety:** If `$custom_weights !== null` or `$custom_composite_weights !== null`, the function **must** return the computed array **without** calling `update_option()`.

#### 4.2.3 Build Algorithm (Canonical)

```
1. Retrieve raw pillar data from L1 cache (via get_option/transient)
2. If empty and not scenario → return error array
3. For each indicator:
   a. blomstra_compute_percentile_ranks_safe($raw_values, $winsor_pct)
   b. Apply inversion if needed: $pct = 100 - $pct
4. Aggregate indicators within each pillar (simple average or weighted)
5. Aggregate pillars to composite:
   a. Sum(pillar_score * pillar_weight) / Sum(pillar_weight_of_present)
   b. Re-normalize weights if pillars missing
6. Assign ranks (descending score = ascending rank; ties = average rank)
7. Classify coverage:
   - 'full' = all pillars present
   - 'partial' = min_required met, not all present
   - 'insufficient' = below min_required → exclude
8. Compute data quality scores (blomstra_pillar_quality_score)
9. Compute weight-sensitivity interval (blomstra_bootstrap_ci) — optional
10. Compute projected rank ranges for partial countries (blomstra_project_partial_rank_composite)
11. If NOT scenario:
    a. Cron auto-rollback check: new_count < 0.8 * previous_count?
    b. If rollback triggered → preserve old data, set transient, return old data
    c. Else → update_option(production_key, $output, false)
    d. Save snapshot via blomstra_index_snapshot_save()
12. Return $output
```

#### 4.2.4 REST API Registration

```php
add_action('rest_api_init', function () {
    register_rest_route('blomstra/v1', '/{slug}/', array(
        'methods'  => 'GET',
        'callback' => '{slug}_rest_callback',
        'permission_callback' => '__return_true', // or is_user_logged_in
    ));
});
```

**Legacy redirect:** If renaming an index, preserve old endpoint with a 301 redirect.

### 4.3 Frontend Integration Contract (T3)

The L4 widget engine auto-detects containers:

```html
<div data-blomstra-index="{slug}"
     data-blomstra-config='{"view":"table","sort":"rank","limit":50}'>
</div>
```

**Index backend responsibility:** Ensure the REST endpoint `/wp-json/blomstra/v1/{slug}/` returns the T2 contract shape. The frontend engine is generic and requires no index-specific code.

---

## Part V: Operational Model (T4)

### 5.1 Health State Taxonomy

Every pillar and index exists in exactly one of these states:

| State | Color | Meaning | Action |
|---|---|---|---|
| `success` | 🟢 | Last run completed, data fresh | None |
| `partial` | 🟡 | Last run completed, some data missing | Monitor; retry if persistent |
| `running` | 🔵 | Currently executing | Wait |
| `stuck` | 🔴 | Lock exists but run timed out | Clear lock, retry |
| `error` | 🔴 | Permanent failure or validation failed | Investigate logs |
| `retryable` | 🟠 | Transient failure, will retry automatically | Wait for next cron |
| `stale` | 🟡 | Data exists but exceeds freshness threshold | Refresh recommended |
| `never-run` | ⚪ | No data, no pointer, no lock | Initialize |

### 5.2 Decision Tree: "Should I Refresh?"

```
Is cache empty?
  ├─ YES → Refresh
  └─ NO → Is pointer incomplete?
      ├─ YES → Refresh (resume)
      └─ NO → Is status == partial?
          ├─ YES → Refresh (retry missing)
          └─ NO → Is data stale?
              ├─ YES → Refresh
              └─ NO → Is status == error?
                  ├─ YES → Check logs, then Refresh
                  └─ NO → Data is healthy
```

### 5.3 Emergency Procedures

| Situation | Command | Effect |
|---|---|---|
| Stuck lock | Flush pillar cache | Deletes lock transient + pointer |
| Corrupt staging | Flush pillar cache | Deletes staging option |
| Need clean slate | Emergency Flush All | Deletes ALL L1 caches, pointers, logs |
| Index build failed | Rebuild from cache | Triggers `build_composite(manual)` without re-fetching L1 |
| Suspect API outage | API Diagnostic Sandbox | Tests single target without batch execution |

### 5.4 Deviation Logging Standard

Every known deviation from BMS-1.1.0 must be logged with this schema:

```markdown
### Deviation ID: DEV-{NNNN}
**Standard:** BMS-1.1.0
**Clause:** [which conformance predicate is affected]
**Description:** [what was done differently]
**Rationale:** [why]
**Risk:** [Low / Medium / High]
**Mitigation:** [how the risk is addressed]
**Date introduced:** YYYY-MM-DD
**Review date:** YYYY-MM-DD
```

---

## Part VI: Implementation Roadmap — Index Phase

### 6.1 Pre-Implementation Checklist

Before writing the SERI/SIVI backend code, ensure:

- [ ] L1 (Reference Data) migration is complete and all fetchers return T2 contract shapes.
- [ ] L2 (Utilities) migration is complete and all statistical functions are tested.
- [ ] `blomstra_index_history_maybe_install()` has run (table exists).
- [ ] Admin Data Health Dashboard renders without errors.
- [ ] API Diagnostic Sandbox can successfully test each data source.

### 6.2 Implementation Order

1. **Pillar definition files** — Write `INDEX_PILLARS` constants for SERI and SIVI.
2. **Raw data retrieval** — Implement `get_pillar_data()` functions that read L1 caches.
3. **Percentile normalization** — Wire `blomstra_compute_percentile_ranks_safe()` with correct inversion flags.
4. **Composite builder** — Implement the canonical build algorithm (§4.2.3).
5. **Partial-index logic** — Wire `blomstra_project_partial_rank_composite()`.
6. **Scenario safety** — Verify that custom weights never touch production.
7. **Cron auto-rollback** — Implement the 80% country-count check.
8. **Snapshot persistence** — Wire `blomstra_index_snapshot_save()`.
9. **REST endpoints** — Register routes and verify T2 response shape.
10. **Frontend integration** — Add shortcodes and verify widget rendering.
11. **Statistical validation** — Run Spearman, Cronbach α, and bootstrap CI on full dataset.
12. **Documentation** — Produce T3 implementation docs for each index.

### 6.3 Testing Matrix

| Test | Input | Expected Output | Owner |
|---|---|---|---|
| Full build | All pillars present | `coverage == 'full'`, ranks definitive | L3 |
| Partial build | 1 pillar missing (SIVI) | `coverage == 'partial'`, projected range present | L3 |
| Insufficient build | 2 pillars missing (SIVI) | Country excluded, counted in `excluded_countries` | L3 |
| Scenario build | Custom weights | Correct scores, NO database write | L3 |
| Auto-rollback | New count < 80% old | Old data preserved, failure transient set | L3 |
| Stale L1 cache | L1 data > 7 days old | Build uses stale data, quality flags reflect staleness | L1 + L3 |
| Empty L1 cache | No L1 data | Build returns error array or empty | L3 |
| Bootstrap CI | 1000 resamples | `ci_low` < `point` < `ci_high` for all countries | L2 |
| Cron duplicate | Second cron fires while first running | Second run skipped, "Already running" logged | L1 |

---

## Appendix A: Unified Glossary

| Term | Definition | Layer | First used |
|---|---|---|---|
| **BMS** | Blomstra Metric Standard. The conformance specification for all indices. | T1 | 2026-08 |
| **Composite score** | Weighted average of pillar percentiles, 0–100. Higher = more vulnerable. | L3 | v1.0 |
| **Coverage ratio** | Fraction of required indicators/pillars present for a country. | L3 | BMS-1.1.0 |
| **HHI** | Herfindahl-Hirschman Index. Import partner concentration, 0–10,000. | L1 | v1.0 |
| **Injection point** | Hypothetical percentile value (0, 10, 50, 90, 100) used to project rank ranges for partial-coverage countries. | L2 | BMS-1.1.0 |
| **L1 / L2 / L3 / L4** | Reference Data / Shared Utilities / Index Backend / Frontend. See §2.1. | T1 | UDM-1.0.0 |
| **Partial index** | A country with sufficient pillars to be ranked (≥ min_required) but not all pillars. Receives projected rank ranges, not definitive ranks. | L3 | BMS-1.1.0 |
| **Percentile rank** | Cross-sectional rank converted to 0–100 scale. Ties receive average rank. | L2 | v1.1.3 |
| **Pointer** | Resumable checkpoint stored in `wp_options`. Defeats PHP timeouts. | L1 | v2.7.28 |
| **Projected rank range** | The range of possible composite scores if a missing pillar were at various injection points. | L2 | BMS-1.1.0 |
| **Scenario-safe** | A build mode where custom weights are accepted but production data is never overwritten. | L3 | BMS-1.1.0 |
| **Staging** | A temporary cache (option or transient) where fetcher results are written before validation. | L1 | v2.7.28 |
| **State machine** | The formal lifecycle (idle → running → done/failed → promoted/rolled_back) of every long-running fetcher. | T1 | v2.7.28 |
| **Weight-sensitivity interval** | The range of composite scores produced by ±10% perturbations in pillar weights. Not a classical confidence interval. | L2 | BMS-1.1.0 |
| **Winsorization** | Capping extreme values at the 1st/99th percentile before percentile computation. | L2 | v1.1.3 |

---

## Appendix B: Cross-Reference Matrix

This matrix maps every major concept to its **Single Source of Truth (SSOT)** document.

| Concept | SSOT Document | Tier | Also referenced in |
|---|---|---|---|
| Design principles | `01-architecture.md` §1 | T1 | UDM §2.1 |
| Layer architecture | UDM §2.1 | T1 | `01-architecture.md` §2 |
| Risk model (SERI/SIVI/GPRI) | `01-architecture.md` §3 | T1 | UDM §2.2 |
| State machine formalism | UDM §2.3 | T1 | `03-state-machines-safeguards.md` §1 |
| Staging→promotion concept | UDM §2.4 | T1 | `02-data-flow.md` §2, `03-state-machines-safeguards.md` §3 |
| Pointer pattern concept | UDM §2.5 | T1 | `03-state-machines-safeguards.md` §2 |
| BMS-1.1.0 conformance | UDM §3.4 | T2 | `01-architecture.md` §5, `09-changelog-deviations.md` §1 |
| L1→L2 data shape | UDM §3.1 | T2 | `05-shared-utilities-api.md` §4 |
| L2→L3 pillar shape | UDM §3.2 | T2 | `07-index-backend.md` §5 |
| L3→L4 REST shape | UDM §3.3 | T2 | `07-index-backend.md` §5, `08-frontend-engine.md` §5 |
| Composite builder algorithm | UDM §4.2.3 | T3 | `07-index-backend.md` §4 |
| HHI implementation | `04-reference-data-api.md` §3 | T3 | `06-collection-methods.md` §3 |
| EIA implementation | `04-reference-data-api.md` §4 | T3 | `06-collection-methods.md` §4 |
| WB indicator implementation | `04-reference-data-api.md` §5 | T3 | `06-collection-methods.md` §1 |
| IMF WEO implementation | `04-reference-data-api.md` §6 | T3 | `06-collection-methods.md` §2 |
| Statistical functions | `05-shared-utilities-api.md` §8 | T2/T3 | `07-index-backend.md` §4.5 |
| Cron schedules | `06-collection-methods.md` §5 | T4 | `04-reference-data-api.md` §7 |
| Health dashboard | `03-state-machines-safeguards.md` §5 | T4 | UDM §5.1 |
| Deviation registry | `09-changelog-deviations.md` | T4 | UDM §5.4 |

---

## Appendix C: Document Templates

### C.1 T1 — Conceptual Document Template

```markdown
# [Concept Name]
> **Tier:** T1
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** [modules]
> **Last updated:** YYYY-MM-DD
> **SSOT For:** [concept list]
> **Depends on:** [T1 documents only]

## 1. Purpose
[Why this concept exists. 1 paragraph.]

## 2. Formal Model
[Diagrams, state machines, invariants. No implementation detail.]

## 3. Design Rationale
[Why we chose this approach over alternatives.]

## 4. Relationships
[Links to other T1 concepts.]
```

### C.2 T2 — Contract Document Template

```markdown
# [Interface Name] — Contract Specification
> **Tier:** T2
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** [modules]
> **Last updated:** YYYY-MM-DD
> **SSOT For:** [data shape / API / invariant list]
> **Depends on:** [T1 concepts, other T2 contracts]

## 1. Interface Boundary
[Which layers/modules this contract separates.]

## 2. Data Shape
[Exact schema, types, constraints.]

## 3. Invariants
[What must always be true.]

## 4. Error Codes
[What can go wrong and how it's signaled.]

## 5. Version History
[Changes to this contract over time.]
```

### C.3 T3 — Implementation Document Template

```markdown
# [Module] — Implementation Guide
> **Tier:** T3
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** [modules]
> **Last updated:** YYYY-MM-DD
> **SSOT For:** [algorithm / code pattern list]
> **Depends on:** [T1 concepts, T2 contracts]

## 1. Entry Points
[Public functions, shortcodes, hooks.]

## 2. Algorithm
[Step-by-step. Pseudocode preferred over prose.]

## 3. Configuration
[Constants, filters, options.]

## 4. Testing
[How to verify correctness.]

## 5. Known Issues
[Link to T4 deviation registry.]
```

### C.4 T4 — Operational Document Template

```markdown
# [System] — Runbook
> **Tier:** T4
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** [modules]
> **Last updated:** YYYY-MM-DD
> **SSOT For:** [procedure / schedule / health metric list]
> **Depends on:** [T1 concepts, T3 implementations]

## 1. Health Monitoring
[What to watch, thresholds, dashboards.]

## 2. Procedures
[Step-by-step operational tasks.]

## 3. Troubleshooting
[Decision trees, common failures, recovery.]

## 4. Scheduling
[Cron table, manual refresh procedures.]

## 5. Deviation Registry
[Link to formal deviation log.]
```

---

*End of Unified Documentation Model (UDM-1.0.0)*
