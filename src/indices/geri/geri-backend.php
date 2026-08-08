/**
 * Blomstra Geo-Economic Risk Index (GERI) — v3.5.5
 *
 * @package Blomstra\Insights\Indices\GERI
 * @since   3.5.5
 * @version 3.5.5
 *
 * FIXES (v3.5.5):
 * - Fixed macro indicator count: derived indicators (volatility) now included in geri_get_pillar_weights()
 * - Fixed fiscal indicator count: debt_trajectory now included in geri_get_pillar_weights()
 * - Composite builder now reads indicator lists from geri_get_pillar_weights() (single source of truth)
 * - Restored country count to expected ~177+ scores
 * - Restored gni_gdp_divergence computation (lost during refactor)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── CONSTANTS ──────────────────────────────────────────────────────

define( 'GERI_VERSION', '3.5.5' );
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

// Pillar meta storage keys (for last_fetched)
define( 'GERI_GOVERNANCE_META_KEY', 'blomstra_geri_governance_meta' );
define( 'GERI_MACRO_META_KEY', 'blomstra_geri_macro_meta' );
define( 'GERI_EXTERNAL_META_KEY', 'blomstra_geri_external_meta' );
define( 'GERI_FISCAL_META_KEY', 'blomstra_geri_fiscal_meta' );

// ─── PILLAR WEIGHT DEFINITIONS (single source of truth for builder) ──

function geri_get_pillar_weights() {
    return array(
        'governance' => array(
            'name' => 'Governance',
            'indicators' => array(
                'rule_of_law' => 33.3333,
                'control_of_corruption' => 33.3333,
                'political_stability' => 33.3333,
            ),
        ),
        'macro' => array(
            'name' => 'Macro Stability',
            'indicators' => array(
                'gni_growth' => 15,
                'inflation' => 15,
                'unemployment' => 25,
                'gdp_volatility' => 15,    // derived
                'inflation_volatility' => 15, // derived
            ),
        ),
        'external' => array(
            'name' => 'External Vulnerability',
            'indicators' => array(
                'reserve_months' => 30,
                'external_debt' => 30,
                'current_account' => 30,
                'gni_gdp_divergence' => 10, // derived
            ),
        ),
        'fiscal' => array(
            'name' => 'Fiscal Stress',
            'indicators' => array(
                'gov_debt' => 35,
                'gov_balance' => 35,
                'debt_trajectory' => 30, // derived
            ),
        ),
    );
}

// ─── INDICATOR DEFINITIONS (for fetching) ─────────────────────────

function geri_get_pillar_defs() {
    return array(
        'governance' => array(
            'name' => 'Governance',
            'indicators' => array(
                'GOV_WGI_RL.SC' => array( 'name' => 'rule_of_law', 'source' => 3 ),
                'GOV_WGI_CC.SC' => array( 'name' => 'control_of_corruption', 'source' => 3 ),
                'GOV_WGI_PV.SC' => array( 'name' => 'political_stability', 'source' => 3 ),
            ),
            'min_required' => 3,
            'min_weight' => 100,
        ),
        'macro' => array(
            'name' => 'Macro Stability',
            'indicators' => array(
                'NY.GNP.MKTP.KD.ZG' => array( 'name' => 'gni_growth', 'source' => null ),
                'FP.CPI.TOTL.ZG'    => array( 'name' => 'inflation', 'source' => null ),
                'SL.UEM.TOTL.ZS'    => array( 'name' => 'unemployment', 'source' => null ),
            ),
            'min_required' => 4,
            'min_weight' => 60,
        ),
        'external' => array(
            'name' => 'External Vulnerability',
            'indicators' => array(
                'FI.RES.TOTL.MO'    => array( 'name' => 'reserve_months', 'source' => null ),
                'DT.DOD.DECT.GN.ZS' => array( 'name' => 'external_debt', 'source' => null ),
                'BN.CAB.XOKA.GD.ZS' => array( 'name' => 'current_account', 'source' => null ),
            ),
            'min_required' => 3,
            'min_weight' => 60,
        ),
        'fiscal' => array(
            'name' => 'Fiscal Stress',
            'indicators' => array(
                'GC.DOD.TOTL.GD.ZS' => array( 'name' => 'gov_debt', 'source' => null ),
                'GC.NLD.TOTL.GD.ZS' => array( 'name' => 'gov_balance', 'source' => null ),
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

function geri_fetch_wb_indicator( $code, $source = null, $force = false, $direct_api = false, $date_params = null ) {
    if ( function_exists( 'blomstra_fetch_wb_indicator_batch' ) && ! $direct_api ) {
        if ( $date_params ) {
            return geri_direct_wb_fetch( $code, $source, $date_params );
        }
        $data = blomstra_fetch_wb_indicator_batch( $code, $source, $force );
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return geri_direct_wb_fetch( $code, $source, $date_params );
}

function geri_direct_wb_fetch( $code, $source = null, $date_params = null ) {
    $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
    if ( $source ) {
        $url .= "&source={$source}";
    }
    if ( $date_params && isset( $date_params['start_year'] ) && isset( $date_params['end_year'] ) ) {
        $url .= "&date={$date_params['start_year']}:{$date_params['end_year']}";
    } else {
        $url .= '&mrnev=1';
    }
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'GERI-Direct/' . GERI_VERSION ) );
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
            if ( $date_params && isset( $date_params['start_year'] ) ) {
                if ( ! isset( $out[ $iso3 ] ) ) {
                    $out[ $iso3 ] = array();
                }
                $out[ $iso3 ][ $year ] = floatval( $val );
            } else {
                $out[ $iso3 ] = array( 'value' => floatval( $val ), 'year' => $year, 'source' => 'Direct API (GERI fallback)' );
            }
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
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'GERI-Direct/' . GERI_VERSION ) );
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

// ─── VOLATILITY & HISTORY HELPERS ──────────────────────────────────

function geri_compute_stddev( $values ) {
    $values = array_filter( $values, 'is_numeric' );
    $n = count( $values );
    if ( $n < 4 ) return null; // Require at least 4 observations for meaningful volatility
    $mean = array_sum( $values ) / $n;
    $variance = 0.0;
    foreach ( $values as $v ) {
        $variance += pow( $v - $mean, 2 );
    }
    $variance /= $n;
    return sqrt( $variance );
}

/**
 * Fetch 5-year history for an indicator, returning an associative array ISO3 => [ year => value ].
 * This ensures year information is preserved for trajectory calculation.
 */
