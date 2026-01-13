<?php
// path to access log
define( 'ACCESS_LOG', '/data/sites/web/stagingbegenl/logs/access.log' );

// check if visitor is a bot
function llm_toolkit_is_bot( $user_agent ) {
    $bot_regex = "/(?i)\b(claude|bot|crawler|crawl|spider|slurp|fetch|search|monitor|scraper|python|perl|php|java|wget|curl|httpclient|libwww|bingpreview|mediapartners-google|applebot|yahoo|yandex|baiduspider|duckduckbot|semrush|ahrefs|mj12bot|rogerbot|dotbot|gigabot|seznambot|petalbot|sogou|exabot|facebookexternalhit|twitterbot|linkedinbot|pinterest|embedly|quora link preview|discordbot|telegrambot)\b/";
    return preg_match( $bot_regex, $user_agent );
}

function llm_toolkit_get_bot_requests_from_access_log( $limit ) {
    if ( ! file_exists( ACCESS_LOG ) ) {
        return [];
    }

    // get file contents from access log
    $lines = array_slice( file( ACCESS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), -2000 );

    $bots = [];
    foreach ( $lines as $line ) {
        if ( preg_match( '/^(\S+) .*? \[(.*?)\] ".*?" \d+ \d+ ".*?" "(.*?)"$/', $line, $matches ) ) {
            $ip = $matches[1];
            $time = $matches[2];
            $user_agent = $matches[3];

            // check if the user agent is a bot
            if ( llm_toolkit_is_bot( $user_agent ) ) {
                // add log to bots array
                $bots[] = [
                    'time' => $time,
                    'ip' => $ip,
                    'user_agent' => $user_agent,
                    'raw_line' => $line,
                ];
            }
        }
    }

    // return bots with limit
    return array_slice( $bots, -$limit );
}

// ajax callback
add_action( 'wp_ajax_llm_toolkit_get_bot_logs', function() {
    $bots = llm_toolkit_get_bot_requests_from_access_log( 200 );
    wp_send_json( $bots );
});