/**
 * Blomstra Geoeconomic Risk Index (GERI) — WordPress-native pipeline.
 * Version: 2.0.0 — Production architecture rebuild
 *
 * v2.0.0 is a full rewrite against the CII reference pattern
 * (docs/05-index-template.md), replacing v1.7's standalone
 * implementation. BREAKING CHANGES from v1.7:
 *
 *   - Country-name keys → ISO3 keys throughout (v1.7 keyed everything
 *     by World Bank's country-name string, which is fragile across
 *     endpoints; every other Blomstra index uses ISO3).
 *   - Linear/log-curve normalization → percentile-rank normalization
 *     (OECD/JRC Handbook, Nardo et al.), matching CII. Uses the
 *     shared blomstra_compute_percentile_ranks() in Reference Data.
 *   - 2 pillars (Governance, Macro Stability), hard 2-of-2 requirement,
 *     no partial coverage →
 *     4 pillars (+ External Vulnerability, + Fiscal Stress), each
 *     25% weighted. GERI_MIN_PILLARS_REQUIRED = 3 of 4, so "Partial
 *     Index" always means exactly one pillar missing — the same
 *     invariant CII's 3-pillar/min-2 structure has, so the existing
 *     OECD/JRC injection-simulation rank-range logic (now shared, see
 *     blomstra_build_partial_rank_display() in Reference Data) applies
 *     unmodified. Countries with ≤2 of 4 pillars are excluded outright
 *     — no imputation, matching v1.7's stated philosophy, just
 *     extended from 2 pillars to 4.
 *   - Composite is now risk-oriented throughout: every pillar reports
 *     a "_risk_percentile" (higher = more risk) computed directly via
 *     per-indicator inversion, rather than v1.7's "average goodness,
 *     invert once at the very end" (100 - composite). Avoids a
 *     double-inversion bug class and matches the index's actual name.
 *   - Raw World Bank fetch centralized to Reference Data
 *     (blomstra_fetch_wb_indicator_batch(), added in v2.4) as the
 *     primary path, each pillar dispatcher falling back to a
 *     self-contained direct-API implementation on failure/empty —
 *     same primary+fallback shape as CII's Maritime/HHI/EIA.
 *   - Full API contract compliance (docs/03-api-contract.md):
 *     rank_display object on every row (Full and Partial alike),
 *     weights/global_averages_informational_only/_meta echoed in the
 *     top-level payload, {pillar}_source on every pillar (fixing
 *     CII's own known Energy-source asymmetry rather than repeating
 *     it), snapshot history via blomstra_index_snapshot_save('geri', ...).
 *   - Admin page moved from WP's built-in Tools menu to the shared
 *     "Blomstra Insights Tools" top-level menu (admin.php?page=...),
 *     matching CII's own prior migration.
 *   - Daily cron (central_cached only, build lock, dual freshness
 *     signal) replaces v1.7's simple weekly cron with no lock/health
 *     tracking.
 *
 * Indicator provenance note (data source = "WB WDI, IMF" per the
 * portfolio spec): all 9 locked indicators are delivered through the
 * World Bank API (WGI source=3 for governance; standard WDI for the
 * other 6). The external-debt and fiscal series WDI serves are
 * themselves compiled by WB from IMF-reported statistics, but there is
 * no separate IMF API call in this version. A genuinely separate IMF
 * integration (e.g. WEO fiscal forecasts, COFER reserve composition —
 * data WDI doesn't mirror) is a real future addition, not implemented
 * here.
 *
 * @package Blomstra
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// CONSTANTS & CONFIGURATION
// ═══════════════════════════════════════════════════════════════════

if ( ! defined( 'GERI_VERSION' ) )              define( 'GERI_VERSION', '2.0.0' );
if ( ! defined( 'GERI_WEIGHT_GOVERNANCE' ) )    define( 'GERI_WEIGHT_GOVERNANCE', 0.25 );
if ( ! defined( 'GERI_WEIGHT_MACRO' ) )         define( 'GERI_WEIGHT_MACRO', 0.25 );
if ( ! defined( 'GERI_WEIGHT_EXTERNAL_VULN' ) ) define( 'GERI_WEIGHT_EXTERNAL_VULN', 0.25 );
if ( ! defined( 'GERI_WEIGHT_FISCAL' ) )        define( 'GERI_WEIGHT_FISCAL', 0.25 );
// Guarantees "Partial" always means exactly one pillar missing — see
// this file's header note and 03-api-contract.md's structural warning
// on 4+ pillar indices.
if ( ! defined( 'GERI_MIN_PILLARS_REQUIRED' ) ) define( 'GERI_MIN_PILLARS_REQUIRED', 3 );

/**
 * Indicator registry, by pillar. Each sub-indicator declares:
 *   - code:   World Bank indicator code
 *   - source: WB source ID (3 = WGI) or null (standard WDI, mrnev=1)
 *   - invert: true  => raw value is "higher = better/less risk"
 *                       (risk_percentile = 100 - percentile(raw))
 *             false => raw value is "higher = worse/more risk" already
 *                       (risk_percentile = percentile(raw) as-is)
 *
 * This table is the single source of truth for both the fetch layer
 * and the composite builder's direction handling — do not hardcode
 * invert logic elsewhere.
 */
