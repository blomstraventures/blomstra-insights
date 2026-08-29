<?php
/**
 * Blomstra Alert Webhook System
 *
 * @package Blomstra\Insights\Alerts
 * @since   1.0.0
 * @version 2.0.3  – Removed unnecessary scroll-on-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// 1. CONSTANTS & DEFAULTS
// ============================================================

define( 'BLOMSTRA_ALERT_VERSION', '2.0.3' );
define( 'BLOMSTRA_ALERT_COOLDOWN_DEFAULT', 5 * MINUTE_IN_SECONDS );
define( 'BLOMSTRA_ALERT_TABLE', 'blomstra_alerts' );
define( 'BLOMSTRA_ALERT_PER_PAGE', 100 );
define( 'BLOMSTRA_ALERT_RETENTION_DEFAULT', 45 );
define( 'BLOMSTRA_ALERT_MAX_PER_INDEX_DEFAULT', 500 );

// ============================================================
// 2. DATABASE TABLE CREATION
// ============================================================

function blomstra_alerts_maybe_install_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        index_slug VARCHAR(40) NOT NULL,
        iso3 VARCHAR(3) DEFAULT NULL,
        country_name VARCHAR(100) DEFAULT NULL,
        previous_rank INT DEFAULT NULL,
        current_rank INT DEFAULT NULL,
        previous_score DECIMAL(6,2) DEFAULT NULL,
        current_score DECIMAL(6,2) DEFAULT NULL,
        rank_delta INT DEFAULT NULL,
        score_delta DECIMAL(6,2) DEFAULT NULL,
        previous_pillars LONGTEXT DEFAULT NULL,
        current_pillars LONGTEXT DEFAULT NULL,
        alert_reason VARCHAR(100) DEFAULT NULL,
        log_type VARCHAR(20) DEFAULT 'alert',
        triggered_at DATETIME NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_slug (index_slug),
        KEY idx_iso3 (iso3),
        KEY idx_triggered_at (triggered_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'admin_init', 'blomstra_alerts_maybe_install_table' );

function blomstra_alerts_add_log_type_column() {
    global $wpdb;
    $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;
    $columns = $wpdb->get_results( "DESCRIBE $table" );
    $column_names = array_column( $columns, 'Field' );
    if ( ! in_array( 'log_type', $column_names ) ) {
        $wpdb->query( "ALTER TABLE $table ADD COLUMN log_type VARCHAR(20) DEFAULT 'alert'" );
        error_log( 'ALERT: Added log_type column to blomstra_alerts table.' );
        $wpdb->query( "UPDATE $table SET log_type = 'alert' WHERE log_type IS NULL" );
        set_transient( 'blomstra_alerts_column_added', 1, DAY_IN_SECONDS );
    }
}
add_action( 'admin_init', 'blomstra_alerts_add_log_type_column' );

// ============================================================
// 3. CONFIGURATION
// ============================================================

function blomstra_get_alert_config() {
    $default = array(
        'enabled'          => 0,
        'email'            => 'blomstrainsights@gmail.com',
        'webhook_url'      => '',
        'slack_url'        => '',
        'store_alerts'     => 1,
        'retention_days'   => BLOMSTRA_ALERT_RETENTION_DEFAULT,
        'max_per_index'    => BLOMSTRA_ALERT_MAX_PER_INDEX_DEFAULT,
    );
    $config = get_option( 'blomstra_alert_config', $default );
    return array_merge( $default, $config );
}

function blomstra_save_alert_config( $config ) {
    update_option( 'blomstra_alert_config', $config, false );
}

// ============================================================
// 4. LOGGING
// ============================================================

function blomstra_alert_log( $level, $message, $index_slug = null, $context = array() ) {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        $prefix = '[ALERT][' . strtoupper( $level ) . ']';
        if ( $index_slug ) {
            $prefix .= '[' . strtoupper( $index_slug ) . ']';
        }
        error_log( $prefix . ' ' . $message );
    }

    global $wpdb;
    $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;
    $wpdb->insert( $table, array(
        'index_slug'   => $index_slug ?: 'system',
        'country_name' => $context['country'] ?? null,
        'iso3'         => $context['iso3'] ?? null,
        'alert_reason' => $message,
        'log_type'     => $level,
        'triggered_at' => current_time( 'mysql' ),
    ) );
}

// ============================================================
// 5. CHANGE DETECTION
// ============================================================

function blomstra_alert_normalize_country( $country_data, $index_slug ) {
    $rank = null;
    if ( isset( $country_data['rank'] ) && is_numeric( $country_data['rank'] ) ) {
        $rank = (int) $country_data['rank'];
    } elseif ( isset( $country_data['rank_display']['best_estimate'] ) && is_numeric( $country_data['rank_display']['best_estimate'] ) ) {
        $rank = (int) $country_data['rank_display']['best_estimate'];
    }

    $score = null;
    $score_key = ( $index_slug === 'sivi' ) ? 'sivi_structural' : 'score';
    if ( isset( $country_data[ $score_key ] ) && is_numeric( $country_data[ $score_key ] ) ) {
        $score = (float) $country_data[ $score_key ];
    } elseif ( isset( $country_data['composite_score'] ) && is_numeric( $country_data['composite_score'] ) ) {
        $score = (float) $country_data['composite_score'];
    }

    $pillars = array();
    $raw_pillars = $country_data['pillars'] ?? array();
    foreach ( $raw_pillars as $name => $value ) {
        if ( is_array( $value ) && isset( $value['score'] ) && is_numeric( $value['score'] ) ) {
            $pillars[ $name ] = (float) $value['score'];
        } elseif ( is_numeric( $value ) ) {
            $pillars[ $name ] = (float) $value;
        }
    }

    return array(
        'rank'    => $rank,
        'score'   => $score,
        'pillars' => $pillars,
        'name'    => $country_data['name'] ?? $country_data['country'] ?? 'Unknown',
    );
}

function blomstra_alert_detect_changes( $new_data, $old_data, $index_slug ) {
    $changes = array();

    foreach ( $new_data as $iso3 => $current_raw ) {
        $current = blomstra_alert_normalize_country( $current_raw, $index_slug );

        if ( ! isset( $old_data[ $iso3 ] ) ) {
            $changes[] = array(
                'iso3'            => $iso3,
                'country'         => $current['name'],
                'type'            => 'new',
                'current_rank'    => $current['rank'],
                'current_score'   => $current['score'],
                'previous_rank'   => null,
                'previous_score'  => null,
                'rank_delta'      => null,
                'score_delta'     => null,
                'previous_pillars'=> null,
                'current_pillars' => $current['pillars'],
                'pillar_deltas'   => array(),
                'alert_reason'    => 'new_country'
            );
            continue;
        }

        $previous_raw = $old_data[ $iso3 ];
        $previous = blomstra_alert_normalize_country( $previous_raw, $index_slug );

        $rank_delta = null;
        $score_delta = null;
        $alert_reason = array();
        $pillar_deltas = array();

        if ( $current['rank'] !== null && $previous['rank'] !== null && $current['rank'] !== $previous['rank'] ) {
            $rank_delta = $current['rank'] - $previous['rank'];
            $alert_reason[] = 'rank_change';
        }

        if ( $current['score'] !== null && $previous['score'] !== null && abs( $current['score'] - $previous['score'] ) >= 0.01 ) {
            $score_delta = round( $current['score'] - $previous['score'], 2 );
            $alert_reason[] = 'score_change';
        }

        foreach ( $current['pillars'] as $pname => $cval ) {
            $pval = $previous['pillars'][ $pname ] ?? null;
            if ( $pval !== null && abs( $cval - $pval ) >= 0.01 ) {
                $pillar_deltas[ $pname ] = round( $cval - $pval, 2 );
                $alert_reason[] = 'pillar_change_' . $pname;
            }
        }

        if ( empty( $alert_reason ) ) {
            continue;
        }

        $changes[] = array(
            'iso3'             => $iso3,
            'country'          => $current['name'],
            'type'             => 'change',
            'current_rank'     => $current['rank'],
            'current_score'    => $current['score'],
            'previous_rank'    => $previous['rank'],
            'previous_score'   => $previous['score'],
            'rank_delta'       => $rank_delta,
            'score_delta'      => $score_delta,
            'previous_pillars' => $previous['pillars'],
            'current_pillars'  => $current['pillars'],
            'pillar_deltas'    => $pillar_deltas,
            'alert_reason'     => implode( ', ', $alert_reason )
        );
    }

    foreach ( $old_data as $iso3 => $previous_raw ) {
        if ( ! isset( $new_data[ $iso3 ] ) ) {
            $previous = blomstra_alert_normalize_country( $previous_raw, $index_slug );
            $changes[] = array(
                'iso3'             => $iso3,
                'country'          => $previous['name'],
                'type'             => 'excluded',
                'current_rank'     => null,
                'current_score'    => null,
                'previous_rank'    => $previous['rank'],
                'previous_score'   => $previous['score'],
                'rank_delta'       => null,
                'score_delta'      => null,
                'previous_pillars' => $previous['pillars'],
                'current_pillars'  => null,
                'pillar_deltas'    => array(),
                'alert_reason'     => 'excluded'
            );
        }
    }

    return $changes;
}

// ============================================================
// 6. STORAGE
// ============================================================

function blomstra_alert_store_records( $index_slug, $changes ) {
    global $wpdb;
    $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;

    foreach ( $changes as $change ) {
        $wpdb->insert( $table, array(
            'index_slug'       => $index_slug,
            'iso3'             => $change['iso3'],
            'country_name'     => $change['country'],
            'previous_rank'    => $change['previous_rank'],
            'current_rank'     => $change['current_rank'],
            'previous_score'   => $change['previous_score'],
            'current_score'    => $change['current_score'],
            'rank_delta'       => $change['rank_delta'],
            'score_delta'      => $change['score_delta'],
            'previous_pillars' => $change['previous_pillars'] ? wp_json_encode( $change['previous_pillars'] ) : null,
            'current_pillars'  => $change['current_pillars'] ? wp_json_encode( $change['current_pillars'] ) : null,
            'alert_reason'     => $change['alert_reason'],
            'log_type'         => 'alert',
            'triggered_at'     => current_time( 'mysql' ),
        ) );
    }
}

// ============================================================
// 7. NOTIFICATIONS
// ============================================================

function blomstra_alert_send_email( $index_slug, $changes, $config ) {
    if ( empty( $config['email'] ) ) {
        return;
    }

    $total = count( $changes );
    $subject = '🔔 ' . strtoupper( $index_slug ) . ' – ' . $total . ' changes detected';
    $message = "Index: " . strtoupper( $index_slug ) . "\n";
    $message .= "Time: " . current_time( 'mysql' ) . "\n";
    $message .= "Total changes: " . $total . "\n\n";

    $new_count = count( array_filter( $changes, function( $c ) { return $c['type'] === 'new'; } ) );
    $excluded_count = count( array_filter( $changes, function( $c ) { return $c['type'] === 'excluded'; } ) );
    $rank_movers = count( array_filter( $changes, function( $c ) { return $c['rank_delta'] !== null && $c['rank_delta'] !== 0; } ) );
    $score_movers = count( array_filter( $changes, function( $c ) { return $c['score_delta'] !== null && $c['score_delta'] !== 0; } ) );
    $pillar_changes = count( array_filter( $changes, function( $c ) {
        return isset( $c['pillar_deltas'] ) && ! empty( $c['pillar_deltas'] );
    } ) );

    if ( $total > 10 ) {
        $message .= "📊 Summary:\n";
        $message .= "- Total: $total\n";
        $message .= "- New countries: $new_count\n";
        $message .= "- Excluded countries: $excluded_count\n";
        $message .= "- Rank changes: $rank_movers\n";
        $message .= "- Score changes: $score_movers\n";
        $message .= "- Pillar changes: $pillar_changes\n\n";

        $message .= "📋 Top 10 changes:\n";
        $top = array_slice( $changes, 0, 10 );
        $i = 1;
        foreach ( $top as $change ) {
            $line = $i . '. ' . $change['country'] . ' (#' . $change['iso3'] . ')';
            if ( $change['type'] === 'new' ) {
                $line .= ' – NEW (Rank: #' . $change['current_rank'] . ', Score: ' . $change['current_score'] . ')';
            } elseif ( $change['type'] === 'excluded' ) {
                $line .= ' – REMOVED';
            } else {
                if ( $change['rank_delta'] !== null ) {
                    $arrow = $change['rank_delta'] < 0 ? '▼' : '▲';
                    $line .= ' – Rank: #' . $change['previous_rank'] . ' → #' . $change['current_rank'] . ' (' . $arrow . abs( $change['rank_delta'] ) . ')';
                }
                if ( $change['score_delta'] !== null ) {
                    $sign = $change['score_delta'] > 0 ? '+' : '';
                    $line .= ', Score: ' . $sign . $change['score_delta'];
                }
                if ( ! empty( $change['pillar_deltas'] ) ) {
                    $parts = array();
                    foreach ( $change['pillar_deltas'] as $pname => $delta ) {
                        $sign = $delta > 0 ? '+' : '';
                        $parts[] = ucfirst( $pname ) . ' ' . $sign . $delta;
                    }
                    $line .= ', Pillars: ' . implode( ', ', $parts );
                }
            }
            $message .= $line . "\n";
            $i++;
        }

        $message .= "\n🔗 View all $total changes: " . admin_url( 'admin.php?page=blomstra-alerts' ) . "\n";
    } else {
        $message .= "Changes:\n";
        $message .= str_repeat( '-', 80 ) . "\n";
        foreach ( $changes as $change ) {
            $line = $change['country'] . ' (#' . $change['iso3'] . ')';
            if ( $change['type'] === 'new' ) {
                $line .= ' – NEW! Rank: #' . $change['current_rank'] . ' Score: ' . $change['current_score'];
            } elseif ( $change['type'] === 'excluded' ) {
                $line .= ' – REMOVED';
            } else {
                if ( $change['rank_delta'] !== null ) {
                    $arrow = $change['rank_delta'] < 0 ? '▲' : '▼';
                    $line .= ' – Rank: #' . $change['previous_rank'] . ' → #' . $change['current_rank'] . ' (' . $arrow . abs( $change['rank_delta'] ) . ')';
                }
                if ( $change['score_delta'] !== null ) {
                    $sign = $change['score_delta'] > 0 ? '+' : '';
                    $line .= ' – Score: ' . $change['previous_score'] . ' → ' . $change['current_score'] . ' (' . $sign . $change['score_delta'] . ')';
                }
                if ( ! empty( $change['pillar_deltas'] ) ) {
                    foreach ( $change['pillar_deltas'] as $pname => $delta ) {
                        $sign = $delta > 0 ? '+' : '';
                        $line .= ' – ' . ucfirst( $pname ) . ': ' . $sign . $delta;
                    }
                }
            }
            $message .= $line . "\n";
        }
    }

    $message .= "\n---\nBlomstra Insights Alert System";
    wp_mail( $config['email'], $subject, $message );
}

function blomstra_alert_send_webhook( $payload, $config ) {
    if ( empty( $config['webhook_url'] ) ) return;
    wp_remote_post( $config['webhook_url'], array(
        'headers'  => array( 'Content-Type' => 'application/json' ),
        'body'     => wp_json_encode( $payload ),
        'timeout'  => 10,
    ) );
}

function blomstra_alert_send_slack( $index_slug, $changes, $config ) {
    if ( empty( $config['slack_url'] ) ) return;

    $total = count( $changes );
    $text = '🔔 *' . strtoupper( $index_slug ) . '* – ' . $total . ' changes detected';
    if ( $total > 10 ) {
        $text .= "\nTop 5 changes:\n";
        $top = array_slice( $changes, 0, 5 );
        foreach ( $top as $change ) {
            $line = $change['country'];
            if ( $change['type'] === 'new' ) {
                $line .= ' – *NEW* (Rank: #' . $change['current_rank'] . ', Score: ' . $change['current_score'] . ')';
            } elseif ( $change['type'] === 'excluded' ) {
                $line .= ' – *REMOVED*';
            } else {
                if ( $change['rank_delta'] !== null ) {
                    $arrow = $change['rank_delta'] < 0 ? '↓' : '↑';
                    $line .= ' – Rank: #' . $change['previous_rank'] . ' → #' . $change['current_rank'] . ' (' . $arrow . abs( $change['rank_delta'] ) . ')';
                }
                if ( $change['score_delta'] !== null ) {
                    $sign = $change['score_delta'] > 0 ? '+' : '';
                    $line .= ', Score: ' . $sign . $change['score_delta'];
                }
            }
            $text .= "\n• " . $line;
        }
        if ( $total > 5 ) {
            $text .= "\n\n…and " . ( $total - 5 ) . ' more changes. See admin panel for full list.';
        }
    } else {
        $text .= "\nChanges:\n";
        foreach ( $changes as $change ) {
            $line = $change['country'];
            if ( $change['type'] === 'new' ) {
                $line .= ' – NEW (Rank: #' . $change['current_rank'] . ', Score: ' . $change['current_score'] . ')';
            } elseif ( $change['type'] === 'excluded' ) {
                $line .= ' – REMOVED';
            } else {
                if ( $change['rank_delta'] !== null ) {
                    $arrow = $change['rank_delta'] < 0 ? '↓' : '↑';
                    $line .= ' – Rank: #' . $change['previous_rank'] . ' → #' . $change['current_rank'] . ' (' . $arrow . abs( $change['rank_delta'] ) . ')';
                }
                if ( $change['score_delta'] !== null ) {
                    $sign = $change['score_delta'] > 0 ? '+' : '';
                    $line .= ', Score: ' . $sign . $change['score_delta'];
                }
            }
            $text .= "\n• " . $line;
        }
    }

    $slack_payload = array( 'text' => $text );
    wp_remote_post( $config['slack_url'], array(
        'headers'  => array( 'Content-Type' => 'application/json' ),
        'body'     => wp_json_encode( $slack_payload ),
        'timeout'  => 10,
    ) );
}

// ============================================================
// 8. ORCHESTRATOR
// ============================================================

function blomstra_fire_index_alerts( $index_slug, $new_data, $old_data, $new_meta, $old_meta ) {
    $config = blomstra_get_alert_config();
    if ( empty( $config['enabled'] ) ) {
        blomstra_alert_log( 'info', 'Alerts disabled, skipping.', $index_slug );
        return 0;
    }

    $cooldown = apply_filters( 'blomstra_alert_cooldown', BLOMSTRA_ALERT_COOLDOWN_DEFAULT, $index_slug );
    $cooldown_key = 'blomstra_alert_cooldown_' . $index_slug;
    $last_run = get_transient( $cooldown_key );

    $changes = blomstra_alert_detect_changes( $new_data, $old_data, $index_slug );

    if ( empty( $changes ) ) {
        blomstra_alert_log( 'info', 'No changes detected, no alert fired.', $index_slug );
        return 0;
    }

    $change_hash = md5( wp_json_encode( $changes ) );
    if ( $last_run && isset( $last_run['hash'] ) && $last_run['hash'] === $change_hash ) {
        blomstra_alert_log( 'warning', 'Duplicate alert skipped (cooldown).', $index_slug );
        return 0;
    }

    set_transient( $cooldown_key, array(
        'hash' => $change_hash,
        'time' => time(),
    ), $cooldown );

    if ( $config['store_alerts'] ) {
        blomstra_alert_store_records( $index_slug, $changes );
    }

    $payload = array(
        'payload_version' => '2',
        'alert_id'        => $change_hash,
        'event'           => 'index_alert',
        'timestamp'       => current_time( 'mysql' ),
        'index_slug'      => $index_slug,
        'new_meta'        => $new_meta,
        'old_meta'        => $old_meta,
        'changes'         => array_map( function( $change ) {
            $parts = array();
            if ( $change['type'] === 'new' ) {
                $parts[] = 'NEW (Rank: #' . $change['current_rank'] . ', Score: ' . $change['current_score'] . ')';
            } elseif ( $change['type'] === 'excluded' ) {
                $parts[] = 'REMOVED';
            } else {
                if ( $change['rank_delta'] !== null ) {
                    $arrow = $change['rank_delta'] < 0 ? '▼' : '▲';
                    $parts[] = 'Rank: #' . $change['previous_rank'] . ' → #' . $change['current_rank'] . ' (' . $arrow . abs( $change['rank_delta'] ) . ')';
                }
                if ( $change['score_delta'] !== null ) {
                    $sign = $change['score_delta'] > 0 ? '+' : '';
                    $parts[] = 'Score: ' . $change['previous_score'] . ' → ' . $change['current_score'] . ' (' . $sign . $change['score_delta'] . ')';
                }
                if ( ! empty( $change['pillar_deltas'] ) ) {
                    foreach ( $change['pillar_deltas'] as $pname => $delta ) {
                        $sign = $delta > 0 ? '+' : '';
                        $parts[] = ucfirst( $pname ) . ': ' . $sign . $delta;
                    }
                }
            }
            $change['human'] = $change['country'] . ' – ' . implode( ', ', $parts );
            return $change;
        }, $changes ),
        'summary' => array(
            'total'          => count( $changes ),
            'new'            => count( array_filter( $changes, function( $c ) { return $c['type'] === 'new'; } ) ),
            'excluded'       => count( array_filter( $changes, function( $c ) { return $c['type'] === 'excluded'; } ) ),
            'rank_movers'    => count( array_filter( $changes, function( $c ) { return $c['rank_delta'] !== null && $c['rank_delta'] !== 0; } ) ),
            'score_movers'   => count( array_filter( $changes, function( $c ) { return $c['score_delta'] !== null && $c['score_delta'] !== 0; } ) ),
            'pillar_changes' => count( array_filter( $changes, function( $c ) {
                return isset( $c['pillar_deltas'] ) && ! empty( $c['pillar_deltas'] );
            } ) ),
        )
    );

    blomstra_alert_send_email( $index_slug, $changes, $config );
    blomstra_alert_send_webhook( $payload, $config );
    blomstra_alert_send_slack( $index_slug, $changes, $config );

    blomstra_alert_log( 'info', 'Alert fired: ' . count( $changes ) . ' changes detected.', $index_slug );
    return count( $changes );
}

// ============================================================
// 9. RETENTION & CLEANUP
// ============================================================

function blomstra_alert_cleanup() {
    $config = blomstra_get_alert_config();
    $retention_days = (int) $config['retention_days'];
    $max_per_index = (int) $config['max_per_index'];

    global $wpdb;
    $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;

    if ( $retention_days > 0 ) {
        $deleted_old = $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE log_type = 'alert' AND triggered_at < NOW() - INTERVAL %d DAY",
            $retention_days
        ) );
        blomstra_alert_log( 'info', "Cleanup: deleted $deleted_old alerts older than $retention_days days.", 'system' );
    }

    if ( $max_per_index > 0 ) {
        $indices = $wpdb->get_col( "SELECT DISTINCT index_slug FROM $table WHERE log_type = 'alert'" );
        foreach ( $indices as $index ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table 
                 WHERE log_type = 'alert' 
                 AND index_slug = %s 
                 AND id NOT IN (
                     SELECT id FROM (
                         SELECT id FROM $table 
                         WHERE log_type = 'alert' AND index_slug = %s 
                         ORDER BY triggered_at DESC 
                         LIMIT %d
                     ) AS keep
                 )",
                $index, $index, $max_per_index
            ) );
        }
        blomstra_alert_log( 'info', "Cleanup: trimmed each index to $max_per_index alerts.", 'system' );
    }
}

function blomstra_alert_cleanup_cron() {
    if ( ! wp_next_scheduled( 'blomstra_alert_cleanup_daily' ) ) {
        wp_schedule_event( time() + 3600, 'daily', 'blomstra_alert_cleanup_daily' );
    }
}
add_action( 'init', 'blomstra_alert_cleanup_cron' );
add_action( 'blomstra_alert_cleanup_daily', 'blomstra_alert_cleanup' );

// ============================================================
// 10. ADMIN UI
// ============================================================

add_action( 'admin_menu', function () {
    add_submenu_page(
        'blomstra-insights-tools',
        '⚡ Alerts',
        '⚡ Alerts',
        'manage_options',
        'blomstra-alerts',
        'blomstra_alerts_render_page'
    );
}, 15 );

function blomstra_alerts_render_page() {
    // Handle POST actions
    if ( isset( $_POST['blomstra_alert_config'] ) && check_admin_referer( 'blomstra_alert_config_action' ) ) {
        $config = array(
            'enabled'          => isset( $_POST['alert_enabled'] ) ? 1 : 0,
            'email'            => sanitize_email( $_POST['alert_email'] ),
            'webhook_url'      => esc_url_raw( trim( $_POST['alert_webhook_url'] ) ),
            'slack_url'        => esc_url_raw( trim( $_POST['alert_slack_url'] ) ),
            'store_alerts'     => isset( $_POST['alert_store_alerts'] ) ? 1 : 0,
            'retention_days'   => (int) $_POST['alert_retention_days'],
            'max_per_index'    => (int) $_POST['alert_max_per_index'],
        );
        blomstra_save_alert_config( $config );
        blomstra_alert_log( 'config', 'Alert configuration updated by admin.', 'system' );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Alert configuration saved.</p></div>';
    }

    if ( isset( $_POST['blomstra_alert_cleanup_now'] ) && check_admin_referer( 'blomstra_alert_cleanup_now_action' ) ) {
        blomstra_alert_cleanup();
        echo '<div class="notice notice-success is-dismissible"><p>🧹 Cleanup completed. Old alerts removed and indices trimmed.</p></div>';
    }

    if ( isset( $_POST['blomstra_alert_flush'] ) && check_admin_referer( 'blomstra_alert_flush_action' ) ) {
        $target = sanitize_text_field( $_POST['flush_target'] );
        global $wpdb;
        $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;
        if ( $target === 'all' ) {
            $wpdb->query( "TRUNCATE TABLE $table" );
            echo '<div class="notice notice-warning"><p>🗑️ All alerts have been permanently deleted.</p></div>';
        } else {
            $wpdb->delete( $table, array( 'index_slug' => $target ) );
            echo '<div class="notice notice-warning"><p>🗑️ Alerts for <strong>' . esc_html( strtoupper( $target ) ) . '</strong> have been permanently deleted.</p></div>';
        }
    }

    if ( isset( $_POST['blomstra_alert_bulk_delete'] ) && check_admin_referer( 'blomstra_alert_bulk_delete_action' ) ) {
        if ( ! empty( $_POST['alert_ids'] ) && is_array( $_POST['alert_ids'] ) ) {
            $ids = array_map( 'intval', $_POST['alert_ids'] );
            $ids_string = implode( ',', $ids );
            global $wpdb;
            $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;
            $wpdb->query( "DELETE FROM $table WHERE id IN ($ids_string)" );
            echo '<div class="notice notice-warning"><p>🗑️ Selected alerts have been deleted.</p></div>';
        }
    }

    $config = blomstra_get_alert_config();
    ?>
    <div class="wrap">
        <h1>⚡ Alert Webhook System</h1>
        <p style="color:#666;">Monitor all indices and get notified when countries change rank, score, or any pillar value.</p>

        <!-- Configuration Form -->
        <div class="postbox" style="border-left:4px solid #2271b1;">
            <div class="postbox-header"><h2 class="hndle">📨 Alert Configuration</h2></div>
            <div class="inside">
                <form method="post">
                    <?php wp_nonce_field( 'blomstra_alert_config_action' ); ?>
                    <input type="hidden" name="blomstra_alert_config" value="1">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="alert_enabled">Enable alert system</label></th>
                            <td>
                                <input type="checkbox" id="alert_enabled" name="alert_enabled" value="1" <?php checked( $config['enabled'], 1 ); ?>>
                                <p class="description">When enabled, alerts will fire after every index rebuild.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alert_email">📧 Email <span style="color:#d63638;">*</span></label></th>
                            <td>
                                <input type="email" id="alert_email" name="alert_email" value="<?php echo esc_attr( $config['email'] ); ?>" class="regular-text" required>
                                <p class="description">Alerts will be sent to this email. Default: <code>blomstrainsights@gmail.com</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alert_webhook_url">🔗 Webhook URL</label></th>
                            <td>
                                <input type="url" id="alert_webhook_url" name="alert_webhook_url" value="<?php echo esc_attr( $config['webhook_url'] ); ?>" class="regular-text" placeholder="https://your-service.com/webhook">
                                <p class="description">Custom webhook endpoint for integrations.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alert_slack_url">💬 Slack Webhook URL</label></th>
                            <td>
                                <input type="url" id="alert_slack_url" name="alert_slack_url" value="<?php echo esc_attr( $config['slack_url'] ); ?>" class="regular-text" placeholder="https://hooks.slack.com/services/...">
                                <p class="description">Send alerts directly to a Slack channel.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alert_store_alerts">💾 Store alerts in database</label></th>
                            <td>
                                <input type="checkbox" id="alert_store_alerts" name="alert_store_alerts" value="1" <?php checked( $config['store_alerts'], 1 ); ?>>
                                <p class="description">Save alerts for later reporting and analysis.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>📊 Alert Retention</label></th>
                            <td>
                                <label>
                                    Auto-delete alerts older than:
                                    <input type="number" name="alert_retention_days" value="<?php echo esc_attr( $config['retention_days'] ); ?>" min="1" max="365" style="width:60px;"> days
                                </label>
                                <p class="description">Alerts older than this will be automatically deleted by the daily cron job.</p>
                                <label style="display:block; margin-top:8px;">
                                    Max alerts per index:
                                    <input type="number" name="alert_max_per_index" value="<?php echo esc_attr( $config['max_per_index'] ); ?>" min="100" max="10000" style="width:80px;">
                                </label>
                                <p class="description">For each index (SIVI, SERI, etc.), only the most recent N alerts will be kept.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">💾 Save Alert Configuration</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Alert Management -->
        <div class="postbox" style="border-left:4px solid #f56e28;">
            <div class="postbox-header"><h2 class="hndle">🧹 Alert Management</h2></div>
            <div class="inside">
                <p><strong>Manual Cleanup:</strong></p>
                <form method="post" style="display:inline-block; margin-right:10px;">
                    <?php wp_nonce_field( 'blomstra_alert_cleanup_now_action' ); ?>
                    <input type="submit" name="blomstra_alert_cleanup_now" value="🧹 Clean Up Old Alerts Now" class="button button-secondary">
                </form>
                <p style="color:#666; font-size:12px;">This will delete alerts older than the retention period and trim each index to the max.</p>
                <hr>
                <p><strong>🗑️ Flush Alerts (Danger Zone)</strong></p>
                <form method="post" style="display:inline-block; margin-right:5px;" onsubmit="return confirm('⚠️ Delete ALL SIVI alerts? This cannot be undone.');">
                    <?php wp_nonce_field( 'blomstra_alert_flush_action' ); ?>
                    <input type="hidden" name="flush_target" value="sivi">
                    <input type="submit" name="blomstra_alert_flush" value="🗑️ Flush SIVI Alerts" class="button button-small button-link-delete">
                </form>
                <form method="post" style="display:inline-block; margin-right:5px;" onsubmit="return confirm('⚠️ Delete ALL SERI alerts? This cannot be undone.');">
                    <?php wp_nonce_field( 'blomstra_alert_flush_action' ); ?>
                    <input type="hidden" name="flush_target" value="seri">
                    <input type="submit" name="blomstra_alert_flush" value="🗑️ Flush SERI Alerts" class="button button-small button-link-delete">
                </form>
                <form method="post" style="display:inline-block;" onsubmit="return confirm('⚠️ DELETE ALL ALERTS from ALL indices? This is irreversible!');">
                    <?php wp_nonce_field( 'blomstra_alert_flush_action' ); ?>
                    <input type="hidden" name="flush_target" value="all">
                    <input type="submit" name="blomstra_alert_flush" value="🗑️ Flush ALL Alerts" class="button button-small" style="background:#d63638; color:#fff; border-color:#d63638;">
                </form>
            </div>
        </div>

        <!-- Recent Alerts with Pagination & Filters -->
        <?php if ( $config['store_alerts'] ) : ?>
        <div class="postbox" style="border-left:4px solid #00a0d2;">
            <div class="postbox-header"><h2 class="hndle">📊 Recent Alerts</h2></div>
            <div class="inside">
                <?php
                global $wpdb;
                $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;

                $filter_index = isset( $_GET['filter_index'] ) ? sanitize_text_field( $_GET['filter_index'] ) : '';
                $filter_country = isset( $_GET['filter_country'] ) ? sanitize_text_field( $_GET['filter_country'] ) : '';
                $filter_date_from = isset( $_GET['filter_date_from'] ) ? sanitize_text_field( $_GET['filter_date_from'] ) : '';
                $filter_date_to = isset( $_GET['filter_date_to'] ) ? sanitize_text_field( $_GET['filter_date_to'] ) : '';
                $filter_type = isset( $_GET['filter_type'] ) ? sanitize_text_field( $_GET['filter_type'] ) : '';
                $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
                $per_page = BLOMSTRA_ALERT_PER_PAGE;
                $offset = ( $paged - 1 ) * $per_page;

                $where = "WHERE log_type = 'alert'";
                $params = array();
                if ( $filter_index && $filter_index !== 'all' ) {
                    $where .= " AND index_slug = %s";
                    $params[] = $filter_index;
                }
                if ( $filter_country ) {
                    $where .= " AND country_name LIKE %s";
                    $params[] = '%' . $wpdb->esc_like( $filter_country ) . '%';
                }
                if ( $filter_date_from ) {
                    $where .= " AND DATE(triggered_at) >= %s";
                    $params[] = $filter_date_from;
                }
                if ( $filter_date_to ) {
                    $where .= " AND DATE(triggered_at) <= %s";
                    $params[] = $filter_date_to;
                }
                if ( $filter_type ) {
                    if ( $filter_type === 'new' ) {
                        $where .= " AND alert_reason LIKE '%new_country%'";
                    } elseif ( $filter_type === 'excluded' ) {
                        $where .= " AND alert_reason LIKE '%excluded%'";
                    } elseif ( $filter_type === 'rank' ) {
                        $where .= " AND alert_reason LIKE '%rank_change%'";
                    } elseif ( $filter_type === 'score' ) {
                        $where .= " AND alert_reason LIKE '%score_change%'";
                    } elseif ( $filter_type === 'pillar' ) {
                        $where .= " AND alert_reason LIKE '%pillar_change%'";
                    }
                }

                $count_sql = "SELECT COUNT(*) FROM $table $where";
                if ( ! empty( $params ) ) {
                    $count_sql = $wpdb->prepare( $count_sql, $params );
                }
                $total = (int) $wpdb->get_var( $count_sql );

                $sql = "SELECT * FROM $table $where ORDER BY triggered_at DESC LIMIT %d OFFSET %d";
                $params[] = $per_page;
                $params[] = $offset;
                $alerts = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

                $indices = $wpdb->get_col( "SELECT DISTINCT index_slug FROM $table WHERE log_type = 'alert' ORDER BY index_slug" );
                $base_url = admin_url( 'admin.php?page=blomstra-alerts' );

                // Build filter URL
                $filter_url = add_query_arg( array(
                    'page'           => 'blomstra-alerts',
                    'filter_index'   => $filter_index,
                    'filter_country' => $filter_country,
                    'filter_date_from' => $filter_date_from,
                    'filter_date_to' => $filter_date_to,
                    'filter_type'    => $filter_type,
                    'paged'          => $paged,
                ), admin_url( 'admin.php' ) );
                ?>
                <form method="get" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:15px;" id="alert-filter-form">
                    <input type="hidden" name="page" value="blomstra-alerts">
                    <div>
                        <label>Index:</label>
                        <select name="filter_index">
                            <option value="all">All</option>
                            <?php foreach ( $indices as $idx ) : ?>
                            <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( $filter_index, $idx ); ?>><?php echo esc_html( strtoupper( $idx ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Country:</label>
                        <input type="text" name="filter_country" value="<?php echo esc_attr( $filter_country ); ?>" placeholder="Search country..." style="width:150px;">
                    </div>
                    <div>
                        <label>From:</label>
                        <input type="date" name="filter_date_from" value="<?php echo esc_attr( $filter_date_from ); ?>">
                    </div>
                    <div>
                        <label>To:</label>
                        <input type="date" name="filter_date_to" value="<?php echo esc_attr( $filter_date_to ); ?>">
                    </div>
                    <div>
                        <label>Change Type:</label>
                        <select name="filter_type">
                            <option value="">All</option>
                            <option value="new" <?php selected( $filter_type, 'new' ); ?>>New Country</option>
                            <option value="excluded" <?php selected( $filter_type, 'excluded' ); ?>>Excluded Country</option>
                            <option value="rank" <?php selected( $filter_type, 'rank' ); ?>>Rank Change</option>
                            <option value="score" <?php selected( $filter_type, 'score' ); ?>>Score Change</option>
                            <option value="pillar" <?php selected( $filter_type, 'pillar' ); ?>>Pillar Change</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="button button-secondary">Apply Filters</button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=blomstra-alerts' ) ); ?>" class="button button-link">Clear</a>
                    </div>
                </form>

                <?php if ( empty( $alerts ) ) : ?>
                    <p style="color:#666;">No alerts found for the selected filters.</p>
                <?php else : ?>
                    <form method="post" id="bulk-delete-form" onsubmit="return confirm('Delete selected alerts? This cannot be undone.');">
                        <?php wp_nonce_field( 'blomstra_alert_bulk_delete_action' ); ?>
                        <div style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                            <div>
                                <button type="submit" name="blomstra_alert_bulk_delete" value="1" class="button button-link-delete">🗑️ Delete Selected</button>
                            </div>
                            <div>
                                <?php
                                $total_pages = ceil( $total / $per_page );
                                if ( $total_pages > 1 ) {
                                    echo '<span style="margin-right:10px;">Showing ' . ( $offset + 1 ) . '-' . min( $offset + $per_page, $total ) . ' of ' . $total . '</span>';
                                    echo paginate_links( array(
                                        'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                                        'format'    => '',
                                        'prev_text' => '&laquo;',
                                        'next_text' => '&raquo;',
                                        'total'     => $total_pages,
                                        'current'   => $paged,
                                        'add_args'  => array(
                                            'filter_index' => $filter_index,
                                            'filter_country' => $filter_country,
                                            'filter_date_from' => $filter_date_from,
                                            'filter_date_to' => $filter_date_to,
                                            'filter_type' => $filter_type,
                                        ),
                                    ) );
                                }
                                ?>
                            </div>
                        </div>

                        <div style="overflow-x:auto;">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th style="width:30px;"><input type="checkbox" id="select-all-alerts"></th>
                                        <th>Time</th>
                                        <th>Index</th>
                                        <th>Country</th>
                                        <th>Rank</th>
                                        <th>Score</th>
                                        <th>Pillar Changes</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $alerts as $alert ) : ?>
                                    <?php
                                    // Safe rank display
                                    $rank_display = '';
                                    if ( $alert->previous_rank !== null && $alert->current_rank !== null ) {
                                        if ( isset( $alert->rank_delta ) && is_numeric( $alert->rank_delta ) ) {
                                            $arrow = $alert->rank_delta < 0 ? '▲' : '▼';
                                            $rank_display = '#' . $alert->previous_rank . ' → #' . $alert->current_rank . ' (' . $arrow . abs( $alert->rank_delta ) . ')';
                                        } else {
                                            $rank_display = '#' . $alert->previous_rank . ' → #' . $alert->current_rank;
                                        }
                                    } elseif ( $alert->previous_rank === null && $alert->current_rank !== null ) {
                                        $rank_display = 'NEW: #' . $alert->current_rank;
                                    } elseif ( $alert->previous_rank !== null && $alert->current_rank === null ) {
                                        $rank_display = 'REMOVED (#' . $alert->previous_rank . ')';
                                    } else {
                                        $rank_display = '—';
                                    }

                                    // Safe score display
                                    $score_display = '';
                                    if ( $alert->previous_score !== null && $alert->current_score !== null ) {
                                        if ( isset( $alert->score_delta ) && is_numeric( $alert->score_delta ) ) {
                                            $sign = $alert->score_delta > 0 ? '+' : '';
                                            $score_display = $alert->previous_score . ' → ' . $alert->current_score . ' (' . $sign . $alert->score_delta . ')';
                                        } else {
                                            $score_display = $alert->previous_score . ' → ' . $alert->current_score;
                                        }
                                    } elseif ( $alert->previous_score === null && $alert->current_score !== null ) {
                                        $score_display = 'NEW: ' . $alert->current_score;
                                    } elseif ( $alert->previous_score !== null && $alert->current_score === null ) {
                                        $score_display = 'REMOVED (' . $alert->previous_score . ')';
                                    } else {
                                        $score_display = '—';
                                    }

                                    // Safe pillar rendering
                                    $pillar_display = '';
                                    $prev = ! empty( $alert->previous_pillars ) ? json_decode( $alert->previous_pillars, true ) : null;
                                    $curr = ! empty( $alert->current_pillars ) ? json_decode( $alert->current_pillars, true ) : null;
                                    if ( is_array( $curr ) && ! empty( $curr ) ) {
                                        $prev = is_array( $prev ) ? $prev : array();
                                        $deltas = array();
                                        foreach ( $curr as $pname => $val ) {
                                            if ( isset( $prev[ $pname ] ) && is_numeric( $val ) && is_numeric( $prev[ $pname ] ) ) {
                                                $delta = round( $val - $prev[ $pname ], 2 );
                                                if ( abs( $delta ) >= 0.01 ) {
                                                    $sign = $delta > 0 ? '+' : '';
                                                    $deltas[] = ucfirst( $pname ) . ' ' . $sign . $delta;
                                                }
                                            }
                                        }
                                        if ( ! empty( $deltas ) ) {
                                            $pillar_display = implode( ', ', $deltas );
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" name="alert_ids[]" value="<?php echo esc_attr( $alert->id ); ?>" class="alert-checkbox"></td>
                                        <td><?php echo esc_html( $alert->triggered_at ); ?></td>
                                        <td><strong><?php echo esc_html( strtoupper( $alert->index_slug ) ); ?></strong></td>
                                        <td><?php echo esc_html( $alert->country_name ); ?></td>
                                        <td><?php echo esc_html( $rank_display ); ?></td>
                                        <td><?php echo esc_html( $score_display ); ?></td>
                                        <td><?php echo esc_html( $pillar_display ); ?></td>
                                        <td><code><?php echo esc_html( $alert->alert_reason ); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                            <div>
                                <button type="submit" name="blomstra_alert_bulk_delete" value="1" class="button button-link-delete">🗑️ Delete Selected</button>
                            </div>
                            <div>
                                <?php
                                if ( $total_pages > 1 ) {
                                    echo '<span style="margin-right:10px;">Showing ' . ( $offset + 1 ) . '-' . min( $offset + $per_page, $total ) . ' of ' . $total . '</span>';
                                    echo paginate_links( array(
                                        'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                                        'format'    => '',
                                        'prev_text' => '&laquo;',
                                        'next_text' => '&raquo;',
                                        'total'     => $total_pages,
                                        'current'   => $paged,
                                        'add_args'  => array(
                                            'filter_index' => $filter_index,
                                            'filter_country' => $filter_country,
                                            'filter_date_from' => $filter_date_from,
                                            'filter_date_to' => $filter_date_to,
                                            'filter_type' => $filter_type,
                                        ),
                                    ) );
                                }
                                ?>
                            </div>
                        </div>
                    </form>
                    <p style="margin-top:5px; color:#666; font-size:12px;">Total alerts: <?php echo esc_html( $total ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- System Log -->
        <div class="postbox" style="border-left:4px solid #f56e28;">
            <div class="postbox-header"><h2 class="hndle">📋 System Log</h2></div>
            <div class="inside">
                <?php
                global $wpdb;
                $table = $wpdb->prefix . BLOMSTRA_ALERT_TABLE;
                $logs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $table WHERE log_type IN ('info','warning','error','config') ORDER BY triggered_at DESC LIMIT 30"
                ) );
                if ( empty( $logs ) ) {
                    echo '<p style="color:#666;">No system logs yet.</p>';
                } else {
                    echo '<div style="overflow-x:auto;"><table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead><tbody>';
                    foreach ( $logs as $log ) {
                        $level_class = '';
                        $level_icon = '';
                        if ( $log->log_type === 'error' ) {
                            $level_class = 'style="color:#d63638;"';
                            $level_icon = '❌';
                        } elseif ( $log->log_type === 'warning' ) {
                            $level_class = 'style="color:#f56e28;"';
                            $level_icon = '⚠️';
                        } elseif ( $log->log_type === 'config' ) {
                            $level_class = 'style="color:#2271b1;"';
                            $level_icon = '⚙️';
                        } else {
                            $level_class = 'style="color:#2e7d32;"';
                            $level_icon = '✅';
                        }
                        echo '<tr>';
                        echo '<td>' . esc_html( $log->triggered_at ) . '</td>';
                        echo '<td ' . $level_class . '><strong>' . esc_html( strtoupper( $log->log_type ) ) . ' ' . $level_icon . '</strong></td>';
                        echo '<td><code>' . esc_html( $log->alert_reason ) . '</code></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                ?>
                <p style="margin-top:10px; color:#666; font-size:12px;">
                    Log entries show system events: info, warnings, errors, and configuration changes.
                </p>
            </div>
        </div>

        <!-- Diagnostics Panel -->
        <div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">
            <div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-testimonial"></span> 🩺 System Diagnostics</h2></div>
            <div class="inside">
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'blomstra_alerts';
                $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;
                echo '<p><strong>Table exists:</strong> ' . ( $table_exists ? '✅ Yes' : '❌ No' ) . '</p>';
                if ( $table_exists ) {
                    $columns = $wpdb->get_results( "DESCRIBE $table" );
                    $column_names = array_column( $columns, 'Field' );
                    $has_log_type = in_array( 'log_type', $column_names );
                    echo '<p><strong>Column `log_type` exists:</strong> ' . ( $has_log_type ? '✅ Yes' : '❌ No' ) . '</p>';
                    if ( ! $has_log_type ) {
                        echo '<p style="color:#d63638;">⚠️ Missing `log_type` column. Alerts will be stored but the admin UI will not display them. Run: <code>ALTER TABLE ' . $table . ' ADD COLUMN log_type VARCHAR(20) DEFAULT "alert";</code></p>';
                    }
                    $counts = $wpdb->get_results( "SELECT index_slug, COUNT(*) as cnt FROM $table GROUP BY index_slug", OBJECT_K );
                    echo '<p><strong>Alert counts per index:</strong></p><ul>';
                    foreach ( $counts as $slug => $row ) {
                        echo '<li><strong>' . esc_html( $slug ) . '</strong>: ' . esc_html( $row->cnt ) . ' alerts</li>';
                    }
                    if ( empty( $counts ) ) {
                        echo '<li>No alerts recorded yet.</li>';
                    }
                    echo '</ul>';

                    $recent = $wpdb->get_results( "SELECT * FROM $table ORDER BY triggered_at DESC LIMIT 5" );
                    if ( ! empty( $recent ) ) {
                        echo '<p><strong>Last 5 alerts:</strong></p>';
                        echo '<table class="widefat striped" style="font-size:12px;"><thead><tr><th>Time</th><th>Index</th><th>Country</th><th>Rank Δ</th><th>Score Δ</th><th>Reason</th></tr></thead><tbody>';
                        foreach ( $recent as $alert ) {
                            $rank_delta = $alert->rank_delta !== null ? ( $alert->rank_delta > 0 ? '▲' : '▼' ) . abs( $alert->rank_delta ) : '—';
                            $score_delta = $alert->score_delta !== null ? ( $alert->score_delta > 0 ? '+' : '' ) . $alert->score_delta : '—';
                            echo '<tr>';
                            echo '<td>' . esc_html( $alert->triggered_at ) . '</td>';
                            echo '<td><strong>' . esc_html( strtoupper( $alert->index_slug ) ) . '</strong></td>';
                            echo '<td>' . esc_html( $alert->country_name ) . '</td>';
                            echo '<td>' . esc_html( $rank_delta ) . '</td>';
                            echo '<td>' . esc_html( $score_delta ) . '</td>';
                            echo '<td><code>' . esc_html( $alert->alert_reason ) . '</code></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<p>No recent alerts.</p>';
                    }
                }
                ?>
                <hr><p><strong>🧪 Test Alert</strong></p>
                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field( 'blomstra_diagnostics_run_test', 'blomstra_diagnostics_nonce' ); ?>
                    <button type="submit" name="blomstra_run_diagnostic_test" value="1" class="button button-secondary">🔔 Run Test Alert</button>
                </form>
                <?php
                if ( isset( $_POST['blomstra_run_diagnostic_test'] ) && check_admin_referer( 'blomstra_diagnostics_run_test', 'blomstra_diagnostics_nonce' ) ) {
                    $test_new = array(
                        'USA' => array(
                            'name' => 'United States',
                            'rank' => 10,
                            'score' => 50.0,
                            'pillars' => array( 'energy' => 50, 'hhi' => 50, 'maritime' => 50 ),
                        ),
                        'GBR' => array(
                            'name' => 'United Kingdom',
                            'rank' => 20,
                            'score' => 60.0,
                            'pillars' => array( 'energy' => 60, 'hhi' => 60, 'maritime' => 60 ),
                        ),
                    );
                    $test_old = array(
                        'USA' => array(
                            'name' => 'United States',
                            'rank' => 5,
                            'score' => 45.0,
                            'pillars' => array( 'energy' => 45, 'hhi' => 45, 'maritime' => 45 ),
                        ),
                        'GBR' => array(
                            'name' => 'United Kingdom',
                            'rank' => 15,
                            'score' => 55.0,
                            'pillars' => array( 'energy' => 55, 'hhi' => 55, 'maritime' => 55 ),
                        ),
                    );
                    $result = blomstra_fire_index_alerts( 'test', $test_new, $test_old, array('total' => 2), array('total' => 2) );
                    if ( $result > 0 ) {
                        echo '<div class="notice notice-success"><p>✅ Test alert fired! Detected <strong>' . $result . '</strong> changes. Check your email and the alerts table above.</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ Test alert failed – no changes detected. Check config and function availability.</p></div>';
                    }
                }
                ?>
            </div>
        </div>

        <!-- How Alerts Work -->
        <div class="postbox" style="border-left:4px solid #9b51e0;">
            <div class="postbox-header"><h2 class="hndle">ℹ️ How Alerts Work</h2></div>
            <div class="inside">
                <ul style="list-style:disc; padding-left:20px;">
                    <li><strong>Trigger:</strong> After every successful index rebuild (SIVI, SERI, etc.).</li>
                    <li><strong>What is tracked:</strong> Rank, composite score, and <strong>each pillar</strong> (Energy, Supplier Concentration, Maritime, etc.).</li>
                    <li><strong>Change detection:</strong> Any change in rank, score, or any pillar will fire an alert.</li>
                    <li><strong>Delivery:</strong> Email (mandatory), optional webhook (JSON payload), and optional Slack integration.</li>
                    <li><strong>Cooldown:</strong> Prevents duplicate alerts for the same change set within 5 minutes (configurable via filter).</li>
                    <li><strong>Retention:</strong> Alerts are automatically cleaned up after the configured number of days, and each index is trimmed to the max number of alerts.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Inline JS for select-all -->
    <script>
    (function($) {
        $(document).ready(function() {
            $('#select-all-alerts').on('change', function() {
                $('.alert-checkbox').prop('checked', this.checked);
            });
        });
    })(jQuery);
    </script>
    <?php
}
