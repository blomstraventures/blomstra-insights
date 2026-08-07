/**
 * Blomstra Geo-Economic Risk Index — WordPress-native pipeline.
 * Version: 1.7 — Hard 2-pillar requirement + exclusion transparency
 *
 * CHANGES FROM v1.6:
 *   1. Removed adaptive single-pillar imputation. A country now needs
 *      BOTH pillars to be included:
 *        - Governance: at least 2 of 3 WGI indicators present
 *        - Macro: BOTH GNI growth AND inflation present
 *      This matches the Governance Capture Index's ≥2-pillar floor and
 *      the site's published methodology text, which this code had
 *      drifted away from in v1.6.
 *   2. Every excluded country now carries a specific reason
 *      ("insufficient governance data", "incomplete macro data", etc.)
 *      surfaced in the REST payload as `excluded_detail`, so exclusions
 *      are transparent rather than a bare count.
 *   3. Removed the now-meaningless `pillar_coverage` field — since every
 *      included country is unconditionally full-coverage, it no longer
 *      carries information.
 */

// ── DISCOVERY TOOL (unchanged) ───────────────────────────────────

function blomstra_discover_wgi() {
    $out = array();

    $sources_url = 'https://api.worldbank.org/v2/source?format=json&per_page=100';
    $resp = wp_remote_get( $sources_url, array( 'timeout' => 30 ) );
    if ( is_wp_error( $resp ) ) {
        $out[] = 'ERROR fetching source list: ' . $resp->get_error_message();
        return $out;
    }
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    $wgi_id = null;
    if ( isset( $body[1] ) && is_array( $body[1] ) ) {
        foreach ( $body[1] as $src ) {
            $name = $src['name'] ?? '';
            if ( stripos( $name, 'governance' ) !== false ) {
                $out[] = "Found source: id={$src['id']} name=\"{$name}\"";
                if ( $wgi_id === null ) {
                    $wgi_id = $src['id'];
                }
            }
        }
    } else {
        $out[] = 'Could not parse source list. Raw: ' . substr( wp_remote_retrieve_body( $resp ), 0, 300 );
        return $out;
    }

    if ( ! $wgi_id ) {
        $out[] = 'No source with "governance" in its name was found. Full source list follows:';
        foreach ( $body[1] as $src ) {
            $out[] = "  id={$src['id']} name=\"{$src['name']}\"";
        }
        return $out;
    }

    $ind_url = "https://api.worldbank.org/v2/source/{$wgi_id}/indicator?format=json&per_page=100";
    $resp2 = wp_remote_get( $ind_url, array( 'timeout' => 30 ) );
    if ( is_wp_error( $resp2 ) ) {
        $out[] = 'ERROR fetching indicator list: ' . $resp2->get_error_message();
        return $out;
    }
    $body2 = json_decode( wp_remote_retrieve_body( $resp2 ), true );
    if ( isset( $body2[1] ) && is_array( $body2[1] ) ) {
        $out[] = "Indicators currently in source {$wgi_id}:";
        foreach ( $body2[1] as $ind ) {
            $out[] = "  {$ind['id']} — {$ind['name']}";
        }
    } else {
        $out[] = 'Could not parse indicator list. Raw: ' . substr( wp_remote_retrieve_body( $resp2 ), 0, 500 );
    }

    return $out;
}

// ── FETCH (unchanged) ─────────────────────────────────────────────

function blomstra_fetch_wb_indicator( $code, $source = null ) {
    $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";

    if ( $source ) {
        $url .= "&source={$source}";
    } else {
        $url .= "&mrnev=1";
    }

    $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

    if ( is_wp_error( $response ) ) {
        error_log( 'World Bank API Error: ' . $response->get_error_message() );
        return array();
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        error_log( "World Bank API HTTP {$http_code} for {$code}" );
        return array();
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
        error_log( "World Bank API: No data array for {$code}" );
        return array();
    }

    $out = array();
    foreach ( $body[1] as $row ) {
        $name = $row['country']['value'] ?? null;
        $val  = $row['value'] ?? null;
        if ( $name && $val !== null ) {
            $out[ $name ] = floatval( $val );
        }
    }
    return $out;
}

