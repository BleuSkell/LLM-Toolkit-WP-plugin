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
    include WP_PLUGIN_DIR . '/llm-toolkit/config/api-models.php';
    $path = WP_PLUGIN_DIR . '/llm-toolkit/config/api-models.php';
    llm_toolkit_add_status_message( 'success', 'OpenAI call: ' . $path );

    $api_key    = llm_toolkit_decrypt( get_option( 'llm_toolkit_api_key' ) );
    $api_model  = get_option( 'llm_toolkit_ai_model' );
    $max_tokens = get_option( 'llm_toolkit_ai_max_tokens' );

    switch ( $api_model ) {
        case 'gpt-5.1':
            $response = llm_toolkit_5_1( $api_key, $api_model, $max_tokens, $prompt );
            llm_toolkit_add_status_message( 'success', 'OpenAI call: successfully fetched the response' );
            break;
        default:
            llm_toolkit_add_status_message( 'error', 'OpenAI call: Invalid API model selected: ' . $api_model );
            return false;
    }

    if ( is_wp_error( $response ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: ' . $response->get_error_message() );
        return false;
    }

    llm_toolkit_add_status_message( 'success', 'OpenAI call: No wp errors found' );

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    llm_toolkit_add_status_message( 'success', 'OpenAI call: correctly fetched and decoded the response' );

    if ( isset( $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ] ) ) {
        $content = $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ];

        $content = preg_replace('/```html\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim( $content );

        llm_toolkit_add_status_message( 'success', 'OpenAI call: response content set: ' . substr( $content, 0, 200 ) );
        return $content;
    }

    if ( isset( $data[ 'error' ] ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI call: something went wrong with returning the data from response ' . $data[ 'error' ][ 'message' ] );
        return false;
    }

    llm_toolkit_add_status_message( 'error', 'OpenAI call: No content retrieved from OpenAI' );
    return false;
}