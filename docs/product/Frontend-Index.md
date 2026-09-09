# FRONTEND.md – Blomstra Insights Frontend Engine

Document version: 1.0.0\
Status: CANONICAL\
Applies to: `src/frontend/`\
Last verified against commit: (to be filled)\
Source files: `index-frontend-engine.js` (v4.1.8, 3,208 lines), `index-frontend-styles.css` (v4.1.8, 2,175 lines), `index-frontend-utility.js` (25 lines)\
Effective date: 2026-09-09

***


## Document Control

| Field                   | Value                                                                                                                               |
| :---------------------- | :---------------------------------------------------------------------------------------------------------------------------------- |
| Document                | `FRONTEND.md` v1.0.0                                                                                                                |
| Verified against commit | (to be filled)                                                                                                                      |
| Source files            | `index-frontend-engine.js` (L4 main engine), `index-frontend-styles.css` (L4 styles), `index-frontend-utility.js` (L4 shared utils) |
| Method                  | Code-first reconstruction (Stage 2) — supersedes the structural summary in `ARCHITECTURE.md` §6 with full interaction-model detail. |
| Status                  | Complete for current code. Items in §11 are open questions, not implementation gaps.                                                |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## Table of Contents

1. Overview

2. Boot Sequence

3. Configuration Contract

4. State Management

5. Data Loading

6. Filtering, Sorting, and Year Selection

7. Historical Year Mechanics

8. Views

9. Features

10. Clash-Proof Design

11. Dependencies

12. Theme & Styling

13. Open Questions

14. Corrections to Prior Documentation

***


## 1. Overview

\[D] The Blomstra frontend is a generic, config-driven widget engine that powers all indices (SIVI today; SERI, GPRI future). One JavaScript file and one CSS file serve all indices. The WordPress shortcode passes configuration via `data-biw-*` attributes; the engine reads them and renders the appropriate visualization.

\[D] The frontend engine (`index-frontend-engine.js` v4.1.8) is a self-contained vanilla JavaScript application with no external framework dependencies (except D3 and topojson, loaded on demand).

\[N] Clash-proof guarantee: The engine must never conflict with other site JavaScript. No global variables (except the `BIW_*` utility globals). No prototype pollution. All event listeners are scoped per widget instance. All CSS classes are prefixed with `.biw-`.

\[D] The frontend currently supports:

| Feature                                  | Status        |
| :--------------------------------------- | :------------ |
| Dashboard view (map, charts, cards)      | ✅ Implemented |
| Table view (sortable, filterable)        | ✅ Implemented |
| Country drawer (details, radar, history) | ✅ Implemented |
| Country comparison (up to 4 countries)   | ✅ Implemented |
| Group comparison (pre-defined groups)    | ✅ Implemented |
| Watchlist (localStorage per slug)        | ✅ Implemented |
| CSV Export                               | ✅ Implemented |
| Sharing (X, LinkedIn, copy link)         | ✅ Implemented |
| Dark mode                                | ✅ Implemented |
| D3 map with year slider                  | ✅ Implemented |
| DQI & provenance display                 | ✅ Implemented |
| Sensitivity interval display             | ✅ Implemented |

***


## 2. Boot Sequence

### 2.1 Discovery & Initialization

\[D] `boot()` runs on script load (or when `window.BlomstraIndexFrontendRescan()` is called). It queries:

javascript

    document.querySelectorAll('.biw[data-biw-slug]:not([data-biw-initialized])')

\[D] For each match, it:

1. Marks the element `data-biw-initialized="1"` to prevent double-instantiation

2. Creates a new `BlomstraIndexWidget(root)` instance

\[N] Each widget instance is fully self-contained — no shared module-level state between multiple widgets on the same page except:

- The two globals from `index-frontend-utility.js` (§3.4)

- Whatever the browser itself shares (`localStorage`, D3/TopoJSON script tags loaded once and reused)


### 2.2 Rescan Capability

\[D] A rescan function is exposed globally:

javascript

    window.BlomstraIndexFrontendRescan = boot;

\[N] This allows dynamically added widgets (e.g., via page builders, AJAX-loaded content) to be initialized after page load.


### 2.3 Loading Flow

