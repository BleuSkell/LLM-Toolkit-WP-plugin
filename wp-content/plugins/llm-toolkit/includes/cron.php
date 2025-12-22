<?php

// cron used for checking which page has a llm optimized html and creates one if it doesn't have it
// custom cron interval every 5 minutes
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules[ 'every_5_minutes' ] ) ) {
        $schedules[ 'every_5_minutes' ] = [
            'interval'  => 300,
            'display'   => __( 'Every 5 Minutes' )
        ];
    }
    return $schedules;
});

// register cron job on plugin activation (activates on the wp hook set in the add_action load plugin)
// __FILE__: return full path and name of the executed PHP file
register_activation_hook( __FILE__, function () {

    // plan cron when admin toggle is enabled
    if ( get_option( 'llm_toolkit_cron_enabled', false ) == true ) {
        if ( ! wp_next_scheduled( 'llm_generate_batch' ) ) {
            wp_schedule_event( time() + 60, 'every_5_minutes', 'llm_generate_batch' );
        }

        llm_toolkit_add_status_message( 'cron', 'Cron started on ' . current_time( 'Y-m-d H:i:s' ) . ' generating LLM HTML files' );
    }
});

// remove cron job on plugin deactivation
register_deactivation_hook( __FILE__, function () {
    $timestamp = wp_next_scheduled( 'llm_generate_batch' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'llm_generate_batch' );
        llm_toolkit_add_status_message( 'cron', 'Cron stopped on ' . current_time( 'Y-m-d H:i:s' ) . ' completing last batch before stopping' );
    }
});

// cron callback (runs every 5 minutes)
add_action( 'llm_generate_batch', 'llm_generate_batch_callback' );
function llm_generate_batch_callback() {
    if ( get_option( 'llm_toolkit_cron_enabled', '0' ) != '1' ) {
        return; // cron disabled, skip generation logic
    }

    // check for batch lock
    if ( get_transient( 'llm_generate_batch_lock' ) ) {
        error_log( 'LLM Toolkit: Cron skipped because another batch was already running' );
        return;
    }
    set_transient( 'llm_generate_batch_lock', true, 460 ); // lock for 5 minutes

    // get remaining pages queue from transient
    $remaining_pages = get_transient( 'llm_remaining_pages' );

    // if there's no pages queue build a new one based on the sitemap
    if ( ! $remaining_pages ) {
        $selected_sitemaps = get_option( 'llm_toolkit_selected_sitemaps', [] ); // get selected sitemaps from admin menu
        $urls = [];

        // if no sitemaps have been selected stop and return a log
        if ( empty( $selected_sitemaps ) ) {
            llm_toolkit_add_status_message( 'error', 'Cron error: No sitemaps defined' );
            return;
        }

        foreach ( $selected_sitemaps as $sitemap_url ) {
            $urls = array_merge( $urls, read_sitemap( $sitemap_url ) ); // page urls
        }

        $remaining_pages = [];

        // build the queue with only pages that still need a llm optimized html
        foreach ( $urls as $url ) {
            $uri = wp_parse_url( $url, PHP_URL_PATH );
            $clean = trim( $uri, '/' );
            $clean = $clean ?: 'index';

            $file_path = WP_CONTENT_DIR . '/llms/' . $clean . '.html';

            // if it doesn't have one, add it to the queue
            if ( ! file_exists( $file_path ) ) {
                $remaining_pages[] = $url;
            }
        }

        // if everything exists, stop
        if ( empty( $remaining_pages ) ) {
            delete_transient( 'llm_remaining_pages' );
            return;
        }

        llm_toolkit_add_status_message( 'cron', 'New batch queue started' );
    }

    // get first 5 pages out of the queue in a batch
    $batch = array_slice( $remaining_pages, 0, 3 );
    $remaining_pages = array_slice( $remaining_pages, 3 );

    // process the batch
    foreach ( $batch as $url ) {
        $uri = wp_parse_url( $url, PHP_URL_PATH );
        $clean = trim( $uri, '/' );
        $clean = $clean ?: 'index';

        $file_path = WP_CONTENT_DIR . '/llms/' . $clean . '.html';

        if ( !file_exists( $file_path ) ) {
            $success = generate_llm_html( $url, $file_path );
            if ( $success ) {
                llm_toolkit_add_status_message( 'success', "Success: LLMS HTML file generated for $clean" );
            } else {

                // delete the empty file if needed
                if ( file_exists( $file_path ) && filesize( $file_path ) === 0 ) {
                    unlink( $file_path );
                    llm_toolkit_add_status_message( 'error', "Error: Empty file: $clean" );
                }

                llm_toolkit_add_status_message( 'error', "Error: Failed to generate LLMS HTML for $clean" );
            }
        }
    }

    // save remaining pages for the next cron run
    if ( ! empty( $remaining_pages ) ) {
        set_transient( 'llm_remaining_pages', $remaining_pages, 12 * HOUR_IN_SECONDS );
    } else {
        delete_transient( 'llm_remaining_pages' );
        llm_toolkit_add_status_message( 'cron', 'Success: Batch processing complete' );
    }

    // remove lock
    delete_transient( 'llm_generate_batch_lock' );
}