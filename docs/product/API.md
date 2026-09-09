# API.md – Blomstra Insights REST API Contract

Document version: 1.0.0\
Status: CANONICAL\
Applies to: All REST endpoints exposed by Blomstra Insights\
Last verified against commit: (to be filled)\
Source files: `sivi-backend.php` (REST registration), `blomstra-index-utilities.php` (history endpoint), `index-frontend-engine.js` (client contract)\
Effective date: 2026-09-09

***


## Document Control

| Field                   | Value                                                                                                                                   |
| :---------------------- | :-------------------------------------------------------------------------------------------------------------------------------------- |
| Document                | `API.md` v1.0.0                                                                                                                         |
| Verified against commit | (to be filled)                                                                                                                          |
| Source files            | `sivi-backend.php` (REST registration), `blomstra-index-utilities.php` (history endpoint), `index-frontend-engine.js` (client contract) |
| Method                  | Exhaustive grep for `register_rest_route` across `src/` (Stage 2) — every registered route is listed below; none omitted.               |
| Status                  | Complete for current code. Items in §8 are open questions, not implementation gaps.                                                     |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## Table of Contents

1. Overview

2. Index Endpoint

3. History Endpoint

4. Country Names Endpoint

5. Legacy Redirects

6. Frontend Configuration Contract

7. Error Responses

8. Open Questions

9. Corrections to Prior Documentation

***


## 1. Overview

\[D] The Blomstra Insights platform exposes REST endpoints for index data, historical snapshots, and country names.

\[N] Exactly three REST routes exist in the active codebase. No hidden or undocumented routes were found. All three are namespaced `blomstra/v1` and are public (`permission_callback: __return_true`) — there is currently no authenticated route anywhere in the plugin.

| Endpoint                                                                | Purpose              | Registered In                  |
| :---------------------------------------------------------------------- | :------------------- | :----------------------------- |
| `GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index` | SIVI index data      | `sivi-backend.php`             |
| `GET /wp-json/blomstra/v1/index-history/{slug}`                         | Historical snapshots | `blomstra-index-utilities.php` |
| `GET /wp-json/blomstra/v1/country-names`                                | Country name map     | `blomstra-index-utilities.php` |

\[N] The API contract is defined by:

1. Backend: PHP REST registration in `sivi-backend.php` and `blomstra-index-utilities.php`

2. Frontend: The widget engine (`index-frontend-engine.js`) consumes these endpoints via `fetch()`

3. Configuration: The shortcode passes endpoint URLs via `data-biw-*` attributes

\[N] Breaking changes to the API shape require:

- A Major version bump (see §16 of the Master Specification)

- Documentation update in the same change set

***


## 2. Index Endpoint

### 2.1 Endpoint URL

text

    GET /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index

\[D] Registered in `sivi-backend.php`:

php

    register_rest_route('blomstra/v1', '/sovereign-infrastructure-vulnerability-index', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $data = get_option(SIVI_OPTION_KEY, null);
            if (!$data) {
                return new WP_Error('no_data', 'Index not built yet.', array('status' => 404));
            }
            header('Cache-Control: public, max-age=3600');
            return $data;
        },
    ));


### 2.2 Response Shape

\[D] Returns the full `sivi_composite_index` option verbatim:

| Field                 | Type   | Required | Description                                    |
| :-------------------- | :----- | :------- | :--------------------------------------------- |
| `version`             | string | Yes      | SIVI methodology version (e.g., "3.3.0")       |
| `last_updated`        | string | Yes      | Build timestamp (UTC, MySQL format)            |
| `total_countries`     | int    | Yes      | Number of countries in `countries` object      |
| `excluded`            | int    | Yes      | Number of countries excluded                   |
| `excluded_detail`     | object | Yes      | Map of `iso3 => reason` for excluded countries |
| `weights`             | object | Yes      | Pillar weights used for this build             |
| `methodology_url`     | string | Yes      | URL to methodology page                        |
| `methodology_summary` | string | Yes      | Brief methodology summary                      |
| `footnote`            | string | No       | Extended methodology note                      |
| `countries`           | object | Yes      | Map of `iso3 => country_data`                  |
| `_meta`               | object | Yes      | Build metadata                                 |


