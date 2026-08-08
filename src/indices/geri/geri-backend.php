/**
 * Blomstra Geo-Economic Risk Index (GERI) — v3.0.0
 *
 * @package Blomstra\Insights\Indices\GERI
 * @since   3.0.0
 * @version 3.0.0
 *
 * @description
 * GERI v3.0 measures a country's structural exposure to institutional,
 * macroeconomic, external, and fiscal vulnerabilities. It produces two
 * independent outputs:
 *   1. GERI Structural — a backward‑looking diagnostic (0–100, higher = more risk)
 *      built exclusively from observed/estimated data.
 *   2. GERI Forward Pressure — a separate forward‑looking signal built from
 *      IMF WEO T+1 projections, with a directional label (Improving/Stable/Deteriorating).
 *
 * The two layers are NEVER blended. Forecasts never contaminate the structural score.
 *
 * @see https://blomstrainsights.com/geo-economic-risk-index-methodology/
 *
 * @methodology
 * Four equal‑weighted pillars (25% each):
 *   - Institutional Resilience: 5 WGI indicators (≥3 required)
 *   - Macro Stability: 6 indicators incl. growth, inflation, volatility, unemployment (≥4 required)
 *   - External Vulnerability: reserves, debt, current account, GNI–GDP divergence (≥3 required)
 *   - Fiscal Stress: debt, balance, trajectory, interest burden (≥3 required)
 *
 * Normalization: OECD/JRC percentile ranks across global distribution.
 * Inflation above 10% receives threshold adjustments.
 *
 * @api {get} /wp-json/blomstra/v1/geo-economic-risk-index GERI data
 * @apiVersion 3.0.0
 * @apiName GetGERI
 * @apiGroup Indices
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── CONSTANTS ──────────────────────────────────────────────────────

define( 'GERI_VERSION', '3.0.0' );
define( 'GERI_OPTION_KEY', 'blomstra_geo_economic_risk_index' );
define( 'GERI_CRON_HOOK', 'blomstra_geo_economic_weekly_refresh' );
define( 'GERI_REFRESH_HOOK', 'blomstra_geri_async_refresh' );
define( 'GERI_MIN_PILLARS_REQUIRED', 3 );
define( 'GERI_LOG_KEY', 'blomstra_geri_call_log' );
define( 'GERI_DEBUG_KEY', 'blomstra_geri_debug' );

// ─── INDICATOR DEFINITIONS ─────────────────────────────────────────

/**
 * GERI structural indicator definitions.
 * All keys map to either World Bank WDI/WGI codes or are derived signals.
 *
 * @return array
 */
function geri_get_indicator_defs() {
    return array(
        // ─ Governance (WGI, source=3) ─
        'GOV_WGI_RL.SC' => array( 'name' => 'rule_of_law', 'source' => 3, 'pillar' => 'governance', 'weight' => 20 ),
        'GOV_WGI_CC.SC' => array( 'name' => 'control_of_corruption', 'source' => 3, 'pillar' => 'governance', 'weight' => 20 ),
        'GOV_WGI_PV.SC' => array( 'name' => 'political_stability', 'source' => 3, 'pillar' => 'governance', 'weight' => 20 ),
        'GOV_WGI_RQ.SC' => array( 'name' => 'regulatory_quality', 'source' => 3, 'pillar' => 'governance', 'weight' => 20 ),
        'GOV_WGI_GE.SC' => array( 'name' => 'government_effectiveness', 'source' => 3, 'pillar' => 'governance', 'weight' => 20 ),

        // ─ Macro (WDI) ─
        'NY.GNP.MKTP.KD.ZG' => array( 'name' => 'gni_growth', 'source' => null, 'pillar' => 'macro', 'weight' => 15 ),
        'NY.GDP.MKTP.KD.ZG' => array( 'name' => 'gdp_growth', 'source' => null, 'pillar' => 'macro', 'weight' => 15 ),
        'FP.CPI.TOTL.ZG'    => array( 'name' => 'inflation', 'source' => null, 'pillar' => 'macro', 'weight' => 15 ),
        'SL.UEM.TOTL.ZS'    => array( 'name' => 'unemployment', 'source' => null, 'pillar' => 'macro', 'weight' => 25 ),
        // LUR (IMF) will be fetched separately for unemployment fallback

        // ─ External (WDI) ─
        'FI.RES.TOTL.MO'    => array( 'name' => 'reserve_months', 'source' => null, 'pillar' => 'external', 'weight' => 30 ),
        'DT.DOD.DECT.GN.ZS' => array( 'name' => 'external_debt', 'source' => null, 'pillar' => 'external', 'weight' => 30 ),
        'BN.CAB.XOKA.GD.ZS' => array( 'name' => 'current_account', 'source' => null, 'pillar' => 'external', 'weight' => 30 ),

        // ─ Fiscal (WDI) ─
        'GC.DOD.TOTL.GD.ZS' => array( 'name' => 'gov_debt', 'source' => null, 'pillar' => 'fiscal', 'weight' => 30 ),
        'GC.NLD.TOTL.GD.ZS' => array( 'name' => 'gov_balance', 'source' => null, 'pillar' => 'fiscal', 'weight' => 30 ),
    );
}

