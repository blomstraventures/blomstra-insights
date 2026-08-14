/**
 * Sovereign Infrastructure Vulnerability Index (SIVI) — v2.0.0
 *
 * @package     Blomstra\Insights\Indices\SIVI
 * @since       1.0.0
 * @version     2.0.0
 * @author      Blomstra Insights Team
 * @license     Proprietary
 *
 * ============================================================================
 * CHANGELOG (v2.0.0)
 * ============================================================================
 * - Full architectural migration to SERI pattern (BMS‑1.0.0 conformance)
 * - All constants, functions, and options renamed from cii_/CII_ to sivi_/SIVI_
 * - Per‑pillar storage now uses a two‑key structure: 'data' (values) + 'sources' (provenance)
 * - Composite builder supports scenario‑safe custom weights and sensitivity testing
 * - Asynchronous refresh hooks for each pillar (Energy, HHI, Maritime)
 * - Cron safeguards: auto‑rollback on data loss, status tracking via blomstra_update_cron_status()
 * - Data quality & measurement flags are now part of the API output
 * - Admin UI restructured to match SERI layout (cards, coverage breakdown, scenario builder)
 * - REST endpoint updated to new canonical slug (old slug kept for backward compatibility)
 * ============================================================================
 *
 * @see https://github.com/blomstraventures/blomstra-insights/blob/main/src/shared/blomstra-index-utilities.php
 *      Shared utilities required for this index to function.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── SHARED UTILITIES INCLUDE ────────────────────────────────────────────
// NOTE: In WPCode or WordPress, ensure that the Blomstra Index Utilities
// snippet is loaded BEFORE this file. The following line is commented out
// because the file is typically loaded via a separate snippet.
// require_once __DIR__ . '/../../shared/blomstra-index-utilities.php';


// ============================================================================
// 1.  CONSTANTS
// ============================================================================

/** @var string SIVI version number (major.minor.patch). */
define( 'SIVI_VERSION', '2.1.0' );

/** @var string Main option key where the composite index is stored. */
define( 'SIVI_OPTION_KEY', 'sivi_composite_index' );

/** @var string Weekly cron hook name. */
define( 'SIVI_CRON_HOOK', 'sivi_weekly_refresh' );

/** @var string Daily cron hook name (for more frequent refreshes). */
define( 'SIVI_DAILY_CRON_HOOK', 'sivi_daily_cron' );

/** @var string Async refresh hook (triggers all pillars at once). */
define( 'SIVI_REFRESH_HOOK', 'sivi_async_refresh' );

/**
 * Minimum number of pillars required to be scored.
 * SIVI has 3 pillars; a country with 2/3 is scored as "partial".
 */
define( 'SIVI_MIN_PILLARS_REQUIRED', 2 );

// ─── Pillar storage keys (data + sources) ────────────────────────────────

/** @var string Energy pillar data/sources option key. */
define( 'SIVI_ENERGY_KEY', 'sivi_energy_data' );

/** @var string HHI pillar data/sources option key. */
define( 'SIVI_HHI_KEY', 'sivi_hhi_data' );

/** @var string Maritime pillar data/sources option key. */
define( 'SIVI_MARITIME_KEY', 'sivi_maritime_data' );

// ─── Pillar meta keys (last_fetched timestamps) ──────────────────────────

/** @var string Energy pillar meta option key. */
define( 'SIVI_ENERGY_META_KEY', 'sivi_energy_meta' );

/** @var string HHI pillar meta option key. */
define( 'SIVI_HHI_META_KEY', 'sivi_hhi_meta' );

/** @var string Maritime pillar meta option key. */
define( 'SIVI_MARITIME_META_KEY', 'sivi_maritime_meta' );

// ─── API / Batch constants ───────────────────────────────────────────────

/** @var int Number of countries to request per EIA API chunk. */
if ( ! defined( 'SIVI_EIA_CHUNK_SIZE' ) ) {
    define( 'SIVI_EIA_CHUNK_SIZE', 25 );
}

/** @var int Number of reporters to request per Comtrade API chunk. */
if ( ! defined( 'SIVI_HHI_CHUNK_SIZE' ) ) {
    define( 'SIVI_HHI_CHUNK_SIZE', 50 );
}

/** @var int TTL for the build lock (prevents concurrent builds). */
if ( ! defined( 'SIVI_LOCK_TTL' ) ) {
    define( 'SIVI_LOCK_TTL', 5 * MINUTE_IN_SECONDS );
}

/** @var int How "fresh" a pillar should be (10 days). */
if ( ! defined( 'SIVI_FRESHNESS_PILLAR' ) ) {
    define( 'SIVI_FRESHNESS_PILLAR', 10 * DAY_IN_SECONDS );
}

/** @var int Threshold for wp‑cron health alerts (30 hours). */
if ( ! defined( 'SIVI_WPCRON_ALERT_THRESHOLD' ) ) {
    define( 'SIVI_WPCRON_ALERT_THRESHOLD', 30 * HOUR_IN_SECONDS );
}

/** @var string Sentinel value returned when Comtrade quota is exhausted. */
if ( ! defined( 'SIVI_COMTRADE_QUOTA_EXHAUSTED' ) ) {
    define( 'SIVI_COMTRADE_QUOTA_EXHAUSTED', '__SIVI_QUOTA_EXHAUSTED__' );
}

/**
 * Fallback list of landlocked ISO3 codes (UN‑OHRLLS + developed landlocked states).
 *
 * @see https://www.un.org/ohrlls/content/list-ldcs
 */
if ( ! defined( 'SIVI_LANDLOCKED_ISO3_FALLBACK' ) ) {
    define( 'SIVI_LANDLOCKED_ISO3_FALLBACK', array(
        'AFG', 'AND', 'ARM', 'AUT', 'AZE', 'BLR', 'BTN', 'BOL', 'BWA', 'BFA',
        'BDI', 'CAF', 'TCD', 'CZE', 'ETH', 'SWZ', 'HUN', 'KAZ', 'KGZ', 'LAO',
        'LSO', 'LIE', 'LUX', 'MWI', 'MLI', 'MDA', 'MNG', 'NPL', 'NER', 'MKD',
        'PRY', 'RWA', 'SMR', 'SRB', 'SVK', 'SSD', 'CHE', 'TJK', 'TKM', 'UGA',
        'UZB', 'VAT', 'ZMB', 'ZWE',
    ) );
}


// ============================================================================
// 2.  PILLAR DEFINITIONS & WEIGHTS
// ============================================================================

/**
 * Returns the pillar weight configuration used by the composite builder.
 *
 * This structure defines how each pillar's indicators are weighted internally.
 * For SIVI, each pillar is a single indicator, so the weight is always 100.
 *
 * @since  2.0.0
 * @return array {
 *     @type array $energy    Energy pillar definition.
 *     @type array $hhi       HHI pillar definition.
 *     @type array $maritime  Maritime pillar definition.
 * }
 */
function sivi_get_pillar_weights() {
    return array(
        'energy'   => array(
            'name'         => 'Energy Dependency',
            'indicators'   => array( 'energy_dependency' => 100 ),
            'min_required' => 1,
            'min_weight'   => 100,
            // BMS-1.1.0: genuine extremes for highly energy-dependent
            // small economies are real vulnerability signal, not
            // reporting artifacts — no winsorization.
            'winsorize'    => array( 'energy_dependency' => 0.0 ),
        ),
        'hhi'      => array(
            'name'         => 'Supplier Concentration',
            'indicators'   => array( 'supplier_concentration' => 100 ),
            'min_required' => 1,
            'min_weight'   => 100,
            // BMS-1.1.0: HHI is bounded 0-10000 by construction; a value
            // of 10000 (single supplier) is genuine and meaningful, not
            // an artifact — no winsorization.
            'winsorize'    => array( 'supplier_concentration' => 0.0 ),
        ),
        'maritime' => array(
            'name'         => 'Maritime Connectivity',
            'indicators'   => array( 'maritime_connectivity' => 100 ),
            'min_required' => 1,
            'min_weight'   => 100,
            // BMS-1.1.0: LSCI-based connectivity data for very small or
            // remote nations can be thin/erratic — a light 1% guards
            // against a single unreliable data point dominating the
            // percentile ranking.
            'winsorize'    => array( 'maritime_connectivity' => 0.01 ),
        ),
    );
}

/**
 * Returns the pillar definitions used for validation and metadata.
 *
 * This is the source‑of‑truth for what indicators each pillar contains and
 * where they come from. It is used by blomstra_validate_pillar_thresholds().
 *
 * @since  2.0.0
 * @return array Same structure as sivi_get_pillar_weights(), but with
 *               indicator metadata (name, source) instead of weights.
 */
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

/**
 * Returns the default composite weights (equal‑weighted).
 *
 * These values are used when no custom weights are provided.
 * They must sum to 100.
 *
 * @since  2.0.0
 * @return array Associative array with keys 'energy', 'hhi', 'maritime'.
 */
function sivi_get_composite_weights() {
    return array(
        'energy'   => 33.3333,
        'hhi'      => 33.3333,
        'maritime' => 33.3334,
    );
}


// ============================================================================
// 3.  LANDLOCKED CHECK
// ============================================================================

/**
 * Determines whether a country is landlocked.
 *
 * First attempts to use the shared `blomstra_is_landlocked()` function;
 * falls back to a hardcoded list if the shared utility isn't available.
 *
 * @since  1.0.0
 * @param  string $iso3  Three‑letter ISO country code.
 * @return bool          True if landlocked, false otherwise.
 */
function sivi_is_landlocked( $iso3 ) {
    if ( function_exists( 'blomstra_is_landlocked' ) ) {
        return blomstra_is_landlocked( $iso3 );
    }
    return in_array( $iso3, SIVI_LANDLOCKED_ISO3_FALLBACK, true );
}


// ============================================================================
// 4.  COUNTRY LIST WRAPPERS
// ============================================================================

/**
 * Retrieves the global list of countries (ISO3 → name).
 *
 * Uses the shared `blomstra_get_global_country_list()` if available;
 * otherwise falls back to a direct World Bank API call.
 *
 * @since  1.0.0
 * @return array Associative array [ISO3 => country_name].
 */
function sivi_get_global_country_list() {
    if ( function_exists( 'blomstra_get_global_country_list' ) ) {
        $list = blomstra_get_global_country_list();
        if ( ! empty( $list ) ) {
            return $list;
        }
    }
    return sivi_get_global_country_list_fallback();
}

/**
 * Fallback country list fetcher (direct World Bank API call).
 *
 * @since  1.0.0
 * @return array [ISO3 => country_name].
 */
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
// 5.  COMTRADE REPORTER MAP
// ============================================================================

/**
 * Retrieves a mapping of ISO3 → Comtrade reporter code.
 *
 * Uses the shared `blomstra_get_comtrade_reporter_map()` if available;
 * otherwise falls back to a direct fetch from Comtrade's JSON reference.
 *
 * @since  1.0.0
 * @return array [ISO3 => reporter_code].
 */
function sivi_hhi_reporter_map() {
    if ( function_exists( 'blomstra_get_comtrade_reporter_map' ) ) {
        $map = blomstra_get_comtrade_reporter_map();
        if ( ! empty( $map ) ) {
            return $map;
        }
    }
    return sivi_hhi_reporter_map_fallback();
}

/**
 * Fallback reporter map fetcher (direct Comtrade JSON endpoint).
 *
 * @since  1.0.0
 * @return array [ISO3 => reporter_code].
 */