if ( ! defined( 'GERI_INDICATORS' ) ) {
    define( 'GERI_INDICATORS', array(
        'governance' => array(
            'min_required' => 2, // of 3
            'indicators'   => array(
                'rule_of_law'            => array( 'code' => 'GOV_WGI_RL.SC', 'source' => 3, 'invert' => true ),
                'control_of_corruption'  => array( 'code' => 'GOV_WGI_CC.SC', 'source' => 3, 'invert' => true ),
                'political_stability'    => array( 'code' => 'GOV_WGI_PV.SC', 'source' => 3, 'invert' => true ),
            ),
        ),
        'macro' => array(
            'min_required' => 2, // of 2 — both required, matches v1.7
            'indicators'   => array(
                'gni_growth' => array( 'code' => 'NY.GNP.MKTP.KD.ZG', 'source' => null, 'invert' => true ),
                'inflation'  => array( 'code' => 'FP.CPI.TOTL.ZG',    'source' => null, 'invert' => false ),
            ),
        ),
        'external_vulnerability' => array(
            'min_required' => 2, // of 3
            'indicators'   => array(
                'reserves_months'    => array( 'code' => 'FI.RES.TOTL.MO',   'source' => null, 'invert' => true ),
                'external_debt_gni'  => array( 'code' => 'DT.DOD.DECT.GN.ZS','source' => null, 'invert' => false ),
                'current_account_gdp'=> array( 'code' => 'BN.CAB.XOKA.GD.ZS','source' => null, 'invert' => true ),
            ),
        ),
        'fiscal_stress' => array(
            'min_required' => 2, // of 2 — both required; gov debt series has known coverage gaps, expect more Partial-via-fiscal-stress rows than other pillars
            'indicators'   => array(
                'gov_debt_gdp'   => array( 'code' => 'GC.DOD.TOTL.GD.ZS', 'source' => null, 'invert' => false ),
                'net_lending_gdp'=> array( 'code' => 'GC.NLD.TOTL.GD.ZS', 'source' => null, 'invert' => true ),
            ),
        ),
    ) );
}

// ═══════════════════════════════════════════════════════════════════
// PILLAR FALLBACK FETCH (self-contained, no Reference Data dependency)
// ═══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'geri_fetch_wb_indicator_fallback' ) ) {
    /**
     * Direct World Bank fetch, ISO3-keyed. Identical shape/behavior to
     * blomstra_fetch_wb_indicator_batch() in Reference Data, duplicated
     * here as GERI's own complete fallback so a pillar refresh still
     * works if the Reference Data snippet is inactive or its call fails
     * — same resilience reasoning as CII's per-pillar *_fallback()
     * functions.
     *
     * @param string   $code
     * @param int|null $source
     * @return array ISO3 => { value, year }
     */
    function geri_fetch_wb_indicator_fallback( $code, $source = null ) {
        $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
        $url .= $source ? "&source={$source}" : '&mrnev=1';

        $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'GERI/2.0.0-fallback' ) );
        if ( is_wp_error( $response ) ) {
            error_log( "GERI fallback fetch ({$code}): " . $response->get_error_message() );
            return array();
        }
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            error_log( "GERI fallback fetch ({$code}): HTTP " . wp_remote_retrieve_response_code( $response ) );
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
            if ( $iso3 && $val !== null ) {
                $out[ $iso3 ] = array( 'value' => floatval( $val ), 'year' => $row['date'] ?? null );
            }
        }
        return $out;
    }
}

