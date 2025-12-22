<?php
// get bots from wp database and place it in a json file
$allowed_bots   = get_option('llm_toolkit_allowed_bots', []);
$export         = json_encode( ( array_values( $allowed_bots ) ) );

file_put_contents(WP_CONTENT_DIR . '/llm-toolkit-selected-bots.json', $export, LOCK_EX);