// ── COUNTRY VS. AGGREGATE FILTER (unchanged) ─────────────────────
// World Bank's indicator endpoints return both real countries AND
// regional/income-group aggregates (e.g. "Arab World", "Caribbean
// small states"). The only reliable way to tell them apart is the
// separate /v2/country metadata endpoint, where real countries have
// a real region and aggregates are tagged region.id === "NA".
// Cached for 24h via transient so this doesn't add a network call
// to every refresh.

function blomstra_get_real_country_names() {
    $cached = get_transient( 'blomstra_wb_real_countries' );
    if ( is_array( $cached ) && ! empty( $cached ) ) {
        return $cached;
    }

    $names = array();
    $page  = 1;
    do {
        $url = "https://api.worldbank.org/v2/country?format=json&per_page=300&page={$page}";
        $resp = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $resp ) ) {
            error_log( 'World Bank country-list API error: ' . $resp->get_error_message() );
            break;
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
            break;
        }
        foreach ( $body[1] as $entry ) {
            $region_id = $entry['region']['id'] ?? 'NA';
            if ( $region_id !== 'NA' ) {
                $names[] = $entry['name'];
            }
        }
        $pages_total = $body[0]['pages'] ?? 1;
        $page++;
    } while ( $page <= $pages_total );

    if ( ! empty( $names ) ) {
        // 24 hours — country metadata essentially never changes.
        set_transient( 'blomstra_wb_real_countries', $names, DAY_IN_SECONDS );
    }

    return $names;
}

function blomstra_normalize_macro_component( $gni_growth, $inflation ) {
    // Returns the two macro sub-scores separately so the caller can decide
    // how to combine them depending on which are available.
    $g = null;
    if ( $gni_growth !== null ) {
        $gni_lo = -5; $gni_hi = 8;
        $g = ( $gni_growth - $gni_lo ) / ( $gni_hi - $gni_lo ) * 100;
        $g = max( 0, min( 100, $g ) );
    }

    $i = null;
    if ( $inflation !== null ) {
        if ( $inflation < 0 ) {
            $safe_inflation = 1.5;
            $deflation_penalty = 10;
        } else {
            $safe_inflation = max( 0.1, $inflation );
            $deflation_penalty = 0;
        }
        $i = 100 - ( log10( $safe_inflation ) / 3 ) * 100;
        $i = max( 0, min( 100, $i ) );
        if ( $deflation_penalty > 0 ) {
            $i = max( 0, $i - $deflation_penalty );
        }
    }

    return array( 'gni_score' => $g, 'inflation_score' => $i );
}

/**
 * ── HARD-FLOOR SCORING (v1.7) ────────────────────────────────────
 * Governance pillar: requires at least 2 of 3 WGI indicators present.
 *   (1 of 3 is not treated as a usable pillar — no single-indicator
 *    governance claims.)
 * Macro pillar: requires BOTH GNI growth AND inflation present.
 *   (With only 2 possible macro indicators, "1 of 2" is 50% blind —
 *    not a usable pillar either.)
 * Composite: 50/50 average of the two pillars. A country missing
 * either pillar entirely is excluded — no imputation, no partial-
 * pillar substitution. This matches the Governance Capture Index's
 * ≥2-pillar standard and the site's published methodology.
 */