// ═══════════════════════════════════════════════════════════════════
// GENERIC PILLAR DISPATCHER (shared logic for all 4 pillars)
// ═══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'geri_refresh_pillar' ) ) {
    /**
     * Fetch and store one pillar's raw sub-indicator data for every
     * real country, ISO3-keyed. Same primary+fallback dispatch shape
     * as CII's cii_refresh_{pillar}_pillar() functions:
     *
     *   - 'central'        call Reference Data, fail explicitly if empty
     *   - 'central_cached'   same, but never forces a live fetch (cron use)
     *   - 'api'              complete direct-API fallback, no central call
     *   - 'auto' (default)   central first, silent fallback to api
     *
     * @param string $pillar_key One of the top-level keys in GERI_INDICATORS.
     * @param string $source     'auto' | 'central' | 'central_cached' | 'api'
     * @return array ISO3 => { <sub_indicator>: value, <sub_indicator>_year:
     *               year, ..., source, last_updated } | array{error: string}
     */
    function geri_refresh_pillar( $pillar_key, $source = 'auto' ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $config = GERI_INDICATORS[ $pillar_key ] ?? null;
        if ( ! $config ) {
            return array( 'error' => "Unknown pillar key: {$pillar_key}" );
        }

        $real_countries = function_exists( 'blomstra_get_global_country_list' )
            ? blomstra_get_global_country_list()
            : array();
        if ( empty( $real_countries ) ) {
            return array( 'error' => 'Real country list unavailable (Reference Data inactive or its own fetch failed) — cannot filter aggregates.' );
        }

        $sub_data = array(); // sub_indicator_name => ISO3 => {value, year}
        foreach ( $config['indicators'] as $name => $ind ) {
            if ( $source === 'central' || $source === 'central_cached' || $source === 'auto' ) {
                $force  = ( $source === 'central' );
                $fetch  = function_exists( 'blomstra_fetch_wb_indicator_batch' )
                    ? blomstra_fetch_wb_indicator_batch( $ind['code'], $ind['source'], $force )
                    : array();
                if ( empty( $fetch ) && $source === 'central' ) {
                    return array( 'error' => "Central model returned nothing for {$name} ({$ind['code']}). Deliberately NOT falling back, so this test is meaningful." );
                }
                if ( empty( $fetch ) && $source === 'central_cached' ) {
                    return array( 'error' => "Central cache has no data yet for {$name} ({$ind['code']})." );
                }
                if ( empty( $fetch ) && $source === 'auto' ) {
                    $fetch = geri_fetch_wb_indicator_fallback( $ind['code'], $ind['source'] );
                }
            } elseif ( $source === 'api' ) {
                $fetch = geri_fetch_wb_indicator_fallback( $ind['code'], $ind['source'] );
                if ( empty( $fetch ) ) {
                    return array( 'error' => "Direct API fallback call itself failed for {$name} ({$ind['code']}) — see the PHP error log." );
                }
            } else {
                return array( 'error' => "Unknown source mode: {$source}" );
            }
            $sub_data[ $name ] = $fetch;
        }

        $results = array();
        foreach ( array_keys( $real_countries ) as $iso3 ) {
            $row = array();
            foreach ( $config['indicators'] as $name => $ind ) {
                $row[ $name ]            = $sub_data[ $name ][ $iso3 ]['value'] ?? null;
                $row[ $name . '_year' ]  = $sub_data[ $name ][ $iso3 ]['year']  ?? null;
            }
            $row['source']       = 'World Bank ' . ( $config['indicators'][ array_key_first( $config['indicators'] ) ]['source'] === 3 ? 'WGI' : 'WDI' );
            $row['last_updated'] = current_time( 'mysql' );
            $results[ $iso3 ]    = $row;
        }

        update_option( "geri_{$pillar_key}_pillar", $results, false );
        return $results;
    }
}

// Thin, explicitly-named wrappers — kept so the admin page and any
// external caller can reference a stable per-pillar function name
// rather than passing pillar_key strings around everywhere.
if ( ! function_exists( 'geri_refresh_governance_pillar' ) ) {
    function geri_refresh_governance_pillar( $source = 'auto' ) { return geri_refresh_pillar( 'governance', $source ); }
}
if ( ! function_exists( 'geri_refresh_macro_pillar' ) ) {
    function geri_refresh_macro_pillar( $source = 'auto' ) { return geri_refresh_pillar( 'macro', $source ); }
}
if ( ! function_exists( 'geri_refresh_external_vulnerability_pillar' ) ) {
    function geri_refresh_external_vulnerability_pillar( $source = 'auto' ) { return geri_refresh_pillar( 'external_vulnerability', $source ); }
}
if ( ! function_exists( 'geri_refresh_fiscal_stress_pillar' ) ) {
    function geri_refresh_fiscal_stress_pillar( $source = 'auto' ) { return geri_refresh_pillar( 'fiscal_stress', $source ); }
}

