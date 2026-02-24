<?php
// route to the log file
define( 'LLM_TOOLKIT_LOG_FILE', WP_PLUGIN_DIR . '/llm-toolkit/logs.txt' );

// get logs from log file
function llm_toolkit_get_file_logs( $limit = 100 ) {
    // check if log file exists, return an empty array
    if ( ! file_exists( LLM_TOOLKIT_LOG_FILE ) ) return [];

    // get the file contents
    $lines = file( LLM_TOOLKIT_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

    // limit to the last 100 lines
    $lines = array_slice( $lines, -$limit );

    $logs = [];
    foreach ( $lines as $line ) {
        if ( preg_match( '/\[(.*?)\]\[(.*?)\] (.*)/', $line, $matches ) ) {
            $logs[] = [
                'time'      => $matches[1],
                'type'      => $matches[2],
                'message'   => $matches[3],
            ];
        }
    }

    // return logs
    return $logs;
}

// ajax callback for live log display
add_action( 'wp_ajax_llm_toolkit_get_status', function() {
    $logs = llm_toolkit_get_file_logs( 100 );
    wp_send_json( $logs );
});

// add log message
function llm_toolkit_add_status_message( $type, $message ) {
    $time = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
    $time = $time->format( 'd-m-Y H:i:s' ) . ' UTC';
    $line = "[$time][$type] $message" . PHP_EOL; // format log message

    // append to file and create if it doesn't exist
    file_put_contents( LLM_TOOLKIT_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
}

// clear log file
add_action( 'admin_post_llm_toolkit_clear_status', function() {
    // Check nonce for security
    if ( ! isset( $_POST[ '_wpnonce' ] ) || ! wp_verify_nonce( $_POST[ '_wpnonce' ], 'llm_toolkit_clear_status_nonce' ) ) {
        wp_die( 'Nonce check failed' );
    }

    // Clear status file
    if ( file_exists( LLM_TOOLKIT_LOG_FILE ) ) {
        file_put_contents( LLM_TOOLKIT_LOG_FILE, '' );
    }

    // redirect back to status page
    wp_safe_redirect( admin_url( 'admin.php?page=status-logs' ) );
    exit;
});

// live generated files count
add_action('wp_ajax_llm_toolkit_get_summary', 'llm_toolkit_get_summary');
function llm_toolkit_get_summary() {
    // get selected sitemaps
    $selected_sitemaps = get_option( 'llm_toolkit_selected_sitemaps', [] );
    $results = [];

    foreach( $selected_sitemaps as $sitemap ) {
        // return only sitemap name
        $pattern        = '/(' . preg_quote( home_url() . "/", '/' ) . '|-sitemap\.xml)/';
        $sitemap_name   = preg_replace( $pattern, '', $sitemap );

        // read and count total urls in sitemap
        $page_urls  = read_sitemap( $sitemap );
        $total_urls = count( $page_urls );

        $llms_dir           = WP_CONTENT_DIR . '/llms/';
        $generated_files    = 0;

        if ( is_dir( $llms_dir ) ) {
            // fetch all files in llms_dir recursively (as if it's 1 list)
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $llms_dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );

            $llm_files = [];
            foreach ( $rii as $file ) {
                if ( $file->isFile() ) {
                    // save filenames from recursive fetch in array
                    $llm_files[] = $file->getFilename();
                }
            }

            foreach ( $page_urls as $url ) {
                // get basename from url path
                $slug = basename( parse_url( $url, PHP_URL_PATH ) );

                // loop through all generated files
                foreach( $llm_files as $file ) {
                    // check if file contains slug so generated_files matches the actual generated files per sitemap
                    if ( strpos( $file, $slug ) !== false ) {
                        // matched? count +1
                        $generated_files++;
                        break;
                    }
                }
            }
        }

        $results[] = [
            'sitemap_name'      => $sitemap_name,
            'generated_files'   => $generated_files,
            'total_urls'        => $total_urls,
        ];
    }

    wp_send_json( $results );
}