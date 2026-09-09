# Read Me First

> **Version:** BMS-1.0.0 (Blomstra Methodology Standard)
> **Last updated:** 2026-08-10
> **Status:** SERI and SIVI are live and BMS-1.0.0 conformant. GPRI is in planning.

---

## Naming: What We Call Things

This codebase has undergone two major renames. Documentation always reflects **current truth**.

| Current Name | Former Name | What it is |
|---|---|---|
| **SERI** | GERI | Sovereign Economic Resilience Index -- 4 pillars (governance, macro, external, fiscal) |
| **SIVI** | CII / CIVI | Sovereign Infrastructure Vulnerability Index -- 3 pillars (energy, HHI, maritime) |
| **GPRI** | -- | Geopolitical Risk Index -- planned, not yet built |
| **BMS** | -- | Blomstra Methodology Standard -- the architectural standard all indices must follow |
| **Reference Data** | -- | The shared `blomstra-index-utilities.php` snippet that provides country lists, batch fetchers, math utilities |

**Do not use old names in new code.** Legacy REST endpoints redirect for backward compatibility, but all internal functions, options, and slugs use the current names.

---

## How to Read These Docs

- **Building a new index?** Start with [05-index-template.md](05-index-template.md), then reference [01-architecture.md](01-architecture.md) and [11-engineering-research-standards.md](11-engineering-research-standards.md).
- **Debugging a live index?** Start with [02-data-flow.md](02-data-flow.md) and [06-deployment.md](06-deployment.md).
- **Integrating the frontend?** Read [04-frontend-engine.md](04-frontend-engine.md) and [03-api-contract.md](03-api-contract.md).
- **Writing a research paper?** Read [10-methodology-deepdive.md](10-methodology-deepdive.md) and [11-engineering-research-standards.md](11-engineering-research-standards.md).

---

## Version Policy

- **Index version** (e.g., `SERI_VERSION`, `SIVI_VERSION`): Semver. Major bumps for methodology changes. Minor for new indicators. Patch for bug fixes.
- **BMS version** (e.g., `BMS-1.0.0`): Bumped when the architectural standard itself changes -- e.g., a new required field in the API output, a new admin UI pattern, or a new shared utility.
- **Standard version** is declared in every index's composite output: `'standard_version' => 'BMS-1.0.0'`.

---

## What BMS-1.0.0 Requires

Every index in this repo must implement:

1. **Per-pillar storage** as `{index}_{pillar}_data` containing `['data' => [...], 'sources' => [...]]`
2. **Per-pillar meta** as `{index}_{pillar}_meta` containing `['last_fetched' => '...']`
3. **Composite storage** as `{index}_composite_index` with scenario-safe builder (`context !== 'scenario'`)
4. **Async callbacks** per pillar (`{index}_async_fetch_{pillar}`)
5. **Cron safeguards** -- auto-rollback if new build drops below 80% of previous country count
6. **Sensitivity testing** -- preset weights, custom JSON, Spearman correlation vs baseline
7. **Data quality scores** -- per pillar, per country, using `blomstra_pillar_quality_score()`
8. **Measurement flags** -- per country, documenting structural zeros, coverage ratio, missing pillars
9. **Admin dashboard** -- cards, freshness bar, pillar table, composite build section, sensitivity section
10. **REST endpoint** -- canonical + legacy redirect
11. **Init validation** -- `blomstra_validate_pillar_thresholds()` on `init`

If an index does not have all 11, it is **not BMS-1.0.0 conformant**.

---

## Support & Contribution

This is a proprietary codebase. External contributions are not accepted. For internal developers, open an issue with the `BMS-deviation` label if you need to break a standard rule.
