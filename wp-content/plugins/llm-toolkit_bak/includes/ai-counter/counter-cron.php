<?php
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'ACCESSLOG', '/data/sites/web/stagingbegenl/logs/access-com-linweb116.log' );

require __DIR__ . '/counter-bot-signatures.php';
require __DIR__ . '/counter-db-installer.php';
require '../helpers/write-to-db.php';

// define cron bookmark
add_action( 'init', function() {
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_counter_bookmark', [ 'sanitize_callback' => 'absint' ] );
});

add_action( 'llm_count_bots', 'llm_count_bots_callback' );
function llm_count_bots_callback(): void {
    if ( ! defined( 'ACCESSLOG' ) || ! file_exists( ACCESSLOG ) ) {
        llm_toolkit_add_status_message( 'error', 'AI counter: Unable to find the access log file' );
        return;
    }

    // check for a potential accesslog rotation
    $bookmark = (int) get_option( 'llm_toolkit_counter_bookmark', 0 );
    if ( $bookmark > filesize( ACCESSLOG ) ) {
        llm_toolkit_add_status_message( 'cron', 'AI counter: Access log rotated, resetting bookmark' );
        $bookmark = 0;
        update_option( 'llm_toolkit_counter_bookmark', 0 );
    }

    llm_toolkit_add_status_message( 'cron', 'AI counter: Processing access log' );
    $handle = fopen( ACCESSLOG, 'r' );
    if ( ! $handle ) return;
    fseek( $handle, $bookmark ); // find bookmark in log

    $signatures = get_signatures();

    $tally = [];

    while ( ! feof( $handle ) ) {
        $line = fgets( $handle );
        if ( $line === false || trim( $line ) === '' ) continue;

        $fields = llm_toolkit_parse_log_line($line);
        if ( ! $fields ) continue;

        $ua         = $fields['ua'];
        $referral   = $fields['referral'];
        $matched    = false;

        // check for a match in the user agent string
        foreach ( ['crawler', 'answer_engine', 'trad_search_crawler'] as $category ) {
            if ( preg_match( $signatures[$category], $ua ) ) {
                $tally[$ua][$category] = ( $tally[$ua][$category] ?? 0 ) + 1;
                $matched = true;
                break;
            }
        }

        // check referrer separately
        if ( ! $matched && preg_match( $signatures['ai_referral'], $referral ) ) {
            $tally[$referral]['ai_referral'] = ( $tally[$referral]['ai_referral'] ?? 0 ) + 1;
        }
    }

    // update bookmark
    llm_toolkit_add_status_message( 'cron', 'AI counter: Tally complete' );
    update_option( 'llm_toolkit_counter_bookmark', ftell( $handle ) );
    fclose( $handle );

    // write tally to database
    llm_toolkit_add_status_message( 'cron', 'AI counter: Writing tally to database' );
    llm_toolkit_write_to_db( $tally );
}