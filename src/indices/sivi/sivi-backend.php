/**
 * Sovereign Infrastructure Vulnerability Index (SIVI) — v2.8.5
 *
 * @package     Blomstra\Insights\Indices\SIVI
 * @since       1.0.0
 * @version     2.8.5  – Build lock TTL extended to 30 minutes; dependency checks removed
 * @author      Blomstra Insights Team
 * @license     Proprietary
 *
 * ============================================================================
 * CHANGELOG (v2.8.5)
 * ============================================================================
 * - Build lock TTL increased from 5 to 30 minutes (prevents race conditions)
 * - Removed top‑level dependency checks (they caused persistent warnings in Code Snippets)
 *   All necessary fallbacks are already in place.
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// 1.  CONSTANTS
// ============================================================================

define( 'SIVI_VERSION', '2.8.5' );
define( 'SIVI_OPTION_KEY', 'sivi_composite_index' );
define( 'SIVI_STAGING_KEY', SIVI_OPTION_KEY . '_staging' );
define( 'SIVI_MIN_PILLARS_REQUIRED', 2 );
define( 'SIVI_BACKFILL_MIN_YEAR', 2004 ); // Maritime data availability

define( 'SIVI_ENERGY_KEY', 'sivi_energy_data' );
define( 'SIVI_HHI_KEY', 'sivi_hhi_data' );
define( 'SIVI_MARITIME_KEY', 'sivi_maritime_data' );

define( 'SIVI_ENERGY_META_KEY', 'sivi_energy_meta' );
define( 'SIVI_HHI_META_KEY', 'sivi_hhi_meta' );
define( 'SIVI_MARITIME_META_KEY', 'sivi_maritime_meta' );

if ( ! defined( 'SIVI_LOCK_TTL' ) ) {
    // Extended from 5 to 30 minutes to prevent race conditions on slow builds
    define( 'SIVI_LOCK_TTL', 30 * MINUTE_IN_SECONDS );
}

// ─── DQI (Data Quality Index) – Phase 5b ────────────────────────────
define( 'SIVI_MAX_LAG_ENERGY', 3 );    // EIA annual data, typical 1-2 year lag
define( 'SIVI_MAX_LAG_HHI', 3 );       // Comtrade annual data, typical 1-2 year lag
define( 'SIVI_MAX_LAG_MARITIME', 5 );  // LSCI multi-year cadence (last major update 2021)
define( 'SIVI_CURRENT_YEAR', (int) date('Y') );

// ─── Auto‑refresh hook ────────────────────────────────────────────────
define( 'SIVI_AUTO_REFRESH_HOOK', 'sivi_auto_refresh_cron' );


// ============================================================================
// 2.  PILLAR DEFINITIONS & WEIGHTS
// ============================================================================

function sivi_get_pillar_weights() {
    return array(
        'energy'   => array(
            'name'         => 'Energy Dependency',
            'indicators'   => array( 'energy_dependency' => 100 ),
            'min_required' => 1,
            'min_weight'   => 100,
            'winsorize'    => array( 'energy_dependency' => 0.0 ),
        ),
        'hhi'      => array(
            'name'         => 'Supplier Concentration',
            'indicators'   => array( 'supplier_concentration' => 100 ),
            'min_required' => 1,
            'min_weight'   => 100,
            'winsorize'    => array( 'supplier_concentration' => 0.0 ),
        ),
        'maritime' => array(
            'name'         => 'Maritime Connectivity',
            'indicators'   => array( 'maritime_connectivity' => 100 ),
            'min_required' => 1,
            'min_weight'   => 100,
            'winsorize'    => array( 'maritime_connectivity' => 0.01 ),
        ),
    );
}

function sivi_get_pillar_defs() {
    return array(
        'energy'   => array(
            'name'       => 'Energy Dependency',
            'indicators' => array(
                'energy_dependency' => array( 'name' => 'energy_dependency', 'source' => 'EIA' ),
            ),
            'min_required' => 1,
            'min_weight'   => 100,
        ),
        'hhi'      => array(
            'name'       => 'Supplier Concentration',
            'indicators' => array(
                'supplier_concentration' => array( 'name' => 'supplier_concentration', 'source' => 'Comtrade' ),
            ),
            'min_required' => 1,
            'min_weight'   => 100,
        ),
        'maritime' => array(
            'name'       => 'Maritime Connectivity',
            'indicators' => array(
                'maritime_connectivity' => array( 'name' => 'maritime_connectivity', 'source' => 'WB_WDI' ),
            ),
            'min_required' => 1,
            'min_weight'   => 100,
        ),
    );
}

// ─── Supports custom composite weights from options ──────────────────
function sivi_get_composite_weights() {
    // Check for custom weights stored in options
    $custom = get_option( 'sivi_custom_composite_weights', false );
    if ( is_array( $custom ) && isset( $custom['energy'], $custom['hhi'], $custom['maritime'] ) ) {
        $sum = (float) $custom['energy'] + (float) $custom['hhi'] + (float) $custom['maritime'];
        if ( abs( $sum - 100 ) < 0.01 ) {
            return array(
                'energy'   => (float) $custom['energy'],
                'hhi'      => (float) $custom['hhi'],
                'maritime' => (float) $custom['maritime'],
            );
        }
        // If sum invalid, fall back to defaults and delete the invalid option
        delete_option( 'sivi_custom_composite_weights' );
        error_log( 'SIVI: Invalid custom weights sum (' . $sum . '), reset to defaults.' );
    }
    // Default fallback
    return array(
        'energy'   => 33.3333,
        'hhi'      => 33.3333,
        'maritime' => 33.3334,
    );
}


// ============================================================================
// 3.  LANDLOCKED CHECK
// ============================================================================

function sivi_is_landlocked( $iso3 ) {
    if ( function_exists( 'blomstra_is_landlocked' ) ) {
        return blomstra_is_landlocked( $iso3 );
    }
    // Log once if the function is missing (silent fallback to false)
    static $logged = false;
    if ( ! $logged ) {
        error_log( 'SIVI: blomstra_is_landlocked() not available – falling back to false for all countries.' );
        $logged = true;
    }
    return false;
}


// ============================================================================
// 4.  COUNTRY LIST WRAPPERS
// ============================================================================

function sivi_get_global_country_list() {
    if ( function_exists( 'blomstra_get_global_country_list' ) ) {
        $list = blomstra_get_global_country_list();
        if ( ! empty( $list ) ) {
            return $list;
        }
    }
    return sivi_get_global_country_list_fallback();
}

function sivi_get_global_country_list_fallback() {
    $names = array();
    $page  = 1;
    do {
        $url  = "https://api.worldbank.org/v2/country?format=json&per_page=300&page={$page}";
        $resp = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $resp ) ) {
            break;
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
            break;
        }
        foreach ( $body[1] as $entry ) {
            $region_id = $entry['region']['id'] ?? 'NA';
            if ( $region_id !== 'NA' ) {
                $iso3 = $entry['id'];
                $name = $entry['name'];
                if ( $iso3 && $name ) {
                    $names[ $iso3 ] = $name;
                }
            }
        }
        $pages_total = $body[0]['pages'] ?? 1;
        $page++;
    } while ( $page <= $pages_total );
    return $names;
}


// ============================================================================
// 5.  ENERGY PILLAR – PURE CONSUMER (with year tracking)
// ============================================================================

/**
 * Aggregates energy dependency from consumption and production data.
 * Also extracts the year of the consumption data for DQI.
 *
 * @param array $iso3_list              List of ISO3 codes.
 * @param array $consumption_by_fuel    Multi-dimensional: fuel_id => iso3 => { value, year, status }.
 * @param array $production_by_fuel     Multi-dimensional: fuel_id => iso3 => { value, status }.
 * @return array Computed energy dependency per country with year.
 */
function sivi_eia_aggregate_energy_dependency( $iso3_list, $consumption_by_fuel, $production_by_fuel ) {
    $fuel_ids = array(
        '4411' => 'Coal',
        '4413' => 'Natural gas',
        '4415' => 'Petroleum and other liquids',
        '4417' => 'Nuclear',
        '4418' => 'Renewables and other',
    );
    $out = array();

    foreach ( $iso3_list as $iso3 ) {
        $fuels = array();
        $representative_year = null;

        foreach ( $fuel_ids as $product_id => $fuel_name ) {
            // ─── Consumption ──────────────────────────────────────────
            $cons_entry = $consumption_by_fuel[ $product_id ][ $iso3 ] ?? null;
            $consumption = null;
            $year = null;

            if ( is_array( $cons_entry ) ) {
                // New structure: ['value' => float, 'year' => int, 'status' => string]
                $consumption = isset( $cons_entry['value'] ) && is_numeric( $cons_entry['value'] ) ? (float) $cons_entry['value'] : null;
                $year = isset( $cons_entry['year'] ) ? (int) $cons_entry['year'] : null;
            } elseif ( is_numeric( $cons_entry ) ) {
                // Legacy fallback (if data is still stored as pure number)
                $consumption = (float) $cons_entry;
                $year = null;
            }

            if ( $consumption === null || $consumption == 0 ) {
                continue;
            }

            // ─── Production ──────────────────────────────────────────
            $prod_entry = $production_by_fuel[ $product_id ][ $iso3 ] ?? null;
            $production = null;
            $prod_note = 'real production value';
            if ( is_array( $prod_entry ) ) {
                $production = isset( $prod_entry['value'] ) && is_numeric( $prod_entry['value'] ) ? (float) $prod_entry['value'] : null;
                if ( isset( $prod_entry['status'] ) && $prod_entry['status'] === 'confirmed_zero' ) {
                    $prod_note = 'confirmed zero production';
                }
            } elseif ( is_numeric( $prod_entry ) ) {
                $production = (float) $prod_entry;
            } else {
                continue;
            }

            if ( $production === null ) {
                continue;
            }

            $fuel_dep = ( ( $consumption - $production ) / $consumption ) * 100;

            $fuels[ $product_id ] = array(
                'name'        => $fuel_name,
                'consumption' => $consumption,
                'production'  => $production,
                'dependency'  => round( $fuel_dep, 2 ),
                'note'        => $prod_note,
                'year'        => $year,  // ← store per-fuel year
            );

            // Track the most recent year among fuels
            if ( $year !== null && ( $representative_year === null || $year > $representative_year ) ) {
                $representative_year = $year;
            }
        }

        if ( empty( $fuels ) ) {
            $out[ $iso3 ] = array(
                'value' => null,
                'source' => 'EIA',
                'note' => 'No fuel had usable consumption data',
                'fuels' => array(),
                'year' => null,
            );
            continue;
        }

        $total_consumption = 0;
        foreach ( $fuels as $f ) {
            $total_consumption += $f['consumption'];
        }
        if ( $total_consumption <= 0 ) {
            $out[ $iso3 ] = array(
                'value' => null,
                'source' => 'EIA',
                'note' => 'Total consumption across fuels is zero',
                'fuels' => $fuels,
                'year' => $representative_year,
            );
            continue;
        }

        // Weighted dependency
        $weighted_sum = 0;
        $fuel_names_used = array();
        foreach ( $fuels as $pid => $f ) {
            $weight = $f['consumption'] / $total_consumption;
            $weighted_sum += $f['dependency'] * $weight;
            $fuel_names_used[] = $f['name'];
        }

        $out[ $iso3 ] = array(
            'value'  => round( $weighted_sum, 2 ),
            'source' => 'EIA (multi-fuel, consumption-weighted)',
            'note'   => count( $fuels ) . '/' . count( $fuel_ids ) . ' fuels had usable data',
            'fuels'  => $fuels,
            'year'   => $representative_year,   // ← now passed to persistence
        );
    }
    return $out;
}

