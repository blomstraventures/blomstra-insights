/**
 * Blomstra Reference Data — Shared Utility & Reference Layer (v2.7.0)
 *
 * Pure reference data / lookup functions and collection engines used across
 * Blomstra index tools (CII, GERI, and future indices).
 *
 * LOAD ORDER: Must run BEFORE downstream index tools (e.g. CII, GERI).
 *
 * @package Blomstra
 * @version 2.7.0
 * @since   2.7.0  - Fixed WGI overwrite bug: now compares year, keeps highest.
 *                 - Fixed false-zero bug: is_numeric() check before floatval().
 *                 - Fixed IMF "latest year" ambiguity: only uses years <= current year.
 *                 - Added IMF→ISO3 mapping for non-standard codes.
 *                 - Added retry logic (3 attempts) with exponential backoff for WB/IMF.
 *                 - Added rate-limit sleep (1s) between sequential calls.
 *                 - Implemented atomic cache updates (staging → live).
 *                 - Added data provenance fields to cached payloads.
 *                 - Rank function now enforces rsort() internally.
 *                 - GNI is now the primary macro indicator, GDP fallback transparently tracked.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── LANDLOCKED COUNTRIES (UN-OHRLLS list) ────────────────────────

if ( ! defined( 'BLOMSTRA_LANDLOCKED_ISO3' ) ) {
    define( 'BLOMSTRA_LANDLOCKED_ISO3', array(
        'AFG', 'AND', 'ARM', 'AUT', 'AZE', 'BLR', 'BTN', 'BOL', 'BWA', 'BFA',
        'BDI', 'CAF', 'TCD', 'CZE', 'ETH', 'SWZ', 'HUN', 'KAZ', 'KGZ', 'LAO',
        'LSO', 'LIE', 'LUX', 'MWI', 'MLI', 'MDA', 'MNG', 'NPL', 'NER', 'MKD',
        'PRY', 'RWA', 'SMR', 'SRB', 'SVK', 'SSD', 'CHE', 'TJK', 'TKM', 'UGA',
        'UZB', 'VAT', 'ZMB', 'ZWE',
    ) );
}

if ( ! function_exists( 'blomstra_is_landlocked' ) ) {
    function blomstra_is_landlocked( $iso3 ) {
        return in_array( strtoupper( trim( $iso3 ) ), BLOMSTRA_LANDLOCKED_ISO3, true );
    }
}

// ─── GLOBAL COUNTRY LIST (World Bank) ─────────────────────────────

if ( ! function_exists( 'blomstra_get_global_country_list' ) ) {
    function blomstra_get_global_country_list( $force = false ) {
        $cache_key = 'blomstra_global_country_list';
        if ( $force ) {
            delete_transient( $cache_key );
        }
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $names = array();
        $page = 1;
        do {
            $url  = "https://api.worldbank.org/v2/country?format=json&per_page=300&page={$page}";
            $resp = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => 'BlomstraReferenceData/2.7.0' ) );
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

        if ( ! empty( $names ) ) {
            set_transient( $cache_key, $names, DAY_IN_SECONDS );
        }
        return $names;
    }
}

// ─── COMTRADE REPORTER-CODE MAP ───────────────────────────────────

if ( ! defined( 'BLOMSTRA_COMTRADE_REPORTER_URL' ) ) {
    define( 'BLOMSTRA_COMTRADE_REPORTER_URL', 'https://comtradeapi.un.org/files/v1/app/reference/Reporters.json' );
}
if ( ! defined( 'BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL', WEEK_IN_SECONDS );
}

if ( ! function_exists( 'blomstra_get_comtrade_reporter_map' ) ) {
    function blomstra_get_comtrade_reporter_map( $force = false ) {
        $cache_key = 'blomstra_comtrade_reporters';
        if ( $force ) {
            delete_transient( $cache_key );
        }
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $debug = array(
            'checked_at' => current_time( 'mysql' ),
            'url'        => BLOMSTRA_COMTRADE_REPORTER_URL,
        );

        $response = wp_remote_get( BLOMSTRA_COMTRADE_REPORTER_URL, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            $debug['result'] = 'wp_error';
            $debug['error']  = $response->get_error_message();
            update_option( 'blomstra_comtrade_reporters_debug', $debug, false );
            error_log( 'Blomstra Comtrade reporter list fetch failed: ' . $response->get_error_message() );
            return array();
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $debug['http_code']    = $http_code;
        $debug['body_snippet'] = substr( $body, 0, 500 );

        $decoded   = json_decode( $body, true );
        $reporters = $decoded['results'] ?? null;
        if ( ! is_array( $reporters ) ) {
            $debug['result'] = 'invalid_json_or_missing_results_key';
            update_option( 'blomstra_comtrade_reporters_debug', $debug, false );
            error_log( 'Blomstra Comtrade reporter list: no results[] array in response' );
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

        $debug['result']           = ! empty( $map ) ? 'ok' : 'parsed_but_empty';
        $debug['countries_parsed'] = count( $map );
        update_option( 'blomstra_comtrade_reporters_debug', $debug, false );

        if ( ! empty( $map ) ) {
            set_transient( $cache_key, $map, BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL );
        }
        return $map;
    }
}

// ─── MARITIME (WORLD BANK LSCI, RAW ONLY) ─────────────────────────

if ( ! defined( 'BLOMSTRA_MARITIME_INDICATOR_CODE' ) ) {
    define( 'BLOMSTRA_MARITIME_INDICATOR_CODE', 'IS.SHP.GCNW.XQ' );
}
if ( ! defined( 'BLOMSTRA_MARITIME_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_MARITIME_CACHE_TTL', WEEK_IN_SECONDS );
}

if ( ! function_exists( 'blomstra_get_maritime_raw' ) ) {
    function blomstra_get_maritime_raw( $force = false, $attempt = 1 ) {
        $cache_key = 'blomstra_maritime_raw';
        if ( $force ) {
            delete_transient( $cache_key );
        }
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $current_year = (int) current_time( 'Y' );
        $start_year   = $current_year - 20;
        $url = "https://api.worldbank.org/v2/country/all/indicator/" . BLOMSTRA_MARITIME_INDICATOR_CODE . "?format=json&per_page=20000&date={$start_year}:{$current_year}";

        $debug = array( 'checked_at' => current_time( 'mysql' ), 'url' => $url );
        $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

        if ( is_wp_error( $response ) && $attempt < 2 ) {
            sleep( 3 );
            return blomstra_get_maritime_raw( false, $attempt + 1 );
        }

        if ( is_wp_error( $response ) ) {
            $debug['result'] = 'wp_error';
            $debug['error']  = $response->get_error_message() . ' (after ' . $attempt . ' attempt(s))';
            update_option( 'blomstra_maritime_fetch_debug', $debug, false );
            error_log( 'Blomstra Maritime fetch WP_Error: ' . $response->get_error_message() );
            return array();
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $debug['http_code'] = $http_code;

        if ( $http_code !== 200 ) {
            $debug['result'] = 'http_error';
            $debug['body_snippet'] = substr( $body_raw, 0, 500 );
            update_option( 'blomstra_maritime_fetch_debug', $debug, false );
            error_log( 'Blomstra Maritime fetch HTTP ' . $http_code );
            return array();
        }

        $body = json_decode( $body_raw, true );
        if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
            $debug['result'] = 'bad_shape';
            $debug['body_snippet'] = substr( $body_raw, 0, 500 );
            update_option( 'blomstra_maritime_fetch_debug', $debug, false );
            error_log( 'Blomstra Maritime: unexpected response shape — body[1] missing' );
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

        $debug['result']           = ! empty( $data ) ? 'ok' : 'parsed_but_empty';
        $debug['countries_parsed'] = count( $data );
        update_option( 'blomstra_maritime_fetch_debug', $debug, false );

        if ( ! empty( $data ) ) {
            set_transient( $cache_key, $data, BLOMSTRA_MARITIME_CACHE_TTL );
        }
        return $data;
    }
}

if ( ! function_exists( 'blomstra_get_maritime_value' ) ) {
    function blomstra_get_maritime_value( $iso3 ) {
        $iso3 = strtoupper( trim( $iso3 ) );
        if ( blomstra_is_landlocked( $iso3 ) ) {
            return array( 'value' => 0.0, 'is_landlocked' => true, 'year' => null );
        }
        $raw = blomstra_get_maritime_raw();
        if ( isset( $raw[ $iso3 ] ) ) {
            return array( 'value' => $raw[ $iso3 ]['value'], 'is_landlocked' => false, 'year' => $raw[ $iso3 ]['year'] );
        }
        return array( 'value' => null, 'is_landlocked' => false, 'year' => null );
    }
}

// ─── HHI (COMTRADE, FULL COLLECTION ENGINE) ───────────────────────

if ( ! defined( 'BLOMSTRA_COMTRADE_BASE_URL' ) ) {
    define( 'BLOMSTRA_COMTRADE_BASE_URL', 'https://comtradeapi.un.org/data/v1/get/C/A/HS' );
}
if ( ! defined( 'BLOMSTRA_HHI_CHUNK_SIZE' ) ) {
    define( 'BLOMSTRA_HHI_CHUNK_SIZE', 50 );
}
if ( ! defined( 'BLOMSTRA_HHI_LOOKBACK' ) ) {
    define( 'BLOMSTRA_HHI_LOOKBACK', 4 );
}
if ( ! defined( 'BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED' ) ) {
    define( 'BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED', '__BLOMSTRA_QUOTA_EXHAUSTED__' );
}

if ( ! function_exists( 'blomstra_log_comtrade_call' ) ) {
    function blomstra_log_comtrade_call( $reporter_code, $year, $outcome, $detail ) {
        $log = get_option( 'blomstra_comtrade_call_log', array() );
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
        update_option( 'blomstra_comtrade_call_log', $log, false );
    }
}

if ( ! function_exists( 'blomstra_comtrade_fetch_partner_imports_batch' ) ) {
    function blomstra_comtrade_fetch_partner_imports_batch( $reporter_codes, $year, $attempt = 1 ) {
        if ( ! defined( 'COMTRADE_PRIMARY_KEY' ) || COMTRADE_PRIMARY_KEY === '' ) {
            blomstra_log_comtrade_call( implode( ',', $reporter_codes ), $year, 'network_error', 'COMTRADE_PRIMARY_KEY not defined/empty' );
            return null;
        }

        $chunk_label = count( $reporter_codes ) . ' reporters (' . implode( ',', $reporter_codes ) . ')';
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
            $url = BLOMSTRA_COMTRADE_BASE_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
            $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

            if ( is_wp_error( $response ) ) {
                $fail_reason = 'network error: ' . $response->get_error_message();
                blomstra_log_comtrade_call( $chunk_label, $year, 'network_error', $fail_reason );
                if ( $attempt < 3 ) {
                    sleep( 3 * $attempt );
                    return blomstra_comtrade_fetch_partner_imports_batch( $reporter_codes, $year, $attempt + 1 );
                }
                return null;
            }

            $code = wp_remote_retrieve_response_code( $response );

            if ( $code === 429 || $code === 403 ) {
                $body_snip = substr( wp_remote_retrieve_body( $response ), 0, 300 );
                if ( preg_match( '/[Tt]ry again in\s+(\d+)\s+seconds?/', $body_snip, $m ) && (int) $m[1] <= 90 && $attempt <= 2 ) {
                    $wait = (int) $m[1] + 2;
                    blomstra_log_comtrade_call( $chunk_label, $year, 'rate_limited_retrying', 'HTTP ' . $code . ' — waiting ' . $wait . 's: ' . $body_snip );
                    sleep( $wait );
                    return blomstra_comtrade_fetch_partner_imports_batch( $reporter_codes, $year, $attempt + 1 );
                }
                $fail_reason = 'HTTP ' . $code . ' — likely quota: ' . $body_snip;
                blomstra_log_comtrade_call( $chunk_label, $year, 'quota_or_rate_limit', 'HTTP ' . $code . ' (attempt ' . $attempt . '): ' . $fail_reason );
                return BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED;
            }

            if ( $code !== 200 ) {
                $fail_reason = 'HTTP ' . $code . ' — body: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 );
                blomstra_log_comtrade_call( $chunk_label, $year, 'http_error', $fail_reason );
                if ( $code >= 500 && $attempt < 3 ) {
                    sleep( 3 * $attempt );
                    return blomstra_comtrade_fetch_partner_imports_batch( $reporter_codes, $year, $attempt + 1 );
                }
                return null;
            }

            $raw_body = wp_remote_retrieve_body( $response );
            if ( strlen( $raw_body ) > 15 * 1024 * 1024 ) {
                blomstra_log_comtrade_call( $chunk_label, $year, 'oversized_response', 'response body > 15MB' );
                return null;
            }

            $body = json_decode( $raw_body, true );
            unset( $raw_body );
            if ( isset( $body['error'] ) && $body['error'] !== '' ) {
                blomstra_log_comtrade_call( $chunk_label, $year, 'api_error', (string) $body['error'] );
                return null;
            }
            if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
                blomstra_log_comtrade_call( $chunk_label, $year, 'bad_shape', 'missing data array' );
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
            blomstra_log_comtrade_call( $chunk_label, $year, 'empty', 'HTTP 200, zero rows returned' );
            return array();
        }

        blomstra_log_comtrade_call( $chunk_label, $year, 'success', 'Retrieved ' . count( $all_rows ) . ' trade rows' );
        return $all_rows;
    }
}

if ( ! function_exists( 'blomstra_compute_hhi_from_batch_rows' ) ) {
    function blomstra_compute_hhi_from_batch_rows( $rows, $reporter_codes, $year ) {
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
            $hhi = min( 10000, max( 0, $hhi ) );
            $results[ $rc ] = array( 'value' => round( $hhi, 2 ), 'year' => $year, 'source' => 'Comtrade' );
        }
        return $results;
    }
}

if ( ! function_exists( 'blomstra_refresh_comtrade_hhi_data' ) ) {
    function blomstra_refresh_comtrade_hhi_data( $year = null, $iso3_list = null, $force = false ) {
        $run_started = current_time( 'mysql' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 900 );
        }
        if ( $year === null ) {
            $year = (int) current_time( 'Y' ) - 1;
        }
        if ( $force ) {
            delete_option( 'blomstra_comtrade_hhi_data' );
        }

        $reporter_map = blomstra_get_comtrade_reporter_map();
        if ( $iso3_list === null ) {
            $iso3_list = array_keys( blomstra_get_global_country_list() );
        }

        $results = array();
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
            'chunk_size'           => BLOMSTRA_HHI_CHUNK_SIZE,
            'last_checkpoint'      => null,
            'failed_chunks'        => array(),
        );

        update_option( 'blomstra_hhi_refresh_summary', $summary, false );

        $checkpoint = function () use ( &$results, &$summary ) {
            $existing_now = get_option( 'blomstra_comtrade_hhi_data', array() );
            $merged_now   = array_merge( $existing_now, $results );
            update_option( 'blomstra_comtrade_hhi_data', $merged_now, false );
            $summary['last_checkpoint'] = current_time( 'mysql' );
            update_option( 'blomstra_hhi_refresh_summary', $summary, false );
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

        for ( $offset = 0; $offset <= BLOMSTRA_HHI_LOOKBACK && ! empty( $pending ) && ! $quota_dead; $offset++ ) {
            $try_year = $year - $offset;
            $still_pending = array();
            $chunks = array_chunk( $pending, BLOMSTRA_HHI_CHUNK_SIZE, true );

            foreach ( $chunks as $chunk ) {
                if ( $quota_dead ) {
                    foreach ( $chunk as $iso3 => $code ) {
                        $still_pending[ $iso3 ] = $code;
                    }
                    continue;
                }

                $codes_in_chunk = array_values( $chunk );
                $rows = blomstra_comtrade_fetch_partner_imports_batch( $codes_in_chunk, $try_year );

                if ( $rows === BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED ) {
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
                    $failed_chunk_label = count( $chunk ) . ' reporters: ' . implode( ',', array_slice( array_keys( $chunk ), 0, 3 ) );
                    $summary['failed_chunks'][] = array(
                        'chunk'  => $failed_chunk_label,
                        'year'   => $try_year,
                        'reason' => 'network or API error',
                    );
                    foreach ( $chunk as $iso3 => $code ) {
                        if ( $offset < BLOMSTRA_HHI_LOOKBACK ) {
                            $still_pending[ $iso3 ] = $code;
                        } else {
                            $summary['attempted_no_data']++;
                            $results[ $iso3 ] = array(
                                'value' => null, 'scale' => '0-10000', 'requested_year' => $year,
                                'actual_year' => null, 'source' => 'no data in lookback window',
                                'last_updated' => current_time( 'mysql' ),
                            );
                        }
                    }
                    continue;
                }

                $computed_by_code = blomstra_compute_hhi_from_batch_rows( $rows, $codes_in_chunk, $try_year );
                unset( $rows );

                foreach ( $chunk as $iso3 => $code ) {
                    if ( isset( $computed_by_code[ $code ] ) ) {
                        $summary['succeeded']++;
                        $results[ $iso3 ] = array(
                            'value' => $computed_by_code[ $code ]['value'], 'scale' => '0-10000', 'requested_year' => $year,
                            'actual_year' => $computed_by_code[ $code ]['year'], 'source' => 'Comtrade',
                            'last_updated' => current_time( 'mysql' ),
                        );
                    } else {
                        if ( $offset < BLOMSTRA_HHI_LOOKBACK ) {
                            $still_pending[ $iso3 ] = $code;
                        } else {
                            $summary['attempted_no_data']++;
                            $results[ $iso3 ] = array(
                                'value' => null, 'scale' => '0-10000', 'requested_year' => $year,
                                'actual_year' => null, 'source' => 'no data in lookback window',
                                'last_updated' => current_time( 'mysql' ),
                            );
                        }
                    }
                }

                $checkpoint();
                sleep( 2 );
            }

            if ( $offset >= BLOMSTRA_HHI_LOOKBACK ) {
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

        $checkpoint();
        $summary['run_finished'] = current_time( 'mysql' );
        update_option( 'blomstra_hhi_refresh_summary', $summary, false );

        return $results;
    }
}

if ( ! function_exists( 'blomstra_get_comtrade_hhi_data' ) ) {
    function blomstra_get_comtrade_hhi_data() {
        return get_option( 'blomstra_comtrade_hhi_data', array() );
    }
}

if ( ! function_exists( 'blomstra_get_country_hhi_value' ) ) {
    function blomstra_get_country_hhi_value( $iso3 ) {
        $data = blomstra_get_comtrade_hhi_data();
        $iso3 = strtoupper( trim( $iso3 ) );
        return $data[ $iso3 ] ?? null;
    }
}

// ─── EIA (RAW PER-FUEL DATA ONLY) ──────────────────────────────────

if ( ! defined( 'BLOMSTRA_EIA_BASE_URL' ) ) {
    define( 'BLOMSTRA_EIA_BASE_URL', 'https://api.eia.gov/v2/international/data/' );
}
if ( ! defined( 'BLOMSTRA_EIA_FUEL_PRODUCT_IDS' ) ) {
    define( 'BLOMSTRA_EIA_FUEL_PRODUCT_IDS', array(
        '4411' => 'Coal',
        '4413' => 'Natural gas',
        '4415' => 'Petroleum and other liquids',
        '4417' => 'Nuclear',
        '4418' => 'Renewables and other',
    ) );
}
if ( ! defined( 'BLOMSTRA_EIA_ACTIVITY_PROD' ) ) {
    define( 'BLOMSTRA_EIA_ACTIVITY_PROD', '1' );
}
if ( ! defined( 'BLOMSTRA_EIA_ACTIVITY_CONS' ) ) {
    define( 'BLOMSTRA_EIA_ACTIVITY_CONS', '2' );
}
if ( ! defined( 'BLOMSTRA_EIA_UNIT' ) ) {
    define( 'BLOMSTRA_EIA_UNIT', 'QBTU' );
}
if ( ! defined( 'BLOMSTRA_EIA_CHUNK_SIZE' ) ) {
    define( 'BLOMSTRA_EIA_CHUNK_SIZE', 25 );
}

if ( ! function_exists( 'blomstra_log_eia_call' ) ) {
    function blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, $outcome, $detail ) {
        $log = get_option( 'blomstra_eia_call_log', array() );
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
        update_option( 'blomstra_eia_call_log', $log, false );
    }
}

if ( ! function_exists( 'blomstra_eia_fetch_activity_batch' ) ) {
    function blomstra_eia_fetch_activity_batch( $country_codes, $activity_id, $product_id, $attempt = 1 ) {
        if ( ! defined( 'EIA_API_KEY' ) || EIA_API_KEY === '' ) {
            return array( 'status' => 'failed', 'rows' => array(), 'error' => 'API key missing' );
        }

        $scalar_args = array(
            'api_key'              => EIA_API_KEY,
            'facets[activityId][]' => $activity_id,
            'facets[productId][]'  => $product_id,
            'facets[unit][]'       => BLOMSTRA_EIA_UNIT,
            'frequency'            => 'annual',
            'data[]'               => 'value',
            'sort[0][column]'      => 'period',
            'sort[0][direction]'   => 'desc',
            'length'               => 5000,
        );

        $query_pairs = array();
        foreach ( $scalar_args as $k => $v ) {
            $query_pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
        }
        foreach ( $country_codes as $cc ) {
            $query_pairs[] = rawurlencode( 'facets[countryRegionId][]' ) . '=' . rawurlencode( $cc );
        }
        $url = BLOMSTRA_EIA_BASE_URL . '?' . implode( '&', $query_pairs );

        $response = wp_remote_get( $url, array( 'timeout' => 45 ) );
        $chunk_label = count( $country_codes ) . ' countries (' . implode( ',', array_slice( $country_codes, 0, 3 ) ) . ')';

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
            error_log( 'Blomstra EIA batch fetch FAILED (' . $chunk_label . ', attempt ' . $attempt . '): ' . $fail_reason );
            blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, $should_retry ? 'rate_limited_or_network' : 'http_error', $fail_reason );
            if ( $should_retry && $attempt < 3 ) {
                sleep( 2 * $attempt );
                return blomstra_eia_fetch_activity_batch( $country_codes, $activity_id, $product_id, $attempt + 1 );
            }
            return array( 'status' => 'failed', 'rows' => array(), 'error' => $fail_reason );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $rows = $body['response']['data'] ?? array();
        blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, 'ok', 'Retrieved ' . count( $rows ) . ' rows' );
        return array( 'status' => 'ok', 'rows' => $rows, 'error' => null );
    }
}

if ( ! function_exists( 'blomstra_eia_pick_latest_per_country' ) ) {
    function blomstra_eia_pick_latest_per_country( $rows ) {
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

if ( ! function_exists( 'blomstra_refresh_eia_raw_data' ) ) {
    function blomstra_refresh_eia_raw_data( $iso3_list = null, $force = false ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }
        if ( $iso3_list === null ) {
            $iso3_list = array_keys( blomstra_get_global_country_list() );
        }
        if ( $force ) {
            delete_option( 'blomstra_eia_raw_data' );
        }

        $summary = array(
            'run_started'        => current_time( 'mysql' ),
            'countries_in_scope' => count( $iso3_list ),
            'fuels_total'        => count( BLOMSTRA_EIA_FUEL_PRODUCT_IDS ),
            'last_checkpoint'    => null,
            'failed_chunks'      => array(),
        );
        update_option( 'blomstra_eia_refresh_summary', $summary, false );

        $existing_raw = get_option( 'blomstra_eia_raw_data', array( 'consumption' => array(), 'production' => array() ) );
        $consumption_by_fuel = $existing_raw['consumption'];
        $production_by_fuel  = $existing_raw['production'];

        foreach ( BLOMSTRA_EIA_FUEL_PRODUCT_IDS as $product_id => $fuel_name ) {
            $chunks = array_chunk( $iso3_list, BLOMSTRA_EIA_CHUNK_SIZE );

            $consumption_by_fuel[ $product_id ] = array();
            foreach ( $chunks as $chunk ) {
                $result = blomstra_eia_fetch_activity_batch( $chunk, BLOMSTRA_EIA_ACTIVITY_CONS, $product_id );
                if ( $result['status'] === 'ok' ) {
                    $latest = blomstra_eia_pick_latest_per_country( $result['rows'] );
                    foreach ( $latest as $iso3 => $row ) {
                        if ( $row['value'] != 0.0 ) {
                            $consumption_by_fuel[ $product_id ][ $iso3 ] = $row['value'];
                        }
                    }
                } else {
                    $summary['failed_chunks'][] = array(
                        'chunk'    => implode( ',', array_slice( $chunk, 0, 3 ) ),
                        'activity' => 'consumption',
                        'fuel'     => $fuel_name,
                        'reason'   => $result['error'] ?? 'unknown error',
                    );
                }
                usleep( 200000 );
            }

            $production_by_fuel[ $product_id ] = array();
            foreach ( $chunks as $chunk ) {
                $result = blomstra_eia_fetch_activity_batch( $chunk, BLOMSTRA_EIA_ACTIVITY_PROD, $product_id );
                if ( $result['status'] === 'ok' ) {
                    $latest = blomstra_eia_pick_latest_per_country( $result['rows'] );
                    foreach ( $chunk as $iso3 ) {
                        if ( isset( $latest[ $iso3 ] ) ) {
                            $production_by_fuel[ $product_id ][ $iso3 ] = array( 'value' => $latest[ $iso3 ]['value'], 'status' => 'ok' );
                        } else {
                            $production_by_fuel[ $product_id ][ $iso3 ] = array( 'value' => 0.0, 'status' => 'confirmed_zero' );
                        }
                    }
                } else {
                    $summary['failed_chunks'][] = array(
                        'chunk'    => implode( ',', array_slice( $chunk, 0, 3 ) ),
                        'activity' => 'production',
                        'fuel'     => $fuel_name,
                        'reason'   => $result['error'] ?? 'unknown error',
                    );
                }
                usleep( 200000 );
            }

            update_option( 'blomstra_eia_raw_data', array( 'consumption' => $consumption_by_fuel, 'production' => $production_by_fuel ), false );
            $summary['last_checkpoint'] = current_time( 'mysql' );
            update_option( 'blomstra_eia_refresh_summary', $summary, false );
        }

        $summary['run_finished'] = current_time( 'mysql' );
        update_option( 'blomstra_eia_refresh_summary', $summary, false );

        return array( 'consumption' => $consumption_by_fuel, 'production' => $production_by_fuel );
    }
}

if ( ! function_exists( 'blomstra_get_eia_raw_data' ) ) {
    function blomstra_get_eia_raw_data() {
        return get_option( 'blomstra_eia_raw_data', array( 'consumption' => array(), 'production' => array() ) );
    }
}

if ( ! function_exists( 'blomstra_get_eia_country_totals' ) ) {
    function blomstra_get_eia_country_totals( $iso3 ) {
        $raw  = blomstra_get_eia_raw_data();
        $iso3 = strtoupper( trim( $iso3 ) );

        $total_consumption = 0.0;
        $total_production  = 0.0;

        if ( ! empty( $raw['consumption'] ) ) {
            foreach ( $raw['consumption'] as $pid => $c_data ) {
                if ( isset( $c_data[ $iso3 ] ) ) {
                    $total_consumption += (float) $c_data[ $iso3 ];
                }
            }
        }

        if ( ! empty( $raw['production'] ) ) {
            foreach ( $raw['production'] as $pid => $p_data ) {
                if ( isset( $p_data[ $iso3 ]['value'] ) ) {
                    $total_production += (float) $p_data[ $iso3 ]['value'];
                }
            }
        }

        return array(
            'consumption_qbtu' => round( $total_consumption, 4 ),
            'production_qbtu'  => round( $total_production, 4 ),
        );
    }
}

// ─── SYSTEM & API KEY HEALTH CHECK ────────────────────────────────

if ( ! function_exists( 'blomstra_check_api_keys_status' ) ) {
    function blomstra_check_api_keys_status() {
        return array(
            'comtrade' => defined( 'COMTRADE_PRIMARY_KEY' ) && COMTRADE_PRIMARY_KEY !== '',
            'eia'      => defined( 'EIA_API_KEY' ) && EIA_API_KEY !== '',
        );
    }
}

// ─── CRON SCHEDULING & HEALTH TRACKING ────────────────────────────

if ( ! function_exists( 'blomstra_update_cron_status' ) ) {
    function blomstra_update_cron_status( $pillar, $status, $message, $count = 0 ) {
        $cron_status = get_option( 'blomstra_cron_status', array() );
        $cron_status[ $pillar ] = array(
            'last_run'  => current_time( 'mysql' ),
            'timestamp' => time(),
            'status'    => $status,
            'message'   => $message,
            'count'     => $count,
        );
        update_option( 'blomstra_cron_status', $cron_status, false );
    }
}

// ─── WB INDICATOR LOGGING ──────────────────────────────────────────

if ( ! function_exists( 'blomstra_log_wb_indicator_fetch' ) ) {
    function blomstra_log_wb_indicator_fetch( $indicator_code, $row_count, $status, $detail = '' ) {
        $log = get_option( 'blomstra_wb_indicator_fetch_log', array() );
        $log[] = array(
            'time'    => current_time( 'mysql' ),
            'code'    => $indicator_code,
            'rows'    => $row_count,
            'status'  => $status,
            'detail'  => $detail,
        );
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }
        update_option( 'blomstra_wb_indicator_fetch_log', $log, false );
    }
}

// ─── IMF WEO INDICATOR FUNCTIONS ──────────────────────────────────

if ( ! defined( 'BLOMSTRA_IMF_BASE_URL' ) ) {
    define( 'BLOMSTRA_IMF_BASE_URL', 'https://www.imf.org/external/datamapper/api/v1' );
}
if ( ! defined( 'BLOMSTRA_IMF_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_IMF_CACHE_TTL', WEEK_IN_SECONDS );
}

/**
 * IMF non-standard country code to ISO3 mapping.
 * DataMapper sometimes uses alternate codes for certain countries/territories.
 */