function geri_fetch_history_5yr( $code, $source = null, $direct_api = false ) {
    $current_year = (int) current_time( 'Y' );
    $start_year = $current_year - 5;
    $end_year = $current_year;
    $data = geri_fetch_wb_indicator( $code, $source, false, $direct_api, array( 'start_year' => $start_year, 'end_year' => $end_year ) );
    $out = array();
    foreach ( $data as $iso3 => $years ) {
        if ( is_array( $years ) ) {
            ksort( $years );
            $out[ $iso3 ] = $years; // year => value
        }
    }
    return $out;
}

// ─── PILLAR FETCH FUNCTIONS ────────────────────────────────────────

function geri_fetch_governance( $force = false, $direct_api = false ) {
    $defs = geri_get_pillar_defs()['governance'];
    $raw = array();
    foreach ( $defs['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
    }
    // Update meta last_fetched
    update_option( GERI_GOVERNANCE_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( GERI_GOVERNANCE_KEY, $raw, false );
    return $raw;
}

function geri_fetch_macro( $force = false, $direct_api = false ) {
    $defs = geri_get_pillar_defs()['macro'];
    $raw = array();

    // Fetch GNI growth, inflation, unemployment
    foreach ( $defs['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
    }

    // Also fetch GDP growth as fallback (but not as a separate indicator)
    // We'll store it in the raw data for fallback and divergence.
    $gdp_data = geri_fetch_wb_indicator( 'NY.GDP.MKTP.KD.ZG', null, $force, $direct_api );
    foreach ( $gdp_data as $iso3 => $row ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $raw[ $iso3 ]['gdp_growth'] = is_numeric( $row['value'] ) ? $row['value'] : null;
        $raw[ $iso3 ]['gdp_growth_year'] = $row['year'] ?? null;
        $raw[ $iso3 ]['gdp_growth_source'] = $row['source'] ?? 'Reference Data';
    }

    // Fetch 5-year history for GDP growth to compute volatility.
    $gdp_history = geri_fetch_history_5yr( 'NY.GDP.MKTP.KD.ZG', null, $direct_api );
    foreach ( $gdp_history as $iso3 => $values ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $vals = array_values( $values );
        if ( count( $vals ) >= 4 ) {
            $raw[ $iso3 ]['gdp_volatility'] = geri_compute_stddev( $vals );
            $raw[ $iso3 ]['gdp_volatility_window'] = '5 years';
            $raw[ $iso3 ]['gdp_volatility_observations'] = count( $vals );
            $raw[ $iso3 ]['gdp_volatility_years'] = implode( ',', array_keys( $values ) );
        } else {
            $raw[ $iso3 ]['gdp_volatility'] = null;
        }
    }

    // Fetch 5-year history for inflation.
    $inf_history = geri_fetch_history_5yr( 'FP.CPI.TOTL.ZG', null, $direct_api );
    foreach ( $inf_history as $iso3 => $values ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $vals = array_values( $values );
        if ( count( $vals ) >= 4 ) {
            $raw[ $iso3 ]['inflation_volatility'] = geri_compute_stddev( $vals );
            $raw[ $iso3 ]['inflation_volatility_window'] = '5 years';
            $raw[ $iso3 ]['inflation_volatility_observations'] = count( $vals );
            $raw[ $iso3 ]['inflation_volatility_years'] = implode( ',', array_keys( $values ) );
        } else {
            $raw[ $iso3 ]['inflation_volatility'] = null;
        }
    }

    // Update meta last_fetched
    update_option( GERI_MACRO_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( GERI_MACRO_KEY, $raw, false );
    return $raw;
}

function geri_fetch_external( $force = false, $direct_api = false ) {
    $defs = geri_get_pillar_defs()['external'];
    $raw = array();
    foreach ( $defs['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
    }
    // Update meta last_fetched
    update_option( GERI_EXTERNAL_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( GERI_EXTERNAL_KEY, $raw, false );
    return $raw;
}

function geri_fetch_fiscal( $force = false, $direct_api = false ) {
    $defs = geri_get_pillar_defs()['fiscal'];
    $raw = array();
    foreach ( $defs['indicators'] as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force, $direct_api );
        foreach ( $data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $raw[ $iso3 ][ $info['name'] ] = is_numeric( $row['value'] ) ? $row['value'] : null;
            $raw[ $iso3 ][ $info['name'] . '_year' ] = $row['year'] ?? null;
            $raw[ $iso3 ][ $info['name'] . '_source' ] = $row['source'] ?? 'Reference Data';
        }
    }

    // Fetch 5-year history for debt to compute trajectory with explicit year sorting.
    $debt_hist = geri_fetch_history_5yr( 'GC.DOD.TOTL.GD.ZS', null, $direct_api );
    foreach ( $debt_hist as $iso3 => $years ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $year_keys = array_keys( $years );
        if ( count( $year_keys ) >= 2 ) {
            $oldest_year = $year_keys[0];
            $newest_year = $year_keys[ count( $year_keys ) - 1 ];
            $raw[ $iso3 ]['debt_trajectory'] = $years[ $newest_year ] - $years[ $oldest_year ];
            $raw[ $iso3 ]['debt_trajectory_oldest_year'] = $oldest_year;
            $raw[ $iso3 ]['debt_trajectory_newest_year'] = $newest_year;
            $raw[ $iso3 ]['debt_trajectory_oldest_value'] = $years[ $oldest_year ];
            $raw[ $iso3 ]['debt_trajectory_newest_value'] = $years[ $newest_year ];
        } else {
            $raw[ $iso3 ]['debt_trajectory'] = null;
        }
    }

    // Update meta last_fetched
    update_option( GERI_FISCAL_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( GERI_FISCAL_KEY, $raw, false );
    return $raw;
}

// ─── INFLATION THRESHOLD ADJUSTMENT ───────────────────────────────

/**
 * Apply threshold adjustment to inflation percentiles.
 *
 * @param float $raw_inflation Raw inflation value
 * @param float $percentile   Raw percentile from global distribution
 * @return float Adjusted percentile
 */
function geri_adjust_inflation_percentile( $raw_inflation, $percentile ) {
    if ( $raw_inflation > 20.0 ) {
        return 95.0; // Capped at 95th percentile
    } elseif ( $raw_inflation > 10.0 && $raw_inflation <= 20.0 ) {
        // Compress 10–20% into 75–95 percentile range
        $pct = 75 + ( ( $raw_inflation - 10 ) / 10 ) * 20;
        return min( 95, $pct );
    }
    // ≤10%: keep as computed
    return $percentile;
}

// ─── COMPOSITE BUILDER ─────────────────────────────────────────────

function geri_build_composite( $force = false, $context = 'manual' ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }

    // 1. Load pillar data
    $gov_data = get_option( GERI_GOVERNANCE_KEY, array() );
    $macro_data = get_option( GERI_MACRO_KEY, array() );
    $ext_data = get_option( GERI_EXTERNAL_KEY, array() );
    $fisc_data = get_option( GERI_FISCAL_KEY, array() );

    // 2. Get country list
    $countries = function_exists( 'blomstra_get_global_country_list' )
        ? blomstra_get_global_country_list()
        : array();
    if ( empty( $countries ) ) {
        return array( 'error' => 'No country list available' );
    }
    $all_iso3 = array_keys( $countries );

    // 3. Build rows with GNI→GDP fallback
    $rows = array();
    foreach ( $all_iso3 as $iso3 ) {
        $rows[ $iso3 ] = array_merge(
            $gov_data[ $iso3 ] ?? array(),
            $macro_data[ $iso3 ] ?? array(),
            $ext_data[ $iso3 ] ?? array(),
            $fisc_data[ $iso3 ] ?? array()
        );
        // GNI→GDP fallback: if gni_growth is missing, use gdp_growth
        if ( ( ! isset( $rows[ $iso3 ]['gni_growth'] ) || ! is_numeric( $rows[ $iso3 ]['gni_growth'] ) ) ) {
            if ( isset( $rows[ $iso3 ]['gdp_growth'] ) && is_numeric( $rows[ $iso3 ]['gdp_growth'] ) ) {
                $rows[ $iso3 ]['gni_growth'] = $rows[ $iso3 ]['gdp_growth'];
                $rows[ $iso3 ]['macro_base_source'] = 'gdp_fallback';
                // Also copy year/source
                $rows[ $iso3 ]['gni_growth_year'] = $rows[ $iso3 ]['gdp_growth_year'] ?? null;
                $rows[ $iso3 ]['gni_growth_source'] = $rows[ $iso3 ]['gdp_growth_source'] ?? 'GDP Fallback';
            } else {
                $rows[ $iso3 ]['macro_base_source'] = 'gni_missing';
            }
        } else {
            $rows[ $iso3 ]['macro_base_source'] = 'gni';
        }
    }

    // ─── RESTORE GNI‑GDP DIVERGENCE COMPUTATION ─────────────────
    // Compute divergence = GDP growth - GNI growth (positive = risk)
    // Only for countries that have both and where GNI is real (not GDP fallback)
    foreach ( $rows as $iso3 => &$row ) {
        if ( isset( $row['macro_base_source'] ) && $row['macro_base_source'] === 'gdp_fallback' ) {
            continue; // Divergence meaningless if GNI is missing
        }
        $gni = isset( $row['gni_growth'] ) && is_numeric( $row['gni_growth'] ) ? (float) $row['gni_growth'] : null;
        $gdp = isset( $row['gdp_growth'] ) && is_numeric( $row['gdp_growth'] ) ? (float) $row['gdp_growth'] : null;
        if ( $gni !== null && $gdp !== null ) {
            $row['gni_gdp_divergence'] = $gdp - $gni;
        }
    }
    unset( $row );

    // 4. Get weights from single source of truth
    $weight_defs = geri_get_pillar_weights();
    $percentiles = array();

    // Governance
    $gov_indicators = array_keys( $weight_defs['governance']['indicators'] );
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
    $macro_indicators = array_keys( $weight_defs['macro']['indicators'] );
    foreach ( $macro_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                // Growth: higher = lower risk → negate
                if ( $ind === 'gni_growth' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } else {
                    $values[ $iso3 ] = $row[ $ind ];
                }
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // Apply inflation threshold adjustment
    if ( isset( $percentiles['inflation'] ) ) {
        foreach ( $percentiles['inflation'] as $iso3 => $pct ) {
            $raw_inflation = $rows[ $iso3 ]['inflation'] ?? null;
            if ( $raw_inflation !== null ) {
                $percentiles['inflation'][ $iso3 ] = geri_adjust_inflation_percentile( $raw_inflation, $pct );
            }
        }
    }

   // External
   $ext_indicators = array_keys( $weight_defs['external']['indicators'] );
   foreach ( $ext_indicators as $ind ) {
      $values = array();
      foreach ( $rows as $iso3 => $row ) {
         if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
             if ( $ind === 'reserve_months' || $ind === 'current_account' ) {
                 $values[ $iso3 ] = - $row[ $ind ]; // Higher reserves/CA = lower risk
             } elseif ( $ind === 'external_debt' ) {
                 $values[ $iso3 ] = $row[ $ind ]; // Higher debt = higher risk
             } elseif ( $ind === 'gni_gdp_divergence' ) {
                 $values[ $iso3 ] = $row[ $ind ]; // FIXED: Higher divergence = higher risk
             }
         }
      }
      $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
   }

    // Fiscal
    $fisc_indicators = array_keys( $weight_defs['fiscal']['indicators'] );
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

    // 5. Compute pillar scores with coverage rules (using weights from config)
    $pillar_scores = array();
    $excluded = array();

    foreach ( $rows as $iso3 => $row ) {
        $pillars = array();

        // Governance
        $gov_weights = $weight_defs['governance']['indicators'];
        $gov_weighted_sum = 0;
        $gov_weight_total = 0;
        $gov_count = 0;
        foreach ( $gov_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $gov_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $gov_weight_total += $w;
                $gov_count++;
            }
        }
        if ( $gov_count >= 3 ) {
            $pillars['governance'] = $gov_weighted_sum / $gov_weight_total;
        } else {
            $pillars['governance'] = null;
        }

        // Macro
        $macro_weights = $weight_defs['macro']['indicators'];
        $macro_weighted_sum = 0;
        $macro_weight_total = 0;
        $macro_count = 0;
        foreach ( $macro_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $macro_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $macro_weight_total += $w;
                $macro_count++;
            }
        }
        if ( $macro_count >= 4 && $macro_weight_total >= 60 ) {
            $pillars['macro'] = $macro_weighted_sum / $macro_weight_total;
        } else {
            $pillars['macro'] = null;
        }

        // External
        $ext_weights = $weight_defs['external']['indicators'];
        $ext_weighted_sum = 0;
        $ext_weight_total = 0;
        $ext_count = 0;
        foreach ( $ext_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $ext_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $ext_weight_total += $w;
                $ext_count++;
            }
        }
        if ( $ext_count >= 3 && $ext_weight_total >= 60 ) {
            $pillars['external'] = $ext_weighted_sum / $ext_weight_total;
        } else {
            $pillars['external'] = null;
        }

        // Fiscal
        $fisc_weights = $weight_defs['fiscal']['indicators'];
        $fisc_weighted_sum = 0;
        $fisc_weight_total = 0;
        $fisc_count = 0;
        foreach ( $fisc_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $fisc_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $fisc_weight_total += $w;
                $fisc_count++;
            }
        }
        if ( $fisc_count >= 3 && $fisc_weight_total >= 60 ) {
            $pillars['fiscal'] = $fisc_weighted_sum / $fisc_weight_total;
        } else {
            $pillars['fiscal'] = null;
        }

        $valid_pillars = array_filter( $pillars, function( $v ) { return $v !== null; } );
        $coverage = count( $valid_pillars );

        if ( $coverage < GERI_MIN_PILLARS_REQUIRED ) {
            $excluded[ $iso3 ] = 'Insufficient pillar coverage: ' . $coverage . '/4 pillars available.';
            continue;
        }

        $pillar_scores[ $iso3 ] = $pillars;
        $pillar_scores[ $iso3 ]['_coverage'] = $coverage;
    }

    // 6. Compute composite: full vs partial (using injection for partial)
    $country_output = array();
    $structural_scores = array();

    // Pre‑compute global distributions for each pillar to use for injection
    $global_pillar_values = array();
    $all_pillars = array( 'governance', 'macro', 'external', 'fiscal' );
    foreach ( $all_pillars as $p ) {
        $values = array();
        foreach ( $pillar_scores as $iso3 => $pillars ) {
            if ( isset( $pillars[ $p ] ) && is_numeric( $pillars[ $p ] ) ) {
                $values[] = $pillars[ $p ];
            }
        }
        sort( $values );
        $global_pillar_values[ $p ] = $values;
    }

    foreach ( $pillar_scores as $iso3 => $pillars ) {
        // Determine available pillars (numeric values)
        $available_pillars = array_filter( $pillars, function( $v ) { return is_numeric( $v ); } );
        unset( $available_pillars['_coverage'] );
        $coverage = count( $available_pillars );
        $coverage_type = ( $coverage == 4 ) ? 'full' : 'partial';

        $composite = null;

        if ( $coverage == 4 ) {
            $composite = ( $available_pillars['governance'] + $available_pillars['macro'] + $available_pillars['external'] + $available_pillars['fiscal'] ) / 4;
        } elseif ( $coverage == 3 ) {
            $missing_pillar = null;
            foreach ( $all_pillars as $p ) {
                if ( ! isset( $available_pillars[ $p ] ) ) {
                    $missing_pillar = $p;
                    break;
                }
            }
            if ( $missing_pillar ) {
                $global_vals = $global_pillar_values[ $missing_pillar ] ?? array();
                if ( ! empty( $global_vals ) ) {
                    $n = count( $global_vals );
                    $mid = floor( $n / 2 );
                    if ( $n % 2 == 0 ) {
                        $p50 = ( $global_vals[ $mid - 1 ] + $global_vals[ $mid ] ) / 2;
                    } else {
                        $p50 = $global_vals[ $mid ];
                    }
                } else {
                    $p50 = 50;
                }
                $injected_pillars = $available_pillars;
                $injected_pillars[ $missing_pillar ] = $p50;
                $composite = ( $injected_pillars['governance'] + $injected_pillars['macro'] + $injected_pillars['external'] + $injected_pillars['fiscal'] ) / 4;
            }
        }

        if ( $composite === null && $coverage >= 3 ) {
            $composite = array_sum( $available_pillars ) / $coverage;
        }

        if ( $composite === null ) {
            $excluded[ $iso3 ] = 'Could not compute composite';
            continue;
        }

        $structural_scores[ $iso3 ] = $composite;

        // Build data_freshness
        $freshness = array(
            'governance' => array(
                'year' => $rows[ $iso3 ]['rule_of_law_year'] ?? null,
                'source' => $rows[ $iso3 ]['rule_of_law_source'] ?? null,
            ),
            'macro' => array(
                'gni_year' => $rows[ $iso3 ]['gni_growth_year'] ?? null,
                'gni_source' => $rows[ $iso3 ]['gni_growth_source'] ?? null,
                'inflation_year' => $rows[ $iso3 ]['inflation_year'] ?? null,
                'inflation_source' => $rows[ $iso3 ]['inflation_source'] ?? null,
                'unemployment_year' => $rows[ $iso3 ]['unemployment_year'] ?? null,
                'unemployment_source' => $rows[ $iso3 ]['unemployment_source'] ?? null,
                'macro_base_source' => $rows[ $iso3 ]['macro_base_source'] ?? null,
                'gdp_volatility_window' => $rows[ $iso3 ]['gdp_volatility_window'] ?? null,
                'gdp_volatility_observations' => $rows[ $iso3 ]['gdp_volatility_observations'] ?? null,
                'gdp_volatility_years' => $rows[ $iso3 ]['gdp_volatility_years'] ?? null,
                'inflation_volatility_window' => $rows[ $iso3 ]['inflation_volatility_window'] ?? null,
                'inflation_volatility_observations' => $rows[ $iso3 ]['inflation_volatility_observations'] ?? null,
                'inflation_volatility_years' => $rows[ $iso3 ]['inflation_volatility_years'] ?? null,
            ),
            'external' => array(
                'reserves_year' => $rows[ $iso3 ]['reserve_months_year'] ?? null,
                'reserves_source' => $rows[ $iso3 ]['reserve_months_source'] ?? null,
                'debt_year' => $rows[ $iso3 ]['external_debt_year'] ?? null,
                'debt_source' => $rows[ $iso3 ]['external_debt_source'] ?? null,
                'current_account_year' => $rows[ $iso3 ]['current_account_year'] ?? null,
                'current_account_source' => $rows[ $iso3 ]['current_account_source'] ?? null,
            ),
            'fiscal' => array(
                'debt_year' => $rows[ $iso3 ]['gov_debt_year'] ?? null,
                'debt_source' => $rows[ $iso3 ]['gov_debt_source'] ?? null,
                'balance_year' => $rows[ $iso3 ]['gov_balance_year'] ?? null,
                'balance_source' => $rows[ $iso3 ]['gov_balance_source'] ?? null,
                'trajectory_oldest_year' => $rows[ $iso3 ]['debt_trajectory_oldest_year'] ?? null,
                'trajectory_newest_year' => $rows[ $iso3 ]['debt_trajectory_newest_year'] ?? null,
                'trajectory_oldest_value' => $rows[ $iso3 ]['debt_trajectory_oldest_value'] ?? null,
                'trajectory_newest_value' => $rows[ $iso3 ]['debt_trajectory_newest_value'] ?? null,
            ),
        );

        $country_output[ $iso3 ] = array(
            'iso3' => $iso3,
            'name' => $countries[ $iso3 ] ?? $iso3,
            'geri_structural' => round( $composite, 2 ),
            'coverage' => $coverage_type,
            'macro_base_source' => $rows[ $iso3 ]['macro_base_source'] ?? 'unknown',
            'data_freshness' => $freshness,
            'pillars' => array(
                'governance' => array( 'score' => isset( $pillars['governance'] ) ? round( $pillars['governance'], 2 ) : null, 'weight' => 25 ),
                'macro'      => array( 'score' => isset( $pillars['macro'] ) ? round( $pillars['macro'], 2 ) : null, 'weight' => 25 ),
                'external'   => array( 'score' => isset( $pillars['external'] ) ? round( $pillars['external'], 2 ) : null, 'weight' => 25 ),
                'fiscal'     => array( 'score' => isset( $pillars['fiscal'] ) ? round( $pillars['fiscal'], 2 ) : null, 'weight' => 25 ),
            ),
        );
    }

    // 7. Compute ranks with full and partial support
    if ( function_exists( 'blomstra_rank_in_full_index' ) && function_exists( 'blomstra_build_full_rank_display' ) && function_exists( 'blomstra_build_partial_rank_display' ) ) {
        $full_countries = array();
        $partial_countries = array();
        foreach ( $country_output as $iso3 => $out ) {
            if ( $out['coverage'] === 'full' ) {
                $full_countries[ $iso3 ] = $out['geri_structural'];
            } else {
                $partial_countries[ $iso3 ] = $out['geri_structural'];
            }
        }
        arsort( $full_countries );
        $full_composites_sorted = array_values( $full_countries );
        $full_rank_map = array();
        $i = 1;
        foreach ( $full_countries as $iso3 => $score ) {
            $full_rank_map[ $iso3 ] = $i;
            $i++;
        }

        $partial_rank_data = array();
        foreach ( $partial_countries as $iso3 => $score ) {
            $pillars = $pillar_scores[ $iso3 ];
            $available_pillars = array();
            $missing_pillar = null;
            foreach ( $all_pillars as $p ) {
                if ( isset( $pillars[ $p ] ) && is_numeric( $pillars[ $p ] ) ) {
                    $available_pillars[] = $p;
                } else {
                    $missing_pillar = $p;
                }
            }
            if ( ! $missing_pillar || count( $available_pillars ) < 3 ) {
                continue;
            }

            $global_vals = $global_pillar_values[ $missing_pillar ] ?? array();
            if ( empty( $global_vals ) ) {
                continue;
            }
            $n = count( $global_vals );

            $ranks_by_injection = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $p ) {
                $rank_idx = ( $p / 100 ) * ( $n - 1 );
                $low = floor( $rank_idx );
                $high = ceil( $rank_idx );
                if ( $low == $high ) {
                    $injected_value = $global_vals[ $low ] ?? 0;
                } else {
                    $frac = $rank_idx - $low;
                    $injected_value = $global_vals[ $low ] * ( 1 - $frac ) + $global_vals[ $high ] * $frac;
                }
                $injected_pillars = $pillars;
                $injected_pillars[ $missing_pillar ] = $injected_value;
                unset( $injected_pillars['_coverage'] );
                $injected_composite = ( $injected_pillars['governance'] + $injected_pillars['macro'] + $injected_pillars['external'] + $injected_pillars['fiscal'] ) / 4;
                $ranks_by_injection[ $p ] = blomstra_rank_in_full_index( $injected_composite, $full_composites_sorted );
            }
            $partial_rank_data[ $iso3 ] = blomstra_build_partial_rank_display( $ranks_by_injection );
        }

        foreach ( $country_output as $iso3 => &$out ) {
            if ( isset( $full_rank_map[ $iso3 ] ) ) {
                $rank = $full_rank_map[ $iso3 ];
                $out['rank_display'] = blomstra_build_full_rank_display( $rank );
                $out['rank_display']['total'] = count( $full_countries );
            } elseif ( isset( $partial_rank_data[ $iso3 ] ) ) {
                $out['rank_display'] = $partial_rank_data[ $iso3 ];
                $out['rank_display']['total'] = count( $full_countries );
            } else {
                $out['rank_display'] = null;
            }
        }
        unset( $out );
    }

    // 8. Forward Pressure
    $imf_defs = geri_get_imf_forecast_defs();
    $imf_forecast = array();
    foreach ( $imf_defs as $code => $name ) {
        $data = geri_fetch_imf_forecast( $code, 1, false, false );
        $imf_forecast[ $name ] = $data;
    }
    $imf_current = array();
    foreach ( $imf_defs as $code => $name ) {
        if ( function_exists( 'blomstra_fetch_imf_indicator_batch' ) ) {
            $data = blomstra_fetch_imf_indicator_batch( $code, false );
            $imf_current[ $name ] = $data;
        }
    }
    $delta_values = array();
    $current_map = array(
        'gdp_growth_forecast' => 'gdp_growth',
        'inflation_forecast' => 'inflation',
        'current_account_forecast' => 'current_account',
        'gov_debt_forecast' => 'gov_debt',
        'gov_balance_forecast' => 'gov_balance',
        'unemployment_forecast' => 'unemployment'
    );
    foreach ( $imf_forecast as $name => $forecast_data ) {
        $current_name = $current_map[ $name ] ?? str_replace( '_forecast', '', $name );
        foreach ( $forecast_data as $iso3 => $fval ) {
            if ( isset( $imf_current[ $name ][ $iso3 ] ) && is_numeric( $imf_current[ $name ][ $iso3 ]['value'] ) ) {
                $current_val = $imf_current[ $name ][ $iso3 ]['value'];
                $delta = $fval['value'] - $current_val;
                if ( in_array( $current_name, array( 'gdp_growth', 'current_account', 'gov_balance' ) ) ) {
                    $delta = -$delta;
                }
                $delta_values[ $name ][ $iso3 ] = $delta;
            }
        }
    }
    $delta_percentiles = array();
    foreach ( $delta_values as $name => $deltas ) {
        $delta_percentiles[ $name ] = ! empty( $deltas ) ? blomstra_compute_percentile_ranks( $deltas ) : array();
    }
    foreach ( $country_output as $iso3 => &$out ) {
        $fwd_scores = array();
        $direction_signals = array();
        foreach ( $imf_defs as $code => $name ) {
            if ( isset( $delta_percentiles[ $name ][ $iso3 ] ) ) {
                $fwd_scores[] = $delta_percentiles[ $name ][ $iso3 ];
            }
            if ( isset( $delta_values[ $name ][ $iso3 ] ) ) {
                $direction_signals[] = $delta_values[ $name ][ $iso3 ];
            }
        }
        if ( count( $fwd_scores ) >= 4 ) {
            $fwd = array_sum( $fwd_scores ) / count( $fwd_scores );
            $out['geri_forward_pressure'] = round( $fwd, 2 );
            if ( count( $direction_signals ) >= 4 ) {
                $avg_delta = array_sum( $direction_signals ) / count( $direction_signals );
                $out['forward_delta_avg'] = round( $avg_delta, 2 );
                if ( $avg_delta > 0.5 ) {
                    $out['forward_direction'] = 'Deteriorating';
                } elseif ( $avg_delta < -0.5 ) {
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
            $out['forward_delta_avg'] = null;
        }
    }
    unset( $out );

    // 9. Output
    $weo_vintage = function_exists( 'blomstra_get_weo_vintage' ) ? blomstra_get_weo_vintage() : 'April 2026';
    $output = array(
        'version'            => GERI_VERSION,
        'last_updated'       => current_time( 'mysql', true ),
        'reference_vintage'  => date( 'Y' ),
        'weo_vintage'        => $weo_vintage,
        'min_pillars_required' => GERI_MIN_PILLARS_REQUIRED,
        'weights' => array( 'governance' => 25, 'macro' => 25, 'external' => 25, 'fiscal' => 25 ),
        'methodology_note' => 'Structural score uses observed/estimated data. Forward Pressure uses IMF WEO T+1 forecasts. Not blended.',
        'total_countries'    => count( $country_output ),
        'excluded_countries' => count( $excluded ),
        'excluded_detail'    => $excluded,
        'countries'          => $country_output,
    );

    // 10. Cron and async safeguards
    $previous = get_option( GERI_OPTION_KEY, null );
    $should_keep_old = false;

    if ( $context === 'cron' && $previous && ! empty( $previous['countries'] ) ) {
        $prev_count = count( $previous['countries'] );
        $new_count = count( $output['countries'] );
        if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
            error_log( 'GERI: Automated build failed – new country count (' . $new_count . ') is significantly lower than previous (' . $prev_count . '). Keeping old composite.' );
            set_transient( 'geri_auto_build_failed', 'yes', DAY_IN_SECONDS );
            $should_keep_old = true;
        }
    }

    if ( $should_keep_old && $previous ) {
        return $previous;
    }

    $staging_key = GERI_OPTION_KEY . '_tmp';
    update_option( $staging_key, $output, false );
    update_option( GERI_OPTION_KEY, $output, false );
    delete_option( $staging_key );

    if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
        $snap = array();
        foreach ( $country_output as $iso3 => $data ) {
            $snap[ $iso3 ] = array(
                'composite_score' => $data['geri_structural'] ?? null,
                'rank' => $data['rank_display']['best_estimate'] ?? null,
                'coverage_type' => $data['coverage'] ?? 'full',
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

// ─── ASYNC FETCH CALLBACKS ─────────────────────────────────────────

function geri_async_fetch_governance_callback() {
    geri_fetch_governance( true, false );
}
add_action( 'geri_async_fetch_governance', 'geri_async_fetch_governance_callback' );

function geri_async_fetch_macro_callback() {
    geri_fetch_macro( true, false );
}
add_action( 'geri_async_fetch_macro', 'geri_async_fetch_macro_callback' );

function geri_async_fetch_external_callback() {
    geri_fetch_external( true, false );
}
add_action( 'geri_async_fetch_external', 'geri_async_fetch_external_callback' );

function geri_async_fetch_fiscal_callback() {
    geri_fetch_fiscal( true, false );
}
add_action( 'geri_async_fetch_fiscal', 'geri_async_fetch_fiscal_callback' );

// ─── ASYNC REFRESH (for Fetch All) ─────────────────────────────────

function geri_async_refresh_callback() {
    $direct_api = get_option( 'geri_emergency_direct_api_flag', false );
    if ( $direct_api ) {
        delete_option( 'geri_emergency_direct_api_flag' );
    }

    $previous = get_option( GERI_OPTION_KEY, null );

    geri_fetch_governance( true, $direct_api );
    geri_fetch_macro( true, $direct_api );
    geri_fetch_external( true, $direct_api );
    geri_fetch_fiscal( true, $direct_api );

    $result = geri_build_composite( false, 'async' );

    if ( isset( $result['error'] ) && $previous ) {
        error_log( 'GERI Async: Build failed, keeping previous composite.' );
        set_transient( 'geri_auto_build_failed', 'yes', DAY_IN_SECONDS );
        if ( get_option( GERI_OPTION_KEY, null ) !== $previous ) {
            update_option( GERI_OPTION_KEY, $previous, false );
        }
    }
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
        blomstra_update_cron_status( 'geri_daily', 'running', 'Daily cron: refreshing pillar data...' );
    }
    geri_fetch_governance( true, false );
    geri_fetch_macro( true, false );
    geri_fetch_external( true, false );
    geri_fetch_fiscal( true, false );
    $result = geri_build_composite( false, 'cron' );
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        $msg = isset( $result['total_countries'] ) ? $result['total_countries'] . ' countries scored.' : 'Build completed.';
        blomstra_update_cron_status( 'geri_daily', 'success', $msg, $result['total_countries'] ?? 0 );
    }
} );

// ─── WEEKLY CRON ────────────────────────────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( GERI_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'weekly', GERI_CRON_HOOK );
    }
} );

