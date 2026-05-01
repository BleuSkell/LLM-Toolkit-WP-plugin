<?php
function llm_toolkit_write_selected_bots_file() {
    $allowed_bots = get_option('llm_toolkit_allowed_bots', []);
    if ( ! is_array( $allowed_bots ) ) {
        $allowed_bots = [];
    }

    $export = json_encode( ( array_values( $allowed_bots ) ) );
    $target = WP_CONTENT_DIR . '/llm-toolkit-selected-bots.json';

    file_put_contents( $target, $export, LOCK_EX );
}

// run when the option is updated
add_action( 'update_option_llm_toolkit_allowed_bots', 'llm_toolkit_write_selected_bots_file' );

// run when the option is first added
add_action( 'add_option_llm_toolkit_allowed_bots', 'llm_toolkit_write_selected_bots_file' );