/**
 * Sovereign Infrastructure Vulnerability Index (SIVI) — v1.0.0
 * Formerly known as CII (Critical Infrastructure Vulnerability Index).
 *
 * @package Blomstra\Insights\Indices\SIVI
 * @since   1.0.0
 * @version 1.0.0
 *
 * CHANGES (v1.0.0):
 * - Renamed to Sovereign Infrastructure Vulnerability Index (SIVI)
 * - Admin page labels and titles updated
 * - Shortcode alias kept for backward compatibility
 * - Ranking: highest composite score = #1 (most vulnerable)
 *
 * Methodology and functionality unchanged — only naming & rank direction.
 */

// ─── CONSTANTS ──────────────────────────────────────────────────────

if ( ! defined( 'CII_VERSION' ) ) {
    define( 'CII_VERSION', '1.0.0' );
}

// EIA
if ( ! defined( 'EIA_BASE_URL' ) ) {
    define( 'EIA_BASE_URL', 'https://api.eia.gov/v2/international/data/' );
}
if ( ! defined( 'EIA_FUEL_PRODUCT_IDS' ) ) {
    define( 'EIA_FUEL_PRODUCT_IDS', array(
        '4411' => 'Coal',
        '4413' => 'Natural gas',
        '4415' => 'Petroleum and other liquids',
        '4417' => 'Nuclear',
        '4418' => 'Renewables and other',
    ) );
}
if ( ! defined( 'EIA_ACTIVITY_PROD' ) ) {
    define( 'EIA_ACTIVITY_PROD', '1' );
}
if ( ! defined( 'EIA_ACTIVITY_CONS' ) ) {
    define( 'EIA_ACTIVITY_CONS', '2' );
}
if ( ! defined( 'EIA_UNIT' ) ) {
    define( 'EIA_UNIT', 'QBTU' );
}
if ( ! defined( 'EIA_CACHE_TTL' ) ) {
    define( 'EIA_CACHE_TTL', 12 * HOUR_IN_SECONDS );
}
if ( ! defined( 'EIA_CACHE_KEY_PREFIX' ) ) {
    define( 'EIA_CACHE_KEY_PREFIX', 'cii_energy_' );
}

// Comtrade
if ( ! defined( 'COMTRADE_BASE_URL' ) ) {
    define( 'COMTRADE_BASE_URL', 'https://comtradeapi.un.org/data/v1/get/C/A/HS' );
}
if ( ! defined( 'COMTRADE_CACHE_TTL' ) ) {
    define( 'COMTRADE_CACHE_TTL', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'COMTRADE_CACHE_KEY_PREFIX' ) ) {
    define( 'COMTRADE_CACHE_KEY_PREFIX', 'cii_hhi_' );
}
if ( ! defined( 'COMTRADE_REPORTER_CACHE_TTL' ) ) {
    define( 'COMTRADE_REPORTER_CACHE_TTL', WEEK_IN_SECONDS );
}
if ( ! defined( 'COMTRADE_REPORTER_URL' ) ) {
    define( 'COMTRADE_REPORTER_URL', 'https://comtradeapi.un.org/files/v1/app/reference/Reporters.json' );
}
if ( ! defined( 'COMTRADE_HHI_LOOKBACK' ) ) {
    define( 'COMTRADE_HHI_LOOKBACK', 4 );
}
if ( ! defined( 'CII_COMTRADE_QUOTA_EXHAUSTED' ) ) {
    define( 'CII_COMTRADE_QUOTA_EXHAUSTED', '__CII_QUOTA_EXHAUSTED__' );
}

// Maritime
if ( ! defined( 'CII_MARITIME_CODE' ) ) {
    define( 'CII_MARITIME_CODE', 'IS.SHP.GCNW.XQ' );
}
if ( ! defined( 'CII_MARITIME_CACHE_TTL' ) ) {
    define( 'CII_MARITIME_CACHE_TTL', 7 * DAY_IN_SECONDS );
}
if ( ! defined( 'CII_MARITIME_CACHE_KEY_PREFIX' ) ) {
    define( 'CII_MARITIME_CACHE_KEY_PREFIX', 'cii_maritime_' );
}

// v3.0: Lock TTL (5 minutes)
if ( ! defined( 'CII_LOCK_TTL' ) ) {
    define( 'CII_LOCK_TTL', 5 * MINUTE_IN_SECONDS );
}
// v3.0: Freshness threshold (10 days)
if ( ! defined( 'CII_FRESHNESS_PILLAR' ) ) {
    define( 'CII_FRESHNESS_PILLAR', 10 * DAY_IN_SECONDS );
}

// ─── LANDLOCKED FALLBACK ──────────────────────────────────────────

if ( ! defined( 'CII_LANDLOCKED_ISO3_FALLBACK' ) ) {
    define( 'CII_LANDLOCKED_ISO3_FALLBACK', array(
        'AFG', 'AND', 'ARM', 'AUT', 'AZE', 'BLR', 'BTN', 'BOL', 'BWA', 'BFA',
        'BDI', 'CAF', 'TCD', 'CZE', 'ETH', 'SWZ', 'HUN', 'KAZ', 'KGZ', 'LAO',
        'LSO', 'LIE', 'LUX', 'MWI', 'MLI', 'MDA', 'MNG', 'NPL', 'NER', 'MKD',
        'PRY', 'RWA', 'SMR', 'SRB', 'SVK', 'SSD', 'CHE', 'TJK', 'TKM', 'UGA',
        'UZB', 'VAT', 'ZMB', 'ZWE',
    ) );
}

// ─── REFERENCE DATA WRAPPERS (primary + fallback) ─────────────────

add_action( 'admin_notices', function () {
    if ( ! function_exists( 'blomstra_get_global_country_list' ) ) {
        echo '<div class="notice notice-warning"><p><strong>SIVI Index:</strong> the "Blomstra Reference Data" utility snippet isn\'t active. SIVI will still work using its own fallback API calls, but activating that snippet lets SIVI share its collected data (and any future fixes to it) with other index tools instead of duplicating the work.</p></div>';
    }
} );

if ( ! function_exists( 'cii_is_landlocked' ) ) {
    function cii_is_landlocked( $iso3 ) {
        if ( function_exists( 'blomstra_is_landlocked' ) ) {
            return blomstra_is_landlocked( $iso3 );
        }
        return in_array( $iso3, CII_LANDLOCKED_ISO3_FALLBACK, true );
    }
}

if ( ! function_exists( 'cii_get_global_country_list' ) ) {
    function cii_get_global_country_list() {
        if ( function_exists( 'blomstra_get_global_country_list' ) ) {
            $list = blomstra_get_global_country_list();
            if ( ! empty( $list ) ) {
                return $list;
            }
        }
        return cii_get_global_country_list_fallback();
    }
}