function sivi_hhi_reporter_map_fallback() {
    $url = 'https://comtradeapi.un.org/files/v1/app/reference/Reporters.json';
    $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        error_log( 'SIVI reporter-map fallback fetch failed: ' . $response->get_error_message() );
        return array();
    }
    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    $reporters = $decoded['results'] ?? null;
    if ( ! is_array( $reporters ) ) {
        return array();
    }
    $map = array();
    foreach ( $reporters as $reporter ) {
        if ( ! empty( $reporter['isGroup'] ) ) {
            continue;
        }
        $iso3 = isset( $reporter['reporterCodeIsoAlpha3'] ) ? trim( $reporter['reporterCodeIsoAlpha3'] ) : '';
        $code = isset( $reporter['reporterCode'] ) ? (int) $reporter['reporterCode'] : null;
        if ( $iso3 === '' || $code === null ) {
            continue;
        }
        if ( isset( $map[ $iso3 ] ) && ! empty( $reporter['entryExpiredDate'] ) ) {
            continue;
        }
        $map[ $iso3 ] = $code;
    }
    return $map;
}


// ============================================================================
// 6.  TEST COUNTRY LIST (for debugging)
// ============================================================================


// ============================================================================
// 7.  ENERGY PILLAR (EIA)
// ============================================================================

/**
 * Fetches EIA activity data for a batch of countries.
 *
 * This function handles chunked requests to the EIA API, with retry logic
 * for rate limits and network errors. It logs each call via sivi_log_eia_call().
 *
 * @since  1.0.0
 * @param  array  $country_codes  List of ISO3 codes.
 * @param  string $activity_id    EIA activity ID ('1' for production, '2' for consumption).
 * @param  string $product_id     EIA product ID (e.g., '4411' for coal).
 * @param  int    $attempt        Current attempt number (for retries).
 * @return array {
 *     @type string $status  'ok' or 'failed'.
 *     @type array  $rows    Array of raw EIA data rows.
 *     @type string $error   Error message if status is 'failed'.
 * }
 */
function sivi_eia_fetch_activity_batch_fallback( $country_codes, $activity_id, $product_id, $attempt = 1 ) {
    if ( ! defined( 'EIA_API_KEY' ) || EIA_API_KEY === '' ) {
        return array( 'status' => 'failed', 'rows' => array(), 'error' => 'API key missing' );
    }

    $scalar_args = array(
        'api_key'              => EIA_API_KEY,
        'facets[activityId][]' => $activity_id,
        'facets[productId][]'  => $product_id,
        'facets[unit][]'       => 'QBTU',
        'frequency'            => 'annual',
        'data[]'               => 'value',
        'sort[0][column]'      => 'period',
        'sort[0][direction]'   => 'desc',
        'length'               => min( 5000, count( $country_codes ) * 5 ),
    );

    $query_pairs = array();
    foreach ( $scalar_args as $k => $v ) {
        $query_pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
    }
    foreach ( $country_codes as $cc ) {
        $query_pairs[] = rawurlencode( 'facets[countryRegionId][]' ) . '=' . rawurlencode( $cc );
    }
    $url = EIA_BASE_URL . '?' . implode( '&', $query_pairs );

    $response = wp_remote_get( $url, array( 'timeout' => 45 ) );
    $chunk_label = count( $country_codes ) . ' countries (' . implode( ',', array_slice( $country_codes, 0, 3 ) ) . ( count( $country_codes ) > 3 ? '...' : '' ) . ')';

    $should_retry = false;
    $fail_reason  = '';
    if ( is_wp_error( $response ) ) {
        $fail_reason  = 'network error: ' . $response->get_error_message();
        $should_retry = true;
    } else {
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 429 ) {
            $fail_reason  = 'HTTP 429 rate-limited';
            $should_retry = true;
        } elseif ( $code !== 200 ) {
            $fail_reason  = 'HTTP ' . $code . ' — body: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 );
            $should_retry = ( $code >= 500 );
        }
    }
    if ( $fail_reason !== '' ) {
        error_log( 'SIVI EIA batch fetch FAILED (' . $chunk_label . ', attempt ' . $attempt . '): ' . $fail_reason );
        sivi_log_eia_call( $chunk_label, $activity_id, $product_id, $should_retry ? 'rate_limited_or_network' : 'http_error', $fail_reason . ' (attempt ' . $attempt . ')' );
        if ( $should_retry && $attempt < 3 ) {
            sleep( 2 * $attempt );
            return sivi_eia_fetch_activity_batch_fallback( $country_codes, $activity_id, $product_id, $attempt + 1 );
        }
        return array( 'status' => 'failed', 'rows' => array(), 'error' => $fail_reason );
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $rows = $body['response']['data'] ?? array();

    $countries_with_data = array();
    foreach ( $rows as $row ) {
        $cc = $row['countryRegionId'] ?? null;
        if ( $cc !== null ) {
            $countries_with_data[ $cc ] = true;
        }
    }
    $missing_from_chunk = array_values( array_diff( $country_codes, array_keys( $countries_with_data ) ) );

    if ( empty( $rows ) ) {
        sivi_log_eia_call( $chunk_label, $activity_id, $product_id, 'empty', 'HTTP 200, valid shape, zero rows for this entire chunk' );
    } elseif ( ! empty( $missing_from_chunk ) ) {
        sivi_log_eia_call( $chunk_label, $activity_id, $product_id, 'partial', count( $rows ) . ' rows, but ' . count( $missing_from_chunk ) . '/' . count( $country_codes ) . ' requested countries had no row in this chunk: ' . implode( ',', $missing_from_chunk ) );
    } else {
        sivi_log_eia_call( $chunk_label, $activity_id, $product_id, 'ok', count( $rows ) . ' rows, all ' . count( $country_codes ) . ' requested countries represented' );
    }

    return array( 'status' => 'ok', 'rows' => $rows, 'error' => null );
}

/**
 * Picks the latest year's value for each country from a set of EIA rows.
 *
 * @since  1.0.0
 * @param  array $rows  Array of raw EIA data rows.
 * @return array [ISO3 => ['value' => float, 'period' => string]].
 */
function sivi_eia_pick_latest_per_country_fallback( $rows ) {
    $latest = array();
    foreach ( $rows as $row ) {
        $cc     = $row['countryRegionId'] ?? null;
        $val    = $row['value'] ?? null;
        $period = $row['period'] ?? null;
        if ( ! $cc || $val === null || $period === null ) {
            continue;
        }
        if ( ! isset( $latest[ $cc ] ) || $period > $latest[ $cc ]['period'] ) {
            $latest[ $cc ] = array( 'value' => (float) $val, 'period' => $period );
        }
    }
    return $latest;
}

/**
 * Aggregates energy dependency from consumption and production data across fuels.
 *
 * The dependency for each fuel is (consumption - production) / consumption * 100.
 * The final pillar value is a weighted average by consumption share.
 *
 * @since  1.0.0
 * @param  array $iso3_list          List of ISO3 codes.
 * @param  array $consumption_by_fuel Nested array [product_id][ISO3] => consumption.
 * @param  array $production_by_fuel  Nested array [product_id][ISO3] => ['value' => float, 'status' => 'ok'|'confirmed_zero'].
 * @return array [ISO3 => ['value' => float|null, 'source' => string, 'note' => string, 'fuels' => array]].
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
        foreach ( $fuel_ids as $product_id => $fuel_name ) {
            if ( ! isset( $consumption_by_fuel[ $product_id ][ $iso3 ] ) ) {
                continue;
            }
            $consumption = $consumption_by_fuel[ $product_id ][ $iso3 ];

            if ( ! isset( $production_by_fuel[ $product_id ][ $iso3 ] ) ) {
                continue;
            }
            $prod_entry = $production_by_fuel[ $product_id ][ $iso3 ];
            $production = $prod_entry['value'];
            $prod_note  = ( $prod_entry['status'] === 'confirmed_zero' ) ? 'confirmed zero production' : 'real production value';

            $fuel_dep = ( ( $consumption - $production ) / $consumption ) * 100;
            $fuels[ $product_id ] = array(
                'name'        => $fuel_name,
                'consumption' => $consumption,
                'production'  => $production,
                'dependency'  => round( $fuel_dep, 2 ),
                'note'        => $prod_note,
            );
        }

        if ( empty( $fuels ) ) {
            $out[ $iso3 ] = array( 'value' => null, 'source' => 'EIA', 'note' => 'No fuel had usable consumption data', 'fuels' => array() );
            continue;
        }

        $total_consumption = 0;
        foreach ( $fuels as $f ) {
            $total_consumption += $f['consumption'];
        }
        if ( $total_consumption <= 0 ) {
            $out[ $iso3 ] = array( 'value' => null, 'source' => 'EIA', 'note' => 'Total consumption across fuels is zero', 'fuels' => $fuels );
            continue;
        }

        $weighted_sum     = 0;
        $fuel_names_used  = array();
        foreach ( $fuels as $pid => $f ) {
            $weight = $f['consumption'] / $total_consumption;
            $weighted_sum += $f['dependency'] * $weight;
            $fuel_names_used[] = $f['name'];
        }

        $out[ $iso3 ] = array(
            'value'  => round( $weighted_sum, 2 ),
            'source' => 'EIA (multi-fuel, consumption-weighted, batched: ' . implode( ', ', $fuel_names_used ) . ')',
            'note'   => count( $fuels ) . '/' . count( $fuel_ids ) . ' fuels had usable data',
            'fuels'  => $fuels,
        );
    }
    return $out;
}

/**
 * Computes energy dependency for a list of countries in batches, with checkpoints.
 *
 * This is the core batch processor that loops over all fuel types and fetches
 * both consumption and production data. It uses a checkpoint callback to persist
 * partial results after each fuel type.
 *
 * @since  1.0.0
 * @param  array    $iso3_list          List of ISO3 codes.
 * @param  callable $checkpoint_callback Optional callback for partial persistence.
 * @return array Computed energy dependency per country (same as sivi_eia_aggregate_energy_dependency).
 */
function sivi_compute_energy_dependency_batch_fallback( $iso3_list, $checkpoint_callback = null ) {
    $fuel_ids = array(
        '4411' => 'Coal',
        '4413' => 'Natural gas',
        '4415' => 'Petroleum and other liquids',
        '4417' => 'Nuclear',
        '4418' => 'Renewables and other',
    );
    $consumption_by_fuel = array();
    $production_by_fuel  = array();

    foreach ( $fuel_ids as $product_id => $fuel_name ) {
        $chunks = array_chunk( $iso3_list, SIVI_EIA_CHUNK_SIZE );

        $consumption_by_fuel[ $product_id ] = array();
        foreach ( $chunks as $chunk ) {
            $result = sivi_eia_fetch_activity_batch_fallback( $chunk, '2', $product_id );
            if ( $result['status'] === 'ok' ) {
                $latest = sivi_eia_pick_latest_per_country_fallback( $result['rows'] );
                foreach ( $latest as $iso3 => $row ) {
                    if ( $row['value'] != 0.0 ) {
                        $consumption_by_fuel[ $product_id ][ $iso3 ] = $row['value'];
                    }
                }
            }
            usleep( 200000 );
        }

        $production_by_fuel[ $product_id ] = array();
        foreach ( $chunks as $chunk ) {
            $result = sivi_eia_fetch_activity_batch_fallback( $chunk, '1', $product_id );
            if ( $result['status'] === 'ok' ) {
                $latest = sivi_eia_pick_latest_per_country_fallback( $result['rows'] );
                foreach ( $chunk as $iso3 ) {
                    if ( isset( $latest[ $iso3 ] ) ) {
                        $production_by_fuel[ $product_id ][ $iso3 ] = array( 'value' => $latest[ $iso3 ]['value'], 'status' => 'ok' );
                    } else {
                        $production_by_fuel[ $product_id ][ $iso3 ] = array( 'value' => 0.0, 'status' => 'confirmed_zero' );
                    }
                }
            }
            usleep( 200000 );
        }

        if ( $checkpoint_callback !== null ) {
            $partial = sivi_eia_aggregate_energy_dependency( $iso3_list, $consumption_by_fuel, $production_by_fuel );
            $checkpoint_callback( $partial );
        }
    }

    return sivi_eia_aggregate_energy_dependency( $iso3_list, $consumption_by_fuel, $production_by_fuel );
}

