/**
 * Blomstra Geo-Economic Risk Index (GERI) — v3.3.2
 *
 * @package Blomstra\Insights\Indices\GERI
 * @since   3.3.2
 * @version 3.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── CONSTANTS ──────────────────────────────────────────────────────

define( 'GERI_VERSION', '3.3.2' );
define( 'GERI_OPTION_KEY', 'blomstra_geo_economic_risk_index' );
define( 'GERI_CRON_HOOK', 'blomstra_geo_economic_weekly_refresh' );
define( 'GERI_DAILY_CRON_HOOK', 'blomstra_geri_daily_cron' );
define( 'GERI_REFRESH_HOOK', 'blomstra_geri_async_refresh' );
define( 'GERI_MIN_PILLARS_REQUIRED', 3 );

// Pillar data storage keys
define( 'GERI_GOVERNANCE_KEY', 'blomstra_geri_governance_data' );
define( 'GERI_MACRO_KEY', 'blomstra_geri_macro_data' );
define( 'GERI_EXTERNAL_KEY', 'blomstra_geri_external_data' );
define( 'GERI_FISCAL_KEY', 'blomstra_geri_fiscal_data' );

// ─── INDICATOR DEFINITIONS ─────────────────────────────────────────

function geri_get_pillar_defs() {
    return array(
        'governance' => array(
            'name' => 'Governance',
            'indicators' => array(
                'GOV_WGI_RL.SC' => array( 'name' => 'rule_of_law', 'source' => 3, 'weight' => 20 ),
                'GOV_WGI_CC.SC' => array( 'name' => 'control_of_corruption', 'source' => 3, 'weight' => 20 ),
                'GOV_WGI_PV.SC' => array( 'name' => 'political_stability', 'source' => 3, 'weight' => 20 ),
                'GOV_WGI_RQ.SC' => array( 'name' => 'regulatory_quality', 'source' => 3, 'weight' => 20 ),
                'GOV_WGI_GE.SC' => array( 'name' => 'government_effectiveness', 'source' => 3, 'weight' => 20 ),
            ),
            'min_required' => 3,
        ),
        'macro' => array(
            'name' => 'Macro Stability',
            'indicators' => array(
                'NY.GNP.MKTP.KD.ZG' => array( 'name' => 'gni_growth', 'source' => null, 'weight' => 15 ),
                'NY.GDP.MKTP.KD.ZG' => array( 'name' => 'gdp_growth', 'source' => null, 'weight' => 15 ),
                'FP.CPI.TOTL.ZG'    => array( 'name' => 'inflation', 'source' => null, 'weight' => 15 ),
                'SL.UEM.TOTL.ZS'    => array( 'name' => 'unemployment', 'source' => null, 'weight' => 25 ),
            ),
            'min_required' => 4,
            'min_weight' => 60,
        ),
        'external' => array(
            'name' => 'External Vulnerability',
            'indicators' => array(
                'FI.RES.TOTL.MO'    => array( 'name' => 'reserve_months', 'source' => null, 'weight' => 30 ),
                'DT.DOD.DECT.GN.ZS' => array( 'name' => 'external_debt', 'source' => null, 'weight' => 30 ),
                'BN.CAB.XOKA.GD.ZS' => array( 'name' => 'current_account', 'source' => null, 'weight' => 30 ),
            ),
            'min_required' => 3,
            'min_weight' => 60,
        ),
        'fiscal' => array(
            'name' => 'Fiscal Stress',
            'indicators' => array(
                'GC.DOD.TOTL.GD.ZS' => array( 'name' => 'gov_debt', 'source' => null, 'weight' => 30 ),
                'GC.NLD.TOTL.GD.ZS' => array( 'name' => 'gov_balance', 'source' => null, 'weight' => 30 ),
            ),
            'min_required' => 3,
            'min_weight' => 60,
        ),
    );
}

function geri_get_imf_forecast_defs() {
    return array(
        'NGDP_RPCH'   => 'gdp_growth_forecast',
        'PCPIPCH'     => 'inflation_forecast',
        'BCA_NGDPD'   => 'current_account_forecast',
        'GGXWDG_NGDP' => 'gov_debt_forecast',
        'GGXCNL_NGDP' => 'gov_balance_forecast',
        'LUR'         => 'unemployment_forecast',
    );
}

// ─── DATA FETCH HELPERS ────────────────────────────────────────────

function geri_fetch_wb_indicator( $code, $source = null, $force = false, $direct_api = false ) {
    if ( function_exists( 'blomstra_fetch_wb_indicator_batch' ) && ! $direct_api ) {
        $data = blomstra_fetch_wb_indicator_batch( $code, $source, $force );
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return geri_direct_wb_fetch( $code, $source );
}

function geri_direct_wb_fetch( $code, $source = null ) {
    $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
    $url .= $source ? "&source={$source}" : '&mrnev=1';
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'GERI-Direct/3.3' ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array();
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
        return array();
    }
    $out = array();
    foreach ( $body[1] as $row ) {
        $iso3 = $row['countryiso3code'] ?? null;
        $val  = $row['value'] ?? null;
        $year = $row['date'] ?? null;
        if ( $iso3 && is_numeric( $val ) ) {
            $out[ $iso3 ] = array( 'value' => floatval( $val ), 'year' => $year, 'source' => 'Direct API (GERI fallback)' );
        }
    }
    return $out;
}

function geri_fetch_imf_forecast( $code, $horizon = 1, $force = false, $direct_api = false ) {
    if ( function_exists( 'blomstra_fetch_imf_forecast_batch' ) && ! $direct_api ) {
        $data = blomstra_fetch_imf_forecast_batch( $code, $horizon, $force );
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return geri_direct_imf_fetch( $code, $horizon );
}

function geri_direct_imf_fetch( $code, $horizon = 1 ) {
    $url = "https://www.imf.org/external/datamapper/api/v1/{$code}";
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'GERI-Direct/3.3' ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array();
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! isset( $body['values'][ $code ] ) ) {
        return array();
    }
    $current_year = (int) current_time( 'Y' );
    $target_year = $current_year + $horizon;
    $out = array();
    foreach ( $body['values'][ $code ] as $iso3 => $years ) {
        if ( is_array( $years ) && isset( $years[ $target_year ] ) && is_numeric( $years[ $target_year ] ) ) {
            $out[ $iso3 ] = array(
                'value' => floatval( $years[ $target_year ] ),
                'year' => (string) $target_year,
                'source' => 'Direct API (GERI fallback)',
            );
        }
    }
    return $out;
}

// ─── VOLATILITY HELPERS ────────────────────────────────────────────

function geri_compute_stddev( $values ) {
    $values = array_filter( $values, 'is_numeric' );
    $n = count( $values );
    if ( $n < 2 ) return null;
    $mean = array_sum( $values ) / $n;
    $variance = 0.0;
    foreach ( $values as $v ) {
        $variance += pow( $v - $mean, 2 );
    }
    $variance /= $n;
    return sqrt( $variance );
}

// ─── PILLAR FETCH FUNCTIONS (with last_fetched timestamp) ─────────

function geri_fetch_governance( $force = false, $direct_api = false ) {
    $def = geri_get_pillar_defs()['governance'];
    $raw = array();
    foreach ( $def['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
        if ( ! $direct_api ) sleep(1);
    }
    $raw['_last_fetched'] = current_time( 'mysql' );
    update_option( GERI_GOVERNANCE_KEY, $raw, false );
    return $raw;
}

function geri_fetch_macro( $force = false, $direct_api = false ) {
    $def = geri_get_pillar_defs()['macro'];
    $raw = array();
    foreach ( $def['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
        if ( ! $direct_api ) sleep(1);
    }
    // IMF unemployment fallback
    $imf_unemp = geri_fetch_imf_forecast( 'LUR', 1, $force, $direct_api );
    foreach ( $imf_unemp as $iso3 => $row ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        if ( ( ! isset( $raw[ $iso3 ]['unemployment'] ) || $raw[ $iso3 ]['unemployment'] === null ) ) {
            $raw[ $iso3 ]['unemployment'] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ]['unemployment_source'] = $row['source'] ?? 'IMF WEO (Direct)';
            $raw[ $iso3 ]['unemployment_year'] = $row['year'] ?? null;
        }
    }
    // Derive volatility (placeholder)
    foreach ( $raw as $iso3 => &$row ) {
        if ( isset( $row['gdp_growth'] ) && is_numeric( $row['gdp_growth'] ) ) {
            $row['gdp_volatility'] = abs( $row['gdp_growth'] ) * 0.5;
        } else {
            $row['gdp_volatility'] = null;
        }
        if ( isset( $row['inflation'] ) && is_numeric( $row['inflation'] ) ) {
            $row['inflation_volatility'] = abs( $row['inflation'] ) * 0.3;
        } else {
            $row['inflation_volatility'] = null;
        }
    }
    unset( $row );
    $raw['_last_fetched'] = current_time( 'mysql' );
    update_option( GERI_MACRO_KEY, $raw, false );
    return $raw;
}

function geri_fetch_external( $force = false, $direct_api = false ) {
    $def = geri_get_pillar_defs()['external'];
    $raw = array();
    foreach ( $def['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
        if ( ! $direct_api ) sleep(1);
    }
    // GNI–GDP divergence from macro pillar
    $macro_data = get_option( GERI_MACRO_KEY, array() );
    foreach ( $raw as $iso3 => &$row ) {
        if ( isset( $macro_data[ $iso3 ]['gni_growth'] ) && isset( $macro_data[ $iso3 ]['gdp_growth'] ) &&
             is_numeric( $macro_data[ $iso3 ]['gni_growth'] ) && is_numeric( $macro_data[ $iso3 ]['gdp_growth'] ) ) {
            $row['gni_gdp_divergence'] = $macro_data[ $iso3 ]['gni_growth'] - $macro_data[ $iso3 ]['gdp_growth'];
        } else {
            $row['gni_gdp_divergence'] = null;
        }
    }
    unset( $row );
    $raw['_last_fetched'] = current_time( 'mysql' );
    update_option( GERI_EXTERNAL_KEY, $raw, false );
    return $raw;
}

function geri_fetch_fiscal( $force = false, $direct_api = false ) {
    $def = geri_get_pillar_defs()['fiscal'];
    $raw = array();
    foreach ( $def['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
        if ( ! $direct_api ) sleep(1);
    }
    // Derive debt trajectory and interest burden
    foreach ( $raw as $iso3 => &$row ) {
        if ( isset( $row['gov_debt'] ) && is_numeric( $row['gov_debt'] ) ) {
            $row['debt_trajectory'] = $row['gov_debt'] * 0.02;
            $row['interest_burden'] = $row['gov_debt'] * 0.05;
        } else {
            $row['debt_trajectory'] = null;
            $row['interest_burden'] = null;
        }
    }
    unset( $row );
    $raw['_last_fetched'] = current_time( 'mysql' );
    update_option( GERI_FISCAL_KEY, $raw, false );
    return $raw;
}

// ─── COMPOSITE BUILDER ─────────────────────────────────────────────

function geri_build_composite( $force = false, $context = 'manual' ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }

    $gov_data = get_option( GERI_GOVERNANCE_KEY, array() );
    $macro_data = get_option( GERI_MACRO_KEY, array() );
    $ext_data = get_option( GERI_EXTERNAL_KEY, array() );
    $fisc_data = get_option( GERI_FISCAL_KEY, array() );

    $countries = function_exists( 'blomstra_get_global_country_list' )
        ? blomstra_get_global_country_list()
        : array();
    if ( empty( $countries ) ) {
        return array( 'error' => 'No country list available' );
    }
    $all_iso3 = array_keys( $countries );

    $rows = array();
    foreach ( $all_iso3 as $iso3 ) {
        $rows[ $iso3 ] = array_merge(
            $gov_data[ $iso3 ] ?? array(),
            $macro_data[ $iso3 ] ?? array(),
            $ext_data[ $iso3 ] ?? array(),
            $fisc_data[ $iso3 ] ?? array()
        );
    }

    // ─── Compute percentiles ──────────────────────────────────────
    $percentiles = array();

    // Governance
    $gov_indicators = array( 'rule_of_law', 'control_of_corruption', 'political_stability', 'regulatory_quality', 'government_effectiveness' );
    foreach ( $gov_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                $values[ $iso3 ] = 100 - $row[ $ind ];
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // Macro
    $macro_indicators = array( 'gni_growth', 'gdp_growth', 'inflation', 'unemployment', 'gdp_volatility', 'inflation_volatility' );
    foreach ( $macro_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                $values[ $iso3 ] = $row[ $ind ];
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // External
    $ext_indicators = array( 'reserve_months', 'external_debt', 'current_account', 'gni_gdp_divergence' );
    foreach ( $ext_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                if ( $ind === 'reserve_months' || $ind === 'current_account' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } elseif ( $ind === 'external_debt' ) {
                    $values[ $iso3 ] = $row[ $ind ];
                } elseif ( $ind === 'gni_gdp_divergence' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                }
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // Fiscal
    $fisc_indicators = array( 'gov_debt', 'gov_balance', 'debt_trajectory', 'interest_burden' );
    foreach ( $fisc_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                if ( $ind === 'gov_balance' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } else {
                    $values[ $iso3 ] = $row[ $ind ];
                }
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // ─── Pillar scores ────────────────────────────────────────────
    $pillar_scores = array();
    $excluded = array();

    foreach ( $rows as $iso3 => $row ) {
        $pillars = array();

        // Governance
        $scores = array();
        foreach ( $gov_indicators as $ind ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $scores[] = $percentiles[ $ind ][ $iso3 ];
            }
        }
        $pillars['governance'] = count( $scores ) >= 3 ? round( array_sum( $scores ) / count( $scores ), 2 ) : null;

        // Macro
        $macro_scores = array();
        $macro_weight = 0;
        foreach ( $macro_indicators as $ind ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $macro_scores[] = $percentiles[ $ind ][ $iso3 ];
                $macro_weight += 100 / count( $macro_indicators );
            }
        }
        $pillars['macro'] = ( count( $macro_scores ) >= 4 && $macro_weight >= 60 ) ? round( array_sum( $macro_scores ) / count( $macro_scores ), 2 ) : null;

        // External
        $ext_scores = array();
        $ext_weight = 0;
        foreach ( $ext_indicators as $ind ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $ext_scores[] = $percentiles[ $ind ][ $iso3 ];
                $ext_weight += 100 / count( $ext_indicators );
            }
        }
        $pillars['external'] = ( count( $ext_scores ) >= 3 && $ext_weight >= 60 ) ? round( array_sum( $ext_scores ) / count( $ext_scores ), 2 ) : null;

        // Fiscal
        $fisc_scores = array();
        $fisc_weight = 0;
        foreach ( $fisc_indicators as $ind ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $fisc_scores[] = $percentiles[ $ind ][ $iso3 ];
                $fisc_weight += 100 / count( $fisc_indicators );
            }
        }
        $pillars['fiscal'] = ( count( $fisc_scores ) >= 3 && $fisc_weight >= 60 ) ? round( array_sum( $fisc_scores ) / count( $fisc_scores ), 2 ) : null;

        $valid = array_filter( $pillars, function( $v ) { return $v !== null; } );
        if ( count( $valid ) < GERI_MIN_PILLARS_REQUIRED ) {
            $excluded[ $iso3 ] = 'Insufficient pillar coverage: ' . count( $valid ) . '/4 pillars available.';
            continue;
        }
        $pillar_scores[ $iso3 ] = $pillars;
    }

    // ─── Structural composite ─────────────────────────────────────
    $country_output = array();
    foreach ( $pillar_scores as $iso3 => $pillars ) {
        $scores = array_values( array_filter( $pillars, function( $v ) { return $v !== null; } ) );
        $composite = array_sum( $scores ) / count( $scores );
        $country_output[ $iso3 ] = array(
            'iso3' => $iso3,
            'name' => $countries[ $iso3 ] ?? $iso3,
            'geri_structural' => round( $composite, 2 ),
            'pillars' => array(
                'governance' => array( 'score' => $pillars['governance'] ?? null, 'weight' => 25 ),
                'macro'      => array( 'score' => $pillars['macro'] ?? null, 'weight' => 25 ),
                'external'   => array( 'score' => $pillars['external'] ?? null, 'weight' => 25 ),
                'fiscal'     => array( 'score' => $pillars['fiscal'] ?? null, 'weight' => 25 ),
            ),
        );
    }

    // ─── Forward Pressure ─────────────────────────────────────────
    $imf_defs = geri_get_imf_forecast_defs();
    $imf_data = array();
    foreach ( $imf_defs as $code => $name ) {
        $data = geri_fetch_imf_forecast( $code, 1, false, false );
        $imf_data[ $name ] = $data;
    }
    $fwd_percentiles = array();
    foreach ( $imf_data as $name => $data ) {
        $values = array();
        foreach ( $data as $iso3 => $row ) {
            if ( isset( $row['value'] ) && is_numeric( $row['value'] ) ) {
                if ( in_array( $name, array( 'gdp_growth_forecast', 'current_account_forecast', 'gov_balance_forecast' ) ) ) {
                    $values[ $iso3 ] = - $row['value'];
                } else {
                    $values[ $iso3 ] = $row['value'];
                }
            }
        }
        $fwd_percentiles[ $name ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    foreach ( $country_output as $iso3 => &$out ) {
        $fwd_scores = array();
        foreach ( $imf_defs as $code => $name ) {
            if ( isset( $fwd_percentiles[ $name ][ $iso3 ] ) ) {
                $fwd_scores[] = $fwd_percentiles[ $name ][ $iso3 ];
            }
        }
        if ( count( $fwd_scores ) >= 4 ) {
            $fwd = array_sum( $fwd_scores ) / count( $fwd_scores );
            $out['geri_forward_pressure'] = round( $fwd, 2 );
            if ( isset( $out['geri_structural'] ) ) {
                $diff = $fwd - $out['geri_structural'];
                if ( $diff > 10 ) {
                    $out['forward_direction'] = 'Deteriorating';
                } elseif ( $diff < -10 ) {
                    $out['forward_direction'] = 'Improving';
                } else {
                    $out['forward_direction'] = 'Stable';
                }
            } else {
                $out['forward_direction'] = null;
            }
        } else {
            $out['geri_forward_pressure'] = null;
            $out['forward_direction'] = null;
        }
    }
    unset( $out );

    $output = array(
        'version'            => GERI_VERSION,
        'last_updated'       => current_time( 'mysql', true ),
        'reference_vintage'  => date( 'Y' ),
        'weo_vintage'        => 'April 2026',
        'min_pillars_required' => GERI_MIN_PILLARS_REQUIRED,
        'weights' => array( 'governance' => 25, 'macro' => 25, 'external' => 25, 'fiscal' => 25 ),
        'methodology_note' => 'Structural score uses observed/estimated data. Forward Pressure uses IMF WEO T+1 forecasts. Not blended.',
        'total_countries'    => count( $country_output ),
        'excluded_countries' => count( $excluded ),
        'excluded_detail'    => $excluded,
        'countries'          => $country_output,
    );

    // ─── Cron failure safeguard ──────────────────────────────────
    $previous = get_option( GERI_OPTION_KEY, null );
    if ( $context === 'cron' && $previous && ! empty( $previous['countries'] ) ) {
        $prev_count = count( $previous['countries'] );
        $new_count = count( $output['countries'] );
        if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
            error_log( 'GERI: Automated build failed – new country count (' . $new_count . ') is significantly lower than previous (' . $prev_count . '). Keeping old composite.' );
            set_transient( 'geri_auto_build_failed', 'yes', DAY_IN_SECONDS );
            return $previous;
        }
    }

    delete_option( GERI_OPTION_KEY );
    update_option( GERI_OPTION_KEY, $output, false );

    if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
        $snap = array();
        foreach ( $country_output as $iso3 => $data ) {
            $snap[ $iso3 ] = array(
                'composite_score' => $data['geri_structural'] ?? null,
                'rank' => null,
                'coverage_type' => 'full',
                'governance' => $data['pillars']['governance']['score'] ?? null,
                'macro'      => $data['pillars']['macro']['score'] ?? null,
                'external'   => $data['pillars']['external']['score'] ?? null,
                'fiscal'     => $data['pillars']['fiscal']['score'] ?? null,
            );
        }
        blomstra_index_snapshot_save( 'geri', $snap );
    }

    return $output;
}

// ─── ASYNC REFRESH ──────────────────────────────────────────────────

function geri_async_refresh_callback() {
    delete_option( GERI_OPTION_KEY );
    geri_build_composite( true, 'manual' );
}
add_action( GERI_REFRESH_HOOK, 'geri_async_refresh_callback' );

// ─── DAILY CRON ─────────────────────────────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( GERI_DAILY_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'daily', GERI_DAILY_CRON_HOOK );
    }
} );

add_action( GERI_DAILY_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'geri_daily', 'success', 'Daily test cron executed (build from cache).' );
    }
    geri_build_composite( false, 'cron' );
} );

// ─── CRON (Weekly) ──────────────────────────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( GERI_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'weekly', GERI_CRON_HOOK );
    }
} );

add_action( GERI_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'geri', 'running', 'GERI weekly cron started...' );
    }
    $result = geri_build_composite( true, 'cron' );
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        $msg = isset( $result['total_countries'] ) ? $result['total_countries'] . ' countries scored.' : 'Build completed.';
        blomstra_update_cron_status( 'geri', 'success', $msg, $result['total_countries'] ?? 0 );
    }
} );

// ─── REST ENDPOINT ──────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/geo-economic-risk-index', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $data = get_option( GERI_OPTION_KEY, null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index has not been generated yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );

// ─── ADMIN PAGE ────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_submenu_page(
        'blomstra-insights-tools',
        'GERI Index',
        'GERI Index',
        'manage_options',
        'blomstra-geoeconomic-risk-index',
        'geri_render_admin_page'
    );
} );

function geri_render_admin_page() {
    // ── Handle actions ────────────────────────────────────────────
    if ( isset( $_POST['geri_fetch_governance'] ) && check_admin_referer( 'geri_fetch_governance_action' ) ) {
        $data = geri_fetch_governance( true, false );
        echo '<div class="notice notice-success"><p>✅ Governance: fetched from central data (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['geri_fetch_macro'] ) && check_admin_referer( 'geri_fetch_macro_action' ) ) {
        $data = geri_fetch_macro( true, false );
        echo '<div class="notice notice-success"><p>✅ Macro: fetched from central data (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['geri_fetch_external'] ) && check_admin_referer( 'geri_fetch_external_action' ) ) {
        $data = geri_fetch_external( true, false );
        echo '<div class="notice notice-success"><p>✅ External: fetched from central data (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['geri_fetch_fiscal'] ) && check_admin_referer( 'geri_fetch_fiscal_action' ) ) {
        $data = geri_fetch_fiscal( true, false );
        echo '<div class="notice notice-success"><p>✅ Fiscal: fetched from central data (' . count( $data ) . ' countries).</p></div>';
    }

    if ( isset( $_POST['geri_fetch_api_governance'] ) && check_admin_referer( 'geri_fetch_api_governance_action' ) ) {
        $data = geri_fetch_governance( true, true );
        echo '<div class="notice notice-success"><p>✅ Governance: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['geri_fetch_api_macro'] ) && check_admin_referer( 'geri_fetch_api_macro_action' ) ) {
        $data = geri_fetch_macro( true, true );
        echo '<div class="notice notice-success"><p>✅ Macro: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['geri_fetch_api_external'] ) && check_admin_referer( 'geri_fetch_api_external_action' ) ) {
        $data = geri_fetch_external( true, true );
        echo '<div class="notice notice-success"><p>✅ External: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['geri_fetch_api_fiscal'] ) && check_admin_referer( 'geri_fetch_api_fiscal_action' ) ) {
        $data = geri_fetch_fiscal( true, true );
        echo '<div class="notice notice-success"><p>✅ Fiscal: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }

    if ( isset( $_POST['geri_flush_governance'] ) && check_admin_referer( 'geri_flush_governance_action' ) ) {
        delete_option( GERI_GOVERNANCE_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Governance pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['geri_flush_macro'] ) && check_admin_referer( 'geri_flush_macro_action' ) ) {
        delete_option( GERI_MACRO_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Macro pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['geri_flush_external'] ) && check_admin_referer( 'geri_flush_external_action' ) ) {
        delete_option( GERI_EXTERNAL_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ External pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['geri_flush_fiscal'] ) && check_admin_referer( 'geri_flush_fiscal_action' ) ) {
        delete_option( GERI_FISCAL_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Fiscal pillar cache flushed.</p></div>';
    }

    if ( isset( $_POST['geri_build_cache'] ) && check_admin_referer( 'geri_build_cache_action' ) ) {
        $data = geri_build_composite( false, 'manual' );
        echo '<div class="notice notice-success"><p>✅ Composite built from pillar cache: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded).</p></div>';
    }

    if ( isset( $_POST['geri_fetch_all_build'] ) && check_admin_referer( 'geri_fetch_all_build_action' ) ) {
        geri_fetch_governance( true, false );
        geri_fetch_macro( true, false );
        geri_fetch_external( true, false );
        geri_fetch_fiscal( true, false );
        $data = geri_build_composite( false, 'manual' );
        echo '<div class="notice notice-success"><p>✅ All pillars fetched from central data, composite built: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded).</p></div>';
    }

    if ( isset( $_POST['geri_emergency_api_build'] ) && check_admin_referer( 'geri_emergency_api_build_action' ) ) {
        geri_fetch_governance( true, true );
        geri_fetch_macro( true, true );
        geri_fetch_external( true, true );
        geri_fetch_fiscal( true, true );
        $data = geri_build_composite( false, 'manual' );
        echo '<div class="notice notice-success"><p>🚨 Emergency: All pillars fetched from API directly, composite built: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded).</p></div>';
    }

    if ( isset( $_POST['geri_flush_all_confirmed'] ) && check_admin_referer( 'geri_flush_all_action' ) ) {
        delete_option( GERI_GOVERNANCE_KEY );
        delete_option( GERI_MACRO_KEY );
        delete_option( GERI_EXTERNAL_KEY );
        delete_option( GERI_FISCAL_KEY );
        delete_option( GERI_OPTION_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ All GERI pillar caches and composite have been flushed.</p></div>';
    }

    // ── Force daily cron ──────────────────────────────────────────
    if ( isset( $_POST['geri_force_daily_cron'] ) && check_admin_referer( 'geri_force_daily_cron_action' ) ) {
        wp_schedule_single_event( time(), GERI_DAILY_CRON_HOOK );
        echo '<div class="notice notice-info"><p>🧪 Daily cron triggered (build from cache). Result will appear in the Composite card below.</p></div>';
    }

    $existing = get_option( GERI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( GERI_CRON_HOOK );
    $last_cron = get_option( 'blomstra_cron_status', array() );
    $geri_status = $last_cron['geri'] ?? null;

    // ─── Pillar cache statuses ────────────────────────────────────
    $gov_data = get_option( GERI_GOVERNANCE_KEY, array() );
    $macro_data = get_option( GERI_MACRO_KEY, array() );
    $ext_data = get_option( GERI_EXTERNAL_KEY, array() );
    $fisc_data = get_option( GERI_FISCAL_KEY, array() );

    $gov_count = count( $gov_data );
    $macro_count = count( $macro_data );
    $ext_count = count( $ext_data );
    $fisc_count = count( $fisc_data );

    // ─── Pillar-specific freshness ─────────────────────────────────
    $pillar_freshness = array();

    foreach ( array( 'governance', 'macro', 'external', 'fiscal' ) as $key ) {
        $data = get_option( constant( 'GERI_' . strtoupper( $key ) . '_KEY' ), array() );
        $last_fetched = $data['_last_fetched'] ?? null;
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

    // ─── Composite freshness ──────────────────────────────────────
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

    $gov_status = $gov_count > 0 ? 'Scored ✓ (' . $gov_count . ')' : 'Not Scored';
    $macro_status = $macro_count > 0 ? 'Scored ✓ (' . $macro_count . ')' : 'Not Scored';
    $ext_status = $ext_count > 0 ? 'Scored ✓ (' . $ext_count . ')' : 'Not Scored';
    $fisc_status = $fisc_count > 0 ? 'Scored ✓ (' . $fisc_count . ')' : 'Not Scored';

    $composite_status = 'Not built yet';
    if ( $existing ) {
        $composite_status = 'Composite built from pillar cache with ' . $existing['total_countries'] . ' countries.';
    }

    echo '<div class="wrap"><h1>GERI Index</h1>';

    // ─── TOP DASHBOARD CARDS ──────────────────────────────────────
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin:15px 0;">';

    // Governance
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Governance Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $gov_status . '</p></div></div>';

    // Macro
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Macro Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $macro_status . '</p></div></div>';

    // External
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">External Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $ext_status . '</p></div></div>';

    // Fiscal
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Fiscal Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $fisc_status . '</p></div></div>';

    // Composite
    echo '<div class="postbox" style="border-left:4px solid #f56e28; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Composite Index</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ( $existing ? 'Scored ✓ (' . $existing['total_countries'] . ')' : 'Not Scored' ) . '</p>';
    echo '<p style="margin:4px 0 0; font-size:12px; color:#666;">' . $composite_status . '</p></div></div>';

    echo '</div>';

    // ─── Freshness & System Status ────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#f9f9f9;">';
    echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:30px; padding:10px 15px;">';

    // Each pillar has its own freshness now
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Governance</strong><span style="font-size:14px;">' . $pillar_freshness['governance'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Macro</strong><span style="font-size:14px;">' . $pillar_freshness['macro'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">External</strong><span style="font-size:14px;">' . $pillar_freshness['external'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Fiscal</strong><span style="font-size:14px;">' . $pillar_freshness['fiscal'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Composite Index</strong><span style="font-size:14px;">' . $composite_fresh . '</span></div>';

    // Build Lock
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Build Lock</strong><span style="font-size:14px;">🔓 Free</span></div>';

    // Last wp-cron fire
    $last_run = null;
    if ( $geri_status && isset( $geri_status['last_run'] ) ) {
        $last_run = $geri_status['last_run'];
    }
    if ( isset( $last_cron['geri_daily'] ) && isset( $last_cron['geri_daily']['last_run'] ) ) {
        if ( ! $last_run || strtotime( $last_cron['geri_daily']['last_run'] ) > strtotime( $last_run ) ) {
            $last_run = $last_cron['geri_daily']['last_run'];
        }
    }
    $last_fire_display = $last_run ? $last_run . ' ✅' : 'Never ❌';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Last Real wp-cron Fire</strong><span style="font-size:14px;">' . $last_fire_display . '</span></div>';

    // Force daily cron button
    echo '<div style="margin-left:auto;">';
    echo '<form method="post">';
    wp_nonce_field( 'geri_force_daily_cron_action' );
    echo '<input type="submit" name="geri_force_daily_cron" class="button button-secondary" value="🧪 Test Daily Cron (Build from Cache)" style="font-size:12px;">';
    echo '</form>';
    echo '</div>';

    echo '</div></div>';

    // ─── Auto‑build failure notice ──────────────────────────────────
    if ( get_transient( 'geri_auto_build_failed' ) ) {
        echo '<div class="notice notice-error"><p>⚠️ The automated weekly build failed to fetch complete data. Please run a manual refresh (e.g., "Fetch All → Build") to update the index.</p></div>';
        delete_transient( 'geri_auto_build_failed' );
    }

    // ─── DETAILED CONTROLS ──────────────────────────────────────────
    echo '<div style="margin-top:20px;">';

    // ─── Cron Status ──────────────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Cron &amp; Automation (Detailed)</h2></div>';
    echo '<div class="inside">';
    echo '<p>Automated weekly refresh: <strong>' . ( $next_cron ? 'ACTIVE — next run ' . esc_html( date_i18n( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : 'NOT SCHEDULED' ) . '</strong></p>';
    if ( $geri_status ) {
        echo '<p>Last weekly cron run: <strong>' . esc_html( $geri_status['status'] ) . '</strong> at ' . esc_html( $geri_status['last_run'] ) . ' — ' . esc_html( $geri_status['message'] ) . '</p>';
    }
    if ( isset( $last_cron['geri_daily'] ) ) {
        echo '<p>Last daily test cron run: <strong>' . esc_html( $last_cron['geri_daily']['status'] ) . '</strong> at ' . esc_html( $last_cron['geri_daily']['last_run'] ) . ' — ' . esc_html( $last_cron['geri_daily']['message'] ) . '</p>';
    }
    echo '</div></div>';

    // ─── Pillar Fetch Controls ────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database"></span> Pillar Data Layer</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;"><strong>Fetch from Central Data</strong> — uses the shared Reference Data cache (recommended).<br>';
    echo '<strong>Fetch from API Directly</strong> — bypasses Reference Data, calls the API directly (fallback/resilience).</p>';

    echo '<table class="widefat striped"><thead><tr><th>Pillar</th><th>Status</th><th>Fetch from Central Data</th><th>Fetch from API (Direct)</th><th>Flush</th></tr></thead><tbody>';

    $pillars = array(
        'governance' => 'Governance',
        'macro'      => 'Macro Stability',
        'external'   => 'External Vulnerability',
        'fiscal'     => 'Fiscal Stress',
    );

    foreach ( $pillars as $key => $label ) {
        $data = get_option( constant( 'GERI_' . strtoupper( $key ) . '_KEY' ), array() );
        $count = count( $data );
        $status = $count > 0 ? '<span style="color:#2e7d32;">Cached ✓ (' . $count . ' countries)</span>' : '<span style="color:#d63638;">Not Cached</span>';
        echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $status . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'geri_fetch_' . $key . '_action' );
        echo '<input type="submit" name="geri_fetch_' . $key . '" class="button button-small" style="min-width:140px;" value="📥 Fetch">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'geri_fetch_api_' . $key . '_action' );
        echo '<input type="submit" name="geri_fetch_api_' . $key . '" class="button button-small button-secondary" style="min-width:140px;" value="🔌 API Direct">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'geri_flush_' . $key . '_action' );
        echo '<input type="submit" name="geri_flush_' . $key . '" class="button button-small button-link-delete" style="min-width:140px;" value="🗑️ Flush">';
        echo '</form></td></tr>';
    }

    echo '</tbody></table>';
    echo '</div></div>';

    // ─── Composite Build Controls ─────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #f56e28; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-area"></span> Composite &amp; Build</h2></div>';
    echo '<div class="inside">';
    if ( $existing ) {
        echo '<p>Last built: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' countries scored, ' . esc_html( $existing['excluded_countries'] ?? 0 ) . ' excluded.</p>';
    } else {
        echo '<p>No composite exists yet.</p>';
    }

    echo '<table class="widefat striped"><thead><tr><th>Action</th><th>Description</th><th>Data Source</th></tr></thead><tbody>';
    echo '<tr><td>';
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'geri_build_cache_action' );
    echo '<input type="submit" name="geri_build_cache" class="button" style="min-width:140px;" value="🔨 Build from Cache">';
    echo '</form></td>';
    echo '<td>Calculate composite using existing pillar caches</td>';
    echo '<td>Pillar Cache</td></tr>';

    echo '<tr><td>';
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'geri_fetch_all_build_action' );
    echo '<input type="submit" name="geri_fetch_all_build" class="button button-primary" style="min-width:140px;" value="📥 Fetch All → Build">';
    echo '</form></td>';
    echo '<td>Fetch all pillars from central data, then build composite</td>';
    echo '<td>Reference Data → Pillar Cache → Build</td></tr>';

    echo '<tr><td>';
    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will fetch data directly from the API for ALL pillars, bypassing the Reference Data layer. This is a fallback for when the central cache is unavailable. Continue?\');">';
    wp_nonce_field( 'geri_emergency_api_build_action' );
    echo '<input type="submit" name="geri_emergency_api_build" class="button button-secondary" style="min-width:140px; background:#d63638; color:#fff; border-color:#d63638;" value="🚨 Emergency API → Build">';
    echo '</form></td>';
    echo '<td>Emergency: fetch ALL pillars directly from API, then build</td>';
    echo '<td>API Direct → Pillar Cache → Build</td></tr>';

    echo '<tr><td>';
    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will delete ALL pillar caches and the composite. This data cannot be recovered. Continue?\');">';
    wp_nonce_field( 'geri_flush_all_action' );
    echo '<input type="submit" name="geri_flush_all_confirmed" class="button button-secondary" style="min-width:140px; background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Flush ALL">';
    echo '</form></td>';
    echo '<td>Delete all pillar caches and composite</td>';
    echo '<td>⚠️ Destructive</td></tr>';

    echo '</tbody></table>';
    echo '</div></div>';

    // ─── Preview (Collapsible) ────────────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $countries = $existing['countries'];
        uasort( $countries, function( $a, $b ) {
            return ( $a['geri_structural'] ?? 0 ) <=> ( $b['geri_structural'] ?? 0 );
        } );
        $lowest = array_slice( $countries, 0, 10, true );
        $highest = array_slice( $countries, -10, 10, true );

        echo '<details style="margin-top:20px;">';
        echo '<summary style="cursor:pointer; font-weight:bold;">10 Lowest‑Risk Countries</summary>';
        echo '<div class="postbox" style="margin-top:10px;"><div class="inside">';
        echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $lowest as $name => $row ) {
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['geri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['geri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div></details>';

        echo '<details style="margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold;">10 Highest‑Risk Countries</summary>';
        echo '<div class="postbox" style="margin-top:10px;"><div class="inside">';
        echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $highest as $name => $row ) {
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['geri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['geri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div></details>';

        if ( ! empty( $existing['excluded_detail'] ) ) {
            echo '<details style="margin-top:10px;">';
            echo '<summary style="cursor:pointer; font-weight:bold;">Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</summary>';
            echo '<div class="postbox" style="margin-top:10px;"><div class="inside">';
            echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
            foreach ( $existing['excluded_detail'] as $name => $reason ) {
                echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
            }
            echo '</tbody></table></div></div></details>';
        }

        echo '<details style="margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold;">Raw JSON Output</summary>';
        echo '<div class="postbox" style="margin-top:10px;"><div class="inside">';
        echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) ) . '</textarea>';
        echo '</div></div></details>';
    }

    echo '</div>';
}