if ( ! function_exists( 'cii_get_global_country_list_fallback' ) ) {
    function cii_get_global_country_list_fallback() {
        $names = array();
        $page = 1;
        do {
            $url = "https://api.worldbank.org/v2/country?format=json&per_page=300&page={$page}";
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
}

if ( ! function_exists( 'cii_hhi_reporter_map' ) ) {
    function cii_hhi_reporter_map() {
        if ( function_exists( 'blomstra_get_comtrade_reporter_map' ) ) {
            $map = blomstra_get_comtrade_reporter_map();
            if ( ! empty( $map ) ) {
                return $map;
            }
        }
        return cii_hhi_reporter_map_fallback();
    }
}

if ( ! function_exists( 'cii_hhi_reporter_map_fallback' ) ) {
    function cii_hhi_reporter_map_fallback() {
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
}

// ─── COMPOSITE WEIGHTS ─────────────────────────────────────────────

if ( ! defined( 'CII_WEIGHT_ENERGY' ) ) {
    define( 'CII_WEIGHT_ENERGY', 0.3333 );
}
if ( ! defined( 'CII_WEIGHT_HHI' ) ) {
    define( 'CII_WEIGHT_HHI', 0.3333 );
}
if ( ! defined( 'CII_WEIGHT_MARITIME' ) ) {
    define( 'CII_WEIGHT_MARITIME', 0.3334 );
}
if ( ! defined( 'CII_MIN_PILLARS_REQUIRED' ) ) {
    define( 'CII_MIN_PILLARS_REQUIRED', 2 );
}

// ─── TEST COUNTRY LIST ────────────────────────────────────────────

if ( ! function_exists( 'cii_test_country_list' ) ) {
    function cii_test_country_list() {
        return array(
            'SWE' => 'Sweden',
            'USA' => 'United States',
            'CHN' => 'China',
            'DEU' => 'Germany',
            'FRA' => 'France',
            'GBR' => 'United Kingdom',
            'JPN' => 'Japan',
            'IND' => 'India',
            'NOR' => 'Norway',
            'KOR' => 'Korea, Rep.',
        );
    }
}

// ─── ENERGY PILLAR ──────────────────────────────────────────────────

if ( ! defined( 'CII_EIA_CHUNK_SIZE' ) ) {
    define( 'CII_EIA_CHUNK_SIZE', 25 );
}

if ( ! function_exists( 'cii_eia_fetch_activity_batch_fallback' ) ) {
    function cii_eia_fetch_activity_batch_fallback( $country_codes, $activity_id, $product_id, $attempt = 1 ) {
        if ( ! defined( 'EIA_API_KEY' ) || EIA_API_KEY === '' ) {
            return array( 'status' => 'failed', 'rows' => array(), 'error' => 'API key missing' );
        }

        $scalar_args = array(
            'api_key'              => EIA_API_KEY,
            'facets[activityId][]' => $activity_id,
            'facets[productId][]'  => $product_id,
            'facets[unit][]'       => EIA_UNIT,
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
            cii_log_eia_call( $chunk_label, $activity_id, $product_id, $should_retry ? 'rate_limited_or_network' : 'http_error', $fail_reason . ' (attempt ' . $attempt . ')' );
            if ( $should_retry && $attempt < 3 ) {
                sleep( 2 * $attempt );
                return cii_eia_fetch_activity_batch_fallback( $country_codes, $activity_id, $product_id, $attempt + 1 );
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
            cii_log_eia_call( $chunk_label, $activity_id, $product_id, 'empty', 'HTTP 200, valid shape, zero rows for this entire chunk' );
        } elseif ( ! empty( $missing_from_chunk ) ) {
            cii_log_eia_call( $chunk_label, $activity_id, $product_id, 'partial', count( $rows ) . ' rows, but ' . count( $missing_from_chunk ) . '/' . count( $country_codes ) . ' requested countries had no row in this chunk: ' . implode( ',', $missing_from_chunk ) );
        } else {
            cii_log_eia_call( $chunk_label, $activity_id, $product_id, 'ok', count( $rows ) . ' rows, all ' . count( $country_codes ) . ' requested countries represented' );
        }

        return array( 'status' => 'ok', 'rows' => $rows, 'error' => null );
    }
}

if ( ! function_exists( 'cii_eia_pick_latest_per_country_fallback' ) ) {
    function cii_eia_pick_latest_per_country_fallback( $rows ) {
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
}

if ( ! function_exists( 'cii_eia_aggregate_energy_dependency' ) ) {
    function cii_eia_aggregate_energy_dependency( $iso3_list, $consumption_by_fuel, $production_by_fuel ) {
        $out = array();
        foreach ( $iso3_list as $iso3 ) {
            $fuels = array();
            foreach ( EIA_FUEL_PRODUCT_IDS as $product_id => $fuel_name ) {
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
                'note'   => count( $fuels ) . '/' . count( EIA_FUEL_PRODUCT_IDS ) . ' fuels had usable data',
                'fuels'  => $fuels,
            );
        }
        return $out;
    }
}

if ( ! function_exists( 'cii_compute_energy_dependency_batch_fallback' ) ) {
    function cii_compute_energy_dependency_batch_fallback( $iso3_list, $checkpoint_callback = null ) {
        $consumption_by_fuel = array();
        $production_by_fuel = array();

        foreach ( EIA_FUEL_PRODUCT_IDS as $product_id => $fuel_name ) {
            $chunks = array_chunk( $iso3_list, CII_EIA_CHUNK_SIZE );

            $consumption_by_fuel[ $product_id ] = array();
            foreach ( $chunks as $chunk ) {
                $result = cii_eia_fetch_activity_batch_fallback( $chunk, EIA_ACTIVITY_CONS, $product_id );
                if ( $result['status'] === 'ok' ) {
                    $latest = cii_eia_pick_latest_per_country_fallback( $result['rows'] );
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
                $result = cii_eia_fetch_activity_batch_fallback( $chunk, EIA_ACTIVITY_PROD, $product_id );
                if ( $result['status'] === 'ok' ) {
                    $latest = cii_eia_pick_latest_per_country_fallback( $result['rows'] );
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
                $partial = cii_eia_aggregate_energy_dependency( $iso3_list, $consumption_by_fuel, $production_by_fuel );
                $checkpoint_callback( $partial );
            }
        }

        return cii_eia_aggregate_energy_dependency( $iso3_list, $consumption_by_fuel, $production_by_fuel );
    }
}

if ( ! function_exists( 'cii_refresh_energy_pillar_fallback' ) ) {
    function cii_refresh_energy_pillar_fallback( $countries = null ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }
        if ( $countries === null ) {
            $countries = cii_get_global_country_list();
        }
        $iso3_list = array_keys( $countries );

        $checkpoint = function ( $partial_computed ) use ( $iso3_list ) {
            $existing_now = get_option( 'cii_energy_pillar', array() );
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
            $merged_now = array_merge( $existing_now, $partial_results );
            update_option( 'cii_energy_pillar', $merged_now, false );
        };

        $computed = cii_compute_energy_dependency_batch_fallback( $iso3_list, $checkpoint );

        $results = array();
        foreach ( $iso3_list as $iso3 ) {
            $c = $computed[ $iso3 ] ?? array( 'value' => null, 'source' => 'EIA', 'note' => 'not returned by batch computation' );
            $results[ $iso3 ] = array(
                'value'        => $c['value'],
                'source'       => $c['source'],
                'note'         => $c['note'] ?? '',
                'last_updated' => current_time( 'mysql' ),
            );
            set_transient( EIA_CACHE_KEY_PREFIX . $iso3, $results[ $iso3 ], EIA_CACHE_TTL );
        }
        $merged = array_merge( get_option( 'cii_energy_pillar', array() ), $results );
        update_option( 'cii_energy_pillar', $merged, false );
        return $results;
    }
}

if ( ! function_exists( 'cii_persist_energy_results' ) ) {
    function cii_persist_energy_results( $iso3_list, $computed ) {
        $results = array();
        foreach ( $iso3_list as $iso3 ) {
            $c = $computed[ $iso3 ] ?? array( 'value' => null, 'source' => 'EIA', 'note' => 'not returned' );
            $results[ $iso3 ] = array(
                'value'        => $c['value'],
                'source'       => $c['source'],
                'note'         => $c['note'] ?? '',
                'last_updated' => current_time( 'mysql' ),
            );
            set_transient( EIA_CACHE_KEY_PREFIX . $iso3, $results[ $iso3 ], EIA_CACHE_TTL );
        }
        $merged = array_merge( get_option( 'cii_energy_pillar', array() ), $results );
        update_option( 'cii_energy_pillar', $merged, false );
        return $results;
    }
}

if ( ! function_exists( 'cii_refresh_energy_pillar' ) ) {
    function cii_refresh_energy_pillar( $countries = null, $source = 'auto' ) {
        if ( $countries === null ) {
            $countries = cii_get_global_country_list();
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
            $computed = cii_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
            return cii_persist_energy_results( $iso3_list, $computed );
        }

        if ( $source === 'central_cached' ) {
            if ( ! function_exists( 'blomstra_get_eia_raw_data' ) ) {
                return array( 'error' => 'Central model not active.' );
            }
            $raw = blomstra_get_eia_raw_data();
            if ( empty( $raw['consumption'] ) && empty( $raw['production'] ) ) {
                return array( 'error' => 'Central cache has no EIA data yet.' );
            }
            $computed = cii_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
            return cii_persist_energy_results( $iso3_list, $computed );
        }

        if ( $source === 'api' ) {
            return cii_refresh_energy_pillar_fallback( $countries );
        }

        // auto: try central, fall back silently on failure/empty
        if ( function_exists( 'blomstra_refresh_eia_raw_data' ) ) {
            $raw = blomstra_refresh_eia_raw_data( $iso3_list );
            if ( ! empty( $raw['consumption'] ) || ! empty( $raw['production'] ) ) {
                $computed = cii_eia_aggregate_energy_dependency( $iso3_list, $raw['consumption'], $raw['production'] );
                return cii_persist_energy_results( $iso3_list, $computed );
            }
        }
        return cii_refresh_energy_pillar_fallback( $countries );
    }
}

if ( ! function_exists( 'cii_debug_fetch_eia_activity' ) ) {
    function cii_debug_fetch_eia_activity( $country_code, $product_id, $activity_id ) {
        $args = array(
            'api_key'                   => defined( 'EIA_API_KEY' ) ? EIA_API_KEY : '',
            'facets[activityId][]'      => $activity_id,
            'facets[productId][]'       => $product_id,
            'facets[countryRegionId][]' => $country_code,
            'facets[unit][]'            => EIA_UNIT,
            'frequency'                 => 'annual',
            'data[]'                    => 'value',
            'sort[0][column]'           => 'period',
            'sort[0][direction]'        => 'desc',
            'length'                    => 3,
        );
        $url = EIA_BASE_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
        $out = array( 'url' => preg_replace( '/api_key=[^&]+/', 'api_key=REDACTED', $url ) );

        $response = wp_remote_get( $url, array( 'timeout' => 20 ) );
        if ( is_wp_error( $response ) ) {
            $out['error'] = $response->get_error_message();
            return $out;
        }
        $out['http_code']    = wp_remote_retrieve_response_code( $response );
        $out['body_snippet'] = substr( wp_remote_retrieve_body( $response ), 0, 800 );
        return $out;
    }
}
// ─── MARITIME PILLAR ──────────────────────────────────────────────

if ( ! function_exists( 'cii_fetch_maritime_raw' ) ) {
    function cii_fetch_maritime_raw() {
        if ( function_exists( 'blomstra_get_maritime_raw' ) ) {
            $data = blomstra_get_maritime_raw();
            if ( ! empty( $data ) ) {
                return $data;
            }
        }
        return cii_fetch_maritime_raw_fallback();
    }
}

if ( ! function_exists( 'cii_fetch_maritime_raw_fallback' ) ) {
    function cii_fetch_maritime_raw_fallback( $attempt = 1 ) {
        $current_year = (int) current_time( 'Y' );
        $start_year   = $current_year - 20;
        $url = "https://api.worldbank.org/v2/country/all/indicator/" . CII_MARITIME_CODE . "?format=json&per_page=20000&date={$start_year}:{$current_year}";

        $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

        if ( is_wp_error( $response ) && $attempt < 2 ) {
            sleep( 3 );
            return cii_fetch_maritime_raw_fallback( $attempt + 1 );
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
}

if ( ! function_exists( 'cii_refresh_maritime_pillar' ) ) {
    function cii_refresh_maritime_pillar( $source = 'auto' ) {
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
            $data = cii_fetch_maritime_raw_fallback();
            if ( empty( $data ) ) {
                return array( 'error' => 'Direct API fallback call itself failed — see the PHP error log for details.' );
            }
        } else {
            $data = cii_fetch_maritime_raw();
        }

        $results = array();
        $all_countries = cii_get_global_country_list();
        foreach ( $all_countries as $iso3 => $name ) {
            if ( isset( $data[ $iso3 ] ) ) {
                $results[ $iso3 ] = array(
                    'value'        => $data[ $iso3 ]['value'],
                    'year'         => $data[ $iso3 ]['year'],
                    'source'       => 'World Bank WDI (' . CII_MARITIME_CODE . ')',
                    'last_updated' => current_time( 'mysql' ),
                );
            } elseif ( cii_is_landlocked( $iso3 ) ) {
                $results[ $iso3 ] = array(
                    'value'        => 0.0,
                    'year'         => null,
                    'source'       => 'Structural zero — landlocked (no direct maritime access; UN-OHRLLS LLDC list + developed landlocked states)',
                    'last_updated' => current_time( 'mysql' ),
                );
            } else {
                $results[ $iso3 ] = array(
                    'value'        => null,
                    'year'         => null,
                    'source'       => 'World Bank WDI',
                    'last_updated' => current_time( 'mysql' ),
                );
            }
            set_transient( CII_MARITIME_CACHE_KEY_PREFIX . $iso3, $results[ $iso3 ], CII_MARITIME_CACHE_TTL );
        }
        update_option( 'cii_maritime_pillar', $results, false );
        return $results;
    }
}
// ─── COMTRADE FETCH (FALLBACK) ────────────────────────────────────

if ( ! function_exists( 'cii_log_comtrade_call' ) ) {
    function cii_log_comtrade_call( $reporter_code, $year, $outcome, $detail ) {
        $log = get_option( 'cii_comtrade_call_log', array() );
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
        update_option( 'cii_comtrade_call_log', $log, false );
    }
}

if ( ! function_exists( 'cii_log_eia_call' ) ) {
    function cii_log_eia_call( $chunk_label, $activity_id, $product_id, $outcome, $detail ) {
        $log = get_option( 'cii_eia_call_log', array() );
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
        update_option( 'cii_eia_call_log', $log, false );
    }
}

if ( ! function_exists( 'cii_comtrade_fetch_partner_imports_batch_fallback' ) ) {
    function cii_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt = 1 ) {
        if ( ! defined( 'COMTRADE_PRIMARY_KEY' ) || COMTRADE_PRIMARY_KEY === '' ) {
            cii_log_comtrade_call( implode( ',', $reporter_codes ), $year, 'network_error', 'COMTRADE_PRIMARY_KEY not defined/empty' );
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
                cii_log_comtrade_call( $chunk_label, $year, 'network_error', $fail_reason );
                if ( $attempt < 3 ) {
                    sleep( 3 * $attempt );
                    return cii_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt + 1 );
                }
                return null;
            }

            $code = wp_remote_retrieve_response_code( $response );

            if ( $code === 429 || $code === 403 ) {
                $body_snip = substr( wp_remote_retrieve_body( $response ), 0, 300 );

                if ( preg_match( '/[Tt]ry again in\s+(\d+)\s+seconds?/', $body_snip, $m ) && (int) $m[1] <= 90 && $attempt <= 2 ) {
                    $wait = (int) $m[1] + 2;
                    error_log( 'SIVI Comtrade batch short-term rate limit (' . $chunk_label . ', year ' . $year . '): waiting ' . $wait . 's then retrying (attempt ' . $attempt . ')' );
                    cii_log_comtrade_call( $chunk_label, $year, 'rate_limited_retrying', 'HTTP ' . $code . ' — short-term throttle, waiting ' . $wait . 's then retrying: ' . $body_snip );
                    sleep( $wait );
                    return cii_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt + 1 );
                }

                $fail_reason = 'HTTP ' . $code . ' — likely quota: ' . $body_snip;
                error_log( 'SIVI Comtrade batch fetch FAILED (' . $chunk_label . ', year ' . $year . ', attempt ' . $attempt . '): ' . $fail_reason );
                cii_log_comtrade_call( $chunk_label, $year, 'quota_or_rate_limit', 'HTTP ' . $code . ' (attempt ' . $attempt . '): ' . $fail_reason );
                return CII_COMTRADE_QUOTA_EXHAUSTED;
            }

            if ( $code !== 200 ) {
                $fail_reason = 'HTTP ' . $code . ' — body: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 );
                error_log( 'SIVI Comtrade batch fetch FAILED (' . $chunk_label . ', year ' . $year . ', attempt ' . $attempt . '): ' . $fail_reason );
                cii_log_comtrade_call( $chunk_label, $year, 'http_error', 'HTTP ' . $code . ' (attempt ' . $attempt . '): ' . $fail_reason );
                if ( $code >= 500 && $attempt < 3 ) {
                    sleep( 3 * $attempt );
                    return cii_comtrade_fetch_partner_imports_batch_fallback( $reporter_codes, $year, $attempt + 1 );
                }
                return null;
            }

            $raw_body = wp_remote_retrieve_body( $response );
            if ( strlen( $raw_body ) > 15 * 1024 * 1024 ) {
                $fail_reason = 'response body ' . round( strlen( $raw_body ) / 1024 / 1024, 1 ) . 'MB — too large to safely decode, skipping to avoid a memory-exhaustion crash';
                error_log( 'SIVI Comtrade batch fetch SKIPPED (' . $chunk_label . ', year ' . $year . ', page ' . $page . '): ' . $fail_reason );
                cii_log_comtrade_call( $chunk_label, $year, 'oversized_response', $fail_reason );
                return null;
            }

            $body = json_decode( $raw_body, true );
            unset( $raw_body );
            if ( isset( $body['error'] ) && $body['error'] !== '' ) {
                error_log( 'SIVI Comtrade batch API error field (' . $chunk_label . ', year ' . $year . '): ' . $body['error'] );
                cii_log_comtrade_call( $chunk_label, $year, 'api_error', (string) $body['error'] );
                return null;
            }
            if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
                error_log( 'SIVI Comtrade batch unexpected response shape (' . $chunk_label . ', year ' . $year . '): missing data array' );
                cii_log_comtrade_call( $chunk_label, $year, 'bad_shape', 'missing data array' );
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
            cii_log_comtrade_call( $chunk_label, $year, 'empty', 'HTTP 200, valid shape, zero rows across all pages' );
            return array();
        }

        $missing_world_total = array();
        foreach ( $reporter_codes as $rc ) {
            if ( ! isset( $found_world_total_for[ $rc ] ) ) {
                $missing_world_total[] = $rc;
            }
        }

        if ( ! empty( $missing_world_total ) ) {
            cii_log_comtrade_call( $chunk_label, $year, 'partial', count( $all_rows ) . ' rows returned, but ' . count( $missing_world_total ) . '/' . count( $reporter_codes ) . ' requested reporters had no partnerCode=0 world-total row even after pagination: ' . implode( ',', $missing_world_total ) . ' (' . ( $page - 1 ) . ' page(s))' );
        } else {
            cii_log_comtrade_call( $chunk_label, $year, 'ok', count( $all_rows ) . ' rows returned across ' . count( $reporter_codes ) . ' reporters, all represented (' . ( $page - 1 ) . ' page(s))' );
        }

        return $all_rows;
    }
}

if ( ! function_exists( 'cii_compute_hhi_from_batch_rows_fallback' ) ) {
    function cii_compute_hhi_from_batch_rows_fallback( $rows, $reporter_codes, $year ) {
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
}

// ─── HHI PILLAR ────────────────────────────────────────────────────

if ( ! defined( 'CII_HHI_CHUNK_SIZE' ) ) {
    define( 'CII_HHI_CHUNK_SIZE', 50 );
}

if ( ! function_exists( 'cii_refresh_hhi_pillar_fallback' ) ) {
    function cii_refresh_hhi_pillar_fallback( $year = null, $iso3_list = null ) {
        $run_started = current_time( 'mysql' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 900 );
        }
        if ( $year === null ) {
            $year = (int) current_time( 'Y' ) - 1;
        }
        $reporter_map = cii_hhi_reporter_map();
        if ( $iso3_list === null ) {
            $iso3_list = array_keys( cii_get_global_country_list() );
        }
        $existing = get_option( 'cii_hhi_pillar', array() );
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
            'chunk_size'           => CII_HHI_CHUNK_SIZE,
            'last_checkpoint'      => null,
        );

        update_option( 'cii_hhi_refresh_summary', $summary, false );

        $cii_hhi_checkpoint = function () use ( &$results, &$summary ) {
            $existing_now = get_option( 'cii_hhi_pillar', array() );
            $merged_now   = array_merge( $existing_now, $results );
            update_option( 'cii_hhi_pillar', $merged_now, false );
            $summary['last_checkpoint'] = current_time( 'mysql' );
            update_option( 'cii_hhi_refresh_summary', $summary, false );
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

        for ( $offset = 0; $offset <= COMTRADE_HHI_LOOKBACK && ! empty( $pending ) && ! $quota_dead; $offset++ ) {
            $try_year      = $year - $offset;
            $still_pending = array();
            $chunks        = array_chunk( $pending, CII_HHI_CHUNK_SIZE, true );

            foreach ( $chunks as $chunk ) {
                if ( $quota_dead ) {
                    foreach ( $chunk as $iso3 => $code ) {
                        $still_pending[ $iso3 ] = $code;
                    }
                    continue;
                }

                $codes_in_chunk = array_values( $chunk );
                $rows = cii_comtrade_fetch_partner_imports_batch_fallback( $codes_in_chunk, $try_year );

                if ( $rows === CII_COMTRADE_QUOTA_EXHAUSTED ) {
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

                $computed_by_code = cii_compute_hhi_from_batch_rows_fallback( $rows, $codes_in_chunk, $try_year );
                unset( $rows );

                foreach ( $chunk as $iso3 => $code ) {
                    if ( isset( $computed_by_code[ $code ] ) ) {
                        $summary['succeeded']++;
                        $results[ $iso3 ] = array(
                            'value' => $computed_by_code[ $code ]['value'], 'scale' => '0-10000', 'requested_year' => $year,
                            'actual_year' => $computed_by_code[ $code ]['year'], 'source' => 'Comtrade',
                            'last_updated' => current_time( 'mysql' ),
                        );
                        set_transient( COMTRADE_CACHE_KEY_PREFIX . $iso3, $results[ $iso3 ], COMTRADE_CACHE_TTL );
                    } else {
                        $still_pending[ $iso3 ] = $code;
                    }
                }

                $cii_hhi_checkpoint();
                sleep( 2 );
            }

            if ( $offset >= COMTRADE_HHI_LOOKBACK ) {
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

        $cii_hhi_checkpoint();
        $summary['run_finished'] = current_time( 'mysql' );
        update_option( 'cii_hhi_refresh_summary', $summary, false );

        return $results;
    }
}

// ─── HHI: PRIMARY (CENTRALIZED) + FALLBACK DISPATCHER ──────────────

if ( ! function_exists( 'cii_merge_hhi_into_pillar' ) ) {
    function cii_merge_hhi_into_pillar( $iso3_list ) {
        $central_data = function_exists( 'blomstra_get_comtrade_hhi_data' ) ? blomstra_get_comtrade_hhi_data() : array();
        $results = array();
        foreach ( $iso3_list as $iso3 ) {
            if ( isset( $central_data[ $iso3 ] ) ) {
                $results[ $iso3 ] = $central_data[ $iso3 ];
            }
        }
        $merged = array_merge( get_option( 'cii_hhi_pillar', array() ), $results );
        update_option( 'cii_hhi_pillar', $merged, false );
        return $results;
    }
}

if ( ! function_exists( 'cii_refresh_hhi_pillar' ) ) {
    function cii_refresh_hhi_pillar( $year = null, $iso3_list = null, $source = 'auto' ) {
        if ( $iso3_list === null ) {
            $iso3_list = array_keys( cii_get_global_country_list() );
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
            return cii_merge_hhi_into_pillar( $iso3_list );
        }

        if ( $source === 'central_cached' ) {
            if ( ! function_exists( 'blomstra_get_comtrade_hhi_data' ) ) {
                return array( 'error' => 'Central model not active.' );
            }
            $central_data = blomstra_get_comtrade_hhi_data();
            if ( empty( $central_data ) ) {
                return array( 'error' => 'Central cache has no HHI data yet.' );
            }
            return cii_merge_hhi_into_pillar( $iso3_list );
        }

        if ( $source === 'api' ) {
            return cii_refresh_hhi_pillar_fallback( $year, $iso3_list );
        }

        // auto: try central, fall back silently on failure/empty
        if ( function_exists( 'blomstra_refresh_comtrade_hhi_data' ) ) {
            blomstra_refresh_comtrade_hhi_data( $year, $iso3_list );
            $summary = get_option( 'blomstra_hhi_refresh_summary', array() );
            if ( ! empty( $summary ) && ( $summary['succeeded'] ?? 0 ) > 0 ) {
                return cii_merge_hhi_into_pillar( $iso3_list );
            }
        }
        return cii_refresh_hhi_pillar_fallback( $year, $iso3_list );
    }
}
// ─── PERCENTILE-RANK NORMALIZATION (v2.8) ──────────────────────────

if ( ! function_exists( 'cii_compute_percentile_ranks' ) ) {
    function cii_compute_percentile_ranks( $values_by_iso3 ) {
        $n = count( $values_by_iso3 );
        if ( $n === 0 ) {
            return array();
        }
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
}

// ─── COMPOSITE BUILDER ─────────────────────────────────────────────

if ( ! function_exists( 'cii_build_composite' ) ) {
    function cii_build_composite( $meta_source = 'manual' ) {
        $energy_data   = get_option( 'cii_energy_pillar', array() );
        $hhi_data      = get_option( 'cii_hhi_pillar', array() );
        $maritime_data = get_option( 'cii_maritime_pillar', array() );

        $energy_raw_values   = array();
        $hhi_raw_values      = array();
        $maritime_raw_values = array();

        foreach ( $energy_data as $iso3 => $row ) {
            if ( $row['value'] !== null ) { $energy_raw_values[ $iso3 ] = (float) $row['value']; }
        }
        foreach ( $hhi_data as $iso3 => $row ) {
            if ( $row['value'] !== null ) { $hhi_raw_values[ $iso3 ] = (float) $row['value']; }
        }
        foreach ( $maritime_data as $iso3 => $row ) {
            if ( $row['value'] !== null ) { $maritime_raw_values[ $iso3 ] = (float) $row['value']; }
        }

        $avg_energy   = ! empty( $energy_raw_values ) ? array_sum( $energy_raw_values ) / count( $energy_raw_values ) : null;
        $avg_hhi      = ! empty( $hhi_raw_values ) ? array_sum( $hhi_raw_values ) / count( $hhi_raw_values ) : null;
        $avg_maritime = ! empty( $maritime_raw_values ) ? array_sum( $maritime_raw_values ) / count( $maritime_raw_values ) : null;

        $energy_pct = cii_compute_percentile_ranks( $energy_raw_values );
        $hhi_pct    = cii_compute_percentile_ranks( $hhi_raw_values );
        $maritime_connectivity_pct  = cii_compute_percentile_ranks( $maritime_raw_values );
        $maritime_vulnerability_pct = array();
        foreach ( $maritime_connectivity_pct as $iso3 => $pct ) {
            $maritime_vulnerability_pct[ $iso3 ] = round( 100 - $pct, 2 );
        }

        $all_keys = array_unique( array_merge(
            array_keys( $energy_data ),
            array_keys( $hhi_data ),
            array_keys( $maritime_data )
        ) );

        $results  = array();
        $excluded = array();
        $all_pillar_names = array( 'energy', 'hhi', 'maritime' );

        foreach ( $all_keys as $iso3 ) {
            $present = array();
            if ( isset( $energy_pct[ $iso3 ] ) ) {
                $present['energy'] = array( 'value' => $energy_pct[ $iso3 ], 'weight' => CII_WEIGHT_ENERGY );
            }
            if ( isset( $hhi_pct[ $iso3 ] ) ) {
                $present['hhi'] = array( 'value' => $hhi_pct[ $iso3 ], 'weight' => CII_WEIGHT_HHI );
            }
            if ( isset( $maritime_vulnerability_pct[ $iso3 ] ) ) {
                $present['maritime'] = array( 'value' => $maritime_vulnerability_pct[ $iso3 ], 'weight' => CII_WEIGHT_MARITIME );
            }

            $pillars_present = count( $present );
            $pillars_missing = array_values( array_diff( $all_pillar_names, array_keys( $present ) ) );

            if ( $pillars_present < CII_MIN_PILLARS_REQUIRED ) {
                $excluded[ $iso3 ] = array(
                    'reason'          => 'Fewer than ' . CII_MIN_PILLARS_REQUIRED . ' pillars have real data — not scored (no fabricated fill-in used).',
                    'pillars_present' => $pillars_present,
                    'pillars_missing' => $pillars_missing,
                );
                continue;
            }

            $score_sum  = 0;
            $weight_sum = 0;
            foreach ( $present as $pillar ) {
                $score_sum  += $pillar['value'] * $pillar['weight'];
                $weight_sum += $pillar['weight'];
            }
            $composite = round( $score_sum / $weight_sum, 1 );

            $hhi_source    = isset( $hhi_data[ $iso3 ]['source'] ) ? $hhi_data[ $iso3 ]['source'] : 'no data';
            $maritime_source = isset( $maritime_data[ $iso3 ]['source'] ) ? $maritime_data[ $iso3 ]['source'] : 'no data';
            $coverage_type = ( $pillars_present >= count( $all_pillar_names ) ) ? 'full' : 'partial';

            $results[ $iso3 ] = array(
                'composite_score' => $composite,
                'coverage_type'   => $coverage_type,
                'energy_dependency_percentile'       => isset( $present['energy'] ) ? $present['energy']['value'] : null,
                'energy_dependency_raw'              => isset( $energy_raw_values[ $iso3 ] ) ? $energy_raw_values[ $iso3 ] : null,
                'supplier_concentration_percentile'  => isset( $present['hhi'] ) ? $present['hhi']['value'] : null,
                'supplier_concentration_raw'         => isset( $hhi_raw_values[ $iso3 ] ) ? $hhi_raw_values[ $iso3 ] : null,
                'hhi_source'                          => $hhi_source,
                'maritime_connectivity_percentile'   => isset( $maritime_connectivity_pct[ $iso3 ] ) ? $maritime_connectivity_pct[ $iso3 ] : null,
                'maritime_vulnerability_percentile'  => isset( $present['maritime'] ) ? $present['maritime']['value'] : null,
                'maritime_connectivity_raw'          => isset( $maritime_raw_values[ $iso3 ] ) ? $maritime_raw_values[ $iso3 ] : null,
                'maritime_source'                    => $maritime_source,
                'is_landlocked'                       => cii_is_landlocked( $iso3 ),
                'pillars_used'    => $pillars_present,
                'pillars_missing' => $pillars_missing,
                'last_updated'    => current_time( 'mysql' ),
            );
        }

        // ─── Rank assignment (DESCENDING: highest score = #1) ──────
        $full_composites_sorted = array();
        foreach ( $results as $iso3 => $row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $full_composites_sorted[] = $row['composite_score'];
            }
        }
        rsort( $full_composites_sorted ); // DESCENDING: highest score first
        $rank_in_full_index = function ( $score ) use ( $full_composites_sorted ) {
            $greater = 0;
            foreach ( $full_composites_sorted as $c ) {
                if ( $c > $score ) { $greater++; } else { break; }
            }
            return $greater + 1;
        };

        foreach ( $results as $iso3 => &$row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $r = $rank_in_full_index( $row['composite_score'] );
                $row['rank'] = $r;
                $row['rank_display'] = array(
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

        $pillar_weight_by_name = array(
            'energy'   => CII_WEIGHT_ENERGY,
            'hhi'      => CII_WEIGHT_HHI,
            'maritime' => CII_WEIGHT_MARITIME,
        );
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

            $known_weighted_sum = 0.0;
            foreach ( $pillar_weight_by_name as $pname => $pweight ) {
                if ( $pname === $missing_pillar ) {
                    continue;
                }
                $known_weighted_sum += ( $row[ $pillar_value_key[ $pname ] ] ?? 0 ) * $pweight;
            }
            $missing_weight = $pillar_weight_by_name[ $missing_pillar ];

            $ranks_by_injection = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $injected ) {
                $hypothetical_composite = $known_weighted_sum + ( $injected * $missing_weight );
                $ranks_by_injection[ $injected ] = $rank_in_full_index( $hypothetical_composite );
            }

            $range_80 = array( $ranks_by_injection[90], $ranks_by_injection[10] );
            sort( $range_80 );
            $theoretical = array( $ranks_by_injection[100], $ranks_by_injection[0] );
            sort( $theoretical );

            $row['rank'] = null;
            $row['rank_display'] = array(
                'is_definitive'    => false,
                'best_estimate'    => $ranks_by_injection[50],
                'range_80_low'     => $range_80[0],
                'range_80_high'    => $range_80[1],
                'theoretical_low'  => $theoretical[0],
                'theoretical_high' => $theoretical[1],
                'string_format'    => '#' . $range_80[0] . '–#' . $range_80[1] . '*',
            );
        }
        unset( $row );

        $output = array(
            'version'         => CII_VERSION,
            'last_updated'    => current_time( 'mysql' ),
            'total_countries' => count( $results ),
            'excluded'        => count( $excluded ),
            'excluded_detail' => $excluded,
            'methodology_url'     => 'https://blomstrainsights.com/methodology/sivi',
            'methodology_summary' => 'Percentile-rank composite (Energy dependency, HHI supplier concentration, inverted Maritime connectivity). Full Index = definitive rank. Partial Index = projected rank range with 80% and theoretical bounds. See methodology_url for full derivation.',
            'footnote'        => 'Partial ranks are projections, not definitive placements. Following OECD/JRC guidelines, we report two uncertainty intervals for countries missing a pillar: the 80% Plausible Range (simulating the missing dimension between the 10th and 90th percentile of global data) and the Theoretical Bound (0th to 100th percentile). The Best Estimate uses the global median (50th percentile) for the missing dimension. Countries with structural zeros (e.g. landlocked states with no maritime connectivity) are scored in the Full Index, not the Partial Index.',
            'global_averages_informational_only' => array(
                'note'     => 'Descriptive mean of actual RAW (pre-percentile, pre-inversion) values only — never used to fill in a missing pillar or in the composite math.',
                'energy'   => $avg_energy !== null ? round( $avg_energy, 2 ) : null,
                'hhi'      => $avg_hhi !== null ? round( $avg_hhi, 2 ) : null,
                'maritime' => $avg_maritime !== null ? round( $avg_maritime, 2 ) : null,
            ),
            'weights' => array(
                'energy'   => CII_WEIGHT_ENERGY,
                'hhi'      => CII_WEIGHT_HHI,
                'maritime' => CII_WEIGHT_MARITIME,
            ),
            '_meta' => array(
                'built_at'   => current_time( 'mysql' ),
                'source'     => $meta_source,
                'status'     => 'valid',
            ),
            'countries' => $results,
        );

        update_option( 'cii_composite_index', $output, false );
        if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
            blomstra_index_snapshot_save( 'cii', $output['countries'] );
        }

        return $output;
    }
}

// ─── COMBINED REFRESH & BUILD ──────────────────────────────────────

if ( ! function_exists( 'cii_refresh_all_and_build' ) ) {
    function cii_refresh_all_and_build( $scope = 'global' ) {
        if ( $scope === 'test' ) {
            $country_list = cii_test_country_list();
        } else {
            $country_list = cii_get_global_country_list();
        }

        $energy = cii_refresh_energy_pillar( $country_list );
        $maritime = cii_refresh_maritime_pillar();
        $hhi = cii_refresh_hhi_pillar( null, array_keys( $country_list ) );
        $composite = cii_build_composite();

        return array(
            'energy'    => $energy,
            'maritime'  => $maritime,
            'hhi'       => $hhi,
            'composite' => $composite,
        );
    }
}

// ─── REST ENDPOINT ──────────────────────────────────────────────────

add_action( 'rest_api_init', function() {
    register_rest_route( 'blomstra/v1', '/critical-infrastructure-index', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function() {
            $data = get_option( 'cii_composite_index', null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index not built yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );

// ─── v3.0 NEW FEATURES ─────────────────────────────────────────────

if ( ! function_exists( 'cii_acquire_build_lock' ) ) {
    function cii_acquire_build_lock() {
        $lock_key = 'cii_building_lock';
        $lock_data = get_transient( $lock_key );
        $now = time();

        if ( false !== $lock_data && is_numeric( $lock_data ) && ( $now - $lock_data ) < CII_LOCK_TTL ) {
            error_log( 'SIVI: Build lock already held (age ' . ( $now - $lock_data ) . 's), aborting.' );
            return false;
        }

        set_transient( $lock_key, $now, CII_LOCK_TTL );
        if ( false !== $lock_data ) {
            error_log( 'SIVI: Force‑cleared stale lock (age ' . ( $now - $lock_data ) . 's).' );
        }
        return true;
    }
}

if ( ! function_exists( 'cii_release_build_lock' ) ) {
    function cii_release_build_lock() {
        delete_transient( 'cii_building_lock' );
    }
}

if ( ! function_exists( 'cii_pillar_last_refreshed' ) ) {
    function cii_pillar_last_refreshed( $option_name ) {
        $data = get_option( $option_name, array() );
        if ( empty( $data ) ) {
            return null;
        }
        $latest = 0;
        foreach ( $data as $row ) {
            if ( ! empty( $row['last_updated'] ) ) {
                $ts = strtotime( $row['last_updated'] );
                if ( $ts && $ts > $latest ) {
                    $latest = $ts;
                }
            }
        }
        return $latest > 0 ? $latest : null;
    }
}

if ( ! function_exists( 'cii_check_dual_freshness' ) ) {
    function cii_check_dual_freshness() {
        $composite = get_option( 'cii_composite_index', null );
        if ( null === $composite ) {
            return array( 'fresh' => true, 'reason' => 'first_run' );
        }

        $now = time();
        $stale = array();
        $pillar_options = array(
            'energy'   => 'cii_energy_pillar',
            'maritime' => 'cii_maritime_pillar',
            'hhi'      => 'cii_hhi_pillar',
        );

        foreach ( $pillar_options as $pillar => $option_name ) {
            $last_refreshed = cii_pillar_last_refreshed( $option_name );
            if ( $last_refreshed === null ) {
                $stale[] = $pillar . ' (never refreshed)';
                continue;
            }
            $age = $now - $last_refreshed;
            if ( $age > CII_FRESHNESS_PILLAR ) {
                $stale[] = $pillar . ' (age: ' . round( $age / DAY_IN_SECONDS ) . ' days)';
            }
        }

        if ( ! empty( $stale ) ) {
            return array(
                'fresh'  => false,
                'reason' => 'stale_refdata: ' . implode( ', ', $stale ),
            );
        }
        return array( 'fresh' => true, 'reason' => 'fresh' );
    }
}

if ( ! function_exists( 'cii_build_from_central_cache' ) ) {
    function cii_build_from_central_cache( $force = false ) {
        if ( ! $force && ! cii_acquire_build_lock() ) {
            return false;
        }

        try {
            $country_list = cii_get_global_country_list();
            $iso3_list    = array_keys( $country_list );

            $energy_result   = cii_refresh_energy_pillar( $country_list, 'central_cached' );
            $maritime_result = cii_refresh_maritime_pillar( 'central_cached' );
            $hhi_result      = cii_refresh_hhi_pillar( null, $iso3_list, 'central_cached' );

            if ( isset( $energy_result['error'], $maritime_result['error'], $hhi_result['error'] ) ) {
                // All three pillars had nothing cached — nothing to build.
                return array();
            }

            return cii_build_composite( 'cron_central_cached' );
        } finally {
            if ( ! $force ) {
                cii_release_build_lock();
            }
        }
    }
}

if ( ! function_exists( 'cii_schedule_daily_cron' ) ) {
    function cii_schedule_daily_cron() {
        if ( ! wp_next_scheduled( 'cii_daily_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'cii_daily_cron' );
        }
    }
    add_action( 'wp', 'cii_schedule_daily_cron' );
}

if ( ! function_exists( 'cii_run_daily_build_logic' ) ) {
    function cii_run_daily_build_logic() {
        $freshness = cii_check_dual_freshness();
        if ( ! $freshness['fresh'] ) {
            $status = array(
                'time'    => current_time( 'mysql' ),
                'status'  => 'skipped_stale_refdata',
                'details' => $freshness['reason'],
            );
            update_option( 'cii_cron_status', $status );
            return $status;
        }

        $output = cii_build_from_central_cache( false );
        if ( false === $output ) {
            $status = array(
                'time'    => current_time( 'mysql' ),
                'status'  => 'error',
                'details' => 'Composite build skipped — build lock already held by another run.',
            );
            update_option( 'cii_cron_status', $status );
            return $status;
        }

        if ( empty( $output ) ) {
            $status = array(
                'time'    => current_time( 'mysql' ),
                'status'  => 'error',
                'details' => 'Composite build returned empty data — no pillar had usable data in the central cache. Check the Blomstra Reference Data page.',
            );
            update_option( 'cii_cron_status', $status );
            return $status;
        }

        $status = array(
            'time'    => current_time( 'mysql' ),
            'status'  => 'success',
            'details' => 'Composite built from central cache with ' . ( $output['total_countries'] ?? 0 ) . ' countries.',
        );
        update_option( 'cii_cron_status', $status );
        return $status;
    }
}

if ( ! function_exists( 'cii_daily_cron_callback' ) ) {
    function cii_daily_cron_callback() {
        update_option( 'cii_last_wpcron_fired', current_time( 'mysql' ), false );

        if ( wp_get_environment_type() !== 'production' ) {
            update_option( 'cii_last_wpcron_env_skip', wp_get_environment_type(), false );
            return;
        }
        delete_option( 'cii_last_wpcron_env_skip' );
        cii_run_daily_build_logic();
    }
    add_action( 'cii_daily_cron', 'cii_daily_cron_callback' );
}

// ─── ADMIN NOTICE FOR CRON STATUS ──────────────────────────────────

if ( ! defined( 'CII_WPCRON_ALERT_THRESHOLD' ) ) {
    define( 'CII_WPCRON_ALERT_THRESHOLD', 30 * HOUR_IN_SECONDS );
}

if ( ! function_exists( 'cii_wpcron_health_notice' ) ) {
    add_action( 'admin_notices', function() {
        $last_fired = get_option( 'cii_last_wpcron_fired', null );
        $env_skip   = get_option( 'cii_last_wpcron_env_skip', null );
        $now = time();

        if ( $env_skip !== null ) {
            echo '<div class="notice notice-error"><p><strong>SIVI Index:</strong> the daily automatic build IS firing, but is being skipped every time because wp_get_environment_type() returns "' . esc_html( $env_skip ) . '", not "production". Fix WP_ENVIRONMENT_TYPE in wp-config.php, or use the 🔄 Central Data / 🔄 Direct API buttons on the SIVI Index page to refresh manually until then.</p></div>';
            return;
        }

        $age = ( $last_fired !== null ) ? ( $now - strtotime( $last_fired ) ) : null;
        if ( $age === null || $age > CII_WPCRON_ALERT_THRESHOLD ) {
            $detail = ( $last_fired === null )
                ? 'has never fired on this site.'
                : 'last fired ' . round( $age / HOUR_IN_SECONDS ) . 'h ago (expected every ~24h).';
            echo '<div class="notice notice-error"><p><strong>SIVI Index:</strong> the daily automatic build ' . esc_html( $detail ) . ' wp-cron may not be triggering on this site (common on low-traffic sites, or if a host runs a real system cron against wp-cron.php and it isn\'t configured). Until that\'s fixed, go to the SIVI Index page and use 🔄 Central Data / 🔄 Direct API on each pillar, or the "Force Run Daily Cron Now" button, to refresh manually.</p></div>';
        }
    } );
}

if ( ! function_exists( 'cii_health_dashboard_notice' ) ) {
    add_action( 'admin_notices', function() {
        $status = get_option( 'cii_cron_status', array() );
        if ( empty( $status ) ) {
            return;
        }
        $class = 'notice-info';
        if ( $status['status'] === 'success' ) {
            $class = 'notice-success';
        } elseif ( $status['status'] === 'skipped_stale_refdata' ) {
            $class = 'notice-warning';
        } else {
            $class = 'notice-error';
        }
        echo '<div class="notice ' . $class . ' is-dismissible"><p><strong>SIVI Index:</strong> ' . esc_html( $status['details'] ) . ' (Last run: ' . esc_html( $status['time'] ) . ')</p></div>';
    } );
}

// ─── ADMIN PAGE ────────────────────────────────────────────────────

if ( ! function_exists( 'cii_register_test_tools_page' ) ) {
    function cii_register_test_tools_page() {
        add_submenu_page(
            'blomstra-insights-tools',
            'SIVI Index',
            'SIVI Index',
            'manage_options',
            'blomstra-sovereign-infrastructure-vulnerability-index',
            'cii_render_test_tools_page'
        );
    }
    add_action( 'admin_menu', 'cii_register_test_tools_page', 20 );
}

// ─── Redirect old CII admin page to new SIVI page ──────────────────
add_action( 'admin_init', function() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'cii-index' ) {
        wp_redirect( admin_url( 'admin.php?page=blomstra-sovereign-infrastructure-vulnerability-index' ) );
        exit;
    }
} );

if ( ! function_exists( 'cii_handle_early_actions' ) ) {
    function cii_handle_early_actions() {
        $redirecting_actions = array(
            'cii_flush_all',
            'cii_flush_energy',
            'cii_flush_maritime',
            'cii_flush_hhi',
            'cii_do_refresh_all',
            'cii_force_daily_cron',
        );

        $action_found = false;
        foreach ( $redirecting_actions as $action_key ) {
            if ( isset( $_POST[ $action_key ] ) ) {
                $action_found = true;
                break;
            }
        }

        if ( ! $action_found || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['cii_flush_energy'] ) && check_admin_referer( 'cii_flush_energy_action', 'cii_flush_energy_nonce' ) ) {
            delete_option( 'cii_energy_pillar' );
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_energy_%'" );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-sovereign-infrastructure-vulnerability-index', 'flushed' => 'energy' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['cii_flush_maritime'] ) && check_admin_referer( 'cii_flush_maritime_action', 'cii_flush_maritime_nonce' ) ) {
            delete_option( 'cii_maritime_pillar' );
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_maritime_%'" );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-sovereign-infrastructure-vulnerability-index', 'flushed' => 'maritime' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['cii_flush_hhi'] ) && check_admin_referer( 'cii_flush_hhi_action', 'cii_flush_hhi_nonce' ) ) {
            delete_option( 'cii_hhi_pillar' );
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_hhi_%'" );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-sovereign-infrastructure-vulnerability-index', 'flushed' => 'hhi' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['cii_flush_all'] ) && check_admin_referer( 'cii_flush_all_action', 'cii_flush_all_nonce' ) ) {
            delete_option( 'cii_energy_pillar' );
            delete_option( 'cii_maritime_pillar' );
            delete_option( 'cii_hhi_pillar' );
            delete_option( 'cii_composite_index' );
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_energy_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_maritime_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_hhi_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_comtrade_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cii_global_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_blomstra_comtrade_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_blomstra_global_%'" );
            wp_cache_flush();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-sovereign-infrastructure-vulnerability-index', 'flushed' => 'all' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['cii_do_refresh_all'] ) && check_admin_referer( 'cii_refresh_all_action', 'cii_refresh_all_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 600 );
            $scope = ( isset( $_POST['cii_scope'] ) && $_POST['cii_scope'] === 'test' ) ? 'test' : 'global';
            cii_refresh_all_and_build( $scope );
            wp_cache_flush();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-sovereign-infrastructure-vulnerability-index', 'all_done' => 1 ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['cii_force_daily_cron'] ) && check_admin_referer( 'cii_force_daily_cron_action', 'cii_force_daily_cron_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 300 );
            cii_run_daily_build_logic();
            wp_cache_flush();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-sovereign-infrastructure-vulnerability-index', 'cron_forced' => 1 ), admin_url( 'admin.php' ) ) );
            exit;
        }
    }
    add_action( 'admin_init', 'cii_handle_early_actions' );
}

// ─── RENDER PAGE ────────────────────────────────────────────────────

if ( ! function_exists( 'cii_render_test_tools_page' ) ) {
    function cii_render_test_tools_page() {
        nocache_headers();

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // ─── ACTION HANDLERS (non‑redirecting) ─────────────────────
        $energy_notice = null;

        if ( isset( $_POST['cii_do_refresh_energy_central'] ) && check_admin_referer( 'cii_refresh_energy_central_action', 'cii_refresh_energy_central_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 600 );
            $scope = ( isset( $_POST['cii_energy_scope'] ) && $_POST['cii_energy_scope'] === 'test' ) ? 'test' : 'global';
            $countries = ( $scope === 'test' ) ? cii_test_country_list() : cii_get_global_country_list();
            $results = cii_refresh_energy_pillar( $countries, 'central' );
            if ( isset( $results['error'] ) ) {
                $energy_notice = array( 'source_tested' => 'Central Data', 'error' => $results['error'] );
            } else {
                $succeeded = 0;
                foreach ( $results as $row ) { if ( $row['value'] !== null ) { $succeeded++; } }
                $energy_notice = array( 'source_tested' => 'Central Data', 'attempted' => count( $results ), 'succeeded' => $succeeded );
                cii_build_composite();
                wp_cache_flush();
            }
        }

        if ( isset( $_POST['cii_do_refresh_energy_api'] ) && check_admin_referer( 'cii_refresh_energy_api_action', 'cii_refresh_energy_api_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 600 );
            $scope = ( isset( $_POST['cii_energy_scope'] ) && $_POST['cii_energy_scope'] === 'test' ) ? 'test' : 'global';
            $countries = ( $scope === 'test' ) ? cii_test_country_list() : cii_get_global_country_list();
            $results = cii_refresh_energy_pillar( $countries, 'api' );
            $succeeded = 0;
            foreach ( $results as $row ) { if ( $row['value'] !== null ) { $succeeded++; } }
            $energy_notice = array( 'source_tested' => 'Direct API', 'attempted' => count( $results ), 'succeeded' => $succeeded );
            cii_build_composite();
            wp_cache_flush();
        }

        $maritime_notice = null;

        if ( isset( $_POST['cii_do_refresh_maritime_central'] ) && check_admin_referer( 'cii_refresh_maritime_central_action', 'cii_refresh_maritime_central_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 300 );
            $results = cii_refresh_maritime_pillar( 'central' );
            if ( isset( $results['error'] ) ) {
                $maritime_notice = array( 'source_tested' => 'Central Data', 'error' => $results['error'] );
            } else {
                $succeeded = 0;
                foreach ( $results as $row ) { if ( $row['value'] !== null ) { $succeeded++; } }
                $maritime_notice = array( 'source_tested' => 'Central Data', 'attempted' => count( $results ), 'succeeded' => $succeeded );
                cii_build_composite();
                wp_cache_flush();
            }
        }

        if ( isset( $_POST['cii_do_refresh_maritime_api'] ) && check_admin_referer( 'cii_refresh_maritime_api_action', 'cii_refresh_maritime_api_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 300 );
            $results = cii_refresh_maritime_pillar( 'api' );
            if ( isset( $results['error'] ) ) {
                $maritime_notice = array( 'source_tested' => 'Direct API', 'error' => $results['error'] );
            } else {
                $succeeded = 0;
                foreach ( $results as $row ) { if ( $row['value'] !== null ) { $succeeded++; } }
                $maritime_notice = array( 'source_tested' => 'Direct API', 'attempted' => count( $results ), 'succeeded' => $succeeded );
                cii_build_composite();
                wp_cache_flush();
            }
        }

        $hhi_notice = null;

        if ( isset( $_POST['cii_do_refresh_hhi_central'] ) && check_admin_referer( 'cii_refresh_hhi_central_action', 'cii_refresh_hhi_central_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 900 );
            $scope = ( isset( $_POST['cii_hhi_scope'] ) && $_POST['cii_hhi_scope'] === 'test' ) ? 'test' : 'global';
            $iso3_list = ( $scope === 'test' ) ? array_keys( cii_test_country_list() ) : array_keys( cii_get_global_country_list() );
            $results = cii_refresh_hhi_pillar( null, $iso3_list, 'central' );
            if ( isset( $results['error'] ) ) {
                $hhi_notice = array( 'source_tested' => 'Central Data', 'error' => $results['error'] );
            } else {
                $succeeded = 0;
                foreach ( $results as $row ) { if ( $row['value'] !== null ) { $succeeded++; } }
                $hhi_notice = array( 'source_tested' => 'Central Data', 'attempted' => count( $results ), 'succeeded' => $succeeded );
                cii_build_composite();
                wp_cache_flush();
            }
        }

        if ( isset( $_POST['cii_do_refresh_hhi_api'] ) && check_admin_referer( 'cii_refresh_hhi_api_action', 'cii_refresh_hhi_api_nonce' ) ) {
            ignore_user_abort( true );
            set_time_limit( 900 );
            $scope = ( isset( $_POST['cii_hhi_scope'] ) && $_POST['cii_hhi_scope'] === 'test' ) ? 'test' : 'global';
            $iso3_list = ( $scope === 'test' ) ? array_keys( cii_test_country_list() ) : array_keys( cii_get_global_country_list() );
            $results = cii_refresh_hhi_pillar( null, $iso3_list, 'api' );
            $succeeded = 0;
            foreach ( $results as $row ) { if ( $row['value'] !== null ) { $succeeded++; } }
            $hhi_notice = array( 'source_tested' => 'Direct API', 'attempted' => count( $results ), 'succeeded' => $succeeded );
            cii_build_composite();
            wp_cache_flush();
        }

        $just_built_composite = false;
        if ( isset( $_POST['cii_build_composite_now'] ) && check_admin_referer( 'cii_build_composite_action', 'cii_build_composite_nonce' ) ) {
            cii_build_composite();
            $just_built_composite = true;
        }

        $cii_debug_results = null;
        if ( isset( $_POST['cii_debug_fetch'] ) && check_admin_referer( 'cii_debug_fetch_action', 'cii_debug_fetch_nonce' ) ) {
            $cii_debug_results = array(
                'nuclear_france_prod' => cii_debug_fetch_eia_activity( 'FRA', '4417', EIA_ACTIVITY_PROD ),
                'nuclear_france_cons' => cii_debug_fetch_eia_activity( 'FRA', '4417', EIA_ACTIVITY_CONS ),
            );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'SIVI — Sovereign Infrastructure Vulnerability Index', 'cii' ) . '</h1>';
        echo '<p style="color:#666;">Manage the SIVI data pipeline — refresh pillars, build the composite, and preview results.</p>';

        // ─── Success/error notices ──────────────────────────────
        if ( isset( $_GET['flushed'] ) ) {
            $flushed_type = sanitize_text_field( $_GET['flushed'] );
            $message = '';
            switch ( $flushed_type ) {
                case 'energy':   $message = 'Energy pillar data flushed.'; break;
                case 'maritime': $message = 'Maritime pillar data flushed.'; break;
                case 'hhi':      $message = 'HHI pillar data flushed.'; break;
                case 'all':      $message = 'All cached pillar and composite data deleted.'; break;
                default:         $message = 'Data flushed.'; break;
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $message ) . ' Nothing is scored until you refresh and build again.</p></div>';
        }
        if ( isset( $_GET['all_done'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ All three pillars refreshed and composite rebuilt.</p></div>';
        }
        if ( isset( $_GET['cron_forced'] ) ) {
            $forced_status = get_option( 'cii_cron_status', array() );
            $forced_class  = ( ( $forced_status['status'] ?? '' ) === 'success' ) ? 'notice-success' : ( ( $forced_status['status'] ?? '' ) === 'skipped_stale_refdata' ? 'notice-warning' : 'notice-error' );
            echo '<div class="notice ' . $forced_class . ' is-dismissible"><p>🧪 Forced cron run: ' . esc_html( $forced_status['details'] ?? 'no result recorded' ) . '</p></div>';
        }

        if ( $energy_notice ) {
            if ( isset( $energy_notice['error'] ) ) {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html( $energy_notice['source_tested'] ) . ': ' . esc_html( $energy_notice['error'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $energy_notice['source_tested'] ) . ': ' . esc_html( $energy_notice['succeeded'] ) . ' / ' . esc_html( $energy_notice['attempted'] ) . ' countries. Composite rebuilt.</p></div>';
            }
        }

        if ( $maritime_notice ) {
            if ( isset( $maritime_notice['error'] ) ) {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html( $maritime_notice['source_tested'] ) . ': ' . esc_html( $maritime_notice['error'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $maritime_notice['source_tested'] ) . ': ' . esc_html( $maritime_notice['succeeded'] ) . ' / ' . esc_html( $maritime_notice['attempted'] ) . ' countries. Composite rebuilt.</p></div>';
            }
        }

        if ( $hhi_notice ) {
            if ( isset( $hhi_notice['error'] ) ) {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html( $hhi_notice['source_tested'] ) . ': ' . esc_html( $hhi_notice['error'] ) . '</p></div>';
            } else {
                $hhi_summary_for_notice = ( $hhi_notice['source_tested'] === 'Central Data' )
                    ? get_option( 'blomstra_hhi_refresh_summary', array() )
                    : get_option( 'cii_hhi_refresh_summary', array() );
                if ( ! empty( $hhi_summary_for_notice['quota_exhausted_at'] ) ) {
                    echo '<div class="notice notice-warning"><p>⚠️ ' . esc_html( $hhi_notice['source_tested'] ) . ': quota exhausted at ' . esc_html( $hhi_summary_for_notice['quota_exhausted_at'] ) . '. ' . esc_html( $hhi_notice['succeeded'] ) . ' / ' . esc_html( $hhi_notice['attempted'] ) . ' succeeded. Composite rebuilt from what succeeded.</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $hhi_notice['source_tested'] ) . ': ' . esc_html( $hhi_notice['succeeded'] ) . ' / ' . esc_html( $hhi_notice['attempted'] ) . ' countries. Composite rebuilt.</p></div>';
                }
            }
        }

        // ─── STATUS DASHBOARD ────────────────────────────────────
        echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #2271b1;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-dashboard"></span> Status Dashboard</h2></div>';
        echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:20px;">';

        $energy_data = get_option( 'cii_energy_pillar', array() );
        $maritime_data = get_option( 'cii_maritime_pillar', array() );
        $hhi_data = get_option( 'cii_hhi_pillar', array() );
        $composite = get_option( 'cii_composite_index', null );

        $energy_count = 0;
        foreach ( $energy_data as $row ) { if ( $row['value'] !== null ) { $energy_count++; } }
        $maritime_count = 0;
        foreach ( $maritime_data as $row ) { if ( $row['value'] !== null ) { $maritime_count++; } }
        $hhi_count = 0;
        foreach ( $hhi_data as $row ) { if ( $row['value'] !== null ) { $hhi_count++; } }

        $statuses = array(
            'Energy Pillar' => array( 'cached' => $energy_count > 0, 'count' => $energy_count ),
            'Maritime Pillar' => array( 'cached' => $maritime_count > 0, 'count' => $maritime_count ),
            'HHI Pillar' => array( 'cached' => $hhi_count > 0, 'count' => $hhi_count ),
            'Composite Index' => array( 'cached' => $composite && ! empty( $composite['countries'] ), 'count' => $composite ? $composite['total_countries'] : 0 ),
        );

        foreach ( $statuses as $label => $info ) {
            $color = $info['cached'] ? '#2e7d32' : '#a94442';
            $icon = $info['cached'] ? 'yes' : 'no-alt';
            echo '<div style="flex:1; min-width:150px; background:#fff; padding:12px; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
            echo '<span class="dashicons dashicons-' . $icon . '" style="color:' . $color . ';"></span> ';
            echo '<strong>' . esc_html( $label ) . '</strong><br>';
            echo '<span style="color:' . $color . ';">' . ( $info['cached'] ? 'Scored ✓' : 'No data' ) . '</span>';
            if ( $info['count'] > 0 ) {
                echo ' <span style="color:#666;font-size:0.9em;">(' . $info['count'] . ')</span>';
            }
            echo '</div>';
        }
        echo '</div></div>';

        // ─── v3.0 HEALTH DASHBOARD CARD ──────────────────────────
        echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#f9f9f9;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-heart"></span> 🩺 Pipeline Health (v3.0)</h2></div>';
        echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:20px;">';

        $cii_status = get_option( 'cii_cron_status', array() );
        $lock_data = get_transient( 'cii_building_lock' );
        $lock_age = ( false !== $lock_data && is_numeric( $lock_data ) ) ? ( time() - $lock_data ) : -1;
        $now = time();

        $pillar_options = array(
            'maritime' => array( 'label' => 'Maritime LSCI', 'option' => 'cii_maritime_pillar' ),
            'energy'   => array( 'label' => 'Energy (EIA)', 'option' => 'cii_energy_pillar' ),
            'hhi'      => array( 'label' => 'HHI (Comtrade)', 'option' => 'cii_hhi_pillar' ),
        );
        foreach ( $pillar_options as $key => $meta ) {
            $last_refreshed = cii_pillar_last_refreshed( $meta['option'] );
            $status_text = 'Never refreshed';
            $color = '#a94442';
            if ( $last_refreshed !== null ) {
                $age = $now - $last_refreshed;
                $age_days = round( $age / DAY_IN_SECONDS );
                $status_text = $age_days . ' days ago';
                if ( $age <= CII_FRESHNESS_PILLAR ) {
                    $color = '#2e7d32';
                    $status_text .= ' ✅ Fresh';
                } else {
                    $color = '#b26a00';
                    $status_text .= ' ⚠️ Stale';
                }
            }
            echo '<div style="flex:1; min-width:150px; background:#fff; padding:10px; border-radius:4px; border-left:4px solid ' . $color . ';">';
            echo '<strong>' . esc_html( $meta['label'] ) . '</strong><br>';
            echo '<span style="color:' . $color . ';">' . esc_html( $status_text ) . '</span>';
            echo '</div>';
        }

        $cii_age = 0;
        $cii_status_text = 'Never built';
        $cii_color = '#a94442';
        if ( isset( $cii_status['time'] ) ) {
            $cii_age = $now - strtotime( $cii_status['time'] );
            $cii_age_days = round( $cii_age / DAY_IN_SECONDS );
            $cii_status_text = $cii_age_days . ' days ago';
            if ( $cii_status['status'] === 'success' ) {
                $cii_color = '#2e7d32';
                $cii_status_text .= ' ✅ ' . $cii_status['details'];
            } elseif ( $cii_status['status'] === 'skipped_stale_refdata' ) {
                $cii_color = '#b26a00';
                $cii_status_text .= ' ⚠️ ' . $cii_status['details'];
            } else {
                $cii_color = '#a94442';
                $cii_status_text .= ' ❌ ' . $cii_status['details'];
            }
        }
        echo '<div style="flex:1; min-width:150px; background:#fff; padding:10px; border-radius:4px; border-left:4px solid ' . $cii_color . ';">';
        echo '<strong>Composite Index</strong><br>';
        echo '<span style="color:' . $cii_color . ';">' . esc_html( $cii_status_text ) . '</span>';
        echo '</div>';

        if ( $lock_age >= 0 && $lock_age < CII_LOCK_TTL ) {
            echo '<div style="flex:1; min-width:150px; background:#fff; padding:10px; border-radius:4px; border-left:4px solid #f56e28;">';
            echo '<strong>Build Lock</strong><br>';
            echo '<span style="color:#f56e28;">🔒 Held for ' . esc_html( $lock_age ) . 's</span>';
            echo '</div>';
        } else {
            echo '<div style="flex:1; min-width:150px; background:#fff; padding:10px; border-radius:4px; border-left:4px solid #2e7d32;">';
            echo '<strong>Build Lock</strong><br>';
            echo '<span style="color:#2e7d32;">🔓 Free</span>';
            echo '</div>';
        }

        $last_wpcron_fired = get_option( 'cii_last_wpcron_fired', null );
        $wpcron_env_skip   = get_option( 'cii_last_wpcron_env_skip', null );
        if ( $wpcron_env_skip !== null ) {
            $wpcron_color = '#a94442';
            $wpcron_text  = 'Firing, but skipped — env is "' . $wpcron_env_skip . '"';
        } elseif ( $last_wpcron_fired === null ) {
            $wpcron_color = '#a94442';
            $wpcron_text  = 'Never fired';
        } else {
            $wpcron_age = $now - strtotime( $last_wpcron_fired );
            if ( $wpcron_age > CII_WPCRON_ALERT_THRESHOLD ) {
                $wpcron_color = '#b26a00';
                $wpcron_text  = round( $wpcron_age / HOUR_IN_SECONDS ) . 'h ago ⚠️ overdue';
            } else {
                $wpcron_color = '#2e7d32';
                $wpcron_text  = round( $wpcron_age / HOUR_IN_SECONDS ) . 'h ago ✅';
            }
        }
        echo '<div style="flex:1; min-width:150px; background:#fff; padding:10px; border-radius:4px; border-left:4px solid ' . $wpcron_color . ';">';
        echo '<strong>Last Real wp-cron Fire</strong><br>';
        echo '<span style="color:' . $wpcron_color . ';">' . esc_html( $wpcron_text ) . '</span>';
        echo '</div>';

        echo '</div>'; // end flex row

        echo '<p style="margin-top:15px;"><form method="post" style="display:inline-block;">';
        wp_nonce_field( 'cii_force_daily_cron_action', 'cii_force_daily_cron_nonce' );
        echo '<button type="submit" name="cii_force_daily_cron" value="1" class="button button-secondary">🧪 Force Run Daily Cron Now (Test)</button></form>';
        echo ' <span style="color:#666; font-size:0.9em;">Runs the exact same logic wp-cron fires nightly — freshness gate included — without waiting for the schedule. Result appears in the Composite Index card above.</span></p>';

        echo '</div></div>';

        // ─── ENERGY PILLAR ──────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-line"></span> ⚡ Energy Pillar</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Raw per-fuel data is centralized; weighting formula stays here (SIVI-specific methodology).</p>';
        echo '<p>' . $energy_count . ' / ' . count( cii_get_global_country_list() ) . ' countries have energy data.</p>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'cii_refresh_energy_central_action', 'cii_refresh_energy_central_nonce' );
        echo '<label>Scope: <select name="cii_energy_scope"><option value="test">Test (10)</option><option value="global" selected>Global</option></select></label> ';
        echo '<button type="submit" name="cii_do_refresh_energy_central" value="1" class="button button-primary">🔄 Central Data</button></form>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'cii_refresh_energy_api_action', 'cii_refresh_energy_api_nonce' );
        echo '<button type="submit" name="cii_do_refresh_energy_api" value="1" class="button">🔄 Direct API</button></form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'cii_flush_energy_action', 'cii_flush_energy_nonce' );
        echo '<button type="submit" name="cii_flush_energy" value="1" class="button" style="border-color:#d63638; color:#d63638;">🗑️ Flush</button></form>';
        echo '</div></div>';

        // ─── MARITIME PILLAR ─────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-ship"></span> 🚢 Maritime Pillar</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">All countries in one World Bank call. Landlocked countries with no data get a structural zero.</p>';
        echo '<p>' . $maritime_count . ' / ' . count( cii_get_global_country_list() ) . ' countries have maritime data.</p>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'cii_refresh_maritime_central_action', 'cii_refresh_maritime_central_nonce' );
        echo '<button type="submit" name="cii_do_refresh_maritime_central" value="1" class="button button-primary">🔄 Central Data</button></form>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'cii_refresh_maritime_api_action', 'cii_refresh_maritime_api_nonce' );
        echo '<button type="submit" name="cii_do_refresh_maritime_api" value="1" class="button">🔄 Direct API</button></form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'cii_flush_maritime_action', 'cii_flush_maritime_nonce' );
        echo '<button type="submit" name="cii_flush_maritime" value="1" class="button" style="border-color:#d63638; color:#d63638;">🗑️ Flush</button></form>';
        echo '</div></div>';

        // ─── HHI PILLAR ──────────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-bar"></span> 📦 HHI Pillar</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Can take several minutes for global scope. Shared Comtrade quota across all tools.</p>';
        echo '<p>' . $hhi_count . ' / ' . count( cii_get_global_country_list() ) . ' countries have HHI data.</p>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'cii_refresh_hhi_central_action', 'cii_refresh_hhi_central_nonce' );
        echo '<label>Scope: <select name="cii_hhi_scope"><option value="test">Test (10)</option><option value="global" selected>Global</option></select></label> ';
        echo '<button type="submit" name="cii_do_refresh_hhi_central" value="1" class="button button-primary">🔄 Central Data</button></form>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'cii_refresh_hhi_api_action', 'cii_refresh_hhi_api_nonce' );
        echo '<button type="submit" name="cii_do_refresh_hhi_api" value="1" class="button">🔄 Direct API</button></form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'cii_flush_hhi_action', 'cii_flush_hhi_nonce' );
        echo '<button type="submit" name="cii_flush_hhi" value="1" class="button" style="border-color:#d63638; color:#d63638;">🗑️ Flush</button></form>';
        echo '</div></div>';

        // ─── BUILD COMPOSITE ─────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-calculator"></span> 🔨 Build Composite Index</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Computes the composite from whatever is currently stored — no external API calls, instant.</p>';

        $composite = get_option( 'cii_composite_index', null );
        $stale = false;
        if ( $composite && ! empty( $composite['countries'] ) ) {
            $pillar_refresh_times = array();
            $hhi_summary_check = get_option( 'cii_hhi_refresh_summary', null );
            $hhi_central_summary_check = get_option( 'blomstra_hhi_refresh_summary', null );
            $hhi_newest_finish = null;
            if ( $hhi_summary_check && ! empty( $hhi_summary_check['run_finished'] ) ) {
                $hhi_newest_finish = $hhi_summary_check['run_finished'];
            }
            if ( $hhi_central_summary_check && ! empty( $hhi_central_summary_check['run_finished'] ) ) {
                if ( $hhi_newest_finish === null || $hhi_central_summary_check['run_finished'] > $hhi_newest_finish ) {
                    $hhi_newest_finish = $hhi_central_summary_check['run_finished'];
                }
            }
            if ( $hhi_newest_finish !== null ) {
                $pillar_refresh_times['HHI'] = $hhi_newest_finish;
            }
            foreach ( array( 'cii_energy_pillar' => 'Energy', 'cii_maritime_pillar' => 'Maritime' ) as $opt_name => $label ) {
                $pdata = get_option( $opt_name, array() );
                $newest = null;
                foreach ( $pdata as $prow ) {
                    if ( ! empty( $prow['last_updated'] ) && ( $newest === null || $prow['last_updated'] > $newest ) ) {
                        $newest = $prow['last_updated'];
                    }
                }
                if ( $newest !== null ) { $pillar_refresh_times[ $label ] = $newest; }
            }
            foreach ( $pillar_refresh_times as $label => $ts ) {
                if ( $ts > $composite['last_updated'] ) { $stale = true; break; }
            }
        }
        if ( $stale ) {
            echo '<div class="notice notice-warning" style="margin:0 0 12px;"><p><strong>⚠ This composite looks stale</strong> — pillar data is newer than the composite. Click "Build Composite Now" below to refresh it.</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'cii_build_composite_action', 'cii_build_composite_nonce' );
        echo '<button type="submit" name="cii_build_composite_now" value="1" class="button button-primary" style="font-size:1.05rem;padding:8px 24px;">Build Composite Index</button>';
        echo '</form>';
        echo '</div></div>';

        // ─── REFRESH ALL ────────────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-update"></span> 🔄 Refresh All Three &amp; Build</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Once you\'ve verified the data sources work, use this instead of refreshing each pillar separately. Global can take 10–20 minutes.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'cii_refresh_all_action', 'cii_refresh_all_nonce' );
        echo '<label>Scope: <select name="cii_scope"><option value="test">Test (10 countries)</option><option value="global" selected>Global</option></select></label> ';
        echo '<button type="submit" name="cii_do_refresh_all" value="1" class="button">Refresh All &amp; Build</button>';
        echo '</form>';
        echo '</div></div>';

        // ─── FLUSH ALL ────────────────────────────────────────────────
        echo '<div class="postbox" style="border-color:#d63638;">';
        echo '<div class="postbox-header"><h2 class="hndle" style="color:#d63638;"><span class="dashicons dashicons-warning"></span> ⚠️ Flush All Cached Data</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Deletes ALL stored pillar data (Energy, HHI, Maritime) and the composite. Use this only when you suspect the stored data itself is corrupted, not as a routine reset.</p>';
        echo '<form method="post" onsubmit="return confirm(\'This deletes all stored SIVI data. Are you sure?\');">';
        wp_nonce_field( 'cii_flush_all_action', 'cii_flush_all_nonce' );
        echo '<button type="submit" name="cii_flush_all" value="1" class="button button-secondary" style="background:#d63638; color:#fff; border-color:#d63638;">🗑️ Flush All Cached Data</button>';
        echo '</form>';
        echo '</div></div>';

        // ─── DIAGNOSTICS ──────────────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-bug"></span> 🩺 Diagnostics</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">These are specific to SIVI\'s Direct API fallback path. Full diagnostics for the central model are on the <a href="' . esc_url( admin_url( 'admin.php?page=blomstra-insights-tools' ) ) . '">Blomstra Reference Data</a> page.</p>';

        $hhi_summary = get_option( 'cii_hhi_refresh_summary', null );
        echo '<h3>Last Direct API HHI run</h3>';
        if ( $hhi_summary ) {
            echo '<table class="widefat striped" style="max-width:700px;"><tbody>';
            echo '<tr><td>Run started / finished</td><td>' . esc_html( $hhi_summary['run_started'] ) . ' → ' . esc_html( $hhi_summary['run_finished'] ?? '(did not finish)' ) . '</td></tr>';
            echo '<tr><td>Countries in scope</td><td>' . esc_html( $hhi_summary['countries_in_scope'] ) . '</td></tr>';
            echo '<tr><td><strong>Succeeded</strong></td><td><strong>' . esc_html( $hhi_summary['succeeded'] ) . '</strong></td></tr>';
            if ( ! empty( $hhi_summary['quota_exhausted_at'] ) ) {
                echo '<tr><td style="color:#a94442;">Quota exhausted at</td><td style="color:#a94442;">' . esc_html( $hhi_summary['quota_exhausted_at'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666;">No Direct API HHI run recorded yet.</p>';
        }

        $call_log = get_option( 'cii_comtrade_call_log', array() );
        if ( ! empty( $call_log ) ) {
            echo '<details><summary style="cursor:pointer;color:#2271b1;">Show last ' . count( $call_log ) . ' fallback Comtrade calls</summary>';
            echo '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Time</th><th>Reporter</th><th>Year</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $call_log ) as $entry ) {
                $outcome_color = in_array( $entry['outcome'], array( 'quota_or_rate_limit', 'http_error', 'network_error' ), true ) ? '#a94442' : ( $entry['outcome'] === 'partial' ? '#b26a00' : ( $entry['outcome'] === 'ok' ? '#2e7d32' : '#666' ) );
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td>' . esc_html( $entry['reporter_code'] ) . '</td><td>' . esc_html( $entry['year'] ) . '</td><td style="color:' . $outcome_color . ';font-weight:600;">' . esc_html( $entry['outcome'] ) . '</td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        $eia_call_log = get_option( 'cii_eia_call_log', array() );
        if ( ! empty( $eia_call_log ) ) {
            echo '<h3>Last fallback EIA calls</h3>';
            echo '<details><summary style="cursor:pointer;color:#2271b1;">Show last ' . count( $eia_call_log ) . ' calls</summary>';
            echo '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Time</th><th>Chunk</th><th>Activity</th><th>Product</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $eia_call_log ) as $entry ) {
                $outcome_color = in_array( $entry['outcome'], array( 'http_error', 'rate_limited_or_network' ), true ) ? '#a94442' : ( $entry['outcome'] === 'partial' ? '#b26a00' : ( $entry['outcome'] === 'ok' ? '#2e7d32' : '#666' ) );
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td>' . esc_html( $entry['chunk_label'] ) . '</td><td>' . esc_html( $entry['activity_id'] ) . '</td><td>' . esc_html( $entry['product_id'] ) . '</td><td style="color:' . $outcome_color . ';font-weight:600;">' . esc_html( $entry['outcome'] ) . '</td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        echo '<h3>🔍 Debug Fetch (Nuclear EIA check)</h3>';
        echo '<form method="post">';
        wp_nonce_field( 'cii_debug_fetch_action', 'cii_debug_fetch_nonce' );
        echo '<button type="submit" name="cii_debug_fetch" value="1" class="button">Test Nuclear EIA (France prod vs cons)</button>';
        echo '</form>';
        if ( $cii_debug_results ) {
            echo '<details><summary style="cursor:pointer;color:#2271b1;">Show results</summary>';
            echo '<h4>France Nuclear — Production</h4>';
            echo '<pre style="white-space:pre-wrap;max-height:250px;overflow-y:auto;background:#f6f7f7;padding:8px;">' . esc_html( wp_json_encode( $cii_debug_results['nuclear_france_prod'], JSON_PRETTY_PRINT ) ) . '</pre>';
            echo '<h4>France Nuclear — Consumption</h4>';
            echo '<pre style="white-space:pre-wrap;max-height:250px;overflow-y:auto;background:#f6f7f7;padding:8px;">' . esc_html( wp_json_encode( $cii_debug_results['nuclear_france_cons'], JSON_PRETTY_PRINT ) ) . '</pre>';
            echo '</details>';
        }
        echo '</div></div>';

        // ─── COMPOSITE PREVIEW ──────────────────────────────────────────
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-area"></span> 📊 Composite Index Preview</h2></div>';
        echo '<div class="inside">';

        $composite = get_option( 'cii_composite_index', null );
        if ( $just_built_composite ) {
            echo '<div class="notice notice-success" style="margin:0 0 12px;"><p>✅ Composite rebuilt just now from current stored pillar data.</p></div>';
        }

        if ( $composite && ! empty( $composite['countries'] ) ) {
            echo '<p>Last updated: ' . esc_html( $composite['last_updated'] ) . ' | Version: ' . esc_html( $composite['version'] ) . '</p>';
            echo '<p><strong>Total scored:</strong> ' . esc_html( $composite['total_countries'] ) . ' | <strong>Excluded:</strong> ' . esc_html( $composite['excluded'] ) . '</p>';

            $countries = $composite['countries'];
            $full_countries = array_filter( $countries, function ( $row ) { return ( $row['coverage_type'] ?? '' ) === 'full'; } );
            $partial_countries = array_filter( $countries, function ( $row ) { return ( $row['coverage_type'] ?? '' ) === 'partial'; } );

            uasort( $full_countries, function ( $a, $b ) { return $b['composite_score'] <=> $a['composite_score']; } );
            uasort( $partial_countries, function ( $a, $b ) { return $b['composite_score'] <=> $a['composite_score']; } );

            $render_preview_table = function ( $rows ) {
                echo '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Rank</th><th>Country</th><th>Score</th><th>Energy</th><th>HHI</th><th>Maritime</th><th>Coverage</th></tr></thead><tbody>';
                $count = 0;
                foreach ( $rows as $iso3 => $row ) {
                    $count++;
                    if ( $count > 20 ) { break; }
                    $rank_str = isset( $row['rank_display']['string_format'] ) ? $row['rank_display']['string_format'] : '—';
                    $e = isset( $row['energy_dependency_percentile'] ) ? round( $row['energy_dependency_percentile'], 1 ) : '—';
                    $h = isset( $row['supplier_concentration_percentile'] ) ? round( $row['supplier_concentration_percentile'], 1 ) : '—';
                    $m = isset( $row['maritime_vulnerability_percentile'] ) ? round( $row['maritime_vulnerability_percentile'], 1 ) : '—';
                    $cov = $row['coverage_type'] ?? 'partial';
                    $cov_style = $cov === 'full' ? '#2e7d32' : '#b26a00';
                    echo '<tr><td><strong>' . esc_html( $rank_str ) . '</strong></td><td><strong>' . esc_html( $iso3 ) . '</strong></td><td>' . esc_html( $row['composite_score'] ) . '</td><td>' . esc_html( $e ) . '</td><td>' . esc_html( $h ) . '</td><td>' . esc_html( $m ) . '</td><td style="color:' . $cov_style . ';">' . esc_html( $cov ) . '</td></tr>';
                }
                if ( count( $rows ) > 20 ) {
                    echo '<tr><td colspan="7" style="text-align:center;color:#666;">... and ' . ( count( $rows ) - 20 ) . ' more countries</td></tr>';
                }
                echo '</tbody></table>';
            };

            echo '<h4>Full Index (' . count( $full_countries ) . ')</h4>';
            $render_preview_table( $full_countries );

            echo '<h4>Partial Index (' . count( $partial_countries ) . ')</h4>';
            $render_preview_table( $partial_countries );

            echo '<p style="margin-top:12px;color:#666;">REST endpoint: <code>' . esc_url( rest_url( 'blomstra/v1/critical-infrastructure-index' ) ) . '</code></p>';
        } else {
            echo '<p><em>No composite data yet. Refresh pillars and build the composite to see results here.</em></p>';
        }

        echo '</div></div>';

        echo '</div>'; // .wrap
    }
}