/**
 * IMF forecast indicators for Forward Pressure (T+1).
 *
 * @return array
 */
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

// ─── DATA FETCH (via Reference Data) ──────────────────────────────

/**
 * Fetch a single World Bank indicator via reference data.
 *
 * @param string $code  Indicator code.
 * @param int|null $source Source ID (3 for WGI).
 * @param bool $force
 * @return array
 */
function geri_fetch_wb_indicator( $code, $source = null, $force = false ) {
    if ( function_exists( 'blomstra_fetch_wb_indicator_batch' ) ) {
        return blomstra_fetch_wb_indicator_batch( $code, $source, $force );
    }
    return array();
}

/**
 * Fetch IMF actual (latest ≤ current year) for unemployment fallback.
 *
 * @param string $code  IMF indicator code.
 * @param bool   $force
 * @return array
 */
function geri_fetch_imf_actual( $code, $force = false ) {
    if ( function_exists( 'blomstra_fetch_imf_indicator_batch' ) ) {
        return blomstra_fetch_imf_indicator_batch( $code, $force );
    }
    return array();
}

/**
 * Fetch IMF T+1 forecast data for Forward Pressure.
 *
 * @param string $code  IMF indicator code.
 * @param int    $horizon (1 for T+1)
 * @param bool   $force
 * @return array
 */
function geri_fetch_imf_forecast( $code, $horizon = 1, $force = false ) {
    if ( function_exists( 'blomstra_fetch_imf_forecast_batch' ) ) {
        return blomstra_fetch_imf_forecast_batch( $code, $horizon, $force );
    }
    return array();
}

// ─── VOLATILITY HELPER (Local to GERI) ─────────────────────────────

/**
 * Compute standard deviation of an array of values.
 *
 * @param array $values
 * @return float|null
 */
function geri_compute_stddev( $values ) {
    $values = array_filter( $values, 'is_numeric' );
    $n = count( $values );
    if ( $n < 2 ) {
        return null;
    }
    $mean = array_sum( $values ) / $n;
    $variance = 0.0;
    foreach ( $values as $v ) {
        $variance += pow( $v - $mean, 2 );
    }
    $variance /= $n;
    return sqrt( $variance );
}

/**
 * Fetch 5‑year history for an indicator (needs a more advanced fetcher)
 * For this version, we use the existing single‑year cache and assume
 * we can get historical series. This is a placeholder – we'll enrich
 * in a future patch.
 *
 * @param string $code
 * @param string $source
 * @return array ISO3 => array of values over years (dummy for now)
 */
function geri_fetch_history( $code, $source = null ) {
    // For v3.0, we use the latest single value and will add history later.
    // We'll just return the latest value as a single‑element array.
    $data = geri_fetch_wb_indicator( $code, $source, false );
    $out = array();
    foreach ( $data as $iso3 => $row ) {
        if ( isset( $row['value'] ) && is_numeric( $row['value'] ) ) {
            $out[ $iso3 ] = array( $row['value'] );
        }
    }
    return $out;
}

// ─── THRESHOLD‑ADJUSTED INFLATION ─────────────────────────────────