### 2.3 Country Object

\[D] Each entry in `countries` has:

| Field                               | Type         | Required | Description                       |
| :---------------------------------- | :----------- | :------- | :-------------------------------- |
| `iso3`                              | string       | Yes      | 3-letter country code             |
| `name`                              | string       | Yes      | Country name                      |
| `sivi_structural`                   | float        | Yes      | Composite score (0-100)           |
| `coverage`                          | string       | Yes      | `"full"` or `"partial"`           |
| `rank_display`                      | object       | Yes      | Rank information                  |
| `energy_dependency_percentile`      | float        | No       | Energy pillar percentile          |
| `energy_dependency_raw`             | float        | No       | Raw dependency value              |
| `supplier_concentration_percentile` | float        | No       | HHI pillar percentile             |
| `supplier_concentration_raw`        | float        | No       | Raw HHI value (0-10000)           |
| `maritime_vulnerability_percentile` | float        | No       | Maritime vulnerability percentile |
| `maritime_connectivity_raw`         | float        | No       | Raw LSCI value                    |
| `is_landlocked`                     | bool         | No       | Whether country is landlocked     |
| `pillars_used`                      | int          | No       | Number of pillars with data       |
| `pillars_missing`                   | array        | No       | List of missing pillars           |
| `composite_dqi`                     | float\|null  | No       | Data Quality Index                |
| `vintage_summary`                   | string\|null | No       | Data years per pillar             |
| `sensitivity_interval`              | object\|null | No       | Weight-perturbation interval      |
| `pillars`                           | object       | No       | Per-pillar scores and weights     |


### 2.4 Rank Display Object

\[D] `rank_display` has:

| Field              | Type      | Description                                   |
| :----------------- | :-------- | :-------------------------------------------- |
| `is_definitive`    | bool      | `true` for full coverage, `false` for partial |
| `best_estimate`    | int       | Definitive rank or best estimate              |
| `range_80_low`     | int\|null | 80% range low (partial only)                  |
| `range_80_high`    | int\|null | 80% range high (partial only)                 |
| `theoretical_low`  | int\|null | Theoretical low (partial only)                |
| `theoretical_high` | int\|null | Theoretical high (partial only)               |
| `string_format`    | string    | Human-readable rank string                    |

Examples:

Full coverage:

json

    {
        "is_definitive": true,
        "best_estimate": 45,
        "string_format": "#45"
    }

Partial coverage:

json

    {
        "is_definitive": false,
        "best_estimate": 45,
        "range_80_low": 38,
        "range_80_high": 52,
        "theoretical_low": 12,
        "theoretical_high": 89,
        "string_format": "#38-#52*"
    }


### 2.5 Pillars Object

\[D] `pillars` contains per-pillar scores and weights:

json

    {
        "energy": {
            "score": 65.0,
            "weight": 33.3333
        },
        "hhi": {
            "score": 55.0,
            "weight": 33.3333
        },
        "maritime": {
            "score": 70.0,
            "weight": 33.3334
        }
    }


### 2.6 Sensitivity Interval Object

\[D] `sensitivity_interval` (if computed):

json

    {
        "point": 42.35,
        "ci_low": 38.12,
        "ci_high": 46.78
    }

\[N] This is a weight-sensitivity interval, not a statistical confidence interval.


### 2.7 Error Response

\[D] If no index has been built:

json

    {
        "code": "no_data",
        "message": "Index not built yet.",
        "data": {
            "status": 404
        }
    }

\[D] If the endpoint is not registered (backend snippet not active):

json

    {
        "code": "rest_no_route",
        "message": "No route was found matching the URL and request method."
    }


### 2.8 Caching

\[D] Response includes `Cache-Control: public, max-age=3600` – cached for 1 hour.

\[D] The endpoint ignores query parameters entirely — the frontend engine appends `?_=timestamp` which has no effect server-side beyond defeating browser/CDN cache.

***


## 3. History Endpoint

### 3.1 Endpoint URL

text

    GET /wp-json/blomstra/v1/index-history/{slug}

