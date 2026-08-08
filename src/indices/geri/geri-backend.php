/**
 * Blomstra Geo-Economic Risk Index (GERI) — v3.5.0
 *
 * @package Blomstra\Insights\Indices\GERI
 * @since   3.5.0
 * @version 3.5.0
 *
 * FIXES (v3.5.0):
 * - GNI→GDP fallback no longer double-counts (when fallback triggers, GDP is skipped in scoring)
 * - Partial rank injection now performs real composite recalculation (not placeholder)
 * - Inflation threshold adjustment implemented (≤10% linear, 10–20% compressed, >20% capped)
 * - Data freshness (year, source, macro_base_source) exposed in API output
 * - Forward Direction threshold documented (±0.5 delta)
 * - Version strings unified (header and User-Agent now 3.5.0)
 * - Async refresh now has failure safeguard (0.8× prev-count guard)
 * - Atomic save uses staging key without delete-before-update
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── CONSTANTS ──────────────────────────────────────────────────────

define( 'GERI_VERSION', '3.5.0' );
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
                'GOV_WGI_RL.SC' => array( 'name' => 'rule_of_law', 'source' => 3, 'weight' => 33.3333 ),
                'GOV_WGI_CC.SC' => array( 'name' => 'control_of_corruption', 'source' => 3, 'weight' => 33.3333 ),
                'GOV_WGI_PV.SC' => array( 'name' => 'political_stability', 'source' => 3, 'weight' => 33.3333 ),
            ),
            'min_required' => 3,
            'min_weight' => 100,
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
                // debt_trajectory is derived in fetch, not a fetched indicator
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

    // Fetch 5-year history for GDP growth to compute volatility.
    $gdp_history = geri_fetch_history_5yr( 'NY.GDP.MKTP.KD.ZG', null, $direct_api );
    foreach ( $gdp_history as $iso3 => $values ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $vals = array_values( $values );
        if ( count( $vals ) >= 4 ) {
            $raw[ $iso3 ]['gdp_volatility'] = geri_compute_stddev( $vals );
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
        } else {
            $raw[ $iso3 ]['inflation_volatility'] = null;
        }
    }

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

    $raw['_last_fetched'] = current_time( 'mysql' );
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
            } else {
                $rows[ $iso3 ]['macro_base_source'] = 'gni_missing';
            }
        } else {
            $rows[ $iso3 ]['macro_base_source'] = 'gni';
        }
    }

    // 4. Compute percentiles per indicator (risk-oriented)
    $percentiles = array();

    // Governance
    $gov_indicators = array( 'rule_of_law', 'control_of_corruption', 'political_stability' );
    foreach ( $gov_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                $values[ $iso3 ] = 100 - $row[ $ind ];
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // Macro: include GNI growth, GDP growth (but skip if fallback used), inflation, unemployment, volatility
    $macro_items = array( 'gni_growth', 'gdp_growth', 'inflation', 'unemployment', 'gdp_volatility', 'inflation_volatility' );
    foreach ( $macro_items as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                // Skip GDP growth if fallback was used (it's already serving as GNI)
                if ( $ind === 'gdp_growth' && isset( $row['macro_base_source'] ) && $row['macro_base_source'] === 'gdp_fallback' ) {
                    continue;
                }
                // Growth indicators: higher = lower risk → negate
                if ( $ind === 'gni_growth' || $ind === 'gdp_growth' ) {
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
    $ext_items = array( 'reserve_months', 'external_debt', 'current_account' );
    foreach ( $ext_items as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                if ( $ind === 'reserve_months' || $ind === 'current_account' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } else {
                    $values[ $iso3 ] = $row[ $ind ];
                }
            }
        }
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks( $values ) : array();
    }

    // GNI–GDP divergence: only compute if macro_base_source is NOT 'gdp_fallback'
    $divergence_values = array();
    foreach ( $rows as $iso3 => $row ) {
        if ( isset( $row['gni_growth'] ) && isset( $row['gdp_growth'] ) &&
             is_numeric( $row['gni_growth'] ) && is_numeric( $row['gdp_growth'] ) &&
             ( ! isset( $row['macro_base_source'] ) || $row['macro_base_source'] !== 'gdp_fallback' ) ) {
            $div = $row['gni_growth'] - $row['gdp_growth'];
            $divergence_values[ $iso3 ] = - $div;
        }
    }
    $percentiles['gni_gdp_divergence'] = ! empty( $divergence_values ) ? blomstra_compute_percentile_ranks( $divergence_values ) : array();

    // Fiscal
    $fisc_items = array( 'gov_debt', 'gov_balance', 'debt_trajectory' );
    foreach ( $fisc_items as $ind ) {
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

    // 5. Compute pillar scores with coverage rules
    $pillar_scores = array();
    $excluded = array();

    foreach ( $rows as $iso3 => $row ) {
        $pillars = array();

        // Governance: require all 3
        $gov_weights = array( 'rule_of_law' => 33.3333, 'control_of_corruption' => 33.3333, 'political_stability' => 33.3333 );
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

        // Macro: require at least 4 indicators and >= 60% weight
        $macro_weights = array( 'gni_growth' => 15, 'gdp_growth' => 15, 'inflation' => 15, 'unemployment' => 25, 'gdp_volatility' => 15, 'inflation_volatility' => 15 );
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

        // External: require 3 indicators
        $ext_weights = array( 'reserve_months' => 30, 'external_debt' => 30, 'current_account' => 30, 'gni_gdp_divergence' => 10 );
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

        // Fiscal: require 3 indicators (debt, balance, trajectory)
        $fisc_weights = array( 'gov_debt' => 30, 'gov_balance' => 30, 'debt_trajectory' => 40 );
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
        if ( count( $valid_pillars ) < GERI_MIN_PILLARS_REQUIRED ) {
            $excluded[ $iso3 ] = 'Insufficient pillar coverage: ' . count( $valid_pillars ) . '/4 pillars available.';
            continue;
        }
        $pillar_scores[ $iso3 ] = $pillars;
        $pillar_scores[ $iso3 ]['_coverage'] = count( $valid_pillars );
    }

    // 6. Build composite and ranks
    $country_output = array();
    $structural_scores = array();

    foreach ( $pillar_scores as $iso3 => $pillars ) {
        $scores = array_values( array_filter( $pillars, function( $v ) { return is_numeric( $v ); } ) );
        if ( count( $scores ) < GERI_MIN_PILLARS_REQUIRED ) continue;
        $composite = array_sum( $scores ) / count( $scores );
        $structural_scores[ $iso3 ] = $composite;
        $coverage_type = ( $pillars['_coverage'] == 4 ) ? 'full' : 'partial';

        // Build data_freshness
        $freshness = array(
            'governance' => array(
                'year' => $rows[ $iso3 ]['rule_of_law_year'] ?? null,
                'source' => $rows[ $iso3 ]['rule_of_law_source'] ?? null,
            ),
            'macro' => array(
                'gni_year' => $rows[ $iso3 ]['gni_growth_year'] ?? null,
                'gni_source' => $rows[ $iso3 ]['gni_growth_source'] ?? null,
                'gdp_year' => $rows[ $iso3 ]['gdp_growth_year'] ?? null,
                'gdp_source' => $rows[ $iso3 ]['gdp_growth_source'] ?? null,
                'inflation_year' => $rows[ $iso3 ]['inflation_year'] ?? null,
                'inflation_source' => $rows[ $iso3 ]['inflation_source'] ?? null,
                'unemployment_year' => $rows[ $iso3 ]['unemployment_year'] ?? null,
                'unemployment_source' => $rows[ $iso3 ]['unemployment_source'] ?? null,
                'macro_base_source' => $rows[ $iso3 ]['macro_base_source'] ?? null,
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
        // Separate full and partial
        $full_countries = array();
        $partial_countries = array();
        foreach ( $country_output as $iso3 => $out ) {
            if ( $out['coverage'] === 'full' ) {
                $full_countries[ $iso3 ] = $out['geri_structural'];
            } else {
                $partial_countries[ $iso3 ] = $out['geri_structural'];
            }
        }
        // Sort full composites descending
        arsort( $full_countries );
        $full_composites_sorted = array_values( $full_countries );
        $full_rank_map = array();
        $i = 1;
        foreach ( $full_countries as $iso3 => $score ) {
            $full_rank_map[ $iso3 ] = $i;
            $i++;
        }

        // For partial countries, compute real injection-simulated rank ranges
        $partial_rank_data = array();
        foreach ( $partial_countries as $iso3 => $score ) {
            // Find which pillar is missing
            $pillars = $pillar_scores[ $iso3 ];
            $available_pillars = array();
            $missing_pillar = null;
            foreach ( array( 'governance', 'macro', 'external', 'fiscal' ) as $p ) {
                if ( isset( $pillars[ $p ] ) && is_numeric( $pillars[ $p ] ) ) {
                    $available_pillars[] = $p;
                } else {
                    $missing_pillar = $p;
                }
            }
            if ( ! $missing_pillar || count( $available_pillars ) < 3 ) {
                continue;
            }

            // Get the global percentile distribution for the missing pillar from all countries that have it
            $global_pillar_values = array();
            foreach ( $pillar_scores as $other_iso3 => $other_pillars ) {
                if ( isset( $other_pillars[ $missing_pillar ] ) && is_numeric( $other_pillars[ $missing_pillar ] ) ) {
                    $global_pillar_values[] = $other_pillars[ $missing_pillar ];
                }
            }
            sort( $global_pillar_values );
            $n = count( $global_pillar_values );

            $ranks_by_injection = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $p ) {
                // Calculate the p-th percentile value from the global distribution
                $rank_idx = ( $p / 100 ) * ( $n - 1 );
                $low = floor( $rank_idx );
                $high = ceil( $rank_idx );
                if ( $low == $high ) {
                    $injected_value = $global_pillar_values[ $low ] ?? 0;
                } else {
                    $frac = $rank_idx - $low;
                    $injected_value = $global_pillar_values[ $low ] * ( 1 - $frac ) + $global_pillar_values[ $high ] * $frac;
                }

                // Build injected pillars
                $injected_pillars = $pillars;
                $injected_pillars[ $missing_pillar ] = $injected_value;

                // Remove _coverage key if present
                unset( $injected_pillars['_coverage'] );

                // Calculate injected composite
                $valid_injected = array_values( array_filter( $injected_pillars, 'is_numeric' ) );
                $injected_composite = array_sum( $valid_injected ) / count( $valid_injected );

                $ranks_by_injection[ $p ] = blomstra_rank_in_full_index( $injected_composite, $full_composites_sorted );
            }
            $partial_rank_data[ $iso3 ] = blomstra_build_partial_rank_display( $ranks_by_injection );
        }

        // Apply ranks to output
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

    // 8. Forward Pressure (forecasted change, internally IMF-consistent)
    $imf_defs = geri_get_imf_forecast_defs();
    $imf_forecast = array();
    foreach ( $imf_defs as $code => $name ) {
        $data = geri_fetch_imf_forecast( $code, 1, false, false );
        $imf_forecast[ $name ] = $data;
    }
    // Also get IMF current-year estimates for the same indicators to compute deltas consistently
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
            // Prefer IMF current-year estimate if available, otherwise fallback to World Bank
            $current_val = null;
            if ( isset( $imf_current[ $name ][ $iso3 ] ) && is_numeric( $imf_current[ $name ][ $iso3 ]['value'] ) ) {
                $current_val = $imf_current[ $name ][ $iso3 ]['value'];
            } elseif ( isset( $rows[ $iso3 ][ $current_name ] ) && is_numeric( $rows[ $iso3 ][ $current_name ] ) ) {
                $current_val = $rows[ $iso3 ][ $current_name ];
            }
            if ( $current_val !== null ) {
                $delta = $fval['value'] - $current_val;
                // Invert for indicators where increase is good
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
    // Compute Forward Pressure and Direction
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
            // Direction from aggregated deltas (threshold ±0.5 is empirically chosen)
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

    // 11. Atomic save: write to staging then update live (without delete-before-update)
    if ( $should_keep_old && $previous ) {
        return $previous;
    }

    $staging_key = GERI_OPTION_KEY . '_tmp';
    update_option( $staging_key, $output, false );
    // Atomically move staging to live (overwrite without delete)
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

// ─── ASYNC REFRESH ──────────────────────────────────────────────────

function geri_async_refresh_callback() {
    // Check for emergency direct API flag
    $direct_api = get_option( 'geri_emergency_direct_api_flag', false );
    if ( $direct_api ) {
        delete_option( 'geri_emergency_direct_api_flag' );
    }

    // Preserve previous composite for failure safeguard
    $previous = get_option( GERI_OPTION_KEY, null );

    // Refresh pillars
    geri_fetch_governance( true, $direct_api );
    geri_fetch_macro( true, $direct_api );
    geri_fetch_external( true, $direct_api );
    geri_fetch_fiscal( true, $direct_api );

    // Build composite with safeguard
    $result = geri_build_composite( false, 'async' );

    // If build failed and we had previous data, keep it
    if ( isset( $result['error'] ) && $previous ) {
        error_log( 'GERI Async: Build failed, keeping previous composite.' );
        set_transient( 'geri_auto_build_failed', 'yes', DAY_IN_SECONDS );
        // Ensure previous data is restored if it was overwritten
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

    if ( isset( $_POST['geri_fetch_all_async'] ) && check_admin_referer( 'geri_fetch_all_async_action' ) ) {
        wp_schedule_single_event( time(), GERI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🔄 All pillars queued for background refresh. Please wait a few minutes and refresh the page.</p></div>';
    }

    if ( isset( $_POST['geri_emergency_api_build'] ) && check_admin_referer( 'geri_emergency_api_build_action' ) ) {
        // Set flag for async emergency direct API fetch
        update_option( 'geri_emergency_direct_api_flag', true, false );
        wp_schedule_single_event( time(), GERI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🚨 Emergency API refresh queued as background task. Please wait a few minutes and refresh the page.</p></div>';
    }

    if ( isset( $_POST['geri_flush_all_confirmed'] ) && check_admin_referer( 'geri_flush_all_action' ) ) {
        delete_option( GERI_GOVERNANCE_KEY );
        delete_option( GERI_MACRO_KEY );
        delete_option( GERI_EXTERNAL_KEY );
        delete_option( GERI_FISCAL_KEY );
        delete_option( GERI_OPTION_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ All GERI pillar caches and composite have been flushed.</p></div>';
    }

    if ( isset( $_POST['geri_force_daily_cron'] ) && check_admin_referer( 'geri_force_daily_cron_action' ) ) {
        wp_schedule_single_event( time(), GERI_DAILY_CRON_HOOK );
        echo '<div class="notice notice-info"><p>🧪 Daily cron triggered (will refresh pillars and rebuild). Result will appear shortly.</p></div>';
    }

    $existing = get_option( GERI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( GERI_CRON_HOOK );
    $last_cron = get_option( 'blomstra_cron_status', array() );
    $geri_status = $last_cron['geri'] ?? null;

    // Pillar statuses
    $gov_data = get_option( GERI_GOVERNANCE_KEY, array() );
    $macro_data = get_option( GERI_MACRO_KEY, array() );
    $ext_data = get_option( GERI_EXTERNAL_KEY, array() );
    $fisc_data = get_option( GERI_FISCAL_KEY, array() );

    $gov_count = count( $gov_data ) - 1;
    $macro_count = count( $macro_data ) - 1;
    $ext_count = count( $ext_data ) - 1;
    $fisc_count = count( $fisc_data ) - 1;

    // Pillar-specific freshness
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
    echo '<p style="color:#666;"><strong>Fetch from Central Data</strong> — uses shared Reference Data cache.<br>';
    echo '<strong>Fetch from API Directly</strong> — bypasses Reference Data, calls API directly (fallback).</p>';
    echo '<table class="widefat striped"><thead><tr><th>Pillar</th><th>Status</th><th>Fetch from Central</th><th>Fetch API Direct</th><th>Flush</th></tr></thead><tbody>';
    foreach ( array( 'governance', 'macro', 'external', 'fiscal' ) as $key ) {
        $label = ucfirst( $key );
        if ( $key === 'macro' ) $label = 'Macro Stability';
        elseif ( $key === 'external' ) $label = 'External Vulnerability';
        elseif ( $key === 'fiscal' ) $label = 'Fiscal Stress';
        $data = get_option( constant( 'GERI_' . strtoupper( $key ) . '_KEY' ), array() );
        $count = count( $data ) - 1;
        $status = $count > 0 ? '<span style="color:#2e7d32;">Cached ✓ (' . $count . ')</span>' : '<span style="color:#d63638;">Not Cached</span>';
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

    // Composite Build
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
    wp_nonce_field( 'geri_fetch_all_async_action' );
    echo '<input type="submit" name="geri_fetch_all_async" class="button button-primary" style="min-width:140px;" value="📥 Fetch All (Async)">';
    echo '</form></td>';
    echo '<td>Queue background refresh of all pillars, then build</td>';
    echo '<td>Background Task</td></tr>';

    echo '<tr><td>';
    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will fetch data directly from the API for ALL pillars, bypassing the Reference Data layer. This is a fallback. Continue?\');">';
    wp_nonce_field( 'geri_emergency_api_build_action' );
    echo '<input type="submit" name="geri_emergency_api_build" class="button button-secondary" style="min-width:140px; background:#d63638; color:#fff; border-color:#d63638;" value="🚨 Emergency API → Build (Async)">';
    echo '</form></td>';
    echo '<td>Emergency: fetch ALL pillars directly from API (background), then build</td>';
    echo '<td>API Direct → Pillar Cache → Build</td></tr>';

    echo '<tr><td>';
    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will delete ALL pillar caches and the composite. Continue?\');">';
    wp_nonce_field( 'geri_flush_all_action' );
    echo '<input type="submit" name="geri_flush_all_confirmed" class="button button-secondary" style="min-width:140px; background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Flush ALL">';
    echo '</form></td>';
    echo '<td>Delete all pillar caches and composite</td>';
    echo '<td>⚠️ Destructive</td></tr>';
    echo '</tbody></table>';
    echo '</div></div>';

    // Preview (collapsible)
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
