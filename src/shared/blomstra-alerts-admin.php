/**
 * Blomstra Alert Webhook – Admin Configuration Page
 *
 * @package Blomstra\Insights\Alerts
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the admin menu page under "Blomstra Insights Tools".
 */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'blomstra-insights-tools',          // Parent slug
        '⚡ Alerts',                        // Page title
        '⚡ Alerts',                        // Menu title
        'manage_options',                   // Capability
        'blomstra-alerts',                  // Menu slug
        'blomstra_alerts_render_page'       // Callback
    );
}, 15 );

/**
 * Render the Alert Configuration page.
 */
function blomstra_alerts_render_page() {
    // ─── Handle form submission ──────────────────────────────────
    if ( isset( $_POST['blomstra_alert_config'] ) && check_admin_referer( 'blomstra_alert_config_action' ) ) {
        $config = array(
            'enabled'       => isset( $_POST['alert_enabled'] ) ? 1 : 0,
            'email'         => sanitize_email( $_POST['alert_email'] ),
            'webhook_url'   => esc_url_raw( trim( $_POST['alert_webhook_url'] ) ),
            'slack_url'     => esc_url_raw( trim( $_POST['alert_slack_url'] ) ),
            'store_alerts'  => isset( $_POST['alert_store_alerts'] ) ? 1 : 0,
        );
        update_option( 'blomstra_alert_config', $config, false );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Alert configuration saved.</p></div>';
    }

    // ─── Get current config ──────────────────────────────────────
    $config = blomstra_get_alert_config();

    ?>
    <div class="wrap">
        <h1>⚡ Alert Webhook System</h1>
        <p style="color:#666;">Monitor all indices and get notified when countries change rank, score, or any pillar value.</p>

        <!-- ─── Configuration Form ──────────────────────────────── -->
        <div class="postbox" style="border-left:4px solid #2271b1;">
            <div class="postbox-header"><h2 class="hndle">📨 Alert Configuration</h2></div>
            <div class="inside">
                <form method="post">
                    <?php wp_nonce_field( 'blomstra_alert_config_action' ); ?>
                    <input type="hidden" name="blomstra_alert_config" value="1">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="alert_enabled">Enable alert system</label>
                            </th>
                            <td>
                                <input type="checkbox" id="alert_enabled" name="alert_enabled" value="1" <?php checked( $config['enabled'], 1 ); ?>>
                                <p class="description">When enabled, alerts will fire after every index rebuild.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="alert_email">📧 Email <span style="color:#d63638;">*</span></label>
                            </th>
                            <td>
                                <input type="email" id="alert_email" name="alert_email" value="<?php echo esc_attr( $config['email'] ); ?>" class="regular-text" required>
                                <p class="description">Alerts will be sent to this email. Default: <code>blomstrainsights@gmail.com</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="alert_webhook_url">🔗 Webhook URL</label>
                            </th>
                            <td>
                                <input type="url" id="alert_webhook_url" name="alert_webhook_url" value="<?php echo esc_attr( $config['webhook_url'] ); ?>" class="regular-text" placeholder="https://your-service.com/webhook">
                                <p class="description">Custom webhook endpoint for integrations (Zapier, Make, custom services).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="alert_slack_url">💬 Slack Webhook URL</label>
                            </th>
                            <td>
                                <input type="url" id="alert_slack_url" name="alert_slack_url" value="<?php echo esc_attr( $config['slack_url'] ); ?>" class="regular-text" placeholder="https://hooks.slack.com/services/...">
                                <p class="description">Send alerts directly to a Slack channel.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="alert_store_alerts">💾 Store alerts in database</label>
                            </th>
                            <td>
                                <input type="checkbox" id="alert_store_alerts" name="alert_store_alerts" value="1" <?php checked( $config['store_alerts'], 1 ); ?>>
                                <p class="description">Save alerts for later reporting and analysis.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">💾 Save Alert Configuration</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- ─── Recent Alerts ────────────────────────────────────── -->
        <?php if ( $config['store_alerts'] ) : ?>
        <div class="postbox" style="border-left:4px solid #00a0d2;">
            <div class="postbox-header"><h2 class="hndle">📊 Recent Alerts</h2></div>
            <div class="inside">
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'blomstra_alerts';
                $alerts = $wpdb->get_results(
                    "SELECT * FROM $table 
                     ORDER BY triggered_at DESC 
                     LIMIT 50"
                );

                if ( empty( $alerts ) ) {
                    echo '<p style="color:#666;">No alerts recorded yet. Trigger a rebuild to see alerts here.</p>';
                } else {
                    echo '<div style="overflow-x:auto;">';
                    echo '<table class="widefat striped">';
                    echo '<thead>
                            <tr>
                                <th>Time</th>
                                <th>Index</th>
                                <th>Country</th>
                                <th>Rank</th>
                                <th>Score</th>
                                <th>Pillar Changes</th>
                                <th>Reason</th>
                            </tr>
                          </thead>';
                    echo '<tbody>';
                    foreach ( $alerts as $alert ) {
                        $rank_display = '';
                        if ( $alert->previous_rank !== null && $alert->current_rank !== null ) {
                            $arrow = $alert->rank_delta < 0 ? '▲' : '▼';
                            $rank_display = '#' . $alert->previous_rank . ' → #' . $alert->current_rank . ' (' . $arrow . abs( $alert->rank_delta ) . ')';
                        } elseif ( $alert->previous_rank === null && $alert->current_rank !== null ) {
                            $rank_display = 'NEW: #' . $alert->current_rank;
                        } elseif ( $alert->previous_rank !== null && $alert->current_rank === null ) {
                            $rank_display = 'REMOVED (#' . $alert->previous_rank . ')';
                        } else {
                            $rank_display = '—';
                        }

                        $score_display = '';
                        if ( $alert->previous_score !== null && $alert->current_score !== null ) {
                            $sign = $alert->score_delta > 0 ? '+' : '';
                            $score_display = $alert->previous_score . ' → ' . $alert->current_score . ' (' . $sign . $alert->score_delta . ')';
                        } elseif ( $alert->previous_score === null && $alert->current_score !== null ) {
                            $score_display = 'NEW: ' . $alert->current_score;
                        } elseif ( $alert->previous_score !== null && $alert->current_score === null ) {
                            $score_display = 'REMOVED (' . $alert->previous_score . ')';
                        } else {
                            $score_display = '—';
                        }

                        // Pillar changes
                        $pillar_display = '';
                        if ( ! empty( $alert->previous_pillars ) || ! empty( $alert->current_pillars ) ) {
                            $prev = json_decode( $alert->previous_pillars, true ) ?: array();
                            $curr = json_decode( $alert->current_pillars, true ) ?: array();
                            $deltas = array();
                            foreach ( $curr as $pillar => $val ) {
                                if ( isset( $prev[ $pillar ] ) && abs( $val - $prev[ $pillar ] ) >= 0.01 ) {
                                    $delta = round( $val - $prev[ $pillar ], 2 );
                                    $sign = $delta > 0 ? '+' : '';
                                    $deltas[] = ucfirst( $pillar ) . ' ' . $sign . $delta;
                                }
                            }
                            if ( ! empty( $deltas ) ) {
                                $pillar_display = implode( ', ', $deltas );
                            }
                        }

                        echo '<tr>';
                        echo '<td>' . esc_html( $alert->triggered_at ) . '</td>';
                        echo '<td><strong>' . esc_html( strtoupper( $alert->index_slug ) ) . '</strong></td>';
                        echo '<td>' . esc_html( $alert->country_name ) . '</td>';
                        echo '<td>' . esc_html( $rank_display ) . '</td>';
                        echo '<td>' . esc_html( $score_display ) . '</td>';
                        echo '<td>' . esc_html( $pillar_display ) . '</td>';
                        echo '<td><code>' . esc_html( $alert->alert_reason ) . '</code></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';

                    // Show total count
                    $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
                    echo '<p style="margin-top:10px; color:#666;">Total alerts recorded: ' . esc_html( $total ) . '</p>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Quick Test / Info ────────────────────────────────── -->
        <div class="postbox" style="border-left:4px solid #f56e28;">
            <div class="postbox-header"><h2 class="hndle">ℹ️ How Alerts Work</h2></div>
            <div class="inside">
                <ul style="list-style:disc; padding-left:20px;">
                    <li><strong>Trigger:</strong> After every successful index rebuild (SIVI, SERI, etc.).</li>
                    <li><strong>What is tracked:</strong> Rank, composite score, and <strong>each pillar</strong> (Energy, Supplier Concentration, Maritime, etc.).</li>
                    <li><strong>Change detection:</strong> Any change in rank, score, or any pillar will fire an alert.</li>
                    <li><strong>Delivery:</strong> Email (mandatory), optional webhook (JSON payload), and optional Slack integration.</li>
                    <li><strong>History:</strong> Alerts are stored in the database for reporting and analysis.</li>
                    <li><strong>Integration:</strong> The alert system is automatically called after each rebuild – no manual action needed.</li>
                </ul>
                <p style="margin-top:10px;">
                    <strong>Webhook JSON Payload</strong> – includes all changes with old/new values and pillar deltas.
                    <br>
                    <code>POST</code> to your configured URL with <code>Content-Type: application/json</code>.
                </p>
            </div>
        </div>

    </div>
    <?php
}
