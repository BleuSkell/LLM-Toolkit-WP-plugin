<?php
if ( ! defined( 'MY_PLUGIN_LOADED' ) ) {
    define( 'MY_PLUGIN_LOADED', true );

    $root_dir = dirname( __DIR__, 5 );

    // get user-agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ( ! empty( $user_agent ) ) {
        $selected_bots = [];

        // get the selected bots to detect
        $selected_bots_file = $root_dir . '/wp-content/llm-toolkit-selected-bots.json'; // location configured in llm-toolkit config
        if ( file_exists( $selected_bots_file ) ) {
            $selected_bots = json_decode( file_get_contents( $selected_bots_file ), true );
        }

        // turn the bot names/keywords into regex
        $llm_regex_patterns = [];
        if ( ! empty( $selected_bots ) ) {
            foreach ( $selected_bots as $bot ) {
                $bot = trim( $bot );
                if ( $bot !== '' ) {
                    $llm_regex_patterns[] = '/' . preg_quote( $bot, '/' ) . '/i';
                }
            }
        }

        // check if the user agent matches a llm regex
        foreach ( $llm_regex_patterns as $llm_regex_pattern ) {
            if ( preg_match( $llm_regex_pattern, $user_agent ) ) {
                $uri = strtok( $_SERVER['REQUEST_URI'], '?' );
                $uri = trim( $uri, '/' );

                // if uri is empty set it to index
                $llm_html_file = $root_dir . '/wp-content/llms/' . ( $uri ?: 'index' ) . '.html';

                // display llm html file
                if ( file_exists( $llm_html_file ) ) {
                    header( 'Content-type: text/html; charset=utf-8' );
                    ob_clean();
                    readfile( $llm_html_file );
                    exit;
                }

                // if the file doesn't exist show the normal page
                break;
            }
        }
    }
}