function sivi_persist_energy_results( $iso3_list, $computed ) {
    $results = array();
    $sources = array();
    foreach ( $iso3_list as $iso3 ) {
        $c = $computed[ $iso3 ] ?? array( 'value' => null, 'source' => 'EIA', 'note' => 'not returned', 'year' => null );
        $results[ $iso3 ] = array(
            'value'        => $c['value'],
            'source'       => $c['source'],
            'note'         => $c['note'] ?? '',
            'data_year'    => $c['year'] ?? null,  // ← stored for DQI
            'last_updated' => current_time( 'mysql' ),
        );
        if ( function_exists( 'blomstra_track_source' ) ) {
            blomstra_track_source( $sources, $iso3, 'energy_dependency', 'EIA', 'national', $c['year'] ?? null );
        }
        set_transient( 'sivi_energy_' . $iso3, $results[ $iso3 ], 12 * HOUR_IN_SECONDS );
    }
    $existing = get_option( SIVI_ENERGY_KEY, array() );
    $merged = array_merge( $existing['data'] ?? array(), $results );
    $merged_sources = array_merge( $existing['sources'] ?? array(), $sources );
    update_option( SIVI_ENERGY_KEY, array( 'data' => $merged, 'sources' => $merged_sources ), false );
    update_option( SIVI_ENERGY_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}

function sivi_refresh_energy_pillar() {
    if ( ! function_exists( 'blomstra_get_eia_raw_data' ) ) {
        return array( 'error' => 'Central model not active – missing blomstra_get_eia_raw_data().' );
    }
    $raw = blomstra_get_eia_raw_data();
    if ( empty( $raw['consumption'] ) && empty( $raw['production'] ) ) {
        return array( 'error' => 'EIA central cache is empty. Please trigger a refresh from the Reference Data > Energy dashboard.' );
    }
    $countries = sivi_get_global_country_list();
    $iso3_list = array_keys( $countries );
    $computed = sivi_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
    return sivi_persist_energy_results( $iso3_list, $computed );
}


// ============================================================================
// 6.  MARITIME PILLAR – PURE CONSUMER
// ============================================================================

function sivi_refresh_maritime_pillar() {
    if ( ! function_exists( 'blomstra_get_maritime_raw' ) ) {
        return array( 'error' => 'Central model not active – missing blomstra_get_maritime_raw().' );
    }
    $raw = blomstra_get_maritime_raw();
    if ( empty( $raw ) ) {
        return array( 'error' => 'Maritime central cache is empty. Please trigger a refresh from the Reference Data > Maritime dashboard.' );
    }
    $results = array();
    $sources = array();
    $all_countries = sivi_get_global_country_list();
    foreach ( $all_countries as $iso3 => $name ) {
        if ( isset( $raw[ $iso3 ] ) ) {
            $results[ $iso3 ] = array(
                'value'        => $raw[ $iso3 ]['value'],
                'year'         => $raw[ $iso3 ]['year'],
                'source'       => 'World Bank WDI (IS.SHP.GCNW.XQ)',
                'last_updated' => current_time( 'mysql' ),
            );
            if ( function_exists( 'blomstra_track_source' ) ) {
                blomstra_track_source( $sources, $iso3, 'maritime_connectivity', 'WB_WDI', 'national', $raw[ $iso3 ]['year'] );
            }
        } elseif ( sivi_is_landlocked( $iso3 ) ) {
            $results[ $iso3 ] = array(
                'value'        => 0.0,
                'year'         => null,
                'source'       => 'Structural zero — landlocked',
                'last_updated' => current_time( 'mysql' ),
            );
            if ( function_exists( 'blomstra_track_source' ) ) {
                blomstra_track_source( $sources, $iso3, 'maritime_connectivity', 'structural_zero', 'national', null );
            }
        } else {
            $results[ $iso3 ] = array(
                'value'        => null,
                'year'         => null,
                'source'       => 'World Bank WDI',
                'last_updated' => current_time( 'mysql' ),
            );
        }
        set_transient( 'sivi_maritime_' . $iso3, $results[ $iso3 ], 7 * DAY_IN_SECONDS );
    }
    update_option( SIVI_MARITIME_KEY, array( 'data' => $results, 'sources' => $sources ), false );
    update_option( SIVI_MARITIME_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}


// ============================================================================
// 7.  HHI PILLAR – PURE CONSUMER
// ============================================================================

function sivi_merge_hhi_into_pillar( $iso3_list ) {
    $central_data = function_exists( 'blomstra_get_comtrade_hhi_data' ) ? blomstra_get_comtrade_hhi_data() : array();
    $results = array();
    $sources = array();
    foreach ( $iso3_list as $iso3 ) {
        if ( isset( $central_data[ $iso3 ] ) ) {
            $results[ $iso3 ] = $central_data[ $iso3 ];
            if ( function_exists( 'blomstra_track_source' ) ) {
                blomstra_track_source( $sources, $iso3, 'supplier_concentration', 'Comtrade', 'national', $central_data[ $iso3 ]['year'] ?? null );
            }
        }
    }
    $existing = get_option( SIVI_HHI_KEY, array() );
    $merged = array_merge( $existing['data'] ?? array(), $results );
    $merged_sources = array_merge( $existing['sources'] ?? array(), $sources );
    update_option( SIVI_HHI_KEY, array( 'data' => $merged, 'sources' => $merged_sources ), false );
    update_option( SIVI_HHI_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}

function sivi_refresh_hhi_pillar() {
    if ( ! function_exists( 'blomstra_get_comtrade_hhi_data' ) ) {
        return array( 'error' => 'Central model not active – missing blomstra_get_comtrade_hhi_data().' );
    }
    $central = blomstra_get_comtrade_hhi_data();
    if ( empty( $central ) ) {
        return array( 'error' => 'HHI central cache is empty. Please trigger a refresh from the Reference Data > HHI dashboard.' );
    }
    $iso3_list = array_keys( sivi_get_global_country_list() );
    return sivi_merge_hhi_into_pillar( $iso3_list );
}


// ============================================================================
// 8.  PERCENTILE COMPUTATION
// ============================================================================

function sivi_compute_percentile_ranks( $values_by_iso3, $winsor_pct = 0.0 ) {
    if ( function_exists( 'blomstra_compute_percentile_ranks_safe' ) ) {
        return blomstra_compute_percentile_ranks_safe( $values_by_iso3, $winsor_pct );
    }
    // Minimal fallback (should not happen if utilities are loaded)
    $n = count( $values_by_iso3 );
    if ( $n === 0 ) { return array(); }
    if ( $n === 1 ) {
        $only = array_keys( $values_by_iso3 );
        return array( $only[0] => 50.0 );
    }
    $pairs = $values_by_iso3;
    asort( $pairs );
    $sorted_values = array_values( $pairs );
    $sorted_iso3   = array_keys( $pairs );
    $ranks = array();
    $i = 0;
    while ( $i < $n ) {
        $j = $i;
        while ( $j < $n - 1 && abs( $sorted_values[ $j + 1 ] - $sorted_values[ $i ] ) < 0.0001 ) {
            $j++;
        }
        $avg_rank = ( ( $i + 1 ) + ( $j + 1 ) ) / 2;
        for ( $k = $i; $k <= $j; $k++ ) {
            $ranks[ $sorted_iso3[ $k ] ] = $avg_rank;
        }
        $i = $j + 1;
    }
    $percentiles = array();
    foreach ( $ranks as $iso3 => $rank ) {
        $percentiles[ $iso3 ] = round( ( ( $rank - 0.5 ) / $n ) * 100, 2 );
    }
    return $percentiles;
}


// ============================================================================
// 9.  UPSTREAM HEALTH CHECK
// ============================================================================

function sivi_check_upstream_health() {
    $warnings = array();
    
    // HHI pointer check
    if ( function_exists( 'blomstra_get_hhi_pointer' ) ) {
        $hhi_pointer = blomstra_get_hhi_pointer();
        if ( ! empty( $hhi_pointer['pending_iso3s'] ) ) {
            $warnings[] = 'HHI data is still being fetched (' . count( $hhi_pointer['pending_iso3s'] ) . ' countries pending).';
        }
    }
    
    // EIA pointer check
    if ( function_exists( 'blomstra_get_eia_pointer' ) ) {
        $eia_pointer = blomstra_get_eia_pointer();
        $fuel_ids = defined( 'BLOMSTRA_EIA_FUEL_PRODUCT_IDS' ) ? array_keys( BLOMSTRA_EIA_FUEL_PRODUCT_IDS ) : array();
        if ( isset( $eia_pointer['fuel_index'] ) && ! empty( $fuel_ids ) && $eia_pointer['fuel_index'] < count( $fuel_ids ) ) {
            $warnings[] = 'EIA data is still being fetched (fuel ' . ( $eia_pointer['fuel_index'] + 1 ) . ' of ' . count( $fuel_ids ) . ').';
        }
    }
    
    return $warnings;
}


// ============================================================================
// 10. COMPOSITE BUILDER (with sensitivity, freshness, benchmark, alerts, DQI)
// ============================================================================

function sivi_build_composite( $context = 'manual', $custom_weights = null, $custom_composite_weights = null ) {
    $is_scenario = ( $custom_weights !== null || $custom_composite_weights !== null );
    $lock_key = 'sivi_build_lock';

    // ─── Acquire lock ──────────────────────────────────────────────
    $lock = get_transient( $lock_key );
    if ( $lock !== false && ( time() - (int)$lock ) < SIVI_LOCK_TTL ) {
        return array( 'error' => 'Build already in progress. Please wait.' );
    }
    set_transient( $lock_key, time(), SIVI_LOCK_TTL );

    // ─── OOM protection ────────────────────────────────────────────
    $lock_cleared = false;
    $shutdown = function() use ( $lock_key, &$lock_cleared ) {
        if ( ! $lock_cleared ) {
            delete_transient( $lock_key );
            error_log( 'SIVI: Build lock cleared by shutdown handler (OOM or fatal).' );
        }
    };
    register_shutdown_function( $shutdown );

    try {
        if ( function_exists( 'blomstra_update_cron_status' ) ) {
            blomstra_update_cron_status( 'sivi', 'running', 'SIVI composite build started.' );
        }

        // ─── Load pillar data ──────────────────────────────────────
        $energy_store   = get_option( SIVI_ENERGY_KEY, array() );
        $hhi_store      = get_option( SIVI_HHI_KEY, array() );
        $maritime_store = get_option( SIVI_MARITIME_KEY, array() );

        $energy_data    = $energy_store['data'] ?? array();
        $hhi_data       = $hhi_store['data'] ?? array();
        $maritime_data  = $maritime_store['data'] ?? array();

        $energy_sources    = $energy_store['sources'] ?? array();
        $hhi_sources       = $hhi_store['sources'] ?? array();
        $maritime_sources  = $maritime_store['sources'] ?? array();
        $all_sources = array_merge_recursive( $energy_sources, $hhi_sources, $maritime_sources );

        $countries = sivi_get_global_country_list();
        if ( empty( $countries ) ) {
            throw new Exception( 'No country list available.' );
        }
        $all_iso3 = array_keys( $countries );
        $all_pillars = array( 'energy', 'hhi', 'maritime' );

        // ─── Extract raw values ──────────────────────────────────
        $energy_raw_values = array();
        $hhi_raw_values = array();
        $maritime_raw_values = array();

        foreach ( $energy_data as $iso3 => $row ) {
            if ( isset( $row['value'] ) && is_numeric( $row['value'] ) ) {
                $energy_raw_values[ $iso3 ] = (float) $row['value'];
            }
        }
        foreach ( $hhi_data as $iso3 => $row ) {
            if ( isset( $row['value'] ) && is_numeric( $row['value'] ) ) {
                $hhi_raw_values[ $iso3 ] = (float) $row['value'];
            }
        }
        foreach ( $maritime_data as $iso3 => $row ) {
            if ( isset( $row['value'] ) && is_numeric( $row['value'] ) ) {
                $maritime_raw_values[ $iso3 ] = (float) $row['value'];
            }
        }

        // ─── Compute percentiles ──────────────────────────────────
        $weight_defs = $custom_weights ?? sivi_get_pillar_weights();
        $energy_winsor   = $weight_defs['energy']['winsorize']['energy_dependency'] ?? 0.0;
        $hhi_winsor      = $weight_defs['hhi']['winsorize']['supplier_concentration'] ?? 0.0;
        $maritime_winsor = $weight_defs['maritime']['winsorize']['maritime_connectivity'] ?? 0.0;

        $energy_pct = ! empty( $energy_raw_values ) ? sivi_compute_percentile_ranks( $energy_raw_values, $energy_winsor ) : array();
        $hhi_pct    = ! empty( $hhi_raw_values ) ? sivi_compute_percentile_ranks( $hhi_raw_values, $hhi_winsor ) : array();
        $maritime_connectivity_pct  = ! empty( $maritime_raw_values ) ? sivi_compute_percentile_ranks( $maritime_raw_values, $maritime_winsor ) : array();
        $maritime_vulnerability_pct = array();
        foreach ( $maritime_connectivity_pct as $iso3 => $pct ) {
            $maritime_vulnerability_pct[ $iso3 ] = round( 100 - $pct, 2 );
        }

        // ─── Get composite weights (may be custom) ────────────────
        $composite_weights = $custom_composite_weights ?? sivi_get_composite_weights();

        // ─── Build results ────────────────────────────────────────
        $results = array();
        $excluded = array();

        foreach ( $all_iso3 as $iso3 ) {
            $present = array();
            if ( isset( $energy_pct[ $iso3 ] ) ) {
                $present['energy'] = array( 'value' => $energy_pct[ $iso3 ], 'weight' => $composite_weights['energy'] );
            }
            if ( isset( $hhi_pct[ $iso3 ] ) ) {
                $present['hhi'] = array( 'value' => $hhi_pct[ $iso3 ], 'weight' => $composite_weights['hhi'] );
            }
            if ( isset( $maritime_vulnerability_pct[ $iso3 ] ) ) {
                $present['maritime'] = array( 'value' => $maritime_vulnerability_pct[ $iso3 ], 'weight' => $composite_weights['maritime'] );
            }

            $pillars_present = count( $present );
            $missing_pillars = array_values( array_diff( $all_pillars, array_keys( $present ) ) );

            if ( $pillars_present < SIVI_MIN_PILLARS_REQUIRED ) {
                $excluded[ $iso3 ] = array(
                    'reason'          => 'Fewer than ' . SIVI_MIN_PILLARS_REQUIRED . ' pillars have real data — not scored.',
                    'pillars_present' => $pillars_present,
                    'pillars_missing' => $missing_pillars,
                    'name'            => $countries[ $iso3 ] ?? $iso3,
                );
                continue;
            }

            $score_sum  = 0;
            $weight_sum = 0;
            foreach ( $present as $pillar ) {
                $score_sum  += $pillar['value'] * $pillar['weight'];
                $weight_sum += $pillar['weight'];
            }
            $composite_score = round( $score_sum / $weight_sum, 1 );
            $coverage_type = ( $pillars_present >= count( $all_pillars ) ) ? 'full' : 'partial';

            $results[ $iso3 ] = array(
                'composite_score' => $composite_score,
                'coverage_type'   => $coverage_type,
                'energy_dependency_percentile'       => isset( $present['energy'] ) ? $present['energy']['value'] : null,
                'energy_dependency_raw'              => $energy_raw_values[ $iso3 ] ?? null,
                'supplier_concentration_percentile'  => isset( $present['hhi'] ) ? $present['hhi']['value'] : null,
                'supplier_concentration_raw'         => $hhi_raw_values[ $iso3 ] ?? null,
                'maritime_connectivity_percentile'   => isset( $maritime_connectivity_pct[ $iso3 ] ) ? $maritime_connectivity_pct[ $iso3 ] : null,
                'maritime_vulnerability_percentile'  => isset( $present['maritime'] ) ? $present['maritime']['value'] : null,
                'maritime_connectivity_raw'          => $maritime_raw_values[ $iso3 ] ?? null,
                'is_landlocked'                       => sivi_is_landlocked( $iso3 ),
                'pillars_used'    => $pillars_present,
                'pillars_missing' => $missing_pillars,
                'last_updated'    => current_time( 'mysql' ),
            );
        }

        // ─── Rank assignment ──────────────────────────────────────
        $full_composites_sorted = array();
        foreach ( $results as $iso3 => $row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $full_composites_sorted[] = $row['composite_score'];
            }
        }

        $has_display_fns = function_exists( 'blomstra_build_full_rank_display' );
        $has_partial_display_fns = function_exists( 'blomstra_project_partial_rank_composite' )
            && function_exists( 'blomstra_build_partial_rank_display' );

        foreach ( $results as $iso3 => &$row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $r = 1;
                foreach ( $full_composites_sorted as $full_score ) {
                    if ( $row['composite_score'] < $full_score ) {
                        $r++;
                    }
                }
                $row['rank'] = $r;
                $row['rank_display'] = $has_display_fns
                    ? blomstra_build_full_rank_display( $r )
                    : array(
                        'is_definitive'    => true,
                        'best_estimate'    => $r,
                        'range_80_low'     => $r,
                        'range_80_high'    => $r,
                        'theoretical_low'  => $r,
                        'theoretical_high' => $r,
                        'string_format'    => '#' . $r,
                    );
            }
        }
        unset( $row );

        // ─── Partial ranks ─────────────────────────────────────────
        $pillar_weight_by_name = $composite_weights;
        $pillar_value_key = array(
            'energy'   => 'energy_dependency_percentile',
            'hhi'      => 'supplier_concentration_percentile',
            'maritime' => 'maritime_vulnerability_percentile',
        );

        // This relies on SIVI_MIN_PILLARS_REQUIRED = 2 (out of 3), so partial always means exactly one pillar missing.
        // If MIN_PILLARS_REQUIRED changes, this logic must be revisited.
        foreach ( $results as $iso3 => &$row ) {
            if ( $row['coverage_type'] !== 'partial' ) {
                continue;
            }
            $missing_pillar = $row['pillars_missing'][0] ?? null;
            if ( $missing_pillar === null || ! isset( $pillar_weight_by_name[ $missing_pillar ] ) ) {
                continue;
            }

            $known_pillars = array();
            foreach ( $pillar_weight_by_name as $pname => $pweight ) {
                if ( $pname === $missing_pillar ) {
                    continue;
                }
                $known_pillars[ $pname ] = $row[ $pillar_value_key[ $pname ] ] ?? 0;
            }

            $injected_values_by_point = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $point ) {
                $injected_values_by_point[ $point ] = $point;
            }

            if ( ! $has_partial_display_fns ) {
                continue;
            }

            $hypothetical_composites = blomstra_project_partial_rank_composite(
                $known_pillars,
                $missing_pillar,
                $injected_values_by_point,
                $pillar_weight_by_name
            );
            if ( empty( $hypothetical_composites ) ) {
                continue;
            }

            $ranks_by_injection = array();
            foreach ( $hypothetical_composites as $point => $hyp_composite ) {
                $rank = 1;
                foreach ( $full_composites_sorted as $full_score ) {
                    if ( $hyp_composite < $full_score ) {
                        $rank++;
                    }
                }
                $ranks_by_injection[ $point ] = $rank;
            }

            $row['rank'] = null;
            $row['rank_display'] = blomstra_build_partial_rank_display( $ranks_by_injection );
        }
        unset( $row );

        // ─── Data Quality & Measurement Flags ────────────────────
        $country_output = array();
        foreach ( $results as $iso3 => $row ) {
            $coverage = ( $row['coverage_type'] === 'full' ) ? count($all_pillars) : (count($all_pillars) - 1);
            $missing_pillars_list = $row['pillars_missing'];

            $data_quality = array();
            foreach ( array( 'energy' => 'energy_dependency', 'hhi' => 'supplier_concentration', 'maritime' => 'maritime_connectivity' ) as $pillar => $indicator ) {
                if ( function_exists( 'blomstra_pillar_quality_score' ) ) {
                    $data_quality[ $pillar ] = blomstra_pillar_quality_score( $all_sources, $iso3, array( $indicator ) );
                } else {
                    $data_quality[ $pillar ] = null;
                }
            }

            $measurement_flags = array(
                'is_landlocked' => sivi_is_landlocked( $iso3 ),
                'maritime_is_structural_zero' => sivi_is_landlocked( $iso3 ),
                'coverage_ratio' => $coverage / count($all_pillars),
                'is_definitive' => ( $coverage == count($all_pillars) ),
                'missing_pillars' => $missing_pillars_list,
            );

            $country_output[ $iso3 ] = array(
                'iso3' => $iso3,
                'name' => $countries[ $iso3 ] ?? $iso3,
                'sivi_structural' => $row['composite_score'],
                'coverage' => $row['coverage_type'],
                'pillars_missing' => $missing_pillars_list,
                'data_quality' => $data_quality,
                'measurement_flags' => $measurement_flags,
                'rank_display' => $row['rank_display'] ?? null,
                'energy_dependency_percentile'       => $row['energy_dependency_percentile'],
                'energy_dependency_raw'              => $row['energy_dependency_raw'],
                'supplier_concentration_percentile'  => $row['supplier_concentration_percentile'],
                'supplier_concentration_raw'         => $row['supplier_concentration_raw'],
                'maritime_connectivity_percentile'   => $row['maritime_connectivity_percentile'],
                'maritime_vulnerability_percentile'  => $row['maritime_vulnerability_percentile'],
                'maritime_connectivity_raw'          => $row['maritime_connectivity_raw'],
                'is_landlocked'                       => $row['is_landlocked'],
                'pillars_used'    => $row['pillars_used'],
                'pillars_missing' => $row['pillars_missing'],
                'last_updated'    => $row['last_updated'],
                'pillars' => array(
                    'energy'   => array( 'score' => $row['energy_dependency_percentile'], 'weight' => $composite_weights['energy'] ?? 33.3333 ),
                    'hhi'      => array( 'score' => $row['supplier_concentration_percentile'], 'weight' => $composite_weights['hhi'] ?? 33.3333 ),
                    'maritime' => array( 'score' => $row['maritime_vulnerability_percentile'], 'weight' => $composite_weights['maritime'] ?? 33.3334 ),
                ),
            );
        }

        // ─── Assemble output ──────────────────────────────────────
        $output = array(
            'version'         => SIVI_VERSION,
            'last_updated'    => current_time( 'mysql' ),
            'total_countries' => count( $country_output ),
            'excluded'        => count( $excluded ),
            'excluded_detail' => $excluded,
            'methodology_url'     => 'https://blomstrainsights.com/methodology/sivi',
            'methodology_summary' => 'Percentile-rank composite (Energy dependency, HHI supplier concentration, inverted Maritime connectivity).',
            'footnote'        => 'Partial ranks are projections...',
            'weights' => $composite_weights,
            '_meta' => array(
                'built_at'            => current_time( 'mysql' ),
                'source'              => $context,
                'status'              => 'valid',
                'standard_version'    => 'BMS-1.1.0',
                'methodology_version' => SIVI_VERSION,
                'software_version'    => SIVI_VERSION,
                'dqi_config' => array(
                    'energy_max_lag'   => SIVI_MAX_LAG_ENERGY,
                    'hhi_max_lag'      => SIVI_MAX_LAG_HHI,
                    'maritime_max_lag' => SIVI_MAX_LAG_MARITIME,
                    'reference_year'   => SIVI_CURRENT_YEAR,
                ),
            ),
            'countries' => $country_output,
        );

        // ─── Upstream warnings (FIX: clear stale warnings) ──────
        $warnings = sivi_check_upstream_health();
        if ( ! empty( $warnings ) ) {
            $output['_meta']['upstream_warnings'] = $warnings;
        } else {
            // Remove any stale upstream warnings from previous builds
            unset( $output['_meta']['upstream_warnings'] );
        }

        // ─── Weight‑sensitivity interval ───────────────────────────
        if ( function_exists( 'blomstra_bootstrap_ci' ) ) {
            $pillar_values_by_country = array();
            foreach ( $results as $iso3 => $row ) {
                if ( $row['coverage_type'] === 'full' ) {
                    $pillar_values_by_country[ $iso3 ] = array(
                        'energy'   => $row['energy_dependency_percentile'],
                        'hhi'      => $row['supplier_concentration_percentile'],
                        'maritime' => $row['maritime_vulnerability_percentile'],
                    );
                }
            }
            if ( ! empty( $pillar_values_by_country ) ) {
                $sensitivity = blomstra_bootstrap_ci( $pillar_values_by_country, $composite_weights, 1000, 0.95 );
                if ( $sensitivity ) {
                    foreach ( $sensitivity as $iso3 => $interval ) {
                        $output['countries'][ $iso3 ]['sensitivity_interval'] = $interval;
                    }
                }
            }
        }

        // ─── Data freshness per pillar ────────────────────────────
        if ( function_exists( 'blomstra_data_quality_flag' ) ) {
            foreach ( $output['countries'] as $iso3 => &$country ) {
                $country['data_freshness'] = array(
                    'energy'   => blomstra_data_quality_flag( $all_sources, $iso3, 'energy_dependency' ),
                    'hhi'      => blomstra_data_quality_flag( $all_sources, $iso3, 'supplier_concentration' ),
                    'maritime' => blomstra_data_quality_flag( $all_sources, $iso3, 'maritime_connectivity' ),
                );
            }
            unset( $country );
        }

        // ─── DQI (Data Quality Index) – Phase 5b ──────────────────
        if ( function_exists( 'blomstra_compute_dqi' ) && function_exists( 'blomstra_compute_composite_dqi' ) ) {
            foreach ( $output['countries'] as $iso3 => &$country ) {
                // Get pillar data years from the pillar caches
                $energy_row = $energy_data[ $iso3 ] ?? array();
                $hhi_row    = $hhi_data[ $iso3 ] ?? array();
                $maritime_row = $maritime_data[ $iso3 ] ?? array();
                
                $energy_year = isset( $energy_row['data_year'] ) ? (int) $energy_row['data_year'] : null;
                $hhi_year    = isset( $hhi_row['year'] ) ? (int) $hhi_row['year'] : null;
                $maritime_year = isset( $maritime_row['year'] ) ? (int) $maritime_row['year'] : null;
                
                // Compute pillar DQIs
                $energy_dqi = blomstra_compute_dqi( $energy_year, SIVI_CURRENT_YEAR, SIVI_MAX_LAG_ENERGY );
                $hhi_dqi    = blomstra_compute_dqi( $hhi_year, SIVI_CURRENT_YEAR, SIVI_MAX_LAG_HHI );
                $maritime_dqi = blomstra_compute_dqi( $maritime_year, SIVI_CURRENT_YEAR, SIVI_MAX_LAG_MARITIME );
                
                // Store per-pillar DQI and data_year
                $country['data_year_energy'] = $energy_year;
                $country['data_year_hhi'] = $hhi_year;
                $country['data_year_maritime'] = $maritime_year;
                $country['dqi_energy'] = $energy_dqi;
                $country['dqi_hhi'] = $hhi_dqi;
                $country['dqi_maritime'] = $maritime_dqi;
                
                // Compute composite DQI (using the same weights as the score)
                $composite_dqi = blomstra_compute_composite_dqi( array(
                    array( 'dqi' => $energy_dqi, 'weight' => $composite_weights['energy'] ),
                    array( 'dqi' => $hhi_dqi, 'weight' => $composite_weights['hhi'] ),
                    array( 'dqi' => $maritime_dqi, 'weight' => $composite_weights['maritime'] ),
                ) );
                $country['composite_dqi'] = $composite_dqi;
                
                // Add vintage summary for display
                $vintage_parts = array();
                if ( $energy_year ) {
                    $vintage_parts[] = 'Energy: ' . $energy_year;
                }
                if ( $hhi_year ) {
                    $vintage_parts[] = 'HHI: ' . $hhi_year;
                }
                if ( $maritime_year ) {
                    $vintage_parts[] = 'Maritime: ' . $maritime_year;
                }
                $country['vintage_summary'] = ! empty( $vintage_parts ) ? implode( ', ', $vintage_parts ) : 'No data';
            }
            unset( $country );
        }

        // ─── Benchmark correlation ────────────────────────────────
        if ( function_exists( 'blomstra_benchmark_correlate' ) ) {
            $benchmark_data = get_transient( 'sivi_benchmark_comparator' );
            if ( $benchmark_data && is_array( $benchmark_data ) ) {
                $index_scores = array();
                foreach ( $output['countries'] as $iso3 => $c ) {
                    if ( isset( $c['sivi_structural'] ) && is_numeric( $c['sivi_structural'] ) ) {
                        $index_scores[ $iso3 ] = (float) $c['sivi_structural'];
                    }
                }
                $comparator_clean = array();
                foreach ( $benchmark_data as $iso3 => $val ) {
                    if ( is_numeric( $val ) ) {
                        $comparator_clean[ strtoupper( trim( $iso3 ) ) ] = (float) $val;
                    }
                }
                $corr = blomstra_benchmark_correlate( $index_scores, $comparator_clean );
                if ( $corr ) {
                    $output['_meta']['benchmark_correlation'] = $corr;
                }
            }
        }

        // ─── Staging & validation ─────────────────────────────────
        $old_composite = get_option( SIVI_OPTION_KEY, null );

        // ─── Now proceed with staging & validation ──────────────
        if ( ! $is_scenario && $context !== 'scenario' ) {
            update_option( SIVI_STAGING_KEY, $output, false );

            $should_keep_old = false;
            if ( $old_composite && ! empty( $old_composite['countries'] ) ) {
                $prev_count = count( $old_composite['countries'] );
                $new_count = count( $output['countries'] );
                if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
                    error_log( 'SIVI: Automated build failed – new count (' . $new_count . ') vs previous (' . $prev_count . '). Keeping old composite.' );
                    set_transient( 'sivi_auto_build_failed', 'yes', DAY_IN_SECONDS );
                    $should_keep_old = true;
                }
            }

            if ( $should_keep_old && $old_composite ) {
                delete_option( SIVI_STAGING_KEY );
                if ( function_exists( 'blomstra_update_cron_status' ) ) {
                    blomstra_update_cron_status( 'sivi', 'error', 'Build failed – coverage too low. Old composite preserved.' );
                }
                return $old_composite;
            }

            // ─── FIRE ALERTS ONLY NOW (after validation) ──────────
            if ( function_exists( 'blomstra_fire_index_alerts' ) && $old_composite && ! empty( $old_composite['countries'] ) ) {
                $new_meta = array(
                    'total_countries' => $output['total_countries'],
                    'excluded'        => $output['excluded'],
                    'version'         => $output['version'],
                    'last_updated'    => $output['last_updated'],
                );
                $old_meta = array(
                    'total_countries' => $old_composite['total_countries'] ?? 0,
                    'excluded'        => $old_composite['excluded'] ?? 0,
                    'version'         => $old_composite['version'] ?? '',
                    'last_updated'    => $old_composite['last_updated'] ?? '',
                );
                $alert_count = blomstra_fire_index_alerts( 'sivi', $output['countries'], $old_composite['countries'], $new_meta, $old_meta );
                error_log( 'SIVI: Alerts fired with ' . $alert_count . ' changes detected (post-validation).' );
            }

            // ─── Save new composite ──────────────────────────────────
            update_option( SIVI_OPTION_KEY, $output, false );
            delete_option( SIVI_STAGING_KEY );

            // ─── Save snapshot history ──────────────────────────────
            if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
                $snap = array();
                foreach ( $output['countries'] as $iso3 => $data ) {
                    $snap[ $iso3 ] = array(
                        'composite_score' => $data['sivi_structural'] ?? null,
                        'rank' => $data['rank_display']['best_estimate'] ?? null,
                        'coverage_type' => $data['coverage'] ?? 'full',
                        'energy' => $data['energy_dependency_percentile'] ?? null,
                        'hhi'    => $data['supplier_concentration_percentile'] ?? null,
                        'maritime' => $data['maritime_vulnerability_percentile'] ?? null,
                    );
                }
                blomstra_index_snapshot_save( 'sivi', $snap );
            }

            if ( function_exists( 'blomstra_update_cron_status' ) ) {
                blomstra_update_cron_status( 'sivi', 'success', 'Build completed: ' . count( $output['countries'] ) . ' countries scored.', count( $output['countries'] ) );
            }
        }

        delete_transient( $lock_key );
        $lock_cleared = true;
        return $output;

    } catch ( Exception $e ) {
        delete_transient( $lock_key );
        $lock_cleared = true;
        if ( function_exists( 'blomstra_update_cron_status' ) ) {
            blomstra_update_cron_status( 'sivi', 'error', 'Build failed: ' . $e->getMessage() );
        }
        error_log( 'SIVI build error: ' . $e->getMessage() );
        return array( 'error' => $e->getMessage() );
    }
}


