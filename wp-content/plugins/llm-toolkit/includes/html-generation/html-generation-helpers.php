<?php
// helper method to read the sitemap (url based for the pages sitemap)
function read_sitemap( $sitemap_url ) {
    $urls = [];
    $xml = @simplexml_load_file( $sitemap_url ); // return the object of the SimpleXMLElement class from the sitemap

    // debug error log, prevent silent failure
    if (!$xml) {
        llm_toolkit_add_status_message( 'error', "Error: Unable to parse XML response from $sitemap_url" );
        return [];
    }

    // check if the xml object and its urls are set
    if ( $xml && isset( $xml->url ) ) {

        // loop through all xml urls
        foreach ( $xml->url as $url ) {

            // take loc url from the current node url, convert it into a string, append it into $urls[]
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

    $body = [
        'model' => $api_model,
        'messages' => [
            [
                'role'      => 'user',
                'content'   => $prompt,
            ]
        ],
        'max_completion_tokens' => (int) $max_tokens,
    ];

    $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'      => wp_json_encode( $body ),
            'timeout'   => 120,
        ]
    );

    if ( is_wp_error( $response ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: ' . $response->get_error_message() );
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ] ) ) {
        return $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ];
    }

    if ( isset( $data[ 'error' ] ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: ' . $data[ 'error' ][ 'message' ] );
        return false;
    }

    llm_toolkit_add_status_message( 'error', 'OpenAI call: No content retrieved from OpenAI' );
    return false;
}