text

    Page Load
        │
        ▼
    boot() scans .biw[data-biw-slug]:not([data-biw-initialized])
        │
        ▼
    new BlomstraIndexWidget(root) per match
        │
        ├─ root.innerHTML = buildShell()
        ├─ bindControls()
        ├─ loadData()
        └─ (if view === 'dashboard') ensureD3() → ensureTopojson()

***


## 3. Configuration Contract

### 3.1 All `data-biw-*` Attributes

\[N] The engine reads ALL configuration from `data-biw-*` attributes. No configuration is hardcoded in JavaScript (except documented fallback defaults).

| Attribute                   | Required | Purpose                                                                     | Default                                     |
| :-------------------------- | :------- | :-------------------------------------------------------------------------- | :------------------------------------------ |
| `data-biw-slug`             | Yes      | Index identifier; used for `localStorage` keys and default history endpoint | —                                           |
| `data-biw-endpoint`         | Yes      | Main data REST URL                                                          | —                                           |
| `data-biw-names-endpoint`   | No       | Country names endpoint                                                      | `/wp-json/blomstra/v1/country-names`        |
| `data-biw-history-endpoint` | No       | Historical snapshots endpoint                                               | `/wp-json/blomstra/v1/index-history/{slug}` |
| `data-biw-score-key`        | Yes      | Field name for composite score in fetched JSON                              | `sivi_structural`                           |
| `data-biw-coverage-key`     | Yes      | Field name for coverage type                                                | `coverage`                                  |
| `data-biw-view`             | No       | Initial view                                                                | `dashboard`                                 |
| `data-biw-pillars`          | No       | JSON array of `{key, raw_key, label, color}`                                | `[]`                                        |
| `data-biw-band-thresholds`  | No       | Comma-separated score cutoffs                                               | `25,50,75`                                  |
| `data-biw-band-labels`      | No       | Labels for bands (must have one more than thresholds)                       | `Low,Medium,High,Extreme`                   |
| `data-biw-score-label`      | No       | Display label for score column/axis                                         | `Vulnerability Score`                       |
| `data-biw-methodology`      | No       | Text shown in methodology popup                                             | `''`                                        |
| `data-biw-year-min`         | No       | Minimum year for slider                                                     | `2004`                                      |
| `data-biw-year-max`         | No       | Maximum year for slider                                                     | Current year                                |
| `data-biw-title`            | No       | Widget title                                                                | `''`                                        |
| `data-biw-subtitle`         | No       | Widget subtitle                                                             | `''`                                        |
| `data-biw-eyebrow`          | No       | Eyebrow text                                                                | `'Strategic Intelligence'`                  |
| `data-biw-block-groups`     | No       | JSON array (or single string) restricting which country groups are offered  | All groups                                  |

\[N] Per Invariant #5 (`ARCHITECTURE.md` §7), the only hardcoded index-specific value anywhere in the engine is the `sivi_structural` fallback default on `score-key` — present purely for markup that predates the attribute existing, not as an assumption the engine makes about which index it's rendering.


### 3.2 Pillar Configuration Schema

\[D] The `data-biw-pillars` attribute is a JSON array:

json

    [
        {
            "key": "energy_dependency_percentile",
            "raw_key": "energy_dependency_raw",
            "label": "Energy Dependency",
            "color": "#60a5fa"
        },
        {
            "key": "supplier_concentration_percentile",
            "raw_key": "supplier_concentration_raw",
            "label": "Supplier Concentration",
            "color": "#f87171"
        },
        {
            "key": "maritime_vulnerability_percentile",
            "raw_key": "maritime_connectivity_raw",
            "label": "Maritime Exposure",
            "color": "#fb923c"
        }
    ]

\[N] The engine uses `key` for the score field, `label` for display, and `color` for visual elements (bars, charts). No pillar-specific logic is hardcoded.


### 3.3 Band Configuration

\[D] Bands are computed from the composite score:

javascript

    function band(score) {
        for (var i = 0; i < bandThresholds.length; i++) {
            if (score <= bandThresholds[i]) return i;
        }
        return bandThresholds.length;
    }

\[N] With default thresholds `25,50,75`, a score of exactly 25 lands in band 0 ("Low"), not band 1.