// ============================================================================
// 11. SCENARIO STORAGE
// ============================================================================

function sivi_store_scenario( $output, $scenario_id ) {
    $key = SIVI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id );
    update_option( $key, $output, false );
}

function sivi_list_scenarios() {
    global $wpdb;
    $results = array();
    $rows = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sivi_composite_index_scenario_%'" );
    foreach ( $rows as $row ) {
        $id = str_replace( 'sivi_composite_index_scenario_', '', $row->option_name );
        $data = get_option( $row->option_name );
        if ( $data ) {
            $results[ $id ] = $data;
        }
    }
    return $results;
}

function sivi_delete_scenario( $scenario_id ) {
    delete_option( SIVI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id ) );
}


// ============================================================================
// 12. AUTO‑REFRESH CRON
// ============================================================================

function sivi_auto_refresh_callback() {
    $lock = get_transient( 'sivi_build_lock' );
    if ( $lock !== false && ( time() - (int)$lock ) < SIVI_LOCK_TTL ) {
        error_log( 'SIVI Auto‑refresh: Manual build in progress, skipping.' );
        return;
    }

    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'sivi_auto', 'running', 'Auto‑refresh started.' );
    }

    $energy_result = sivi_refresh_energy_pillar();
    $hhi_result    = sivi_refresh_hhi_pillar();
    $maritime_result = sivi_refresh_maritime_pillar();

    $errors = array();
    if ( isset( $energy_result['error'] ) ) {
        $errors[] = 'Energy: ' . $energy_result['error'];
    }
    if ( isset( $hhi_result['error'] ) ) {
        $errors[] = 'HHI: ' . $hhi_result['error'];
    }
    if ( isset( $maritime_result['error'] ) ) {
        $errors[] = 'Maritime: ' . $maritime_result['error'];
    }

    if ( ! empty( $errors ) ) {
        if ( function_exists( 'blomstra_update_cron_status' ) ) {
            blomstra_update_cron_status( 'sivi_auto', 'error', 'Auto‑refresh failed: ' . implode( '; ', $errors ) );
        }
        error_log( 'SIVI Auto‑refresh errors: ' . implode( '; ', $errors ) );
        return;
    }

    $build_result = sivi_build_composite( 'cron' );
    if ( isset( $build_result['error'] ) ) {
        if ( function_exists( 'blomstra_update_cron_status' ) ) {
            blomstra_update_cron_status( 'sivi_auto', 'error', 'Auto‑refresh rebuild failed: ' . $build_result['error'] );
        }
        error_log( 'SIVI Auto‑refresh rebuild failed: ' . $build_result['error'] );
        return;
    }

    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'sivi_auto', 'success', 'Auto‑refresh completed: ' . $build_result['total_countries'] . ' countries scored.', $build_result['total_countries'] );
    }
}

