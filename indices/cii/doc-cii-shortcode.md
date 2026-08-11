# API Reference — `cii-shortcode.php`

> **File:** `src/indices/cii/cii-shortcode.php`  
> **Version:** 2.9.23  
> **Purpose:** Registers the `[cii_index]` shortcode. Renders a single container `<div>` configured for the Blomstra Index Frontend Engine.  
> **Dependencies:** `index-frontend-engine.js` and `index-frontend-styles.css` must be loaded separately (site-wide WPCode snippets).  

---

## Table of Contents

1. [Shortcode](#shortcode)
2. [Function](#function)
3. [Pillar Configuration](#pillar-configuration)
4. [Data Attributes Reference](#data-attributes-reference)
5. [REST Endpoints Consumed](#rest-endpoints-consumed)
6. [Frontend Engine Contract](#frontend-engine-contract)
7. [Usage Examples](#usage-examples)

---

## Shortcode

```
[cii_index]
```

| Attribute | Default | Description |
|---|---|---|
| *(none)* | — | This shortcode accepts no attributes. All configuration is hardcoded in the PHP file. |

**Registered by:** `add_shortcode( 'cii_index', 'cii_render_index_shortcode' );`

---

## Function

### `cii_render_index_shortcode( $atts )`

Renders the CII index widget container. All logic is declarative — the function builds a configuration object and outputs it as `data-biw-*` attributes on a single `<div>`. The frontend engine reads these attributes and handles all rendering, interactivity, and data fetching.

| Param | Type | Description |
|---|---|---|
| `$atts` | `array` | WordPress shortcode attributes array (unused — no attributes defined). |

**Returns:** `string` — HTML markup (container div only).

**Output buffering:** Uses `ob_start()` / `ob_get_clean()` to capture the HTML string for return.

---

## Pillar Configuration

The `$pillars` array defines the three visual bars shown in the country detail panel. Each pillar maps a backend data key to a display label and color.

```php
$pillars = array(
    array(
        'key'     => 'energy_dependency_percentile',
        'raw_key' => 'energy_dependency_raw',
        'label'   => 'Energy Dependency',
        'color'   => '#60a5fa',
    ),
    array(
        'key'     => 'supplier_concentration_percentile',
        'raw_key' => 'supplier_concentration_raw',
        'label'   => 'Supplier Concentration',
        'color'   => '#f87171',
    ),
    array(
        'key'     => 'maritime_vulnerability_percentile',
        'raw_key' => 'maritime_connectivity_raw',
        'label'   => 'Maritime Exposure',
        'color'   => '#fb923c',
    ),
);
```

| Key | Backend Source | Frontend Display |
|---|---|---|
| `energy_dependency_percentile` | EIA raw data → consumption-share-weighted across 5 fuels | Blue bar (`#60a5fa`) |
| `supplier_concentration_percentile` | Comtrade HHI import data | Red bar (`#f87171`) |
| `maritime_vulnerability_percentile` | World Bank LSCI (inverted) | Orange bar (`#fb923c`) |

**Field semantics:**
- `key` — Percentile-rank value (0–100) used for the visual bar width.
- `raw_key` — Raw underlying value displayed as tooltip or detail text.
- `label` — Human-readable pillar name.
- `color` — Hex color for the bar and legend.

---

## Data Attributes Reference

The frontend engine (`index-frontend-engine.js`) initializes by reading these attributes from the container div.

| Attribute | Value | Purpose |
|---|---|---|
| `class` | `biw` | CSS hook for the widget root. Styles scoped via `.biw { ... }` |
| `data-biw-slug` | `cii` | Unique index identifier. Used for localStorage keys, REST route construction, and snapshot DB slug. |
| `data-biw-endpoint` | `/wp-json/blomstra/v1/critical-infrastructure-index` | REST API endpoint that returns the composite dataset (country list with scores, ranks, pillar values). |
| `data-biw-names-endpoint` | `/wp-json/blomstra/v1/country-names` | Shared endpoint for ISO3 → country name mapping (from `global-reference-data.php`). |
| `data-biw-title` | `Critical Infrastructure Vulnerability Index` | Page heading rendered above the widget. |
| `data-biw-subtitle` | *(see code)* | Descriptive subheading below the title. |
| `data-biw-eyebrow` | `Strategic Intelligence` | Small label above the title (category/tag). |
| `data-biw-score-key` | `composite_score` | Object key in the REST response that holds the primary numeric score. |
| `data-biw-score-label` | `Vulnerability Score` | Label shown next to the score in country cards and detail panels. |
| `data-biw-coverage-key` | `coverage_type` | Object key in the REST response indicating `full` or `partial` index coverage. |
| `data-biw-band-thresholds` | `25,50,75` | Percentile cutoffs that split countries into bands. |
| `data-biw-band-labels` | `Low,Medium,High,Extreme` | Human-readable labels for each band (must match threshold count + 1). |
| `data-biw-band-select-label` | `All Vulnerability Levels` | Default option text for the band filter dropdown. |
| `data-biw-pillars` | *(JSON)* | Serialized `$pillars` array. Frontend parses this to render the 3 pillar bars. |
| `data-biw-methodology` | *(HTML string)* | Methodology paragraph shown in the detail panel. Includes links to full methodology page. |

---

## REST Endpoints Consumed

| Endpoint | Method | Source File | Returns |
|---|---|---|---|
| `/wp-json/blomstra/v1/critical-infrastructure-index` | `GET` | `cii-backend.php` | Array of country objects with `composite_score`, `rank`, `coverage_type`, and pillar percentile/raw values. |
| `/wp-json/blomstra/v1/country-names` | `GET` | `global-reference-data.php` | `['USA' => 'United States', ...]` — ISO3 to name mapping. |

**Note:** The frontend engine fetches both endpoints on init. Country names are merged into the composite dataset by ISO3 key.

---

## Frontend Engine Contract

This shortcode does **not** contain any JavaScript or CSS. It relies entirely on the shared frontend engine loaded site-wide.

**Required snippets (loaded separately via WPCode):**

| Snippet | File | Scope |
|---|---|---|
| Index Frontend Styles | `src/frontend/index-frontend-styles.css` | Site-wide |
| Index Frontend Engine | `src/frontend/index-frontend-engine.js` | Site-wide |

**What the engine does with this container:**
1. Queries `[data-biw-slug="cii"]` on `DOMContentLoaded`
2. Fetches `data-biw-endpoint` and `data-biw-names-endpoint` in parallel
3. Merges datasets by ISO3
4. Renders: title, eyebrow, subtitle, search/filter bar, sortable country grid, band distribution chart
5. Clicking a country opens a detail panel showing the 3 pillar bars (from `data-biw-pillars`) and methodology text

**Clash-proofing:** The engine uses event delegation scoped to `.biw` containers and checks `event.target` to avoid conflicts with other site JavaScript.

---

## Usage Examples

### Basic — add to any page or post
```
[cii_index]
```

### In a page template (PHP)
```php
echo do_shortcode( '[cii_index]' );
```

### Multiple indices on one page (future)
If you later add `[hhi_index]` or `[maritime_index]`, each shortcode outputs its own `<div class="biw" data-biw-slug="...">`. The frontend engine initializes all matching containers independently.

---

## Dependencies

| Dependency | File | Relationship |
|---|---|---|
| Frontend Engine | `src/frontend/index-frontend-engine.js` | Required. Reads `data-biw-*` attributes and renders the widget. |
| Frontend Styles | `src/frontend/index-frontend-styles.css` | Required. Provides `.biw` scoped styles. |
| Backend API | `src/indices/cii/cii-backend.php` | Required. Serves the composite dataset at the REST endpoint. |
| Country Names | `src/reference-data/global-reference-data.php` | Required. Serves ISO3 → name mapping via REST. |

---

## Data Flow

```
[cii_index] shortcode
    → renders <div class="biw" data-biw-endpoint="...">
        → frontend engine (JS) loads on page
            → GET /wp-json/blomstra/v1/critical-infrastructure-index
                → cii-backend.php serves composite data
            → GET /wp-json/blomstra/v1/country-names
                → global-reference-data.php serves name map
            → engine merges + renders UI
```
