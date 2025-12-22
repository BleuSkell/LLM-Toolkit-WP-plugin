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
    $api_key    = OPENAI_API_KEY;
    $api_model  = OPENAI_MODEL;
    $max_tokens = OPENAI_MAX_TOKENS;

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
        llm_toolkit_add_status_message( 'error', 'Error: OpenAI Error ' . $response->get_error_message() );
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ] ) ) {
        return $data[ 'choices' ][ 0 ][ 'message' ][ 'content' ];
    }

    if ( isset( $data[ 'error' ] ) ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI: ' . $data[ 'error' ][ 'message' ] );
        return false;
    }

    llm_toolkit_add_status_message( 'error', 'Error: No content retrieved from OpenAI' );
    return false;
}

// helper method to generate a llm optimized html file through OpenAI
function generate_llm_html( $url, $file_path ) {

    $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
        llm_toolkit_add_status_message( 'error', 'Error: Failed to fetch the url' );
        return false;
    }

    $html = wp_remote_retrieve_body( $response );
    if ( empty( $html ) ) {
        llm_toolkit_add_status_message( 'error', 'Error: Empty HTML retrieved from the url' );
        return false;
    }

    // strip scripts and styles before sending to OpenAI (saves tokens)
    $dom = new DOMDocument();
    @$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

    // remove scripts
    $scripts = $dom->getElementsByTagName( 'script' );
    while ( $scripts->length > 0 ) {
        $scripts->item( 0 )->parentNode->removeChild( $scripts->item( 0 ) );
    }

    // remove styles
    $styles = $dom->getElementsByTagName( 'style' );
    while ( $styles->length > 0 ) {
        $styles->item( 0 )->parentNode->removeChild( $styles->item( 0 ) );
    }

    // keep specific header elements
    // get <head>
    $head = $dom->getElementsByTagName( 'head' )->item( 0 );

    if ( $head ) {

        // loop through <head> children, convert into array
        foreach ( iterator_to_array( $head->childNodes ) as $node ) {

            // process HTML elements only, skip text/comments
            if ($node->nodeType === XML_ELEMENT_NODE) {

                // convert tagnames to lowercase, <LINK> to 'link'
                $tag = strtolower( $node->nodeName );

                // remove everything except <meta>, <link>, <title>
                if ( ! in_array( $tag, [ 'meta', 'link', 'title' ] ) ) {
                    $head->removeChild( $node );
                } else {

                    // optional, for meta tags only keep SEO-relevant ones
                    if ( $tag === 'meta' ) {
                        $nameAttr = $node->getAttribute( 'name' );

                        // only keep tags for robots, description, viewport
                        if ( ! in_array( strtolower( $nameAttr ), [ 'robots', 'description', 'viewport' ] ) ) {
                            // canonical, hreflang, other SEO meta preserver elsewhere
                            continue;
                        }
                    }
                }
            }
        }
    }

    // save the cleaned html
    $cleaned_html = $dom->saveHTML();
    if ( empty( $cleaned_html ) || strlen( $cleaned_html ) < 35 ) {
        $cleaned_html = $html;
    }

    // get custom prompt from settings
    $custom_prompt = sanitize_textarea_field( get_option( 'llm_toolkit_prompt', '' ) );

    // split html into chunks so OpenAI can handle it
    $html_chunks = str_split( $cleaned_html, 30000 ); // 30K chars
    $optimized_html_chunks = [];

    foreach ( $html_chunks as $chunk ) {
        if ( ! empty( $custom_prompt ) ) {
            $prompt = $custom_prompt . "\n\nHTML van de pagina: \n$chunk\n\n";
            $result = llm_toolkit_openai_call( $prompt );

            if ( $result === false ) {
                return false;
            }

            $optimized_html_chunks[] = $result; // add optimalized chunk to array
        } else {
            $fallback_prompt = "
                Maak van deze HTML een cleane, semantische versie met alleen de relevante content:
                - Behoud alle originele koppen, paragrafen, lijsten, tabellen en links (<a>, etc.).
                - Voeg geen nieuwe content toe die niet op de pagina staat.
                - Verwijder alleen scripts, styles en irrelevante elementen.
                - Geef enkel de HTML terug, zonder extra tekst of uitleg en zonder ```html.
                - Afbeeldingen hoef je er niet bij te zetten.
                - Behoud canonical, hreflang, robots, en alle andere SEO-gerelateerde tags uit de <head>.
                
                HTML van de pagina:
                $chunk
                

            ";

            $prompt = $fallback_prompt; // fallback prompt
            $optimized_html_chunks[] = llm_toolkit_openai_call( $prompt );

            llm_toolkit_add_status_message( 'error', 'Failed to fetch the custom prompt, fallback prompt used' );
        }
    }

    // combine chunks into 1 html
    $llm_optimized_html = implode( "\n", $optimized_html_chunks );

    if ( $llm_optimized_html === '' ) {
        llm_toolkit_add_status_message( 'error', 'OpenAI returned an empty output' );
        return false;
    }

    // check if OpenAI returned the llm html
    if ( ! empty( $llm_optimized_html ) ) {

        // create the folder if it doesn't yet exist
        if ( ! is_dir( dirname( $file_path ) ) ) {
            mkdir( dirname( $file_path ), 0755, true );
        }

        // append the llm optimized html file into the folder
        file_put_contents( $file_path, $llm_optimized_html );
        return true;
    }

    llm_toolkit_add_status_message( 'error', 'OpenAI returned an empty output' );
    return false;
}

// generate single llm html with admin toolbar button
add_action( 'wp_ajax_llm_toolkit_generate_single_html', 'llm_toolkit_generate_single_html' );
function llm_toolkit_generate_single_html( $url ) {
    if ( empty( $_POST['url'] ) ) {
        llm_toolkit_add_status_message( 'error', 'Error generate single: No URL received' );
        wp_send_json_error( 'No URL received' ); // display error alert in generate single js
        return;
    }

    $url = esc_url_raw( $_POST[ 'url' ] );
    $path = wp_parse_url( $url, PHP_URL_PATH );

    $file_slug = trim( $path, '/' );
    $file_slug = $file_slug ?: 'index';

    $file_path = WP_CONTENT_DIR . '/llms/' . $file_slug . '.html';

    // generate llm html
    $success = generate_llm_html( $url, $file_path );

    if ( $success ) {
        llm_toolkit_add_status_message( 'success', "Success generate single: Succesfully generated LLM HTML for $url and $file_path" );
        wp_send_json_success( "Generated $url" );
    } else {
        llm_toolkit_add_status_message( 'error', "Error generate single: Failed to generate LLM HTML for $url and $file_path" );
        wp_send_json_error( "Failed to generate $url" );
    }

    // stop executing
    wp_die();
}