/**
 * Fallback energy refresh (direct EIA API calls, no central cache).
 *
 * This is the "API Direct" mode. It fetches data directly from EIA and
 * persists it to the sivi_energy_data option with full provenance.
 *
 * @since  1.0.0
 * @param  array|null $countries Optional country list (ISO3 => name).
 * @return array The stored data array (keyed by ISO3).
 */
function sivi_refresh_energy_pillar_fallback( $countries = null ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 600 );
    }
    if ( $countries === null ) {
        $countries = sivi_get_global_country_list();
    }
    $iso3_list = array_keys( $countries );

    $checkpoint = function ( $partial_computed ) use ( $iso3_list ) {
        $existing_now = get_option( SIVI_ENERGY_KEY, array() );
        $existing_data = $existing_now['data'] ?? array();
        $partial_results = array();
        foreach ( $iso3_list as $iso3 ) {
            $c = $partial_computed[ $iso3 ] ?? array( 'value' => null, 'source' => 'EIA', 'note' => 'not yet computed' );
            $partial_results[ $iso3 ] = array(
                'value'        => $c['value'],
                'source'       => $c['source'],
                'note'         => $c['note'] ?? '',
                'last_updated' => current_time( 'mysql' ),
            );
        }
        $merged_data = array_merge( $existing_data, $partial_results );
        update_option( SIVI_ENERGY_KEY, array( 'data' => $merged_data, 'sources' => $existing_now['sources'] ?? array() ), false );
    };

    $computed = sivi_compute_energy_dependency_batch_fallback( $iso3_list, $checkpoint );

    $results = array();
    $sources = array();
    foreach ( $iso3_list as $iso3 ) {
        $c = $computed[ $iso3 ] ?? array( 'value' => null, 'source' => 'EIA', 'note' => 'not returned by batch computation' );
        $results[ $iso3 ] = array(
            'value'        => $c['value'],
            'source'       => $c['source'],
            'note'         => $c['note'] ?? '',
            'last_updated' => current_time( 'mysql' ),
        );
        blomstra_track_source( $sources, $iso3, 'energy_dependency', 'EIA', 'national', null );
        set_transient( 'sivi_energy_' . $iso3, $results[ $iso3 ], 12 * HOUR_IN_SECONDS );
    }
    $store = array( 'data' => $results, 'sources' => $sources );
    $existing = get_option( SIVI_ENERGY_KEY, array() );
    $merged = array_merge( $existing['data'] ?? array(), $results );
    $merged_sources = array_merge( $existing['sources'] ?? array(), $sources );
    update_option( SIVI_ENERGY_KEY, array( 'data' => $merged, 'sources' => $merged_sources ), false );
    // BMS-1.1.0 fix (deviations.md Issue #2) — same fix as the central-fetch path.
    update_option( SIVI_ENERGY_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}

/**
 * Persists computed energy results to the option store.
 *
 * @since  1.0.0
 * @param  array $iso3_list List of ISO3 codes.
 * @param  array $computed  Results from sivi_eia_aggregate_energy_dependency().
 * @return array The stored data array.
 */
