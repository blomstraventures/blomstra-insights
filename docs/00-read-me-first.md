# Blomstra Insights — Read Me First

> **You are working inside the Blomstra Insights measurement system.**
> This is a multi-index research platform, not a collection of independent widgets.
> Every index shares architecture, methodology standards, and operational patterns.
> **Do not invent a new solution for a problem already solved elsewhere in this repository.**

---

## Mandatory Reading Order

Before changing or adding **anything**, read these in order:

1. **This file** — orientation and rules
2. [`01-architecture.md`](01-architecture.md) — how the system is organized
3. [`11-engineering-research-standards.md`](11-engineering-research-standards.md) — *Layer B: Research Standards* — the authoritative rules every index inherits
4. [`05-index-template.md`](05-index-template.md) — *the checklist* for building a new index
5. [`03-api-contract.md`](03-api-contract.md) — the JSON schema every endpoint MUST conform to
6. [`08-reference-data-functions.md`](08-reference-data-functions.md) — what already exists in Reference Data (ingestion layer)
7. [`09-index-utilities.md`](09-index-utilities.md) — what already exists in the Integrity layer (processing & validation)

Only then:
- [`02-data-flow.md`](02-data-flow.md) — pipeline details
- [`04-frontend-engine.md`](04-frontend-engine.md) — frontend behavior
- [`06-deployment.md`](06-deployment.md) — operations
- [`07-glossary.md`](07-glossary.md) — vocabulary
- [`10-methodology-deepdive.md`](10-methodology-deepdive.md) — *Layer C: CII case study* (read for inspiration, not as gospel)

---

## Three-Layer Architecture

| Layer | What it is | Example docs |
|-------|-----------|--------------|
| **A — Platform** | Software architecture, data flow, deployment, shared utilities | `01`, `02`, `04`, `06`, `08`, `09` |
| **B — Research Standards** | *How Blomstra measures things* — institutional rules | `11` |
| **C — Index Methodology** | *Why this specific index measures what it measures* | `10` (CII case study), each index's own `deviations.md` |

**Critical rule:** If a problem is solved in Layer B, you MUST reuse that solution unless your index's methodology document explicitly overrides it with a documented justification.

**New rule:** If a problem is solved in the shared Integrity layer (`09-index-utilities.md`), you MUST reuse that function unless your index explicitly documents why a custom approach is required.

---

## The AI / Developer Protocol

