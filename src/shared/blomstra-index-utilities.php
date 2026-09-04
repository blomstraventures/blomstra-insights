/**
 * Blomstra Shared Index Utilities — Data Integrity & Processing Layer
 *
 * Provides safe numeric handling, timeseries sanitization, per-indicator source
 * tracking, validated fallback merging, winsorized percentile computation,
 * static country classification data, and the generic index builder orchestrator.
 *
 * @package Blomstra\Insights\Shared
 * @since   1.0.0
 * @version 1.4.1  – Fixed critical bugs in generic builder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// 0. STATIC COUNTRY CLASSIFICATIONS
// ─────────────────────────────────────────────────────────────────

if ( ! defined( 'BLOMSTRA_LANDLOCKED_ISO3' ) ) {
    define( 'BLOMSTRA_LANDLOCKED_ISO3', array(
        'AFG', 'AND', 'ARM', 'AUT', 'AZE', 'BLR', 'BTN', 'BOL', 'BWA', 'BFA',
        'BDI', 'CAF', 'TCD', 'CZE', 'ETH', 'SWZ', 'HUN', 'KAZ', 'KGZ', 'LAO',
        'LSO', 'LIE', 'LUX', 'MWI', 'MLI', 'MDA', 'MNG', 'NPL', 'NER', 'MKD',
        'PRY', 'RWA', 'SMR', 'SRB', 'SVK', 'SSD', 'CHE', 'TJK', 'TKM', 'UGA',
        'UZB', 'VAT', 'ZMB', 'ZWE',
    ) );
}

if ( ! defined( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE' ) ) {
    define( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE', '2026-08-17' );
}

function blomstra_is_landlocked( $iso3 ) {
    $iso3 = strtoupper( trim( $iso3 ) );
    $override = get_option( 'blomstra_landlocked_override', array() );
    if ( ! empty( $override['iso3s'] ) && is_array( $override['iso3s'] ) ) {
        return in_array( $iso3, $override['iso3s'], true );
    }
    return in_array( $iso3, BLOMSTRA_LANDLOCKED_ISO3, true );
}

function blomstra_is_stale( $pillar, $threshold = null ) {
    $defaults = array(
        'wb_indicators' => 7 * DAY_IN_SECONDS,
        'eia'           => 7 * DAY_IN_SECONDS,
        'hhi'           => 7 * DAY_IN_SECONDS,
        'maritime'      => 7 * DAY_IN_SECONDS,
        'imf'           => 7 * DAY_IN_SECONDS,
        'countries'     => 30 * DAY_IN_SECONDS,
        'reporters'     => 30 * DAY_IN_SECONDS,
        'landlocked'    => 6 * MONTH_IN_SECONDS,
        'sivi'          => 7 * DAY_IN_SECONDS,
    );

    if ( $pillar === 'landlocked' ) {
        $source_date = defined( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE' ) ? strtotime( BLOMSTRA_LANDLOCKED_SOURCE_DATE ) : 0;
        $threshold = $threshold ?: $defaults['landlocked'];
        return ( time() - $source_date ) > $threshold;
    }

    $cron_status = get_option( 'blomstra_cron_status', array() );
    if ( ! isset( $cron_status[ $pillar ] ) ) {
        return true;
    }

    $last_run = isset( $cron_status[ $pillar ]['last_success'] )
        ? strtotime( $cron_status[ $pillar ]['last_success'] )
        : ( isset( $cron_status[ $pillar ]['last_attempt'] ) ? strtotime( $cron_status[ $pillar ]['last_attempt'] ) : 0 );

    if ( ! $last_run ) {
        return true;
    }

    $threshold = $threshold ?: ( isset( $defaults[ $pillar ] ) ? $defaults[ $pillar ] : 7 * DAY_IN_SECONDS );
    return ( time() - $last_run ) > $threshold;
}

// ─────────────────────────────────────────────────────────────────
// 1. SAFE DATA EXTRACTION
// ─────────────────────────────────────────────────────────────────

function blomstra_safe_numeric( $value, $default = null ) {
    if ( isset( $value ) && is_numeric( $value ) ) {
        return (float) $value;
    }
    return $default;
}

function blomstra_safe_string( $value, $default = null ) {
    if ( isset( $value ) && is_string( $value ) && $value !== '' ) {
        return $value;
    }
    return $default;
}

function blomstra_safe_array_get( $array, $key, $subkey = null, $default = null ) {
    if ( ! is_array( $array ) ) {
        return $default;
    }
    if ( ! isset( $array[ $key ] ) ) {
        return $default;
    }
    if ( $subkey === null ) {
        return $array[ $key ];
    }
    if ( is_array( $array[ $key ] ) && isset( $array[ $key ][ $subkey ] ) ) {
        return $array[ $key ][ $subkey ];
    }
    return $default;
}

// ─────────────────────────────────────────────────────────────────
// 2. TIMESERIES SANITIZATION
// ─────────────────────────────────────────────────────────────────

function blomstra_sanitize_timeseries( $data, $min_obs = 2, $min_span = 1 ) {
    if ( ! is_array( $data ) || empty( $data ) ) {
        return array();
    }
    $clean = array_filter( $data, 'is_numeric' );
    if ( count( $clean ) < $min_obs ) {
        return array();
    }
    ksort( $clean, SORT_NUMERIC );
    $years = array_keys( $clean );
    $span  = (int) end( $years ) - (int) reset( $years );
    if ( $span < $min_span ) {
        return array();
    }
    return $clean;
}

function blomstra_timeseries_bounds( $data ) {
    if ( empty( $data ) ) {
        return null;
    }
    $years = array_keys( $data );
    $first = (int) reset( $years );
    $last  = (int) end( $years );
    return array(
        'oldest_year'  => $first,
        'newest_year'  => $last,
        'oldest_value' => (float) $data[ $first ],
        'newest_value' => (float) $data[ $last ],
        'span'         => $last - $first,
        'observations' => count( $data ),
    );
}

function blomstra_compute_cagr( $data ) {
    $bounds = blomstra_timeseries_bounds( $data );
    if ( ! $bounds || $bounds['span'] < 1 ) {
        return null;
    }
    $oldest = $bounds['oldest_value'];
    $newest = $bounds['newest_value'];
    if ( $oldest == 0 ) {
        return null;
    }
    $cagr = pow( $newest / $oldest, 1 / $bounds['span'] ) - 1;
    return $cagr * 100;
}

function blomstra_compute_cagr_from_values( $oldest, $newest, $span ) {
    if ( $span < 1 || $oldest == 0 ) {
        return null;
    }
    return ( pow( $newest / $oldest, 1 / $span ) - 1 ) * 100;
}

function blomstra_compute_stddev( $values, $sample = true ) {
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
    $variance /= $sample ? ( $n - 1 ) : $n;
    return sqrt( $variance );
}

// ─────────────────────────────────────────────────────────────────
// 3. PER-INDICATOR SOURCE TRACKING
// ─────────────────────────────────────────────────────────────────

function blomstra_track_source( &$sources, $iso3, $indicator, $source, $scope = null, $year = null ) {
    if ( ! isset( $sources[ $iso3 ] ) ) {
        $sources[ $iso3 ] = array();
    }
    $sources[ $iso3 ][ $indicator ] = array(
        'source' => $source,
        'scope'  => $scope,
        'year'   => $year,
    );
}

function blomstra_pillar_source_summary( $sources, $iso3, $indicators ) {
    if ( ! isset( $sources[ $iso3 ] ) ) {
        return null;
    }
    $source_counts = array();
    $scope_counts  = array();
    $breakdown     = array();
    foreach ( $indicators as $ind ) {
        if ( isset( $sources[ $iso3 ][ $ind ] ) ) {
            $meta = $sources[ $iso3 ][ $ind ];
            $src  = $meta['source'] ?? 'unknown';
            $scp  = $meta['scope']  ?? 'unknown';
            $source_counts[ $src ] = ( $source_counts[ $src ] ?? 0 ) + 1;
            $scope_counts[ $scp ]  = ( $scope_counts[ $scp ]  ?? 0 ) + 1;
            $breakdown[ $ind ]     = $meta;
        }
    }
    if ( empty( $source_counts ) ) {
        return null;
    }
    arsort( $source_counts );
    arsort( $scope_counts );
    $primary_source = key( $source_counts );
    $primary_scope  = key( $scope_counts );
    $scope_mixed    = count( $scope_counts ) > 1;
    return array(
        'primary_source' => $primary_source,
        'primary_scope'  => $primary_scope,
        'scope_mixed'    => $scope_mixed,
        'breakdown'      => $breakdown,
        'source_counts'  => $source_counts,
    );
}

// ─────────────────────────────────────────────────────────────────
// 4. DEFINITION VALIDATION
// ─────────────────────────────────────────────────────────────────

function blomstra_validate_pillar_thresholds( $defs, $engine ) {
    $mismatches = array();
    foreach ( $defs as $pillar => $def ) {
        if ( ! isset( $engine[ $pillar ] ) ) {
            $mismatches[] = array(
                'pillar'  => $pillar,
                'field'   => 'existence',
                'issue'   => 'Pillar exists in defs but not in engine',
            );
            continue;
        }
        $def_min_req = $def['min_required'] ?? null;
        $eng_min_req = $engine[ $pillar ]['min_required'] ?? null;
        if ( $def_min_req !== null && $eng_min_req !== null && (int) $def_min_req !== (int) $eng_min_req ) {
            $mismatches[] = array(
                'pillar'    => $pillar,
                'field'     => 'min_required',
                'def_value' => $def_min_req,
                'eng_value' => $eng_min_req,
                'issue'     => "Definition requires {$def_min_req} indicators, engine allows {$eng_min_req}",
            );
        }
        $def_min_w = $def['min_weight'] ?? null;
        $eng_min_w = $engine[ $pillar ]['min_weight'] ?? null;
        if ( $def_min_w !== null && $eng_min_w !== null && (float) $def_min_w !== (float) $eng_min_w ) {
            $mismatches[] = array(
                'pillar'    => $pillar,
                'field'     => 'min_weight',
                'def_value' => $def_min_w,
                'eng_value' => $eng_min_w,
                'issue'     => "Definition requires {$def_min_w}% weight, engine allows {$eng_min_w}%",
            );
        }
        $weights = $engine[ $pillar ]['indicators'] ?? array();
        $weight_sum = array_sum( $weights );
        if ( abs( $weight_sum - 100 ) > 0.1 ) {
            $mismatches[] = array(
                'pillar'    => $pillar,
                'field'     => 'indicator_weights',
                'def_value' => $weight_sum,
                'eng_value' => 100,
                'issue'     => "Indicator weights sum to {$weight_sum}, expected 100",
            );
        }
    }
    return array(
        'valid'      => empty( $mismatches ),
        'mismatches' => $mismatches,
    );
}

// ─────────────────────────────────────────────────────────────────
// 5. PERCENTILE COMPUTATION WITH OUTLIER HANDLING
// ─────────────────────────────────────────────────────────────────

function blomstra_winsorize( $values, $winsor_pct = 0.0 ) {
    $n = count( $values );
    if ( $winsor_pct <= 0 || $n < 10 ) {
        return $values;
    }
    $clean = $values;
    asort( $clean, SORT_NUMERIC );
    $sorted_vals = array_values( $clean );
    $cutoff = max( 1, (int) round( $n * $winsor_pct ) );
    $lower_bound = $sorted_vals[ $cutoff - 1 ];
    $upper_bound = $sorted_vals[ $n - $cutoff ];
    $result = array();
    foreach ( $values as $key => $val ) {
        if ( $val < $lower_bound ) {
            $result[ $key ] = $lower_bound;
        } elseif ( $val > $upper_bound ) {
            $result[ $key ] = $upper_bound;
        } else {
            $result[ $key ] = $val;
        }
    }
    return $result;
}

function blomstra_compute_percentile_ranks_safe( $values, $winsor_pct = 0.0 ) {
    if ( empty( $values ) ) {
        return array();
    }
    $clean = array_filter( $values, 'is_numeric' );
    $n = count( $clean );
    if ( $n === 0 ) {
        return array();
    }
    $clean = blomstra_winsorize( $clean, $winsor_pct );
    asort( $clean, SORT_NUMERIC );
    $sorted = array_keys( $clean );
    $ranks = array();
    $i = 1;
    while ( $i <= $n ) {
        $iso3 = $sorted[ $i - 1 ];
        $val  = $clean[ $iso3 ];
        $tie_indices = array( $i );
        $j = $i + 1;
        while ( $j <= $n && $clean[ $sorted[ $j - 1 ] ] == $val ) {
            $tie_indices[] = $j;
            $j++;
        }
        $avg_rank = array_sum( $tie_indices ) / count( $tie_indices );
        foreach ( $tie_indices as $idx ) {
            $ranks[ $sorted[ $idx - 1 ] ] = $avg_rank;
        }
        $i = $j;
    }
    $percentiles = array();
    foreach ( $ranks as $iso3 => $rank ) {
        $percentiles[ $iso3 ] = ( ( $rank - 0.5 ) / $n ) * 100;
    }
    return $percentiles;
}

// ─────────────────────────────────────────────────────────────────
// 6. FALLBACK MERGING WITH PROVENANCE
// ─────────────────────────────────────────────────────────────────

function blomstra_merge_with_fallback( $primary, $fallback, &$sources, $indicator, $primary_label, $fallback_label, $primary_scope = null, $fallback_scope = null ) {
    $result = array();
    foreach ( $primary as $iso3 => $value ) {
        $num = blomstra_safe_numeric( $value );
        if ( $num !== null ) {
            $result[ $iso3 ] = $num;
            blomstra_track_source( $sources, $iso3, $indicator, $primary_label, $primary_scope );
        }
    }
    foreach ( $fallback as $iso3 => $value ) {
        if ( isset( $result[ $iso3 ] ) ) {
            continue;
        }
        $num = blomstra_safe_numeric( $value );
        if ( $num !== null ) {
            $result[ $iso3 ] = $num;
            blomstra_track_source( $sources, $iso3, $indicator, $fallback_label, $fallback_scope );
        }
    }
    return $result;
}

function blomstra_merge_priority_layers( $layers, &$sources, $indicator ) {
    $result = array();
    foreach ( $layers as $layer ) {
        $data  = $layer['data']  ?? array();
        $label = $layer['label'] ?? 'unknown';
        $scope = $layer['scope'] ?? null;
        foreach ( $data as $iso3 => $value ) {
            if ( isset( $result[ $iso3 ] ) ) {
                continue;
            }
            $num = blomstra_safe_numeric( $value );
            if ( $num !== null ) {
                $result[ $iso3 ] = $num;
                blomstra_track_source( $sources, $iso3, $indicator, $label, $scope );
            }
        }
    }
    return $result;
}

// ─────────────────────────────────────────────────────────────────
// 7. DATA QUALITY FLAGS
// ─────────────────────────────────────────────────────────────────

function blomstra_data_quality_flag( $sources, $iso3, $indicator, $current_year = null ) {
    if ( $current_year === null ) {
        $current_year = (int) current_time( 'Y' );
    }
    if ( ! isset( $sources[ $iso3 ][ $indicator ] ) ) {
        return array(
            'available'    => false,
            'staleness_years' => null,
            'source'       => null,
            'scope'        => null,
            'quality'      => 'missing',
        );
    }
    $meta = $sources[ $iso3 ][ $indicator ];
    $year = isset( $meta['year'] ) ? (int) $meta['year'] : null;
    $staleness = ( $year !== null ) ? ( $current_year - $year ) : null;
    $quality = 'good';
    if ( $staleness !== null ) {
        if ( $staleness > 3 ) {
            $quality = 'stale';
        } elseif ( $staleness > 1 ) {
            $quality = 'aged';
        }
    }
    return array(
        'available'       => true,
        'staleness_years' => $staleness,
        'source'          => $meta['source'] ?? 'unknown',
        'scope'           => $meta['scope']  ?? 'unknown',
        'quality'         => $quality,
        'year'            => $year,
    );
}

function blomstra_pillar_quality_score( $sources, $iso3, $indicators, $current_year = null ) {
    if ( $current_year === null ) {
        $current_year = (int) current_time( 'Y' );
    }
    $total = count( $indicators );
    $found = 0;
    $staleness_sum = 0;
    $quality_counts = array( 'good' => 0, 'aged' => 0, 'stale' => 0, 'missing' => 0 );
    foreach ( $indicators as $ind ) {
        $flag = blomstra_data_quality_flag( $sources, $iso3, $ind, $current_year );
        $quality_counts[ $flag['quality'] ]++;
        if ( $flag['available'] ) {
            $found++;
            if ( $flag['staleness_years'] !== null ) {
                $staleness_sum += $flag['staleness_years'];
            }
        }
    }
    $coverage = $total > 0 ? ( $found / $total ) * 100 : 0;
    $avg_staleness = $found > 0 ? ( $staleness_sum / $found ) : null;
    return array(
        'coverage_pct'    => round( $coverage, 2 ),
        'avg_staleness' => $avg_staleness !== null ? round( $avg_staleness, 2 ) : null,
        'quality_counts'=> $quality_counts,
        'indicators'    => $indicators,
    );
}

// ─────────────────────────────────────────────────────────────────
// 8. STATISTICAL / RESEARCH-CREDIBILITY LAYER
// ─────────────────────────────────────────────────────────────────

function blomstra_spearman_correlation( $x, $y ) {
    $n = count( $x );
    if ( $n < 2 || count( $y ) !== $n ) {
        return 0;
    }
    $rank = function ( $arr ) {
        $n = count( $arr );
        $indexed = array();
        foreach ( $arr as $i => $v ) {
            $indexed[] = array( 'i' => $i, 'v' => $v );
        }
        usort( $indexed, function ( $a, $b ) {
            return $a['v'] <=> $b['v'];
        } );
        $ranks = array_fill( 0, $n, 0.0 );
        $pos = 0;
        while ( $pos < $n ) {
            $tie_end = $pos;
            while ( $tie_end + 1 < $n && $indexed[ $tie_end + 1 ]['v'] == $indexed[ $pos ]['v'] ) {
                $tie_end++;
            }
            $avg_rank = ( ( $pos + 1 ) + ( $tie_end + 1 ) ) / 2;
            for ( $k = $pos; $k <= $tie_end; $k++ ) {
                $ranks[ $indexed[ $k ]['i'] ] = $avg_rank;
            }
            $pos = $tie_end + 1;
        }
        return $ranks;
    };
    $rx = $rank( $x );
    $ry = $rank( $y );
    $d2 = 0;
    for ( $i = 0; $i < $n; $i++ ) {
        $d2 += pow( $rx[ $i ] - $ry[ $i ], 2 );
    }
    return 1 - ( ( 6 * $d2 ) / ( $n * ( $n * $n - 1 ) ) );
}

function blomstra_cronbach_alpha( $indicator_matrix ) {
    $k = count( $indicator_matrix );
    if ( $k < 2 ) {
        return null;
    }
    $first = reset( $indicator_matrix );
    $n = count( $first );
    if ( $n < 2 ) {
        return null;
    }
    $sample_variance = function ( $values ) {
        $n = count( $values );
        $mean = array_sum( $values ) / $n;
        $ss = 0;
        foreach ( $values as $v ) {
            $ss += pow( $v - $mean, 2 );
        }
        return $ss / ( $n - 1 );
    };
    $item_variances_sum = 0;
    $total_scores = array_fill( 0, $n, 0 );
    foreach ( $indicator_matrix as $item_values ) {
        $item_values = array_values( $item_values );
        if ( count( $item_values ) !== $n ) {
            return null;
        }
        $item_variances_sum += $sample_variance( $item_values );
        foreach ( $item_values as $i => $v ) {
            $total_scores[ $i ] += $v;
        }
    }
    $total_variance = $sample_variance( $total_scores );
    if ( $total_variance <= 0 ) {
        return null;
    }
    $alpha = ( $k / ( $k - 1 ) ) * ( 1 - ( $item_variances_sum / $total_variance ) );
    return round( $alpha, 3 );
}

function blomstra_bootstrap_ci( $pillar_values_by_country, $pillar_weights, $n_bootstrap = 1000, $ci_level = 0.95 ) {
    $countries = array_keys( $pillar_values_by_country );
    $n = count( $countries );
    if ( $n < 10 ) {
        return null;
    }
    $total_weight = array_sum( $pillar_weights );
    if ( $total_weight <= 0 ) {
        return null;
    }
    $point_estimates = array();
    foreach ( $pillar_values_by_country as $iso3 => $pillars ) {
        $sum = 0;
        foreach ( $pillar_weights as $pname => $w ) {
            $sum += ( $pillars[ $pname ] ?? 0 ) * $w;
        }
        $point_estimates[ $iso3 ] = $sum / $total_weight;
    }
    $draws_by_country = array_fill_keys( $countries, array() );
    for ( $b = 0; $b < $n_bootstrap; $b++ ) {
        $perturbed = array();
        $sum_w = 0;
        foreach ( $pillar_weights as $pname => $w ) {
            $factor = 1 + ( wp_rand( -1000, 1000 ) / 10000 );
            $pw = max( 0, $w * $factor );
            $perturbed[ $pname ] = $pw;
            $sum_w += $pw;
        }
        if ( $sum_w <= 0 ) {
            continue;
        }
        foreach ( $pillar_values_by_country as $iso3 => $pillars ) {
            $sum = 0;
            foreach ( $perturbed as $pname => $pw ) {
                $sum += ( $pillars[ $pname ] ?? 0 ) * $pw;
            }
            $draws_by_country[ $iso3 ][] = $sum / $sum_w;
        }
    }
    $alpha = ( 1 - $ci_level ) / 2;
    $result = array();
    foreach ( $draws_by_country as $iso3 => $draws ) {
        if ( empty( $draws ) ) {
            $result[ $iso3 ] = array( 'point' => round( $point_estimates[ $iso3 ], 2 ), 'ci_low' => null, 'ci_high' => null );
            continue;
        }
        sort( $draws );
        $n_draws = count( $draws );
        $low_idx  = max( 0, min( $n_draws - 1, (int) floor( $alpha * $n_draws ) ) );
        $high_idx = max( 0, min( $n_draws - 1, (int) ceil( ( 1 - $alpha ) * $n_draws ) - 1 ) );
        $result[ $iso3 ] = array(
            'point'   => round( $point_estimates[ $iso3 ], 2 ),
            'ci_low'  => round( $draws[ $low_idx ], 2 ),
            'ci_high' => round( $draws[ $high_idx ], 2 ),
        );
    }
    return $result;
}

function blomstra_benchmark_correlate( $index_scores, $benchmark_scores ) {
    $common_keys = array_intersect_key( $index_scores, $benchmark_scores );
    if ( count( $common_keys ) < 5 ) {
        return null;
    }
    ksort( $common_keys );
    $x = array();
    $y = array();
    foreach ( $common_keys as $iso3 => $v ) {
        $x[] = $index_scores[ $iso3 ];
        $y[] = $benchmark_scores[ $iso3 ];
    }
    return array(
        'n'   => count( $x ),
        'rho' => round( blomstra_spearman_correlation( $x, $y ), 3 ),
    );
}

// ─────────────────────────────────────────────────────────────────
// 9. PARTIAL-RANK COMPOSITE PROJECTION
// ─────────────────────────────────────────────────────────────────

function blomstra_project_partial_rank_composite( $known_pillar_values, $missing_pillar, $injected_values_by_point, $pillar_weights ) {
    $total_weight = array_sum( $pillar_weights );
    if ( $total_weight <= 0 ) {
        return array();
    }
    $known_weighted_sum = 0.0;
    foreach ( $known_pillar_values as $pname => $pvalue ) {
        if ( $pname === $missing_pillar || ! is_numeric( $pvalue ) ) {
            continue;
        }
        $known_weighted_sum += $pvalue * ( $pillar_weights[ $pname ] ?? 0 );
    }
    $missing_weight = $pillar_weights[ $missing_pillar ] ?? 0;
    $hypothetical_composites = array();
    foreach ( $injected_values_by_point as $point => $injected_value ) {
        $hypothetical_composites[ $point ] = ( $known_weighted_sum + ( $injected_value * $missing_weight ) ) / $total_weight;
    }
    return $hypothetical_composites;
}

// ─────────────────────────────────────────────────────────────────
// 10. RANK DISPLAY HELPERS
// ─────────────────────────────────────────────────────────────────

function blomstra_build_full_rank_display( $rank ) {
    return array(
        'is_definitive'    => true,
        'best_estimate'    => (int) $rank,
        'range_80_low'     => (int) $rank,
        'range_80_high'    => (int) $rank,
        'theoretical_low'  => (int) $rank,
        'theoretical_high' => (int) $rank,
        'string_format'    => '#' . (int) $rank,
    );
}

function blomstra_build_partial_rank_display( $ranks_by_injection ) {
    $range_80_low  = $ranks_by_injection[10] ?? null;
    $range_80_high = $ranks_by_injection[90] ?? null;
    $theoretical_low  = $ranks_by_injection[0] ?? null;
    $theoretical_high = $ranks_by_injection[100] ?? null;
    $best_estimate = $ranks_by_injection[50] ?? null;
    return array(
        'is_definitive'    => false,
        'best_estimate'    => $best_estimate,
        'range_80_low'     => $range_80_low,
        'range_80_high'    => $range_80_high,
        'theoretical_low'  => $theoretical_low,
        'theoretical_high' => $theoretical_high,
        'string_format'    => ( $range_80_low && $range_80_high )
            ? '#' . $range_80_low . '–' . $range_80_high . '*'
            : '#?–?*',
    );
}


// ─────────────────────────────────────────────────────────────────
// 11. DATA QUALITY INDEX (DQI)
// ─────────────────────────────────────────────────────────────────

function blomstra_compute_dqi( $data_year, $current_year, $max_lag ) {
    if ( $data_year === null || $data_year <= 0 ) {
        return null;
    }
    $lag = $current_year - $data_year;
    if ( $lag < 0 ) {
        return 100.0;
    }
    if ( $lag >= $max_lag ) {
        return 0.0;
    }
    return round( ( 1 - ( $lag / $max_lag ) ) * 100, 1 );
}

function blomstra_compute_composite_dqi( $pillar_data ) {
    $total_weight = 0;
    $weighted_sum = 0;
    foreach ( $pillar_data as $pillar ) {
        if ( $pillar['dqi'] !== null ) {
            $weighted_sum += $pillar['dqi'] * $pillar['weight'];
            $total_weight += $pillar['weight'];
        }
    }
    if ( $total_weight <= 0 ) {
        return null;
    }
    return round( $weighted_sum / $total_weight, 1 );
}

// ─────────────────────────────────────────────────────────────────
// 12. GENERIC INDEX BUILDER ORCHESTRATOR – CORRECTED
// ─────────────────────────────────────────────────────────────────

/**
 * Build a composite index using a generic orchestrator.
 *
 * @since 1.4.1
 * @param array $config {
 *     Index configuration.
 *
 *     @type string $index_slug                Unique slug (e.g., 'sivi', 'seri').
 *     @type array  $pillar_keys              Array of pillar keys.
 *     @type array  $pillar_weights           Associative: pillar => weight (must sum to 100).
 *     @type int    $min_pillars_required     Minimum pillars to score a country.
 *     @type array  $get_raw_values           Associative: pillar => callable( $iso3_list ) returning [iso3 => float].
 *     @type array  $winsorization            Optional: pillar => float (0.0 = none).
 *     @type array  $post_percentile_transform Optional: pillar => callable( $percentile ) returning transformed value.
 *     @type callable $get_data_years         Optional: callable( $iso3_list ) returning [iso3 => [pillar => year]].
 *     @type callable $is_landlocked_check    Optional: function( $iso3 ) : bool.
 *     @type array  $dqi_config               Optional: pillar => max_lag (years).
 *     @type callable $benchmark_getter       Optional: callable returning [iso3 => score].
 *     @type bool   $sensitivity_enabled      Default true.
 *     @type int    $lock_ttl                 Lock TTL in seconds.
 *     @type string $composite_field          Field name for composite score (default 'composite_score').
 *     @type bool   $skip_snapshot            If true, skip saving snapshot (caller handles it).
 * }
 *
 * @param string $context  'manual', 'cron', 'historical', or 'scenario'.
 * @return array|WP_Error  The composite index data, or error.
 */
