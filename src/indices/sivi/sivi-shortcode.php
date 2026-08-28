if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'blomstra_sivi_index', function () {
    $pillars = array(
        array( 'key' => 'energy_dependency_percentile', 'raw_key' => 'energy_dependency_raw', 'label' => 'Energy Dependency', 'color' => '#60a5fa' ),
        array( 'key' => 'supplier_concentration_percentile', 'raw_key' => 'supplier_concentration_raw', 'label' => 'Supplier Concentration', 'color' => '#f87171' ),
        array( 'key' => 'maritime_vulnerability_percentile', 'raw_key' => 'maritime_connectivity_raw', 'label' => 'Maritime Exposure', 'color' => '#fb923c' ),
    );
    $methodology = 'The Sovereign Infrastructure Vulnerability Index (SIVI) combines three pillars — ' .
        '<strong>Energy Dependency</strong> (EIA, consumption-share-weighted across five fuels), ' .
        '<strong>Supplier Concentration</strong> (UN Comtrade import HHI), and ' .
        '<strong>Maritime Exposure</strong> (World Bank LSCI, inverted to a vulnerability orientation). ' .
        '<strong>Higher scores indicate higher vulnerability (rank #1 is the most vulnerable country).</strong> ' .
        '<a href="https://blomstrainsights.com/methodology/sivi" target="_blank" rel="noopener">Full methodology →</a>';
    $pillars_json = wp_json_encode( $pillars );
    ob_start();
    ?>
    <div class="biw"
         data-biw-slug="sivi"
         data-biw-endpoint="/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index"
         data-biw-names-endpoint="/wp-json/blomstra/v1/country-names"
         data-biw-title="Sovereign Infrastructure Vulnerability Index"
         data-biw-subtitle="A country-level assessment of exposure, dependency, and systemic weakness"
         data-biw-eyebrow="Strategic Intelligence"
         data-biw-score-key="sivi_structural"
         data-biw-score-label="Vulnerability Score"
         data-biw-coverage-key="coverage"
         data-biw-band-thresholds="25,50,75"
         data-biw-band-labels="Low,Medium,High,Extreme"
         data-biw-band-select-label="All Vulnerability Levels"
         data-biw-pillars='<?php echo esc_attr( $pillars_json ); ?>'
         data-biw-methodology="<?php echo esc_attr( $methodology ); ?>"
         data-biw-view="dashboard">
    </div>
    <?php
    return ob_get_clean();
} );
