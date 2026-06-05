<?php
if ( ! defined( 'ABSPATH' ) ) exit;
define ( 'ACCESS_GZIP', '/data/sites/web/stagingbegenl/logs/access.log.1.gz' );

require WP_PLUGIN_DIR . '/llm-toolkit/includes/helpers/write-to-db.php';
require WP_PLUGIN_DIR . '/llm-toolkit/includes/helpers/parse-log-line.php';

add_action( 'init', function() {
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_gz_last_modified', [ 'sanitize_callback' => 'date' ] );
});

add_action( 'llm_count_bots', 'llm_count_bots_callback' );
function llm_count_bots_callback() {
    if ( ! defined( 'ACCESS_GZIP' ) ) {
        llm_toolkit_add_status_message( 'error', 'AI Counter: gzipped access logs file not found.' );
        return;
    }

    $last_modified      = get_option( 'llm_toolkit_gz_last_modified', 0 );
    $current_modified   = filemtime( ACCESS_GZIP );
    if ( $current_modified <= $last_modified ) {
        llm_toolkit_add_status_message( 'cron', 'AI Counter: Gzipped access logs unchanged since last run, skipping.' );
        return;
    }

    $bot_signatures = [
        'crawler'               => '#GPTBot|OAI-AdsBot|ClaudeBot|claude-web|anthropic-ai|Google-Extended|cohere-ai|DeepSeekBot|GrokBot|MistralAI-Index/crawler#i',
        'answer_engine'         => '#OAI-SearchBot|ChatGPT-User|Claude-User|Claude-SearchBot|Google-Agent|PerplexityBot|Perplexity-User|MistralAI-User|xAI-Grok|Grok-DeepSearch#i',
        'trad_search_crawler'   => '#Googlebot|bingbot|Slurp|DuckDuckBot|Baiduspider|YandexBot|facebot|ia_archiver#i',
        'ai_referral'           => '#chatgpt\.com|claude\.ai|perplexity\.ai|gemini\.google\.com|copilot\.microsoft\.com|grok\.com|mistral\.ai|deepseek\.com#i',
    ];

    $file = gzopen( ACCESS_GZIP, 'r' );
    llm_toolkit_add_status_message( 'cron', 'AI Counter: Reading gzipped access logs.' );

    $tally = [];

    while ( ! gzeof( $file ) ) {
        $line = gzgets( $file );
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

        $log_date = date( 'Y-m-d', strtotime( $timestamp ) );

        foreach ( ['crawler', 'answer_engine', 'trad_search_crawler'] as $category ) {
            if ( preg_match( $bot_signatures[$category], $user_agent ) ) {
                $tally[$category][$user_agent][$referral][$log_date]++;
                $matched = true;
                break;
            }
        }

        if ( ! $matched && preg_match( $bot_signatures['ai_referral'], $referral) ) {
            $tally['ai_referral'][$user_agent][$referral][$log_date]++;
        }
    }

    llm_toolkit_add_status_message( 'success', 'AI Counter: Read and parsed gzipped access logs.' );
    gzclose( $file );
    update_option( 'llm_toolkit_gz_last_modified', $current_modified );

    llm_toolkit_write_to_db( $tally );
    llm_toolkit_add_status_message( 'success', 'AI Counter: Data written to database.' );
}