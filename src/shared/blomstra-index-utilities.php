/**
 * Blomstra Shared Index Utilities — Data Integrity & Processing Layer
 *
 * Provides safe numeric handling, timeseries sanitization, per-indicator source
 * tracking, validated fallback merging, winsorized percentile computation,
 * and static country classification data.
 *
 * @package Blomstra\Insights\Shared
 * @since   1.0.0
 * @version 1.1.5  – Alert system moved to separate file (blomstra-alerts.php)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// 0. STATIC COUNTRY CLASSIFICATIONS
// ─────────────────────────────────────────────────────────────────

/**
 * List of all landlocked countries (44 countries).
 *
 * This list is used for maritime access calculations in the composite indices.
 * It includes both developing and developed landlocked states, as the geographic
 * fact of being landlocked applies regardless of income level.
 *
 * The list is manually maintained and was last verified against a reliable source
 * on the date set in BLOMSTRA_LANDLOCKED_SOURCE_DATE below.
 *
 * @see https://www.un.org/ohrlls/content/landlocked-developing-countries
 */

if ( ! defined( 'BLOMSTRA_LANDLOCKED_ISO3' ) ) {
    define( 'BLOMSTRA_LANDLOCKED_ISO3', array(
        'AFG', 'AND', 'ARM', 'AUT', 'AZE', 'BLR', 'BTN', 'BOL', 'BWA', 'BFA',
        'BDI', 'CAF', 'TCD', 'CZE', 'ETH', 'SWZ', 'HUN', 'KAZ', 'KGZ', 'LAO',
        'LSO', 'LIE', 'LUX', 'MWI', 'MLI', 'MDA', 'MNG', 'NPL', 'NER', 'MKD',
        'PRY', 'RWA', 'SMR', 'SRB', 'SVK', 'SSD', 'CHE', 'TJK', 'TKM', 'UGA',
        'UZB', 'VAT', 'ZMB', 'ZWE',
    ) );
}

/**
 * Source date of the hardcoded landlocked list (YYYY-MM-DD).
 * Update this manually when the list is verified against a reliable source.
 */