// ═══════════════════════════════════════════════════════════════════
// COMPOSITE BUILDER
// ═══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'geri_build_composite' ) ) {
    /**
     * Build the GERI composite from whatever is currently stored in
     * each pillar option. Percentile-ranks every sub-indicator
     * (direction-adjusted per GERI_INDICATORS' invert flags), averages
     * into a per-pillar risk percentile, applies GERI_MIN_PILLARS_REQUIRED
     * for Full/Partial/Excluded classification, and assigns rank +
     * rank_display (definitive for Full, OECD/JRC injection-simulated
     * range for Partial) via the shared Reference Data builders.
     *
     * @param string $meta_source 'manual' | 'cron_central_cached' | 'cron'
     * @return array Full composite payload — see docs/03-api-contract.md.
     */
    function geri_build_composite( $meta_source = 'manual' ) {
        $pillar_options = array();
        foreach ( array_keys( GERI_INDICATORS ) as $pillar_key ) {
            $pillar_options[ $pillar_key ] = get_option( "geri_{$pillar_key}_pillar", array() );
        }

        // ── Per-sub-indicator percentile ranks (direction-adjusted) ──
        $pillar_risk_pct = array(); // pillar_key => ISO3 => risk percentile (0-100)
        $pillar_raw_avg  = array(); // pillar_key => ISO3 => simple avg of raw sub-values present (for the _raw field)
        $pillar_sources  = array(); // pillar_key => ISO3 => source string
        $pillar_subcount = array(); // pillar_key => ISO3 => count of sub-indicators present
        $global_avg_raw  = array(); // pillar_key => informational-only mean of raw values

        foreach ( GERI_INDICATORS as $pillar_key => $config ) {
            $sub_risk_pct = array(); // sub_name => ISO3 => risk percentile
            foreach ( $config['indicators'] as $name => $ind ) {
                $raw_by_iso3 = array();
                foreach ( $pillar_options[ $pillar_key ] as $iso3 => $row ) {
                    if ( isset( $row[ $name ] ) && $row[ $name ] !== null ) {
                        $raw_by_iso3[ $iso3 ] = (float) $row[ $name ];
                    }
                }
                $raw_pct = blomstra_compute_percentile_ranks( $raw_by_iso3 );
                foreach ( $raw_pct as $iso3 => $pct ) {
                    $sub_risk_pct[ $name ][ $iso3 ] = $ind['invert'] ? round( 100 - $pct, 2 ) : $pct;
                }
            }

            foreach ( $pillar_options[ $pillar_key ] as $iso3 => $row ) {
                $present_risk = array();
                $present_raw  = array();
                foreach ( $config['indicators'] as $name => $ind ) {
                    if ( isset( $sub_risk_pct[ $name ][ $iso3 ] ) ) {
                        $present_risk[] = $sub_risk_pct[ $name ][ $iso3 ];
                        $present_raw[]  = $row[ $name ];
                    }
                }
                $pillar_subcount[ $pillar_key ][ $iso3 ] = count( $present_risk );
                if ( count( $present_risk ) >= $config['min_required'] ) {
                    $pillar_risk_pct[ $pillar_key ][ $iso3 ] = round( array_sum( $present_risk ) / count( $present_risk ), 2 );
                    $pillar_raw_avg[ $pillar_key ][ $iso3 ]  = round( array_sum( $present_raw ) / count( $present_raw ), 2 );
                    $pillar_sources[ $pillar_key ][ $iso3 ]  = $row['source'] ?? 'World Bank';
                }
            }

            $all_pillar_raw = ! empty( $pillar_raw_avg[ $pillar_key ] ) ? array_values( $pillar_raw_avg[ $pillar_key ] ) : array();
            $global_avg_raw[ $pillar_key ] = ! empty( $all_pillar_raw ) ? round( array_sum( $all_pillar_raw ) / count( $all_pillar_raw ), 2 ) : null;
        }

        // ── Composite per country ──
        $pillar_weight = array(
            'governance'             => GERI_WEIGHT_GOVERNANCE,
            'macro'                  => GERI_WEIGHT_MACRO,
            'external_vulnerability' => GERI_WEIGHT_EXTERNAL_VULN,
            'fiscal_stress'          => GERI_WEIGHT_FISCAL,
        );
        $all_pillar_names = array_keys( $pillar_weight );

        $all_keys = array();
        foreach ( $pillar_options as $opt ) {
            $all_keys = array_merge( $all_keys, array_keys( $opt ) );
        }
        $all_keys = array_unique( $all_keys );

        $results  = array();
        $excluded = array();

        foreach ( $all_keys as $iso3 ) {
            $present = array();
            foreach ( $all_pillar_names as $pillar_key ) {
                if ( isset( $pillar_risk_pct[ $pillar_key ][ $iso3 ] ) ) {
                    $present[ $pillar_key ] = array( 'value' => $pillar_risk_pct[ $pillar_key ][ $iso3 ], 'weight' => $pillar_weight[ $pillar_key ] );
                }
            }

            $pillars_present = count( $present );
            $pillars_missing = array_values( array_diff( $all_pillar_names, array_keys( $present ) ) );

            if ( $pillars_present < GERI_MIN_PILLARS_REQUIRED ) {
                $excluded[ $iso3 ] = array(
                    'reason'          => 'Fewer than ' . GERI_MIN_PILLARS_REQUIRED . ' of ' . count( $all_pillar_names ) . ' pillars have real data — not scored (no fabricated fill-in used).',
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
            $coverage_type = ( $pillars_present >= count( $all_pillar_names ) ) ? 'full' : 'partial';

            $row = array(
                'composite_score' => $composite,
                'coverage_type'   => $coverage_type,
                'pillars_used'    => $pillars_present,
                'pillars_missing' => $pillars_missing,
                'last_updated'    => current_time( 'mysql' ),
            );
            foreach ( $all_pillar_names as $pillar_key ) {
                $row[ "{$pillar_key}_risk_percentile" ] = $present[ $pillar_key ]['value'] ?? null;
                $row[ "{$pillar_key}_raw" ]             = $pillar_raw_avg[ $pillar_key ][ $iso3 ] ?? null;
                $row[ "{$pillar_key}_source" ]          = $pillar_sources[ $pillar_key ][ $iso3 ] ?? 'no data';
            }
            $results[ $iso3 ] = $row;
        }

        // ── Rank assignment (Full Index population) ──
        $full_composites_sorted = array();
        foreach ( $results as $row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $full_composites_sorted[] = $row['composite_score'];
            }
        }
        rsort( $full_composites_sorted );

        foreach ( $results as $iso3 => &$row ) {
            if ( $row['coverage_type'] === 'full' ) {
                $r = blomstra_rank_in_full_index( $row['composite_score'], $full_composites_sorted );
                $row['rank'] = $r;
                $row['rank_display'] = blomstra_build_full_rank_display( $r );
            }
        }
        unset( $row );

        $pillar_value_key = array();
        foreach ( $all_pillar_names as $pillar_key ) {
            $pillar_value_key[ $pillar_key ] = "{$pillar_key}_risk_percentile";
        }

        foreach ( $results as $iso3 => &$row ) {
            if ( $row['coverage_type'] !== 'partial' ) {
                continue;
            }
            $missing_pillar = $row['pillars_missing'][0] ?? null; // guaranteed exactly one by GERI_MIN_PILLARS_REQUIRED = 3 of 4
            if ( $missing_pillar === null || ! isset( $pillar_weight[ $missing_pillar ] ) ) {
                continue;
            }

            $known_weighted_sum = 0.0;
            foreach ( $pillar_weight as $pname => $pweight ) {
                if ( $pname === $missing_pillar ) {
                    continue;
                }
                $known_weighted_sum += ( $row[ $pillar_value_key[ $pname ] ] ?? 0 ) * $pweight;
            }
            $missing_weight = $pillar_weight[ $missing_pillar ];

            $ranks_by_injection = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $injected ) {
                $hypothetical = $known_weighted_sum + ( $injected * $missing_weight );
                $ranks_by_injection[ $injected ] = blomstra_rank_in_full_index( $hypothetical, $full_composites_sorted );
            }

            $row['rank'] = null;
            $row['rank_display'] = blomstra_build_partial_rank_display( $ranks_by_injection );
        }
        unset( $row );

        $output = array(
            'version'         => GERI_VERSION,
            'last_updated'    => current_time( 'mysql', true ),
            'total_countries' => count( $results ),
            'excluded'        => count( $excluded ),
            'excluded_detail' => $excluded,
            'methodology_url'     => home_url( '/geo-economic-risk-index-methodology/' ),
            'methodology_summary' => 'Percentile-rank composite risk score across four equally-weighted pillars (Governance, Macro Stability, External Vulnerability, Fiscal Stress), each built from World Bank WGI/WDI sub-indicators. Full Index = definitive rank (all 4 pillars present). Partial Index = projected rank range with 80% and theoretical bounds (exactly 1 pillar missing). See methodology_url for full derivation.',
            'footnote'        => 'Partial ranks are projections, not definitive placements. Following OECD/JRC guidelines, we report two uncertainty intervals for countries missing one pillar: the 80% Plausible Range (simulating the missing dimension between the 10th and 90th percentile of global data) and the Theoretical Bound (0th to 100th percentile). The Best Estimate uses the global median (50th percentile) for the missing dimension. Countries missing 2 or more of the 4 pillars are excluded outright, not scored as Partial.',
            'global_averages_informational_only' => array_merge(
                array( 'note' => 'Descriptive mean of pillar-level raw sub-indicator averages only — never used to fill in a missing pillar or in the composite math.' ),
                $global_avg_raw
            ),
            'weights' => $pillar_weight,
            '_meta' => array(
                'built_at' => current_time( 'mysql' ),
                'source'   => $meta_source,
                'status'   => 'valid',
            ),
            'countries' => $results,
        );

        delete_option( 'geri_composite_index' );
        update_option( 'geri_composite_index', $output, false );

        if ( function_exists( 'blomstra_index_snapshot_save' ) ) {
            blomstra_index_snapshot_save( 'geri', $output['countries'] );
        }

        return $output;
    }
}