function blomstra_score_country( $row ) {
    $gov_values = array_filter(
        array( $row['rule_of_law'], $row['control_of_corruption'], $row['political_stability'] ),
        function ( $v ) { return $v !== null; }
    );
    $gov_count = count( $gov_values );
    $governance = $gov_count >= 2 ? round( array_sum( $gov_values ) / $gov_count, 1 ) : null;

    $macro_components = blomstra_normalize_macro_component( $row['gni_growth'], $row['inflation'] );
    $has_both_macro = ( $macro_components['gni_score'] !== null && $macro_components['inflation_score'] !== null );
    $macro = $has_both_macro
        ? round( ( $macro_components['gni_score'] + $macro_components['inflation_score'] ) / 2, 1 )
        : null;

    if ( $governance === null || $macro === null ) {
        return null; // Excluded — see blomstra_exclusion_reason() for why.
    }

    $composite = round( ( $governance + $macro ) / 2, 1 );

    return array(
        'risk_score'         => round( 100 - $composite, 1 ),
        'governance_score'   => $governance,
        'macro_score'        => $macro,
        'gni_growth'         => $row['gni_growth'],
        'inflation'          => $row['inflation'],
        'governance_inputs'  => $gov_count . '/3',
        'macro_inputs'       => '2/2',
    );
}

/**
 * Human-readable reason a country was excluded. Thresholds here must
 * stay in sync with blomstra_score_country() above.
 */
function blomstra_exclusion_reason( $row ) {
    $gov_count = count( array_filter(
        array( $row['rule_of_law'], $row['control_of_corruption'], $row['political_stability'] ),
        function ( $v ) { return $v !== null; }
    ) );
    $has_gov   = $gov_count >= 2;
    $has_macro = ( $row['gni_growth'] !== null && $row['inflation'] !== null );

    if ( ! $has_gov && ! $has_macro ) {
        return "No usable governance data ({$gov_count}/3 indicators; 2 required) and incomplete macro data (GNI growth and inflation both required).";
    } elseif ( ! $has_gov ) {
        return "Insufficient governance data ({$gov_count}/3 WGI indicators present; 2 required).";
    } else {
        $missing = array();
        if ( $row['gni_growth'] === null ) { $missing[] = 'GNI growth'; }
        if ( $row['inflation'] === null )  { $missing[] = 'inflation'; }
        return 'Incomplete macro data — missing: ' . implode( ', ', $missing ) . '.';
    }
}

function blomstra_build_geo_economic_risk_index() {
    if ( function_exists( 'set_time_limit' ) ) {
        set_time_limit( 120 );
    }

    wp_cache_flush();

    $indicators = array(
        'GOV_WGI_RL.SC'     => array( 'name' => 'rule_of_law', 'source' => 3 ),
        'GOV_WGI_CC.SC'     => array( 'name' => 'control_of_corruption', 'source' => 3 ),
        'GOV_WGI_PV.SC'     => array( 'name' => 'political_stability', 'source' => 3 ),
        'NY.GNP.MKTP.KD.ZG' => array( 'name' => 'gni_growth', 'source' => null ),
        'FP.CPI.TOTL.ZG'    => array( 'name' => 'inflation', 'source' => null ),
    );

    $data = array();
    foreach ( $indicators as $code => $info ) {
        $data[ $info['name'] ] = blomstra_fetch_wb_indicator( $code, $info['source'] );
    }

    $countries = array();
    foreach ( $data as $series ) {
        $countries = array_merge( $countries, array_keys( $series ) );
    }
    $countries = array_unique( $countries );

    // Drop regional/income-group aggregates (e.g. "Arab World",
    // "Caribbean small states") — only real, individual countries
    // are ever rankable, matching the standard used across every
    // other Blomstra index.
    $real_country_names = blomstra_get_real_country_names();
    $aggregates_dropped  = 0;
    if ( ! empty( $real_country_names ) ) {
        $before = count( $countries );
        $countries = array_values( array_intersect( $countries, $real_country_names ) );
        $aggregates_dropped = $before - count( $countries );
    }

    $results  = array();
    $excluded = array(); // country name => reason string

    foreach ( $countries as $country ) {
        $row = array();
        foreach ( $indicators as $code => $info ) {
            $row[ $info['name'] ] = $data[ $info['name'] ][ $country ] ?? null;
        }

        $scored = blomstra_score_country( $row );
        if ( $scored === null ) {
            $excluded[ $country ] = blomstra_exclusion_reason( $row );
            continue;
        }
        $results[ $country ] = $scored;
    }

    $output = array(
        'version'            => '1.7',
        'last_updated'       => current_time( 'mysql', true ),
        'sources'            => array(
            'World Bank Worldwide Governance Indicators (WGI)',
            'World Bank World Development Indicators (WDI)',
        ),
        'methodology_url'    => home_url( '/geo-economic-risk-index-methodology/' ),
        'inclusion_criteria' => 'At least 2 of 3 WGI governance indicators, AND both GNI growth and inflation (WDI). No single-pillar imputation.',
        'total_countries'    => count( $results ),
        'excluded_countries' => count( $excluded ),   // had insufficient data on 1 or both pillars
        'excluded_detail'    => $excluded,              // { "Country Name": "reason string" }
        'aggregates_dropped' => $aggregates_dropped,    // regional/income-group groupings, not real countries
        'countries'          => $results,
    );

    delete_option( 'blomstra_geo_economic_risk_index' );
    update_option( 'blomstra_geo_economic_risk_index', $output, false );

    return $output;
}

