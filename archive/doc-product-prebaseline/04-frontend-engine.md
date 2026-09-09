# Frontend Engine

> **Applies to:** All indices
> **Standard:** BMS-1.0.0 (clash-proof event handling required)

---

## Philosophy

The frontend engine is **generic and config-driven**. One JavaScript file and one CSS file power all indices. The shortcode passes configuration via `data-*` attributes; the engine reads them and renders the appropriate visualization.

**Critical rule:** The engine must never conflict with other site JavaScript. No global variables. No prototype pollution. All event listeners are namespaced and removable.

---

## Architecture

```
WordPress Shortcode
    -> Injects <div data-index="seri" data-view="ranking">
    -> Loads index-frontend-engine.js (site-wide)
    -> Engine reads data-* attributes
    -> Fetches /wp-json/blomstra/v1/{endpoint}
    -> Renders widget into the div
```

---

## Shortcode API

### SERI Shortcode

```
[seri_index view="ranking" limit="20"]
[seri_index view="country" iso3="USA"]
[seri_index view="map"]
[seri_index view="comparison" iso3s="USA,CHN,DEU"]
```

### SIVI Shortcode

```
[sivi_index view="ranking" limit="20"]
[sivi_index view="country" iso3="USA"]
[sivi_index view="map"]
```

### Common Attributes

| Attribute | Default | Description |
|---|---|---|
| `view` | `"ranking"` | `ranking`, `country`, `map`, `comparison`, `sensitivity` |
| `limit` | `10` | Number of countries to show (ranking view) |
| `iso3` | -- | Target country (country view) |
| `iso3s` | -- | Comma-separated list (comparison view) |
| `sort` | `"desc"` | `desc` (most vulnerable first) or `asc` (least vulnerable first) |
| `show_partial` | `"true"` | Whether to include partial-index countries |

---

## Widget Lifecycle

### 1. Discovery

```javascript
const widgets = document.querySelectorAll('[data-blomstra-index]');
widgets.forEach(el => {
    const config = {
        index: el.dataset.index,      // "seri" or "sivi"
        view: el.dataset.view,        // "ranking", "country", etc.
        limit: parseInt(el.dataset.limit || 10),
        iso3: el.dataset.iso3,
        // ...
    };
    new BlomstraWidget(el, config);
});
```

### 2. Fetch

```javascript
fetch(`/wp-json/blomstra/v1/${config.endpoint}`)
    .then(r => r.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        return data;
    });
```

### 3. Render

The engine switches on `config.view`:

- **ranking**: Sortable table with rank, country, composite score, pillar breakdown, coverage badge
- **country**: Full country profile -- score card, pillar radar chart, data freshness table, measurement flags
- **map**: Choropleth map (requires external mapping library integration)
- **comparison**: Side-by-side country cards with delta highlighting
- **sensitivity**: Embedded scenario comparison table (reads from scenario options)

### 4. Event Handling

All events are namespaced:

```javascript
// Good -- namespaced and removable
el.addEventListener('click.blomstra', handler);

// Bad -- will clash with other handlers
el.onclick = handler;
```

State is stored per-instance, never globally:

```javascript
class BlomstraWidget {
    constructor(container, config) {
        this.container = container;
        this.config = config;
        this.state = { data: null, sort: config.sort, filter: null };
    }
}
```

---

## CSS Architecture

All styles are prefixed with `.blomstra-`:

```css
.blomstra-widget { /* ... */ }
.blomstra-ranking-table { /* ... */ }
.blomstra-country-card { /* ... */ }
.blomstra-pillar-bar { /* ... */ }
.blomstra-coverage-badge--full { color: #2e7d32; }
.blomstra-coverage-badge--partial { color: #b26a00; }
```

No global element selectors (e.g., `table { ... }`) that would override theme styles.

---

## Data Attributes Reference

The shortcode renders a container like this:

```html
<div
    data-blomstra-index="seri"
    data-view="ranking"
    data-limit="20"
    data-endpoint="geo-economic-risk-index"
    data-sort="desc"
    data-show-partial="true"
></div>
```

The engine reads all `data-*` attributes from the container. No inline JavaScript.

---

## Clash-Proof Checklist

When modifying the frontend engine, verify:

- [ ] No `window.Blomstra` or other global namespace pollution
- [ ] All event listeners use `.blomstra` namespace
- [ ] All CSS classes use `.blomstra-` prefix
- [ ] No `document.write()` or `innerHTML` with unsanitized user input
- [ ] Fetch errors are caught and rendered as friendly messages inside the widget
- [ ] Widget works when multiple instances exist on the same page
- [ ] Widget works when loaded after DOMContentLoaded (e.g., via lazy loading)