if ( ! defined( 'BLOMSTRA_IMF_TO_ISO3_MAP' ) ) {
    define( 'BLOMSTRA_IMF_TO_ISO3_MAP', array(
        'KSV' => 'XKX', // Kosovo
        'WBG' => 'PSE', // West Bank & Gaza
        'ZAR' => 'COD', // DR Congo (historic code)
        'ROM' => 'ROU', // Romania (historic code)
        // Add others as discovered via sandbox testing
    ) );
}

if ( ! function_exists( 'blomstra_fetch_imf_indicator_batch' ) ) {
    /**
     * Fetch a single IMF WEO indicator for all countries.
     * Returns the latest ACTUAL year (<= current year) for each country.
     * Implements retry logic (3 attempts, exponential backoff).
     *
     * @param string $code   IMF indicator code (e.g., 'NGDP_RPCH')
     * @param bool   $force  Bypass cache.
     * @return array ISO3 => [ 'value' => float, 'year' => string, 'data_type' => 'actual'|'forecast_fallback', 'fetched_at' => string, 'source' => string ]
     */
    function blomstra_fetch_imf_indicator_batch( $code, $force = false ) {
        $cache_key = 'blomstra_imf_indicator_' . md5( $code );
        $staging_key = $cache_key . '_tmp';

        if ( $force ) {
            delete_transient( $cache_key );
            delete_transient( $staging_key );
        }

        // Check live cache
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        // Check staging cache (in case previous refresh failed)
        $staging = get_transient( $staging_key );
        if ( false !== $staging && is_array( $staging ) ) {
            set_transient( $cache_key, $staging, BLOMSTRA_IMF_CACHE_TTL );
            delete_transient( $staging_key );
            return $staging;
        }

        $url = BLOMSTRA_IMF_BASE_URL . '/' . $code;
        $current_year = (int) current_time( 'Y' );
        $iso3_map = BLOMSTRA_IMF_TO_ISO3_MAP;
        $out = array();

        // Retry logic (3 attempts)
        $attempt = 1;
        $max_attempts = 3;
        $backoff = 2;

        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'BlomstraReferenceData/2.7.1' ) );

            if ( is_wp_error( $response ) ) {
                error_log( "IMF indicator fetch ({$code}) attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                // Rate limit: wait longer and retry
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "IMF indicator fetch ({$code}) rate-limited after {$max_attempts} attempts" );
                return array();
            }

            if ( $http_code !== 200 ) {
                error_log( "IMF indicator fetch ({$code}) attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            $body_raw = wp_remote_retrieve_body( $response );
            $body = json_decode( $body_raw, true );
            if ( ! isset( $body['values'][ $code ] ) || ! is_array( $body['values'][ $code ] ) ) {
                error_log( "IMF indicator fetch ({$code}): no data array" );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            // Success – parse data
            foreach ( $body['values'][ $code ] as $imf_code => $years ) {
                $iso3 = $iso3_map[ $imf_code ] ?? $imf_code;
                if ( ! is_array( $years ) || empty( $years ) ) {
                    continue;
                }

                // Separate actual/historical years (<= current_year) from forecast years (> current_year)
                $actual_years = array_filter( array_keys( $years ), function( $y ) use ( $current_year ) {
                    return (int) $y <= $current_year;
                } );
                $forecast_years = array_filter( array_keys( $years ), function( $y ) use ( $current_year ) {
                    return (int) $y > $current_year;
                } );

                // For the structural "latest actual" value, pick the highest actual year
                if ( ! empty( $actual_years ) ) {
                    $latest_actual_year = max( $actual_years );
                    $out[ $iso3 ] = array(
                        'value'       => is_numeric( $years[ $latest_actual_year ] ) ? floatval( $years[ $latest_actual_year ] ) : null,
                        'year'        => (string) $latest_actual_year,
                        'data_type'   => $latest_actual_year == $current_year ? 'current_year_estimate' : 'actual',
                        'fetched_at'  => current_time( 'mysql' ),
                        'source'      => 'IMF WEO DataMapper',
                    );
                } elseif ( ! empty( $forecast_years ) ) {
                    // Fallback: if no actual data exists, use the earliest forecast (T+1) as proxy
                    $earliest_forecast_year = min( $forecast_years );
                    $out[ $iso3 ] = array(
                        'value'       => is_numeric( $years[ $earliest_forecast_year ] ) ? floatval( $years[ $earliest_forecast_year ] ) : null,
                        'year'        => (string) $earliest_forecast_year,
                        'data_type'   => 'forecast_fallback',
                        'fetched_at'  => current_time( 'mysql' ),
                        'source'      => 'IMF WEO DataMapper (forecast)',
                    );
                }
            }

            // If we got data, break out of retry loop
            if ( ! empty( $out ) ) {
                break;
            }

            // If we got empty data but no error, treat as success but empty
            break;
        }

        // If we have data, store in staging and then atomically copy to live
        if ( ! empty( $out ) ) {
            set_transient( $staging_key, $out, BLOMSTRA_IMF_CACHE_TTL );
            set_transient( $cache_key, $out, BLOMSTRA_IMF_CACHE_TTL );
            delete_transient( $staging_key );
        }

        return $out;
    }
}
// For forward pressure, we need a separate getter that returns forecast data for T+1
if ( ! function_exists( 'blomstra_fetch_imf_forecast_batch' ) ) {
    /**
     * Fetch IMF forecast data for a given horizon (1 = next year).
     * Implements retry logic (3 attempts, exponential backoff).
     *
     * @param string $code   IMF indicator code.
     * @param int    $horizon 1 = T+1, 2 = T+2, etc.
     * @param bool   $force
     * @return array ISO3 => [ 'value' => float, 'year' => string, 'data_type' => 'forecast', 'horizon' => int, 'fetched_at' => string, 'source' => string ]
     */
    function blomstra_fetch_imf_forecast_batch( $code, $horizon = 1, $force = false ) {
        $cache_key = 'blomstra_imf_forecast_' . md5( $code . '_h' . $horizon );
        $staging_key = $cache_key . '_tmp';

        if ( $force ) {
            delete_transient( $cache_key );
            delete_transient( $staging_key );
        }

        // Check live cache
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        // Check staging cache
        $staging = get_transient( $staging_key );
        if ( false !== $staging && is_array( $staging ) ) {
            set_transient( $cache_key, $staging, BLOMSTRA_IMF_CACHE_TTL );
            delete_transient( $staging_key );
            return $staging;
        }

        $url = BLOMSTRA_IMF_BASE_URL . '/' . $code;
        $current_year = (int) current_time( 'Y' );
        $target_year = $current_year + $horizon;
        $iso3_map = BLOMSTRA_IMF_TO_ISO3_MAP;
        $out = array();

        // Retry logic (3 attempts)
        $attempt = 1;
        $max_attempts = 3;
        $backoff = 2;

        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'BlomstraReferenceData/2.7.1' ) );

            if ( is_wp_error( $response ) ) {
                error_log( "IMF forecast fetch ({$code}) attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "IMF forecast fetch ({$code}) rate-limited after {$max_attempts} attempts" );
                return array();
            }

            if ( $http_code !== 200 ) {
                error_log( "IMF forecast fetch ({$code}) attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            $body_raw = wp_remote_retrieve_body( $response );
            $body = json_decode( $body_raw, true );
            if ( ! isset( $body['values'][ $code ] ) || ! is_array( $body['values'][ $code ] ) ) {
                error_log( "IMF forecast fetch ({$code}): no data array" );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            // Success – parse data
            foreach ( $body['values'][ $code ] as $imf_code => $years ) {
                $iso3 = $iso3_map[ $imf_code ] ?? $imf_code;
                if ( ! is_array( $years ) || empty( $years ) ) {
                    continue;
                }
                if ( isset( $years[ $target_year ] ) && is_numeric( $years[ $target_year ] ) ) {
                    $out[ $iso3 ] = array(
                        'value'     => floatval( $years[ $target_year ] ),
                        'year'      => (string) $target_year,
                        'data_type' => 'forecast',
                        'horizon'   => $horizon,
                        'fetched_at' => current_time( 'mysql' ),
                        'source'    => 'IMF WEO DataMapper (forecast)',
                    );
                }
            }

            if ( ! empty( $out ) ) {
                break;
            }
            break;
        }

        if ( ! empty( $out ) ) {
            set_transient( $staging_key, $out, BLOMSTRA_IMF_CACHE_TTL );
            set_transient( $cache_key, $out, BLOMSTRA_IMF_CACHE_TTL );
            delete_transient( $staging_key );
        }

        return $out;
    }
}

if ( ! function_exists( 'blomstra_log_imf_call' ) ) {
    function blomstra_log_imf_call( $indicator_code, $row_count, $status, $detail = '' ) {
        $log = get_option( 'blomstra_imf_call_log', array() );
        $log[] = array(
            'time'    => current_time( 'mysql' ),
            'code'    => $indicator_code,
            'rows'    => $row_count,
            'status'  => $status,
            'detail'  => $detail,
        );
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }
        update_option( 'blomstra_imf_call_log', $log, false );
    }
}