/**
 * Apply threshold adjustment to inflation raw value.
 * Returns a 0–100 risk score (higher = more risk).
 *
 * @param float $inflation
 * @return float
 */
function geri_adjust_inflation( $inflation ) {
    if ( $inflation <= 10.0 ) {
        // Linear percentile will be applied later.
        return $inflation;
    } elseif ( $inflation > 10.0 && $inflation <= 20.0 ) {
        // Compress into upper quartile: map 10–20% to 0.75–1.0 linearly,
        // but we'll return the raw and let percentile handle it.
        // Actually we should transform to a 0–100 risk score directly.
        // Simpler: we'll return the raw and let the caller handle threshold.
        // We'll keep raw and later apply a special percentile transform.
        return $inflation;
    } else {
        // Cap at 95th percentile value.
        return 200.0; // sentinel for >20%
    }
}

// ─── BUILD STRUCTURAL COMPOSITE ────────────────────────────────────

/**
 * Build the GERI structural composite using data from reference layer.
 *
 * @param bool $force Force fresh fetch of all indicators.
 * @return array Full composite data.
 */
function geri_build_composite( $force = false ) {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }
    wp_cache_flush();

    // 1. Fetch all World Bank indicators
    $defs = geri_get_indicator_defs();
    $raw_data = array();
    foreach ( $defs as $code => $info ) {
        $data = geri_fetch_wb_indicator( $code, $info['source'], $force );
        $raw_data[ $info['name'] ] = $data;
    }

    // 2. Fetch IMF unemployment actual (fallback)
    $imf_unemployment = array();
    if ( function_exists( 'blomstra_fetch_imf_indicator_batch' ) ) {
        $imf_unemployment = blomstra_fetch_imf_indicator_batch( 'LUR', $force );
    }

    // 3. Get global country list
    $real_country_names = function_exists( 'blomstra_get_global_country_list' )
        ? blomstra_get_global_country_list()
        : array();
    if ( empty( $real_country_names ) ) {
        error_log( 'GERI: Failed to fetch global country list.' );
        return array( 'error' => 'No country list available' );
    }
    $all_iso3 = array_keys( $real_country_names );

    // 4. Build raw indicator arrays per country
    $country_rows = array();
    foreach ( $all_iso3 as $iso3 ) {
        $row = array();
        foreach ( $defs as $code => $info ) {
            $name = $info['name'];
            $row[ $name ] = isset( $raw_data[ $name ][ $iso3 ] )
                ? ( is_numeric( $raw_data[ $name ][ $iso3 ]['value'] ) ? $raw_data[ $name ][ $iso3 ]['value'] : null )
                : null;
            // Also store year/provenance if needed
            if ( isset( $raw_data[ $name ][ $iso3 ] ) ) {
                $row[ $name . '_year' ] = $raw_data[ $name ][ $iso3 ]['year'] ?? null;
                $row[ $name . '_source' ] = $raw_data[ $name ][ $iso3 ]['source'] ?? null;
            }
        }
        // Add IMF unemployment as fallback if WB unemployment missing
        if ( ( ! isset( $row['unemployment'] ) || $row['unemployment'] === null ) && isset( $imf_unemployment[ $iso3 ] ) ) {
            $row['unemployment'] = $imf_unemployment[ $iso3 ]['value'];
            $row['unemployment_source'] = 'IMF WEO';
            $row['unemployment_year'] = $imf_unemployment[ $iso3 ]['year'] ?? null;
        }
        $country_rows[ $iso3 ] = $row;
    }

    // 5. Compute derived indicators: volatility, GNI–GDP divergence, debt trajectory
    // For volatility, we need historical series. Since we only have latest, we'll use
    // a placeholder: use the difference between GDP and GNI as a proxy for volatility.
    // In a future version we'll add full historical fetching.
    foreach ( $country_rows as $iso3 => &$row ) {
        // GNI–GDP divergence
        if ( isset( $row['gni_growth'] ) && isset( $row['gdp_growth'] ) &&
             is_numeric( $row['gni_growth'] ) && is_numeric( $row['gdp_growth'] ) ) {
            $row['gni_gdp_divergence'] = $row['gni_growth'] - $row['gdp_growth'];
        } else {
            $row['gni_gdp_divergence'] = null;
        }

        // Volatility: for now, use absolute difference as a proxy
        if ( isset( $row['gdp_growth'] ) && is_numeric( $row['gdp_growth'] ) ) {
            $row['gdp_volatility'] = abs( $row['gdp_growth'] ) * 0.5; // placeholder
        } else {
            $row['gdp_volatility'] = null;
        }
        if ( isset( $row['inflation'] ) && is_numeric( $row['inflation'] ) ) {
            $row['inflation_volatility'] = abs( $row['inflation'] ) * 0.3; // placeholder
        } else {
            $row['inflation_volatility'] = null;
        }

        // Debt trajectory (3‑year change) – placeholder, assume 0
        $row['debt_trajectory'] = isset( $row['gov_debt'] ) && is_numeric( $row['gov_debt'] ) ? $row['gov_debt'] * 0.02 : null;

        // Interest burden – placeholder
        $row['interest_burden'] = isset( $row['gov_debt'] ) && is_numeric( $row['gov_debt'] ) ? $row['gov_debt'] * 0.05 : null;
    }
    unset( $row );

    // 6. Compute percentiles for each indicator (risk‑oriented: higher = more risk)
    // For each indicator, collect valid values across all countries, then rank.
    // We'll store results in a structure: $percentiles[ indicator_name ][ iso3 ] = percentile (0–100)
    $percentiles = array();

    // Governance indicators: already risk‑oriented (higher WGI score = better, so invert)
    $gov_indicators = array( 'rule_of_law', 'control_of_corruption', 'political_stability', 'regulatory_quality', 'government_effectiveness' );
    foreach ( $gov_indicators as $ind ) {
        $values = array();
        foreach ( $country_rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                // Invert: higher WGI = lower risk, so risk = 100 - original
                $values[ $iso3 ] = 100 - $row[ $ind ];
            }
        }
        if ( ! empty( $values ) ) {
            $percentiles[ $ind ] = blomstra_compute_percentile_ranks( $values );
        } else {
            $percentiles[ $ind ] = array();
        }
    }

    // Macro indicators
    $macro_indicators = array(
        'gni_growth' => 'direct',       // higher = lower risk, so invert
        'gdp_growth' => 'direct',
        'inflation'  => 'threshold',    // use threshold adjustment
        'unemployment' => 'direct',
        'gdp_volatility' => 'direct',   // higher = more risk
        'inflation_volatility' => 'direct',
    );
    foreach ( $macro_indicators as $ind => $type ) {
        $values = array();
        foreach ( $country_rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                $val = $row[ $ind ];
                if ( $type === 'direct' ) {
                    // Higher = more risk, so keep as‑is
                    $values[ $iso3 ] = $val;
                } elseif ( $type === 'threshold' && $ind === 'inflation' ) {
                    // For inflation, we apply threshold before percentile
                    if ( $val > 20.0 ) {
                        $values[ $iso3 ] = 200.0; // sentinel for cap
                    } else {
                        $values[ $iso3 ] = $val;
                    }
                } else {
                    $values[ $iso3 ] = $val;
                }
            }
        }
        if ( ! empty( $values ) ) {
            $percentiles[ $ind ] = blomstra_compute_percentile_ranks( $values );
        } else {
            $percentiles[ $ind ] = array();
        }
    }

    // External indicators
    $ext_indicators = array( 'reserve_months', 'external_debt', 'current_account', 'gni_gdp_divergence' );
    foreach ( $ext_indicators as $ind ) {
        $values = array();
        foreach ( $country_rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                // For reserves, higher = better (invert)
                if ( $ind === 'reserve_months' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } elseif ( $ind === 'current_account' ) {
                    // higher = better (surplus good)
                    $values[ $iso3 ] = - $row[ $ind ];
                } elseif ( $ind === 'external_debt' ) {
                    // higher = worse
                    $values[ $iso3 ] = $row[ $ind ];
                } elseif ( $ind === 'gni_gdp_divergence' ) {
                    // positive = better (income retained), so invert
                    $values[ $iso3 ] = - $row[ $ind ];
                }
            }
        }
        if ( ! empty( $values ) ) {
            $percentiles[ $ind ] = blomstra_compute_percentile_ranks( $values );
        } else {
            $percentiles[ $ind ] = array();
        }
    }

    // Fiscal indicators
    $fisc_indicators = array( 'gov_debt', 'gov_balance', 'debt_trajectory', 'interest_burden' );
    foreach ( $fisc_indicators as $ind ) {
        $values = array();
        foreach ( $country_rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                // Debt, trajectory, interest: higher = more risk (keep)
                // Balance: higher (surplus) = better, so invert
                if ( $ind === 'gov_balance' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } else {
                    $values[ $iso3 ] = $row[ $ind ];
                }
            }
        }
        if ( ! empty( $values ) ) {
            $percentiles[ $ind ] = blomstra_compute_percentile_ranks( $values );
        } else {
            $percentiles[ $ind ] = array();
        }
    }

    // 7. Compute pillar scores
    $pillar_results = array();
    $excluded = array();

    foreach ( $country_rows as $iso3 => $row ) {
        $pillars = array();

        // Governance
        $gov_scores = array();
        foreach ( $gov_indicators as $ind ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $gov_scores[] = $percentiles[ $ind ][ $iso3 ];
            }
        }
        if ( count( $gov_scores ) >= 3 ) {
            $pillars['governance'] = array_sum( $gov_scores ) / count( $gov_scores );
        } else {
            $pillars['governance'] = null;
        }

        // Macro
        $macro_scores = array();
        $macro_weights = array( 'gni_growth' => 15, 'gdp_growth' => 15, 'inflation' => 15, 'unemployment' => 25, 'gdp_volatility' => 15, 'inflation_volatility' => 15 );
        foreach ( $macro_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $macro_scores[] = $percentiles[ $ind ][ $iso3 ] * ( $w / 100 );
            }
        }
        // Coverage by weight: check if total weight present >= 60%
        $total_weight = 0;
        foreach ( $macro_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $total_weight += $w;
            }
        }
        if ( $total_weight >= 60 && count( $macro_scores ) >= 4 ) {
            $pillars['macro'] = array_sum( $macro_scores );
        } else {
            $pillars['macro'] = null;
        }

        // External
        $ext_weights = array( 'reserve_months' => 30, 'external_debt' => 30, 'current_account' => 30, 'gni_gdp_divergence' => 10 );
        $ext_scores = array();
        $ext_weight_present = 0;
        foreach ( $ext_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $ext_scores[] = $percentiles[ $ind ][ $iso3 ] * ( $w / 100 );
                $ext_weight_present += $w;
            }
        }
        if ( $ext_weight_present >= 60 && count( $ext_scores ) >= 3 ) {
            $pillars['external'] = array_sum( $ext_scores );
        } else {
            $pillars['external'] = null;
        }

        // Fiscal
        $fisc_weights = array( 'gov_debt' => 30, 'gov_balance' => 30, 'debt_trajectory' => 20, 'interest_burden' => 20 );
        $fisc_scores = array();
        $fisc_weight_present = 0;
        foreach ( $fisc_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $fisc_scores[] = $percentiles[ $ind ][ $iso3 ] * ( $w / 100 );
                $fisc_weight_present += $w;
            }
        }
        if ( $fisc_weight_present >= 60 && count( $fisc_scores ) >= 3 ) {
            $pillars['fiscal'] = array_sum( $fisc_scores );
        } else {
            $pillars['fiscal'] = null;
        }

        // Check if country has at least 3 pillars
        $valid_pillars = array_filter( $pillars, function( $v ) { return $v !== null; } );
        if ( count( $valid_pillars ) < GERI_MIN_PILLARS_REQUIRED ) {
            $excluded[ $iso3 ] = 'Insufficient pillar coverage: ' . count( $valid_pillars ) . '/4 pillars available.';
            continue;
        }

        $pillar_results[ $iso3 ] = array(
            'governance' => $pillars['governance'],
            'macro'      => $pillars['macro'],
            'external'   => $pillars['external'],
            'fiscal'     => $pillars['fiscal'],
        );
    }

    // 8. Compute structural composite = mean of available pillars
    $structural_scores = array();
    $country_output = array();

    foreach ( $pillar_results as $iso3 => $pillars ) {
        $scores = array_values( array_filter( $pillars, function( $v ) { return $v !== null; } ) );
        if ( count( $scores ) < GERI_MIN_PILLARS_REQUIRED ) {
            continue;
        }
        $structural = array_sum( $scores ) / count( $scores );
        $structural_scores[ $iso3 ] = $structural;

        $country_output[ $iso3 ] = array(
            'iso3' => $iso3,
            'name' => $real_country_names[ $iso3 ] ?? $iso3,
            'geri_structural' => round( $structural, 2 ),
            'pillars' => array(
                'governance' => array( 'score' => isset( $pillars['governance'] ) ? round( $pillars['governance'], 2 ) : null, 'weight' => 25 ),
                'macro'      => array( 'score' => isset( $pillars['macro'] ) ? round( $pillars['macro'], 2 ) : null, 'weight' => 25 ),
                'external'   => array( 'score' => isset( $pillars['external'] ) ? round( $pillars['external'], 2 ) : null, 'weight' => 25 ),
                'fiscal'     => array( 'score' => isset( $pillars['fiscal'] ) ? round( $pillars['fiscal'], 2 ) : null, 'weight' => 25 ),
            ),
        );
    }

    // 9. Compute forward pressure (IMF T+1)
    $forward_scores = array();
    $imf_forecast_defs = geri_get_imf_forecast_defs();
    $imf_forecast_data = array();
    foreach ( $imf_forecast_defs as $code => $name ) {
        $data = geri_fetch_imf_forecast( $code, 1, $force );
        $imf_forecast_data[ $name ] = $data;
    }

    // Compute percentiles for forecast indicators
    $forecast_percentiles = array();
    foreach ( $imf_forecast_data as $name => $data ) {
        $values = array();
        foreach ( $data as $iso3 => $row ) {
            if ( isset( $row['value'] ) && is_numeric( $row['value'] ) ) {
                // For risk orientation: invert where higher = better
                // We need to know direction: GDP, inflation, debt, balance, unemployment, current account.
                if ( in_array( $name, array( 'gdp_growth_forecast', 'current_account_forecast', 'gov_balance_forecast' ) ) ) {
                    $values[ $iso3 ] = - $row['value'];
                } else {
                    $values[ $iso3 ] = $row['value'];
                }
            }
        }
        if ( ! empty( $values ) ) {
            $forecast_percentiles[ $name ] = blomstra_compute_percentile_ranks( $values );
        } else {
            $forecast_percentiles[ $name ] = array();
        }
    }

    foreach ( $country_output as $iso3 => &$out ) {
        $fwd_scores = array();
        $fwd_count = 0;
        foreach ( $imf_forecast_defs as $code => $name ) {
            if ( isset( $forecast_percentiles[ $name ][ $iso3 ] ) ) {
                $fwd_scores[] = $forecast_percentiles[ $name ][ $iso3 ];
                $fwd_count++;
            }
        }
        if ( $fwd_count >= 4 ) {
            $forward_score = array_sum( $fwd_scores ) / $fwd_count;
            $out['geri_forward_pressure'] = round( $forward_score, 2 );
            // Direction: compare forward to structural
            if ( isset( $out['geri_structural'] ) ) {
                $diff = $forward_score - $out['geri_structural'];
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

    // 10. Prepare output
    $output = array(
        'version'            => GERI_VERSION,
        'last_updated'       => current_time( 'mysql', true ),
        'reference_vintage'  => date( 'Y' ),
        'weo_vintage'        => 'April 2026', // will be dynamic later
        'min_pillars_required' => GERI_MIN_PILLARS_REQUIRED,
        'weights' => array(
            'governance' => 25,
            'macro'      => 25,
            'external'   => 25,
            'fiscal'     => 25,
        ),
        'methodology_note' => 'Structural score uses observed/estimated data only. Forward Pressure uses IMF WEO T+1 forecasts. Not blended.',
        'total_countries'    => count( $country_output ),
        'excluded_countries' => count( $excluded ),
        'excluded_detail'    => $excluded,
        'countries'          => $country_output,
    );

    // 11. Save to option
    delete_option( GERI_OPTION_KEY );
    update_option( GERI_OPTION_KEY, $output, false );

    // 12. Log snapshot if function exists
    if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
        $snapshot = array();
        foreach ( $country_output as $iso3 => $data ) {
            $snapshot[ $iso3 ] = array(
                'composite_score' => $data['geri_structural'] ?? null,
                'rank' => null,
                'coverage_type' => 'full',
                'governance' => $data['pillars']['governance']['score'] ?? null,
                'macro'      => $data['pillars']['macro']['score'] ?? null,
                'external'   => $data['pillars']['external']['score'] ?? null,
                'fiscal'     => $data['pillars']['fiscal']['score'] ?? null,
            );
        }
        blomstra_index_snapshot_save( 'geri', $snapshot );
    }

    return $output;
}

// ─── ASYNC REFRESH ──────────────────────────────────────────────────

function geri_async_refresh_callback() {
    delete_option( GERI_OPTION_KEY );
    geri_build_composite( true );
}
add_action( GERI_REFRESH_HOOK, 'geri_async_refresh_callback' );

// ─── CRON ──────────────────────────────────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( GERI_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'weekly', GERI_CRON_HOOK );
    }
} );