add_action( SIVI_AUTO_REFRESH_HOOK, 'sivi_auto_refresh_callback' );

// ─── Schedule the cron on init ────────────────────────────────────────
add_action( 'init', function () {
    if ( ! wp_next_scheduled( SIVI_AUTO_REFRESH_HOOK ) ) {
        // Run daily at 03:00 UTC
        $time = strtotime( '03:00:00 UTC' ) + ( time() > strtotime( '03:00:00 UTC' ) ? DAY_IN_SECONDS : 0 );
        wp_schedule_event( $time, 'daily', SIVI_AUTO_REFRESH_HOOK );
    }
} );

// ─── Also run when Reference Data cron completes (as a fallback) ───
function sivi_maybe_auto_refresh_after_rd( $trigger = null ) {
    if ( ! get_transient( 'sivi_auto_refresh_queued' ) ) {
        set_transient( 'sivi_auto_refresh_queued', 1, 5 * MINUTE_IN_SECONDS );
        wp_schedule_single_event( time() + 60, SIVI_AUTO_REFRESH_HOOK );
    }
}
add_action( 'blomstra_cron_eia_weekly_event', 'sivi_maybe_auto_refresh_after_rd', 30 );
add_action( 'blomstra_cron_hhi_weekly_event', 'sivi_maybe_auto_refresh_after_rd', 30 );
add_action( 'blomstra_cron_maritime_weekly_event', 'sivi_maybe_auto_refresh_after_rd', 30 );


// ============================================================================
// 13. REST ENDPOINTS
// ============================================================================

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/sovereign-infrastructure-vulnerability-index', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $data = get_option( SIVI_OPTION_KEY, null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index not built yet.', array( 'status' => 404 ) );
            }
            // Cache for 1 hour
            header( 'Cache-Control: public, max-age=3600' );
            return $data;
        },
    ) );
} );


// ============================================================================
// 14. VALIDATION ON INIT
// ============================================================================

function sivi_initialize() {
    if ( function_exists( 'blomstra_validate_pillar_thresholds' ) ) {
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
}
add_action( 'init', 'sivi_initialize' );


// ============================================================================
// 15. ADMIN PAGE (Full with custom weights, DQI, vintage, backfill)
// ============================================================================

add_action( 'admin_menu', function () {
    add_submenu_page(
        'blomstra-insights-tools',
        'SIVI Index',
        'SIVI Index',
        'manage_options',
        'blomstra-sovereign-infrastructure-vulnerability-index',
        'sivi_render_admin_page'
    );
} );

add_action( 'admin_init', function () {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'cii-index' ) {
        wp_redirect( admin_url( 'admin.php?page=blomstra-sovereign-infrastructure-vulnerability-index' ) );
        exit;
    }
} );

