<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ajax callback
add_action( 'wp_ajax_llm_toolkit_bot_counts', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    global $wpdb;
    $table = $wpdb->prefix . "ai_counts";
    $cutoff = date( 'Y-m-d', strtotime( '-14 days' ) );

    $daily = $wpdb->get_results(
        $wpdb->prepare("
            SELECT UA, referral, category, hits, timestamp AS day
            FROM $table 
            WHERE timestamp >= %s
            ORDER BY timestamp DESC",
            $cutoff
        )
    );

    $monthly = $wpdb->get_results(
        $wpdb->prepare("
            SELECT UA, referral, category, SUM(hits) AS total_hits, DATE_FORMAT(timestamp, '%%Y-%%m') AS month 
            FROM $table 
            WHERE timestamp < %s 
            GROUP BY UA, category, DATE_FORMAT(timestamp, '%%Y-%%m')
            ORDER BY month DESC",
            $cutoff
        )
    );

    wp_send_json([
        'daily'   => $daily,
        'monthly' => $monthly,
    ]);
});