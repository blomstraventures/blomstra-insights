/**
 * SIVI Frontend — Shortcode (PHP)
 * WPCode PHP Snippet.
 *
 * Registers [blomstra_sivi_index] which renders the Sovereign Infrastructure
 * Vulnerability Index using the shared Blomstra Index Frontend engine/styles.
 *
 * Usage: [blomstra_sivi_index]
 */

if ( ! function_exists( 'sivi_render_index_shortcode' ) ) {
    function sivi_render_index_shortcode( $atts ) {

        $pillars = array(
            array(
                'key'     => 'energy_dependency_percentile',
                'raw_key' => 'energy_dependency_raw',
                'label'   => 'Energy Dependency',
                'color'   => '#60a5fa',
            ),
            array(
                'key'     => 'supplier_concentration_percentile',
                'raw_key' => 'supplier_concentration_raw',
                'label'   => 'Supplier Concentration',
                'color'   => '#f87171',
            ),
            array(
                'key'     => 'maritime_vulnerability_percentile',
                'raw_key' => 'maritime_connectivity_raw',
                'label'   => 'Maritime Exposure',
                'color'   => '#fb923c',
            ),
        );

        $methodology = 'The Sovereign Infrastructure Vulnerability Index (SIVI) combines three pillars — '
            . '<strong>Energy Dependency</strong> (EIA, consumption-share-weighted across five fuels), '
            . '<strong>Supplier Concentration</strong> (UN Comtrade import HHI), and '
            . '<strong>Maritime Exposure</strong> (World Bank LSCI, inverted to a vulnerability orientation; '
            . 'landlocked countries score a structural zero). '
            . 'Countries with real data for all three pillars receive a definitive rank (Full Index). '
            . 'Countries missing one pillar receive a projected rank range rather than a fabricated fill-in value (Partial Index). '
            . 'Lower scores indicate lower vulnerability (rank #1 is the least vulnerable country). '
            . '<a href="' . esc_url( 'https://blomstrainsights.com/methodology/sivi' ) . '" target="_blank" rel="noopener">Full methodology →</a>';

        ob_start();
        ?>
<div class="biw"
     data-biw-slug="sivi"
     data-biw-endpoint="/wp-json/blomstra/v1/critical-infrastructure-index"
     data-biw-names-endpoint="/wp-json/blomstra/v1/country-names"
     data-biw-title="Sovereign Infrastructure Vulnerability Index"
     data-biw-subtitle="A country-level assessment of exposure, dependency, and systemic weakness across critical infrastructure sectors"
     data-biw-eyebrow="Strategic Intelligence"
     data-biw-score-key="composite_score"
     data-biw-score-label="Vulnerability Score"
     data-biw-coverage-key="coverage_type"
     data-biw-band-thresholds="25,50,75"
     data-biw-band-labels="Low,Medium,High,Extreme"
     data-biw-band-select-label="All Vulnerability Levels"
     data-biw-pillars='<?php echo esc_attr( wp_json_encode( $pillars ) ); ?>'
     data-biw-methodology="<?php echo esc_attr( $methodology ); ?>">
</div>
        <?php
        return ob_get_clean();
    }
    add_shortcode( 'blomstra_sivi_index', 'sivi_render_index_shortcode' );
}

// ─── DEPRECATED: Keep old shortcode as alias ──────────────────────
if ( ! function_exists( 'cii_render_index_shortcode' ) ) {
    function cii_render_index_shortcode( $atts ) {
        return sivi_render_index_shortcode( $atts );
    }
    add_shortcode( 'cii_index', 'cii_render_index_shortcode' );
}