function sivi_render_admin_page() {
    // ── Handle actions ────────────────────────────────────────────
    if ( isset( $_POST['sivi_fetch_energy'] ) && check_admin_referer( 'sivi_fetch_energy_action' ) ) {
        $result = sivi_refresh_energy_pillar();
        if ( isset( $result['error'] ) ) {
            echo '<div class="notice notice-error"><p>❌ Energy fetch failed: ' . esc_html( $result['error'] ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ Energy pillar cache updated from central data (' . count( $result ) . ' countries).</p></div>';
        }
    }
    if ( isset( $_POST['sivi_fetch_hhi'] ) && check_admin_referer( 'sivi_fetch_hhi_action' ) ) {
        $result = sivi_refresh_hhi_pillar();
        if ( isset( $result['error'] ) ) {
            echo '<div class="notice notice-error"><p>❌ HHI fetch failed: ' . esc_html( $result['error'] ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ HHI pillar cache updated from central data (' . count( $result ) . ' countries).</p></div>';
        }
    }
    if ( isset( $_POST['sivi_fetch_maritime'] ) && check_admin_referer( 'sivi_fetch_maritime_action' ) ) {
        $result = sivi_refresh_maritime_pillar();
        if ( isset( $result['error'] ) ) {
            echo '<div class="notice notice-error"><p>❌ Maritime fetch failed: ' . esc_html( $result['error'] ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ Maritime pillar cache updated from central data (' . count( $result ) . ' countries).</p></div>';
        }
    }

    if ( isset( $_POST['sivi_flush_energy'] ) && check_admin_referer( 'sivi_flush_energy_action' ) ) {
        delete_option( SIVI_ENERGY_KEY );
        delete_option( SIVI_ENERGY_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Energy pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['sivi_flush_hhi'] ) && check_admin_referer( 'sivi_flush_hhi_action' ) ) {
        delete_option( SIVI_HHI_KEY );
        delete_option( SIVI_HHI_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ HHI pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['sivi_flush_maritime'] ) && check_admin_referer( 'sivi_flush_maritime_action' ) ) {
        delete_option( SIVI_MARITIME_KEY );
        delete_option( SIVI_MARITIME_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Maritime pillar cache flushed.</p></div>';
    }

    if ( isset( $_POST['sivi_build_cache'] ) && check_admin_referer( 'sivi_build_cache_action' ) ) {
        $data = sivi_build_composite( 'manual' );
        if ( isset( $data['error'] ) ) {
            echo '<div class="notice notice-error"><p>❌ Build failed: ' . esc_html( $data['error'] ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ Composite built from pillar cache: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded'] ) . ' excluded).</p></div>';
        }
    }

    if ( isset( $_POST['sivi_flush_all_confirmed'] ) && check_admin_referer( 'sivi_flush_all_action' ) ) {
        delete_option( SIVI_ENERGY_KEY );
        delete_option( SIVI_HHI_KEY );
        delete_option( SIVI_MARITIME_KEY );
        delete_option( SIVI_ENERGY_META_KEY );
        delete_option( SIVI_HHI_META_KEY );
        delete_option( SIVI_MARITIME_META_KEY );
        delete_option( SIVI_OPTION_KEY );
        delete_option( SIVI_STAGING_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ All SIVI pillar caches and composite have been flushed.</p></div>';
    }

    // ─── SENSITIVITY TESTING ──────────────────────────────────────
    if ( isset( $_POST['sivi_build_scenario'] ) && check_admin_referer( 'sivi_build_scenario_action' ) ) {
        $scenario_name = sanitize_key( $_POST['sivi_scenario_name'] );
        $raw_json = wp_unslash( $_POST['sivi_custom_weights'] );
        $json = json_decode( $raw_json, true );
        if ( $json === null ) {
            echo '<div class="notice notice-error"><p>❌ Invalid JSON. Please check the syntax. Error: ' . json_last_error_msg() . '</p></div>';
        } elseif ( ! isset( $json['pillars'] ) || ! isset( $json['composite'] ) ) {
            echo '<div class="notice notice-error"><p>❌ JSON must include both <code>pillars</code> and <code>composite</code> keys.</p></div>';
        } elseif ( ! isset( $json['composite']['energy'], $json['composite']['hhi'], $json['composite']['maritime'] ) ) {
            echo '<div class="notice notice-error"><p>❌ <code>composite</code> must contain exactly <code>energy</code>, <code>hhi</code>, and <code>maritime</code> keys.</p></div>';
        } else {
            $sum = array_sum( $json['composite'] );
            if ( abs( $sum - 100 ) > 0.1 ) {
                echo '<div class="notice notice-error"><p>❌ Composite weights must sum to 100. Current sum: ' . esc_html( $sum ) . '</p></div>';
            } else {
                $result = sivi_build_composite( 'scenario', $json['pillars'], $json['composite'] );
                if ( isset( $result['error'] ) ) {
                    echo '<div class="notice notice-error"><p>❌ Scenario build failed: ' . esc_html( $result['error'] ) . '</p></div>';
                } else {
                    sivi_store_scenario( $result, $scenario_name );
                    echo '<div class="notice notice-success"><p>✅ Scenario <strong>' . esc_html( $scenario_name ) . '</strong> built: ' . esc_html( $result['total_countries'] ) . ' countries scored.</p></div>';
                }
            }
        }
    }

    if ( isset( $_POST['sivi_delete_scenario'] ) && check_admin_referer( 'sivi_delete_scenario_action' ) ) {
        $scenario_id = sanitize_key( $_POST['sivi_delete_scenario'] );
        sivi_delete_scenario( $scenario_id );
        echo '<div class="notice notice-warning"><p>🗑️ Scenario <strong>' . esc_html( $scenario_id ) . '</strong> deleted.</p></div>';
    }

    // ─── BENCHMARK CORRELATION ────────────────────────────────────
    if ( isset( $_POST['sivi_benchmark_correlate'] ) && check_admin_referer( 'sivi_benchmark_correlate_action' ) ) {
        $raw_bench_json = wp_unslash( $_POST['sivi_benchmark_json'] ?? '' );
        $comparator = json_decode( $raw_bench_json, true );
        if ( $comparator === null || ! is_array( $comparator ) ) {
            echo '<div class="notice notice-error"><p>❌ Invalid JSON. Expected a flat <code>{"ISO3": score, ...}</code> object.</p></div>';
        } elseif ( ! function_exists( 'blomstra_benchmark_correlate' ) ) {
            echo '<div class="notice notice-error"><p>❌ blomstra_benchmark_correlate() is not available — check the shared Utility snippet is active.</p></div>';
        } else {
            set_transient( 'sivi_benchmark_comparator', $comparator, HOUR_IN_SECONDS );
            echo '<div class="notice notice-info"><p>📊 Comparator stored. Please rebuild the index to see correlation in the meta field.</p></div>';
        }
    }

    // ─── CUSTOM COMPOSITE WEIGHTS (fixed: dynamic sum + nonce) ──
    if ( isset( $_POST['sivi_save_custom_weights'] ) && check_admin_referer( 'sivi_custom_weights_action' ) ) {
        $energy   = (float) $_POST['sivi_weight_energy'];
        $hhi      = (float) $_POST['sivi_weight_hhi'];
        $maritime = (float) $_POST['sivi_weight_maritime'];
        $sum = $energy + $hhi + $maritime;
        if ( abs( $sum - 100 ) > 0.01 ) {
            echo '<div class="notice notice-error"><p>❌ Weights must sum to 100. Current sum: ' . esc_html( $sum ) . '</p></div>';
        } else {
            update_option( 'sivi_custom_composite_weights', array(
                'energy'   => $energy,
                'hhi'      => $hhi,
                'maritime' => $maritime,
            ) );
            echo '<div class="notice notice-success"><p>✅ Custom weights saved. Rebuild the index to apply.</p></div>';
        }
    }

    if ( isset( $_POST['sivi_reset_custom_weights'] ) && check_admin_referer( 'sivi_custom_weights_action' ) ) {
        delete_option( 'sivi_custom_composite_weights' );
        echo '<div class="notice notice-success"><p>✅ Custom weights reset to defaults. Rebuild the index to apply.</p></div>';
    }

    // ─── BACKFILL ALL YEARS (async) ──────────────────────────────
    if ( isset( $_POST['sivi_backfill_all'] ) && check_admin_referer( 'sivi_backfill_all_action', 'sivi_backfill_all_nonce' ) ) {
        // Check if production refreshes are running
        $eia_lock = get_transient( 'blomstra_eia_refresh_in_progress' );
        $hhi_lock = get_transient( 'blomstra_hhi_refresh_in_progress' );
        if ( $eia_lock || $hhi_lock ) {
            echo '<div class="notice notice-error"><p>⚠️ Production pillar refresh is in progress (EIA or HHI). Please wait and try again later.</p></div>';
        } else {
            $lock = get_transient( 'sivi_backfill_lock' );
            if ( $lock !== false ) {
                echo '<div class="notice notice-warning"><p>⚠️ Backfill is already running. Please wait for completion.</p></div>';
            } else {
                // Set lock for 2 hours (safety)
                set_transient( 'sivi_backfill_lock', time(), 2 * HOUR_IN_SECONDS );

                // Dynamic range
                $range = blomstra_get_index_backfill_range('sivi');
                $years = range( $range['start'], $range['end'] );
                foreach ( $years as $index => $year ) {
                    // Schedule each year with 2-minute stagger
                    wp_schedule_single_event( time() + ( $index * 120 ), 'sivi_backfill_year_cron', array( $year ) );
                    sivi_update_backfill_status( $year, 'scheduled', 0, null );
                }
                echo '<div class="notice notice-success"><p>✅ Backfill scheduled for ' . $range['start'] . '–' . $range['end'] . '. Each year will run as a background job (2-minute stagger). Check the status table below for progress.</p></div>';
            }
        }
    }

    // ─── RETRY SINGLE YEAR (synchronous) ──────────────────────────
    if ( isset( $_POST['sivi_backfill_year'] ) && check_admin_referer( 'sivi_backfill_year_action', 'sivi_backfill_year_nonce' ) ) {
        $year = (int) $_POST['sivi_backfill_year'];
        $range = blomstra_get_index_backfill_range('sivi');
        if ( $year < $range['start'] || $year > $range['end'] ) {
            echo '<div class="notice notice-error"><p>Invalid year. Must be between ' . $range['start'] . ' and ' . $range['end'] . '.</p></div>';
        } else {
            // Run synchronously – safe for a single year
            $result = sivi_build_historical_snapshot( $year );
            if ( $result['success'] ) {
                sivi_update_backfill_status( $year, 'success', $result['countries'], null );
                echo '<div class="notice notice-success"><p>✅ Backfill for ' . $year . ' completed – ' . $result['countries'] . ' countries saved.</p></div>';
            } else {
                sivi_update_backfill_status( $year, 'failed', 0, $result['error'] );
                echo '<div class="notice notice-error"><p>❌ Backfill for ' . $year . ' failed: ' . esc_html( $result['error'] ) . '</p></div>';
            }
        }
    }

    // ─── Display current status ────────────────────────────────────
    $existing = get_option( SIVI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( SIVI_AUTO_REFRESH_HOOK );
    $auto_refresh_time = $next_cron ? date_i18n( 'Y-m-d H:i', $next_cron ) : 'Not scheduled';
    $last_cron = get_option( 'blomstra_cron_status', array() );
    $sivi_status = $last_cron['sivi'] ?? null;
    $auto_status = $last_cron['sivi_auto'] ?? null;

    $energy_store = get_option( SIVI_ENERGY_KEY, array() );
    $hhi_store = get_option( SIVI_HHI_KEY, array() );
    $maritime_store = get_option( SIVI_MARITIME_KEY, array() );

    $energy_count = count( array_filter( $energy_store['data'] ?? array(), function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) );
    $hhi_count    = count( array_filter( $hhi_store['data'] ?? array(), function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) );
    $maritime_count = count( array_filter( $maritime_store['data'] ?? array(), function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) );

    $energy_fresh = function_exists( 'blomstra_is_stale' ) ? ! blomstra_is_stale( 'eia' ) : false;
    $hhi_fresh    = function_exists( 'blomstra_is_stale' ) ? ! blomstra_is_stale( 'hhi' ) : false;
    $maritime_fresh = function_exists( 'blomstra_is_stale' ) ? ! blomstra_is_stale( 'maritime' ) : false;

    $energy_status = $energy_count > 0 ? ( $energy_fresh ? '✅ Cached (source fresh)' : '⚠️ Source stale' ) : '⏳ Cache empty – click "Fetch"';
    $hhi_status    = $hhi_count > 0    ? ( $hhi_fresh    ? '✅ Cached (source fresh)' : '⚠️ Source stale' ) : '⏳ Cache empty – click "Fetch"';
    $maritime_status = $maritime_count > 0 ? ( $maritime_fresh ? '✅ Cached (source fresh)' : '⚠️ Source stale' ) : '⏳ Cache empty – click "Fetch"';

    $energy_color = $energy_count > 0 ? ( $energy_fresh ? '#2e7d32' : '#d63638' ) : '#666';
    $hhi_color    = $hhi_count > 0    ? ( $hhi_fresh    ? '#2e7d32' : '#d63638' ) : '#666';
    $maritime_color = $maritime_count > 0 ? ( $maritime_fresh ? '#2e7d32' : '#d63638' ) : '#666';

    echo '<div class="wrap"><h1>SIVI — Sovereign Infrastructure Vulnerability Index</h1>';

    // ─── DASHBOARD CARDS ──────────────────────────────────────────
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin:15px 0;">';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Energy Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ($energy_count > 0 ? 'Scored ✓ (' . $energy_count . ')' : 'Not Scored') . '</p>';
    echo '<p style="font-size:12px; color:' . $energy_color . ';">' . $energy_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">HHI Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ($hhi_count > 0 ? 'Scored ✓ (' . $hhi_count . ')' : 'Not Scored') . '</p>';
    echo '<p style="font-size:12px; color:' . $hhi_color . ';">' . $hhi_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Maritime Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ($maritime_count > 0 ? 'Scored ✓ (' . $maritime_count . ')' : 'Not Scored') . '</p>';
    echo '<p style="font-size:12px; color:' . $maritime_color . ';">' . $maritime_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #f56e28; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Composite Index</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ( $existing ? 'Scored ✓ (' . $existing['total_countries'] . ')' : 'Not Scored' ) . '</p></div></div>';
    echo '</div>';

    // ─── Coverage Breakdown ──────────────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $full_count = 0; $partial_count = 0;
        foreach ( $existing['countries'] as $country ) {
            if ( isset( $country['coverage'] ) ) {
                if ( $country['coverage'] === 'full' ) $full_count++;
                else $partial_count++;
            }
        }
        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#f0f6fc; margin:15px 0;">';
        echo '<div class="inside" style="padding:10px 15px;">';
        echo '<h3 style="margin:0 0 8px 0; font-size:14px;">📊 Coverage Breakdown</h3>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:20px;">';
        echo '<div><strong style="color:#2e7d32;">Full Index:</strong> ' . $full_count . ' countries</div>';
        echo '<div><strong style="color:#ed6c02;">Partial Index:</strong> ' . $partial_count . ' countries</div>';
        echo '<div><strong style="color:#d32f2f;">Excluded:</strong> ' . $existing['excluded'] . ' countries</div>';
        echo '<div><strong style="color:#1976d2;">Total Scored:</strong> ' . $existing['total_countries'] . ' countries</div>';
        echo '</div>';
        echo '</div></div>';
    }

    // ─── Upstream warnings ──────────────────────────────────────
    $warnings = sivi_check_upstream_health();
    if ( ! empty( $warnings ) ) {
        echo '<div class="notice notice-warning"><p>⚠️ ' . implode( ' ', $warnings ) . '</p></div>';
    }

    // ─── STATUS SECTION ──────────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Data Source &amp; Auto‑Refresh Status</h2></div>';
    echo '<div class="inside">';
    echo '<p>Next auto‑refresh: <strong>' . esc_html( $auto_refresh_time ) . '</strong></p>';
    if ( $auto_status ) {
        $last_time = isset( $auto_status['last_success'] ) 
            ? $auto_status['last_success'] 
            : ( isset( $auto_status['last_attempt'] ) ? $auto_status['last_attempt'] : '—' );
        echo '<p>Last auto‑refresh: <strong>' . esc_html( $auto_status['status'] ) . '</strong> at ' . esc_html( $last_time ) . ' — ' . esc_html( $auto_status['message'] ) . '</p>';
    } else {
        echo '<p>Auto‑refresh has not run yet.</p>';
    }
    echo '</div></div>';

    // ─── CUSTOM COMPOSITE WEIGHTS UI (fixed) ────────────────────
    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-sliders"></span> ⚖️ Custom Composite Weights</h2></div>';
    echo '<div class="inside">';
    $current = get_option( 'sivi_custom_composite_weights', false );
    if ( ! $current ) {
        $current = sivi_get_composite_weights(); // use the function for default
    }

    echo '<p style="color:#666;">Adjust the pillar weights for the composite index. They must sum to 100. Changes take effect after rebuilding the index.</p>';

    ?>
    <script>
    function updateWeightSum() {
        var e = parseFloat(document.getElementById('sivi_weight_energy').value) || 0;
        var h = parseFloat(document.getElementById('sivi_weight_hhi').value) || 0;
        var m = parseFloat(document.getElementById('sivi_weight_maritime').value) || 0;
        var sum = e + h + m;
        var display = document.getElementById('weightSumDisplay');
        display.textContent = sum.toFixed(2);
        var errorDiv = document.getElementById('weightSumError');
        if (Math.abs(sum - 100) > 0.01) {
            errorDiv.style.display = 'block';
            errorDiv.textContent = '⚠️ Sum must equal 100 (currently ' + sum.toFixed(2) + ')';
        } else {
            errorDiv.style.display = 'none';
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        var inputs = ['sivi_weight_energy', 'sivi_weight_hhi', 'sivi_weight_maritime'];
        inputs.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', updateWeightSum);
                el.addEventListener('change', updateWeightSum);
            }
        });
        updateWeightSum();
    });
    </script>
    <?php

    echo '<form method="post">';
    wp_nonce_field( 'sivi_custom_weights_action' );
    echo '<div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">';
    echo '<div><label>Energy: <input type="number" id="sivi_weight_energy" name="sivi_weight_energy" value="' . esc_attr( $current['energy'] ) . '" step="0.01" min="0" max="100" style="width:80px;"></label></div>';
    echo '<div><label>HHI: <input type="number" id="sivi_weight_hhi" name="sivi_weight_hhi" value="' . esc_attr( $current['hhi'] ) . '" step="0.01" min="0" max="100" style="width:80px;"></label></div>';
    echo '<div><label>Maritime: <input type="number" id="sivi_weight_maritime" name="sivi_weight_maritime" value="' . esc_attr( $current['maritime'] ) . '" step="0.01" min="0" max="100" style="width:80px;"></label></div>';
    echo '<div><strong>Sum: <span id="weightSumDisplay">' . esc_html( array_sum( $current ) ) . '</span></strong></div>';
    echo '</div>';
    echo '<div id="weightSumError" style="color:#d63638; margin-top:5px; display:none;"></div>';
    echo '<div style="margin-top:10px; display:flex; gap:10px;">';
    echo '<input type="submit" name="sivi_save_custom_weights" class="button button-primary" value="💾 Save Weights">';
    echo '<input type="submit" name="sivi_reset_custom_weights" class="button button-secondary" value="↺ Reset to Defaults" onclick="return confirm(\'Reset to default weights?\');">';
    echo '</div>';
    echo '</form>';
    echo '<p style="font-size:12px; color:#666; margin-top:10px;">Current weights will be used in all future builds unless overridden by scenario JSON.</p>';
    echo '</div></div>';

    // ─── PILLAR DATA LAYER ──────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database"></span> Pillar Data Layer</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;"><strong>Fetch from Central Data</strong> — reads the Reference Data cache and populates SIVI pillar caches.<br>';
    echo '<strong>Flush</strong> — clears the pillar cache (use only if data is corrupted).</p>';
    echo '<table class="widefat striped"><thead><tr><th>Pillar</th><th>Status</th><th>Fetch from Central</th><th>Flush</th></tr></thead><tbody>';
    foreach ( array( 'energy' => 'Energy', 'hhi' => 'HHI', 'maritime' => 'Maritime' ) as $key => $label ) {
        $store = get_option( constant( 'SIVI_' . strtoupper( $key ) . '_KEY' ), array() );
        $data = $store['data'] ?? array();
        $count = is_array( $data ) ? count( array_filter( $data, function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) ) : 0;
        $status = $count > 0 ? '<span style="color:#2e7d32;">Cached ✓ (' . $count . ')</span>' : '<span style="color:#d63638;">Not Cached</span>';
        echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $status . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'sivi_fetch_' . $key . '_action' );
        echo '<input type="submit" name="sivi_fetch_' . $key . '" class="button button-small button-primary" style="min-width:140px;" value="📥 Fetch (Sync)">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'sivi_flush_' . $key . '_action' );
        echo '<input type="submit" name="sivi_flush_' . $key . '" class="button button-small button-link-delete" style="min-width:140px;" value="🗑️ Flush">';
        echo '</form></td></tr>';
    }
    echo '</tbody></table>';
    echo '</div></div>';

    // ─── COMPOSITE & BUILD ──────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #f56e28; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-area"></span> Composite &amp; Build</h2></div>';
    echo '<div class="inside">';
    if ( $existing ) {
        echo '<p>Last built: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' countries scored, ' . esc_html( $existing['excluded'] ) . ' excluded.</p>';
    } else {
        echo '<p>No composite exists yet.</p>';
    }

    echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:15px 0;">';
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'sivi_build_cache_action' );
    echo '<input type="submit" name="sivi_build_cache" class="button button-primary" style="min-width:180px; font-weight:bold;" value="🔨 Build Index from Cache">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will delete ALL pillar caches and the composite. Continue?\');">';
    wp_nonce_field( 'sivi_flush_all_action' );
    echo '<input type="submit" name="sivi_flush_all_confirmed" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Flush ALL Caches">';
    echo '</form>';
    echo '</div>';

    echo '<p style="color:#666; font-size:12px; margin:0;"><strong>Build from Cache</strong> — uses existing pillar data (no API calls).<br>';
    echo '<strong>Flush ALL Caches</strong> — deletes all pillar and composite data (destructive).</p>';
    echo '</div></div>';

    // ─── HISTORICAL BACKFILL RANGE CONFIG (Moved here) ──────────
    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-admin-settings"></span> ⚙️ Historical Backfill Range</h2></div>';
    echo '<div class="inside">';
    $range = blomstra_get_index_backfill_range('sivi');
    echo '<p style="color:#666;">Configure the range of years to backfill for SIVI. Default is the previous 5 years (capped at ' . SIVI_BACKFILL_MIN_YEAR . ' due to Maritime data availability).</p>';
    echo '<form method="post">';
    wp_nonce_field( 'sivi_backfill_range_action', 'sivi_backfill_range_nonce' );
    echo '<div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">';
    echo '<div><label>Start Year: <input type="number" name="sivi_backfill_start" value="' . esc_attr( $range['start'] ) . '" min="' . SIVI_BACKFILL_MIN_YEAR . '" max="2030"></label></div>';
    echo '<div><label>End Year: <input type="number" name="sivi_backfill_end" value="' . esc_attr( $range['end'] ) . '" min="' . SIVI_BACKFILL_MIN_YEAR . '" max="2030"></label></div>';
    echo '<div><button type="submit" name="sivi_save_backfill_range" class="button button-secondary">💾 Save Range</button></div>';
    echo '</div>';
    echo '</form>';
    echo '</div></div>';

    // ─── HISTORICAL BACKFILL STATUS & ACTIONS ────────────────────
    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-backup"></span> 📅 Historical Backfill Status</h2></div>';
    echo '<div class="inside">';

    $backfill_status = sivi_get_backfill_status();
    $lock = get_transient( 'sivi_backfill_lock' );
    $is_running = ( $lock !== false );

    // Show status table
    $range = blomstra_get_index_backfill_range('sivi');
    echo '<table class="widefat striped"><thead><tr><th>Year</th><th>Status</th><th>Countries</th><th>Last Attempt</th><th>Error</th><th>Action</th></tr></thead><tbody>';
    for ( $year = $range['start']; $year <= $range['end']; $year++ ) {
        $st = $backfill_status[ $year ] ?? array( 'status' => 'not_started', 'countries' => 0, 'last_attempt' => null, 'error' => null );
        $status_label = ucfirst( $st['status'] );
        $color = '';
        if ( $st['status'] === 'success' ) {
            $color = '#2e7d32';
        } elseif ( $st['status'] === 'partial' ) {
            $color = '#f0ad4e';
        } elseif ( $st['status'] === 'failed' ) {
            $color = '#d63638';
        } elseif ( $st['status'] === 'scheduled' ) {
            $color = '#2271b1';
        } else {
            $color = '#999';
        }
        echo '<tr>';
        echo '<td><strong>' . esc_html( $year ) . '</strong></td>';
        echo '<td style="color:' . $color . ';">' . esc_html( $status_label ) . '</td>';
        echo '<td>' . esc_html( $st['countries'] ) . '</td>';
        echo '<td>' . ( $st['last_attempt'] ? esc_html( $st['last_attempt'] ) : '—' ) . '</td>';
        echo '<td>' . ( $st['error'] ? esc_html( $st['error'] ) : '—' ) . '</td>';
        echo '<td>';
        if ( ! $is_running && in_array( $st['status'], array( 'failed', 'partial', 'not_started' ), true ) ) {
            // Retry button for this year
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'sivi_backfill_year_action', 'sivi_backfill_year_nonce' );
            echo '<input type="hidden" name="sivi_backfill_year" value="' . esc_attr( $year ) . '">';
            echo '<input type="submit" class="button button-small" value="Retry">';
            echo '</form>';
        } else {
            echo '—';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    // Backfill All Years button
    if ( ! $is_running ) {
        echo '<form method="post" style="margin-top:15px;" onsubmit="return confirm(\'This will schedule backfill for all years ' . $range['start'] . '–' . $range['end'] . '. Continue?\');">';
        wp_nonce_field( 'sivi_backfill_all_action', 'sivi_backfill_all_nonce' );
        echo '<input type="submit" name="sivi_backfill_all" class="button button-primary" value="📦 Backfill All Years (' . $range['start'] . '–' . $range['end'] . ')">';
        echo '</form>';
    } else {
        echo '<p style="color:#2271b1;">⏳ Backfill is currently running in the background. Refresh to see progress.</p>';
    }

    echo '<p style="color:#666; font-size:12px; margin-top:5px;">This will create historical snapshots for the configured range. Each year runs as a separate background job to avoid timeouts. The DQI and vintage metadata will reflect the actual data year used for each pillar.</p>';
    echo '</div></div>';

    // ─── SENSITIVITY TESTING (Research) ──────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-admin-generic"></span> 🔬 Sensitivity Testing (Research)</h2></div>';
    echo '<div class="inside">';

    // Preset weights (unchanged)
    $preset_weights = array(
        'baseline'        => array( 'energy' => 33.33, 'hhi' => 33.33, 'maritime' => 33.34 ),
        'energy-heavy'    => array( 'energy' => 70, 'hhi' => 15, 'maritime' => 15 ),
        'energy-light'    => array( 'energy' => 10, 'hhi' => 45, 'maritime' => 45 ),
        'hhi-heavy'       => array( 'energy' => 15, 'hhi' => 70, 'maritime' => 15 ),
        'hhi-light'       => array( 'energy' => 45, 'hhi' => 10, 'maritime' => 45 ),
        'maritime-heavy'  => array( 'energy' => 15, 'hhi' => 15, 'maritime' => 70 ),
        'maritime-light'  => array( 'energy' => 45, 'hhi' => 45, 'maritime' => 10 ),
    );
    $preset_js = array();
    foreach ( $preset_weights as $key => $weights ) {
        $preset_js[ $key ] = array(
            'pillars'   => sivi_get_pillar_weights(),
            'composite' => $weights,
        );
    }
    $preset_json = wp_json_encode( $preset_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

    echo '<p><strong>Presets:</strong> Click a button to load a predefined weighting scheme.</p>';
    echo '<div style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:15px;">';
    foreach ( $preset_weights as $key => $weights ) {
        $label = str_replace( '-', ' ', $key );
        $label = ucwords( $label );
        echo '<button type="button" class="button preset-btn" data-preset="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button> ';
    }
    echo '</div>';

    ?>
    <script>
    var siviPresets = <?php echo $preset_json; ?>;
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.preset-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var presetName = this.dataset.preset;
                var preset = siviPresets[presetName];
                if (preset) {
                    document.getElementById('sivi_custom_weights').value = JSON.stringify(preset, null, 4);
                    document.getElementById('sivi_scenario_name').value = presetName;
                }
            });
        });
    });
    </script>
    <?php

    echo '<form method="post" style="margin-top:10px;">';
    wp_nonce_field( 'sivi_build_scenario_action' );
    echo '<p><strong>Custom Weights JSON</strong></p>';
    echo '<p style="color:#666; font-size:12px;">Edit the JSON below to define custom pillar weights. <code>pillars</code> controls within-pillar indicator weights (rarely changed). <code>composite</code> controls the 3 pillar weights (must sum to 100).</p>';
    $default_json = wp_json_encode( array( 'pillars' => sivi_get_pillar_weights(), 'composite' => sivi_get_composite_weights() ), JSON_PRETTY_PRINT );
    echo '<textarea id="sivi_custom_weights" name="sivi_custom_weights" style="width:100%;height:180px;font-family:monospace;font-size:12px;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;">' . esc_textarea( $default_json ) . '</textarea>';

    echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">';
    echo '<label><strong>Scenario ID:</strong></label>';
    echo '<input type="text" id="sivi_scenario_name" name="sivi_scenario_name" placeholder="e.g., energy-heavy" style="width:200px;" required pattern="[a-z0-9\-]+">';
    echo '<input type="submit" name="sivi_build_scenario" class="button button-primary" value="🔬 Build Scenario">';
    echo '</div>';
    echo '</form>';

    // Scenario comparison table
    $scenarios = sivi_list_scenarios();
    $baseline = get_option( SIVI_OPTION_KEY );
    if ( ! empty( $scenarios ) && $baseline ) {
        echo '<h4 style="margin-top:20px;">Scenario Comparison</h4>';
        echo '<table class="widefat striped"><thead><tr><th>Scenario</th><th>Countries</th><th>Spearman ρ vs Baseline</th><th>Top Mover</th><th>Action</th></tr></thead><tbody>';

        $baseline_ranks = array();
        foreach ( $baseline['countries'] as $iso3 => $c ) {
            if ( isset( $c['rank_display']['best_estimate'] ) ) {
                $baseline_ranks[ $iso3 ] = $c['rank_display']['best_estimate'];
            }
        }

        foreach ( $scenarios as $id => $scenario ) {
            $scenario_ranks = array();
            foreach ( $scenario['countries'] as $iso3 => $c ) {
                if ( isset( $c['rank_display']['best_estimate'] ) ) {
                    $scenario_ranks[ $iso3 ] = $c['rank_display']['best_estimate'];
                }
            }

            $common = array_intersect_key( $baseline_ranks, $scenario_ranks );
            $x = array();
            $y = array();
            foreach ( $common as $iso3 => $br ) {
                $x[] = $br;
                $y[] = $scenario_ranks[ $iso3 ];
            }

            $rho = 'N/A';
            if ( count( $x ) > 2 ) {
                $rho = function_exists( 'blomstra_spearman_correlation' )
                    ? round( blomstra_spearman_correlation( $x, $y ), 3 )
                    : 0;
            }

            $max_delta = 0;
            $top_mover = '-';
            foreach ( $common as $iso3 => $br ) {
                $delta = abs( $br - $scenario_ranks[ $iso3 ] );
                if ( $delta > $max_delta ) {
                    $max_delta = $delta;
                    $top_mover = $iso3 . ' (±' . $delta . ')';
                }
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html( $id ) . '</strong></td>';
            echo '<td>' . esc_html( $scenario['total_countries'] ) . '</td>';
            echo '<td>' . esc_html( $rho ) . '</td>';
            echo '<td>' . esc_html( $top_mover ) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'sivi_delete_scenario_action' );
            echo '<input type="hidden" name="sivi_delete_scenario" value="' . esc_attr( $id ) . '">';
            echo '<input type="submit" class="button button-small button-link-delete" value="Delete" onclick="return confirm(\'Delete scenario ' . esc_js( $id ) . '?\');">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="margin-top:10px; color:#666;">No scenarios built yet. Use the presets and build a scenario to see comparison data.</p>';
    }

    echo '</div></div>';

    // ─── BENCHMARK CORRELATION (Research) ─────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-line"></span> 🔗 Benchmark Correlation (Research)</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;">Paste an external comparator index\'s scores as a flat <code>{"ISO3": score, ...}</code> JSON object. The correlation will appear in the composite meta after the next rebuild.</p>';
    echo '<form method="post">';
    wp_nonce_field( 'sivi_benchmark_correlate_action' );
    echo '<textarea name="sivi_benchmark_json" style="width:100%;height:140px;font-family:monospace;font-size:12px;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;" placeholder=\'{"USA": 42.1, "DEU": 30.5, "CHN": 55.0}\'></textarea>';
    echo '<p style="margin-top:10px;"><input type="submit" name="sivi_benchmark_correlate" class="button button-primary" value="🔗 Store Comparator"></p>';
    echo '</form>';
    echo '</div></div>';

    // ─── PREVIEW TABLES (with DQI) ──────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $countries = $existing['countries'];
        uasort( $countries, function( $a, $b ) {
            return ( $b['sivi_structural'] ?? 0 ) <=> ( $a['sivi_structural'] ?? 0 );
        } );

        $top_vulnerable = array_slice( $countries, 0, 10, true );
        $least_vulnerable = array_slice( $countries, -10, 10, true );

        echo '<div style="margin-top:20px;">';

        // Top 10 Vulnerable
        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📊 10 Most Vulnerable Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Rank</th><th>Country</th><th>Vulnerability Score</th><th>Energy</th><th>HHI</th><th>Maritime</th><th>DQI</th><th>Coverage</th>';
        echo '</tr></thead><tbody>';
        foreach ( $top_vulnerable as $iso3 => $row ) {
            $rank = $row['rank_display']['string_format'] ?? '—';
            $energy = $row['energy_dependency_percentile'] ?? '—';
            $hhi = $row['supplier_concentration_percentile'] ?? '—';
            $maritime = $row['maritime_vulnerability_percentile'] ?? '—';
            $cov = $row['coverage'] ?? 'partial';
            $cov_style = $cov === 'full' ? '#2e7d32' : '#b26a00';
            
            // DQI display
            $dqi = isset( $row['composite_dqi'] ) ? $row['composite_dqi'] : null;
            if ( $dqi !== null ) {
                $dqi_display = round( $dqi ) . '%';
                $dqi_color = $dqi >= 70 ? '#2e7d32' : ( $dqi >= 40 ? '#f0ad4e' : '#d63638' );
            } else {
                $dqi_display = '—';
                $dqi_color = '#999';
            }
            
            // Vintage tooltip
            $vintage = isset( $row['vintage_summary'] ) ? $row['vintage_summary'] : 'No data';
            
            echo '<tr>';
            echo '<td><strong>' . esc_html( $rank ) . '</strong></td>';
            echo '<td><strong>' . esc_html( $row['name'] ) . '</strong></td>';
            echo '<td>' . esc_html( $row['sivi_structural'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $energy ) . '</td>';
            echo '<td>' . esc_html( $hhi ) . '</td>';
            echo '<td>' . esc_html( $maritime ) . '</td>';
            echo '<td style="color:' . $dqi_color . ';" title="Data vintage: ' . esc_attr( $vintage ) . '"><strong>' . esc_html( $dqi_display ) . '</strong></td>';
            echo '<td style="color:' . $cov_style . ';">' . esc_html( $cov ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        // Least Vulnerable
        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📊 10 Least Vulnerable Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Rank</th><th>Country</th><th>Vulnerability Score</th><th>Energy</th><th>HHI</th><th>Maritime</th><th>DQI</th><th>Coverage</th>';
        echo '</tr></thead><tbody>';
        foreach ( $least_vulnerable as $iso3 => $row ) {
            $rank = $row['rank_display']['string_format'] ?? '—';
            $energy = $row['energy_dependency_percentile'] ?? '—';
            $hhi = $row['supplier_concentration_percentile'] ?? '—';
            $maritime = $row['maritime_vulnerability_percentile'] ?? '—';
            $cov = $row['coverage'] ?? 'partial';
            $cov_style = $cov === 'full' ? '#2e7d32' : '#b26a00';
            
            $dqi = isset( $row['composite_dqi'] ) ? $row['composite_dqi'] : null;
            if ( $dqi !== null ) {
                $dqi_display = round( $dqi ) . '%';
                $dqi_color = $dqi >= 70 ? '#2e7d32' : ( $dqi >= 40 ? '#f0ad4e' : '#d63638' );
            } else {
                $dqi_display = '—';
                $dqi_color = '#999';
            }
            
            $vintage = isset( $row['vintage_summary'] ) ? $row['vintage_summary'] : 'No data';
            
            echo '<tr>';
            echo '<td><strong>' . esc_html( $rank ) . '</strong></td>';
            echo '<td><strong>' . esc_html( $row['name'] ) . '</strong></td>';
            echo '<td>' . esc_html( $row['sivi_structural'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $energy ) . '</td>';
            echo '<td>' . esc_html( $hhi ) . '</td>';
            echo '<td>' . esc_html( $maritime ) . '</td>';
            echo '<td style="color:' . $dqi_color . ';" title="Data vintage: ' . esc_attr( $vintage ) . '"><strong>' . esc_html( $dqi_display ) . '</strong></td>';
            echo '<td style="color:' . $cov_style . ';">' . esc_html( $cov ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        // Excluded
        if ( ! empty( $existing['excluded_detail'] ) ) {
            echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
            echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">🚫 Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</summary>';
            echo '<div style="padding:15px; background:#fff;">';
            echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
            foreach ( $existing['excluded_detail'] as $iso3 => $reason ) {
                echo '<tr><td>' . esc_html( $reason['name'] ?? $iso3 ) . '</td><td>' . esc_html( $reason['reason'] ?? wp_json_encode( $reason ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div></details>';
        }

        // Raw JSON
        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📄 Raw JSON Output</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) ) . '</textarea>';
        echo '</div></details>';
        echo '</div>';
    }

    echo '</div>'; // .wrap
}


// ============================================================================
// 16. HISTORICAL BACKFILL ENGINE (Phase 5c)
// ============================================================================

/**
 * Build a historical SIVI snapshot for a specific year.
 * Does NOT use production caches – calls year‑specific fetchers directly.
 *
 * @param int $year Target year (e.g., 2021).
 * @return array ['success'=>bool, 'countries'=>int, 'error'=>string|null]
 */
function sivi_build_historical_snapshot( $year ) {
    if ( ! function_exists( 'blomstra_fetch_eia_for_year' ) ) {
        return array( 'success' => false, 'countries' => 0, 'error' => 'Year-specific fetchers not available.' );
    }

    $iso3_list = array_keys( sivi_get_global_country_list() );

    // 1. Fetch raw pillar data for the year
    $eia_raw = blomstra_fetch_eia_for_year( $year, $iso3_list );
    $hhi_raw = blomstra_fetch_hhi_for_year( $year, $iso3_list );
    $maritime_raw = blomstra_fetch_maritime_for_year( $year, $iso3_list );

    // 2. Aggregate energy dependency (reuse existing function)
    $energy_aggregated = sivi_eia_aggregate_energy_dependency(
        $iso3_list,
        $eia_raw['consumption'],
        $eia_raw['production']
    );

    // 3. Prepare raw values for percentiles
    $energy_raw_values = array();
    foreach ( $energy_aggregated as $iso3 => $data ) {
        if ( isset( $data['value'] ) && is_numeric( $data['value'] ) ) {
            $energy_raw_values[ $iso3 ] = (float) $data['value'];
        }
    }

    $hhi_raw_values = array();
    foreach ( $hhi_raw as $iso3 => $data ) {
        if ( isset( $data['value'] ) ) {
            $hhi_raw_values[ $iso3 ] = (float) $data['value'];
        }
    }

    $maritime_raw_values = array();
    foreach ( $maritime_raw as $iso3 => $data ) {
        if ( isset( $data['value'] ) ) {
            $maritime_raw_values[ $iso3 ] = (float) $data['value'];
        }
    }

    // 4. Compute percentiles (only among countries with data for this year)
    $weights = sivi_get_pillar_weights();
    $energy_pct = ! empty( $energy_raw_values ) ? sivi_compute_percentile_ranks( $energy_raw_values, $weights['energy']['winsorize']['energy_dependency'] ?? 0.0 ) : array();
    $hhi_pct    = ! empty( $hhi_raw_values )    ? sivi_compute_percentile_ranks( $hhi_raw_values, $weights['hhi']['winsorize']['supplier_concentration'] ?? 0.0 ) : array();

    // Maritime: invert connectivity to vulnerability
    $maritime_connectivity_pct = ! empty( $maritime_raw_values ) ? sivi_compute_percentile_ranks( $maritime_raw_values, $weights['maritime']['winsorize']['maritime_connectivity'] ?? 0.01 ) : array();
    $maritime_vulnerability_pct = array();
    foreach ( $maritime_connectivity_pct as $iso3 => $pct ) {
        $maritime_vulnerability_pct[ $iso3 ] = round( 100 - $pct, 2 );
    }

    // 5. Build composite (same logic as live builder)
    $composite_weights = sivi_get_composite_weights();
    $all_pillars = array( 'energy', 'hhi', 'maritime' );
    $composite_scores = array();
    $excluded = array();
    $coverage_data = array();

    foreach ( $iso3_list as $iso3 ) {
        $present = array();
        if ( isset( $energy_pct[ $iso3 ] ) ) {
            $present['energy'] = array( 'value' => $energy_pct[ $iso3 ], 'weight' => $composite_weights['energy'] );
        }
        if ( isset( $hhi_pct[ $iso3 ] ) ) {
            $present['hhi'] = array( 'value' => $hhi_pct[ $iso3 ], 'weight' => $composite_weights['hhi'] );
        }
        if ( isset( $maritime_vulnerability_pct[ $iso3 ] ) ) {
            $present['maritime'] = array( 'value' => $maritime_vulnerability_pct[ $iso3 ], 'weight' => $composite_weights['maritime'] );
        }

        $pillars_present = count( $present );
        $missing_pillars = array_values( array_diff( $all_pillars, array_keys( $present ) ) );

        if ( $pillars_present < SIVI_MIN_PILLARS_REQUIRED ) {
            $excluded[ $iso3 ] = array(
                'reason' => 'Fewer than ' . SIVI_MIN_PILLARS_REQUIRED . ' pillars have real data — not scored.',
                'pillars_present' => $pillars_present,
                'pillars_missing' => $missing_pillars,
                'name'   => sivi_get_global_country_list()[ $iso3 ] ?? $iso3,
            );
            continue;
        }

        $score_sum = 0;
        $weight_sum = 0;
        foreach ( $present as $pillar ) {
            $score_sum += $pillar['value'] * $pillar['weight'];
            $weight_sum += $pillar['weight'];
        }
        $composite_score = round( $score_sum / $weight_sum, 1 );
        $coverage_type = ( $pillars_present >= count( $all_pillars ) ) ? 'full' : 'partial';

        $composite_scores[ $iso3 ] = $composite_score;
        $coverage_data[ $iso3 ] = array(
            'composite_score' => $composite_score,
            'coverage_type'   => $coverage_type,
            'pillars_used'    => $pillars_present,
            'pillars_missing' => $missing_pillars,
            'energy_dependency_percentile' => $present['energy']['value'] ?? null,
            'supplier_concentration_percentile' => $present['hhi']['value'] ?? null,
            'maritime_vulnerability_percentile' => $present['maritime']['value'] ?? null,
            'energy_dependency_raw' => $energy_raw_values[ $iso3 ] ?? null,
            'supplier_concentration_raw' => $hhi_raw_values[ $iso3 ] ?? null,
            'maritime_connectivity_raw' => $maritime_raw_values[ $iso3 ] ?? null,
            'is_landlocked' => sivi_is_landlocked( $iso3 ),
        );
    }

    // 6. Rank assignment (same as live builder)
    $full_composites = array();
    foreach ( $coverage_data as $iso3 => $row ) {
        if ( $row['coverage_type'] === 'full' ) {
            $full_composites[] = $row['composite_score'];
        }
    }
    sort( $full_composites );

    // Assign definitive ranks for full-coverage countries
    foreach ( $coverage_data as $iso3 => &$row ) {
        if ( $row['coverage_type'] === 'full' ) {
            $rank = 1;
            foreach ( $full_composites as $full_score ) {
                if ( $row['composite_score'] < $full_score ) {
                    $rank++;
                }
            }
            $row['rank'] = $rank;
            if ( function_exists( 'blomstra_build_full_rank_display' ) ) {
                $row['rank_display'] = blomstra_build_full_rank_display( $rank );
            }
        }
    }
    unset( $row );

    // Partial ranks (projection) – reuse same logic as live builder if functions exist
    // This relies on SIVI_MIN_PILLARS_REQUIRED = 2 (out of 3), so partial always means exactly one pillar missing.
    if ( function_exists( 'blomstra_project_partial_rank_composite' ) && function_exists( 'blomstra_build_partial_rank_display' ) ) {
        $pillar_value_key = array(
            'energy'   => 'energy_dependency_percentile',
            'hhi'      => 'supplier_concentration_percentile',
            'maritime' => 'maritime_vulnerability_percentile',
        );
        foreach ( $coverage_data as $iso3 => &$row ) {
            if ( $row['coverage_type'] !== 'partial' ) {
                continue;
            }
            $missing_pillar = $row['pillars_missing'][0] ?? null;
            if ( $missing_pillar === null || ! isset( $composite_weights[ $missing_pillar ] ) ) {
                continue;
            }
            $known_pillars = array();
            foreach ( $composite_weights as $pname => $pweight ) {
                if ( $pname === $missing_pillar ) {
                    continue;
                }
                $known_pillars[ $pname ] = $row[ $pillar_value_key[ $pname ] ] ?? 0;
            }
            $injected_values_by_point = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $point ) {
                $injected_values_by_point[ $point ] = $point;
            }
            $hypothetical_composites = blomstra_project_partial_rank_composite(
                $known_pillars,
                $missing_pillar,
                $injected_values_by_point,
                $composite_weights
            );
            if ( ! empty( $hypothetical_composites ) ) {
                $ranks_by_injection = array();
                foreach ( $hypothetical_composites as $point => $hyp_composite ) {
                    $rank = 1;
                    foreach ( $full_composites as $full_score ) {
                        if ( $hyp_composite < $full_score ) {
                            $rank++;
                        }
                    }
                    $ranks_by_injection[ $point ] = $rank;
                }
                $row['rank'] = null;
                $row['rank_display'] = blomstra_build_partial_rank_display( $ranks_by_injection );
            }
        }
        unset( $row );
    }

    // 7. Add DQI and vintage metadata (using snapshot year as current_year)
    $max_lags = array(
        'energy'   => defined( 'SIVI_MAX_LAG_ENERGY' ) ? SIVI_MAX_LAG_ENERGY : 3,
        'hhi'      => defined( 'SIVI_MAX_LAG_HHI' ) ? SIVI_MAX_LAG_HHI : 3,
        'maritime' => defined( 'SIVI_MAX_LAG_MARITIME' ) ? SIVI_MAX_LAG_MARITIME : 5,
    );

    // Build pillar data arrays for DQI
    $pillar_data_by_country = array();
    foreach ( $iso3_list as $iso3 ) {
        // Energy
        $energy_year = null;
        if ( isset( $energy_aggregated[ $iso3 ]['year'] ) ) {
            $energy_year = (int) $energy_aggregated[ $iso3 ]['year'];
        }
        // HHI
        $hhi_year = null;
        if ( isset( $hhi_raw[ $iso3 ]['year'] ) ) {
            $hhi_year = (int) $hhi_raw[ $iso3 ]['year'];
        }
        // Maritime
        $maritime_year = null;
        if ( isset( $maritime_raw[ $iso3 ]['year'] ) ) {
            $maritime_year = (int) $maritime_raw[ $iso3 ]['year'];
        }

        $dqi_energy = function_exists('blomstra_compute_dqi') ? blomstra_compute_dqi( $energy_year, $year, $max_lags['energy'] ) : null;
        $dqi_hhi    = function_exists('blomstra_compute_dqi') ? blomstra_compute_dqi( $hhi_year, $year, $max_lags['hhi'] ) : null;
        $dqi_maritime = function_exists('blomstra_compute_dqi') ? blomstra_compute_dqi( $maritime_year, $year, $max_lags['maritime'] ) : null;

        $composite_dqi = function_exists('blomstra_compute_composite_dqi') ? blomstra_compute_composite_dqi( array(
            array( 'dqi' => $dqi_energy, 'weight' => $composite_weights['energy'] ),
            array( 'dqi' => $dqi_hhi,    'weight' => $composite_weights['hhi'] ),
            array( 'dqi' => $dqi_maritime, 'weight' => $composite_weights['maritime'] ),
        ) ) : null;

        // Build vintage summary
        $vintage_parts = array();
        if ( $energy_year ) {
            $vintage_parts[] = 'Energy: ' . $energy_year;
        }
        if ( $hhi_year ) {
            $vintage_parts[] = 'HHI: ' . $hhi_year;
        }
        if ( $maritime_year ) {
            $vintage_parts[] = 'Maritime: ' . $maritime_year;
        }

        $pillar_data_by_country[ $iso3 ] = array(
            'data_year_energy'   => $energy_year,
            'data_year_hhi'      => $hhi_year,
            'data_year_maritime' => $maritime_year,
            'dqi_energy'         => $dqi_energy,
            'dqi_hhi'            => $dqi_hhi,
            'dqi_maritime'       => $dqi_maritime,
            'composite_dqi'      => $composite_dqi,
            'vintage_summary'    => ! empty( $vintage_parts ) ? implode( ', ', $vintage_parts ) : 'No data',
        );
    }

    // Merge DQI data into coverage_data
    foreach ( $coverage_data as $iso3 => &$row ) {
        if ( isset( $pillar_data_by_country[ $iso3 ] ) ) {
            $row = array_merge( $row, $pillar_data_by_country[ $iso3 ] );
        }
    }
    unset( $row );

    // 8. Prepare data for snapshot saver (same structure as live builder)
    $snapshot_countries = array();
    foreach ( $coverage_data as $iso3 => $row ) {
        $snapshot_countries[ $iso3 ] = array(
            'composite_score' => $row['composite_score'] ?? null,
            'rank'            => $row['rank'] ?? null,
            'coverage_type'   => $row['coverage_type'] ?? 'partial',
            'pillars' => array(
                'energy'   => $row['energy_dependency_percentile'] ?? null,
                'hhi'      => $row['supplier_concentration_percentile'] ?? null,
                'maritime' => $row['maritime_vulnerability_percentile'] ?? null,
            ),
            'data_year_energy'   => $row['data_year_energy'] ?? null,
            'data_year_hhi'      => $row['data_year_hhi'] ?? null,
            'data_year_maritime' => $row['data_year_maritime'] ?? null,
            'dqi_energy'         => $row['dqi_energy'] ?? null,
            'dqi_hhi'            => $row['dqi_hhi'] ?? null,
            'dqi_maritime'       => $row['dqi_maritime'] ?? null,
            'composite_dqi'      => $row['composite_dqi'] ?? null,
            'vintage_summary'    => $row['vintage_summary'] ?? null,
        );
    }

    // 9. Save snapshot
    if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
        $saved = blomstra_index_snapshot_save( 'sivi', $snapshot_countries, $year . '-01' );
        return array(
            'success'   => $saved > 0,
            'countries' => $saved,
            'error'     => null,
        );
    } else {
        return array(
            'success' => false,
            'countries' => 0,
            'error' => 'blomstra_index_snapshot_save() not available.',
        );
    }
}


// ─── BACKFILL STATUS TRACKING ─────────────────────────────────────

function sivi_get_backfill_status() {
    $range = blomstra_get_index_backfill_range('sivi');
    $default = array();
    for ( $year = $range['start']; $year <= $range['end']; $year++ ) {
        $default[ $year ] = array( 'status' => 'not_started', 'countries' => 0, 'last_attempt' => null, 'error' => null );
    }
    $status = get_option( 'sivi_backfill_status', $default );
    // Ensure all years exist
    foreach ( $default as $year => $val ) {
        if ( ! isset( $status[ $year ] ) ) {
            $status[ $year ] = $val;
        }
    }
    return $status;
}

function sivi_update_backfill_status( $year, $status, $countries = null, $error = null ) {
    $current = sivi_get_backfill_status();
    $current[ $year ] = array(
        'status'        => $status, // 'success', 'partial', 'failed', 'scheduled', 'not_started'
        'countries'     => $countries !== null ? (int) $countries : ( $current[ $year ]['countries'] ?? 0 ),
        'last_attempt'  => current_time( 'mysql' ),
        'error'         => $error,
    );
    $current['last_full_run'] = current_time( 'mysql' );
    update_option( 'sivi_backfill_status', $current, false );
}

/**
 * Check if all backfill years are in a terminal state, then clear the lock.
 */
function sivi_backfill_check_completion() {
    $status = sivi_get_backfill_status();
    $range = blomstra_get_index_backfill_range('sivi');
    $terminal_states = array( 'success', 'partial', 'failed' );
    $all_terminal = true;
    for ( $year = $range['start']; $year <= $range['end']; $year++ ) {
        if ( ! in_array( $status[ $year ]['status'], $terminal_states, true ) ) {
            $all_terminal = false;
            break;
        }
    }
    if ( $all_terminal ) {
        delete_transient( 'sivi_backfill_lock' );
        error_log( 'SIVI backfill completed – lock cleared.' );
    }
}

// ─── BACKFILL CRON CALLBACK ──────────────────────────────────────

add_action( 'sivi_backfill_year_cron', 'sivi_backfill_year_cron_callback', 10, 1 );
function sivi_backfill_year_cron_callback( $year ) {
    // Check if lock exists; if not, skip (should not happen)
    if ( ! get_transient( 'sivi_backfill_lock' ) ) {
        error_log( "SIVI backfill cron for $year called but lock missing – skipping." );
        return;
    }

    $result = sivi_build_historical_snapshot( $year );
    if ( $result['success'] ) {
        $status = 'success';
        $error = null;
    } else {
        $status = 'failed';
        $error = $result['error'] ?? 'Unknown error';
    }
    sivi_update_backfill_status( $year, $status, $result['countries'], $error );
    sivi_backfill_check_completion();
}

// ─── ADMIN HANDLERS FOR BACKFILL RANGE ────────────────────────────

add_action( 'admin_init', function () {
    if ( isset( $_POST['sivi_save_backfill_range'] ) && check_admin_referer( 'sivi_backfill_range_action', 'sivi_backfill_range_nonce' ) ) {
        $start = (int) $_POST['sivi_backfill_start'];
        $end   = (int) $_POST['sivi_backfill_end'];
        if ( $start > 0 && $end > 0 && $start <= $end && $start >= SIVI_BACKFILL_MIN_YEAR ) {
            update_option( 'sivi_backfill_range_start', $start, false );
            update_option( 'sivi_backfill_range_end', $end, false );
            echo '<div class="notice notice-success"><p>✅ SIVI backfill range updated to ' . $start . '–' . $end . '.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Invalid range. Start must be ≤ End and at least ' . SIVI_BACKFILL_MIN_YEAR . '.</p></div>';
        }
    }
} );
