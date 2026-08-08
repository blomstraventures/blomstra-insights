<?php
/**
 * Blomstra Shared Index Utilities — Data Integrity & Processing Layer
 *
 * Provides safe numeric handling, timeseries sanitization, per-indicator source
 * tracking, validated fallback merging, and winsorized percentile computation.
 *
 * @package Blomstra\Insights\Shared
 * @since   1.0.0
 * @version 1.0.0
 *
 * USAGE CONTRACT:
 *   1. Never use empty() on numeric values — use blomstra_safe_numeric()
 *   2. Never assume API returns chronological order — use blomstra_sanitize_timeseries()
 *   3. Never merge fallback data without tracking — use blomstra_merge_with_fallback()
 *   4. Never let pillar defs and engine thresholds drift — use blomstra_validate_pillar_thresholds()
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// 1. SAFE DATA EXTRACTION
// ─────────────────────────────────────────────────────────────────

/**
 * Safely extract a numeric value.
 *
 * Replaces the dangerous pattern: if ( empty( $val ) ) { // misses 0.0 }
 *
 * @param mixed $value   Raw value from API or cache.
 * @param mixed $default Value to return if null, non-numeric, or not set.
 * @return float|null
 */
function blomstra_safe_numeric( $value, $default = null ) {
    if ( isset( $value ) && is_numeric( $value ) ) {
        return (float) $value;
    }
    return $default;
}

/**
 * Safely extract a non-empty string.
 *
 * @param mixed $value   Raw value.
 * @param mixed $default Value to return if null, not string, or empty string.
 * @return string|null
 */
function blomstra_safe_string( $value, $default = null ) {
    if ( isset( $value ) && is_string( $value ) && $value !== '' ) {
        return $value;
    }
    return $default;
}

/**
 * Safely retrieve a nested array value.
 *
 * @param array  $array   The array to search.
 * @param string $key     Top-level key.
 * @param string $subkey  Optional second-level key.
 * @param mixed  $default Default if path missing.
 * @return mixed
 */
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

/**
 * Sanitize a year => value timeseries.
 *
 * Removes non-numeric values, sorts chronologically (oldest → newest),
 * and enforces a minimum observation span.
 *
 * @param array $data      Raw timeseries [ year => value ].
 * @param int   $min_obs   Minimum numeric observations required.
 * @param int   $min_span  Minimum year span (newest - oldest) required.
 * @return array           Clean, sorted timeseries. Empty if constraints fail.
 */
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

/**
 * Extract boundary values and metadata from a sanitized timeseries.
 *
 * @param array $data  Sanitized timeseries.
 * @return array|null  [ oldest_year, newest_year, oldest_value, newest_value, span, observations ]
 */
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

/**
 * Compute Compound Annual Growth Rate (CAGR) from a timeseries.
 *
 * Formula: ((newest / oldest) ^ (1 / span)) - 1
 *
 * @param array $data  Sanitized timeseries.
 * @return float|null  CAGR as percentage, or null if invalid.
 */
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

/**
 * Compute CAGR from explicit values.
 *
 * @param float $oldest  Earliest value.
 * @param float $newest  Latest value.
 * @param int   $span    Year span (must be >= 1).
 * @return float|null    CAGR as percentage.
 */
function blomstra_compute_cagr_from_values( $oldest, $newest, $span ) {
    if ( $span < 1 || $oldest == 0 ) {
        return null;
    }
    return ( pow( $newest / $oldest, 1 / $span ) - 1 ) * 100;
}

/**
 * Compute standard deviation of a numeric array.
 * Replacement for geri_compute_stddev with population vs sample clarity.
 *
 * @param array $values  Numeric values.
 * @param bool  $sample  Use sample stddev (n-1) instead of population (n).
 * @return float|null
 */
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

/**
 * Track the provenance of every indicator value.
 *
 * @param array  $sources    Reference to source tracking array.
 * @param string $iso3       Country code.
 * @param string $indicator  Indicator name (e.g. 'gov_debt').
 * @param string $source     Source label (e.g. 'WB_WDI', 'IMF_WEO').
 * @param string $scope      Optional scope label (e.g. 'central_gov', 'general_gov').
 * @param string $year       Optional data vintage year.
 */
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

/**
 * Determine the dominant source for a pillar.
 *
 * @param array  $sources     Source tracking array.
 * @param string $iso3        Country code.
 * @param array  $indicators  List of indicator names in pillar.
 * @return array|null        [ primary_source, source_breakdown, scope_mixed ]
 */
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

