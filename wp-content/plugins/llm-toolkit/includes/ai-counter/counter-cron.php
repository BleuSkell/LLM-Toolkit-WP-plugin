<?php
if ( ! defined( 'ABSPATH' ) ) exit;
define ( 'ACCESSLOG', '/data/sites/web/stagingbegenl/logs/access-com-linweb116.log' );

require WP_PLUGIN_DIR . '/llm-toolkit/includes/helpers/write-to-db.php';
require WP_PLUGIN_DIR . '/llm-toolkit/includes/helpers/parse-log-line.php';

add_action( 'init', function() {
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_counter_bookmark', [ 'sanitize_callback' => 'absint' ] );
});

add_action( 'llm_count_bots', 'llm_count_bots_callback' );
function llm_count_bots_callback() {
    if ( ! defined( 'ACCESSLOG' ) ) {
        llm_toolkit_add_status_message( 'error', 'AI Counter: Access logs file not found.' );
        return;
    }
    llm_toolkit_add_status_message( 'cron', 'AI Counter: Reading access logs.' );

    $bookmark = get_option( 'llm_toolkit_counter_bookmark' );
    if ( $bookmark > filesize( ACCESSLOG ) ) {
        $bookmark = 0;
        update_option( 'llm_toolkit_counter_bookmark', $bookmark );
        llm_toolkit_add_status_message( 'cron', 'AI Counter: Bookmark reset.' );
    }

    $bot_signatures = [
        'crawler'               => '#GPTBot|OAI-AdsBot|ClaudeBot|claude-web|anthropic-ai|Google-Extended|cohere-ai|DeepSeekBot|GrokBot|MistralAI-Index/crawler#i',
        'answer_engine'         => '#OAI-SearchBot|ChatGPT-User|Claude-User|Claude-SearchBot|Google-Agent|PerplexityBot|Perplexity-User|MistralAI-User|xAI-Grok|Grok-DeepSearch#i',
        'trad_search_crawler'   => '#Googlebot|bingbot|Slurp|DuckDuckBot|Baiduspider|YandexBot|facebot|ia_archiver#i',
        'ai_referral'           => '#chatgpt\.com|claude\.ai|perplexity\.ai|gemini\.google\.com|copilot\.microsoft\.com|grok\.com|mistral\.ai|deepseek\.com#i',
    ];

    $file = fopen( ACCESSLOG, 'r' );
    fseek( $file, $bookmark );

    $tally = [];

    while ( ! feof( $file ) ) {
        $line = fgets( $file );
        $parsed = llm_toolkit_parse_log_line( $line );

        if ( ! $parsed ) continue;

        $ip         = $parsed[1];
        $timestamp  = $parsed[2];
        $request    = $parsed[3];
        $status     = $parsed[4];
        $byte_size  = $parsed[5];
        $referral   = $parsed[6];
        $user_agent = $parsed[7];
        $matched    = false;

        foreach ( ['crawler', 'answer_engine', 'trad_search_crawler'] as $category ) {
            if ( preg_match( $bot_signatures[$category], $user_agent ) ) {
                $tally[$category][$user_agent][$referral]++;
                $matched = true;
                break;
            }
        }

        if ( ! $matched && preg_match( $bot_signatures['ai_referral'], $referral) ) {
            $tally['ai_referral'][$user_agent][$referral]++;
        }
    }

    llm_toolkit_add_status_message( 'success', 'AI Counter: Read and parsed access logs.' );
    update_option( 'llm_toolkit_counter_bookmark', ftell( $file ) );
    fclose( $file );

    llm_toolkit_write_to_db( $tally );
    llm_toolkit_add_status_message( 'success', 'AI Counter: Data written to database.' );
}