All instructions in this repository use [RFC 2119](https://datatracker.ietf.org/doc/html/rfc2119) keywords:

- **MUST** / **MUST NOT** — Hard requirement. Violations are bugs.
- **SHOULD** / **SHOULD NOT** — Strong recommendation. Deviations need justification.
- **MAY** — Optional. Use if helpful.

### Before writing any backend code

Complete **Gate 0** in [`05-index-template.md`](05-index-template.md). It is a compliance gate, not a suggestion.

### Before adding a new external API

Follow the **New Source Onboarding Protocol** in [`08-reference-data-functions.md`](08-reference-data-functions.md).

### Before processing raw data into an index

Check [`09-index-utilities.md`](09-index-utilities.md) for safe extraction, sanitization, and provenance functions.

### If you need to deviate from a standard

Document it in `src/indices/{slug}/docs/deviations.md` using the template in [`deviations.md`](deviations.md).

---

## Reference Implementation Map

| Problem | Reference Implementation | Where to look |
|---------|------------------------|---------------|
| Partial pillar coverage / rank uncertainty | CII | `src/indices/cii/cii-backend.php` — `cii_build_composite()` |
| Structural zero handling | CII Maritime | `blomstra_get_maritime_value()` in Reference Data |
| Forecast / structural separation | GERI | `src/indices/geri/geri-backend.php` — Forward Pressure layer |
| Data provenance (per-indicator) | GERI + Utilities | `blomstra_track_source()` in `index-utilities.php` |
| Historical trajectory / volatility | GERI + Utilities | `blomstra_compute_cagr()` + `blomstra_sanitize_timeseries()` |
| HHI / concentration | CII | `blomstra_refresh_comtrade_hhi_data()` in Reference Data |
| Multi-source integration | Governance Capture | (see project docs) |
| Data refresh safeguards | GERI | Async safeguard, cron 0.8× guard, atomic save |
| Version / vintage | GERI | `reference_vintage`, `weo_vintage` handling |
| Cron safety (build lock, freshness gate) | CII | `01-architecture.md` Build Reliability section |
| Snapshot history | Reference Data | `blomstra_index_snapshot_save()` |
| Frontend engine | Shared | `04-frontend-engine.md` |
| Safe numeric extraction | Utilities | `blomstra_safe_numeric()` in `index-utilities.php` |
| Fallback merging with provenance | Utilities | `blomstra_merge_with_fallback()` in `index-utilities.php` |
| Definition drift detection | Utilities | `blomstra_validate_pillar_thresholds()` in `index-utilities.php` |
| Winsorized percentiles | Utilities | `blomstra_compute_percentile_ranks_safe()` in `index-utilities.php` |

---

## Common Traps

| Trap | Why it happens | Prevention |
|------|---------------|------------|
| "I'll just build the composite first and fix the pillars later" | Time pressure | [`05-index-template.md`](05-index-template.md) Phase 7: *"Composite/scoring work comes last. Placeholder composite logic always becomes production logic."* |
| "This index is different, so I need different missing-data handling" | Not checking Layer B | Gate 0 in [`05-index-template.md`](05-index-template.md) forces reference implementation audit |
| "I'll add the API call logging later" | It works now | [`08-reference-data-functions.md`](08-reference-data-functions.md) New Source Protocol: logging is Step 5, not an afterthought |
| "The 3-partial CII logic doesn't apply because I have 4 pillars" | Misreading `10` as standard | Read [`11-engineering-research-standards.md`](11-engineering-research-standards.md) BMS-002 for the generalized N-pillar algorithm |
| "Zero debt means missing debt" | No shared taxonomy | [`11-engineering-research-standards.md`](11-engineering-research-standards.md) BMS-002 Data-State Taxonomy: **Structural Zero ≠ Missing** |
| "I'll use `empty()` to check if this value exists" | PHP habit | [`09-index-utilities.md`](09-index-utilities.md) Rule 1: `empty(0.0)` is `true`. Use `blomstra_safe_numeric()`. |
| "The API always returns years in ascending order" | Assumption | [`09-index-utilities.md`](09-index-utilities.md) Rule 2: Use `blomstra_sanitize_timeseries()` before computing trajectories. |
| "I'll merge IMF fallback data manually" | Not knowing utilities exist | [`09-index-utilities.md`](09-index-utilities.md) Rule 3: Use `blomstra_merge_with_fallback()` to preserve provenance. |

---

## Where to find what

| I want to... | Go to... |
|-------------|----------|
| Build a new index | [`05-index-template.md`](05-index-template.md) → Gate 0 → Phase 1 |
| Add a new API source | [`08-reference-data-functions.md`](08-reference-data-functions.md) → "Adding a New Source" |
| Understand partial coverage math | [`11-engineering-research-standards.md`](11-engineering-research-standards.md) BMS-002 |
| See how CII actually does it | [`10-methodology-deepdive.md`](10-methodology-deepdive.md) |
| Fix a broken cron | [`06-deployment.md`](06-deployment.md) → "Fixing Broken wp-cron" |
| Add a frontend widget | [`04-frontend-engine.md`](04-frontend-engine.md) |
| Know the exact API shape | [`03-api-contract.md`](03-api-contract.md) |
| Understand why we use percentiles | [`10-methodology-deepdive.md`](10-methodology-deepdive.md) Step 1 |
| Deploy to production | [`06-deployment.md`](06-deployment.md) |
| Back up historical data | [`06-deployment.md`](06-deployment.md) → "Backup & Recovery" |
| Safely extract numeric values | [`09-index-utilities.md`](09-index-utilities.md) → "Safe Data Extraction" |
| Compute trajectories correctly | [`09-index-utilities.md`](09-index-utilities.md) → "Timeseries Sanitization" |
| Track data sources per-indicator | [`09-index-utilities.md`](09-index-utilities.md) → "Per-Indicator Source Tracking" |
| Validate my pillar definitions | [`09-index-utilities.md`](09-index-utilities.md) → "Definition Validation" |

---

## Repository Navigation

```
docs/
 00-read-me-first.md          ← You are here
 01-architecture.md           ← System design
 02-data-flow.md              ← Pipeline stages
 03-api-contract.md           ← JSON schema (MUST conform)
 04-frontend-engine.md        ← Shared widget
 05-index-template.md         ← New-index checklist
 06-deployment.md             ← Operations
 07-glossary.md               ← Vocabulary
 08-reference-data-functions.md ← Layer 1: Reference Data (ingestion)
 09-index-utilities.md        ← Layer 1b: Data Integrity (processing)
 10-methodology-deepdive.md   ← CII case study (Layer C)
 11-engineering-research-standards.md ← Blomstra-wide rules (Layer B)
 deviations.md                ← Deviation log template

src/
 shared/
  index-utilities.php         ← Data integrity & processing layer
 reference-data/
  global-reference-data.php   ← Data ingestion layer
 indices/
  cii/
   cii-backend.php            ← Reference: partial coverage, structural zeros
   cii-shortcode.php
   docs/
    deviations.md
  geri/
   geri-backend.php           ← Reference: provenance, forecast separation, trajectory
   docs/
    deviations.md
```

---

## One-Sentence Reminders

- **Collection is centralized.** Each index is a thin dispatcher with its own fallback.
- **Processing is centralized too.** Use `index-utilities.php` for extraction, sanitization, and validation.
- **Never fabricate missing data.** Simulate, don't impute.
- **Zero ≠ missing.** Structural zeros are real observations.
- **Forecast ≠ structural.** They live in separate layers.
- **Rank is a property of the data, not the view.** Frontend never recalculates.
- **Document deviations.** If you break a standard, write down why.
- **`empty()` is not a null check for numbers.** Use `blomstra_safe_numeric()`.