// ═══════════════════════════════════════════════════════════════════
// COMBINED REFRESH & BUILD
// ═══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'geri_refresh_all_and_build' ) ) {
    function geri_refresh_all_and_build( $source = 'auto', $meta_source = 'manual' ) {
        $errors = array();
        foreach ( array_keys( GERI_INDICATORS ) as $pillar_key ) {
            $r = geri_refresh_pillar( $pillar_key, $source );
            if ( isset( $r['error'] ) ) {
                $errors[ $pillar_key ] = $r['error'];
            }
        }
        $composite = geri_build_composite( $meta_source );
        if ( ! empty( $errors ) ) {
            $composite['_pillar_errors'] = $errors; // surfaced in admin UI only, not part of the public REST contract
        }
        return $composite;
    }
}

// ═══════════════════════════════════════════════════════════════════
// BUILD LOCK + DUAL FRESHNESS (matches CII's build-reliability layer)
// ═══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'geri_acquire_build_lock' ) ) {
    function geri_acquire_build_lock() {
        if ( get_transient( 'geri_build_lock' ) ) {
            return false;
        }
        set_transient( 'geri_build_lock', 1, 5 * MINUTE_IN_SECONDS ); // self-healing TTL
        return true;
    }
}
if ( ! function_exists( 'geri_release_build_lock' ) ) {
    function geri_release_build_lock() {
        delete_transient( 'geri_build_lock' );
    }
}
if ( ! function_exists( 'geri_pillar_last_refreshed' ) ) {
    function geri_pillar_last_refreshed( $pillar_key ) {
        $data = get_option( "geri_{$pillar_key}_pillar", array() );
        $latest = null;
        foreach ( $data as $row ) {
            if ( ! empty( $row['last_updated'] ) && ( $latest === null || $row['last_updated'] > $latest ) ) {
                $latest = $row['last_updated'];
            }
        }
        return $latest;
    }
}

// ═══════════════════════════════════════════════════════════════════
// ASYNC PILLAR REFRESH — background wp-cron jobs, not blocking HTTP
// ═══════════════════════════════════════════════════════════════════
//
// v2.0.0 called geri_refresh_pillar() synchronously from the admin
// button's own HTTP request. Each pillar makes 2-3 sequential World
// Bank calls; chained across 4 pillars via "Refresh All & Build" (or
// even a single slower WDI pillar on its own) that's enough
// cumulative wall-clock time to exceed the webserver's own request
// timeout, which kills the request mid-loop — observed in practice as
// Governance (small, fast WGI dataset) completing while Macro/External
// Vulnerability/Fiscal Stress silently never run. Same failure class
// HHI/EIA hit in Reference Data, fixed there with
// wp_schedule_single_event() so the actual fetch runs in a detached
// background wp-cron request instead of the browser's blocking one.
// v2.1.0 applies the identical fix here, per-pillar.

