# API Reference — `index-frontend-engine.js`

> **File:** `src/frontend/index-frontend-engine.js`  
> **Version:** 2.9.23  
> **Purpose:** Shared, generic renderer for **all** Blomstra index widgets. Scans the page for `[data-biw-slug]` containers and boots one independent instance per container.  
> **Scope:** Site-wide (loaded once via WPCode CSS/JS snippet).  

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Boot Process](#boot-process)
3. [Configuration (data-* Attributes)](#configuration-data--attributes)
4. [State Object](#state-object)
5. [Data Loading](#data-loading)
6. [Filtering & Sorting](#filtering--sorting)
7. [Rendering](#rendering)
8. [Modal / Detail Panel](#modal--detail-panel)
9. [Watchlist](#watchlist)
10. [Event System](#event-system)
11. [Helper Functions](#helper-functions)
12. [Public API](#public-api)
13. [Dependencies](#dependencies)

---

## Architecture Overview

The engine is a single **Immediately Invoked Function Expression (IIFE)**. It exposes nothing to the global scope except one rescan hook. Each widget instance is fully isolated — no shared state, no document-level IDs, no global variables.

```
IIFE
├── boot()                          → scans DOM, instantiates widgets
└── BlomstraIndexWidget(root)       → one per [data-biw-slug] container
    ├── buildShell()                → generates inner HTML
    ├── loadData()                  → fetches 3 endpoints in parallel
    ├── applyFilters()              → search + band + watchlist
    ├── render()                    → table + grid + no-results
    ├── openModal() / closeModal()  → country detail overlay
    └── bindControls()              → event delegation (clash-proof)
```

**Key design decisions:**
- **No jQuery** — pure vanilla JS for speed and compatibility.
- **No IDs** — all selectors scoped to `root.querySelector`.
- **Event delegation** — single listener on `root` for table/grid clicks; checks `event.target.closest()` to avoid conflicts with other site JS.
- **localStorage per slug** — watchlists are namespaced (`biw_watchlist_cii`, `biw_watchlist_hhi`, etc.).

---

## Boot Process

### `boot()`

Scans `document` for all `.biw[data-biw-slug]` elements that do **not** already have `data-biw-initialized="1"`. For each found container:

1. Sets `data-biw-initialized="1"` (prevents double-init)
2. Instantiates `new BlomstraIndexWidget(el)`
3. Catches init errors and renders a fallback error message inside the container

**Called on:** `DOMContentLoaded` (or immediately if DOM already loaded).

**Re-scannable:** `window.BlomstraIndexFrontendRescan = boot` allows page builders / AJAX-loaded shortcodes to trigger a fresh scan after dynamic content injection.

---

## Configuration (data-* Attributes)

Each container `<div>` supplies its own configuration. The engine reads these on instantiation.

| Attribute | Required | Default | Description |
|---|---|---|---|
| `data-biw-slug` | **Yes** | — | Unique index identifier. Used for localStorage keys and REST route construction. |
| `data-biw-endpoint` | **Yes** | — | REST endpoint returning the composite dataset. |
| `data-biw-names-endpoint` | No | `/wp-json/blomstra/v1/country-names` | ISO3 → country name mapping endpoint. |
| `data-biw-history-endpoint` | No | `/wp-json/blomstra/v1/index-history/{slug}` | Historical snapshot endpoint for rank-delta calculation. |
| `data-biw-title` | No | `''` | Page heading. |
| `data-biw-subtitle` | No | `''` | Subheading below title. |
| `data-biw-eyebrow` | No | `'Strategic Intelligence'` | Small category label above title. |
| `data-biw-score-key` | No | `'composite_score'` | Object key for the primary score value. |
| `data-biw-score-label` | No | `'Composite Score'` | Label shown next to scores. |
| `data-biw-coverage-key` | No | `'coverage_type'` | Object key indicating `full` or `partial` coverage. |
| `data-biw-band-thresholds` | No | `'25,50,75'` | Comma-separated percentile cutoffs. |
| `data-biw-band-labels` | No | `'Low,Medium,High,Extreme'` | Labels for each band (count = thresholds + 1). |
| `data-biw-band-select-label` | No | `'All Levels'` | Default dropdown option text. |
| `data-biw-pillars` | No | `'[]'` | JSON array of pillar config objects (`{key, raw_key, label, color}`). |
| `data-biw-methodology` | No | `''` | HTML string shown in the detail panel and below the widget. |

---

## State Object

Each widget maintains its own `state` object:

```javascript
state = {
    all: [],              // Full dataset (array of country objects with .iso3, .name)
    filtered: [],         // Currently visible subset after search/band/watchlist filters
    names: {},            // ISO3 → country name map from names endpoint
    history: {},          // ISO3 → array of historical snapshots
    indexMeta: null,      // Top-level metadata from endpoint (last_updated, version, etc.)
    view: 'table',        // 'table' or 'grid'
    sortKey: scoreKey,    // Current sort column
    sortAsc: false,       // Sort direction
    showWatchlistOnly: false
};
```

---

## Data Loading

### `loadData()`

Fetches three endpoints in parallel via `Promise.all`:

1. **`endpoint`** — composite dataset (countries, scores, pillars, excluded)
2. **`namesEndpoint`** — ISO3 → name mapping
3. **`historyEndpoint`** — historical snapshots for rank-delta calculation

**Cache-busting:** Appends `?_={Date.now()}` to the composite endpoint to prevent browser caching.

**Error handling:** If any fetch fails, renders an error row in the table body with the HTTP status.

**Post-load processing:**
- Extracts `state.indexMeta` (version, last_updated, total_countries)
- Merges country names into each row: `row.name = state.names[row.iso3] || row.iso3`
- Calls `render()`, `renderWatchlistPanel()`, `renderExcludedPanel()`

### `fetchJSON(url)`

Thin wrapper around `fetch()`. Throws on non-OK HTTP status.

---

## Filtering & Sorting

### `band(score)`

Returns the band index (0–3) for a given score based on `bandThresholds`.

**Logic:** Iterates thresholds in order. If `score <= threshold[i]`, returns `i`. If above all thresholds, returns `bandThresholds.length`.

### `applyFilters()`

Applies three filters in sequence:

1. **Search** — `c.name.toLowerCase().indexOf(term) !== -1`
2. **Band filter** — `band(c[scoreKey]) === selectedBand` (if not "all")
3. **Watchlist filter** — `watchlist.indexOf(c.iso3) !== -1` (if `showWatchlistOnly`)

Then sorts `state.filtered`:

| `sortKey` | Behavior |
|---|---|
| `__coverage__` | Full indices first, then partial. Tie-break by score descending. |
| Any pillar key | Numeric sort. `null/undefined` treated as `-Infinity`. |

**Direction:** `sortAsc` toggles ascending/descending.

---

## Rendering

### `render()`

Master render call. Executes:
1. `applyFilters()`
2. `renderHead()` — table header cells
3. `renderTable()` — `<tbody>` rows
4. `renderGrid()` — grid cards
5. Toggles `biw-no-results` visibility

### `renderHead()`

Generates table header with columns:
- ★ (watchlist star)
- Rank
- Δ (delta)
- Country
- Score (with `scoreLabel`)
- One column per pillar
- Action (Details button)

### `renderTable()`

Generates `<tr>` elements for `state.filtered`. Each row contains:
- Star button (toggle watchlist)
- Rank display (definitive or projected range)
- Delta indicator (↑/↓/—/NEW)
- Country name
- Score badge + coverage tag
- Pillar bar cells (colored fill bars)
- Details button

**Rank display logic (`rankHtml`):**
- If `rank_display.is_definitive` → shows exact rank string
- If `rank_display` exists but not definitive → shows projected range with tooltip showing 80% range and theoretical bounds
- Fallback → raw `rank` value or `—`

### `renderGrid()`

Generates card layout for mobile/tablet view. Same data as table, different markup.

### `renderWatchlistPanel()`

Renders the watchlist chip panel above the controls. If empty, shows instructional text. Chips are clickable (opens modal) and have a remove button (×).

### `renderExcludedPanel(excludedDetail, excludedCount)`

Renders a collapsible section showing countries excluded from the index (missing or stale data). Toggle button shows count. Table body lists ISO3 → reason.

---

## Modal / Detail Panel

### `openModal(c)`

Opens a full-screen overlay modal for a single country.

**Modal sections:**
1. **Header** — Country name + rank + delta
2. **Big score** — Large numeric score with band color
3. **Pillar bars** — Full-width colored bars for each pillar
4. **Raw values grid** — `raw_key` values (if defined in pillar config)
5. **Actions** — Add/Remove from Watchlist, Close
6. **Analysis text** — Coverage explanation (partial vs full)
7. **Sources** — HHI source, maritime source, last updated timestamp

### `closeModal()`

Removes `active` class from modal. Clicking outside the modal content or pressing the × button triggers this.

---

## Watchlist

### `toggleWatchlist(iso3)`

Adds or removes an ISO3 from the watchlist array. Persists to `localStorage` under key `biw_watchlist_{slug}`.

**Quota safety:** `try/catch` wrapped around `localStorage.setItem` to silently ignore quota-exceeded errors.

**UI updates:** Triggers `render()` and `renderWatchlistPanel()` to reflect changes immediately.

---

## Event System

All events use **delegation** on the widget root or specific containers. This prevents conflicts with other plugins, page builders, or theme JS.

| Event | Target | Action |
|---|---|---|
| `input` | `.biw-search` | Re-filter on keystroke |
| `change` | `.biw-band-filter` | Re-filter on band selection |
| `change` | `.biw-sort-select` | Change sort column, reset to descending |
| `click` | `.biw-btn-sort` | Toggle sort direction (↑/↓) |
| `click` | `.biw-btn-view` | Switch between table/grid view |
| `click` | `.biw-watchlist-toggle` | Toggle "show watchlist only" filter |
| `click` | `.biw-star-btn` | Toggle watchlist for that row |
| `click` | `.biw-btn-detail` | Open modal for that row |
| `click` | `tr[data-idx]` / `.biw-grid-card` | Open modal (row/card click) |
| `click` | `.biw-modal-close` / modal backdrop | Close modal |
| `click` | `.biw-modal-btn[data-action="modal-star"]` | Toggle watchlist from inside modal |
| `click` | `.biw-watchlist-items` | Open modal from chip, or remove via × |
| `click` | `.biw-excluded-toggle` | Expand/collapse excluded countries table |
| `click` | `.biw-btn-print` | `window.print()` |
| `click` | `.biw-btn-share` | Share on X, LinkedIn, or copy URL to clipboard |

**Share logic:**
- **X** — Opens `twitter.com/intent/tweet` popup
- **LinkedIn** — Opens LinkedIn share-offsite popup
- **Copy** — Uses `navigator.clipboard.writeText()` with `document.execCommand('copy')` fallback. Shows checkmark SVG for 1.5s.

---

## Helper Functions

### `esc(s)`

HTML entity encoder. Escapes `& < > " '` to prevent XSS when injecting dynamic values into innerHTML.

### `fmtNum(n)`

Rounds to 1 decimal place. Returns `—` for `null`/`undefined`.

### `deltaInfo(c)`

Calculates rank change since last snapshot.

**Logic:**
1. Gets `currentBest` from `rank_display.best_estimate` or `rank`
2. Compares against the **second-to-last** history entry (last entry is current month)
3. Determines direction: `up` (climbed toward #1), `down`, `flat`, or `new` (insufficient history)
4. Flags `approx` if either current or previous rank was non-definitive (projected)

**Returns:** `{type: 'up'|'down'|'flat'|'new', value?: number, approx?: boolean}`

### `deltaHtml(c)`

Renders the delta cell: colored arrow + number, `—` for flat, or `NEW` badge for first-time entries.

### `pillarCellHtml(c, p)`

Renders a table cell with a colored progress bar. If value is null, shows `—` with a tooltip.

### `badgeHtml(score, coverage)`

Renders the score badge with band color class + coverage tag (`FULL` or `PARTIAL`).

---

## Public API

### `window.BlomstraIndexFrontendRescan`

**Type:** `function`

**Purpose:** Re-runs `boot()` to scan for new `.biw` containers. Useful when:
- A page builder injects shortcodes via AJAX
- A tab system lazily loads content
- Dynamic content is appended after initial page load

**Usage:**
```javascript
// After injecting new shortcode content via AJAX
window.BlomstraIndexFrontendRescan();
```

---

## Dependencies

| Dependency | File | Role |
|---|---|---|
| **Frontend Styles** | `src/frontend/index-frontend-styles.css` | Provides `.biw-*` CSS classes. Without this, the widget is unstyled. |
| **Composite Endpoint** | `cii-backend.php` (or index backend) | Serves country data with scores, ranks, pillars, coverage. |
| **Country Names** | `global-reference-data.php` | Serves ISO3 → name mapping. |
| **History Endpoint** | `global-reference-data.php` | Serves historical snapshots for delta calculation. |

**No dependencies on:** jQuery, React, Vue, Bootstrap, or any external JS library.

---

## Data Flow

```
Page Load
    → IIFE executes
        → boot() scans DOM
            → finds [data-biw-slug="cii"]
                → new BlomstraIndexWidget(el)
                    → buildShell() → injects HTML into container
                    → bindControls() → attaches event listeners
                    → loadData()
                        → Promise.all([composite, names, history])
                            → merge names into rows
                            → render()
                                → applyFilters() → search/band/watchlist
                                → renderTable() / renderGrid()
                    → User clicks "Details"
                        → openModal() → overlay with pillar bars + raw values
                    → User clicks "★"
                        → toggleWatchlist() → localStorage + re-render
```

---

## Browser Compatibility

| Feature | Requirement |
|---|---|
| `fetch()` | Modern browsers + IE11 polyfill if needed |
| `Promise` | Modern browsers + IE11 polyfill if needed |
| `localStorage` | Required for watchlist persistence |
| `document.querySelector` | Universal |
| `Array.prototype.find` | IE11 may need polyfill |

> **Note:** The engine uses `var` (not `let`/`const`) and traditional functions for maximum compatibility, but relies on `fetch`, `Promise`, and `Array.prototype.find` which may need polyfills for IE11.
