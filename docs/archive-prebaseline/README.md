# Blomstra Insights — Documentation

> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Last updated:** 2026-08-26

## Document Structure

This documentation follows the **Unified Documentation Model (UDM)** with a strict 4-tier taxonomy:

| Tier | Purpose | Documents |
|---|---|---|
| **T1 — Conceptual** | *What* the system is and *why* | `01-architecture.md` |
| **T2 — Contract** | *Exactly* what each layer promises | `02-contracts.md` |
| **T3 — Implementation** | *How* to build and extend | `03-reference-data-impl.md`, `04-utilities-impl.md`, `05-index-backend-impl.md`, `06-frontend-impl.md` |
| **T4 — Operational** | *How* to run, monitor, debug | `07-operations.md` |

## Quick Navigation

| If you want to... | Read... |
|---|---|
| Understand the system architecture | `01-architecture.md` |
| See formal data shapes and API contracts | `02-contracts.md` |
| Implement or fix a data fetcher (L1) | `03-reference-data-impl.md` |
| Implement or fix a statistical function (L2) | `04-utilities-impl.md` |
| Build a new index or fix composite logic (L3) | `05-index-backend-impl.md` |
| Work on the frontend widget (L4) | `06-frontend-impl.md` |
| Debug a production issue or refresh data | `07-operations.md` |

## Single Source of Truth (SSOT) Rules

- **T1 owns concepts.** T2–T4 reference T1, never duplicate.
- **T2 owns contracts.** T3 implements them; T4 monitors them.
- **T3 owns algorithms.** T1 describes intent; T2 lists invariants.
- **T4 owns procedures.** T1–T3 are never the place for "how to flush cache."

## Relationship to Previous Documentation

This set replaces the previous 9-document corpus:

| Old File | New Location |
|---|---|
| `01-architecture.md` | `01-architecture.md` (enhanced with Mermaid diagrams, stripped of implementation detail) |
| `02-data-flow.md` | Split across `01-architecture.md` (pipeline concept) and `03-reference-data-impl.md` (algorithm) |
| `03-state-machines-safeguards.md` | Split across `01-architecture.md` (formalism) and `03-reference-data-impl.md` (implementation) |
| `04-reference-data-api.md` | Restructured into `02-contracts.md` (T2 shapes) and `03-reference-data-impl.md` (T3 algorithms) |
| `05-shared-utilities-api.md` | `04-utilities-impl.md` (enhanced with cross-layer contract references) |
| `06-collection-methods.md` | `07-operations.md` §4 (source matrix, scheduling, quality matrix) |
| `07-index-backend.md` | `05-index-backend-impl.md` (enhanced with canonical template, testing matrix) |
| `08-frontend-engine.md` | `06-frontend-impl.md` (enhanced with sequence diagram, CSS contract) |
| `09-changelog-deviations.md` | `07-operations.md` §5–6 (deviation registry, changelog) |

## Version History

| Date | Version | Change |
|---|---|---|
| 2026-08-26 | UDM-1.0.0 | Initial unified documentation model established |