function sivi_persist_energy_results( $iso3_list, $computed ) {
    $results = array();
    $sources = array();
    foreach ( $iso3_list as $iso3 ) {
        $c = $computed[ $iso3 ] ?? array( 'value' => null, 'source' => 'EIA', 'note' => 'not returned' );
        $results[ $iso3 ] = array(
            'value'        => $c['value'],
            'source'       => $c['source'],
            'note'         => $c['note'] ?? '',
            'last_updated' => current_time( 'mysql' ),
        );
        blomstra_track_source( $sources, $iso3, 'energy_dependency', 'EIA', 'national', null );
        set_transient( 'sivi_energy_' . $iso3, $results[ $iso3 ], 12 * HOUR_IN_SECONDS );
    }
    $store = array( 'data' => $results, 'sources' => $sources );
    $existing = get_option( SIVI_ENERGY_KEY, array() );
    $merged = array_merge( $existing['data'] ?? array(), $results );
    $merged_sources = array_merge( $existing['sources'] ?? array(), $sources );
    update_option( SIVI_ENERGY_KEY, array( 'data' => $merged, 'sources' => $merged_sources ), false );
    // BMS-1.1.0 fix (deviations.md Issue #2): this was the only one of the
    // three pillar persist functions that never updated its freshness
    // meta key — that's why the admin "Energy: Never ❌" badge never
    // cleared even after real successful fetches. Matches the pattern
    // already present in the maritime persist path.
    update_option( SIVI_ENERGY_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}

/**
 * Main entry point for refreshing the Energy pillar.
 *
 * Supports four modes:
 *   - 'central'      : Fetch from shared Reference Data cache (sync, must exist).
 *   - 'central_cached': Fetch from cached Reference Data (no new fetch).
 *   - 'api'          : Direct EIA API calls (fallback).
 *   - 'auto'         : Try 'central' first; fall back to 'api' on failure/empty.
 *
 * @since  1.0.0
 * @param  array|null $countries Optional country list.
 * @param  string     $source    Source mode: 'central', 'central_cached', 'api', or 'auto'.
 * @return array|array{error:string} Stored data or error array.
 */
function sivi_refresh_energy_pillar( $countries = null, $source = 'auto' ) {
    if ( $countries === null ) {
        $countries = sivi_get_global_country_list();
    }
    $iso3_list = array_keys( $countries );

    if ( $source === 'central' ) {
        if ( ! function_exists( 'blomstra_refresh_eia_raw_data' ) ) {
            return array( 'error' => 'Central model not active — the "Blomstra Reference Data" snippet isn\'t installed/active.' );
        }
        $raw = blomstra_refresh_eia_raw_data( $iso3_list );
        if ( empty( $raw['consumption'] ) && empty( $raw['production'] ) ) {
            return array( 'error' => 'Central model ran but produced no usable raw data — check the Blomstra Reference Data page for its own diagnostics. Deliberately NOT falling back, so this test is meaningful.' );
        }
        $computed = sivi_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
        return sivi_persist_energy_results( $iso3_list, $computed );
    }

    if ( $source === 'central_cached' ) {
        if ( ! function_exists( 'blomstra_get_eia_raw_data' ) ) {
            return array( 'error' => 'Central model not active.' );
        }
        $raw = blomstra_get_eia_raw_data();
        if ( empty( $raw['consumption'] ) && empty( $raw['production'] ) ) {
            return array( 'error' => 'Central cache has no EIA data yet.' );
        }
        $computed = sivi_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
        return sivi_persist_energy_results( $iso3_list, $computed );
    }

    if ( $source === 'api' ) {
        return sivi_refresh_energy_pillar_fallback( $countries );
    }

    // 'auto': try central, fall back silently on failure/empty
    if ( function_exists( 'blomstra_refresh_eia_raw_data' ) ) {
        $raw = blomstra_refresh_eia_raw_data( $iso3_list );
        if ( ! empty( $raw['consumption'] ) || ! empty( $raw['production'] ) ) {
            $computed = sivi_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
            return sivi_persist_energy_results( $iso3_list, $computed );
        }
    }
    return sivi_refresh_energy_pillar_fallback( $countries );
}


// ============================================================================
// 8.  MARITIME PILLAR (World Bank WDI)
// ============================================================================

/**
 * Fetches maritime connectivity (LSCI) from World Bank WDI.
 *
 * Uses the shared blomstra_get_maritime_raw() if available; otherwise
 * falls back to a direct World Bank API call.
 *
 * @since  1.0.0
 * @return array Raw data [ISO3 => ['value' => float, 'year' => int]].
 */
function sivi_fetch_maritime_raw() {
    if ( function_exists( 'blomstra_get_maritime_raw' ) ) {
        $data = blomstra_get_maritime_raw();
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return sivi_fetch_maritime_raw_fallback();
}

/**
 * Fallback maritime data fetcher (direct World Bank API).
 *
 * @since  1.0.0
 * @param  int $attempt Retry attempt number.
 * @return array Raw data.
 */
function sivi_fetch_maritime_raw_fallback( $attempt = 1 ) {
    $current_year = (int) current_time( 'Y' );
    $start_year   = $current_year - 20;
    $url = "https://api.worldbank.org/v2/country/all/indicator/IS.SHP.GCNW.XQ?format=json&per_page=20000&date={$start_year}:{$current_year}";

    $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

    if ( is_wp_error( $response ) && $attempt < 2 ) {
        sleep( 3 );
        return sivi_fetch_maritime_raw_fallback( $attempt + 1 );
    }
    if ( is_wp_error( $response ) ) {
        error_log( 'SIVI Maritime fallback fetch WP_Error: ' . $response->get_error_message() );
        return array();
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body_raw  = wp_remote_retrieve_body( $response );
    if ( $http_code !== 200 ) {
        error_log( 'SIVI Maritime fallback fetch HTTP ' . $http_code );
        return array();
    }

    $body = json_decode( $body_raw, true );
    if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
        error_log( 'SIVI Maritime fallback fetch: unexpected response shape' );
        return array();
    }

    $data = array();
    foreach ( $body[1] as $row ) {
        $iso3  = $row['countryiso3code'] ?? null;
        $value = $row['value'] ?? null;
        $year  = isset( $row['date'] ) ? (int) $row['date'] : 0;
        if ( ! $iso3 || $value === null ) {
            continue;
        }
        if ( ! isset( $data[ $iso3 ] ) || $year > $data[ $iso3 ]['year'] ) {
            $data[ $iso3 ] = array( 'value' => (float) $value, 'year' => $year );
        }
    }
    return $data;
}

/**
 * Refreshes the Maritime pillar.
 *
 * Supports the same source modes as Energy: 'central', 'central_cached', 'api', 'auto'.
 * Landlocked countries are assigned a structural zero (0.0) with appropriate source.
 *
 * @since  1.0.0
 * @param  string $source Source mode.
 * @return array Stored data array (keyed by ISO3).
 */
function sivi_refresh_maritime_pillar( $source = 'auto' ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 300 );
    }

    if ( $source === 'central' ) {
        $data = function_exists( 'blomstra_get_maritime_raw' ) ? blomstra_get_maritime_raw() : array();
        if ( empty( $data ) ) {
            return array( 'error' => 'Central model returned nothing (either the snippet isn\'t active, or the fetch failed — check the Blomstra Reference Data page for its own debug info). Deliberately NOT falling back, so this test is meaningful.' );
        }
    } elseif ( $source === 'central_cached' ) {
        $data = function_exists( 'blomstra_get_maritime_raw' ) ? blomstra_get_maritime_raw( false ) : array();
        if ( empty( $data ) ) {
            return array( 'error' => 'Central cache has no Maritime data yet.' );
        }
    } elseif ( $source === 'api' ) {
        $data = sivi_fetch_maritime_raw_fallback();
        if ( empty( $data ) ) {
            return array( 'error' => 'Direct API fallback call itself failed — see the PHP error log for details.' );
        }
    } else {
        $data = sivi_fetch_maritime_raw();
    }

    $results = array();
    $sources = array();
    $all_countries = sivi_get_global_country_list();
    foreach ( $all_countries as $iso3 => $name ) {
        if ( isset( $data[ $iso3 ] ) ) {
            $results[ $iso3 ] = array(
                'value'        => $data[ $iso3 ]['value'],
                'year'         => $data[ $iso3 ]['year'],
                'source'       => 'World Bank WDI (IS.SHP.GCNW.XQ)',
                'last_updated' => current_time( 'mysql' ),
            );
            blomstra_track_source( $sources, $iso3, 'maritime_connectivity', 'WB_WDI', 'national', $data[ $iso3 ]['year'] );
        } elseif ( sivi_is_landlocked( $iso3 ) ) {
            $results[ $iso3 ] = array(
                'value'        => 0.0,
                'year'         => null,
                'source'       => 'Structural zero — landlocked (UN-OHRLLS LLDC list + developed landlocked states)',
                'last_updated' => current_time( 'mysql' ),
            );
            blomstra_track_source( $sources, $iso3, 'maritime_connectivity', 'structural_zero', 'national', null );
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
// 9.  COMTRADE HHI PILLAR
// ============================================================================

/**
 * Logs a Comtrade API call for debugging and monitoring.
 *
 * @since  1.0.0
 * @param  mixed  $reporter_code Reporter code(s) (string or int).
 * @param  int    $year          Year requested.
 * @param  string $outcome       'ok', 'partial', 'empty', 'network_error', etc.
 * @param  string $detail        Human‑readable detail.
 */
function sivi_log_comtrade_call( $reporter_code, $year, $outcome, $detail ) {
    $log = get_option( 'sivi_comtrade_call_log', array() );
    $log[] = array(
        'time'          => current_time( 'mysql' ),
        'reporter_code' => $reporter_code,
        'year'          => $year,
        'outcome'       => $outcome,
        'detail'        => $detail,
    );
    if ( count( $log ) > 50 ) {
        $log = array_slice( $log, -50 );
    }
    update_option( 'sivi_comtrade_call_log', $log, false );
}

/**
 * Logs an EIA API call for debugging and monitoring.
 *
 * @since  1.0.0
 * @param  string $chunk_label Human‑readable label for the chunk.
 * @param  string $activity_id EIA activity ID.
 * @param  string $product_id  EIA product ID.
 * @param  string $outcome     'ok', 'partial', 'empty', 'rate_limited', etc.
 * @param  string $detail      Human‑readable detail.
 */
function sivi_log_eia_call( $chunk_label, $activity_id, $product_id, $outcome, $detail ) {
    $log = get_option( 'sivi_eia_call_log', array() );
    $log[] = array(
        'time'        => current_time( 'mysql' ),
        'chunk_label' => $chunk_label,
        'activity_id' => $activity_id,
        'product_id'  => $product_id,
        'outcome'     => $outcome,
        'detail'      => $detail,
    );
    if ( count( $log ) > 50 ) {
        $log = array_slice( $log, -50 );
    }
    update_option( 'sivi_eia_call_log', $log, false );
}

/**
 * Fetches Comtrade partner import data for a batch of reporters.
 *
 * Handles pagination, rate‑limit detection, and retries. Returns the sentinel
 * SIVI_COMTRADE_QUOTA_EXHAUSTED if the quota is exhausted.
 *
 * @since  1.0.0
 * @param  array $reporter_codes List of Comtrade reporter codes.
 * @param  int   $year           Year to fetch.
 * @param  int   $attempt        Retry attempt.
 * @return array|string|null  Raw data rows, sentinel, or null on failure.
 */
function sivi_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt = 1 ) {
    if ( ! defined( 'COMTRADE_PRIMARY_KEY' ) || COMTRADE_PRIMARY_KEY === '' ) {
        sivi_log_comtrade_call( implode( ',', $reporter_codes ), $year, 'network_error', 'COMTRADE_PRIMARY_KEY not defined/empty' );
        return null;
    }

    $chunk_label = count( $reporter_codes ) . ' reporters (' . implode( ',', $reporter_codes ) . ') [filtered]';

    $all_rows = array();
    $page = 1;
    $max_pages = 1;
    $found_world_total_for = array();

    do {
        $args = array(
            'reporterCode'      => implode( ',', $reporter_codes ),
            'period'            => $year,
            'cmdCode'           => 'TOTAL',
            'flowCode'          => 'M',
            'motCode'           => 0,
            'partner2Code'      => 0,
            'customsCode'       => 'C00',
            'subscription-key'  => COMTRADE_PRIMARY_KEY,
            'page'              => $page,
            'limit'             => 500,
        );
        $url = COMTRADE_BASE_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
        $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

        if ( is_wp_error( $response ) ) {
            $fail_reason = 'network error: ' . $response->get_error_message();
            error_log( 'SIVI Comtrade batch fetch FAILED (' . $chunk_label . ', year ' . $year . ', page ' . $page . ', attempt ' . $attempt . '): ' . $fail_reason );
            sivi_log_comtrade_call( $chunk_label, $year, 'network_error', $fail_reason );
            if ( $attempt < 3 ) {
                sleep( 3 * $attempt );
                return sivi_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt + 1 );
            }
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 429 || $code === 403 ) {
            $body_snip = substr( wp_remote_retrieve_body( $response ), 0, 300 );

            if ( preg_match( '/[Tt]ry again in\s+(\d+)\s+seconds?/', $body_snip, $m ) && (int) $m[1] <= 90 && $attempt <= 2 ) {
                $wait = (int) $m[1] + 2;
                error_log( 'SIVI Comtrade batch short-term rate limit (' . $chunk_label . ', year ' . $year . '): waiting ' . $wait . 's then retrying (attempt ' . $attempt . ')' );
                sivi_log_comtrade_call( $chunk_label, $year, 'rate_limited_retrying', 'HTTP ' . $code . ' — short-term throttle, waiting ' . $wait . 's then retrying: ' . $body_snip );
                sleep( $wait );
                return sivi_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt + 1 );
            }

            $fail_reason = 'HTTP ' . $code . ' — likely quota: ' . $body_snip;
            error_log( 'SIVI Comtrade batch fetch FAILED (' . $chunk_label . ', year ' . $year . ', attempt ' . $attempt . '): ' . $fail_reason );
            sivi_log_comtrade_call( $chunk_label, $year, 'quota_or_rate_limit', 'HTTP ' . $code . ' (attempt ' . $attempt . '): ' . $fail_reason );
            return SIVI_COMTRADE_QUOTA_EXHAUSTED;
        }

        if ( $code !== 200 ) {
            $fail_reason = 'HTTP ' . $code . ' — body: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 );
            error_log( 'SIVI Comtrade batch fetch FAILED (' . $chunk_label . ', year ' . $year . ', attempt ' . $attempt . '): ' . $fail_reason );
            sivi_log_comtrade_call( $chunk_label, $year, 'http_error', 'HTTP ' . $code . ' (attempt ' . $attempt . '): ' . $fail_reason );
            if ( $code >= 500 && $attempt < 3 ) {
                sleep( 3 * $attempt );
                return sivi_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt + 1 );
            }
            return null;
        }

        $raw_body = wp_remote_retrieve_body( $response );
        if ( strlen( $raw_body ) > 15 * 1024 * 1024 ) {
            $fail_reason = 'response body ' . round( strlen( $raw_body ) / 1024 / 1024, 1 ) . 'MB — too large to safely decode, skipping to avoid a memory-exhaustion crash';
            error_log( 'SIVI Comtrade batch fetch SKIPPED (' . $chunk_label . ', year ' . $year . ', page ' . $page . '): ' . $fail_reason );
            sivi_log_comtrade_call( $chunk_label, $year, 'oversized_response', $fail_reason );
            return null;
        }

        $body = json_decode( $raw_body, true );
        unset( $raw_body );
        if ( isset( $body['error'] ) && $body['error'] !== '' ) {
            error_log( 'SIVI Comtrade batch API error field (' . $chunk_label . ', year ' . $year . '): ' . $body['error'] );
            sivi_log_comtrade_call( $chunk_label, $year, 'api_error', (string) $body['error'] );
            return null;
        }
        if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            error_log( 'SIVI Comtrade batch unexpected response shape (' . $chunk_label . ', year ' . $year . '): missing data array' );
            sivi_log_comtrade_call( $chunk_label, $year, 'bad_shape', 'missing data array' );
            return null;
        }

        $page_rows = $body['data'];
        if ( empty( $page_rows ) ) {
            break;
        }

        $all_rows = array_merge( $all_rows, $page_rows );
        foreach ( $page_rows as $row ) {
            if ( isset( $row['reporterCode'], $row['partnerCode'] ) && (int) $row['partnerCode'] === 0 ) {
                $found_world_total_for[ (int) $row['reporterCode'] ] = true;
            }
        }

        $max_pages = isset( $body['pagination']['totalPages'] ) ? (int) $body['pagination']['totalPages'] : 1;
        $page++;

        $all_have_world_total = true;
        foreach ( $reporter_codes as $rc ) {
            if ( ! isset( $found_world_total_for[ $rc ] ) ) {
                $all_have_world_total = false;
                break;
            }
        }
        if ( $all_have_world_total ) {
            break;
        }
    } while ( $page <= $max_pages && $page < 20 );

    if ( empty( $all_rows ) ) {
        sivi_log_comtrade_call( $chunk_label, $year, 'empty', 'HTTP 200, valid shape, zero rows across all pages' );
        return array();
    }

    $missing_world_total = array();
    foreach ( $reporter_codes as $rc ) {
        if ( ! isset( $found_world_total_for[ $rc ] ) ) {
            $missing_world_total[] = $rc;
        }
    }

    if ( ! empty( $missing_world_total ) ) {
        sivi_log_comtrade_call( $chunk_label, $year, 'partial', count( $all_rows ) . ' rows returned, but ' . count( $missing_world_total ) . '/' . count( $reporter_codes ) . ' requested reporters had no partnerCode=0 world-total row even after pagination: ' . implode( ',', $missing_world_total ) . ' (' . ( $page - 1 ) . ' page(s))' );
    } else {
        sivi_log_comtrade_call( $chunk_label, $year, 'ok', count( $all_rows ) . ' rows returned across ' . count( $reporter_codes ) . ' reporters, all represented (' . ( $page - 1 ) . ' page(s))' );
    }

    return $all_rows;
}

/**
 * Computes HHI (Herfindahl-Hirschman Index) from Comtrade rows.
 *
 * HHI = sum of (partner_share²) * 10000, where partner_share = import_value / world_total.
 *
 * @since  1.0.0
 * @param  array $rows           Raw Comtrade rows.
 * @param  array $reporter_codes List of reporter codes.
 * @param  int   $year           Year used.
 * @return array [reporterCode => ['value' => float, 'year' => int, 'source' => string]].
 */
function sivi_compute_hhi_from_batch_rows_fallback( $rows, $reporter_codes, $year ) {
    $by_reporter = array();
    foreach ( $rows as $row ) {
        if ( ! isset( $row['reporterCode'], $row['partnerCode'], $row['primaryValue'] ) ) {
            continue;
        }
        $rc  = (int) $row['reporterCode'];
        $pc  = (int) $row['partnerCode'];
        $val = (float) $row['primaryValue'];
        if ( ! isset( $by_reporter[ $rc ] ) ) {
            $by_reporter[ $rc ] = array( 'world_total' => null, 'partner_values' => array() );
        }
        if ( $pc === 0 ) {
            $by_reporter[ $rc ]['world_total'] = $val;
        } elseif ( $pc !== $rc && $val > 0.0 ) {
            $by_reporter[ $rc ]['partner_values'][] = $val;
        }
    }

    $results = array();
    foreach ( $reporter_codes as $rc ) {
        if ( ! isset( $by_reporter[ $rc ] ) ) {
            continue;
        }
        $entry = $by_reporter[ $rc ];
        if ( $entry['world_total'] === null || $entry['world_total'] <= 0.0 || empty( $entry['partner_values'] ) ) {
            continue;
        }
        $hhi = 0.0;
        foreach ( $entry['partner_values'] as $value ) {
            $share = $value / $entry['world_total'];
            $hhi  += $share * $share;
        }
        $hhi *= 10000;
        $results[ $rc ] = array( 'value' => round( $hhi, 2 ), 'year' => $year, 'source' => 'Comtrade' );
    }
    return $results;
}

