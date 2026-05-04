<?php

// toggle cron job in admin menu
add_action( 'update_option_llm_toolkit_cron_enabled', 'llm_toolkit_cron_toggle', 10, 2 );
function llm_toolkit_cron_toggle( $old, $new ) {
    // cron enable/disable from admin menu
    if ( $new == '1' && get_option( 'llm_toolkit_enabled' ) === '1' ) {
        if ( ! wp_next_scheduled( 'llm_generate_batch' ) ) {
            wp_schedule_event( time() + 60, 'every_5_minutes', 'llm_generate_batch' );
            error_log( 'LLM Toolkit: Cron enabled via admin toggle' );
            llm_toolkit_add_status_message( 'cron', 'Cron started, generating LLM HTML files' );
        }
    } else {
        $timestamp = wp_next_scheduled( 'llm_generate_batch' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'llm_generate_batch' );
            error_log( 'LLM Toolkit: Cron disabled via admin toggle' );
            llm_toolkit_add_status_message( 'cron', "Cron stopped, completing last batch before stopping" );
        }
    }
}