<?php
// helper method to read the sitemap (url based for the pages sitemap)
function read_sitemap( $sitemap_url ) {
    $cache_key = 'llm_toolkit_sitemap_' . md5( $sitemap_url );

    // serve from short-lived cache so sitemap endpoint doesn't get hit too often
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $site_user = get_option( 'llm_toolkit_site_user' );
    $urls = [];

    $args = [
        'timeout'       => 60,
        'redirection'   => 5,
        'user-agent'    => 'LLM-Toolkit/1.0 (+sitemap-fetch)',
        'headers'       => [
            'Authorization' => 'Basic ' . base64_encode( $site_user . ':' . llm_toolkit_decrypt( get_option( 'llm_toolkit_site_pass' ) ) ),
            'Accept'        => 'application/xml,text/xml;q=0.9',
        ]
    ];

    // retry sitemap up to 3 times
    for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
        $response = wp_remote_get( $sitemap_url, $args );
        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        if ( ! is_wp_error( $response ) && $code == 200 ) {
            break;
        }

        if ( $attempt < 3 ) {
            sleep( 2 * $attempt ); // 2s, then 4s and so on
        }
    }

    if ( is_wp_error( $response ) ) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch failed: HTTP ' . $code . ' from ' . $sitemap_url . '; Message ' . $response->get_error_message() );
        return [];
    }

    if ( $code != 200 ) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch failed: HTTP ' . $code . ' from ' . $sitemap_url );
        return [];
    }

    $body = wp_remote_retrieve_body( $response );

    // check if the response is valid XML
    if ( stripos( ltrim( $body ), '<?xml' ) !== 0 && stripos( ltrim( $body ), '<urlset' ) === false) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch failed: Invalid XML response from ' . $sitemap_url . '. ' . $body );
        return [];
    }

    $previous = libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $body );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $xml ) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch failed: Unable to parse XML response from ' . $sitemap_url . '. ' . $body );
        return [];
    }

    if ( $xml && isset( $xml->url ) ) {
        foreach ( $xml->url as $url ) {
            $urls[] = (string) $url->loc;
        }
    }

    // reset failure counter and set cache for 30 mins
    delete_transient( 'llm_toolkit_sitemap_failures' );
    set_transient( $cache_key, $urls, 30 * MINUTE_IN_SECONDS );

    return $urls;
}

// helper method to register a sitemap fetch failure and set a cooldown
function llm_toolkit_register_sitemap_failure() {
    $failures = (int) get_transient( 'llm_toolkit_sitemap_failures' );
    $failures++;

    // keep failure counter for 1 hour
    set_transient( 'llm_toolkit_sitemap_failures', $failures, HOUR_IN_SECONDS );

    if ( $failures >= 3 ) {
        // cooldown for 30 mins after first trip, capped at 2 hours
        $cooldown = min( 30 * 60 * ( $failures - 2 ), 2 * HOUR_IN_SECONDS );
        set_transient( 'llm_toolkit_sitemap_cooldown', true, $cooldown );

        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch warning: Too many failures. Cooldown set for ' . gmdate( 'H:i:s', $cooldown ) . ' after ' . $failures . 'failures.' );
    }

    return $failures;
}

// helper method to call the OpenAI API
function llm_toolkit_openai_call( $prompt ) {

    // set OpenAI API configs in wp-config for access and security
    $api_key    = llm_toolkit_decrypt( get_option( 'llm_toolkit_api_key' ) );
    $api_model  = get_option( 'llm_toolkit_ai_model' );
    $max_tokens = get_option( 'llm_toolkit_ai_max_tokens' );

    if ( empty( $api_key ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: API key not set' );
        return false;
    }

    require_once WP_PLUGIN_DIR . '/llm-toolkit/config/api-models.php';

    switch ( $api_model ) {
        case 'gpt-5.2':
            $response = llm_toolkit_5_2( $api_key, $api_model, $max_tokens, $prompt );
            break;
        case 'gpt-5.1':
            $response = llm_toolkit_5_1( $api_key, $api_model, $max_tokens, $prompt );
            break;
        case 'gpt-4.1':
            $response = llm_toolkit_4_1( $api_key, $api_model, $max_tokens, $prompt );
            break;
        default:
            llm_toolkit_add_status_message( 'error', 'OpenAI call: unsuported model: ' . $api_model );
            return false;
    }

    if ( is_wp_error( $response ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: wordpress error retrieved: ' . $response->get_error_message() );
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ] ) ) {
        return $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ];
    }

    if ( isset( $data[ 'error' ] ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: error from API: ' . $data[ 'error' ][ 'message' ] );
        return false;
    }

    llm_toolkit_add_status_message( 'error', 'OpenAI call: No content retrieved from OpenAI' );
    return false;
}