/**
 * Fallback HHI refresh (direct Comtrade API calls).
 *
 * Iterates over a 5‑year lookback window, handles quotas, and persists
 * results incrementally via checkpoints.
 *
 * @since  1.0.0
 * @param  int|null    $year       Year to request (default: current year - 1).
 * @param  array|null  $iso3_list  List of ISO3 codes.
 * @return array Stored data array.
 */
function sivi_refresh_hhi_pillar_fallback( $year = null, $iso3_list = null ) {
    $run_started = current_time( 'mysql' );
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 900 );
    }
    if ( $year === null ) {
        $year = (int) current_time( 'Y' ) - 1;
    }
    $reporter_map = sivi_hhi_reporter_map();
    if ( $iso3_list === null ) {
        $iso3_list = array_keys( sivi_get_global_country_list() );
    }
    $existing_store = get_option( SIVI_HHI_KEY, array() );
    $existing_data = $existing_store['data'] ?? array();
    $results  = array();

    $summary = array(
        'run_started'          => $run_started,
        'requested_year'       => $year,
        'countries_in_scope'   => count( $iso3_list ),
        'reporter_map_size'    => count( $reporter_map ),
        'no_reporter_code'     => 0,
        'succeeded'            => 0,
        'attempted_no_data'    => 0,
        'skipped_quota'        => 0,
        'quota_exhausted_at'   => null,
        'quota_exhausted_pass' => null,
        'chunk_size'           => SIVI_HHI_CHUNK_SIZE,
        'last_checkpoint'      => null,
    );

    update_option( 'sivi_hhi_refresh_summary', $summary, false );

    $sivi_hhi_checkpoint = function () use ( &$results, &$summary ) {
        $existing = get_option( SIVI_HHI_KEY, array() );
        $existing_data = $existing['data'] ?? array();
        $merged_data = array_merge( $existing_data, $results );
        update_option( SIVI_HHI_KEY, array( 'data' => $merged_data, 'sources' => $existing['sources'] ?? array() ), false );
        // BMS-1.1.0 fix (deviations.md Issue #2): update on each checkpoint,
        // matching the write cadence of this long-running batched fetch —
        // the last checkpoint's timestamp is what ends up as "last_fetched".
        update_option( SIVI_HHI_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
        $summary['last_checkpoint'] = current_time( 'mysql' );
        update_option( 'sivi_hhi_refresh_summary', $summary, false );
    };

    $pending = array();
    foreach ( $iso3_list as $iso3 ) {
        if ( isset( $reporter_map[ $iso3 ] ) ) {
            $pending[ $iso3 ] = $reporter_map[ $iso3 ];
        } else {
            $summary['no_reporter_code']++;
            $results[ $iso3 ] = array(
                'value' => null, 'scale' => '0-10000', 'requested_year' => $year,
                'actual_year' => null, 'source' => 'no reporter code',
                'last_updated' => current_time( 'mysql' ),
            );
        }
    }

    $quota_dead = false;

    for ( $offset = 0; $offset <= 4 && ! empty( $pending ) && ! $quota_dead; $offset++ ) {
        $try_year      = $year - $offset;
        $still_pending = array();
        $chunks        = array_chunk( $pending, SIVI_HHI_CHUNK_SIZE, true );

        foreach ( $chunks as $chunk ) {
            if ( $quota_dead ) {
                foreach ( $chunk as $iso3 => $code ) {
                    $still_pending[ $iso3 ] = $code;
                }
                continue;
            }

            $codes_in_chunk = array_values( $chunk );
            $rows = sivi_comtrade_fetch_partner_imports_batch_fallback( $codes_in_chunk, $try_year );

            if ( $rows === SIVI_COMTRADE_QUOTA_EXHAUSTED ) {
                $quota_dead = true;
                $iso3s_in_chunk = array_keys( $chunk );
                $summary['quota_exhausted_at']   = implode( ',', array_slice( $iso3s_in_chunk, 0, 3 ) ) . ( count( $iso3s_in_chunk ) > 3 ? '...' : '' );
                $summary['quota_exhausted_pass'] = $offset;
                foreach ( $chunk as $iso3 => $code ) {
                    $still_pending[ $iso3 ] = $code;
                }
                continue;
            }

            if ( $rows === null ) {
                foreach ( $chunk as $iso3 => $code ) {
                    $still_pending[ $iso3 ] = $code;
                }
                continue;
            }

            $computed_by_code = sivi_compute_hhi_from_batch_rows_fallback( $rows, $codes_in_chunk, $try_year );
            unset( $rows );

            $sources = array();
            foreach ( $chunk as $iso3 => $code ) {
                if ( isset( $computed_by_code[ $code ] ) ) {
                    $summary['succeeded']++;
                    $results[ $iso3 ] = array(
                        'value' => $computed_by_code[ $code ]['value'], 'scale' => '0-10000', 'requested_year' => $year,
                        'actual_year' => $computed_by_code[ $code ]['year'], 'source' => 'Comtrade',
                        'last_updated' => current_time( 'mysql' ),
                    );
                    blomstra_track_source( $sources, $iso3, 'supplier_concentration', 'Comtrade', 'national', $computed_by_code[ $code ]['year'] );
                    set_transient( 'sivi_hhi_' . $iso3, $results[ $iso3 ], 24 * HOUR_IN_SECONDS );
                } else {
                    $still_pending[ $iso3 ] = $code;
                }
            }
            $sivi_hhi_checkpoint();
            sleep( 2 );
        }

        if ( $offset >= 4 ) {
            foreach ( $still_pending as $iso3 => $code ) {
                $summary['attempted_no_data']++;
                $results[ $iso3 ] = array(
                    'value' => null, 'scale' => '0-10000', 'requested_year' => $year,
                    'actual_year' => null, 'source' => 'no data in lookback window',
                    'last_updated' => current_time( 'mysql' ),
                );
            }
            $still_pending = array();
        }

        $pending = $still_pending;
    }

    foreach ( $pending as $iso3 => $code ) {
        $summary['skipped_quota']++;
        $results[ $iso3 ] = array(
            'value' => null, 'scale' => '0-10000', 'requested_year' => $year,
            'actual_year' => null, 'source' => 'skipped — quota exhausted this run',
            'last_updated' => current_time( 'mysql' ),
        );
    }

    $sivi_hhi_checkpoint();
    $summary['run_finished'] = current_time( 'mysql' );
    update_option( 'sivi_hhi_refresh_summary', $summary, false );

    return $results;
}

/**
 * Merges HHI data from the shared central cache into the SIVI pillar store.
 *
 * @since  1.0.0
 * @param  array $iso3_list List of ISO3 codes.
 * @return array Stored data array.
 */
