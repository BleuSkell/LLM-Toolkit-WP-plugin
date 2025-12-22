<?php
// add bot logs
function llm_toolkit_add_bot_logs( $type, $message ) {

    // initiate bot logs
    $logs = get_option( 'llm_toolkit_bot_logs', [] );

    // format bot logs
    $logs[] = [
        'type'      => $type,
        'message'   => $message,
        'time'      => current_time( 'Y-m-d H:i:s' ),
    ];

    // limit bot logs to the latest 100
    if ( count( $logs ) > 100 ) {
        $logs = array_slice( $logs, -100 );
    }

    // update with logs
    update_option( 'llm_toolkit_bot_logs', $logs );
}

// bot requests logging
function llm_toolkit_bot_request_logs() {
    $user_agent = $_SERVER[ 'HTTP_USER_AGENT' ] ?? '';

    $bot_regex = "/(?i)\b(claude|bot|crawler|crawl|spider|slurp|fetch|search|monitor|scraper|python|perl|php|java|wget|curl|httpclient|libwww|bingpreview|mediapartners-google|applebot|yahoo|yandex|baiduspider|duckduckbot|semrush|ahrefs|mj12bot|rogerbot|dotbot|gigabot|seznambot|petalbot|sogou|exabot|facebookexternalhit|twitterbot|linkedinbot|pinterest|embedly|quora link preview|discordbot|telegrambot)\b/";

    $ip_address = ! empty( $_SERVER[ 'REMOTE_ADDR' ] ) ? $_SERVER[ 'REMOTE_ADDR' ] : $_SERVER[ 'HTTP_X_FORWARDED_FOR' ];
    $page_request_url = $_SERVER[ 'REQUEST_URI' ];

    // check if visitor is a bot
    if ( ! preg_match( $bot_regex, $user_agent ) ) {
        return;
    }

    // create unique key per bot + page
    $log_key = 'bot_log_' . md5( $user_agent . $page_request_url );

    // if no log exists for the key create one
    if ( ! get_transient( $log_key ) ) {

        // log the bot request
        llm_toolkit_add_bot_logs(
            'bot_request_logs',
            "The following bot has been detected: $user_agent, visiting $page_request_url at IP: $ip_address"
        );

        // save as transient for the next 24 hours so it doesn't continue logging
        set_transient( $log_key, true, 24 * HOUR_IN_SECONDS );
    }
}

// live bot logs
add_action( 'wp_ajax_llm_toolkit_get_bot_logs', function() {

    // get logs from llm_toolkit_add_bot_logs method
    $logs = get_option( 'llm_toolkit_bot_logs', [] );

    // Return latest 100 logs as JSON
    wp_send_json( $logs );
});

// clear bot logs
add_action( 'admin_post_llm_toolkit_clear_bot_logs', function() {
    // Check nonce for security
    if ( ! isset( $_POST[ '_wpnonce' ] ) || ! wp_verify_nonce( $_POST[ '_wpnonce' ], 'llm_toolkit_clear_bot_logs_nonce' ) ) {
        wp_die( 'Nonce check failed' );
    }

    // Clear status logs
    update_option( 'llm_toolkit_bot_logs', [] );

    // redirect back to status page
    wp_safe_redirect( admin_url( 'admin.php?page=bot-logs' ) );
    exit;
});