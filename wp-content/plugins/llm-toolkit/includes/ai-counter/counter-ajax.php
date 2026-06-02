<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function llm_toolkit_counter_ajax() {
    global $wpdb;
    $table = $wpdb->prefix . "ai_counts";

    $results = $wpdb->get_results( "SELECT category, SUM(hits) AS total_hits FROM $table GROUP BY category ORDER BY total_hits DESC" );

    if ( $results === null ) {
        llm_toolkit_add_status_message( 'error', 'AI counter: Unable to fetch data from the database' );
        return;
    }

    $counts = [
        'crawler'               => 0,
        'answer_engine'         => 0,
        'trad_search_crawler'   => 0,
        'ai_referral'           => 0,
    ];

    foreach ( $results as $row ) {
        if ( isset( $counts[$row['category']] ) ) {
            $counts[$row['category']] = (int) $row['total_hits'];
        }
    }

    if ( ! $counts || ! is_array( $counts) ) {
        llm_toolkit_add_status_message( 'error', 'AI counter: Unable to parse data from the database' );
        return null;
    }
    return $counts;
}

// ajax callback
add_action( 'wp_ajax_llm_toolkit_bot_counts', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $counts = llm_toolkit_counter_ajax();
    wp_send_json( $counts );
});