function sivi_merge_hhi_into_pillar( $iso3_list ) {
    $central_data = function_exists( 'blomstra_get_comtrade_hhi_data' ) ? blomstra_get_comtrade_hhi_data() : array();
    $results = array();
    $sources = array();
    foreach ( $iso3_list as $iso3 ) {
        if ( isset( $central_data[ $iso3 ] ) ) {
            $results[ $iso3 ] = $central_data[ $iso3 ];
            blomstra_track_source( $sources, $iso3, 'supplier_concentration', 'Comtrade', 'national', $central_data[ $iso3 ]['year'] ?? null );
        }
    }
    $existing = get_option( SIVI_HHI_KEY, array() );
    $merged = array_merge( $existing['data'] ?? array(), $results );
    $merged_sources = array_merge( $existing['sources'] ?? array(), $sources );
    update_option( SIVI_HHI_KEY, array( 'data' => $merged, 'sources' => $merged_sources ), false );
    // BMS-1.1.0 fix (deviations.md Issue #2): HHI never updated its
    // freshness meta key either — same root cause as energy.
    update_option( SIVI_HHI_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    return $results;
}

/**
 * Main entry point for refreshing the HHI pillar.
 *
 * Supports the same source modes as Energy: 'central', 'central_cached', 'api', 'auto'.
 *
 * @since  1.0.0
 * @param  int|null    $year       Year to request.
 * @param  array|null  $iso3_list  List of ISO3 codes.
 * @param  string      $source     Source mode.
 * @return array|array{error:string} Stored data or error array.
 */
function sivi_refresh_hhi_pillar( $year = null, $iso3_list = null, $source = 'auto' ) {
    if ( $iso3_list === null ) {
        $iso3_list = array_keys( sivi_get_global_country_list() );
    }

    if ( $source === 'central' ) {
        if ( ! function_exists( 'blomstra_refresh_comtrade_hhi_data' ) ) {
            return array( 'error' => 'Central model not active — the "Blomstra Reference Data" snippet isn\'t installed/active.' );
        }
        blomstra_refresh_comtrade_hhi_data( $year, $iso3_list );
        $summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        if ( empty( $summary ) || ( $summary['succeeded'] ?? 0 ) === 0 ) {
            return array( 'error' => 'Central model ran but produced no successful values this run — check the Blomstra Reference Data page for its own diagnostics. Deliberately NOT falling back, so this test is meaningful.' );
        }
        return sivi_merge_hhi_into_pillar( $iso3_list );
    }

    if ( $source === 'central_cached' ) {
        if ( ! function_exists( 'blomstra_get_comtrade_hhi_data' ) ) {
            return array( 'error' => 'Central model not active.' );
        }
        $central_data = blomstra_get_comtrade_hhi_data();
        if ( empty( $central_data ) ) {
            return array( 'error' => 'Central cache has no HHI data yet.' );
        }
        return sivi_merge_hhi_into_pillar( $iso3_list );
    }

    if ( $source === 'api' ) {
        return sivi_refresh_hhi_pillar_fallback( $year, $iso3_list );
    }

    // 'auto': try central, fall back silently on failure/empty
    if ( function_exists( 'blomstra_refresh_comtrade_hhi_data' ) ) {
        blomstra_refresh_comtrade_hhi_data( $year, $iso3_list );
        $summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        if ( ! empty( $summary ) && ( $summary['succeeded'] ?? 0 ) > 0 ) {
            return sivi_merge_hhi_into_pillar( $iso3_list );
        }
    }
    return sivi_refresh_hhi_pillar_fallback( $year, $iso3_list );
}


// ============================================================================
// 10. PERCENTILE COMPUTATION
// ============================================================================

/**
 * Computes percentile ranks for a set of values.
 *
 * This is a custom implementation that handles ties correctly.
 * It is kept as a fallback; the shared blomstra_compute_percentile_ranks_safe()
 * could be used instead for consistency.
 *
 * @since  1.0.0
 * @param  array $values_by_iso3 [ISO3 => numeric value].
 * @param  float $winsor_pct     BMS-1.1.0: winsorization level (0.01 = 1st/99th percentile), 0 = none. Only applied on the primary (blomstra_compute_percentile_ranks_safe) path — the local fallback below does not winsorize, matching its existing behavior.
 * @return array [ISO3 => percentile (0-100)].
 */
function sivi_compute_percentile_ranks( $values_by_iso3, $winsor_pct = 0.0 ) {
    if ( function_exists( 'blomstra_compute_percentile_ranks_safe' ) ) {
        return blomstra_compute_percentile_ranks_safe( $values_by_iso3, $winsor_pct );
    }
    // Fallback implementation
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
// 11. COMPOSITE BUILDER
// ============================================================================

/**
 * Builds the composite index from the three pillar stores.
 *
 * This is the heart of the SIVI index. It loads pillar data, computes percentiles,
 * applies weights (default or custom), assigns definitive or projected ranks,
 * and adds data quality and measurement flags.
 *
 * The builder is "scenario‑safe": if custom weights are provided, the result is
 * stored as a scenario rather than overwriting the main composite.
 *
 * @since  2.0.0
 * @param  bool        $force                   Unused (kept for compatibility).
 * @param  string      $context                 'manual', 'cron', 'async', 'scenario'.
 * @param  array|null  $custom_weights          Custom pillar indicator weights (rarely changed).
 * @param  array|null  $custom_composite_weights Custom composite weights (must sum to 100).
 * @return array The composite index data.
 */
function sivi_build_composite( $force = false, $context = 'manual', $custom_weights = null, $custom_composite_weights = null ) {
    $is_scenario = ( $custom_weights !== null || $custom_composite_weights !== null );

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }

    // Load data and sources
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

    $countries = function_exists( 'blomstra_get_global_country_list' )
        ? blomstra_get_global_country_list()
        : array();
    if ( empty( $countries ) ) {
        return array( 'error' => 'No country list available' );
    }
    $all_iso3 = array_keys( $countries );

    // Prepare raw values for percentile ranking
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

    // Percentiles
    // BMS-1.1.0: winsor level now read from config (§2.8), not hardcoded.
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

    // Use custom weights if provided
    $composite_weights = $custom_composite_weights ?? sivi_get_composite_weights();
    $all_pillars = array( 'energy', 'hhi', 'maritime' );

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
                'reason'          => 'Fewer than ' . SIVI_MIN_PILLARS_REQUIRED . ' pillars have real data — not scored (no fabricated fill-in used).',
                'pillars_present' => $pillars_present,
                'pillars_missing' => $missing_pillars,
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

    // ─── Rank assignment (DESCENDING: highest score = #1) ──────
    // BMS-1.1.0: rank COMPARISON is computed directly here, index-owned —
    // matching SERI's pattern exactly. Reference Data's shared
    // blomstra_rank_in_full_index() is deliberately NOT used for this:
    // its comparison direction is hardcoded (always descending), which
    // happens to match SIVI's own vulnerability convention today, but
    // relying on that silently is exactly what caused SERI's ranks to
    // compute backwards when centralization was first attempted. Only
    // the direction-AGNOSTIC formatting functions (blomstra_build_full_
    // rank_display / blomstra_build_partial_rank_display — which just
    // format an already-computed rank number, no direction logic inside)
    // are shared. If a future index needs a different comparison, it
    // gets its own inline block here too — never a silent shared default.
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
            // Descending: higher composite_score = more vulnerable = rank 1.
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

    // Partial ranks — pillar percentile values are injected directly at
    // each point (0/10/50/90/100), since SIVI's pillar scores are already
    // on a comparable 0-100 percentile scale (SIVI's own methodology
    // choice, unchanged — see blomstra_project_partial_rank_composite()
    // docblock for why this isn't unified with SERI's interpolation approach).
    $pillar_weight_by_name = $composite_weights;
    $pillar_value_key = array(
        'energy'   => 'energy_dependency_percentile',
        'hhi'      => 'supplier_concentration_percentile',
        'maritime' => 'maritime_vulnerability_percentile',
    );

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
            continue; // no fallback here — better to omit rank_display than compute it with the old buggy inline math
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

        // Same index-owned descending comparison as the full-coverage
        // branch above — not the shared blomstra_rank_in_full_index().
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

    // ─── Data Quality & Measurement Flags ──────────────────────
    $country_output = array();
    foreach ( $results as $iso3 => $row ) {
        $coverage = ( $row['coverage_type'] === 'full' ) ? 3 : 2;
        $missing_pillars_list = $row['pillars_missing'];

        // Data quality (uses shared utility)
        $data_quality = array();
        foreach ( array( 'energy' => 'energy_dependency', 'hhi' => 'supplier_concentration', 'maritime' => 'maritime_connectivity' ) as $pillar => $indicator ) {
            if ( function_exists( 'blomstra_pillar_quality_score' ) ) {
                $data_quality[ $pillar ] = blomstra_pillar_quality_score( $all_sources, $iso3, array( $indicator ) );
            } else {
                // Fallback if shared utility is missing (should not happen)
                $data_quality[ $pillar ] = null;
            }
        }

        // Measurement flags
        $measurement_flags = array(
            'is_landlocked' => sivi_is_landlocked( $iso3 ),
            // BMS-1.1.0 fix (deviations.md Issue #3): this used to check
            // energy_data for a "Structural zero — landlocked" source
            // string that (a) only ever gets set on maritime_data, not
            // energy_data, and (b) didn't even match the real label
            // exactly (missing a parenthetical suffix) — so it always
            // evaluated false. Landlocked status affects maritime
            // connectivity, not energy dependency, so the flag is
            // renamed and computed directly from the landlocked check
            // rather than fragile string matching.
            'maritime_is_structural_zero' => sivi_is_landlocked( $iso3 ),
            'coverage_ratio' => $coverage / 3,
            'is_definitive' => ( $coverage == 3 ),
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
            // BMS-1.1.0 (deviations.md Issue #4, partial): nested pillars
            // object matching SERI's {score, weight} shape, built from
            // data SIVI already computes. NOTE: this does NOT add a
            // data_freshness structure — SERI's is bespoke to its own
            // per-indicator year/source field names, and building a
            // matching one for SIVI needs a real design decision about
            // what year/source metadata SIVI actually has available per
            // pillar, not a guessed structure. Left as an open item.
            'pillars' => array(
                'energy'   => array( 'score' => $row['energy_dependency_percentile'], 'weight' => $composite_weights['energy'] ?? 33.3333 ),
                'hhi'      => array( 'score' => $row['supplier_concentration_percentile'], 'weight' => $composite_weights['hhi'] ?? 33.3333 ),
                'maritime' => array( 'score' => $row['maritime_vulnerability_percentile'], 'weight' => $composite_weights['maritime'] ?? 33.3334 ),
            ),
        );
    }

    // ─── Output ──────────────────────────────────────────────────
    $output = array(
        'version'         => SIVI_VERSION,
        'last_updated'    => current_time( 'mysql' ),
        'total_countries' => count( $country_output ),
        'excluded'        => count( $excluded ),
        'excluded_detail' => $excluded,
        'methodology_url'     => 'https://blomstrainsights.com/methodology/sivi',
        'methodology_summary' => 'Percentile-rank composite (Energy dependency, HHI supplier concentration, inverted Maritime connectivity). Full Index = definitive rank. Partial Index = projected rank range with 80% and theoretical bounds.',
        'footnote'        => 'Partial ranks are projections, not definitive placements. Following OECD/JRC guidelines, we report two uncertainty intervals for countries missing a pillar: the 80% Plausible Range (simulating the missing dimension between the 10th and 90th percentile of global data) and the Theoretical Bound (0th to 100th percentile). The Best Estimate uses the global median (50th percentile) for the missing dimension. Countries with structural zeros (e.g. landlocked states with no maritime connectivity) are scored in the Full Index, not the Partial Index.',
        'weights' => $composite_weights,
        '_meta' => array(
            'built_at'            => current_time( 'mysql' ),
            'source'              => $context,
            'status'              => 'valid',
            'standard_version'    => 'BMS-1.1.0',
            'methodology_version' => SIVI_VERSION,
            'software_version'    => SIVI_VERSION,
        ),
        'countries' => $country_output,
    );

    // ─── Cron safeguard ──────────────────────────────────────────
    $previous = get_option( SIVI_OPTION_KEY, null );
    $should_keep_old = false;

    if ( $context === 'cron' && $previous && ! empty( $previous['countries'] ) ) {
        $prev_count = count( $previous['countries'] );
        $new_count = count( $output['countries'] );
        if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
            error_log( 'SIVI: Automated build failed – new count (' . $new_count . ') vs previous (' . $prev_count . '). Keeping old composite.' );
            set_transient( 'sivi_auto_build_failed', 'yes', DAY_IN_SECONDS );
            $should_keep_old = true;
        }
    }

    if ( $should_keep_old && $previous ) {
        return $previous;
    }

    // ─── Scenario‑safe storage ──────────────────────────────────
    if ( ! $is_scenario && $context !== 'scenario' ) {
        $staging_key = SIVI_OPTION_KEY . '_tmp';
        update_option( $staging_key, $output, false );
        update_option( SIVI_OPTION_KEY, $output, false );
        delete_option( $staging_key );

        if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
            $snap = array();
            foreach ( $country_output as $iso3 => $data ) {
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
    }

    return $output;
}


// ============================================================================
// 12. SCENARIO STORAGE
// ============================================================================

/**
 * Stores a scenario result under a unique key.
 *
 * @since  2.0.0
 * @param  array  $output       The composite array from sivi_build_composite().
 * @param  string $scenario_id  A slug‑like identifier (e.g., 'energy-heavy').
 */
function sivi_store_scenario( $output, $scenario_id ) {
    $key = SIVI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id );
    update_option( $key, $output, false );
}

/**
 * Lists all saved scenarios.
 *
 * @since  2.0.0
 * @return array [scenario_id => composite_data].
 */
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

/**
 * Deletes a saved scenario.
 *
 * @since  2.0.0
 * @param  string $scenario_id  Scenario identifier.
 */
function sivi_delete_scenario( $scenario_id ) {
    delete_option( SIVI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id ) );
}


// ============================================================================
// 13. SPEARMAN CORRELATION
// ============================================================================

/**
 * Computes Spearman's rank correlation coefficient between two arrays.
 *
 * @since  2.0.0
 * @param  array $x  First array of numeric values.
 * @param  array $y  Second array of numeric values (same length as $x).
 * @return float Spearman's ρ, or 0 if too few data points.
 */



// ============================================================================
// 14. ASYNC FETCH CALLBACKS
// ============================================================================

/**
 * Asynchronous callback to refresh the Energy pillar.
 *
 * @since  2.0.0
 */
function sivi_async_fetch_energy_callback() {
    sivi_refresh_energy_pillar( null, 'auto' );
}
add_action( 'sivi_async_fetch_energy', 'sivi_async_fetch_energy_callback' );

/**
 * Asynchronous callback to refresh the HHI pillar.
 *
 * @since  2.0.0
 */
function sivi_async_fetch_hhi_callback() {
    sivi_refresh_hhi_pillar( null, null, 'auto' );
}
add_action( 'sivi_async_fetch_hhi', 'sivi_async_fetch_hhi_callback' );

/**
 * Asynchronous callback to refresh the Maritime pillar.
 *
 * @since  2.0.0
 */
function sivi_async_fetch_maritime_callback() {
    sivi_refresh_maritime_pillar( 'auto' );
}
add_action( 'sivi_async_fetch_maritime', 'sivi_async_fetch_maritime_callback' );

/**
 * Asynchronous callback that refreshes all pillars and rebuilds the composite.
 *
 * @since  2.0.0
 */
function sivi_async_refresh_callback() {
    $previous = get_option( SIVI_OPTION_KEY, null );

    sivi_refresh_energy_pillar( null, 'auto' );
    sivi_refresh_hhi_pillar( null, null, 'auto' );
    sivi_refresh_maritime_pillar( 'auto' );

    $result = sivi_build_composite( false, 'async' );

    if ( isset( $result['error'] ) && $previous ) {
        error_log( 'SIVI Async: Build failed, keeping previous composite.' );
        set_transient( 'sivi_auto_build_failed', 'yes', DAY_IN_SECONDS );
        if ( get_option( SIVI_OPTION_KEY, null ) !== $previous ) {
            update_option( SIVI_OPTION_KEY, $previous, false );
        }
    }
}
add_action( SIVI_REFRESH_HOOK, 'sivi_async_refresh_callback' );