add_action( GERI_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'geri', 'running', 'GERI weekly cron started...' );
    }
    geri_fetch_governance( true, false );
    geri_fetch_macro( true, false );
    geri_fetch_external( true, false );
    geri_fetch_fiscal( true, false );
    $result = geri_build_composite( false, 'cron' );
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
        wp_schedule_single_event( time(), 'geri_async_fetch_governance' );
        echo '<div class="notice notice-info"><p>⏳ Governance fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['geri_fetch_macro'] ) && check_admin_referer( 'geri_fetch_macro_action' ) ) {
        wp_schedule_single_event( time(), 'geri_async_fetch_macro' );
        echo '<div class="notice notice-info"><p>⏳ Macro fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['geri_fetch_external'] ) && check_admin_referer( 'geri_fetch_external_action' ) ) {
        wp_schedule_single_event( time(), 'geri_async_fetch_external' );
        echo '<div class="notice notice-info"><p>⏳ External fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['geri_fetch_fiscal'] ) && check_admin_referer( 'geri_fetch_fiscal_action' ) ) {
        wp_schedule_single_event( time(), 'geri_async_fetch_fiscal' );
        echo '<div class="notice notice-info"><p>⏳ Fiscal fetch queued as background task. Refresh the page shortly.</p></div>';
    }

    // ── API Direct (sync, for emergencies) ────────────────────────
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

    // ── Flush per pillar ──────────────────────────────────────────
    if ( isset( $_POST['geri_flush_governance'] ) && check_admin_referer( 'geri_flush_governance_action' ) ) {
        delete_option( GERI_GOVERNANCE_KEY );
        delete_option( GERI_GOVERNANCE_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Governance pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['geri_flush_macro'] ) && check_admin_referer( 'geri_flush_macro_action' ) ) {
        delete_option( GERI_MACRO_KEY );
        delete_option( GERI_MACRO_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Macro pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['geri_flush_external'] ) && check_admin_referer( 'geri_flush_external_action' ) ) {
        delete_option( GERI_EXTERNAL_KEY );
        delete_option( GERI_EXTERNAL_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ External pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['geri_flush_fiscal'] ) && check_admin_referer( 'geri_flush_fiscal_action' ) ) {
        delete_option( GERI_FISCAL_KEY );
        delete_option( GERI_FISCAL_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Fiscal pillar cache flushed.</p></div>';
    }

    // ── Build from cache ──────────────────────────────────────────
    if ( isset( $_POST['geri_build_cache'] ) && check_admin_referer( 'geri_build_cache_action' ) ) {
        $data = geri_build_composite( false, 'manual' );
        echo '<div class="notice notice-success"><p>✅ Composite built from pillar cache: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded).</p></div>';
    }

    // ── Fetch All (Async) ──────────────────────────────────────────
    if ( isset( $_POST['geri_fetch_all_async'] ) && check_admin_referer( 'geri_fetch_all_async_action' ) ) {
        wp_schedule_single_event( time(), GERI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🔄 All pillars queued for background refresh. Please wait a few minutes and refresh the page.</p></div>';
    }

    // ── Emergency API (async) ──────────────────────────────────────
    if ( isset( $_POST['geri_emergency_api_build'] ) && check_admin_referer( 'geri_emergency_api_build_action' ) ) {
        update_option( 'geri_emergency_direct_api_flag', true, false );
        wp_schedule_single_event( time(), GERI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🚨 Emergency API refresh queued as background task. Please wait a few minutes and refresh the page.</p></div>';
    }

    // ── Flush All ──────────────────────────────────────────────────
    if ( isset( $_POST['geri_flush_all_confirmed'] ) && check_admin_referer( 'geri_flush_all_action' ) ) {
        delete_option( GERI_GOVERNANCE_KEY );
        delete_option( GERI_MACRO_KEY );
        delete_option( GERI_EXTERNAL_KEY );
        delete_option( GERI_FISCAL_KEY );
        delete_option( GERI_GOVERNANCE_META_KEY );
        delete_option( GERI_MACRO_META_KEY );
        delete_option( GERI_EXTERNAL_META_KEY );
        delete_option( GERI_FISCAL_META_KEY );
        delete_option( GERI_OPTION_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ All GERI pillar caches and composite have been flushed.</p></div>';
    }

    // ── Force daily cron ──────────────────────────────────────────
    if ( isset( $_POST['geri_force_daily_cron'] ) && check_admin_referer( 'geri_force_daily_cron_action' ) ) {
        wp_schedule_single_event( time(), GERI_DAILY_CRON_HOOK );
        echo '<div class="notice notice-info"><p>🧪 Daily cron triggered (will refresh pillars and rebuild). Result will appear shortly.</p></div>';
    }

    $existing = get_option( GERI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( GERI_CRON_HOOK );
    $last_cron = get_option( 'blomstra_cron_status', array() );
    $geri_status = $last_cron['geri'] ?? null;

    // Pillar statuses (meta for freshness)
    $gov_meta = get_option( GERI_GOVERNANCE_META_KEY, array() );
    $macro_meta = get_option( GERI_MACRO_META_KEY, array() );
    $ext_meta = get_option( GERI_EXTERNAL_META_KEY, array() );
    $fisc_meta = get_option( GERI_FISCAL_META_KEY, array() );

    $gov_data = get_option( GERI_GOVERNANCE_KEY, array() );
    $macro_data = get_option( GERI_MACRO_KEY, array() );
    $ext_data = get_option( GERI_EXTERNAL_KEY, array() );
    $fisc_data = get_option( GERI_FISCAL_KEY, array() );

    $gov_count = count( $gov_data );
    $macro_count = count( $macro_data );
    $ext_count = count( $ext_data );
    $fisc_count = count( $fisc_data );

    // Pillar-specific freshness (from meta)
    $pillar_freshness = array();
    foreach ( array( 'governance' => $gov_meta, 'macro' => $macro_meta, 'external' => $ext_meta, 'fiscal' => $fisc_meta ) as $key => $meta ) {
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

    $gov_status = $gov_count > 0 ? 'Scored ✓ (' . $gov_count . ')' : 'Not Scored';
    $macro_status = $macro_count > 0 ? 'Scored ✓ (' . $macro_count . ')' : 'Not Scored';
    $ext_status = $ext_count > 0 ? 'Scored ✓ (' . $ext_count . ')' : 'Not Scored';
    $fisc_status = $fisc_count > 0 ? 'Scored ✓ (' . $fisc_count . ')' : 'Not Scored';

    $composite_status = 'Not built yet';
    if ( $existing ) {
        $composite_status = 'Composite built from pillar cache with ' . $existing['total_countries'] . ' countries.';
    }

    echo '<div class="wrap"><h1>GERI Index</h1>';

    // Dashboard cards
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin:15px 0;">';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Governance Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $gov_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Macro Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $macro_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">External Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $ext_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Fiscal Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $fisc_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #f56e28; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Composite Index</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ( $existing ? 'Scored ✓ (' . $existing['total_countries'] . ')' : 'Not Scored' ) . '</p>';
    echo '<p style="margin:4px 0 0; font-size:12px; color:#666;">' . $composite_status . '</p></div></div>';
    echo '</div>';

    // Freshness & System Status
    echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#f9f9f9;">';
    echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:30px; padding:10px 15px;">';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Governance</strong><span style="font-size:14px;">' . $pillar_freshness['governance'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Macro</strong><span style="font-size:14px;">' . $pillar_freshness['macro'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">External</strong><span style="font-size:14px;">' . $pillar_freshness['external'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Fiscal</strong><span style="font-size:14px;">' . $pillar_freshness['fiscal'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Composite Index</strong><span style="font-size:14px;">' . $composite_fresh . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Build Lock</strong><span style="font-size:14px;">🔓 Free</span></div>';
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
    echo '<div style="margin-left:auto;">';
    echo '<form method="post">';
    wp_nonce_field( 'geri_force_daily_cron_action' );
    echo '<input type="submit" name="geri_force_daily_cron" class="button button-secondary" value="🧪 Force Daily Cron Now" style="font-size:12px;">';
    echo '</form>';
    echo '</div>';
    echo '</div></div>';

    // Auto-build failure notice
    if ( get_transient( 'geri_auto_build_failed' ) ) {
        echo '<div class="notice notice-error"><p>⚠️ The automated weekly build failed to fetch complete data. Please run a manual refresh.</p></div>';
        delete_transient( 'geri_auto_build_failed' );
    }

    // Detailed controls
    echo '<div style="margin-top:20px;">';

    // Cron Status
    echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Cron &amp; Automation</h2></div>';
    echo '<div class="inside">';
    echo '<p>Automated weekly refresh: <strong>' . ( $next_cron ? 'ACTIVE — next run ' . esc_html( date_i18n( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : 'NOT SCHEDULED' ) . '</strong></p>';
    if ( $geri_status ) {
        echo '<p>Last weekly cron run: <strong>' . esc_html( $geri_status['status'] ) . '</strong> at ' . esc_html( $geri_status['last_run'] ) . ' — ' . esc_html( $geri_status['message'] ) . '</p>';
    }
    if ( isset( $last_cron['geri_daily'] ) ) {
        echo '<p>Last daily cron run: <strong>' . esc_html( $last_cron['geri_daily']['status'] ) . '</strong> at ' . esc_html( $last_cron['geri_daily']['last_run'] ) . ' — ' . esc_html( $last_cron['geri_daily']['message'] ) . '</p>';
    }
    echo '</div></div>';

    // Pillar controls
    echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database"></span> Pillar Data Layer</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;"><strong>Fetch from Central Data</strong> — uses shared Reference Data cache (async).<br>';
    echo '<strong>Fetch from API Directly</strong> — bypasses Reference Data, calls API directly (sync, fallback).</p>';
    echo '<table class="widefat striped"><thead><tr><th>Pillar</th><th>Status</th><th>Fetch from Central</th><th>Fetch API Direct</th><th>Flush</th></tr></thead><tbody>';
    foreach ( array( 'governance' => 'Governance', 'macro' => 'Macro Stability', 'external' => 'External Vulnerability', 'fiscal' => 'Fiscal Stress' ) as $key => $label ) {
        $data = get_option( constant( 'GERI_' . strtoupper( $key ) . '_KEY' ), array() );
        $count = count( $data );
        $status = $count > 0 ? '<span style="color:#2e7d32;">Cached ✓ (' . $count . ')</span>' : '<span style="color:#d63638;">Not Cached</span>';
        echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $status . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'geri_fetch_' . $key . '_action' );
        echo '<input type="submit" name="geri_fetch_' . $key . '" class="button button-small" style="min-width:140px;" value="📥 Fetch (Async)">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'geri_fetch_api_' . $key . '_action' );
        echo '<input type="submit" name="geri_fetch_api_' . $key . '" class="button button-small button-secondary" style="min-width:140px;" value="🔌 API Direct (Sync)">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'geri_flush_' . $key . '_action' );
        echo '<input type="submit" name="geri_flush_' . $key . '" class="button button-small button-link-delete" style="min-width:140px;" value="🗑️ Flush">';
        echo '</form></td></tr>';
    }
    echo '</tbody></table>';
    echo '</div></div>';

    // Composite Build – Prominent Actions
    echo '<div class="postbox" style="border-left:4px solid #f56e28; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-area"></span> Composite &amp; Build</h2></div>';
    echo '<div class="inside">';
    if ( $existing ) {
        echo '<p>Last built: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' countries scored, ' . esc_html( $existing['excluded_countries'] ?? 0 ) . ' excluded.</p>';
    } else {
        echo '<p>No composite exists yet.</p>';
    }

    echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:15px 0;">';
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'geri_build_cache_action' );
    echo '<input type="submit" name="geri_build_cache" class="button button-primary" style="min-width:180px; font-weight:bold;" value="🔨 Build Index from Cache">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'geri_fetch_all_async_action' );
    echo '<input type="submit" name="geri_fetch_all_async" class="button button-secondary" style="min-width:180px; font-weight:bold;" value="📥 Refresh All Pillars (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will fetch data directly from the API for ALL pillars, bypassing the Reference Data layer. This is a fallback. Continue?\');">';
    wp_nonce_field( 'geri_emergency_api_build_action' );
    echo '<input type="submit" name="geri_emergency_api_build" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638; font-weight:bold;" value="🚨 Emergency API → Build (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will delete ALL pillar caches and the composite. Continue?\');">';
    wp_nonce_field( 'geri_flush_all_action' );
    echo '<input type="submit" name="geri_flush_all_confirmed" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Flush ALL Caches">';
    echo '</form>';
    echo '</div>';

    echo '<p style="color:#666; font-size:12px; margin:0;"><strong>Build from Cache</strong> — uses existing pillar data (no API calls).<br>';
    echo '<strong>Refresh All Pillars (Async)</strong> — fetches fresh data from central cache in the background.<br>';
    echo '<strong>Emergency API</strong> — falls back to direct API calls (use when central cache is broken).<br>';
    echo '<strong>Flush ALL Caches</strong> — deletes all pillar and composite data (destructive).</p>';
    echo '</div></div>';

    // Preview (collapsible)
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $countries = $existing['countries'];
        uasort( $countries, function( $a, $b ) {
            return ( $a['geri_structural'] ?? 0 ) <=> ( $b['geri_structural'] ?? 0 );
        } );
        $lowest = array_slice( $countries, 0, 10, true );
        $highest = array_slice( $countries, -10, 10, true );

        echo '<div style="margin-top:20px;">';
        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📊 10 Lowest‑Risk Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $lowest as $name => $row ) {
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['geri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['geri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📈 10 Highest‑Risk Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $highest as $name => $row ) {
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['geri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['geri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        if ( ! empty( $existing['excluded_detail'] ) ) {
            echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
            echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">🚫 Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</summary>';
            echo '<div style="padding:15px; background:#fff;">';
            echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
            foreach ( $existing['excluded_detail'] as $name => $reason ) {
                echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
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