if ( ! function_exists( 'geri_update_pillar_status' ) ) {
    /**
     * Same shape as blomstra_update_cron_status() in Reference Data,
     * kept as GERI's own option so its admin page doesn't need to
     * reach into Reference Data's status array for pillars Reference
     * Data has no knowledge of.
     */
    function geri_update_pillar_status( $pillar_key, $status, $message, $count = 0 ) {
        $all = get_option( 'geri_pillar_status', array() );
        $all[ $pillar_key ] = array(
            'status'    => $status, // 'running' | 'success' | 'error'
            'message'   => $message,
            'count'     => $count,
            'last_run'  => current_time( 'mysql' ),
        );
        update_option( 'geri_pillar_status', $all, false );
    }
}

if ( ! function_exists( 'geri_async_refresh_handler' ) ) {
    /**
     * The actual background job body, run via wp-cron — never called
     * directly from an admin request.
     *
     * @param string $pillar_key
     * @param string $source 'central' | 'api'
     */
    function geri_async_refresh_handler( $pillar_key, $source ) {
        geri_update_pillar_status( $pillar_key, 'running', ucfirst( $source ) . " refresh in progress…" );
        $result = geri_refresh_pillar( $pillar_key, $source );
        if ( isset( $result['error'] ) ) {
            geri_update_pillar_status( $pillar_key, 'error', $result['error'] );
        } else {
            geri_update_pillar_status( $pillar_key, 'success', ucfirst( $source ) . ' refresh completed.', count( $result ) );
        }
    }
}

foreach ( array_keys( GERI_INDICATORS ) as $__geri_pillar_key ) {
    add_action( "geri_async_refresh_{$__geri_pillar_key}_event", function ( $source ) use ( $__geri_pillar_key ) {
        geri_async_refresh_handler( $__geri_pillar_key, $source );
    } );
}
unset( $__geri_pillar_key );

if ( ! function_exists( 'geri_queue_pillar_refresh' ) ) {
    /**
     * Queue one pillar's refresh as a background wp-cron job. Fires on
     * the next wp-cron tick (near-immediate on an actively-visited
     * site) rather than inline — this is the "⚡ Queue Async Refresh"
     * equivalent for GERI, mirroring Reference Data's HHI/EIA pattern.
     *
     * @param string $pillar_key
     * @param string $source 'central' | 'api'
     */
    function geri_queue_pillar_refresh( $pillar_key, $source ) {
        geri_update_pillar_status( $pillar_key, 'running', 'Queued — waiting for next wp-cron tick…' );
        wp_schedule_single_event( time(), "geri_async_refresh_{$pillar_key}_event", array( $source ) );
    }
}

// ═══════════════════════════════════════════════════════════════════
// DAILY CRON
// ═══════════════════════════════════════════════════════════════════
//
// Uses 'central_cached' (reads whatever's already cached, never a
// live fetch), so this stays safely synchronous — no network calls,
// nothing to time out.

if ( ! function_exists( 'geri_run_daily_build_logic' ) ) {
    function geri_run_daily_build_logic() {
        update_option( 'geri_last_wpcron_fired', current_time( 'mysql' ), false );

        if ( ! geri_acquire_build_lock() ) {
            return;
        }
        foreach ( array_keys( GERI_INDICATORS ) as $pillar_key ) {
            $result = geri_refresh_pillar( $pillar_key, 'central_cached' );
            if ( isset( $result['error'] ) ) {
                geri_update_pillar_status( $pillar_key, 'error', $result['error'] );
            } else {
                geri_update_pillar_status( $pillar_key, 'success', 'Refreshed from cache via daily cron.', count( $result ) );
            }
        }
        geri_build_composite( 'cron' );
        geri_release_build_lock();
    }
}
add_action( 'geri_daily_refresh', 'geri_run_daily_build_logic' );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'geri_daily_refresh' ) ) {
        wp_schedule_event( time() + 300, 'daily', 'geri_daily_refresh' );
    }
} );

function geri_clear_cron() {
    $timestamp = wp_next_scheduled( 'geri_daily_refresh' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'geri_daily_refresh' );
    }
}

// ═══════════════════════════════════════════════════════════════════
// REST ENDPOINT — /wp-json/blomstra/v1/geo-economic-risk-index
// ═══════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/geo-economic-risk-index', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $data = get_option( 'geri_composite_index', null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index has not been generated yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );

// ═══════════════════════════════════════════════════════════════════
// ADMIN PAGE — under the shared "Blomstra Insights Tools" menu
// ═══════════════════════════════════════════════════════════════════
//
// v2.1.0 redesign: matches the postbox + widefat-table visual pattern
// used by Reference Data and CII's own admin pages, rather than
// v2.0.0's flat wall of inline button forms. Structurally:
//   Section 1 — Pipeline Health (per-pillar status, mirrors Reference
//               Data's "Automated Weekly Cron Health" table)
//   Section 2 — Pillar Data & Refresh Control (mirrors Reference
//               Data's "Data Layers & Granular Cache Control" table;
//               refresh actions are now Queue Async, not blocking)
//   Section 3 — Composite build + daily cron controls
//   Section 4 — Preview (top 20, excluded, raw JSON)