// ============================================================================
// 15. CRON SCHEDULING & CALLBACKS
// ============================================================================

/**
 * Schedules the daily and weekly cron events on init.
 *
 * @since  2.0.0
 */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( SIVI_DAILY_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'daily', SIVI_DAILY_CRON_HOOK );
    }
    if ( ! wp_next_scheduled( SIVI_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'weekly', SIVI_CRON_HOOK );
    }
} );

/**
 * Daily cron callback: refreshes pillars and rebuilds composite.
 *
 * @since  2.0.0
 */
add_action( SIVI_DAILY_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'sivi_daily', 'running', 'Daily cron: refreshing pillar data...' );
    }
    sivi_refresh_energy_pillar( null, 'auto' );
    sivi_refresh_hhi_pillar( null, null, 'auto' );
    sivi_refresh_maritime_pillar( 'auto' );
    $result = sivi_build_composite( false, 'cron' );
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        $msg = isset( $result['total_countries'] ) ? $result['total_countries'] . ' countries scored.' : 'Build completed.';
        blomstra_update_cron_status( 'sivi_daily', 'success', $msg, $result['total_countries'] ?? 0 );
    }
} );

/**
 * Weekly cron callback: same as daily but with a different hook name.
 *
 * @since  2.0.0
 */
add_action( SIVI_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'sivi', 'running', 'SIVI weekly cron started...' );
    }
    sivi_refresh_energy_pillar( null, 'auto' );
    sivi_refresh_hhi_pillar( null, null, 'auto' );
    sivi_refresh_maritime_pillar( 'auto' );
    $result = sivi_build_composite( false, 'cron' );
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        $msg = isset( $result['total_countries'] ) ? $result['total_countries'] . ' countries scored.' : 'Build completed.';
        blomstra_update_cron_status( 'sivi', 'success', $msg, $result['total_countries'] ?? 0 );
    }
} );


// ============================================================================
// 16. REST ENDPOINTS
// ============================================================================

/**
 * Registers the REST API endpoints for the SIVI index.
 *
 * Two endpoints are provided:
 * - New canonical: /sovereign-infrastructure-vulnerability-index
 * - Legacy (backward compat): /critical-infrastructure-index
 *
 * @since  2.0.0
 */
