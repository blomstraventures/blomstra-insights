# Frontend Engine

![Frontend Architecture](../assets/diagram-04-frontend-architecture.png)

---

## Overview

One shared JavaScript engine (`blomstra-index-frontend-engine.js`) and one shared CSS file (`blomstra-index-frontend-styles.css`) power every Blomstra index widget. The engine is entirely **config-driven** via `data-biw-*` HTML attributes. It knows nothing about specific pillar names, counts, or scoring logic.

---

## Boot Process

```javascript
// On DOMContentLoaded (or immediately if already loaded)
document.querySelectorAll('.biw[data-biw-slug]:not([data-biw-initialized])')
```

Each matched element gets one independent `BlomstraIndexWidget` instance. Multiple indices (or multiple copies of the same index) can coexist on one page safely.

---

## Configuration API (data-* attributes)

| Attribute | Required | Example | Purpose |
|---|---|---|---|
| `data-biw-slug` | Yes | `cii` | Unique ID. Used for localStorage keys, history endpoint |
| `data-biw-endpoint` | Yes | `/wp-json/.../critical-infrastructure-index` | REST endpoint for live data |
| `data-biw-names-endpoint` | No | `/wp-json/.../country-names` | ISO3 → name map |
| `data-biw-history-endpoint` | No | auto-derived | For rank-delta arrows |
| `data-biw-score-key` | No | `composite_score` | Primary sort field |
| `data-biw-coverage-key` | No | `coverage_type` | Field for partial/full badge |
| `data-biw-pillars` | Yes | JSON array | Column definitions |
| `data-biw-band-thresholds` | No | `25,50,75` | Score thresholds for bands |
| `data-biw-band-labels` | No | `Low,Medium,High,Extreme` | Band labels |
| `data-biw-title` | No | `Critical Infrastructure...` | Header title |
| `data-biw-methodology` | No | HTML string | Footer methodology text |

### Pillar Definition Shape

```json
[
  {
    "key": "energy_dependency_percentile",
    "raw_key": "energy_dependency_raw",
    "label": "Energy Dependency",
    "color": "#60a5fa",
    "missingNote": "No data for this pillar"
  }
]
```

| Field | Required | Description |
|---|---|---|
| `key` | Yes | API field name for this pillar's normalized value |
| `raw_key` | No | API field name for raw value (shown in modal) |
| `label` | Yes | Display label |
| `color` | Yes | Bar chart / progress bar color |
| `missingNote` | No | Tooltip text when value is null |

---

## State Object

Each widget maintains independent state:

```javascript
{
  all: [],           // all countries from API
  filtered: [],      // after search/filter/sort
  names: {},         // ISO3 → name map
  history: {},       // from index-history endpoint
  indexMeta: null,   // full API response
  view: 'table',     // 'table' | 'grid'
  sortKey: 'composite_score',
  sortAsc: false,
  showWatchlistOnly: false
}
```

---

## Rendering Pipeline

1. **loadData()** — fetches composite + names + history in parallel
2. **applyFilters()** — search text, band filter, watchlist filter
3. **sort** — by selected key, asc/desc toggle
4. **render()** — calls renderTable() + renderGrid(), toggles visibility
5. **Rank display** — uses `rank_display.string_format` directly from API

---

## Rank Rendering (Critical Rule)

**Rank is a property of the DATA, not the VIEW.**

The frontend uses `rank_display.string_format` from the API response. It never recalculates rank. When a user filters by region or sorts by a pillar score, the Rank column still shows the country's **actual global rank**.

```javascript
// CORRECT — static from API
renderCell: (row) => `<td>${row.rank_display.string_format}</td>`

// WRONG — never do this
rows.forEach((row, index) => { row.rank = index + 1; })
```

---

## Rank-Delta Arrows

The frontend computes change by comparing current row against previous snapshot in `state.history`:

```javascript
// Compare current best_estimate vs previous period's best_estimate
// positive delta = moved toward #1 (improved)
// Shows ↑3, ↓1, —, or NEW
```

This is purely presentational. The rank itself always comes from the backend.

---

## Watchlist

Persisted in `localStorage` under key `biw_watchlist_{slug}`. Per-index isolation means watchlists do not leak between indices.

---

## Views

### Table View
- Sortable columns (click header)
- Static rank column
- Bar charts for pillar scores
- Star button (toggle watchlist)
- Detail button (open modal)
- Click row to open modal

### Grid View
- Cards with badge, rank, delta
- Metrics grid (3 columns)
- Star button
- Click card to open modal

### Modal Detail
- Big score + band label
- Pillar progress bars
- Raw values (if `raw_key` defined)
- Watchlist toggle
- Coverage explanation
- Data source attribution

### Watchlist Panel
- Collapsible chips
- Remove button
- Click chip to open modal

### Excluded Panel
- Collapsible table
- Country + reason

---

## Design Principles

1. **Pillar-agnostic** — The engine reads pillar definitions from `data-biw-pillars`. It does not know about "Energy" or "HHI".
2. **Static rank** — Rank comes from API. Frontend never recalculates.
3. **Instance isolation** — Each `.biw` container gets its own widget. No shared globals.
4. **Zero hardcoded logic** — No if-statements for specific indices. A 2-pillar and 4-pillar index use the same engine file.