if ( ! function_exists( 'blomstra_refresh_imf_indicators' ) ) {
    function blomstra_refresh_imf_indicators( $force = false ) {
        $indicators = array(
            'NGDP_RPCH'   => 'gdp_growth_imf',
            'PCPIPCH'     => 'inflation_imf',
            'BCA_NGDPD'   => 'current_account_imf',
            'GGXWDG_NGDP' => 'gov_debt_imf',
            'GGXCNL_NGDP' => 'gov_balance_imf',
            'LUR'         => 'unemployment_imf',
        );

        $results = array();
        foreach ( $indicators as $code => $name ) {
            // Fetch actual data
            $data = blomstra_fetch_imf_indicator_batch( $code, $force );
            $row_count = count( $data );
            $status = $row_count > 0 ? 'success' : 'error';
            blomstra_log_imf_call( $code, $row_count, $status, $row_count > 0 ? 'OK' : 'No data returned' );
            $results[ $code ] = array(
                'success' => $row_count > 0,
                'count'   => $row_count,
            );
            // Also fetch T+1 forecast separately (for forward pressure)
            $forecast = blomstra_fetch_imf_forecast_batch( $code, 1, $force );
            // Log forecast row count separately but don't affect the "success" status
            if ( ! empty( $forecast ) ) {
                blomstra_log_imf_call( $code . '_forecast', count( $forecast ), 'success', 'T+1 forecast fetched' );
            }
            sleep(1); // Rate limit
        }
        return $results;
    }
}

