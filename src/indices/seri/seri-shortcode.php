/**
 * SERI Frontend — Shortcode (PHP)
 * WPCode PHP Snippet.
 *
 * Registers [blomstra_seri_index] which renders the Sovereign Economic Resilience Index
 * using the shared Blomstra Index Frontend engine/styles.
 *
 * Usage: [blomstra_seri_index]
 */
if ( ! function_exists( 'seri_render_index_shortcode' ) ) {
    function seri_render_index_shortcode( $atts ) {

        $pillars = array(
            array(
                'key'     => 'governance_percentile',
                'raw_key' => null,
                'label'   => 'Governance',
                'color'   => '#60a5fa',
            ),
            array(
                'key'     => 'macro_percentile',
                'raw_key' => null,
                'label'   => 'Macro Stability',
                'color'   => '#34d399',
            ),
            array(
                'key'     => 'external_percentile',
                'raw_key' => null,
                'label'   => 'External Vulnerability',
                'color'   => '#fb923c',
            ),
            array(
                'key'     => 'fiscal_percentile',
                'raw_key' => null,
                'label'   => 'Fiscal Stress',
                'color'   => '#f87171',
            ),
        );

        $methodology = 'The Sovereign Economic Resilience Index (SERI) combines four pillars — '
            . '<strong>Governance</strong> (World Bank WGI: rule of law, control of corruption, political stability), '
            . '<strong>Macro Stability</strong> (GNI growth, inflation, unemployment, GDP volatility, inflation volatility), '
            . '<strong>External Vulnerability</strong> (reserve months, external debt, current account, GNI-GDP divergence), and '
            . '<strong>Fiscal Stress</strong> (government debt, government balance, debt trajectory). '
            . 'Countries with data for all four pillars receive a definitive rank (Full Index). '
            . 'Countries missing one pillar receive a projected rank range using global median injection (Partial Index). '
            . 'Countries with fewer than three pillars are excluded. '
            . 'Higher Structural Scores indicate lower resilience (more vulnerability). '
            . '<a href="' . esc_url( 'https://blomstrainsights.com/methodology/seri' ) . '" target="_blank" rel="noopener">Full methodology →</a>';

        $missing_pillar_notes = array(
            'governance' => 'insufficient WGI coverage',
            'macro'      => 'missing macro data (GNI growth, inflation, unemployment, or volatility)',
            'external'   => 'missing external data (reserves, debt, current account, or divergence)',
            'fiscal'     => 'missing fiscal data (debt, balance, or trajectory)',
        );

        ob_start();
        ?>
        <div class="biw"
             data-biw-slug="seri"
             data-biw-endpoint="/wp-json/blomstra/v1/geo-economic-risk-index"
             data-biw-names-endpoint="/wp-json/blomstra/v1/country-names"
             data-biw-title="Sovereign Economic Resilience Index"
             data-biw-subtitle="A composite measure of governance, macro stability, external vulnerability, and fiscal stress — higher scores indicate lower resilience"
             data-biw-eyebrow="Strategic Intelligence"
             data-biw-score-key="geri_structural"
             data-biw-score-label="Structural Score"
             data-biw-coverage-key="coverage"
             data-biw-missing-key="pillars_missing"
             data-biw-missing-notes='<?php echo esc_attr( wp_json_encode( $missing_pillar_notes ) ); ?>'
             data-biw-band-thresholds="25,50,75"
             data-biw-band-labels="Low,Medium,High,Extreme"
             data-biw-band-select-label="All Risk Levels"
             data-biw-pillars='<?php echo esc_attr( wp_json_encode( $pillars ) ); ?>'
             data-biw-methodology="<?php echo esc_attr( $methodology ); ?>">
        </div>
        <?php
        return ob_get_clean();
    }
    add_shortcode( 'blomstra_seri_index', 'seri_render_index_shortcode' );
}

// ─── DEPRECATED: Keep old shortcode as alias ──────────────────────
if ( ! function_exists( 'geri_render_index_shortcode' ) ) {
    function geri_render_index_shortcode( $atts ) {
        return seri_render_index_shortcode( $atts );
    }
    add_shortcode( 'blomstra_geri_index', 'geri_render_index_shortcode' );
}
