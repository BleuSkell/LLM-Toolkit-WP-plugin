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

// live page count (X pages out of X have LLM HTML)
add_action('wp_ajax_llm_toolkit_get_summary', 'llm_toolkit_get_summary');
function llm_toolkit_get_summary() {
    $selected_sitemaps  = get_option( 'llm_toolkit_selected_sitemaps', [] );
    $sitemap_result     = [];

    foreach ( $selected_sitemaps as $sitemap ) {
        $pattern        = '/(' . preg_quote(home_url() . "/", '/') . '|-sitemap\.xml)/';
        $sitemap_name   = preg_replace( $pattern, '', $sitemap );

        $file_urls      = read_sitemap( $sitemap );
        $total_files    = count( $file_urls );

        $llms_dir        = WP_CONTENT_DIR . '/llms/';
        $generated_files = 0;
        if ( is_dir( $llms_dir ) ) {
            // create new recursive iterator for all files
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $llms_dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );

            $llm_files = [];
            foreach ( $rii as $file ) {
                if ( $file->isFile() ) {
                    // save filenames in array
                    $llm_files[] = $file->getFilename();
                }
            }

            foreach ( $file_urls as $url ) {
                $slug = basename( parse_url( $url, PHP_URL_PATH ) );

                foreach ( $llm_files as $file ) {
                    if ( strpos( $file, $slug ) !== false ) {
                        $generated_files++;
                        break;
                    }
                }
            }
        }

        $sitemap_result[] = [
            'sitemap_name'      => $sitemap_name,
            'generated_files'   => $generated_files,
            'total_files'       => $total_files,
        ];
    }

    wp_send_json($sitemap_result);
}

//        $llms_dir        = WP_CONTENT_DIR . '/llms/';
//        $generated_files = 0;
//
//        if (is_dir( $llms_dir )) {
//            $generated = array_diff( scandir( $llms_dir ), ['.', '..'] );
//
//            foreach ( $file_urls as $url ) {
//                $slug = basename( parse_url( $url, PHP_URL_PATH ) );
//
//                foreach ( $generated as $file ) {
//                    if ( strpos( $file, $slug ) !== false ) {
//                        $generated_files++;
//                        break;
//                    }
//                }
//            }
//        }