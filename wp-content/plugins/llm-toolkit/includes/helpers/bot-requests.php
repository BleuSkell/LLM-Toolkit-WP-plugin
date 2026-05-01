<?php
function llm_toolkit_get_bot_requests( $limit ) {
    $log_file = WP_PLUGIN_DIR . '/llm-toolkit/bot-requests.txt';
    if ( ! file_exists( $log_file ) ) return [];

    $lines = array_slice(
        file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ),
        -2000
    );

    $bots = [];

    foreach ( $lines as $line ) {
        if ( preg_match( '/\[(.*?)\]\s+(.*?)\s+made a request to\s+(.*?)\.\s+At IP:\s+(.*)/', $line, $matches ) ) {
            $bots[] = [
                'time' => $matches[1],
                'user_agent' => $matches[2],
                'request' => $matches[3],
                'ip' => $matches[4],
                'raw_line' => $matches[5],
            ];
        }
    }

    return array_slice( $bots, -$limit );
}

// ajax callback
add_action( 'wp_ajax_llm_toolkit_get_bot_requests', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $bots = llm_toolkit_get_bot_requests( 300 );
    wp_send_json( $bots );
});