/**
 * Validate that pillar definitions match engine thresholds.
 *
 * Logs warnings via error_log() and returns mismatch details.
 *
 * @param array $defs    From index_get_pillar_defs().
 * @param array $engine  From index_get_pillar_weights() or builder rules.
 * @return array          [ 'valid' => bool, 'mismatches' => [...] ]
 */
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

        // Validate that indicator weights sum to ~100%
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

/**
 * Compute percentile ranks with optional winsorization.
 *
 * Uses the "high value = high percentile" convention.
 * Ties receive the average rank.
 *
 * @param array $values      [ iso3 => numeric_value ].
 * @param float $winsor_pct  Winsorization level (0.01 = 1st/99th percentile). 0 = none.
 * @return array             [ iso3 => percentile_0_to_100 ]
 */
function blomstra_compute_percentile_ranks_safe( $values, $winsor_pct = 0.0 ) {
    if ( empty( $values ) ) {
        return array();
    }

    // Filter non-numeric
    $clean = array_filter( $values, 'is_numeric' );
    $n = count( $clean );
    if ( $n === 0 ) {
        return array();
    }

    // Winsorize if requested and sufficient data
    if ( $winsor_pct > 0 && $n >= 10 ) {
        asort( $clean, SORT_NUMERIC );
        $sorted_vals = array_values( $clean );
        $cutoff = max( 1, (int) round( $n * $winsor_pct ) );
        $lower_bound = $sorted_vals[ $cutoff - 1 ];
        $upper_bound = $sorted_vals[ $n - $cutoff ];

        foreach ( $clean as $iso3 => $val ) {
            if ( $val < $lower_bound ) {
                $clean[ $iso3 ] = $lower_bound;
            } elseif ( $val > $upper_bound ) {
                $clean[ $iso3 ] = $upper_bound;
            }
        }
    }

    // Sort ascending for ranking
    asort( $clean, SORT_NUMERIC );
    $sorted = array_keys( $clean );

    // Assign ranks (handle ties with average rank)
    $ranks = array();
    $i = 1;
    while ( $i <= $n ) {
        $iso3 = $sorted[ $i - 1 ];
        $val  = $clean[ $iso3 ];

        // Find all ties
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

    // Convert to percentile (0-100)
    $percentiles = array();
    foreach ( $ranks as $iso3 => $rank ) {
        $percentiles[ $iso3 ] = ( $rank / $n ) * 100;
    }

    return $percentiles;
}

// ─────────────────────────────────────────────────────────────────
// 6. FALLBACK MERGING WITH PROVENANCE
// ─────────────────────────────────────────────────────────────────

/**
 * Merge primary and fallback datasets with full provenance tracking.
 *
 * @param array  $primary         [ iso3 => value ] primary data.
 * @param array  $fallback        [ iso3 => value ] fallback data.
 * @param array  $sources         Reference to source tracking array.
 * @param string $indicator       Indicator name.
 * @param string $primary_label   Source label for primary data.
 * @param string $fallback_label  Source label for fallback data.
 * @param string $primary_scope   Optional scope for primary.
 * @param string $fallback_scope  Optional scope for fallback.
 * @return array                  Merged [ iso3 => value ].
 */
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
            continue; // Primary already won.
        }
        $num = blomstra_safe_numeric( $value );
        if ( $num !== null ) {
            $result[ $iso3 ] = $num;
            blomstra_track_source( $sources, $iso3, $indicator, $fallback_label, $fallback_scope );
        }
    }

    return $result;
}

/**
 * Merge multiple fallback layers in priority order.
 *
 * @param array  $layers     Array of [ 'data' => [...], 'label' => '...', 'scope' => '...' ].
 * @param array  $sources    Reference to source tracking array.
 * @param string $indicator  Indicator name.
 * @return array             Merged [ iso3 => value ].
 */
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

/**
 * Generate a data quality flag for a country-indicator pair.
 *
 * @param array  $sources     Source tracking array.
 * @param string $iso3        Country code.
 * @param string $indicator   Indicator name.
 * @param int    $current_year Expected current year.
 * @return array              Quality metadata.
 */
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

/**
 * Compute a composite data quality score for a country's pillar.
 *
 * @param array  $sources     Source tracking array.
 * @param string $iso3        Country code.
 * @param array  $indicators  Required indicators.
 * @param int    $current_year Expected current year.
 * @return array              [ coverage_pct, avg_staleness, quality_summary ].
 */
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