// ── AUTOMATED WEEKLY CRON (unchanged) ─────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'blomstra_geo_economic_weekly_refresh' ) ) {
        // Schedules first run 5 min from now, then every 7 days.
        wp_schedule_event( time() + 300, 'weekly', 'blomstra_geo_economic_weekly_refresh' );
    }
} );

add_action( 'blomstra_geo_economic_weekly_refresh', 'blomstra_build_geo_economic_risk_index' );

// Clean up the scheduled event if this file is ever removed/deactivated.
// If this lives in a plugin, call this from register_deactivation_hook()
// instead of leaving it unused here.
function blomstra_geo_economic_clear_cron() {
    $timestamp = wp_next_scheduled( 'blomstra_geo_economic_weekly_refresh' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'blomstra_geo_economic_weekly_refresh' );
    }
}

// ── REST ENDPOINT (unchanged) ─────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/geo-economic-risk-index', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $data = get_option( 'blomstra_geo_economic_risk_index', null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index has not been generated yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );

// ── ADMIN PAGE ─────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_management_page(
        'Blomstra Geoeconomic Risk Index',
        'Geoeconomic Risk Index',
        'manage_options',
        'blomstra-geoeconomic-risk-index',
        function () {
            if ( isset( $_POST['blomstra_hard_refresh'] ) && check_admin_referer( 'blomstra_hard_refresh_action' ) ) {
                delete_option( 'blomstra_geo_economic_risk_index' );
                wp_cache_flush();
                $data = blomstra_build_geo_economic_risk_index();
                echo '<div class="notice notice-success"><p>✅ Hard refresh completed: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded, insufficient data; ' . esc_html( $data['aggregates_dropped'] ?? 0 ) . ' regional aggregates filtered out), at ' . esc_html( $data['last_updated'] ) . ' UTC.</p></div>';
            } elseif ( isset( $_POST['blomstra_refresh'] ) && check_admin_referer( 'blomstra_refresh_action' ) ) {
                $data = blomstra_build_geo_economic_risk_index();
                echo '<div class="notice notice-success"><p>Refreshed: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded, insufficient data; ' . esc_html( $data['aggregates_dropped'] ?? 0 ) . ' regional aggregates filtered out), at ' . esc_html( $data['last_updated'] ) . ' UTC.</p></div>';
            }

            $existing  = get_option( 'blomstra_geo_economic_risk_index', null );
            $next_cron = wp_next_scheduled( 'blomstra_geo_economic_weekly_refresh' );

            echo '<div class="wrap"><h1>Blomstra Geo-Economic Risk Index</h1>';

            echo '<p>Automated weekly refresh: <strong>' . ( $next_cron ? 'ACTIVE — next run ' . esc_html( date( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : 'NOT SCHEDULED' ) . '</strong></p>';

            if ( $existing ) {
                echo '<p>Last updated: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' countries scored, ' . esc_html( $existing['excluded_countries'] ?? 0 ) . ' excluded (insufficient data), ' . esc_html( $existing['aggregates_dropped'] ?? 0 ) . ' regional aggregates filtered out.</p>';
            } else {
                echo '<p>No data yet. Click below to generate it for the first time.</p>';
            }

            // ── Refresh Button ──
            echo '<form method="post" style="margin-top:10px;">';
            wp_nonce_field( 'blomstra_refresh_action' );
            echo '<input type="submit" name="blomstra_refresh" class="button button-primary" value="Refresh Data Now"></form>';

            // ── Hard Refresh Button ──
            echo '<form method="post" style="margin-top:10px;">';
            wp_nonce_field( 'blomstra_hard_refresh_action' );
            echo '<input type="submit" name="blomstra_hard_refresh" class="button button-secondary" value="🔄 Force Hard Refresh (Clear Cache)"></form>';

            // ── Discovery Button ──
            echo '<form method="post" style="margin-top:10px;">';
            wp_nonce_field( 'blomstra_discover_action' );
            echo '<input type="submit" name="blomstra_discover" class="button" value="🔍 Discover Real Governance Indicator Codes"></form>';

            if ( isset( $_POST['blomstra_discover'] ) && check_admin_referer( 'blomstra_discover_action' ) ) {
                $discovery = blomstra_discover_wgi();
                echo '<div class="notice notice-info"><p><strong>Live API discovery:</strong></p><pre style="white-space:pre-wrap;max-height:400px;overflow-y:auto;">' . esc_html( implode( "\n", $discovery ) ) . '</pre></div>';
            }

            echo '<p style="margin-top:20px;color:#666;">Endpoint for frontend: <code>' . esc_url( rest_url( 'blomstra/v1/geo-economic-risk-index' ) ) . '</code></p>';

            if ( $existing && ! empty( $existing['countries'] ) ) {
                $countries = $existing['countries'];
                uasort( $countries, function ( $a, $b ) {
                    return $a['risk_score'] <=> $b['risk_score'];
                } );
                $lowest_risk  = array_slice( $countries, 0, 10, true );
                $highest_risk = array_slice( $countries, -10, 10, true );

                echo '<h2>Preview: 10 Lowest-Risk Countries</h2>';
                echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Risk Score</th><th>Governance</th><th>Macro</th></tr></thead><tbody>';
                foreach ( $lowest_risk as $name => $row ) {
                    echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['risk_score'] ?? '—' ) . '</td><td>' . esc_html( $row['governance_score'] ?? '—' ) . '</td><td>' . esc_html( $row['macro_score'] ?? '—' ) . '</td></tr>';
                }
                echo '</tbody></table>';

                echo '<h2>Preview: 10 Highest-Risk Countries</h2>';
                echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Risk Score</th><th>Governance</th><th>Macro</th></tr></thead><tbody>';
                foreach ( $highest_risk as $name => $row ) {
                    echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['risk_score'] ?? '—' ) . '</td><td>' . esc_html( $row['governance_score'] ?? '—' ) . '</td><td>' . esc_html( $row['macro_score'] ?? '—' ) . '</td></tr>';
                }
                echo '</tbody></table>';

                if ( ! empty( $existing['excluded_detail'] ) ) {
                    echo '<h2>Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</h2>';
                    echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
                    foreach ( $existing['excluded_detail'] as $name => $reason ) {
                        echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
                    }
                    echo '</tbody></table>';
                }

                echo '<h2>Raw JSON Output</h2>';
                echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">'
                    . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) )
                    . '</textarea>';
            }

            echo '</div>';
        }
    );
} );