add_action( 'admin_menu', function () {
    add_submenu_page(
        'blomstra-insights-tools',
        'Geoeconomic Risk Index',
        'Geoeconomic Risk Index',
        'manage_options',
        'blomstra-geri',
        'geri_render_admin_page',
        30
    );
} );

if ( ! function_exists( 'geri_handle_early_actions' ) ) {
    function geri_handle_early_actions() {
        if ( ! isset( $_POST['geri_action'] ) || ! check_admin_referer( 'geri_action_nonce' ) ) {
            return;
        }
        $action = sanitize_text_field( $_POST['geri_action'] );

        if ( preg_match( '/^queue_(central|api)_(.+)$/', $action, $m ) ) {
            geri_queue_pillar_refresh( $m[2], $m[1] );
        } elseif ( $action === 'queue_all' ) {
            foreach ( array_keys( GERI_INDICATORS ) as $pk ) {
                geri_queue_pillar_refresh( $pk, 'auto' );
            }
        } elseif ( preg_match( '/^flush_(.+)$/', $action, $m ) ) {
            if ( $m[1] === 'all' ) {
                foreach ( array_keys( GERI_INDICATORS ) as $pk ) {
                    delete_option( "geri_{$pk}_pillar" );
                }
                delete_option( 'geri_composite_index' );
                delete_option( 'geri_pillar_status' );
            } else {
                delete_option( "geri_{$m[1]}_pillar" );
            }
        } elseif ( $action === 'build_composite' ) {
            geri_build_composite( 'manual' );
        } elseif ( $action === 'force_daily_cron' ) {
            geri_run_daily_build_logic();
        }

        wp_safe_redirect( admin_url( 'admin.php?page=blomstra-geri&done=1' ) );
        exit;
    }
}
add_action( 'admin_init', 'geri_handle_early_actions' );

