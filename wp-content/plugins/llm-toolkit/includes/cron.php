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

// cron callback (runs every 5 minutes)
add_action( 'llm_generate_batch', 'llm_generate_batch_callback' );
function llm_generate_batch_callback() {
    if ( get_option( 'llm_toolkit_cron_enabled', '0' ) != '1' ) {
        return; // cron disabled, skip generation logic
    }

    // check for sitemap cooldown
    if ( get_transient( 'llm_toolkit_sitemap_cooldown' ) ) {
        llm_toolkit_add_status_message( 'cron', 'Cron warning: Cron skipped, sitemap cooldown active.' );
        return;
    }

    // check for batch lock
    if ( get_transient( 'llm_generate_batch_lock' ) ) {
        llm_toolkit_add_status_message( 'cron', 'Cron notice: Cron skipped because another batch was already running' );
        return;
    }
    set_transient( 'llm_generate_batch_lock', true, 460 ); // lock for 5 minutes

    // get remaining pages queue from transient
    $data = get_transient( 'llm_remaining_pages' );
    if ( $data ) {
        $remaining_pages = $data[ 'queue' ];
        $url_to_sitemap  = $data[ 'mapping' ];

        llm_toolkit_add_status_message( 'cron', 'Cron success: Batch processing resumed: ' . count( $remaining_pages ) . ' pages remaining' );
    } else {
        $remaining_pages = null;
    }

    // if there's no pages queue build a new one based on the sitemap
    if ( ! $remaining_pages ) {
        $selected_sitemaps = get_option( 'llm_toolkit_selected_sitemaps', [] ); // get selected sitemaps from admin menu
        $urls = [];

        // if no sitemaps have been selected stop and return a log
        if ( empty( $selected_sitemaps ) ) {
            llm_toolkit_add_status_message( 'error', 'Cron error: No sitemaps defined' );
            delete_transient( 'llm_generate_batch_lock' );
            return;
        }

        $url_to_sitemap = [];
        $had_failure = false;

        foreach ( $selected_sitemaps as $sitemap_url ) {
            $sitemap_urls = read_sitemap( $sitemap_url );

            if ( empty( $sitemap_urls ) ) {
                // read_sitemap already logged the error, only remember it failed
                $had_failure = true;
                continue;
            }

            foreach ( $sitemap_urls as $url ) {
                $urls[] = $url;
                $url_to_sitemap[ $url ] = $sitemap_url;
            }
        }

        // if no urls were found, stop and return a log
        if ( empty( $urls ) ) {
            llm_toolkit_add_status_message( 'error', 'Cron error: No sitemap URLs could be loaded this run.' );
            delete_transient( 'llm_generate_batch_lock' );
            return;
        }

        // log if some sitemaps failed, but continue with the rest
        if ( $had_failure ) {
            llm_toolkit_add_status_message( 'cron', 'Cron warning: One or more sitemaps failed to load, cron continuing with remaining sitemaps.' );
        }

        $remaining_pages = [];

        // build the queue with only pages that still need a llm optimized html
        foreach ( $urls as $url ) {
            $uri = wp_parse_url( $url, PHP_URL_PATH );
            $clean = trim( $uri, '/' ) ?: 'index';

            $file_path = WP_CONTENT_DIR . '/llms/' . $clean . '.html';

            // if it doesn't have one, add it to the queue
            if ( ! file_exists( $file_path ) ) {
                $remaining_pages[] = $url;
            }
        }

        // if everything exists, stop
        if ( empty( $remaining_pages ) ) {
            delete_transient( 'llm_remaining_pages' );
            delete_transient( 'llm_generate_batch_lock' );
            return;
        }

        llm_toolkit_add_status_message( 'cron', 'Cron success: New batch queue started' );
    }

    // get first 5 pages out of the queue in a batch
    $batch = array_slice( $remaining_pages, 0, 3 );
    $remaining_pages = array_slice( $remaining_pages, 3 );

    // process the batch
    foreach ( $batch as $url ) {
        $sitemap_url = $url_to_sitemap[ $url ] ?? '';

        $uri = wp_parse_url( $url, PHP_URL_PATH );
        $clean = trim( $uri, '/' );
        $clean = $clean ?: 'index';

        $file_path = WP_CONTENT_DIR . '/llms/' . $clean . '.html';

        if ( ! file_exists( $file_path ) ) {
            $success = generate_llm_html( $url, $file_path, $sitemap_url );

            if ( $success ) {
                llm_toolkit_add_status_message( 'success', "Cron success: LLMS HTML file generated for $clean" );
            } else {

                // delete the empty file if needed
                if ( file_exists( $file_path ) && filesize( $file_path ) === 0 ) {
                    unlink( $file_path );
                    llm_toolkit_add_status_message( 'error', "Cron error: Empty file: $clean" );
                }

                llm_toolkit_add_status_message( 'error', "Cron error: Failed to generate LLMS HTML for $clean" );
            }
        }
    }

    // save remaining pages for the next cron run
    if ( ! empty( $remaining_pages ) ) {
        set_transient( 'llm_remaining_pages', [
            'queue'     => $remaining_pages,
            'mapping'   => $url_to_sitemap
        ], 12 * HOUR_IN_SECONDS );
    } else {
        delete_transient( 'llm_remaining_pages' );
        llm_toolkit_add_status_message( 'cron', 'Cron success: Batch processing complete' );
    }

    // remove lock
    delete_transient( 'llm_generate_batch_lock' );
}