| Band    | Score Range                               | CSS Class           |
| :------ | :---------------------------------------- | :------------------ |
| Low     | 0 – `bandThresholds[0]`                   | `biw-badge-low`     |
| Medium  | `bandThresholds[0]` – `bandThresholds[1]` | `biw-badge-medium`  |
| High    | `bandThresholds[1]` – `bandThresholds[2]` | `biw-badge-high`    |
| Extreme | `bandThresholds[2]` +                     | `biw-badge-extreme` |


### 3.4 Utility Globals (`index-frontend-utility.js`)

\[N] The utility globals must load before the main engine:

javascript

    window.BIW_ISO3_LOOKUP = {
        "004": "AFG",
        "008": "ALB",
        // ... 200+ country mappings
    };

    window.BIW_GET_ISO3 = function(id) {
        return window.BIW_ISO3_LOOKUP[String(id)] || null;
    };

    window.BIW_COUNTRY_GROUPS = {
        'G7': ['USA', 'GBR', 'CAN', 'FRA', 'DEU', 'ITA', 'JPN'],
        'BRICS': ['BRA', 'RUS', 'IND', 'CHN', 'ZAF'],
        'BRICS+': ['BRA', 'RUS', 'IND', 'CHN', 'ZAF', 'IRN', 'ARE', 'ETH', 'EGY'],
        'EU': ['AUT', 'BEL', 'BGR', 'HRV', 'CYP', 'CZE', 'DNK', 'EST', 'FIN', 'FRA', 'DEU', 'GRC', 'HUN', 'IRL', 'ITA', 'LVA', 'LTU', 'LUX', 'MLT', 'NLD', 'POL', 'PRT', 'ROU', 'SVK', 'SVN', 'ESP', 'SWE'],
        'G20': ['ARG', 'AUS', 'BRA', 'CAN', 'CHN', 'FRA', 'DEU', 'IND', 'IDN', 'ITA', 'JPN', 'MEX', 'RUS', 'SAU', 'ZAF', 'KOR', 'TUR', 'GBR', 'USA'],
        'ASEAN': ['BRN', 'KHM', 'IDN', 'LAO', 'MYS', 'MMR', 'PHL', 'SGP', 'THA', 'VNM'],
        'GCC': ['BHR', 'KWT', 'OMN', 'QAT', 'SAU', 'ARE'],
        'Nordic': ['DNK', 'FIN', 'ISL', 'NOR', 'SWE'],
        'Baltic': ['EST', 'LVA', 'LTU'],
    };

\[D] The main engine checks for these globals:

javascript

    if (typeof window.BIW_GET_ISO3 !== 'function') {
        console.warn('Blomstra: BIW_GET_ISO3 not found – country codes will fail.');
    }
    if (!window.BIW_COUNTRY_GROUPS) {
        console.warn('Blomstra: BIW_COUNTRY_GROUPS not found – block comparisons disabled.');
    }

***


## 4. State Management

### 4.1 State Object

\[D] Each `BlomstraIndexWidget` instance maintains its own state:

javascript

    state = {
        all: [],              // all countries from REST endpoint
        filtered: [],         // filtered/sorted countries
        names: {},            // country names
        history: {},          // historical snapshots (iso3 => [entries])
        indexMeta: null,      // index metadata
        view: 'dashboard',    // current view
        sortKey: 'rank',      // current sort column
        sortAsc: false,       // sort direction
        showWatchlistOnly: false,
        selectedCountry: null,
        compareList: [],      // up to 4 countries
        isDark: false,
        selectedYear: maxYear,
        mapLayer: 'score',    // 'score' or pillar key
        d3Ready: false,
        d3Error: null,
        hasRegionData: false,
        // Map state
        mapProjection: null,
        mapPath: null,
        mapFeatures: null,
        mapMarkers: null,
        mapHintObserver: null,
        mapHintTimeout: null,
        // Block comparison state
        blockCompareA: null,
        blockCompareB: null,
    };

\[D] `watchlist` is not in `state` — it's a separate array persisted to `localStorage` under `biw_watchlist_{slug}`, loaded once at construction and read/written directly by `toggleWatchlist()`.


### 4.2 State Updates