\[D] Registered in `blomstra-index-utilities.php` — index-agnostic, shared across every index.

php

    register_rest_route('blomstra/v1', '/index-history/(?P<slug>[a-z0-9_-]+)', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function ($request) {
            $slug = sanitize_key($request['slug']);
            $iso3 = $request->get_param('iso3');
            return rest_ensure_response(blomstra_index_snapshot_get_history($slug, $iso3));
        },
    ));


### 3.2 Query Parameters

| Parameter | Type   | Required | Description                                        |
| :-------- | :----- | :------- | :------------------------------------------------- |
| `slug`    | string | Yes      | Index identifier (e.g., `sivi`) – part of URL path |
| `iso3`    | string | No       | Filter to a specific country                       |


### 3.3 Response Shape

\[D] Returns an object keyed by ISO3:

json

    {
        "USA": [
            {
                "period": "2024-01",
                "composite_score": 42.35,
                "rank": 45,
                "coverage_type": "full",
                "pillars": {
                    "energy": 65.0,
                    "hhi": 55.0,
                    "maritime": 70.0
                }
            },
            {
                "period": "2023-01",
                "composite_score": 40.12,
                "rank": 48,
                "coverage_type": "full",
                "pillars": {
                    "energy": 62.0,
                    "hhi": 53.0,
                    "maritime": 68.0
                }
            }
        ],
        "DEU": [
            // ...
        ]
    }

\[D] Always keyed by ISO3 at the top level, even when `iso3` is supplied (single-key object, not a flat array) — the frontend's `getHistoricalEntry()` expects and relies on this shape.

\[D] `pillars` is whatever was passed into `blomstra_index_snapshot_save()` minus `composite_score`/`rank`/`coverage_type`, JSON-decoded — its internal shape is therefore index-specific and not fixed by this endpoint.


### 3.4 Response Fields

| Field             | Type        | Description                  |
| :---------------- | :---------- | :--------------------------- |
| `period`          | string      | Snapshot period (YYYY-MM)    |
| `composite_score` | float\|null | Composite score at that time |
| `rank`            | int\|null   | Rank at that time            |
| `coverage_type`   | string      | `"full"` or `"partial"`      |
| `pillars`         | object      | Pillar scores at that time   |


### 3.5 Error Response

\[D] No error path distinct from an empty result — a non-existent slug or a slug with no history simply returns `{}` (empty object), not a 404.

***


## 4. Country Names Endpoint

### 4.1 Endpoint URL

text

    GET /wp-json/blomstra/v1/country-names

\[D] Registered in `blomstra-index-utilities.php`:

php

    register_rest_route('blomstra/v1', '/country-names', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            return rest_ensure_response(blomstra_get_global_country_list());
        },
    ));


### 4.2 Response Shape

\[D] Returns a flat object keyed by ISO3:

json

    {
        "USA": "United States",
        "DEU": "Germany",
        "CHN": "China",
        // ... ~190 countries
    }

\[D] No parameters, no pagination — the full \~190-country list every time.


### 4.3 Error Response

\[D] If the country list is unavailable:

json

    {}

***


## 5. Legacy Redirects

### 5.1 SIVI Legacy Endpoint

\[D] The old CII (Critical Infrastructure Index) endpoint redirects to SIVI:

text

    GET /wp-json/blomstra/v1/critical-infrastructure-index
    → 301 redirect to /wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index

\[D] Registered in `sivi-backend.php` via admin redirect:

php

    add_action('admin_init', function () {
        if (isset($_GET['page']) && $_GET['page'] === 'cii-index') {
            wp_redirect(admin_url('admin.php?page=blomstra-sovereign-infrastructure-vulnerability-index'));
            exit;
        }
    });


### 5.2 SERI Legacy Endpoint (Future)

\[N] When SERI is implemented, legacy GERI endpoints will redirect similarly.

***


## 6. Frontend Configuration Contract

\[N] The frontend engine consumes the API via `data-biw-*` attributes. This is the frontend contract – the bridge between L3 and L4.


### 6.1 Required Attributes