if ( ! function_exists( 'geri_render_admin_page' ) ) {
    function geri_render_admin_page() {
        nocache_headers();
        $existing   = get_option( 'geri_composite_index', null );
        $next_cron  = wp_next_scheduled( 'geri_daily_refresh' );
        $last_fired = get_option( 'geri_last_wpcron_fired', null );
        $status_all = get_option( 'geri_pillar_status', array() );

        echo '<div class="wrap">';
        echo '<h1><span class="dashicons dashicons-chart-area" style="font-size:28px;height:28px;width:28px;"></span> Geoeconomic Risk Index (GERI) <span style="color:#888;font-weight:normal;">v' . esc_html( GERI_VERSION ) . '</span></h1>';

        if ( isset( $_GET['done'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Action queued/completed.</p></div>';
        }

        // ─── SECTION 1: PIPELINE HEALTH ────────────────────────
        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Pipeline Health</h2></div>';
        echo '<div class="inside">';
        echo '<table class="widefat striped" style="margin-bottom:10px;">';
        echo '<thead><tr><th>Pillar</th><th>Status</th><th>Last Refreshed</th><th>Countries</th><th>Message</th></tr></thead><tbody>';
        foreach ( array_keys( GERI_INDICATORS ) as $pk ) {
            $label = ucwords( str_replace( '_', ' ', $pk ) );
            $st    = $status_all[ $pk ] ?? null;
            $badge = '<span style="color:#666;">Never Run</span>';
            if ( $st ) {
                if ( $st['status'] === 'success' ) {
                    $badge = '<strong style="color:#2e7d32;">SUCCESS ✓</strong>';
                } elseif ( $st['status'] === 'error' ) {
                    $badge = '<strong style="color:#d63638;">ERROR ✗</strong>';
                } else {
                    $badge = '<strong style="color:#2271b1;">RUNNING… (refresh this page)</strong>';
                }
            }
            echo '<tr>';
            echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>' . esc_html( geri_pillar_last_refreshed( $pk ) ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $st['count'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $st['message'] ?? '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p>Daily cron: <strong>' . ( $next_cron ? 'scheduled — next ' . esc_html( date( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : '<span style="color:#d63638;">NOT SCHEDULED</span>' ) . '</strong>';
        echo ' &nbsp;|&nbsp; last real wp-cron fire: <strong>' . esc_html( $last_fired ?? 'never' ) . '</strong></p>';
        if ( $existing ) {
            echo '<p>Composite last built: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' scored, ' . esc_html( $existing['excluded'] ) . ' excluded.</p>';
        } else {
            echo '<p>No composite built yet.</p>';
        }
        echo '</div></div>';

        // ─── SECTION 2: PILLAR DATA & REFRESH CONTROL ──────────
        echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #2271b1;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-dashboard"></span> Pillar Data &amp; Refresh Control</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Refreshes are queued as background jobs (wp-cron), not run inline — a click here returns immediately and the actual World Bank fetch runs within moments. Reload this page to see status move from Running to Success.</p>';
        echo '<table class="widefat striped" style="background:#fff;">';
        echo '<thead><tr><th>Pillar</th><th>Sub-indicators</th><th>Actions</th></tr></thead><tbody>';
        foreach ( GERI_INDICATORS as $pk => $config ) {
            $label = ucwords( str_replace( '_', ' ', $pk ) );
            $sub_labels = implode( ', ', array_map( function ( $n ) { return str_replace( '_', ' ', $n ); }, array_keys( $config['indicators'] ) ) );
            echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br><span style="color:#888;font-size:12px;">min ' . esc_html( $config['min_required'] ) . ' of ' . count( $config['indicators'] ) . ' required</span></td>';
            echo '<td style="color:#555;">' . esc_html( $sub_labels ) . '</td><td>';
            echo '<form method="post" style="display:inline-block;margin-right:5px;">';
            wp_nonce_field( 'geri_action_nonce' );
            echo "<input type='hidden' name='geri_action' value='queue_central_{$pk}'>";
            echo "<button type='submit' class='button button-small button-primary'>⚡ Queue Async Refresh — Central</button>";
            echo '</form>';
            echo '<form method="post" style="display:inline-block;margin-right:5px;">';
            wp_nonce_field( 'geri_action_nonce' );
            echo "<input type='hidden' name='geri_action' value='queue_api_{$pk}'>";
            echo "<button type='submit' class='button button-small'>⚡ Queue Async Refresh — API</button>";
            echo '</form>';
            echo '<form method="post" style="display:inline-block;">';
            wp_nonce_field( 'geri_action_nonce' );
            echo "<input type='hidden' name='geri_action' value='flush_{$pk}'>";
            echo "<button type='submit' class='button button-small button-link-delete'>🗑️ Flush</button>";
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:12px;">';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'geri_action_nonce' );
        echo '<input type="hidden" name="geri_action" value="queue_all">';
        echo '<button type="submit" class="button button-primary">⚡ Queue Async Refresh — All 4 Pillars</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'geri_action_nonce' );
        echo '<input type="hidden" name="geri_action" value="flush_all">';
        echo '<button type="submit" class="button button-link-delete">🗑️ Flush All Pillars + Composite</button>';
        echo '</form></p>';
        echo '</div></div>';

        // ─── SECTION 3: COMPOSITE BUILD & CRON ─────────────────
        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-analytics"></span> Composite &amp; Cron</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Build Composite is synchronous and safe to click any time — it only does math over whatever pillar data is already cached, no network calls. Run it once all 4 pillars above show SUCCESS.</p>';
        echo '<form method="post" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field( 'geri_action_nonce' );
        echo '<input type="hidden" name="geri_action" value="build_composite">';
        echo '<button type="submit" class="button button-primary">Build Composite</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'geri_action_nonce' );
        echo '<input type="hidden" name="geri_action" value="force_daily_cron">';
        echo '<button type="submit" class="button">Force Run Daily Cron Now (cache-only, safe/fast)</button>';
        echo '</form>';
        echo '<p style="margin-top:12px;color:#666;">Endpoint: <code>' . esc_url( rest_url( 'blomstra/v1/geo-economic-risk-index' ) ) . '</code></p>';
        echo '</div></div>';

        // ─── SECTION 4: PREVIEW ────────────────────────────────
        if ( $existing && ! empty( $existing['countries'] ) ) {
            $countries = $existing['countries'];
            uasort( $countries, function ( $a, $b ) { return $b['composite_score'] <=> $a['composite_score']; } );
            $names = function_exists( 'blomstra_get_global_country_list' ) ? blomstra_get_global_country_list() : array();

            echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #2271b1;">';
            echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-list-view"></span> Preview: Top 20 Highest-Risk</h2></div>';
            echo '<div class="inside"><table class="widefat striped" style="background:#fff;"><thead><tr><th>Country</th><th>Risk Score</th><th>Coverage</th><th>Rank</th></tr></thead><tbody>';
            $i = 0;
            foreach ( $countries as $iso3 => $row ) {
                if ( $i++ >= 20 ) break;
                echo '<tr><td>' . esc_html( $names[ $iso3 ] ?? $iso3 ) . '</td><td>' . esc_html( $row['composite_score'] ) . '</td><td>' . esc_html( $row['coverage_type'] ) . '</td><td>' . esc_html( $row['rank_display']['string_format'] ?? '—' ) . '</td></tr>';
            }
            echo '</tbody></table></div></div>';

            if ( ! empty( $existing['excluded_detail'] ) ) {
                echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #d63638;">';
                echo '<div class="postbox-header"><h2 class="hndle">Excluded (' . count( $existing['excluded_detail'] ) . ')</h2></div>';
                echo '<div class="inside"><table class="widefat striped" style="background:#fff;"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
                foreach ( $existing['excluded_detail'] as $iso3 => $ex ) {
                    echo '<tr><td>' . esc_html( $names[ $iso3 ] ?? $iso3 ) . '</td><td>' . esc_html( $ex['reason'] ) . '</td></tr>';
                }
                echo '</tbody></table></div></div>';
            }

            echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #888;">';
            echo '<div class="postbox-header"><h2 class="hndle">Raw JSON Output</h2></div>';
            echo '<div class="inside"><textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) ) . '</textarea></div></div>';
        }

        echo '</div>';
    }
}