\[D] State updates trigger re-rendering via `render()`:

javascript

    function render() {
        applyFilters();
        renderHead();
        renderTable();
        if (view === 'dashboard') {
            updateSummary();
            if (state.d3Ready) {
                renderDonut();
                renderExtremes();
                renderHistogram();
                renderScatter();
                updateMapMarkers();
            }
        }
        updateCompareDock();
    }


### 4.3 Deep-Linking (URL Hash State)

\[D] `updateHash()`/`decodeHash()` round-trip four pieces of state through the URL fragment:

| Hash Parameter | Purpose                             |
| :------------- | :---------------------------------- |
| `year`         | Selected year (only if non-default) |
| `layer`        | Map layer (only if non-default)     |
| `country`      | Opens that country's drawer on load |
| `watchlist=1`  | Watchlist-only filter               |

\[D] Only non-default values are written, so a widget with nothing special selected has no hash at all. This is the only state that survives a page reload/share — everything else (compare list, dark mode preference aside, applied text search) resets.


### 4.4 Dark Mode

\[D] `toggleDark()` toggles a `biw-light` class on the root element and persists the choice to `localStorage` (`biw_dark_mode_{slug}`) — per-slug, not global across all widgets on a page.

***


## 5. Data Loading

### 5.1 Endpoints

\[D] The engine fetches from three endpoints:

| Endpoint | Purpose              | URL                         |
| :------- | :------------------- | :-------------------------- |
| Primary  | Index data           | `data-biw-endpoint`         |
| Names    | Country names        | `data-biw-names-endpoint`   |
| History  | Historical snapshots | `data-biw-history-endpoint` |


### 5.2 Loading Flow

javascript

    function loadData() {
        Promise.all([
            fetchJSON(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now()),
            fetchJSON(namesEndpoint).catch(function () { return {}; }),
            fetchJSON(historyEndpoint).catch(function (err) {
                console.warn('History endpoint unavailable:', err.message || err);
                return {};
            })
        ]).then(function (results) {
            var data = results[0];
            state.names = results[1] || {};
            state.history = results[2] || {};
            state.indexMeta = data;
            // ... process, render
        }).catch(function (err) {
            // ... render error
        });
    }

\[N] The names and history endpoints are `.catch()` to empty objects rather than failing the whole load, so a widget still renders (without names/history) if either secondary endpoint is unavailable.

\[D] `fetchJSON()` rejects on any non-2xx HTTP status.


### 5.3 Response Shaping

\[D] Each country row gets:

- `iso3` attached (from the key)

- `name` attached (from `state.names`)

- `region` defaults to `'Other'` if absent

- `rank` backfilled from `rank_display.best_estimate` if `rank` itself is absent

\[D] The region filter UI is hidden entirely if no country in the dataset has a `region` field (`hasRegionData`).


### 5.4 D3/TopoJSON Lazy Loading

\[D] D3 and TopoJSON are only loaded when `view === 'dashboard'` — the table view never pays the cost of loading the mapping/visualization libraries.

javascript

    if (view === 'dashboard') {
        ensureD3()
            .then(ensureTopojson)
            .then(function () {
                state.d3Ready = true;
                renderDashboard();
            })
            .catch(function (err) {
                state.d3Error = err.message || 'D3 or topojson failed to load';
                // Show error overlay on map container
            });
    }

\[D] A load failure surfaces an inline error box over the map container rather than failing the whole widget.

***


## 6. Filtering, Sorting, and Year Selection

### 6.1 Filtering

\[D] `applyFilters()` runs on every render:

| Filter         | Description                                       |
| :------------- | :------------------------------------------------ |
| Text search    | Case-insensitive substring match on country name  |
| Region filter  | Matches `c.region` (hidden if no region data)     |
| Band filter    | Matches `band(score)` against selected band index |
| Watchlist-only | Shows only countries in `watchlist`               |


### 6.2 Sorting

\[D] Supported sort keys:

| Sort Key      | Behavior                                |
| :------------ | :-------------------------------------- |
| `name`        | Alphabetical by country name            |
| `rank`        | Numeric by recomputed rank (ascending)  |
| `scoreKey`    | Numeric by composite score (descending) |
| `coverage`    | Full before partial, tie-broken by rank |
| `{pillarKey}` | Numeric by pillar score (descending)    |


