# Deviation Log Template

> **Place this file at:** `src/indices/{slug}/docs/deviations.md`
>
> Every departure from a Blomstra Research Standard (BMS) MUST be documented here.
> Undocumented deviations are treated as bugs, not features.

---

## Index Information

| Field | Value |
|-------|-------|
| Index slug | `{slug}` |
| Index name | `{Full Name}` |
| Standard version | `BMS-x.y.z` |
| Last updated | `YYYY-MM-DD` |

---

## Deviation #1: {Short Title}

| Field | Value |
|-------|-------|
| Standard ID | `BMS-xxx` |
| Standard section | `{section name}` |
| Deviation type | `override` / `extension` / `exception` |
| Status | `approved` / `proposed` / `under_review` |
| Approved by | `{name}` |
| Date approved | `YYYY-MM-DD` |

### What the standard says

> Quote the relevant rule from [`10-engineering-research-standards.md`](10-engineering-research-standards.md).

### What this index does instead

> Describe the deviation precisely.

### Why this deviation is necessary

> Provide methodological justification. "Because it works better" is not sufficient.
> Cite external sources if applicable.

### What risks this introduces

> Be honest about what could go wrong.

### How the risk is mitigated

> Describe safeguards, tests, or monitoring.

### Related code

> File and function names where this deviation is implemented.

---

## Deviation #2: {Short Title}

(Use same format as above)

---

## No Deviations

If this index conforms to all BMS standards without deviation, state that explicitly:

> **This index conforms to BMS-x.y.z without deviation.**
> Date: YYYY-MM-DD
> Verified by: {name}

---

## Example: GERI Deviation (Illustrative)

### Deviation: GNI→GDP Fallback in Macro Stability

| Field | Value |
|-------|-------|
| Standard ID | `BMS-001` |
| Standard section | "Fallback values MUST be flagged" |
| Deviation type | `extension` |
| Status | `approved` |
| Approved by | `{name}` |
| Date approved | `2026-08-01` |

**What the standard says:** Fallback values MUST be flagged with `status: fallback`.

**What GERI does:** When GNI growth is missing, GERI uses GDP growth as a fallback but marks it with `macro_base_source: 'gdp_fallback'` instead of `status: fallback` in the provenance object.

**Why:** Historical design decision. The `macro_base_source` field predates BMS-001 and carries more semantic information (distinguishing "primary GNI" from "GNI missing, used GDP" from "both missing").

**Risk:** Consumers looking only for `status: fallback` may miss that this is a fallback value.

**Mitigation:** Both `macro_base_source` AND `status: fallback` are now set. `macro_base_source` is retained for backward compatibility.

**Related code:** `geri-backend.php`, `geri_build_composite()`, lines ~300-320.
