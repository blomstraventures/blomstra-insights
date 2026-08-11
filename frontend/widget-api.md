# Frontend Widget API

> **Files:** `src/frontend/index-frontend-engine.js`, `src/frontend/index-frontend-styles.css`  
> **Standard:** BMS-1.0.0 (clash-proof event handling required)

---

## Overview

The frontend engine is a generic, config-driven JavaScript module that renders Blomstra index visualizations. One engine powers all indices. Configuration is passed via `data-*` attributes on a container div.

---

## Shortcode API

### SERI

```
[seri_index view="ranking" limit="20" sort="desc" show_partial="true"]
[seri_index view="country" iso3="USA"]
[seri_index view="map"]
[seri_index view="comparison" iso3s="USA,CHN,DEU"]
[seri_index view="sensitivity"]
```

### SIVI

```
[sivi_index view="ranking" limit="20" sort="desc" show_partial="true"]
[sivi_index view="country" iso3="USA"]
[sivi_index view="map"]
```

### Common Attributes

| Attribute | Default | Values | Description |
|---|---|---|---|
| `view` | `"ranking"` | `ranking`, `country`, `map`, `comparison`, `sensitivity` | Visualization type |
| `limit` | `10` | 1–200 | Countries to show (ranking view) |
| `iso3` | `""` | ISO3 code | Target country (country view) |
| `iso3s` | `""` | Comma-separated ISO3s | Comparison list (comparison view) |
| `sort` | `"desc"` | `desc`, `asc` | Sort direction (ranking view) |
| `show_partial` | `"true"` | `true`, `false` | Include partial-index countries |

---

## HTML Output

The shortcode renders:

```html
<div 
    data-blomstra-index="seri"
    data-view="ranking"
    data-limit="20"
    data-endpoint="geo-economic-risk-index"
    data-sort="desc"
    data-show-partial="true"
    class="blomstra-widget-container"
>
    <!-- Widget renders here -->
</div>
```

No inline JavaScript. No global variables.

---

## JavaScript API

### Discovery

```javascript
const widgets = document.querySelectorAll('[data-blomstra-index]');
widgets.forEach(el => new BlomstraWidget(el, {
    index: el.dataset.index,
    view: el.dataset.view,
    limit: parseInt(el.dataset.limit || 10),
    endpoint: el.dataset.endpoint,
    sort: el.dataset.sort,
    showPartial: el.dataset.showPartial === 'true',
}));
```

### Data Fetch

```javascript
fetch(`/wp-json/blomstra/v1/${config.endpoint}`)
    .then(r => r.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        this.render(data);
    })
    .catch(err => {
        this.container.innerHTML = `<div class="blomstra-error">${err.message}</div>`;
    });
```

### Views

#### Ranking View

Renders a sortable table:
- Rank (with partial indicator `*`)
- Country name
- Composite score
- Pillar breakdown (mini bars)
- Coverage badge (full/partial)

**Sortable columns:** Rank, Country, Score, any pillar

#### Country View

Renders a full profile card:
- Score gauge (0–100)
- Coverage badge
- Pillar radar chart
- Data freshness table
- Measurement flags list
- Rank display string

#### Map View

Renders a choropleth map:
- Color scale: low (green) → high (red) vulnerability
- Tooltip on hover: country, score, rank
- Click to navigate to country view

**Requires:** External mapping library (e.g., D3.js, Leaflet) integration

#### Comparison View

Renders side-by-side cards:
- Country A vs Country B vs Country C
- Delta highlighting (green = lower vulnerability, red = higher)
- Pillar score bars aligned vertically

#### Sensitivity View

Renders the scenario comparison table:
- Scenario name
- Country count
- Spearman ρ
- Top mover
- Delete button

**Data source:** Fetches from `sivi_list_scenarios()` or `geri_list_scenarios()` via admin AJAX (not REST API).

---

## CSS Classes

### Container

```css
.blomstra-widget { /* ... */ }
.blomstra-widget-container { /* ... */ }
```

### Ranking Table

```css
.blomstra-ranking-table { }
.blomstra-ranking-table th { }
.blomstra-ranking-table td { }
.blomstra-ranking-row--partial { opacity: 0.8; }
```

### Country Card

```css
.blomstra-country-card { }
.blomstra-score-gauge { }
.blomstra-pillar-radar { }
.blomstra-freshness-table { }
.blomstra-flags-list { }
```

### Coverage Badges

```css
.blomstra-coverage-badge { }
.blomstra-coverage-badge--full { color: #2e7d32; }
.blomstra-coverage-badge--partial { color: #b26a00; }
```

### Pillar Bars

```css
.blomstra-pillar-bar { }
.blomstra-pillar-bar--governance { background: #2271b1; }
.blomstra-pillar-bar--macro { background: #135e96; }
.blomstra-pillar-bar--external { background: #00a0d2; }
.blomstra-pillar-bar--fiscal { background: #f56e28; }
.blomstra-pillar-bar--energy { background: #d63638; }
.blomstra-pillar-bar--hhi { background: #9b51e0; }
.blomstra-pillar-bar--maritime { background: #2e7d32; }
```

---

## Event Handling

All events are namespaced:

```javascript
// Good
el.addEventListener('click.blomstra', handler);

// Bad — will clash
el.onclick = handler;
```

State is per-instance:

```javascript
class BlomstraWidget {
    constructor(container, config) {
        this.container = container;
        this.config = config;
        this.state = {
            data: null,
            sort: config.sort,
            filter: null,
            loading: false,
        };
    }
}
```

---

## Clash-Proof Checklist

- [ ] No `window.Blomstra` global
- [ ] All events use `.blomstra` namespace
- [ ] All CSS classes use `.blomstra-` prefix
- [ ] No `document.write()`
- [ ] No unsanitized `innerHTML` with user input
- [ ] Multiple widgets work on same page
- [ ] Widget works after `DOMContentLoaded`
- [ ] Fetch errors render friendly messages inside widget
- [ ] No prototype pollution

---

## Integration Example

```html
<!-- WordPress page content -->
<h2>Global Risk Ranking</h2>
[seri_index view="ranking" limit="20"]

<h2>United States Profile</h2>
[seri_index view="country" iso3="USA"]

<h2>Infrastructure Vulnerability</h2>
[sivi_index view="ranking" limit="10"]
```

The engine automatically discovers all `[data-blomstra-index]` containers and renders them independently.