### 6.3 Year Selection

\[D] All score/rank/pillar lookups for the selected year go through `getScoreForYear()`, `getHistoricalPillar()`, and `getRecomputedRank()` rather than the live top-level fields directly — so changing the year slider re-derives the entire table/chart/CSV state from historical snapshots without a new network fetch.

***


## 7. Historical Year Mechanics

### 7.1 Historical Entry Lookup

\[D] `getHistoricalEntry(iso3, year)`:

1. First looks for an exact-year match within that country's history array

2. Picks the latest period string if more than one row matches the same year

3. Falls back to the closest available year by absolute distance


### 7.2 Rank Recomposition

\[N] Rank recomputation, not stored rank, drives history/comparison views:

`getRecomputedRank(iso3, year)` rebuilds the full ranking client-side from every country's historical score at that year (sorted descending, position = rank) rather than trusting any stored historical rank field.

Why: A rank stored at snapshot time reflects that build's country set, which may differ from the current set being displayed/filtered.

\[D] Fallback behavior:

- If fewer than 2 countries have historical data for the requested year → falls back to the country's current live rank (or `rank_display.best_estimate`)

- Early years with sparse backfill degrade gracefully to "current rank" rather than showing a meaningless 1-country ranking


### 7.3 Historical Score/Pillar Retrieval

javascript

    function getHistoricalScore(iso3, year) {
        var entry = getHistoricalEntry(iso3, year);
        return entry ? entry.composite_score : null;
    }

    function getHistoricalPillar(iso3, year, pillarKey) {
        var entry = getHistoricalEntry(iso3, year);
        if (entry && entry.pillars && entry.pillars[pillarKey] !== undefined) {
            return entry.pillars[pillarKey];
        }
        if (entry && entry[pillarKey] !== undefined) {
            return entry[pillarKey];
        }
        return null;
    }

***


## 8. Views

### 8.1 Dashboard View

\[D] `view === 'dashboard'` renders:

| Component          | Description                                                                                                                                                  |
| :----------------- | :----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Summary Cards      | Countries Covered (total, full/partial breakdown), Composite Analytics (range, median, mean), Vulnerability Distribution (band counts), Top Movers (up/down) |
| Toolbar            | Map layer selector (Composite + per-pillar), Dark mode toggle                                                                                                |
| World Map          | D3 choropleth with year slider, layer selection, zoom controls, tooltips, markers (most vulnerable, least vulnerable, biggest mover)                         |
| Map Side Panel     | Risk distribution (donut chart), Vulnerability extremes (most/least vulnerable list)                                                                         |
| Pillar Scatter     | 2D scatter plot of any two pillars, hoverable/clickable                                                                                                      |
| Score Distribution | Histogram of composite scores by band, hoverable, clickable (click drills into that bin's countries)                                                         |


### 8.2 Table View

\[D] `view === 'table'` renders:

| Component        | Description                                                                                          |
| :--------------- | :--------------------------------------------------------------------------------------------------- |
| Search           | Filter by country name                                                                               |
| Region Filter    | Filter by region (if region data available)                                                          |
| Band Filter      | Filter by vulnerability band                                                                         |
| Sort             | Sort by Rank, Country, Score, Coverage, or any pillar                                                |
| Watchlist Toggle | Show only watchlist countries                                                                        |
| CSV Export       | Export current filtered/sorted data                                                                  |
| Share Buttons    | X, LinkedIn, Copy link                                                                               |
| Table            | Sortable, filterable with rank, delta, country, score, pillar bars, compare checkbox, details button |


### 8.3 Country Drawer

\[D] Clicking a country row opens a slide-over drawer with:

| Section              | Content                                                                           |
| :------------------- | :-------------------------------------------------------------------------------- |
| Header               | Country name                                                                      |
| Score & Rank         | Composite score, definitive or partial rank, risk badge, delta (▲/▼/—), DQI badge |
| Pillar Radar         | D3 radar chart of all pillar scores (spider chart)                                |
| Pillar Bars          | Horizontal progress bars per pillar                                               |
| Sensitivity Interval | 95% weight-sensitivity range bar (only if `sensitivity_interval` is present)      |
| History Sparkline    | Score trend over time (from snapshots)                                            |
| Provenance           | Data quality per pillar (source, year, freshness: good/aged/stale/missing)        |
| Actions              | Watchlist, Compare, Share                                                         |

\[D] The `data_freshness` object powers the provenance panel — it shows per-pillar source, year, and a quality dot (good/aged/stale/missing). This is the frontend's visual surface for the DQI concept documented in `SIVI.md` §9.


### 8.4 Map

\[D] The map is rendered with D3 and topojson:

| Feature        | Implementation                                                                                                 |
| :------------- | :------------------------------------------------------------------------------------------------------------- |
| Projection     | `d3.geoNaturalEarth1()`                                                                                        |
| Data           | `world-atlas@2/countries-110m.json`                                                                            |
| Year Slider    | Input range with play button (animates years, 800ms interval)                                                  |
| Layer Selector | Composite or per-pillar layers                                                                                 |
| Zoom           | Ctrl+scroll, double-click to zoom, drag to pan; zoom in/out/reset controls                                     |
| Tooltip        | Country name, rank, score, pillars, DQI                                                                        |
| Markers        | Most vulnerable, least vulnerable, biggest mover (pulsing glow)                                                |
| Hint           | First-visit hint shown via `IntersectionObserver` (only if map is scrolled into view), auto-dismisses after 3s |

***


## 9. Features

### 9.1 Watchlist

\[D] Watchlist is stored in `localStorage`:

javascript

    var watchlistKey = 'biw_watchlist_' + slug;
    try { watchlist = JSON.parse(localStorage.getItem(watchlistKey) || '[]'); } catch (e) { watchlist = []; }

\[N] No authentication is required — watchlist is client-side only.

Features:

- Toggle watchlist via star icon in table or drawer

- Filter to show only watchlist countries

- Highlighted watchlist countries in all views

- Persisted across sessions


### 9.2 Country Comparison (Up to 4 Countries)

\[D] `toggleCompare(iso3)`:

| Feature   | Implementation                                      |
| :-------- | :-------------------------------------------------- |
| Selection | Checkbox in table or "Compare" button in drawer     |
| Maximum   | 4 countries (alert if > 4)                          |
| Dock      | Bottom dock with selected countries, remove buttons |
| Modal     | Radar chart overlay + metric comparison table       |
| Colors    | Each country gets a unique color (palette of 5)     |

Comparison Metrics:

- Rank, Composite Score, DQI, Coverage

- All pillar scores


### 9.3 Group Comparison

\[D] Pre-defined country groups (from `BIW_COUNTRY_GROUPS`):

| Group  | Members                                     |
| :----- | :------------------------------------------ |
| G7     | USA, GBR, CAN, FRA, DEU, ITA, JPN           |
| BRICS  | BRA, RUS, IND, CHN, ZAF                     |
| BRICS+ | BRA, RUS, IND, CHN, ZAF, IRN, ARE, ETH, EGY |
| EU     | 27 member states                            |
| G20    | 20 members                                  |
| ASEAN  | 10 members                                  |
| GCC    | 6 members                                   |
| Nordic | DNK, FIN, ISL, NOR, SWE                     |
| Baltic | EST, LVA, LTU                               |

Features:

- Quick buttons on block card (G7, BRICS, EU, ASEAN)

- Dropdown in table toolbar

- `data-biw-block-groups` can restrict which groups are offered

- Modal with:

  - Group comparison radar (spider chart overlay)

  - Summary statistics (avg, min, max, stddev)

  - Detailed comparison table (all metrics)

  - Member list with scores and ranks

  - CSV export


### 9.4 CSV Export

\[D] `exportCSV()`:

javascript

    function exportCSV() {
        var data = state.filtered.length ? state.filtered : state.all;
        var headers = ['Country', 'Rank', 'Score', ...pillarLabels, 'Coverage', 'DQI'];
        // Build rows from filtered data
        // Standard CSV escaping (quotes doubled, fields with commas/quotes/newlines quoted)
        // Download as {slug}_export_{ISO date}.csv
    }

\[N] The `scoreKey` and `pillars` from config determine which fields are exported — no hardcoded fields.


### 9.5 Sharing

\[D] Share buttons in toolbar and block modal:

| Button | Action                        |
| :----- | :---------------------------- |
| 𝕏     | Open X (Twitter) share intent |
| in     | Open LinkedIn share dialog    |
| 🔗     | Copy current URL to clipboard |

\[D] Chart-specific sharing: scatter and histogram charts have their own share buttons.

Known Issue: `openShareModal()` is currently a placeholder `alert()` referencing toolbar share buttons — not an actual modal despite the function name.


### 9.6 DQI & Provenance Display

\[D] DQI is displayed in:

- Country drawer (DQI badge, color-coded: high/good, medium/acceptable, low/stale)

- Country comparison (DQI column)

- Map tooltip (DQI)

- CSV export (DQI column)

\[D] Provenance is displayed in the country drawer:

- Per-pillar source, year, freshness (good/aged/stale/missing)

- Color-coded dots (green/amber/orange/grey)

***


## 10. Clash-Proof Design

### 10.1 CSS Namespacing

\[N] All CSS classes are prefixed with `.biw-`:

| Class               | Purpose                 |
| :------------------ | :---------------------- |
| `.biw`              | Root container          |
| `.biw-table`        | Table wrapper           |
| `.biw-drawer`       | Country drawer          |
| `.biw-map-viewport` | Map container           |
| `.biw-badge-low`    | Low vulnerability badge |

\[N] No global element selectors (e.g., `table { ... }`) are used. All styles are scoped to `.biw`.


### 10.2 JavaScript Isolation

\[N] No global variables (except `window.BIW_*` utilities). The engine uses:

| Technique              | Implementation                                                         |
| :--------------------- | :--------------------------------------------------------------------- |
| IIFE                   | `(function () { 'use strict'; ... })()`                                |
| Per-instance state     | Each `BlomstraIndexWidget` has its own `state`                         |
| Scoped event listeners | Events are attached to the root element via delegation, not `document` |


### 10.3 Multi-Instance Safety

\[N] Multiple widgets on the same page are supported:

javascript

    var containers = document.querySelectorAll('.biw[data-biw-slug]:not([data-biw-initialized])');
    containers.forEach(function (el) {
        el.setAttribute('data-biw-initialized', '1');
        new BlomstraIndexWidget(el);
    });

\[N] Each instance has its own:

- DOM elements (scoped to root)

- Event listeners (scoped to root)

- State (isolated)

- Config (from `data-biw-*`)

***


## 11. Dependencies

### 11.1 External Libraries

\[D] The engine loads D3 and topojson on demand (lazy loading):

| Library  | Version | Purpose               | Loaded On      |
| :------- | :------ | :-------------------- | :------------- |
| D3       | 7.8.5   | Map rendering, charts | Dashboard view |
| Topojson | 3.x     | Map data parsing      | Dashboard view |

javascript

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = function () { reject(new Error('Failed to load: ' + src)); };
            document.head.appendChild(script);
        });
    }