if ( ! function_exists( 'blomstra_count_imf_cache' ) ) {
    function blomstra_count_imf_cache() {
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_imf_indicator_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        return (int) $count;
    }
}

if ( ! function_exists( 'blomstra_flush_imf_cache' ) ) {
    function blomstra_flush_imf_cache() {
        global $wpdb;
        return $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_imf_indicator_%'
             OR option_name LIKE '_transient_timeout_blomstra_imf_indicator_%'"
        );
    }
}

if ( ! function_exists( 'blomstra_cron_handle_imf' ) ) {
    function blomstra_cron_handle_imf() {
        blomstra_update_cron_status( 'imf', 'running', 'IMF WEO weekly refresh running...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }
            $result = blomstra_refresh_imf_indicators( true );
            $success_count = 0;
            $total_count = count( $result );
            foreach ( $result as $code => $status ) {
                if ( $status['success'] ) $success_count++;
            }
            $msg = 'IMF indicators refreshed: ' . $success_count . ' of ' . $total_count . ' fetched.';
            if ( $success_count === $total_count ) {
                blomstra_update_cron_status( 'imf', 'success', $msg, $success_count );
            } elseif ( $success_count > 0 ) {
                blomstra_update_cron_status( 'imf', 'partial', $msg, $success_count );
            } else {
                blomstra_update_cron_status( 'imf', 'error', 'IMF fetch failed or returned empty datasets.' );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'imf', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'IMF cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'imf', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'IMF cron fatal: ' . $e->getMessage() );
        }
    }
    add_action( 'blomstra_cron_imf_weekly_event', 'blomstra_cron_handle_imf' );
}

// ─── SHARED PERCENTILE‑RANK HELPERS ───────────────────────────────

if ( ! function_exists( 'blomstra_compute_percentile_ranks' ) ) {
    function blomstra_compute_percentile_ranks( $values_by_iso3 ) {
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
            // Use absolute epsilon; this is fine for percentage-scale indicators (0-100 range)
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

if ( ! function_exists( 'blomstra_rank_in_full_index' ) ) {
    function blomstra_rank_in_full_index( $score, $full_composites_sorted ) {
        // Enforce descending sort internally to avoid caller mistakes
        $sorted = $full_composites_sorted;
        rsort( $sorted );
        $greater = 0;
        foreach ( $sorted as $c ) {
            if ( $c > $score ) { $greater++; } else { break; }
        }
        return $greater + 1;
    }
}

if ( ! function_exists( 'blomstra_build_full_rank_display' ) ) {
    function blomstra_build_full_rank_display( $rank ) {
        return array(
            'is_definitive'    => true,
            'best_estimate'    => $rank,
            'range_80_low'     => $rank,
            'range_80_high'    => $rank,
            'theoretical_low'  => $rank,
            'theoretical_high' => $rank,
            'string_format'    => '#' . $rank,
        );
    }
}

if ( ! function_exists( 'blomstra_build_partial_rank_display' ) ) {
    function blomstra_build_partial_rank_display( $ranks_by_injection ) {
        $range_80 = array( $ranks_by_injection[90], $ranks_by_injection[10] );
        sort( $range_80 );
        $theoretical = array( $ranks_by_injection[100], $ranks_by_injection[0] );
        sort( $theoretical );

        return array(
            'is_definitive'    => false,
            'best_estimate'    => $ranks_by_injection[50],
            'range_80_low'     => $range_80[0],
            'range_80_high'    => $range_80[1],
            'theoretical_low'  => $theoretical[0],
            'theoretical_high' => $theoretical[1],
            'string_format'    => '#' . $range_80[0] . '–#' . $range_80[1] . '*',
        );
    }
}

// ─── WORLD BANK INDICATOR BATCH FETCHER (with retries & atomic cache) ───

if ( ! defined( 'BLOMSTRA_WB_INDICATOR_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_WB_INDICATOR_CACHE_TTL', WEEK_IN_SECONDS );
}

if ( ! function_exists( 'blomstra_fetch_wb_indicator_batch' ) ) {
    /**
     * Fetch a single World Bank indicator for all countries, ISO3-keyed.
     * Implements retry logic (3 attempts), atomic cache update, and year-based latest selection.
     *
     * @param string   $code   Indicator code (e.g., 'FI.RES.TOTL.MO').
     * @param int|null $source World Bank source ID (3 for WGI, null for WDI).
     * @param bool     $force  Bypass cache and force re-fetch.
     * @return array ISO3 => [ 'value' => float, 'year' => string|null, 'fetched_at' => string, 'source' => string ]
     */
    function blomstra_fetch_wb_indicator_batch( $code, $source = null, $force = false ) {
        $cache_key = 'blomstra_wb_indicator_' . md5( $code . '|' . (string) $source );
        $staging_key = $cache_key . '_tmp';

        if ( $force ) {
            delete_transient( $cache_key );
            delete_transient( $staging_key );
        }

        // Check live cache first
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        // Check staging cache (in case a previous refresh failed)
        $staging = get_transient( $staging_key );
        if ( false !== $staging && is_array( $staging ) ) {
            // Move staging to live
            set_transient( $cache_key, $staging, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            delete_transient( $staging_key );
            return $staging;
        }

        // Build URL
        $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
        // For WGI (source=3), we need to use source parameter; for WDI we use mrnev=1.
        // Note: mrnev=1 is not supported with source parameter, so we fallback to full range and pick latest year.
        if ( $source ) {
            $url .= "&source={$source}";
        } else {
            $url .= '&mrnev=1';
        }

        // Retry logic (3 attempts)
        $attempt = 1;
        $max_attempts = 3;
        $backoff = 2;
        $data = array();

        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'BlomstraReferenceData/2.7.0' ) );

            if ( is_wp_error( $response ) ) {
                error_log( "WB indicator fetch ({$code}) attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                // Rate limit: wait longer and retry
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "WB indicator fetch ({$code}) rate-limited after {$max_attempts} attempts" );
                return array();
            }

            if ( $http_code !== 200 ) {
                error_log( "WB indicator fetch ({$code}) attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            $body_raw = wp_remote_retrieve_body( $response );
            $body = json_decode( $body_raw, true );
            if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
                error_log( "WB indicator fetch ({$code}): missing data array" );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return array();
            }

            // Success – parse data
            $out = array();
            foreach ( $body[1] as $row ) {
                $iso3 = $row['countryiso3code'] ?? null;
                $val  = $row['value'] ?? null;
                $year = isset( $row['date'] ) ? (string) $row['date'] : null;

                // Critical fix: ensure value is numeric and not empty string
                if ( $iso3 && is_numeric( $val ) && $val !== '' ) {
                    // If we already have a value for this country, keep the one with the highest year
                    if ( isset( $out[ $iso3 ] ) && $year !== null && $out[ $iso3 ]['year'] !== null ) {
                        if ( (int) $year > (int) $out[ $iso3 ]['year'] ) {
                            $out[ $iso3 ] = array(
                                'value'      => floatval( $val ),
                                'year'       => $year,
                                'fetched_at' => current_time( 'mysql' ),
                                'source'     => $source ? 'WGI' : 'WDI',
                            );
                        }
                    } else {
                        $out[ $iso3 ] = array(
                            'value'      => floatval( $val ),
                            'year'       => $year,
                            'fetched_at' => current_time( 'mysql' ),
                            'source'     => $source ? 'WGI' : 'WDI',
                        );
                    }
                }
            }

            // If we got data, break out of retry loop
            if ( ! empty( $out ) ) {
                $data = $out;
                break;
            }

            // If we got empty data but no error, treat as success but empty
            $data = $out;
            break;
        }

        // If we have data, store in staging and then atomically copy to live
        if ( ! empty( $data ) ) {
            set_transient( $staging_key, $data, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            set_transient( $cache_key, $data, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            delete_transient( $staging_key );
        }

        return $data;
    }
}

// Cache count and flush functions remain unchanged
if ( ! function_exists( 'blomstra_count_wb_indicator_cache' ) ) {
    function blomstra_count_wb_indicator_cache() {
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_wb_indicator_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        return (int) $count;
    }
}

if ( ! function_exists( 'blomstra_flush_wb_indicator_cache' ) ) {
    function blomstra_flush_wb_indicator_cache() {
        global $wpdb;
        return $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_wb_indicator_%'
             OR option_name LIKE '_transient_timeout_blomstra_wb_indicator_%'"
        );
    }
}

// ─── WB REFRESH FUNCTION (now uses GNI as primary) ────────────────

if ( ! function_exists( 'blomstra_refresh_wb_indicators' ) ) {
    function blomstra_refresh_wb_indicators( $force = false ) {
        /**
         * IMPORTANT: This list is used for the "Refresh All" convenience function.
         * It mirrors GERI's indicator list. For v3.0, GNI is primary, GDP fallback is used inside GERI.
         * This cache-warmer pre-fetches all indicators that GERI uses.
         * Keep this list in sync with geri-backend.php's GERI_INDICATORS.
         */
        $indicators = array(
            // Governance (WGI, source=3)
            'GOV_WGI_RL.SC' => array( 'source' => 3 ),
            'GOV_WGI_CC.SC' => array( 'source' => 3 ),
            'GOV_WGI_PV.SC' => array( 'source' => 3 ),
            // Macro: GNI growth (primary)
            'NY.GNP.MKTP.KD.ZG' => array( 'source' => null ),
            // Inflation
            'FP.CPI.TOTL.ZG'    => array( 'source' => null ),
            // External Vulnerability
            'FI.RES.TOTL.MO'    => array( 'source' => null ),
            'DT.DOD.DECT.GN.ZS' => array( 'source' => null ),
            'BN.CAB.XOKA.GD.ZS' => array( 'source' => null ),
            // Fiscal Stress
            'GC.DOD.TOTL.GD.ZS' => array( 'source' => null ),
            'GC.NLD.TOTL.GD.ZS' => array( 'source' => null ),
            // Add GDP growth as fallback (will be used by GERI's own logic)
            'NY.GDP.MKTP.KD.ZG' => array( 'source' => null ),
        );

        $results = array();
        foreach ( $indicators as $code => $config ) {
            $data = blomstra_fetch_wb_indicator_batch( $code, $config['source'], $force );
            $row_count = count( $data );
            $status = $row_count > 0 ? 'success' : 'error';
            blomstra_log_wb_indicator_fetch( $code, $row_count, $status, $row_count > 0 ? 'OK' : 'No data returned' );
            $results[ $code ] = array(
                'success' => $row_count > 0,
                'count'   => $row_count,
            );
            // Rate limit: sleep 1 second between calls
            sleep(1);
        }
        return $results;
    }
}

// ─── CRON: Weekly refresh of WB indicators ────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_wb_indicators' ) ) {
    function blomstra_cron_handle_wb_indicators() {
        blomstra_update_cron_status( 'wb_indicators', 'running', 'WB indicators weekly refresh running...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }
            $result = blomstra_refresh_wb_indicators( true );
            $success_count = 0;
            $total_count = count( $result );
            foreach ( $result as $code => $status ) {
                if ( $status['success'] ) $success_count++;
            }
            $msg = 'WB indicators refreshed: ' . $success_count . ' of ' . $total_count . ' indicators fetched.';
            if ( $success_count === $total_count ) {
                blomstra_update_cron_status( 'wb_indicators', 'success', $msg, $success_count );
            } elseif ( $success_count > 0 ) {
                blomstra_update_cron_status( 'wb_indicators', 'partial', $msg, $success_count );
            } else {
                blomstra_update_cron_status( 'wb_indicators', 'error', 'WB indicators fetch failed or returned empty datasets.' );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'wb_indicators', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'WB cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'wb_indicators', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'WB cron fatal: ' . $e->getMessage() );
        }
    }
    add_action( 'blomstra_cron_wb_indicators_weekly_event', 'blomstra_cron_handle_wb_indicators' );
}

// ─── CRON: Maritime (unchanged) ──────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_maritime' ) ) {
    function blomstra_cron_handle_maritime() {
        blomstra_update_cron_status( 'maritime', 'running', 'Maritime weekly refresh starting...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }
            $data = blomstra_get_maritime_raw( true );
            if ( ! empty( $data ) && is_array( $data ) ) {
                blomstra_update_cron_status( 'maritime', 'success', 'Maritime data refreshed: ' . count( $data ) . ' countries.', count( $data ) );
            } else {
                blomstra_update_cron_status( 'maritime', 'error', 'Maritime fetch returned empty dataset.' );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'maritime', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'Maritime cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'maritime', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'Maritime cron fatal: ' . $e->getMessage() );
        }
    }
    add_action( 'blomstra_cron_maritime_weekly_event', 'blomstra_cron_handle_maritime' );
}

// ─── CRON: EIA (unchanged) ──────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_eia' ) ) {
    function blomstra_cron_handle_eia() {
        blomstra_update_cron_status( 'eia', 'running', 'EIA weekly refresh starting...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 600 );
            }
            $data = blomstra_refresh_eia_raw_data( null, true );
            if ( ! empty( $data['consumption'] ) ) {
                blomstra_update_cron_status( 'eia', 'success', 'EIA raw data refreshed: ' . count( $data['consumption'] ) . ' fuels.', count( $data['consumption'] ) );
            } else {
                blomstra_update_cron_status( 'eia', 'error', 'EIA fetch returned empty dataset.' );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'eia', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'EIA cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'eia', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'EIA cron fatal: ' . $e->getMessage() );
        }
        // Safeguard: If status is still "running" after execution, force it to "error"
        $current = get_option( 'blomstra_cron_status', array() );
        if ( isset( $current['eia'] ) && $current['eia']['status'] === 'running' ) {
            blomstra_update_cron_status( 'eia', 'error', 'Cron completed but status was not updated – this indicates an unexpected exit.' );
        }
    }
    add_action( 'blomstra_cron_eia_weekly_event', 'blomstra_cron_handle_eia' );
}

// ─── CRON: HHI (unchanged) ──────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_hhi' ) ) {
    function blomstra_cron_handle_hhi() {
        blomstra_update_cron_status( 'hhi', 'running', 'HHI weekly refresh starting...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 900 );
            }
            $data = blomstra_refresh_comtrade_hhi_data( null, null, true );
            if ( ! empty( $data ) ) {
                blomstra_update_cron_status( 'hhi', 'success', 'HHI data refreshed.', count( $data ) );
            } else {
                blomstra_update_cron_status( 'hhi', 'error', 'HHI fetch returned empty dataset or quota exhausted.' );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'hhi', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'HHI cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'hhi', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'HHI cron fatal: ' . $e->getMessage() );
        }
    }
    add_action( 'blomstra_cron_hhi_weekly_event', 'blomstra_cron_handle_hhi' );
}

// ─── SCHEDULE ALL CRONS ─────────────────────────────────────────────

if ( ! function_exists( 'blomstra_schedule_reference_crons' ) ) {
    function blomstra_schedule_reference_crons() {
        if ( ! wp_next_scheduled( 'blomstra_cron_maritime_weekly_event' ) ) {
            $mon = strtotime( 'next Monday 02:00:00 UTC' );
            wp_schedule_event( $mon, 'weekly', 'blomstra_cron_maritime_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_eia_weekly_event' ) ) {
            $tue = strtotime( 'next Tuesday 02:00:00 UTC' );
            wp_schedule_event( $tue, 'weekly', 'blomstra_cron_eia_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_hhi_weekly_event' ) ) {
            $wed = strtotime( 'next Wednesday 02:00:00 UTC' );
            wp_schedule_event( $wed, 'weekly', 'blomstra_cron_hhi_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_wb_indicators_weekly_event' ) ) {
            $thu = strtotime( 'next Thursday 02:00:00 UTC' );
            wp_schedule_event( $thu, 'weekly', 'blomstra_cron_wb_indicators_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_imf_weekly_event' ) ) {
            $fri = strtotime( 'next Friday 02:00:00 UTC' );
            wp_schedule_event( $fri, 'weekly', 'blomstra_cron_imf_weekly_event' );
        }
    }
    add_action( 'init', 'blomstra_schedule_reference_crons' );
}

// ─── ADMIN ACTIONS & REFRESH HANDLERS ─────────────────────────────
// (No changes needed – they remain as in v2.6.4)
// The code from here downwards is identical to the previous version,
// so I'll copy it verbatim to avoid accidental omissions.

if ( ! function_exists( 'blomstra_ref_handle_early_actions' ) ) {
    function blomstra_ref_handle_early_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = sanitize_text_field( $_POST['page'] ?? $_GET['page'] ?? '' );
        if ( $page !== 'blomstra-insights-tools' ) {
            return;
        }

        // ── Direct Refreshes ──
        if ( isset( $_POST['blomstra_ref_refresh_countries'] ) && check_admin_referer( 'blomstra_ref_refresh_countries_action', 'blomstra_ref_refresh_countries_nonce' ) ) {
            blomstra_get_global_country_list( true );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'refreshed' => 'countries' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_reporters'] ) && check_admin_referer( 'blomstra_ref_refresh_reporters_action', 'blomstra_ref_refresh_reporters_nonce' ) ) {
            blomstra_get_comtrade_reporter_map( true );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'refreshed' => 'reporters' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_maritime'] ) && check_admin_referer( 'blomstra_ref_refresh_maritime_action', 'blomstra_ref_refresh_maritime_nonce' ) ) {
            blomstra_update_cron_status( 'maritime', 'running', 'Manual maritime refresh triggered...' );
            $data = blomstra_get_maritime_raw( true );
            if ( ! empty( $data ) && is_array( $data ) ) {
                blomstra_update_cron_status( 'maritime', 'success', 'Manual refresh: ' . count( $data ) . ' countries.', count( $data ) );
            } else {
                blomstra_update_cron_status( 'maritime', 'error', 'Manual refresh returned empty dataset.' );
            }
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'refreshed' => 'maritime' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Async Background Execution Triggers for Heavy Engines
        if ( isset( $_POST['blomstra_ref_refresh_hhi'] ) && check_admin_referer( 'blomstra_ref_refresh_hhi_action', 'blomstra_ref_refresh_hhi_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_hhi_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'hhi' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_eia'] ) && check_admin_referer( 'blomstra_ref_refresh_eia_action', 'blomstra_ref_refresh_eia_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_eia_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'eia' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // ── Cache Flushes ──
        if ( isset( $_POST['blomstra_ref_flush_countries'] ) && check_admin_referer( 'blomstra_ref_flush_countries_action', 'blomstra_ref_flush_countries_nonce' ) ) {
            delete_transient( 'blomstra_global_country_list' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'countries' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_reporters'] ) && check_admin_referer( 'blomstra_ref_flush_reporters_action', 'blomstra_ref_flush_reporters_nonce' ) ) {
            delete_transient( 'blomstra_comtrade_reporters' );
            delete_option( 'blomstra_comtrade_reporters_debug' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'reporters' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_maritime'] ) && check_admin_referer( 'blomstra_ref_flush_maritime_action', 'blomstra_ref_flush_maritime_nonce' ) ) {
            delete_transient( 'blomstra_maritime_raw' );
            delete_option( 'blomstra_maritime_fetch_debug' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'maritime' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_hhi'] ) && check_admin_referer( 'blomstra_ref_flush_hhi_action', 'blomstra_ref_flush_hhi_nonce' ) ) {
            delete_option( 'blomstra_comtrade_hhi_data' );
            delete_option( 'blomstra_hhi_refresh_summary' );
            delete_option( 'blomstra_comtrade_call_log' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'hhi' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_eia'] ) && check_admin_referer( 'blomstra_ref_flush_eia_action', 'blomstra_ref_flush_eia_nonce' ) ) {
            delete_option( 'blomstra_eia_raw_data' );
            delete_option( 'blomstra_eia_refresh_summary' );
            delete_option( 'blomstra_eia_call_log' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'eia' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // ── WB Indicators ──
        if ( isset( $_POST['blomstra_ref_refresh_wb_indicators'] ) && check_admin_referer( 'blomstra_ref_refresh_wb_indicators_action', 'blomstra_ref_refresh_wb_indicators_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_wb_indicators_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'wb_indicators' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_wb_indicators'] ) && check_admin_referer( 'blomstra_ref_flush_wb_indicators_action', 'blomstra_ref_flush_wb_indicators_nonce' ) ) {
            blomstra_flush_wb_indicator_cache();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'wb_indicators' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // ── IMF Indicators ──
        if ( isset( $_POST['blomstra_ref_refresh_imf'] ) && check_admin_referer( 'blomstra_ref_refresh_imf_action', 'blomstra_ref_refresh_imf_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_imf_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'imf' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_imf'] ) && check_admin_referer( 'blomstra_ref_flush_imf_action', 'blomstra_ref_flush_imf_nonce' ) ) {
            blomstra_flush_imf_cache();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'imf' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // ── Emergency Flush ALL ──
        if ( isset( $_POST['blomstra_ref_flush'] ) && check_admin_referer( 'blomstra_ref_flush_action', 'blomstra_ref_flush_nonce' ) ) {
            delete_transient( 'blomstra_global_country_list' );
            delete_transient( 'blomstra_comtrade_reporters' );
            delete_option( 'blomstra_comtrade_reporters_debug' );
            delete_transient( 'blomstra_maritime_raw' );
            delete_option( 'blomstra_maritime_fetch_debug' );
            delete_option( 'blomstra_comtrade_hhi_data' );
            delete_option( 'blomstra_eia_raw_data' );
            delete_option( 'blomstra_cron_status' );
            delete_option( 'blomstra_hhi_refresh_summary' );
            delete_option( 'blomstra_eia_refresh_summary' );
            delete_option( 'blomstra_comtrade_call_log' );
            delete_option( 'blomstra_eia_call_log' );
            delete_option( 'blomstra_wb_indicator_fetch_log' );
            delete_option( 'blomstra_imf_call_log' );
            blomstra_flush_wb_indicator_cache();
            blomstra_flush_imf_cache();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'all' ), admin_url( 'admin.php' ) ) );
            exit;
        }
    }
    add_action( 'admin_init', 'blomstra_ref_handle_early_actions' );
}

if ( ! function_exists( 'blomstra_ref_register_page' ) ) {
    function blomstra_ref_register_page() {
        add_menu_page(
            'Blomstra Insights Tools',
            'Blomstra Insights Tools',
            'manage_options',
            'blomstra-insights-tools',
            'blomstra_ref_render_page',
            'dashicons-chart-area',
            79
        );
        add_submenu_page(
            'blomstra-insights-tools',
            'Reference Data',
            'Reference Data',
            'manage_options',
            'blomstra-insights-tools',
            'blomstra_ref_render_page'
        );
    }
    add_action( 'admin_menu', 'blomstra_ref_register_page', 5 );
}

// ─── ADMIN UI RENDER ───────────────────────────────────────────────
// (This large function remains exactly as in v2.6.4; no changes needed)
// I'm including it here in full to ensure completeness.

if ( ! function_exists( 'blomstra_ref_render_page' ) ) {
    function blomstra_ref_render_page() {
        nocache_headers();

        // ── Single-Target API Sandbox Handler ────────────────────
        $sandbox_result = null;
        if ( isset( $_POST['blomstra_ref_sandbox_test'] ) && check_admin_referer( 'blomstra_ref_sandbox_action', 'blomstra_ref_sandbox_nonce' ) ) {
            $provider = sanitize_text_field( $_POST['sandbox_provider'] ?? 'comtrade' );
            $target   = strtoupper( sanitize_text_field( $_POST['sandbox_target'] ?? 'USA' ) );
            $year     = (int) ( $_POST['sandbox_year'] ?? ((int) current_time( 'Y' ) - 1) );

            $t_start = microtime( true );

            if ( $provider === 'comtrade' ) {
                $reporter_map = blomstra_get_comtrade_reporter_map();
                $code = $reporter_map[ $target ] ?? ( is_numeric( $target ) ? (int) $target : null );
                if ( ! $code ) {
                    $sandbox_result = array( 'error' => "Unknown ISO3/Reporter Code '{$target}'." );
                } else {
                    $rows = blomstra_comtrade_fetch_partner_imports_batch( array( $code ), $year );
                    $computed = ( is_array( $rows ) ) ? blomstra_compute_hhi_from_batch_rows( $rows, array( $code ), $year ) : null;
                    $sandbox_result = array(
                        'provider'       => 'UN Comtrade (HHI Engine)',
                        'target_code'    => $code,
                        'target_iso3'    => $target,
                        'year'           => $year,
                        'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                        'raw_rows_count' => is_array( $rows ) ? count( $rows ) : 'QUOTA_EXHAUSTED / FAILED',
                        'computed_hhi'   => $computed[ $code ] ?? 'No valid HHI output',
                        'sample_raw_row' => ( is_array( $rows ) && ! empty( $rows ) ) ? array_slice( $rows, 0, 3 ) : $rows,
                    );
                }
            } elseif ( $provider === 'eia' ) {
                $batch = blomstra_eia_fetch_activity_batch( array( $target ), BLOMSTRA_EIA_ACTIVITY_CONS, '4415' );
                $sandbox_result = array(
                    'provider'       => 'EIA Energy (Petroleum Test)',
                    'target_iso3'    => $target,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'status'         => $batch['status'],
                    'error'          => $batch['error'],
                    'rows_retrieved' => count( $batch['rows'] ),
                    'sample_rows'    => array_slice( $batch['rows'], 0, 3 ),
                );
            } elseif ( $provider === 'maritime' ) {
                $url  = "https://api.worldbank.org/v2/country/{$target}/indicator/" . BLOMSTRA_MARITIME_INDICATOR_CODE . "?format=json&date={$year}";
                $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
                $code = wp_remote_retrieve_response_code( $resp );
                $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                $sandbox_result = array(
                    'provider'       => 'World Bank Maritime LSCI',
                    'target_iso3'    => $target,
                    'year'           => $year,
                    'http_code'      => $code,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'response_body'  => $body[1] ?? $body,
                );
            } elseif ( $provider === 'wb_indicator' ) {
                $code = sanitize_text_field( $_POST['sandbox_wb_code'] ?? 'NY.GNP.MKTP.KD.ZG' );
                $source = isset( $_POST['sandbox_wb_source'] ) && $_POST['sandbox_wb_source'] !== '' ? (int) $_POST['sandbox_wb_source'] : null;
                $target_iso3 = strtoupper( trim( $target ) );
                $raw = blomstra_fetch_wb_indicator_batch( $code, $source, true );
                $country_data = isset( $raw[ $target_iso3 ] ) ? $raw[ $target_iso3 ] : null;
                $sandbox_result = array(
                    'provider'       => 'World Bank Indicator (' . ( $source === 3 ? 'WGI' : 'WDI' ) . ')',
                    'indicator_code' => $code,
                    'source'         => $source,
                    'target_iso3'    => $target_iso3,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'total_countries_fetched' => count( $raw ),
                    'target_data'    => $country_data,
                    'sample_of_first_3' => array_slice( $raw, 0, 3 ),
                );
            } elseif ( $provider === 'imf' ) {
                $code = sanitize_text_field( $_POST['sandbox_imf_code'] ?? 'NGDP_RPCH' );
                $target_iso3 = strtoupper( trim( $target ) );
                $raw = blomstra_fetch_imf_indicator_batch( $code, true );
                $country_data = isset( $raw[ $target_iso3 ] ) ? $raw[ $target_iso3 ] : null;
                $sandbox_result = array(
                    'provider'       => 'IMF WEO Indicator',
                    'indicator_code' => $code,
                    'target_iso3'    => $target_iso3,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'total_countries_fetched' => count( $raw ),
                    'target_data'    => $country_data,
                    'sample_of_first_3' => array_slice( $raw, 0, 3 ),
                );
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Blomstra Reference Data Architecture v2.7.0', 'blomstra' ) . '</h1>';
        echo '<p style="color:#666;">Centralised reference data layer for CII, GERI, and dependent tools with granular control, live API sandbox, & non-blocking background tasks.</p>';

        // ─── SECTION 0: SYSTEM & API KEY HEALTH + DATA STORAGE ────
        $api_status = blomstra_check_api_keys_status();

        global $wpdb;
        $transient_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        $option_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE 'blomstra_%'
             AND option_name NOT LIKE '%_transient_%'
             AND option_name NOT LIKE '%_timeout_%'"
        );

        echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-shield"></span> System Diagnostics &amp; Data Storage</h2></div>';
        echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:30px;">';

        echo '<div style="min-width:200px;">';
        echo '<h4 style="margin:0 0 8px 0;">🔑 API Keys</h4>';
        $com_badge = $api_status['comtrade'] ? '<span style="color:#2e7d32;">DEFINED ✓</span>' : '<span style="color:#d63638;">MISSING ✗</span>';
        $eia_badge = $api_status['eia'] ? '<span style="color:#2e7d32;">DEFINED ✓</span>' : '<span style="color:#d63638;">MISSING ✗</span>';
        echo '<div><strong>UN Comtrade:</strong> ' . $com_badge . '</div>';
        echo '<div><strong>EIA:</strong> ' . $eia_badge . '</div>';
        echo '<div><strong>PHP Memory:</strong> <code>' . esc_html( ini_get( 'memory_limit' ) ) . '</code></div>';
        echo '<div><strong>Max Execution:</strong> <code>' . esc_html( ini_get( 'max_execution_time' ) ) . 's</code></div>';
        echo '</div>';

        echo '<div style="min-width:300px;">';
        echo '<h4 style="margin:0 0 8px 0;">💾 Data Storage</h4>';
        echo '<div><strong>Database Table:</strong> <code>wp_options</code></div>';
        echo '<div><strong>Transient Cache:</strong> <code>_transient_blomstra_*</code> → ' . number_format( $transient_count ) . ' rows</div>';
        echo '<div><strong>Persistent Options:</strong> <code>blomstra_*</code> → ' . number_format( $option_count ) . ' rows</div>';
        echo '<div><strong>Cache TTL:</strong> 1 week (transients auto-expire)</div>';
        echo '<div style="margin-top:6px; color:#666; font-size:12px;">Data persists across browser restarts and server reboots.</div>';
        echo '</div>';

        echo '</div></div>';

        // Notification messages
        if ( isset( $_GET['triggered'] ) ) {
    echo '<div class="notice notice-info is-dismissible"><p>⏳ Background refresh task queued for: <strong>' . esc_html( strtoupper( sanitize_text_field( $_GET['triggered'] ) ) ) . '</strong> — Please <strong>manually refresh</strong> this page after 2–3 minutes to see the updated status.</p></div>';
        }

        if ( isset( $_GET['flushed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Successfully flushed cache: <strong>' . esc_html( sanitize_text_field( $_GET['flushed'] ) ) . '</strong></p></div>';
        }
        if ( isset( $_GET['refreshed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Successfully refreshed dataset: <strong>' . esc_html( sanitize_text_field( $_GET['refreshed'] ) ) . '</strong></p></div>';
        }

        // ─── SECTION 1: CRON HEALTH ──────────────────────────────────
        $cron_statuses = get_option( 'blomstra_cron_status', array() );
        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Automated Weekly Cron Health</h2></div>';
        echo '<div class="inside">';
        echo '<table class="widefat striped" style="margin-bottom:10px;">';
        echo '<thead><tr><th>Pillar</th><th>Schedule</th><th>Next Run</th><th>Last Status</th><th>Last Run Time</th><th>Items Fetched</th><th>Message</th></tr></thead><tbody>';

        $pillars = array(
            'maritime'      => array( 'label' => 'Maritime (LSCI)', 'hook' => 'blomstra_cron_maritime_weekly_event', 'day' => 'Monday 02:00 UTC' ),
            'eia'           => array( 'label' => 'Energy (EIA Raw)', 'hook' => 'blomstra_cron_eia_weekly_event', 'day' => 'Tuesday 02:00 UTC' ),
            'hhi'           => array( 'label' => 'HHI (Comtrade)', 'hook' => 'blomstra_cron_hhi_weekly_event', 'day' => 'Wednesday 02:00 UTC' ),
            'wb_indicators' => array( 'label' => 'WB Indicators (WDI/WGI)', 'hook' => 'blomstra_cron_wb_indicators_weekly_event', 'day' => 'Thursday 02:00 UTC' ),
            'imf'           => array( 'label' => 'IMF WEO Indicators', 'hook' => 'blomstra_cron_imf_weekly_event', 'day' => 'Friday 02:00 UTC' ),
        );

        foreach ( $pillars as $key => $info ) {
            $nxt = wp_next_scheduled( $info['hook'] );
            $nxt_str = $nxt ? date_i18n( 'Y-m-d H:i:s T', $nxt ) : 'Not scheduled';
            $st = $cron_statuses[ $key ] ?? null;

            $status_badge = '<span style="color:#666;">Never Run</span>';
            if ( $st ) {
                if ( $st['status'] === 'success' ) {
                    $status_badge = '<strong style="color:#2e7d32;">SUCCESS ✓</strong>';
                } elseif ( $st['status'] === 'partial' ) {
                    $status_badge = '<strong style="color:#f0ad4e;">PARTIAL ⚠️</strong>';
                } elseif ( $st['status'] === 'error' ) {
                    $status_badge = '<strong style="color:#d63638;">ERROR ✗</strong>';
                } else {
                    $status_badge = '<strong style="color:#2271b1;">RUNNING...</strong>';
                }
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html( $info['label'] ) . '</strong></td>';
            echo '<td>' . esc_html( $info['day'] ) . '</td>';
            echo '<td>' . esc_html( $nxt_str ) . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td>' . esc_html( $st['last_run'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $st['count'] ?? 0 ) . '</td>';
            echo '<td>' . esc_html( $st['message'] ?? '—' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';

        // ─── SECTION 2: GRANULAR CACHE CONTROLS ──────────────────────
        echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #2271b1;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-dashboard"></span> Data Layers &amp; Granular Cache Control</h2></div>';
        echo '<div class="inside">';
        echo '<table class="widefat striped" style="background:#fff;">';
        echo '<thead><tr><th>Dataset / Cache</th><th>Status</th><th>Item Count</th><th>Actions</th></tr></thead><tbody>';

        $country_cached  = get_transient( 'blomstra_global_country_list' );
        $reporter_cached = get_transient( 'blomstra_comtrade_reporters' );
        $maritime_cached = get_transient( 'blomstra_maritime_raw' );
        $hhi_cached      = get_option( 'blomstra_comtrade_hhi_data', array() );
        $eia_cached      = get_option( 'blomstra_eia_raw_data', array() );
        $wb_indicator_count = blomstra_count_wb_indicator_cache();
        $imf_cached_count   = blomstra_count_imf_cache();

        // Countries
        echo '<tr><td><strong>World Bank Country List</strong></td>';
        echo '<td>' . ( $country_cached !== false ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . ( is_array($country_cached) ? count($country_cached) : 0 ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_countries_action', 'blomstra_ref_refresh_countries_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_countries" value="1" class="button button-small">🔄 Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_countries_action', 'blomstra_ref_flush_countries_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_countries" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        // Reporter Map
        echo '<tr><td><strong>Comtrade Reporter Map</strong></td>';
        echo '<td>' . ( $reporter_cached !== false ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . ( is_array($reporter_cached) ? count($reporter_cached) : 0 ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_reporters_action', 'blomstra_ref_refresh_reporters_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_reporters" value="1" class="button button-small">🔄 Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_reporters_action', 'blomstra_ref_flush_reporters_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_reporters" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        // Maritime
        echo '<tr><td><strong>Maritime LSCI (World Bank)</strong></td>';
        echo '<td>' . ( $maritime_cached !== false ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . ( is_array($maritime_cached) ? count($maritime_cached) : 0 ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_maritime_action', 'blomstra_ref_refresh_maritime_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_maritime" value="1" class="button button-small">🔄 Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_maritime_action', 'blomstra_ref_flush_maritime_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_maritime" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        // HHI
        echo '<tr><td><strong>HHI (Comtrade Engine)</strong></td>';
        echo '<td>' . ( !empty($hhi_cached) ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . count($hhi_cached) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_hhi_action', 'blomstra_ref_refresh_hhi_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_hhi" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_hhi_action', 'blomstra_ref_flush_hhi_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_hhi" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        // EIA
        echo '<tr><td><strong>EIA Raw Energy Data</strong></td>';
        echo '<td>' . ( !empty($eia_cached['consumption']) ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . count($eia_cached['consumption'] ?? array()) . ' fuels</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_eia_action', 'blomstra_ref_refresh_eia_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_eia" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_eia_action', 'blomstra_ref_flush_eia_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_eia" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        // WB Indicators
        echo '<tr><td><strong>World Bank Indicators (WDI/WGI)</strong> <span style="color:#666;font-weight:normal;">— historical data</span></td>';
        echo '<td>' . ( $wb_indicator_count > 0 ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . esc_html( $wb_indicator_count ) . ' code(s)</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_wb_indicators_action', 'blomstra_ref_refresh_wb_indicators_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_wb_indicators" value="1" class="button button-small button-primary">⚡ Refresh All</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_wb_indicators_action', 'blomstra_ref_flush_wb_indicators_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_wb_indicators" value="1" class="button button-small button-link-delete">🗑️ Flush All</button>';
        echo '</form></td></tr>';

        // IMF Indicators
        echo '<tr><td><strong>IMF WEO Indicators</strong> <span style="color:#666;font-weight:normal;">— projections & forecasts</span></td>';
        echo '<td>' . ( $imf_cached_count > 0 ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . esc_html( $imf_cached_count ) . ' code(s)</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_imf_action', 'blomstra_ref_refresh_imf_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_imf" value="1" class="button button-small button-primary">⚡ Refresh All</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_imf_action', 'blomstra_ref_flush_imf_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_imf" value="1" class="button button-small button-link-delete">🗑️ Flush All</button>';
        echo '</form></td></tr>';

        echo '</tbody></table>';

        // Master Wipe
        echo '<div style="margin-top:15px; border-top:1px solid #ccc; padding-top:10px;">';
        echo '<form method="post" onsubmit="return confirm(\'WARNING: This will purge ALL cached datasets across all pillars. Proceed?\');">';
        wp_nonce_field( 'blomstra_ref_flush_action', 'blomstra_ref_flush_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush" value="1" class="button button-secondary" style="background:#d63638; color:#fff; border-color:#d63638;">⚠️ Emergency Flush ALL Caches</button>';
        echo '</form>';
        echo '</div>';
        echo '</div></div>';

        // ─── SECTION 3: API SANDBOX ──────────────────────────────────
        echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-testimonial"></span> API Diagnostic Sandbox (Single Target Tester)</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Run an isolated call for a single target without exhausting quotas or triggering batch execution across all countries.</p>';

        echo '<form method="post" style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; margin-bottom:15px; background:#f9f9f9; padding:12px; border:1px solid #e5e5e5; border-radius:4px;">';
        wp_nonce_field( 'blomstra_ref_sandbox_action', 'blomstra_ref_sandbox_nonce' );

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">Provider Target</label>';
        echo '<select name="sandbox_provider">';
        echo '<option value="comtrade">UN Comtrade (HHI Engine)</option>';
        echo '<option value="eia">EIA Energy Data</option>';
        echo '<option value="maritime">World Bank Maritime LSCI</option>';
        echo '<option value="wb_indicator">World Bank Indicator (WDI/WGI)</option>';
        echo '<option value="imf">IMF WEO Indicator (Projections)</option>';
        echo '</select></div>';

        // WB indicator fields
        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">WB Indicator Code</label>';
        echo '<input type="text" name="sandbox_wb_code" value="NY.GNP.MKTP.KD.ZG" style="width:160px;" placeholder="e.g. NY.GNP.MKTP.KD.ZG" /></div>';
        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">WB Source</label>';
        echo '<input type="text" name="sandbox_wb_source" value="" style="width:60px;" placeholder="3 for WGI" /></div>';

        // IMF indicator field
        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">IMF Indicator Code</label>';
        echo '<input type="text" name="sandbox_imf_code" value="NGDP_RPCH" style="width:160px;" placeholder="e.g. NGDP_RPCH" /></div>';

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">ISO3 or Reporter Code</label>';
        echo '<input type="text" name="sandbox_target" value="USA" style="width:100px; text-transform:uppercase;" placeholder="e.g. USA or 842" required/></div>';

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">Target Year</label>';
        echo '<input type="number" name="sandbox_year" value="' . ( (int) current_time('Y') - 1 ) . '" style="width:90px;" required/></div>';

        echo '<div><button type="submit" name="blomstra_ref_sandbox_test" value="1" class="button button-primary">🧪 Execute Isolated API Test</button></div>';
        echo '</form>';

        // Help box
        echo '<div style="background:#f0f6fc; border-left:4px solid #2271b1; padding:10px 15px; margin:15px 0; border-radius:4px;">';
        echo '<p style="margin:0 0 5px 0;"><strong>🔍 Sandbox provider guide</strong></p>';
        echo '<ul style="margin:5px 0 0 20px; list-style:disc; color:#333;">';
        echo '<li><strong>UN Comtrade (HHI Engine):</strong> needs an <strong>ISO3</strong> (e.g. USA) or a <strong>reporter code</strong> (e.g. 842). The year is the trade year. Returns HHI.</li>';
        echo '<li><strong>EIA Energy Data:</strong> needs an <strong>ISO3</strong> (e.g. USA). Tests petroleum consumption (productId=4415) for the given year. Returns raw API rows.</li>';
        echo '<li><strong>World Bank Maritime LSCI:</strong> needs an <strong>ISO3</strong> (e.g. USA) and a year. Returns the LSCI value.</li>';
        echo '<li><strong>World Bank Indicator (WDI/WGI):</strong> needs an <strong>Indicator Code</strong> and optional <strong>Source</strong> (3 for WGI). The ISO3 filters to a single country. Year is ignored (mrnev=1).</li>';
        echo '<li><strong>IMF WEO Indicator:</strong> needs an <strong>IMF code</strong> (e.g. NGDP_RPCH). The ISO3 filters to a single country. Returns the latest available value (historical or forecast).</li>';
        echo '</ul>';
        echo '</div>';

        if ( $sandbox_result !== null ) {
            echo '<div style="background:#1e1e1e; color:#00ff00; padding:12px; border-radius:4px; overflow:auto; max-height:350px;">';
            echo '<h4 style="margin:0 0 8px 0; color:#fff;">Sandbox Diagnostic Response Output:</h4>';
            echo '<pre style="margin:0; font-family:monospace; font-size:12px;">' . esc_html( json_encode( $sandbox_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
            echo '</div>';
        }

        echo '</div></div>';

        // ─── SECTION 4: API CALL LOGS ──────────────────────────────────
        echo '<div class="postbox" style="border-left:4px solid #f56e28;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-list-view"></span> API Call Logs &amp; Historical Summaries</h2></div>';
        echo '<div class="inside">';

        // HHI Summary
        $hhi_summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        if ( ! empty( $hhi_summary ) ) {
            echo '<blockquote style="background:#f0f6fc; border-left:4px solid #2271b1; padding:8px 12px; margin-bottom:12px;">';
            echo '<strong>Last HHI Execution Summary:</strong> Started: ' . esc_html( $hhi_summary['run_started'] ?? 'N/A' ) . ' | Succeeded: <strong style="color:#2e7d32;">' . esc_html( $hhi_summary['succeeded'] ?? 0 ) . '</strong> | Skipped/Quota: <strong style="color:#d63638;">' . esc_html( $hhi_summary['skipped_quota'] ?? 0 ) . '</strong>';
            echo '</blockquote>';
        }

        // Comtrade Log
        $comtrade_log = get_option( 'blomstra_comtrade_call_log', array() );
        echo '<details style="margin-bottom:15px; background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 UN Comtrade HHI Engine Call Log (' . count( $comtrade_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $comtrade_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Target Reporters</th><th>Year</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $comtrade_log ) as $entry ) {
                $color = ($entry['outcome'] === 'success' || $entry['outcome'] === 'ok') ? '#2e7d32' : '#d63638';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td><code>' . esc_html( $entry['reporter_code'] ) . '</code></td><td>' . esc_html( $entry['year'] ) . '</td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['outcome'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No Comtrade call logs recorded yet.</p>';
        }
        echo '</div></details>';

        // EIA Log
        $eia_log = get_option( 'blomstra_eia_call_log', array() );
        echo '<details style="background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 EIA Energy Data Engine Call Log (' . count( $eia_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $eia_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Chunk Label</th><th>Activity</th><th>Product ID</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $eia_log ) as $entry ) {
                $color = ($entry['outcome'] === 'ok') ? '#2e7d32' : '#d63638';
                $act   = ($entry['activity_id'] == 1) ? 'Production' : 'Consumption';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td>' . esc_html( $entry['chunk_label'] ) . '</td><td>' . esc_html( $act ) . '</td><td><code>' . esc_html( $entry['product_id'] ) . '</code></td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['outcome'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No EIA call logs recorded yet.</p>';
        }
        echo '</div></details>';

        // WB Log
        $wb_log = get_option( 'blomstra_wb_indicator_fetch_log', array() );
        echo '<details style="background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px; margin-top:10px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 World Bank Indicator Fetch Log (' . count( $wb_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $wb_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Indicator Code</th><th>Rows</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $wb_log ) as $entry ) {
                $color = ( $entry['status'] === 'success' ) ? '#2e7d32' : '#d63638';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td><code>' . esc_html( $entry['code'] ) . '</code></td><td>' . esc_html( $entry['rows'] ) . '</td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['status'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No WB indicator fetch logs recorded yet.</p>';
        }
        echo '</div></details>';

        // IMF Log
        $imf_log = get_option( 'blomstra_imf_call_log', array() );
        echo '<details style="background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px; margin-top:10px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 IMF WEO Indicator Fetch Log (' . count( $imf_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $imf_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Indicator Code</th><th>Rows</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $imf_log ) as $entry ) {
                $color = ( $entry['status'] === 'success' ) ? '#2e7d32' : '#d63638';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td><code>' . esc_html( $entry['code'] ) . '</code></td><td>' . esc_html( $entry['rows'] ) . '</td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['status'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No IMF call logs recorded yet.</p>';
        }
        echo '</div></details>';

        echo '</div></div>';

        // ─── SECTION 5: RAW DEBUG INSPECTOR ────────────────────────────
        echo '<div class="postbox" style="background:#f4f4f4;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-code-standards"></span> Raw Debug &amp; Dump Inspector</h2></div>';
        echo '<div class="inside">';

        // Maritime
        $maritime_debug = get_option( 'blomstra_maritime_fetch_debug', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 Maritime Raw Fetch Diagnostics</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $maritime_debug, true ) ) . '</pre>';
        echo '</details>';

        // Comtrade Reporters
        $reporters_debug = get_option( 'blomstra_comtrade_reporters_debug', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 Comtrade Reporters JSON Map Diagnostics</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $reporters_debug, true ) ) . '</pre>';
        echo '</details>';

        // EIA
        $eia_summary = get_option( 'blomstra_eia_refresh_summary', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 EIA Refresh Summary</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $eia_summary, true ) ) . '</pre>';
        echo '</details>';

        // HHI
        $hhi_summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 HHI Refresh Summary</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $hhi_summary, true ) ) . '</pre>';
        echo '</details>';

        // WB Indicators – last fetch log snippet
        $wb_log_debug = get_option( 'blomstra_wb_indicator_fetch_log', array() );
        if ( ! empty( $wb_log_debug ) ) {
            $last_wb = end( $wb_log_debug );
            echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
            echo '<summary style="font-weight:bold; cursor:pointer;">🔍 World Bank Indicators – Last Fetch Summary</summary>';
            echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $last_wb, true ) ) . '</pre>';
            echo '</details>';
        }

        // IMF Indicators – last fetch log snippet
        $imf_log_debug = get_option( 'blomstra_imf_call_log', array() );
        if ( ! empty( $imf_log_debug ) ) {
            $last_imf = end( $imf_log_debug );
            echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
            echo '<summary style="font-weight:bold; cursor:pointer;">🔍 IMF Indicators – Last Fetch Summary</summary>';
            echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $last_imf, true ) ) . '</pre>';
            echo '</details>';
        }

        echo '</div></div>';

        echo '</div>'; // .wrap
    }
}

// ─── MULTI‑YEAR SNAPSHOT HISTORY ──────────────────────────────────

define( 'BLOMSTRA_HISTORY_DB_VERSION', '1.0' );

function blomstra_index_history_maybe_install() {
    if ( get_option( 'blomstra_index_history_db_version' ) === BLOMSTRA_HISTORY_DB_VERSION ) {
        return;
    }
    global $wpdb;
    $table           = $wpdb->prefix . 'blomstra_index_history';
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        index_slug VARCHAR(40) NOT NULL,
        iso3 VARCHAR(3) NOT NULL,
        snapshot_period VARCHAR(7) NOT NULL,
        composite_score DECIMAL(6,2) DEFAULT NULL,
        rank_value SMALLINT UNSIGNED DEFAULT NULL,
        coverage_type VARCHAR(10) DEFAULT NULL,
        pillars_json LONGTEXT DEFAULT NULL,
        recorded_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_slug_iso_period (index_slug, iso3, snapshot_period),
        KEY idx_slug_period (index_slug, snapshot_period)
    ) $charset_collate;";
    dbDelta( $sql );
    update_option( 'blomstra_index_history_db_version', BLOMSTRA_HISTORY_DB_VERSION );
}
add_action( 'admin_init', 'blomstra_index_history_maybe_install' );

function blomstra_index_snapshot_save( $index_slug, $countries ) {
    if ( empty( $countries ) || ! is_array( $countries ) ) {
        return 0;
    }
    global $wpdb;
    $table  = $wpdb->prefix . 'blomstra_index_history';
    $period = current_time( 'Y-m' );
    $now    = current_time( 'mysql' );
    $written = 0;
    foreach ( $countries as $iso3 => $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $composite_score = isset( $row['composite_score'] ) ? (float) $row['composite_score'] : null;
        $rank_value      = isset( $row['rank'] ) && $row['rank'] !== null ? (int) $row['rank'] : null;
        $coverage_type   = isset( $row['coverage_type'] ) ? sanitize_text_field( $row['coverage_type'] ) : null;
        $pillars = $row;
        unset( $pillars['composite_score'], $pillars['rank'], $pillars['coverage_type'] );
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table
                (index_slug, iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json, recorded_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                composite_score = VALUES(composite_score),
                rank_value      = VALUES(rank_value),
                coverage_type   = VALUES(coverage_type),
                pillars_json    = VALUES(pillars_json),
                recorded_at     = VALUES(recorded_at)",
            $index_slug,
            strtoupper( substr( $iso3, 0, 3 ) ),
            $period,
            $composite_score,
            $rank_value,
            $coverage_type,
            wp_json_encode( $pillars ),
            $now
        ) );
        if ( $result !== false ) {
            $written++;
        }
    }
    return $written;
}

function blomstra_index_snapshot_get_history( $index_slug, $iso3 = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'blomstra_index_history';
    if ( $iso3 ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json
             FROM $table WHERE index_slug = %s AND iso3 = %s ORDER BY snapshot_period ASC",
            $index_slug, strtoupper( $iso3 )
        ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json
             FROM $table WHERE index_slug = %s ORDER BY iso3 ASC, snapshot_period ASC",
            $index_slug
        ), ARRAY_A );
    }
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[ $r['iso3'] ][] = array(
            'period'          => $r['snapshot_period'],
            'composite_score' => $r['composite_score'] !== null ? (float) $r['composite_score'] : null,
            'rank'            => $r['rank_value'] !== null ? (int) $r['rank_value'] : null,
            'coverage_type'   => $r['coverage_type'],
            'pillars'         => json_decode( $r['pillars_json'], true ),
        );
    }
    return $out;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/index-history/(?P<slug>[a-z0-9_-]+)', array(
        'methods'             => 'GET',
        'callback'            => function ( $request ) {
            $slug = sanitize_key( $request['slug'] );
            $iso3 = $request->get_param( 'iso3' );
            return rest_ensure_response( blomstra_index_snapshot_get_history( $slug, $iso3 ? sanitize_text_field( $iso3 ) : null ) );
        },
        'permission_callback' => '__return_true',
    ) );
} );

add_action( 'admin_notices', function () {
    if ( empty( $_GET['page'] ) || strpos( $_GET['page'], 'blomstra' ) === false ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'blomstra_index_history';
    $rows  = $wpdb->get_results(
        "SELECT index_slug, snapshot_period, COUNT(*) as country_count, MAX(recorded_at) as last_recorded
         FROM $table GROUP BY index_slug, snapshot_period ORDER BY index_slug, snapshot_period DESC",
        ARRAY_A
    );
    if ( empty( $rows ) ) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>Snapshot history:</strong></p><ul style="margin-left:20px;list-style:disc;">';
    foreach ( $rows as $r ) {
        echo '<li>' . esc_html( $r['index_slug'] ) . ' — ' . esc_html( $r['snapshot_period'] ) . ': '
            . esc_html( $r['country_count'] ) . ' countries · last updated ' . esc_html( $r['last_recorded'] ) . '</li>';
    }
    echo '</ul></div>';
} );

/**
 * ─── REST endpoint for country names (ISO3 → name) ────────────────
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/country-names', array(
        'methods'             => 'GET',
        'callback'            => function () {
            return rest_ensure_response( blomstra_get_global_country_list() );
        },
        'permission_callback' => '__return_true',
    ) );
} );
