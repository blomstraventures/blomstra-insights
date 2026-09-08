# Index Utilities

> **Applies to:** All index backends
> **Standard:** BMS-1.0.0

---

## Shared Utility Functions (Inside Each Backend)

These functions are duplicated across index backends with the appropriate prefix. They are not part of the shared utilities file because they are index-specific.

---

## Country List Wrappers

### `{prefix}_get_global_country_list()`

```php
function sivi_get_global_country_list() {
    if ( function_exists( 'blomstra_get_global_country_list' ) ) {
        $list = blomstra_get_global_country_list();
        if ( ! empty( $list ) ) {
            return $list;
        }
    }
    return sivi_get_global_country_list_fallback();
}
```

**Pattern:** Try shared utility -> fallback to index-specific direct API query.

---

## Direct API Fallbacks

Every index implements its own direct API fallback functions for when the shared cache is unavailable or empty.

### SERI Direct WB Fetch

```php
function seri_direct_wb_fetch( $code, $source = null, $date_params = null ) {
    $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
    // ... adds source, date params, fetches, parses ...
}
```

### SIVI Direct EIA Fetch

```php
function sivi_eia_fetch_activity_batch_fallback( $country_codes, $activity_id, $product_id, $attempt = 1 ) {
    // EIA-specific batch query with retry logic
}
```

### SIVI Direct Comtrade Fetch

```php
function sivi_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt = 1 ) {
    // Comtrade-specific pagination and quota handling
}
```

**Rule:** Fallback functions must log their own errors and never silently return empty arrays without explanation.

---

## Percentile Computation

### `{prefix}_compute_percentile_ranks()`

**Legacy function.** Kept for backward compatibility but delegates to the shared utility:

```php
function sivi_compute_percentile_ranks( $values_by_iso3 ) {
    if ( function_exists( 'blomstra_compute_percentile_ranks_safe' ) ) {
        return blomstra_compute_percentile_ranks_safe( $values_by_iso3, 0.0 );
    }
    // Fallback implementation
}
```

**New code should call `blomstra_compute_percentile_ranks_safe()` directly.**

---

## Spearman Correlation

### `{prefix}_spearman_correlation( $x, $y )`

Computes Spearman's rank correlation coefficient between two arrays of equal length.

```php
function sivi_spearman_correlation( $x, $y ) {
    $n = count( $x );
    if ( $n < 2 ) { return 0; }

    $rank = function( $arr ) {
        $sorted = $arr;
        sort( $sorted );
        $ranks = array();
        foreach ( $arr as $v ) {
            $ranks[] = array_search( $v, $sorted ) + 1;
        }
        return $ranks;
    };

    $rx = $rank( $x );
    $ry = $rank( $y );

    $d2 = 0;
    for ( $i = 0; $i < $n; $i++ ) {
        $d2 += pow( $rx[$i] - $ry[$i], 2 );
    }
    return 1 - ( ( 6 * $d2 ) / ( $n * ( $n * $n - 1 ) ) );
}
```

**Returns:** float between -1 and 1.

**Used in:** Sensitivity testing scenario comparison table.

---

## Scenario Storage

### `{prefix}_store_scenario( $output, $scenario_id )`

```php
function sivi_store_scenario( $output, $scenario_id ) {
    $key = SIVI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id );
    update_option( $key, $output, false );
}
```

**Storage key pattern:** `{composite_key}_scenario_{sanitized_id}`

### `{prefix}_list_scenarios()`

Queries the database for all scenario options matching the pattern.

```php
function sivi_list_scenarios() {
    global $wpdb;
    $results = array();
    $rows = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sivi_composite_index_scenario_%'" );
    // ... parse and return ...
}
```

### `{prefix}_delete_scenario( $scenario_id )`

```php
function sivi_delete_scenario( $scenario_id ) {
    delete_option( SIVI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id ) );
}
```

---

## Landlocked Check

### `sivi_is_landlocked( $iso3 )`

```php
function sivi_is_landlocked( $iso3 ) {
    if ( function_exists( 'blomstra_is_landlocked' ) ) {
        return blomstra_is_landlocked( $iso3 );
    }
    return in_array( $iso3, SIVI_LANDLOCKED_ISO3_FALLBACK, true );
}
```

**Fallback list:** 44 ISO3 codes covering all UN-OHRLLS LLDCs plus developed landlocked states (AUT, CHE, CZE, etc.).

---

## Init Validation

### `{prefix}_initialize()`

```php
function sivi_initialize() {
    $validation = blomstra_validate_pillar_thresholds( sivi_get_pillar_defs(), sivi_get_pillar_weights() );
    if ( ! $validation['valid'] ) {
        foreach ( $validation['mismatches'] as $m ) {
            error_log( 'SIVI Definition Mismatch: ' . $m['issue'] );
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            wp_die( 'SIVI pillar definitions are inconsistent. Check error log.' );
        }
    }
}
add_action( 'init', 'sivi_initialize' );
```

**Purpose:** Catches definition errors at runtime before they corrupt data.

---

## Admin Page Helper Patterns

### Freshness Display

```php
foreach ( array( 'energy' => $energy_meta, 'hhi' => $hhi_meta, 'maritime' => $maritime_meta ) as $key => $meta ) {
    $last_fetched = $meta['last_fetched'] ?? null;
    if ( $last_fetched ) {
        $diff = time() - strtotime( $last_fetched );
        $days = floor( $diff / DAY_IN_SECONDS );
        $pillar_freshness[$key] = $days == 0 ? 'Today' : ( $days == 1 ? '1 day ago' : $days . ' days ago' );
    } else {
        $pillar_freshness[$key] = 'Never';
    }
}
```

### Dashboard Card HTML

```php
echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin:15px 0;">';
echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Pillar Name</h3></div>';
echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $status . '</p></div></div>';
// ... repeat for each pillar + composite ...
echo '</div>';
```

### Preset Button JavaScript

```php
$preset_js = array();
foreach ( $preset_weights as $key => $weights ) {
    $preset_js[$key] = array( 'pillars' => sivi_get_pillar_weights(), 'composite' => $weights );
}
$preset_json = wp_json_encode( $preset_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
?>
<script>
var siviPresets = <?php echo $preset_json; ?>;
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.preset-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var preset = siviPresets[this.dataset.preset];
            if (preset) {
                document.getElementById('sivi_custom_weights').value = JSON.stringify(preset, null, 4);
                document.getElementById('sivi_scenario_name').value = this.dataset.preset;
            }
        });
    });
});
</script>
```

**Critical:** Use `JSON_HEX_*` flags to prevent XSS when embedding JSON in HTML.