function blomstra_build_index_composite( $config, $context = 'manual' ) {
    // ─── Validation ────────────────────────────────────────────────
    if ( empty( $config['index_slug'] ) || empty( $config['pillar_keys'] ) || empty( $config['pillar_weights'] ) ) {
        return new WP_Error( 'missing_config', 'Index slug, pillar keys, and weights are required.' );
    }

    $slug = sanitize_key( $config['index_slug'] );
    $pillar_keys = $config['pillar_keys'];
    $pillar_weights = $config['pillar_weights'];
    $min_pillars_required = $config['min_pillars_required'] ?? 2;
    $get_raw_values = $config['get_raw_values'] ?? array();
    $winsorization = $config['winsorization'] ?? array();
    $post_percentile_transform = $config['post_percentile_transform'] ?? array();
    $get_data_years = $config['get_data_years'] ?? null;
    $is_landlocked = $config['is_landlocked_check'] ?? 'blomstra_is_landlocked';
    $benchmark_getter = $config['benchmark_getter'] ?? null;
    $sensitivity_enabled = $config['sensitivity_enabled'] ?? true;
    $lock_ttl = $config['lock_ttl'] ?? 30 * MINUTE_IN_SECONDS;
    $dqi_config = $config['dqi_config'] ?? array();
    $composite_field = $config['composite_field'] ?? 'composite_score';
    $skip_snapshot = $config['skip_snapshot'] ?? false;

    // Validate that every pillar has a weight
    foreach ( $pillar_keys as $key ) {
        if ( ! isset( $pillar_weights[ $key ] ) ) {
            return new WP_Error( 'missing_weight', "Pillar '$key' has no weight defined." );
        }
    }

    // Validate callbacks
    foreach ( $pillar_keys as $key ) {
        if ( ! isset( $get_raw_values[ $key ] ) || ! is_callable( $get_raw_values[ $key ] ) ) {
            return new WP_Error( 'missing_fetcher', "No callable fetcher for pillar '$key'." );
        }
    }

    // ─── Lock ────────────────────────────────────────────────────────
    $lock_key = $slug . '_build_lock';
    $lock = get_transient( $lock_key );
    if ( $lock !== false && ( time() - (int) $lock ) < $lock_ttl ) {
        return new WP_Error( 'build_in_progress', 'Build already in progress. Please wait.' );
    }
    set_transient( $lock_key, time(), $lock_ttl );

    $lock_cleared = false;
    $shutdown = function() use ( $lock_key, &$lock_cleared ) {
        if ( ! $lock_cleared ) {
            delete_transient( $lock_key );
            error_log( "{$slug}: Build lock cleared by shutdown handler." );
        }
    };
    register_shutdown_function( $shutdown );

    // Initialize output_meta array (fixes undefined variable notice)
    $output_meta = array();

    try {
        if ( function_exists( 'blomstra_update_cron_status' ) ) {
            blomstra_update_cron_status( $slug, 'running', "{$slug} build started." );
        }

        // ─── Fetch Raw Values ────────────────────────────────────────
        $countries = function_exists( 'blomstra_get_global_country_list' ) 
            ? blomstra_get_global_country_list() 
            : array();
        if ( empty( $countries ) ) {
            throw new Exception( 'No country list available.' );
        }
        $all_iso3 = array_keys( $countries );

        $raw_values = array();
        foreach ( $pillar_keys as $key ) {
            $fetcher = $get_raw_values[ $key ];
            $raw = $fetcher( $all_iso3 );
            if ( ! is_array( $raw ) ) {
                throw new Exception( "Fetcher for pillar '$key' did not return an array." );
            }
            $raw_values[ $key ] = $raw;
        }

        // ─── Compute Percentiles (with winsorization) ──────────────
        $percentiles = array();
        foreach ( $pillar_keys as $key ) {
            $winsor = $winsorization[ $key ] ?? 0.0;
            $vals = $raw_values[ $key ];
            $numeric = array_filter( $vals, 'is_numeric' );
            if ( ! empty( $numeric ) ) {
                $percentiles[ $key ] = blomstra_compute_percentile_ranks_safe( $numeric, $winsor );
            } else {
                $percentiles[ $key ] = array();
            }
        }

        // ─── Apply Post-Percentile Transforms ──────────────────────
        $transformed_percentiles = array();
        foreach ( $pillar_keys as $key ) {
            if ( isset( $post_percentile_transform[ $key ] ) && is_callable( $post_percentile_transform[ $key ] ) ) {
                $transformed = array();
                foreach ( $percentiles[ $key ] as $iso3 => $pct ) {
                    $transformed[ $iso3 ] = (float) call_user_func( $post_percentile_transform[ $key ], $pct );
                }
                $transformed_percentiles[ $key ] = $transformed;
            } else {
                $transformed_percentiles[ $key ] = $percentiles[ $key ];
            }
        }

        // ─── Compute Composite Scores ───────────────────────────────
        $results = array();
        $excluded = array();
        $all_pillar_keys = $pillar_keys;

        foreach ( $all_iso3 as $iso3 ) {
            $present = array();
            foreach ( $pillar_keys as $key ) {
                if ( isset( $transformed_percentiles[ $key ][ $iso3 ] ) ) {
                    $present[ $key ] = array(
                        'value'  => $transformed_percentiles[ $key ][ $iso3 ],
                        'weight' => $pillar_weights[ $key ],
                    );
                }
            }

            $pillars_present = count( $present );
            $missing_pillars = array_values( array_diff( $all_pillar_keys, array_keys( $present ) ) );

            if ( $pillars_present < $min_pillars_required ) {
                $excluded[ $iso3 ] = array(
                    'reason'          => "Fewer than {$min_pillars_required} pillars have real data — not scored.",
                    'pillars_present' => $pillars_present,
                    'pillars_missing' => $missing_pillars,
                    'name'            => $countries[ $iso3 ] ?? $iso3,
                );
                continue;
            }

            $score_sum  = 0;
            $weight_sum = 0;
            foreach ( $present as $key => $pillar ) {
                $score_sum  += $pillar['value'] * $pillar['weight'];
                $weight_sum += $pillar['weight'];
            }
            $composite_score = round( $score_sum / $weight_sum, 1 );
            $coverage_type = ( $pillars_present >= count( $all_pillar_keys ) ) ? 'full' : 'partial';

            $results[ $iso3 ] = array(
                'composite_score' => $composite_score,
                'coverage_type'   => $coverage_type,
                'pillars_used'    => $pillars_present,
                'pillars_missing' => $missing_pillars,
                'last_updated'    => current_time( 'mysql' ),
            );

            foreach ( $pillar_keys as $key ) {
                $results[ $iso3 ][ $key . '_percentile' ] = $transformed_percentiles[ $key ][ $iso3 ] ?? null;
                $results[ $iso3 ][ $key . '_raw' ] = $raw_values[ $key ][ $iso3 ] ?? null;
                $results[ $iso3 ][ $key . '_raw_percentile' ] = $percentiles[ $key ][ $iso3 ] ?? null;
            }
        }

        // ─── Rank Assignment ────────────────────────────────────────
        $full_composites = array();
        foreach ( $results as $iso3 => $row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $full_composites[] = $row['composite_score'];
            }
        }
        sort( $full_composites );

        foreach ( $results as $iso3 => &$row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $rank = 1;
                foreach ( $full_composites as $full_score ) {
                    if ( $row['composite_score'] < $full_score ) {
                        $rank++;
                    }
                }
                $row['rank'] = $rank;
                $row['rank_display'] = blomstra_build_full_rank_display( $rank );
            }
        }
        unset( $row );

        // ─── Partial Ranks (Projection) ────────────────────────────
        if ( count( $all_pillar_keys ) >= 3 && $min_pillars_required >= count( $all_pillar_keys ) - 1 ) {
            foreach ( $results as $iso3 => &$row ) {
                if ( $row['coverage_type'] !== 'partial' ) {
                    continue;
                }
                $missing_pillar = $row['pillars_missing'][0] ?? null;
                if ( ! $missing_pillar || ! isset( $pillar_weights[ $missing_pillar ] ) ) {
                    continue;
                }

                $known_pillars = array();
                foreach ( $pillar_weights as $pname => $pweight ) {
                    if ( $pname === $missing_pillar ) {
                        continue;
                    }
                    $known_pillars[ $pname ] = $row[ $pname . '_percentile' ] ?? 0;
                }

                $injected_values_by_point = array();
                foreach ( array( 0, 10, 50, 90, 100 ) as $point ) {
                    $injected_values_by_point[ $point ] = $point;
                }

                $hypothetical_composites = blomstra_project_partial_rank_composite(
                    $known_pillars,
                    $missing_pillar,
                    $injected_values_by_point,
                    $pillar_weights
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

        // ─── Data Quality & Measurement Flags ──────────────────────
        $current_year = (int) current_time( 'Y' );
        
        $data_years = array();
        if ( $get_data_years && is_callable( $get_data_years ) ) {
            $data_years = call_user_func( $get_data_years, $all_iso3 );
            if ( ! is_array( $data_years ) ) {
                $data_years = array();
            }
        }

        foreach ( $results as $iso3 => &$row ) {
            $pillar_data_for_dqi = array();
            foreach ( $pillar_keys as $key ) {
                $year = isset( $data_years[ $iso3 ][ $key ] ) ? (int) $data_years[ $iso3 ][ $key ] : null;
                $row[ 'data_year_' . $key ] = $year;
                
                $max_lag = $dqi_config[ $key ] ?? 3;
                $dqi = blomstra_compute_dqi( $year, $current_year, $max_lag );
                $row[ 'dqi_' . $key ] = $dqi;
                
                $pillar_data_for_dqi[] = array( 'dqi' => $dqi, 'weight' => $pillar_weights[ $key ] );
            }
            
            $row['composite_dqi'] = blomstra_compute_composite_dqi( $pillar_data_for_dqi );
            
            $vintage_parts = array();
            foreach ( $pillar_keys as $key ) {
                if ( isset( $row[ 'data_year_' . $key ] ) && $row[ 'data_year_' . $key ] !== null ) {
                    $vintage_parts[] = ucfirst( $key ) . ': ' . $row[ 'data_year_' . $key ];
                }
            }
            $row['vintage_summary'] = ! empty( $vintage_parts ) ? implode( ', ', $vintage_parts ) : 'No data';
        }
        unset( $row );

        // ─── Sensitivity Interval ──────────────────────────────────
        if ( $sensitivity_enabled && function_exists( 'blomstra_bootstrap_ci' ) ) {
            $pillar_values_by_country = array();
            foreach ( $results as $iso3 => $row ) {
                if ( $row['coverage_type'] === 'full' ) {
                    $pillar_values = array();
                    foreach ( $pillar_keys as $key ) {
                        $pillar_values[ $key ] = $row[ $key . '_percentile' ];
                    }
                    $pillar_values_by_country[ $iso3 ] = $pillar_values;
                }
            }
            if ( ! empty( $pillar_values_by_country ) ) {
                $sensitivity = blomstra_bootstrap_ci( $pillar_values_by_country, $pillar_weights, 1000, 0.95 );
                if ( $sensitivity ) {
                    foreach ( $sensitivity as $iso3 => $interval ) {
                        if ( isset( $results[ $iso3 ] ) ) {
                            $results[ $iso3 ]['sensitivity_interval'] = $interval;
                        }
                    }
                }
            }
        }

        // ─── Benchmark Correlation ──────────────────────────────────
        if ( $benchmark_getter && is_callable( $benchmark_getter ) && function_exists( 'blomstra_benchmark_correlate' ) ) {
            $benchmark_scores = $benchmark_getter();
            if ( is_array( $benchmark_scores ) ) {
                $index_scores = array();
                foreach ( $results as $iso3 => $row ) {
                    if ( isset( $row['composite_score'] ) && is_numeric( $row['composite_score'] ) ) {
                        $index_scores[ $iso3 ] = (float) $row['composite_score'];
                    }
                }
                $corr = blomstra_benchmark_correlate( $index_scores, $benchmark_scores );
                if ( $corr ) {
                    $output_meta['benchmark_correlation'] = $corr;
                }
            }
        }

        // ─── Build Output ──────────────────────────────────────────
        $country_output = array();
        foreach ( $results as $iso3 => $row ) {
            $entry = array(
                'iso3'             => $iso3,
                'name'             => $countries[ $iso3 ] ?? $iso3,
                'composite_score'  => $row['composite_score'],
                'coverage'         => $row['coverage_type'],
                'pillars_missing'  => $row['pillars_missing'],
                'rank_display'     => $row['rank_display'] ?? null,
                'last_updated'     => $row['last_updated'],
                'pillars'          => array(),
            );
            foreach ( $pillar_keys as $key ) {
                $entry['pillars'][ $key ] = array(
                    'score'  => $row[ $key . '_percentile' ] ?? null,
                    'weight' => $pillar_weights[ $key ],
                    'raw'    => $row[ $key . '_raw' ] ?? null,
                    'raw_percentile' => $row[ $key . '_raw_percentile' ] ?? null,
                );
                $entry[ $key . '_percentile' ] = $row[ $key . '_percentile' ] ?? null;
                $entry[ $key . '_raw' ] = $row[ $key . '_raw' ] ?? null;
                $entry[ 'data_year_' . $key ] = $row[ 'data_year_' . $key ] ?? null;
                $entry[ 'dqi_' . $key ] = $row[ 'dqi_' . $key ] ?? null;
            }
            $entry['composite_dqi'] = $row['composite_dqi'] ?? null;
            $entry['vintage_summary'] = $row['vintage_summary'] ?? 'No data';
            if ( isset( $row['sensitivity_interval'] ) ) {
                $entry['sensitivity_interval'] = $row['sensitivity_interval'];
            }
            $country_output[ $iso3 ] = $entry;
        }

        // ─── FIXED BUG 1: Data is already under $composite_field, no rename needed ───
        $output = array(
            'version'         => '1.0',
            'last_updated'    => current_time( 'mysql' ),
            'total_countries' => count( $country_output ),
            'excluded'        => count( $excluded ),
            'excluded_detail' => $excluded,
            'weights'         => $pillar_weights,
            $composite_field  => $country_output,
            '_meta' => array(
                'built_at'            => current_time( 'mysql' ),
                'source'              => $context,
                'status'              => 'valid',
                'methodology_version' => '1.0',
            ),
        );

        // Add benchmark correlation if present
        if ( isset( $output_meta['benchmark_correlation'] ) ) {
            $output['_meta']['benchmark_correlation'] = $output_meta['benchmark_correlation'];
        }

        // ─── FIXED BUG 3: Only promote for manual/cron, NOT historical ───
        $should_promote = in_array( $context, [ 'manual', 'cron' ], true );
        
        if ( $should_promote ) {
            // Use the SIVI-compatible staging key (matches flush button)
            $staging_key = $slug . '_composite_index_staging';
            $production_key = $slug . '_composite_index';

            update_option( $staging_key, $output, false );

            $old_composite = get_option( $production_key, null );
            $should_keep_old = false;
            if ( $old_composite && ! empty( $old_composite[ $composite_field ] ) ) {
                $prev_count = count( $old_composite[ $composite_field ] );
                $new_count = count( $output[ $composite_field ] );
                if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
                    error_log( "{$slug}: Automated build failed – new count ({$new_count}) vs previous ({$prev_count}). Keeping old composite." );
                    set_transient( $slug . '_auto_build_failed', 'yes', DAY_IN_SECONDS );
                    $should_keep_old = true;
                }
            }

            if ( $should_keep_old && $old_composite ) {
                delete_option( $staging_key );
                if ( function_exists( 'blomstra_update_cron_status' ) ) {
                    blomstra_update_cron_status( $slug, 'error', "Build failed – coverage too low. Old composite preserved." );
                }
                delete_transient( $lock_key );
                $lock_cleared = true;
                return $old_composite;
            }

            // ─── Fire Alerts ─────────────────────────────────────────
            if ( function_exists( 'blomstra_fire_index_alerts' ) && $old_composite && ! empty( $old_composite[ $composite_field ] ) ) {
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
                $alert_count = blomstra_fire_index_alerts( $slug, $output[ $composite_field ], $old_composite[ $composite_field ], $new_meta, $old_meta );
                error_log( "{$slug}: Alerts fired with {$alert_count} changes detected." );
            }

            // ─── Save Composite ──────────────────────────────────────
            update_option( $production_key, $output, false );
            delete_option( $staging_key );

            // ─── Save Snapshot (only if caller hasn't disabled it) ───
            if ( ! $skip_snapshot && function_exists( 'blomstra_index_snapshot_save' ) ) {
                $snap = array();
                foreach ( $output[ $composite_field ] as $iso3 => $data ) {
                    $snap[ $iso3 ] = array(
                        'composite_score' => $data['composite_score'] ?? null,
                        'rank'            => $data['rank_display']['best_estimate'] ?? null,
                        'coverage_type'   => $data['coverage'] ?? 'full',
                    );
                    foreach ( $pillar_keys as $key ) {
                        $snap[ $iso3 ][ $key ] = $data[ $key . '_percentile' ] ?? null;
                    }
                }
                blomstra_index_snapshot_save( $slug, $snap );
            }

            if ( function_exists( 'blomstra_update_cron_status' ) ) {
                blomstra_update_cron_status( $slug, 'success', "Build completed: " . count( $output[ $composite_field ] ) . " countries scored.", count( $output[ $composite_field ] ) );
            }
        }

        delete_transient( $lock_key );
        $lock_cleared = true;
        return $output;

    } catch ( Exception $e ) {
        delete_transient( $lock_key );
        $lock_cleared = true;
        if ( function_exists( 'blomstra_update_cron_status' ) ) {
            blomstra_update_cron_status( $slug, 'error', "Build failed: " . $e->getMessage() );
        }
        error_log( "{$slug} build error: " . $e->getMessage() );
        return new WP_Error( 'build_failed', $e->getMessage() );
    }
}