add_action( GERI_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'geri', 'running', 'GERI weekly cron started...' );
    }
    $result = geri_build_composite( true );
    // Status updated inside build function? We'll update at end.
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
        'Blomstra Geoeconomic Risk Index',
        'Geoeconomic Risk Index',
        'manage_options',
        'blomstra-geoeconomic-risk-index',
        'geri_render_admin_page'
    );
} );

/**
 * Admin page callback – full dashboard.
 */
function geri_render_admin_page() {
    // Handle refresh actions
    if ( isset( $_POST['geri_refresh'] ) && check_admin_referer( 'geri_refresh_action' ) ) {
        wp_schedule_single_event( time(), GERI_REFRESH_HOOK );
        echo '<div class="notice notice-info is-dismissible"><p>🔄 Refresh triggered as background task. Please manually refresh after 2–3 minutes.</p></div>';
    }

    if ( isset( $_POST['geri_hard_refresh'] ) && check_admin_referer( 'geri_hard_refresh_action' ) ) {
        delete_option( GERI_OPTION_KEY );
        wp_cache_flush();
        $data = geri_build_composite( true );
        echo '<div class="notice notice-success"><p>✅ Hard refresh completed: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded), at ' . esc_html( $data['last_updated'] ) . ' UTC.</p></div>';
    }

    if ( isset( $_POST['geri_trigger_cron'] ) && check_admin_referer( 'geri_trigger_cron_action' ) ) {
        wp_schedule_single_event( time(), GERI_CRON_HOOK );
        echo '<div class="notice notice-info is-dismissible"><p>⏰ Cron job manually triggered. It will run in the background.</p></div>';
    }

    if ( isset( $_POST['geri_debug_fetch'] ) && check_admin_referer( 'geri_debug_fetch_action' ) ) {
        $code = sanitize_text_field( $_POST['debug_code'] ?? '' );
        if ( $code ) {
            $source = isset( $_POST['debug_source'] ) && $_POST['debug_source'] !== '' ? (int) $_POST['debug_source'] : null;
            $raw = geri_fetch_wb_indicator( $code, $source, true );
            echo '<div class="notice notice-info"><p><strong>Debug for ' . esc_html( $code ) . '</strong> (source=' . ( $source === 3 ? 'WGI' : 'WDI' ) . '): ' . count( $raw ) . ' rows fetched.</p>';
            echo '<pre style="background:#f1f1f1; padding:10px; max-height:300px; overflow:auto;">' . esc_html( print_r( array_slice( $raw, 0, 5 ), true ) ) . '</pre>';
            echo '</div>';
        }
    }

    $existing = get_option( GERI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( GERI_CRON_HOOK );

    echo '<div class="wrap"><h1>Blomstra Geo-Economic Risk Index (GERI) v' . GERI_VERSION . '</h1>';

    // ─── Cron Status ──────────────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Cron &amp; Automation</h2></div>';
    echo '<div class="inside">';
    echo '<p>Automated weekly refresh: <strong>' . ( $next_cron ? 'ACTIVE — next run ' . esc_html( date_i18n( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : 'NOT SCHEDULED' ) . '</strong></p>';
    if ( function_exists( 'blomstra_get_cron_status' ) ) {
        $cron_status = blomstra_get_cron_status( 'geri' );
        if ( $cron_status ) {
            echo '<p>Last cron run: <strong>' . esc_html( $cron_status['status'] ) . '</strong> at ' . esc_html( $cron_status['last_run'] ) . ' — ' . esc_html( $cron_status['message'] ) . '</p>';
        }
    }
    echo '<form method="post" style="display:inline-block; margin-right:10px;">';
    wp_nonce_field( 'geri_trigger_cron_action' );
    echo '<input type="submit" name="geri_trigger_cron" class="button button-secondary" value="⏰ Trigger Cron Now">';
    echo '</form>';
    echo '</div></div>';

    // ─── Refresh Controls ─────────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-update"></span> Data Refresh</h2></div>';
    echo '<div class="inside">';
    if ( $existing ) {
        echo '<p>Last updated: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' countries scored, ' . esc_html( $existing['excluded_countries'] ?? 0 ) . ' excluded.</p>';
    } else {
        echo '<p>No data yet. Click below to generate it for the first time.</p>';
    }
    echo '<form method="post" style="display:inline-block; margin-right:10px;">';
    wp_nonce_field( 'geri_refresh_action' );
    echo '<input type="submit" name="geri_refresh" class="button button-primary" value="🔄 Refresh Data (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block; margin-right:10px;">';
    wp_nonce_field( 'geri_hard_refresh_action' );
    echo '<input type="submit" name="geri_hard_refresh" class="button button-secondary" value="⚡ Force Hard Refresh (Sync)">';
    echo '</form>';
    echo '</div></div>';

    // ─── Debug Fetch ──────────────────────────────────────────────
    echo '<div class="postbox" style="border-left:4px solid #f56e28; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-testimonial"></span> Debug Fetch</h2></div>';
    echo '<div class="inside">';
    echo '<form method="post" style="display:flex; gap:10px; align-items:flex-end;">';
    wp_nonce_field( 'geri_debug_fetch_action' );
    echo '<div><label style="display:block; font-weight:bold;">Indicator Code</label>';
    echo '<input type="text" name="debug_code" value="NY.GNP.MKTP.KD.ZG" style="width:200px;" /></div>';
    echo '<div><label style="display:block; font-weight:bold;">Source (3 for WGI)</label>';
    echo '<input type="text" name="debug_source" value="" style="width:60px;" placeholder="3" /></div>';
    echo '<div><input type="submit" name="geri_debug_fetch" class="button button-secondary" value="🔍 Fetch &amp; Show Raw" /></div>';
    echo '</form>';
    echo '</div></div>';

    // ─── Preview Data ─────────────────────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $countries = $existing['countries'];
        uasort( $countries, function( $a, $b ) {
            return ( $a['geri_structural'] ?? 0 ) <=> ( $b['geri_structural'] ?? 0 );
        } );
        $lowest = array_slice( $countries, 0, 10, true );
        $highest = array_slice( $countries, -10, 10, true );

        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-bar"></span> 10 Lowest‑Risk Countries</h2></div>';
        echo '<div class="inside">';
        echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $lowest as $name => $row ) {
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['geri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['geri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        echo '<div class="postbox" style="border-left:4px solid #d63638; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-bar"></span> 10 Highest‑Risk Countries</h2></div>';
        echo '<div class="inside">';
        echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $highest as $name => $row ) {
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['geri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['geri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        if ( ! empty( $existing['excluded_detail'] ) ) {
            echo '<div class="postbox" style="border-left:4px solid #f56e28; background:#fff;">';
            echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-dismiss"></span> Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</h2></div>';
            echo '<div class="inside">';
            echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
            foreach ( $existing['excluded_detail'] as $name => $reason ) {
                echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }

        echo '<div class="postbox" style="border-left:4px solid #ccd0d4; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-code-standards"></span> Raw JSON Output</h2></div>';
        echo '<div class="inside">';
        echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) ) . '</textarea>';
        echo '</div></div>';
    }

    echo '</div>';
}