| Attribute               | Purpose                         | Source    |
| :---------------------- | :------------------------------ | :-------- |
| `data-biw-slug`         | Index identifier                | Shortcode |
| `data-biw-endpoint`     | Index endpoint URL              | Shortcode |
| `data-biw-score-key`    | Score field name in response    | Shortcode |
| `data-biw-coverage-key` | Coverage field name in response | Shortcode |


### 6.2 Optional Attributes

| Attribute                   | Purpose                | Default                                     |
| :-------------------------- | :--------------------- | :------------------------------------------ |
| `data-biw-names-endpoint`   | Country names endpoint | `/wp-json/blomstra/v1/country-names`        |
| `data-biw-history-endpoint` | History endpoint       | `/wp-json/blomstra/v1/index-history/{slug}` |
| `data-biw-pillars`          | Pillar configuration   | `[]`                                        |
| `data-biw-view`             | Initial view           | `dashboard`                                 |


### 6.3 Frontend Data Loading

\[D] The frontend engine loads data via `fetch()`:

javascript

    function loadData() {
        Promise.all([
            fetchJSON(endpoint),
            fetchJSON(namesEndpoint).catch(function () { return {}; }),
            fetchJSON(historyEndpoint).catch(function (err) {
                console.warn('History endpoint unavailable:', err.message || err);
                return {};
            })
        ]).then(function (results) {
            var data = results[0];
            state.names = results[1] || {};
            state.history = results[2] || {};
            // ...
        });
    }

\[N] The frontend engine does NOT call L1 or L2 directly – all data enters via these REST endpoints.

***


## 7. Error Responses

### 7.1 HTTP Status Codes

| Code | Meaning               | Scenario                         |
| :--- | :-------------------- | :------------------------------- |
| 200  | Success               | Data returned                    |
| 301  | Moved Permanently     | Legacy endpoint redirect         |
| 400  | Bad Request           | Invalid parameter                |
| 404  | Not Found             | Index not built, route not found |
| 500  | Internal Server Error | Server-side failure              |


### 7.2 Error Shapes

WP\_Error (WordPress REST):

json

    {
        "code": "no_data",
        "message": "Index not built yet.",
        "data": {
            "status": 404
        }
    }

Generic error (PHP):

json

    {
        "error": "No country list available"
    }

***


## 8. Open Questions

\[D] These are API design decisions that require explicit resolution:

| #  | Question                                                                                 | Notes                                           |
| :- | :--------------------------------------------------------------------------------------- | :---------------------------------------------- |
| 1  | Pagination – Should the index endpoint support pagination for large responses?           | Currently returns all countries in one response |
| 2  | Versioning in URL – Should the API version be in the URL (e.g., `/v2/`)?                 | Currently in the response only                  |
| 3  | Filtering – Should the index endpoint support query parameters (e.g., `?coverage=full`)? | Currently client-side filtering only            |
| 4  | Authentication – Should any endpoints require authentication?                            | Currently all public (`__return_true`)          |
| 5  | OpenAPI Specification – Should we provide an OpenAPI/Swagger spec?                       | Not currently available                         |
| 6  | Rate limiting – Should we implement application-layer rate limiting?                     | Currently none at application layer             |

***


## 9. Corrections to Prior Documentation

\[D] The following corrections are made from prior documentation:

| Prior Claim                              | Correction                                                          | Source                               |
| :--------------------------------------- | :------------------------------------------------------------------ | :----------------------------------- |
| There were more than 3 REST endpoints    | Exactly three REST routes exist; exhaustive grep found none omitted | `grep -r "register_rest_route" src/` |
| History endpoint required authentication | All endpoints are public (`__return_true`)                          | REST registration code               |
| SIVI endpoint accepted query parameters  | SIVI endpoint ignores all query parameters                          | REST callback code                   |
| SERI had a live REST endpoint            | SERI is future work; no SERI route is registered                    | Codebase verification                |
| Error responses had different shapes     | All errors follow WP\_Error shape or simple `{error: ...}`          | Error handling code                  |
| Caching was not documented               | `Cache-Control: public, max-age=3600` is set                        | REST callback code                   |

***


## End of API Specification

Status: CANONICAL
