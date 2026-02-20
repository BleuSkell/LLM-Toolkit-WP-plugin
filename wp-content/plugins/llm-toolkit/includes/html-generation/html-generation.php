<?php
require_once __DIR__ . '/html-generation-helpers.php';

// method to generate a llm optimized html file through OpenAI
function generate_llm_html( $url, $file_path, $sitemap_url = null ) {
    $site_user = get_option( 'llm_toolkit_site_user' );

    // fetch the url and check for errors
    $response = wp_remote_get( $url, [
        'timeout'   => 30,
        'headers'   => [
            'Authorization' => 'Basic ' . base64_encode( $site_user . ':' . llm_toolkit_decrypt( get_option( 'llm_toolkit_site_pass' ) ) )
        ]
    ]);
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
        llm_toolkit_add_status_message( 'error', 'Error generate llm html: Failed to fetch the url' );
        return false;
    }

    // fetch the html from $response and check if the HTML is received
    $html = wp_remote_retrieve_body( $response );
    if ( empty( $html ) ) {
        llm_toolkit_add_status_message( 'error', 'Error: Empty HTML retrieved from the url' );
        return false;
    }

    // convert to HTML-ENTITIES to process special chars correctly
    $html = mb_convert_encoding( $html, 'UTF-8', 'auto' );

    // load the html in a DOM structure
    $dom = new DOMDocument();
    @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

    // remove scripts from dom html
    $scripts = $dom->getElementsByTagName( 'script' );
    while ( $scripts->length > 0 ) {
        $scripts->item( 0 )->parentNode->removeChild( $scripts->item( 0 ) );
    }

    // remove styles from dom html
    $styles = $dom->getElementsByTagName( 'style' );
    while ( $styles->length > 0 ) {
        $styles->item( 0 )->parentNode->removeChild( $styles->item( 0 ) );
    }

    // save the cleaned html
    $cleaned_html = $dom->saveHTMl();

    $default_prompt = get_option( 'llm_toolkit_default_prompt' );

    // if there's a sitemap url, search for the specific custom prompt of that sitemap
    $custom_prompts = get_option( 'llm_toolkit_prompts', [] );
    $custom_prompt = '';
    if ( $sitemap_url ) {
        // use md5 as key
        $sitemap_key = md5( $sitemap_url );
        if ( isset( $custom_prompts[ $sitemap_key ] ) ) {
            $custom_prompt = sanitize_textarea_field( $custom_prompts[ $sitemap_key ] );
        }
    }

    // use the custom prompt if set
    if ( ! empty( $custom_prompt ) ) {
        $prompt = $custom_prompt . "\n\nHTML:\n$cleaned_html";
    } else {
        // use the default prompt
        $prompt = $default_prompt . "\n\nHTML:\n$cleaned_html";
    }

    // call the openai api
    $result = llm_toolkit_openai_call( $prompt );
    if ( $result === false ) {
        llm_toolkit_add_status_message( 'error', 'Generate HTML: OpenAI call failed' );
        return false;
    }

    // encode the result into UTF-8
    $optimized_html = mb_convert_encoding( $result, 'UTF8', 'auto' );

    // check if openai returned content
    if ( ! empty( $optimized_html ) ) {
        // create the file directory if it doesn't exist yet
        if ( ! is_dir( dirname($file_path) ) ) {
            mkdir( dirname( $file_path ), 0755, true );
        }

        // put optimized content in the file and convert to UTF-8
        file_put_contents( $file_path, $optimized_html );
        return true;
    }

    llm_toolkit_add_status_message( 'error', 'Generate HTML: OpenAI returned an empty output' );
    return false;
}

// generate single llm html with admin toolbar button
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

    $selected_sitemaps = get_option( 'llm_toolkit_selected_sitemaps', [] );
    $sitemap_url = '';

    if ( ! empty( $selected_sitemaps ) ) {
        foreach ($selected_sitemaps as $sitemap) {
            $urls = read_sitemap($sitemap);
            if (in_array($url, $urls, true)) {
                $sitemap_url = $sitemap;
                break;
            }
        }
    }

    // generate llm html
    $success = generate_llm_html( $url, $file_path, $sitemap_url );

    if ( $success ) {
        llm_toolkit_add_status_message( 'success', "Success generate single: Succesfully generated LLM HTML for $file_path" );
        wp_send_json_success( "Generated $file_path" );
    } else {
        llm_toolkit_add_status_message( 'error', "Error generate single: Failed to generate LLM HTML for $file_path" );
        wp_send_json_error( "Failed to generate $file_path" );
    }

    // stop executing
    wp_die();
}
add_action( 'wp_ajax_llm_toolkit_generate_single_html', 'llm_toolkit_generate_single_html' );