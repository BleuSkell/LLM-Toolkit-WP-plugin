<?php
if ( ! defined( 'MY_PLUGIN_LOADED' ) ) {
    define( 'MY_PLUGIN_LOADED', true );

    $root_dir = dirname( __DIR__, 5 );

    // get user-agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // skip internal fetches
    $is_internal_fetch = ! empty( $_SERVER[ 'HTTP_X_LLM_FETCH' ] );

    if ( ! empty( $user_agent ) && ! $is_internal_fetch ) {
        $bot_regex = "/\b(claude|gptbot|chatgpt|openai|anthropic|perplexitybot|googlebot|bingbot|bingpreview|applebot|yahoo!? slurp|yandexbot|baiduspider|duckduckbot|semrushbot|ahrefsbot|mj12bot|rogerbot|dotbot|gigabot|seznambot|petalbot|sogou|exabot|facebookexternalhit|twitterbot|linkedinbot|pinterest|embedly|quora link preview|discordbot|telegrambot|mediapartners-google|wget|curl|python-requests|libwww-perl|httpclient)\b/i";
        $ip_address = $_SERVER[ 'REMOTE_ADDR' ]
            ?? $_SERVER[ 'HTTP_X_FORWARDED_FOR' ]
            ?? 'unknown';
        $page_request_url = $_SERVER[ 'REQUEST_URI' ] ?? '';

        $bot_log_file = $root_dir . '/wp-content/plugins/llm-toolkit/bot-requests.txt';

        if ( preg_match( $bot_regex, $user_agent ) ) {
            $time = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
            $time = $time->format( 'd-m-Y H:i:s' ) . ' UTC';
            $line = "[$time] $user_agent made a request to $page_request_url. At IP: $ip_address" . PHP_EOL; // format log message

            file_put_contents( $bot_log_file, $line, FILE_APPEND | LOCK_EX );
        }

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
                $llm_file_route = '/wp-content/llms/' . ( $uri ?: 'index' ) . '.html';

                // display llm html file
                if ( file_exists( $llm_html_file ) ) {
                    header( 'Content-type: text/html; charset=utf-8' );

                    // add custom header to exclude it from the caching
                    header( 'Exclude-From-Cache: llms-optimized-page' );

                    // prevent caching
                    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
                    header( 'Pragma: no-cache' );
                    header( 'Surrogate-Control: no-store' );

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