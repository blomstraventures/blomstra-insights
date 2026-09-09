# Index Template

> **Standard:** BMS-1.0.0
> **Purpose:** Step-by-step checklist for building a new index

---

## Overview

Creating a new BMS-1.0.0 conformant index requires:
1. Defining pillars, indicators, and data sources
2. Building pillar fetch functions
3. Building the composite builder
4. Adding async/cron infrastructure
5. Building the admin page
6. Adding sensitivity testing
7. Registering REST endpoints
8. Building the frontend shortcode

**Estimated time:** 8-12 hours for a 3-pillar index.

---

## Step 1: Naming & Constants

```php
define( 'NEWI_VERSION', '1.0.0' );
define( 'NEWI_OPTION_KEY', 'newi_composite_index' );
define( 'NEWI_CRON_HOOK', 'newi_weekly_refresh' );
define( 'NEWI_DAILY_CRON_HOOK', 'newi_daily_cron' );
define( 'NEWI_REFRESH_HOOK', 'newi_async_refresh' );
define( 'NEWI_MIN_PILLARS_REQUIRED', 2 );

// Pillar keys
define( 'NEWI_PILLAR_A_KEY', 'newi_pillar_a_data' );
define( 'NEWI_PILLAR_A_META_KEY', 'newi_pillar_a_meta' );
// ... repeat for each pillar
```

**Rules:**
- Prefix: 4-letter acronym (e.g., `seri`, `sivi`, `newi`)
- All functions: `{prefix}_{name}()`
- All options: `{prefix}_{name}`
- REST slug: `/wp-json/blomstra/v1/{kebab-case-name}`

---

## Step 2: Pillar Definitions

```php
function newi_get_pillar_weights() {
    return array(
        'pillar_a' => array(
            'name' => 'Pillar A Name',
            'indicators' => array(
                'indicator_1' => 100,
            ),
            'min_required' => 1,
            'min_weight' => 100,
        ),
        // ...
    );
}

function newi_get_pillar_defs() {
    return array(
        'pillar_a' => array(
            'name' => 'Pillar A Name',
            'indicators' => array(
                'INDICATOR_CODE' => array( 'name' => 'indicator_1', 'source' => 'WB_WDI' ),
            ),
            'min_required' => 1,
            'min_weight' => 100,
        ),
    );
}
```

---

## Step 3: Pillar Fetch Function

```php
function newi_refresh_pillar_a( $source = 'auto' ) {
    $countries = newi_get_global_country_list();
    $iso3_list = array_keys( $countries );

    // Try shared utility first
    if ( $source === 'auto' && function_exists( 'blomstra_fetch_pillar_a_batch' ) ) {
        $data = blomstra_fetch_pillar_a_batch( $iso3_list );
        if ( ! empty( $data ) ) {
            return newi_persist_pillar_a( $iso3_list, $data );
        }
    }

    // Fallback to direct API
    return newi_refresh_pillar_a_fallback( $countries );
}
```

**Persistence must use the two-key structure:**

```php
function newi_persist_pillar_a( $iso3_list, $computed ) {
    $results = array();
    $sources = array();
    foreach ( $iso3_list as $iso3 ) {
        $results[ $iso3 ] = array(
            'value' => $computed[ $iso3 ]['value'] ?? null,
            'year'  => $computed[ $iso3 ]['year'] ?? null,
            'source' => $computed[ $iso3 ]['source'] ?? 'Direct API',
        );
        blomstra_track_source( $sources, $iso3, 'indicator_1', 'SOURCE', 'national', $computed[ $iso3 ]['year'] ?? null );
    }
    update_option( NEWI_PILLAR_A_KEY, array( 'data' => $results, 'sources' => $sources ), false );
    update_option( NEWI_PILLAR_A_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}
```

---

## Step 4: Composite Builder

