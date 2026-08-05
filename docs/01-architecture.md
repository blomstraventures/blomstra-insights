# Architecture

## The core idea, in one sentence

**Collection is centralized; each index is a thin dispatcher over that central data, with its own complete fallback if the central data isn't there.**

![System Architecture](../assets/diagram-01-system-architecture.png)

---

## Why this shape exists

Early on, every index tool collected its own data directly from external APIs — its own EIA calls, its own Comtrade calls, its own World Bank calls, its own caching, its own pagination. That works for one index. It breaks down with 7–10:

- **Shared rate limits and quotas.** Comtrade and EIA both have real quota ceilings. If multiple indices all hit Comtrade independently for the same underlying trade data, they burn through quota N times over.
- **Duplicated bugs.** A fix discovered in one tool's collection code wouldn't automatically propagate to another tool's separate copy.
- **Duplicated maintenance surface.** Seven copies of "fetch from EIA, paginate, checkpoint, handle rate limits" is seven places a future bug can hide.

So collection was migrated, pillar by pillar, into one shared layer.

---

## The three layers

### Layer 1: Reference Data

**File:** `blomstra-reference-data.php` (WPCode PHP snippet, "Run Everywhere")

Owns:
- Global country list and reference lookups (landlocked list, Comtrade reporter code map)
- Centralized raw collection for each pillar type (Maritime, HHI/Comtrade, EIA/Energy) — pagination, chunking, per-chunk checkpointing, rate-limit handling
- Multi-year snapshot history table (`wp_blomstra_index_history`), shared by every index
- Admin UI under **Blomstra Insights Tools** menu
- Shared REST routes: `/wp-json/blomstra/v1/country-names` and `/wp-json/blomstra/v1/index-history/{slug}`

**Cron:** Weekly — Mon(Maritime) → Tue(EIA) → Wed(HHI) @ 02:00 UTC

### Layer 2: Index Backends

**File:** One per index, e.g. `Backend - CIV Critical Infra Vulnerability Index.php`

Each index:
- Calls Reference Data first
- Falls back to its own complete collection logic only if central data is missing
- Owns only what's genuinely index-specific: scoring methodology, REST endpoint, admin page
- Calls `blomstra_index_snapshot_save()` after every successful build

**Cron:** Daily — reads `central_cached` (zero API calls), builds composite, snapshots

### Layer 3: Frontend

**Files:** `blomstra-index-frontend-engine.js` + `blomstra-index-frontend-styles.css` (site-wide)

- One shared `BlomstraIndexWidget` class
- Config-driven via `data-biw-*` attributes on a container div
- Pillar-agnostic: works for 2-pillar, 3-pillar, or 4-pillar indices without code changes

---

## The primary + fallback principle

> An index tool calls the centralized model **first**. Only if that's unavailable, empty, or fails does the index fall back to making its own direct API call.

Concretely, each pillar dispatcher takes a mode:

| Mode | Use Case |
|---|---|
| `central` | "Fetch from Central Data" admin button — forces live refresh via Reference Data |
| `central_cached` | Daily cron — zero API calls, reads stored cache |
| `api` | "Fetch from Direct API" admin button — tests fallback path in isolation |
| `auto` | Normal operation — central first, fallback silently if empty |

---

## Migration status

All three CIV pillars have completed migration:

| Pillar | Central Collection | CIV's Role |
|---|---|---|
| Maritime | `blomstra_get_maritime_raw()` | Dispatcher + fallback; owns inversion/structural-zero/percentile |
| HHI (Comtrade) | `blomstra_get_comtrade_hhi_data()` | Dispatcher + fallback; full engine lives centrally |
| Energy (EIA) | `blomstra_get_eia_raw_data()` | Raw per-fuel collection centralized; multi-fuel weighting formula stays in CIV |

---

## Case study: the Comtrade reporter-code bug

USA/India/Belgium consistently showed "no data" for HHI. The cause: Comtrade lists **multiple reporter entries sharing one ISO3** for countries with a discontinuity — e.g. USA has both a current entry (code 842) and an expired "USA and Puerto Rico" entry (code 841, expired 1980). The original code used last-one-wins overwrite, and Comtrade lists the expired entry *after* the current one.

**Fix:** an entry with `entryExpiredDate` can never overwrite an already-stored code. This logic lives in `blomstra_get_comtrade_reporter_map()` — any future index touching Comtrade should use this shared function.