add_action( 'rest_api_init', function () {
    // Legacy endpoint (backward compatibility)
    register_rest_route( 'blomstra/v1', '/critical-infrastructure-index', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $data = get_option( SIVI_OPTION_KEY, null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index not built yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );

    // New canonical endpoint
    register_rest_route( 'blomstra/v1', '/sovereign-infrastructure-vulnerability-index', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $data = get_option( SIVI_OPTION_KEY, null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index not built yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );


// ============================================================================
// 17. VALIDATION ON INIT
// ============================================================================

/**
 * Validates that the pillar definitions and weights are consistent.
 *
 * Uses the shared blomstra_validate_pillar_thresholds() utility.
 * If validation fails in WP_DEBUG mode, it halts execution with a message.
 *
 * @since  2.0.0
 */
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
// 18. ADMIN PAGE
// ============================================================================

/**
 * Adds the SIVI admin page under the Blomstra Insights Tools menu.
 *
 * @since  2.0.0
 */
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

/**
 * Redirects the old admin slug (cii-index) to the new one.
 *
 * @since  2.0.0
 */
add_action( 'admin_init', function () {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'cii-index' ) {
        wp_redirect( admin_url( 'admin.php?page=blomstra-sovereign-infrastructure-vulnerability-index' ) );
        exit;
    }
} );

/**
 * Renders the SIVI admin page with all controls and status information.
 *
 * This page includes:
 * - Dashboard cards (Energy, HHI, Maritime, Composite)
 * - Coverage breakdown (Full / Partial / Excluded)
 * - Freshness and system status
 * - Pillar data layer table (fetch, flush)
 * - Composite build actions
 * - Sensitivity testing (scenario builder)
 * - Preview tables (most / least vulnerable, excluded)
 *
 * @since  2.0.0
 */
function sivi_render_admin_page() {
    // ── Handle actions ────────────────────────────────────────────
    if ( isset( $_POST['sivi_fetch_energy'] ) && check_admin_referer( 'sivi_fetch_energy_action' ) ) {
        wp_schedule_single_event( time(), 'sivi_async_fetch_energy' );
        echo '<div class="notice notice-info"><p>⏳ Energy fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['sivi_fetch_hhi'] ) && check_admin_referer( 'sivi_fetch_hhi_action' ) ) {
        wp_schedule_single_event( time(), 'sivi_async_fetch_hhi' );
        echo '<div class="notice notice-info"><p>⏳ HHI fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['sivi_fetch_maritime'] ) && check_admin_referer( 'sivi_fetch_maritime_action' ) ) {
        wp_schedule_single_event( time(), 'sivi_async_fetch_maritime' );
        echo '<div class="notice notice-info"><p>⏳ Maritime fetch queued as background task. Refresh the page shortly.</p></div>';
    }

    // ── API Direct ──────────────────────────────────────────────────
    if ( isset( $_POST['sivi_fetch_api_energy'] ) && check_admin_referer( 'sivi_fetch_api_energy_action' ) ) {
        $data = sivi_refresh_energy_pillar( null, 'api' );
        echo '<div class="notice notice-success"><p>✅ Energy: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['sivi_fetch_api_hhi'] ) && check_admin_referer( 'sivi_fetch_api_hhi_action' ) ) {
        $data = sivi_refresh_hhi_pillar( null, null, 'api' );
        echo '<div class="notice notice-success"><p>✅ HHI: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['sivi_fetch_api_maritime'] ) && check_admin_referer( 'sivi_fetch_api_maritime_action' ) ) {
        $data = sivi_refresh_maritime_pillar( 'api' );
        echo '<div class="notice notice-success"><p>✅ Maritime: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }

    // ── Flush per pillar ──────────────────────────────────────────
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

    // ── Build from cache ──────────────────────────────────────────
    if ( isset( $_POST['sivi_build_cache'] ) && check_admin_referer( 'sivi_build_cache_action' ) ) {
        $data = sivi_build_composite( false, 'manual' );
        echo '<div class="notice notice-success"><p>✅ Composite built from pillar cache: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded'] ) . ' excluded).</p></div>';
    }

    // ── Fetch All (Async) ──────────────────────────────────────────
    if ( isset( $_POST['sivi_fetch_all_async'] ) && check_admin_referer( 'sivi_fetch_all_async_action' ) ) {
        wp_schedule_single_event( time(), SIVI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🔄 All pillars queued for background refresh. Please wait a few minutes and refresh the page.</p></div>';
    }

    // ── Emergency API ──────────────────────────────────────────────
    if ( isset( $_POST['sivi_emergency_api_build'] ) && check_admin_referer( 'sivi_emergency_api_build_action' ) ) {
        wp_schedule_single_event( time(), SIVI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🚨 Emergency API refresh queued as background task. Please wait a few minutes and refresh the page.</p></div>';
    }

    // ── Flush All ──────────────────────────────────────────────────
    if ( isset( $_POST['sivi_flush_all_confirmed'] ) && check_admin_referer( 'sivi_flush_all_action' ) ) {
        delete_option( SIVI_ENERGY_KEY );
        delete_option( SIVI_HHI_KEY );
        delete_option( SIVI_MARITIME_KEY );
        delete_option( SIVI_ENERGY_META_KEY );
        delete_option( SIVI_HHI_META_KEY );
        delete_option( SIVI_MARITIME_META_KEY );
        delete_option( SIVI_OPTION_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ All SIVI pillar caches and composite have been flushed.</p></div>';
    }

    // ── Force daily cron ──────────────────────────────────────────
    if ( isset( $_POST['sivi_force_daily_cron'] ) && check_admin_referer( 'sivi_force_daily_cron_action' ) ) {
        wp_schedule_single_event( time(), SIVI_DAILY_CRON_HOOK );
        echo '<div class="notice notice-info"><p>🧪 Daily cron triggered (will refresh pillars and rebuild). Result will appear shortly.</p></div>';
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
        } else {
            $sum = array_sum( $json['composite'] );
            if ( abs( $sum - 100 ) > 0.1 ) {
                echo '<div class="notice notice-error"><p>❌ Composite weights must sum to 100. Current sum: ' . esc_html( $sum ) . '</p></div>';
            } else {
                $result = sivi_build_composite( false, 'scenario', $json['pillars'], $json['composite'] );
                sivi_store_scenario( $result, $scenario_name );
                echo '<div class="notice notice-success"><p>✅ Scenario <strong>' . esc_html( $scenario_name ) . '</strong> built: ' . esc_html( $result['total_countries'] ) . ' countries scored.</p></div>';
            }
        }
    }

    if ( isset( $_POST['sivi_delete_scenario'] ) && check_admin_referer( 'sivi_delete_scenario_action' ) ) {
        $scenario_id = sanitize_key( $_POST['sivi_delete_scenario'] );
        sivi_delete_scenario( $scenario_id );
        echo '<div class="notice notice-warning"><p>🗑️ Scenario <strong>' . esc_html( $scenario_id ) . '</strong> deleted.</p></div>';
    }

    $existing = get_option( SIVI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( SIVI_CRON_HOOK );
    $last_cron = get_option( 'blomstra_cron_status', array() );
    $sivi_status = $last_cron['sivi'] ?? null;

    $energy_meta = get_option( SIVI_ENERGY_META_KEY, array() );
    $hhi_meta = get_option( SIVI_HHI_META_KEY, array() );
    $maritime_meta = get_option( SIVI_MARITIME_META_KEY, array() );

    $energy_store = get_option( SIVI_ENERGY_KEY, array() );
    $hhi_store = get_option( SIVI_HHI_KEY, array() );
    $maritime_store = get_option( SIVI_MARITIME_KEY, array() );

    $energy_data = $energy_store['data'] ?? array();
    $hhi_data = $hhi_store['data'] ?? array();
    $maritime_data = $maritime_store['data'] ?? array();

    $energy_count = count( array_filter( $energy_data, function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) );
    $hhi_count    = count( array_filter( $hhi_data, function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) );
    $maritime_count = count( array_filter( $maritime_data, function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) );

    $pillar_freshness = array();
    foreach ( array( 'energy' => $energy_meta, 'hhi' => $hhi_meta, 'maritime' => $maritime_meta ) as $key => $meta ) {
        $last_fetched = $meta['last_fetched'] ?? null;
        if ( $last_fetched ) {
            $diff = time() - strtotime( $last_fetched );
            $days = floor( $diff / DAY_IN_SECONDS );
            if ( $days == 0 ) {
                $pillar_freshness[ $key ] = 'Today ✅';
            } elseif ( $days == 1 ) {
                $pillar_freshness[ $key ] = '1 day ago ✅';
            } else {
                $pillar_freshness[ $key ] = $days . ' days ago ✅';
            }
        } else {
            $pillar_freshness[ $key ] = 'Never ❌';
        }
    }

    $composite_fresh = 'Never ❌';
    if ( $existing && isset( $existing['last_updated'] ) ) {
        $diff = time() - strtotime( $existing['last_updated'] );
        $days = floor( $diff / DAY_IN_SECONDS );
        if ( $days == 0 ) {
            $composite_fresh = 'Today ✅';
        } elseif ( $days == 1 ) {
            $composite_fresh = '1 day ago ✅';
        } else {
            $composite_fresh = $days . ' days ago ✅';
        }
    }

    $energy_status = $energy_count > 0 ? 'Scored ✓ (' . $energy_count . ')' : 'Not Scored';
    $hhi_status = $hhi_count > 0 ? 'Scored ✓ (' . $hhi_count . ')' : 'Not Scored';
    $maritime_status = $maritime_count > 0 ? 'Scored ✓ (' . $maritime_count . ')' : 'Not Scored';

    $composite_status = 'Not built yet';
    if ( $existing ) {
        $composite_status = 'Composite built from pillar cache with ' . $existing['total_countries'] . ' countries.';
    }

    echo '<div class="wrap"><h1>SIVI — Sovereign Infrastructure Vulnerability Index</h1>';

    // ─── DASHBOARD CARDS ──────────────────────────────────────────
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin:15px 0;">';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Energy Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $energy_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">HHI Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $hhi_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Maritime Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $maritime_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #f56e28; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Composite Index</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ( $existing ? 'Scored ✓ (' . $existing['total_countries'] . ')' : 'Not Scored' ) . '</p>';
    echo '<p style="margin:4px 0 0; font-size:12px; color:#666;">' . $composite_status . '</p></div></div>';
    echo '</div>';

    // ─── COVERAGE BREAKDOWN ──────────────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $full_count = 0;
        $partial_count = 0;
        foreach ( $existing['countries'] as $country ) {
            if ( isset( $country['coverage'] ) ) {
                if ( $country['coverage'] === 'full' ) {
                    $full_count++;
                } else {
                    $partial_count++;
                }
            }
        }
        $excluded_count = $existing['excluded'] ?? 0;
        $total_count = $existing['total_countries'] ?? 0;

        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#f0f6fc; margin:15px 0;">';
        echo '<div class="inside" style="padding:10px 15px;">';
        echo '<h3 style="margin:0 0 8px 0; font-size:14px;">📊 Coverage Breakdown</h3>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:20px;">';
        echo '<div><strong style="color:#2e7d32;">Full Index:</strong> ' . $full_count . ' countries <span style="color:#666;font-size:12px;">(all 3 pillars)</span></div>';
        echo '<div><strong style="color:#ed6c02;">Partial Index:</strong> ' . $partial_count . ' countries <span style="color:#666;font-size:12px;">(2/3 pillars)</span></div>';
        echo '<div><strong style="color:#d32f2f;">Excluded:</strong> ' . $excluded_count . ' countries <span style="color:#666;font-size:12px;">(&lt;2 pillars)</span></div>';
        echo '<div><strong style="color:#1976d2;">Total Scored:</strong> ' . $total_count . ' countries</div>';
        echo '</div>';
        echo '</div></div>';
    }

    // ─── FRESHNESS & SYSTEM STATUS ──────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#f9f9f9;">';
    echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:30px; padding:10px 15px;">';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Energy</strong><span style="font-size:14px;">' . $pillar_freshness['energy'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">HHI</strong><span style="font-size:14px;">' . $pillar_freshness['hhi'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Maritime</strong><span style="font-size:14px;">' . $pillar_freshness['maritime'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Composite Index</strong><span style="font-size:14px;">' . $composite_fresh . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Build Lock</strong><span style="font-size:14px;">🔓 Free</span></div>';
    $last_run = null;
    if ( $sivi_status && isset( $sivi_status['last_run'] ) ) {
        $last_run = $sivi_status['last_run'];
    }
    if ( isset( $last_cron['sivi_daily'] ) && isset( $last_cron['sivi_daily']['last_run'] ) ) {
        if ( ! $last_run || strtotime( $last_cron['sivi_daily']['last_run'] ) > strtotime( $last_run ) ) {
            $last_run = $last_cron['sivi_daily']['last_run'];
        }
    }
    $last_fire_display = $last_run ? $last_run . ' ✅' : 'Never ❌';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Last Real wp-cron Fire</strong><span style="font-size:14px;">' . $last_fire_display . '</span></div>';
    echo '<div style="margin-left:auto;">';
    echo '<form method="post">';
    wp_nonce_field( 'sivi_force_daily_cron_action' );
    echo '<input type="submit" name="sivi_force_daily_cron" class="button button-secondary" value="🧪 Force Daily Cron Now" style="font-size:12px;">';
    echo '</form>';
    echo '</div>';
    echo '</div></div>';

    if ( get_transient( 'sivi_auto_build_failed' ) ) {
        echo '<div class="notice notice-error"><p>⚠️ The automated weekly build failed to fetch complete data. Please run a manual refresh.</p></div>';
        delete_transient( 'sivi_auto_build_failed' );
    }

    echo '<div style="margin-top:20px;">';

    // ─── CRON STATUS ──────────────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Cron &amp; Automation</h2></div>';
    echo '<div class="inside">';
    echo '<p>Automated weekly refresh: <strong>' . ( $next_cron ? 'ACTIVE — next run ' . esc_html( date_i18n( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : 'NOT SCHEDULED' ) . '</strong></p>';
    if ( $sivi_status ) {
        echo '<p>Last weekly cron run: <strong>' . esc_html( $sivi_status['status'] ) . '</strong> at ' . esc_html( $sivi_status['last_run'] ) . ' — ' . esc_html( $sivi_status['message'] ) . '</p>';
    }
    if ( isset( $last_cron['sivi_daily'] ) ) {
        echo '<p>Last daily cron run: <strong>' . esc_html( $last_cron['sivi_daily']['status'] ) . '</strong> at ' . esc_html( $last_cron['sivi_daily']['last_run'] ) . ' — ' . esc_html( $last_cron['sivi_daily']['message'] ) . '</p>';
    }
    echo '</div></div>';

    // ─── PILLAR DATA LAYER ──────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database"></span> Pillar Data Layer</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;"><strong>Fetch from Central Data</strong> — uses shared Reference Data cache (async).<br>';
    echo '<strong>Fetch from API Directly</strong> — bypasses Reference Data, calls API directly (sync, fallback).</p>';
    echo '<table class="widefat striped"><thead><tr><th>Pillar</th><th>Status</th><th>Fetch from Central</th><th>Fetch API Direct</th><th>Flush</th></tr></thead><tbody>';
    foreach ( array( 'energy' => 'Energy', 'hhi' => 'HHI', 'maritime' => 'Maritime' ) as $key => $label ) {
        $store = get_option( constant( 'SIVI_' . strtoupper( $key ) . '_KEY' ), array() );
        $data = $store['data'] ?? array();
        $count = is_array( $data ) ? count( array_filter( $data, function( $row ) { return isset( $row['value'] ) && is_numeric( $row['value'] ); } ) ) : 0;
        $status = $count > 0 ? '<span style="color:#2e7d32;">Cached ✓ (' . $count . ')</span>' : '<span style="color:#d63638;">Not Cached</span>';
        echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $status . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'sivi_fetch_' . $key . '_action' );
        echo '<input type="submit" name="sivi_fetch_' . $key . '" class="button button-small" style="min-width:140px;" value="📥 Fetch (Async)">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'sivi_fetch_api_' . $key . '_action' );
        echo '<input type="submit" name="sivi_fetch_api_' . $key . '" class="button button-small button-secondary" style="min-width:140px;" value="🔌 API Direct (Sync)">';
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

    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'sivi_fetch_all_async_action' );
    echo '<input type="submit" name="sivi_fetch_all_async" class="button button-secondary" style="min-width:180px; font-weight:bold;" value="📥 Refresh All Pillars (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will fetch data directly from the API for ALL pillars, bypassing the Reference Data layer. This is a fallback. Continue?\');">';
    wp_nonce_field( 'sivi_emergency_api_build_action' );
    echo '<input type="submit" name="sivi_emergency_api_build" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638; font-weight:bold;" value="🚨 Emergency API → Build (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will delete ALL pillar caches and the composite. Continue?\');">';
    wp_nonce_field( 'sivi_flush_all_action' );
    echo '<input type="submit" name="sivi_flush_all_confirmed" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Flush ALL Caches">';
    echo '</form>';
    echo '</div>';

    echo '<p style="color:#666; font-size:12px; margin:0;"><strong>Build from Cache</strong> — uses existing pillar data (no API calls).<br>';
    echo '<strong>Refresh All Pillars (Async)</strong> — fetches fresh data from central cache in the background.<br>';
    echo '<strong>Emergency API</strong> — falls back to direct API calls (use when central cache is broken).<br>';
    echo '<strong>Flush ALL Caches</strong> — deletes all pillar and composite data (destructive).</p>';
    echo '</div></div>';

    // ─── SENSITIVITY TESTING ──────────────────────────────────────
    $scenarios = sivi_list_scenarios();
    $baseline = get_option( SIVI_OPTION_KEY );

    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-admin-generic"></span> 🔬 Sensitivity Testing (Research)</h2></div>';
    echo '<div class="inside">';

    // Preset weights
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

    // ─── PREVIEW TABLES ──────────────────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $countries = $existing['countries'];
        // Sort by composite score descending (most vulnerable first)
        uasort( $countries, function( $a, $b ) {
            return ( $b['sivi_structural'] ?? 0 ) <=> ( $a['sivi_structural'] ?? 0 );
        } );

        $top_vulnerable = array_slice( $countries, 0, 10, true );
        $least_vulnerable = array_slice( $countries, -10, 10, true );

        echo '<div style="margin-top:20px;">';

        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📊 10 Most Vulnerable Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Rank</th><th>Country</th><th>Vulnerability Score</th><th>Energy</th><th>HHI</th><th>Maritime</th><th>Coverage</th></tr></thead><tbody>';
        foreach ( $top_vulnerable as $name => $row ) {
            $rank = $row['rank_display']['string_format'] ?? '—';
            $energy = $row['energy_dependency_percentile'] ?? '—';
            $hhi = $row['supplier_concentration_percentile'] ?? '—';
            $maritime = $row['maritime_vulnerability_percentile'] ?? '—';
            $cov = $row['coverage'] ?? 'partial';
            $cov_style = $cov === 'full' ? '#2e7d32' : '#b26a00';
            echo '<tr><td><strong>' . esc_html( $rank ) . '</strong></td><td><strong>' . esc_html( $name ) . '</strong></td><td>' . esc_html( $row['sivi_structural'] ?? '—' ) . '</td><td>' . esc_html( $energy ) . '</td><td>' . esc_html( $hhi ) . '</td><td>' . esc_html( $maritime ) . '</td><td style="color:' . $cov_style . ';">' . esc_html( $cov ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📊 10 Least Vulnerable Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Rank</th><th>Country</th><th>Vulnerability Score</th><th>Energy</th><th>HHI</th><th>Maritime</th><th>Coverage</th></tr></thead><tbody>';
        foreach ( $least_vulnerable as $name => $row ) {
            $rank = $row['rank_display']['string_format'] ?? '—';
            $energy = $row['energy_dependency_percentile'] ?? '—';
            $hhi = $row['supplier_concentration_percentile'] ?? '—';
            $maritime = $row['maritime_vulnerability_percentile'] ?? '—';
            $cov = $row['coverage'] ?? 'partial';
            $cov_style = $cov === 'full' ? '#2e7d32' : '#b26a00';
            echo '<tr><td><strong>' . esc_html( $rank ) . '</strong></td><td><strong>' . esc_html( $name ) . '</strong></td><td>' . esc_html( $row['sivi_structural'] ?? '—' ) . '</td><td>' . esc_html( $energy ) . '</td><td>' . esc_html( $hhi ) . '</td><td>' . esc_html( $maritime ) . '</td><td style="color:' . $cov_style . ';">' . esc_html( $cov ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        if ( ! empty( $existing['excluded_detail'] ) ) {
            echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
            echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">🚫 Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</summary>';
            echo '<div style="padding:15px; background:#fff;">';
            echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
            foreach ( $existing['excluded_detail'] as $name => $reason ) {
                echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $reason['reason'] ?? json_encode( $reason ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div></details>';
        }

        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📄 Raw JSON Output</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) ) . '</textarea>';
        echo '</div></details>';
        echo '</div>';
    }

    echo '</div>';
}