```php
function newi_build_composite( $force = false, $context = 'manual', $custom_weights = null, $custom_composite_weights = null ) {
    $is_scenario = ( $custom_weights !== null || $custom_composite_weights !== null );

    // Load pillar data
    $pillar_a = get_option( NEWI_PILLAR_A_KEY, array() )['data'] ?? array();
    // ...

    // Compute percentiles
    $pillar_a_pct = ! empty( $pillar_a_raw ) ? blomstra_compute_percentile_ranks_safe( $pillar_a_raw, 0.0 ) : array();

    // Aggregate per country
    foreach ( $all_iso3 as $iso3 ) {
        // ... pillar aggregation logic ...

        // Data quality
        $data_quality = array(
            'pillar_a' => blomstra_pillar_quality_score( $all_sources, $iso3, array( 'indicator_1' ) ),
        );

        // Measurement flags
        $measurement_flags = array(
            'coverage_ratio' => $coverage / $total_pillars,
            'is_definitive' => ( $coverage == $total_pillars ),
            'missing_pillars' => $missing_pillars_list,
        );

        // Rank display (full or partial)
        // ... see SERI/SIVI for implementation ...
    }

    // Scenario-safe save
    if ( ! $is_scenario && $context !== 'scenario' ) {
        update_option( NEWI_OPTION_KEY, $output, false );
    }

    return $output;
}
```

---

## Step 5: Async & Cron

```php
// Per-pillar async hooks
function newi_async_fetch_pillar_a_callback() {
    newi_refresh_pillar_a( 'auto' );
}
add_action( 'newi_async_fetch_pillar_a', 'newi_async_fetch_pillar_a_callback' );

// Master async refresh
function newi_async_refresh_callback() {
    $previous = get_option( NEWI_OPTION_KEY, null );
    newi_refresh_pillar_a( 'auto' );
    // ... other pillars ...
    $result = newi_build_composite( false, 'async' );
    if ( isset( $result['error'] ) && $previous ) {
        // Keep old composite on failure
        update_option( NEWI_OPTION_KEY, $previous, false );
    }
}
add_action( NEWI_REFRESH_HOOK, 'newi_async_refresh_callback' );

// Cron scheduling
add_action( 'init', function () {
    if ( ! wp_next_scheduled( NEWI_DAILY_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'daily', NEWI_DAILY_CRON_HOOK );
    }
});
```

---

## Step 6: Admin Page

Copy the SERI or SIVI admin page structure. Required sections:

1. **Dashboard cards** -- one per pillar + composite
2. **Coverage breakdown** -- full / partial / excluded counts
3. **Freshness bar** -- last fetch time per pillar + composite + cron fire
4. **Pillar data layer table** -- status, Fetch Central, Fetch API Direct, Flush
5. **Composite & build section** -- Build from Cache, Refresh All, Emergency API, Flush All
6. **Sensitivity testing** -- preset buttons, JSON editor, scenario comparison table
7. **Preview tables** -- highest/lowest risk, excluded, raw JSON

---

## Step 7: REST Endpoint

```php
add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/new-index-name', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $data = get_option( NEWI_OPTION_KEY, null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index not built yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );
```

---

## Step 8: Init Validation

```php
function newi_initialize() {
    $validation = blomstra_validate_pillar_thresholds( newi_get_pillar_defs(), newi_get_pillar_weights() );
    if ( ! $validation['valid'] ) {
        foreach ( $validation['mismatches'] as $m ) {
            error_log( 'NEWI Definition Mismatch: ' . $m['issue'] );
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            wp_die( 'NEWI pillar definitions are inconsistent.' );
        }
    }
}
add_action( 'init', 'newi_initialize' );
```

---

## Step 9: Frontend Shortcode

```php
function newi_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'view' => 'ranking',
        'limit' => 10,
        'iso3' => '',
        'sort' => 'desc',
    ), $atts, 'newi_index' );

    $endpoint = 'new-index-name';
    $attrs = array(
        'data-blomstra-index' => 'newi',
        'data-view' => $atts['view'],
        'data-limit' => $atts['limit'],
        'data-endpoint' => $endpoint,
        'data-sort' => $atts['sort'],
    );

    $attr_string = '';
    foreach ( $attrs as $k => $v ) {
        $attr_string .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
    }

    return '<div' . $attr_string . '></div>';
}
add_shortcode( 'newi_index', 'newi_shortcode' );
```

---

## BMS-1.0.0 Conformance Checklist

Before declaring an index complete, verify:

- [ ] All 11 BMS requirements from `00-read-me-first.md` are implemented
- [ ] `blomstra_validate_pillar_thresholds()` passes on init
- [ ] Scenario build does not overwrite live composite
- [ ] Cron auto-rollback triggers correctly (test by breaking an API key)
- [ ] Partial rank ranges are plausible (not all #1)
- [ ] Admin freshness bar updates after each pillar fetch
- [ ] REST endpoint returns valid JSON
- [ ] Legacy endpoint (if any) redirects or returns data
- [ ] Frontend widget renders without JavaScript errors
- [ ] Multiple widgets on same page do not conflict
