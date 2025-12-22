<?php

// live page count (X pages out of X have LLM HTML)
add_action('wp_ajax_llm_toolkit_get_summary', 'llm_toolkit_get_summary');
function llm_toolkit_get_summary() {
    $pages_sitemap = home_url() . '/page-sitemap.xml';
    $page_urls     = read_sitemap($pages_sitemap);
    $total_pages   = count($page_urls);

    $llms_dir        = WP_CONTENT_DIR . '/llms/';
    $generated_files = is_dir($llms_dir)
        ? count(array_diff(scandir($llms_dir), ['.', '..']))
        : 0;

    wp_send_json([
        'generated_files'   => $generated_files,
        'total_pages'       => $total_pages,
    ]);
}

// live status logs
add_action( 'wp_ajax_llm_toolkit_get_status', function() {

    // get logs from llm_toolkit_add_status_logs method
    $logs = get_option( 'llm_toolkit_status_logs', [] );

    // Return latest 100 logs as JSON
    wp_send_json( $logs );
});

// add status logs
function llm_toolkit_add_status_message( $type, $message ) {

    // initiate status logs
    $logs = get_option( 'llm_toolkit_status_logs', [] );

    // format status logs
    $logs[] = [
        'type'      => $type,
        'message'   => $message,
        'time'      => current_time( 'Y-m-d H:i:s' ),
    ];

    // limit status logs to the latest 100
    if ( count( $logs ) > 100 ) {
        $logs = array_slice( $logs, -100 );
    }

    // update with logs
    update_option( 'llm_toolkit_status_logs', $logs );
}

// clear status logs
add_action( 'admin_post_llm_toolkit_clear_status', function() {
    // Check nonce for security
    if ( ! isset( $_POST[ '_wpnonce' ] ) || ! wp_verify_nonce( $_POST[ '_wpnonce' ], 'llm_toolkit_clear_status_nonce' ) ) {
        wp_die( 'Nonce check failed' );
    }

    // Clear status logs
    update_option( 'llm_toolkit_status_logs', [] );

    // redirect back to status page
    wp_safe_redirect( admin_url( 'admin.php?page=status-logs' ) );
    exit;
});