### 11.2 Browser APIs

| API                    | Purpose                            |
| :--------------------- | :--------------------------------- |
| `fetch`                | REST data loading                  |
| `localStorage`         | Watchlist, dark mode, compare list |
| `URL`                  | Share links                        |
| `IntersectionObserver` | Map hint visibility                |
| `Clipboard API`        | Copy link                          |

***


## 12. Theme & Styling

### 12.1 CSS Variables

\[D] The styles use CSS custom properties for theming:

css

    .biw {
        --biw-obsidian: #0A0C10;
        --biw-obsidian-card: #12151C;
        --biw-obsidian-elevated: #1A1E28;
        --biw-obsidian-border: #232833;
        --biw-champagne-light: #E6DFD5;
        --biw-champagne: #C5A880;
        --biw-champagne-dim: #8C7A63;
        --biw-slate: #B8BEC9;
        --biw-slate-dim: #6B7280;
        --biw-low: #4ade80;
        --biw-medium: #fac678;
        --biw-high: #eb674e;
        --biw-extreme: #a61b13;
        --biw-no-data: #2a2a3a;
        --biw-bg: var(--biw-obsidian-card);
        --biw-text: var(--biw-champagne-light);
        --biw-border: var(--biw-obsidian-border);
        --biw-card-bg: var(--biw-obsidian-elevated);
        --biw-tooltip-bg: rgba(10, 12, 16, 0.92);
    }


