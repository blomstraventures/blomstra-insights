# Frontend Widget Engine — Implementation Guide
> **Tier:** T3 — Implementation
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** `src/frontend/index-frontend-engine.js`, `index-frontend-styles.css`
> **Last updated:** 2026-08-26
> **SSOT For:** Widget initialization, view rendering, delta tracking, watchlist, detail modal, clash-proof patterns
> **Depends on:** `02-contracts.md` (T2 C3 REST response shape, C4 widget config)

---

## 1. Design Philosophy

The frontend engine is **generic** and **config-driven**. One JavaScript file powers all indices (SERI, SIVI, future GPRI) without modification.

**Clash-proof guarantees:**
- No global variables (everything inside IIFE).
- No prototype pollution.
- No `document.getElementById()` — uses scoped query selectors.
- No event listener conflicts — uses namespaced events where possible.
- CSS class names prefixed with `.biw-` (Blomstra Index Widget).

---

## 2. Initialization

```javascript
document.addEventListener('DOMContentLoaded', function() {
    // Auto-detect all widget containers
    document.querySelectorAll('[data-blomstra-index]').forEach(function(container) {
        var config = JSON.parse(container.getAttribute('data-blomstra-config') || '{}');
        new BlomstraWidgetEngine(container, config);
    });
});
```

**WordPress shortcode** injects:
```html
<div data-blomstra-index="sivi"
     data-blomstra-config='{"view":"table","sort":"rank","limit":50}'>
</div>
```

---

## 3. Configuration Options

| Option | Type | Default | Valid Values |
|---|---|---|---|
| `view` | string | `"table"` | `"table"`, `"grid"`, `"map"` |
| `sort` | string | `"rank"` | `"rank"`, `"score"`, `"name"`, `"delta"` |
| `limit` | int | `50` | `1` to `250` |
| `show_delta` | bool | `true` | `true`, `false` |
| `enable_watchlist` | bool | `true` | `true`, `false` |
| `enable_modal` | bool | `true` | `true`, `false` |
| `pillar_breakdown` | bool | `false` | `true`, `false` |
| `band_filter` | string | `null` | `null`, `"critical"`, `"high"`, `"elevated"`, `"moderate"`, `"low"` |

---

## 4. Data Flow

```mermaid
sequenceDiagram
    participant WP as WordPress Shortcode
    participant JS as Widget Engine
    participant API as REST API
    participant LS as localStorage

    WP->>JS: Inject &lt;div data-blomstra-index="sivi"&gt;
    JS->>API: GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index
    API-->>JS: JSON (BMS-1.1.0 shape)
    JS->>JS: Sort / filter / paginate
    JS->>JS: Render table/grid/map
    JS->>LS: Read watchlist
    LS-->>JS: ["USA", "CHN"]
    JS->>JS: Highlight watchlist countries
    User->>JS: Click row
    JS->>API: GET /wp-json/blomstra/v1/index-history/sivi?iso3=USA
    API-->>JS: Historical snapshots
    JS->>JS: Render detail modal with mini-chart & delta
```

---

## 5. Views

### 5.1 Table View

- Sortable columns (rank, score, name, delta).
- Band-based color coding (Critical/High/Elevated/Moderate/Low).
- Delta arrows (▲ ▼ →) with color.
- Watchlist star toggle per row.
- Click row → detail modal.

### 5.2 Grid View

- Card-based layout.
- Country flag + name + score.
- Band color border.
- Quick stats on hover.

### 5.3 Map View (Planned)

- Choropleth world map.
- Band-colored countries.
- Tooltip on hover.

---

## 6. Vulnerability Bands

Bands are computed client-side from the composite score.

| Band | Score Range | Color | CSS Class |
|---|---|---|---|
| Critical | 80–100 | `#8B0000` | `.biw-band-critical` |
| High | 60–79.99 | `#FF4500` | `.biw-band-high` |
| Elevated | 40–59.99 | `#FFA500` | `.biw-band-elevated` |
| Moderate | 20–39.99 | `#FFD700` | `.biw-band-moderate` |
| Low | 0–19.99 | `#32CD32` | `.biw-band-low` |

---

## 7. Delta Tracking

The engine compares current scores against historical snapshots:

```javascript
async function getDelta(iso3, currentScore, slug) {
    const history = await fetch(`/wp-json/blomstra/v1/index-history/${slug}?iso3=${iso3}`);
    const data = await history.json();
    const previous = data[iso3]?.[0];
    if (!previous) return null;
    return currentScore - previous.composite_score;
}
```

**Display:**
- Positive delta (more vulnerable): red ▲
- Negative delta (less vulnerable): green ▼
- No change: gray →

---

## 8. Watchlist

```javascript
class Watchlist {
    constructor(slug) { this.key = `blomstra_watchlist_${slug}`; }
    get() { return JSON.parse(localStorage.getItem(this.key) || '[]'); }
    toggle(iso3) { /* add/remove */ }
    contains(iso3) { return this.get().includes(iso3); }
}
```

- User clicks star icon → country added to watchlist.
- Persisted to `localStorage` key: `blomstra_watchlist_{slug}`.
- Filter mode: "Show only watchlist".
- Watchlist countries highlighted in all views.

---

## 9. Detail Modal

Clicking a country opens a modal with:

- Country name + flag.
- Composite score + rank.
- Coverage type (full/partial).
- Pillar breakdown with progress bars.
- Data quality indicators per pillar.
- Historical mini-chart (if history available).
- Projected rank range (if partial coverage).

---

## 10. Clash-Proof CSS

All classes are prefixed with `.biw-` (Blomstra Index Widget). The engine never uses `#id` selectors — only `.biw-*` classes scoped to the container element.

```css
/* Example: scoped to container */
[data-blomstra-index] .biw-table { ... }
[data-blomstra-index] .biw-row { ... }
[data-blomstra-index] .biw-band-critical { background-color: #8B0000; }
```

---

## 11. Multi-Instance Safety

Multiple widgets per page are fully supported. Each instance is isolated:

```javascript
// Each container gets its own engine instance
containers.forEach(container => {
    new BlomstraWidgetEngine(container, config);
});
```

No shared state, no global event listeners, no ID collisions.
