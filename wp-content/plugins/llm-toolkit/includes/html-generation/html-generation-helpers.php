<?php
// helper method to read the sitemap (url based for the pages sitemap)
function read_sitemap( $sitemap_url ) {
    $site_user = get_option( 'llm_toolkit_site_user' );
    $urls = [];

    $response = wp_remote_get( $sitemap_url, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( $site_user . ':' . llm_toolkit_decrypt( get_option( 'llm_toolkit_site_pass' ) ) )
        ]
    ]);

    if ( is_wp_error( $response ) ) {
        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch failed: ' . $response->get_error_message() );
        return [];
    }

    $body = wp_remote_retrieve_body( $response );
    $xml = simplexml_load_string( $body );

    if ( ! $xml ) {
        llm_toolkit_add_status_message( 'error', 'Generation helper sitemap fetch failed: Unable to parse XML response from ' . $sitemap_url . '. ' . $body );
        return [];
    }

    if ( $xml && isset( $xml->url ) ) {
        foreach ( $xml->url as $url ) {
            $urls[] = (string) $url->loc;
        }
    }

    return $urls;
}

// helper method to call the OpenAI API
function llm_toolkit_openai_call( $prompt ) {

    // set OpenAI API configs in wp-config for access and security
    $api_key    = llm_toolkit_decrypt( get_option( 'llm_toolkit_api_key' ) );
    $api_model  = get_option( 'llm_toolkit_ai_model' );
    $max_tokens = get_option( 'llm_toolkit_ai_max_tokens' );

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