### 12.2 Light Mode

\[D] Light mode is toggled by adding `.biw-light` to the root:

css

    .biw.biw-light {
        --biw-bg: #f0f2f5;
        --biw-text: #1a1a2e;
        --biw-border: #d0d5dd;
        --biw-card-bg: #ffffff;
        --biw-obsidian-border: #d0d5dd;
        --biw-obsidian-card: #ffffff;
        --biw-obsidian-elevated: #f8f9fa;
        --biw-champagne: #8B7A5E;
        --biw-champagne-dim: #6B5D4A;
        --biw-slate: #4a4a6a;
        --biw-tooltip-bg: rgba(255, 255, 255, 0.95);
        --biw-no-data: #c7c8ca;
    }


### 12.3 Responsive Breakpoints

| Breakpoint | Changes                                                                                     |
| :--------- | :------------------------------------------------------------------------------------------ |
| ≤ 1024px   | Map hero stacks vertically, chart grid stacks, modals narrower                              |
| ≤ 768px    | Padding reduced, toolbar stacks, table font smaller, drawer full width, year slider compact |
| ≤ 480px    | Block comparison summary cards stack                                                        |


### 12.4 Print Styles

\[D] Print styles hide interactive elements:

css

    @media print {
        .biw-controls,
        .biw-widget,
        .biw-map-hero,
        .biw-chart-grid,
        .biw-table-toolbar,
        .biw-compare-dock,
        .biw-drawer-overlay,
        .biw-drawer { display: none !important; }
        .biw { background: #fff; color: #000; }
        .biw-table thead th { color: #333; border-bottom: 2px solid #333; }
        .biw-table tbody td { border-bottom: 1px solid #ddd; color: #333; }
    }

***


## 13. Open Questions

\[D] These are product/design decisions that require explicit resolution:

| #  | Question                                                                                   | Notes                                          |
| :- | :----------------------------------------------------------------------------------------- | :--------------------------------------------- |
| 1  | Group Comparison – Should `BIW_COUNTRY_GROUPS` be configurable per widget or globally?     | Currently global from utility file             |
| 2  | Map Data – Should the map use a higher-resolution world atlas (e.g., 50m instead of 110m)? | Currently 110m for performance                 |
| 3  | Mobile Experience – Is the current mobile layout sufficient for all features?              | Responsive but complex features may be cramped |
| 4  | Offline Support – Should the widget work offline with cached data?                         | Currently requires online fetch                |
| 5  | Accessibility – What accessibility standards must the widget meet (WCAG level)?            | Not defined                                    |
| 6  | Share Modal – `openShareModal()` is a placeholder `alert()`                                | Should be replaced with a real modal           |

***


## 14. Corrections to Prior Documentation

\[D] The following corrections are made from prior documentation:

| Prior Claim                      | Correction                                                                | Source                                                     |
| :------------------------------- | :------------------------------------------------------------------------ | :--------------------------------------------------------- |
| Frontend was SIVI-specific       | Frontend is generic, config-driven via `data-biw-*`                       | `index-frontend-engine.js`                                 |
| Hardcoded field names            | Only fallback default `sivi_structural`; all data access via config       | `scoreKey`, `coverageKey`, `pillars` from attributes       |
| Widget only supported table view | Dashboard view with map, charts, cards is default                         | `renderDashboardShell()`                                   |
| No historical/year features      | Historical snapshots, year slider, recomputed ranks are fully implemented | `getHistoricalEntry()`, `getRecomputedRank()`, year slider |
| No comparison features           | Up-to-4-country comparison and group comparison are fully implemented     | `toggleCompare()`, `runBlockComparison()`                  |
| No watchlist                     | Watchlist stored in `localStorage` per slug                               | `biw_watchlist_{slug}`                                     |
| No CSV export                    | CSV export of filtered/sorted data is implemented                         | `exportCSV()`                                              |

***


## End of Frontend Specification

Status: CANONICAL