if ( ! defined( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE' ) ) {
    define( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE', '2026-08-17' );
}

/**
 * Check if a country is landlocked.
 *
 * Checks a stored override option first, then falls back to the hardcoded constant.
 *
 * @param string $iso3 3-letter ISO country code.
 * @return bool True if landlocked, false otherwise.
 */
function blomstra_is_landlocked( $iso3 ) {
    $iso3 = strtoupper( trim( $iso3 ) );
    // Check override first (admin‑updated list)
    $override = get_option( 'blomstra_landlocked_override', array() );
    if ( ! empty( $override['iso3s'] ) && is_array( $override['iso3s'] ) ) {
        return in_array( $iso3, $override['iso3s'], true );
    }
    return in_array( $iso3, BLOMSTRA_LANDLOCKED_ISO3, true );
}

/**
 * Check if a data pillar or the landlocked list is stale.
 *
 * @param string $pillar   Pillar key (e.g., 'wb_indicators', 'eia', 'hhi', 'maritime', 'imf', 'countries', 'reporters', 'landlocked', 'sivi').
 * @param int    $threshold Custom threshold in seconds (optional, uses per‑pillar defaults if omitted).
 * @return bool True if stale, false if fresh or unknown.
 */
function blomstra_is_stale( $pillar, $threshold = null ) {
    // Define default thresholds per pillar
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

    // Special handling for landlocked list
    if ( $pillar === 'landlocked' ) {
        $source_date = defined( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE' ) ? strtotime( BLOMSTRA_LANDLOCKED_SOURCE_DATE ) : 0;
        $threshold = $threshold ?: $defaults['landlocked'];
        return ( time() - $source_date ) > $threshold;
    }

    // For data pillars, read from cron status
    $cron_status = get_option( 'blomstra_cron_status', array() );
    if ( ! isset( $cron_status[ $pillar ] ) ) {
        // Never run – treat as stale
        return true;
    }

    // ─── FIX: Use 'last_success' if available, else 'last_attempt' ───
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
 * Cap values outside a symmetric winsorization threshold.
 *
 * @param array $values      [ key => numeric_value ] — should already be numeric-filtered.
 * @param float $winsor_pct  Winsorization level (0.01 = 1st/99th percentile). 0 = none.
 * @return array             Same keys, values capped where winsor_pct > 0 and n >= 10.
 */
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

    $clean = blomstra_winsorize( $clean, $winsor_pct );

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
        $percentiles[ $iso3 ] = ( ( $rank - 0.5 ) / $n ) * 100;
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

// ─────────────────────────────────────────────────────────────────
// 8. STATISTICAL / RESEARCH-CREDIBILITY LAYER (BMS-1.1.0)
// ─────────────────────────────────────────────────────────────────

/**
 * Spearman rank correlation with correct tied-value (fractional mid-rank)
 * handling.
 *
 * @since 1.1.0
 * @param array $x First series of values.
 * @param array $y Second series of values, same length/order as $x.
 * @return float Spearman's rho, or 0 if fewer than 2 paired observations.
 */
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

/**
 * Cronbach's alpha for a set of indicators measured across the same
 * countries. Standard measurement-theory reliability statistic.
 *
 * @since 1.1.0
 * @param array $indicator_matrix Array of indicators; each element is an
 *                                array of that indicator's values, one
 *                                per country, in the SAME country order
 *                                across all indicators.
 * @return float|null Alpha rounded to 3 decimals, or null if the matrix
 *                     is too small, not rectangular, or has zero total
 *                     variance.
 */
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
            return null; // not rectangular — caller must align countries first
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

/**
 * Weight-perturbation confidence interval for composite scores.
 *
 * IMPORTANT — methodological honesty note: this is a robustness/sensitivity
 * interval built by resampling the PILLAR WEIGHTS within +/-10% (not a
 * classical statistical bootstrap over indicator-level measurement error).
 * Label it as "95% weight-sensitivity interval", not "95% confidence interval".
 *
 * @since 1.1.0
 * @param array $pillar_values_by_country iso3 => array( pillar_name => percentile value ).
 * @param array $pillar_weights           pillar_name => weight.
 * @param int   $n_bootstrap              Number of resamples. Default 1000.
 * @param float $ci_level                 e.g. 0.95 for a 95% interval.
 * @return array|null iso3 => array( 'point', 'ci_low', 'ci_high' ), or null if too few countries.
 */
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
            $factor = 1 + ( wp_rand( -1000, 1000 ) / 10000 ); // +/-10%
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

/**
 * Spearman correlation between this index's scores and an external
 * comparator index's scores, over the countries both cover.
 *
 * @since 1.1.0
 * @param array $index_scores     iso3 => this index's score.
 * @param array $benchmark_scores iso3 => external comparator's score.
 * @return array|null array('n' => overlap count, 'rho' => Spearman's rho), or null if fewer than 5 overlapping countries.
 */
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
// 9. PARTIAL-RANK COMPOSITE PROJECTION (BMS-1.1.0)
// ─────────────────────────────────────────────────────────────────

/**
 * Computes the hypothetical composite score at each OECD/JRC injection
 * point for a partial-coverage country.
 *
 * @since 1.1.0
 * @param array  $known_pillar_values      pillar_name => percentile value (0-100), for every pillar the country has EXCEPT $missing_pillar.
 * @param string $missing_pillar           Name of the pillar being projected.
 * @param array  $injected_values_by_point injection_point (0,10,50,90,100) => hypothetical value (0-100) for the missing pillar at that point.
 * @param array  $pillar_weights           pillar_name => weight, for ALL pillars in the index (including $missing_pillar).
 * @return array injection_point => hypothetical composite score, on the SAME 0-100 scale as the real composite_score. Empty array if weights are invalid.
 */
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
// 10. RANK DISPLAY HELPERS (BMS-1.1.0)
// ─────────────────────────────────────────────────────────────────

/**
 * Build the rank_display structure for a full‑coverage country.
 *
 * @param int $rank Definitive rank (1 = most vulnerable).
 * @return array
 */
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

/**
 * Build the rank_display structure for a partial‑coverage country.
 *
 * @param array $ranks_by_injection [0,10,50,90,100] => rank at each injection point.
 * @return array
 */
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
