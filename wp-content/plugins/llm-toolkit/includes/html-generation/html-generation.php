<?php
require_once __DIR__ . '/html-generation-helpers.php';

// method to generate a llm optimized html file through OpenAI
function generate_llm_html( $url, $file_path, $sitemap_url = null ) {

    // fetch the url and check for errors
    $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
        llm_toolkit_add_status_message( 'error', 'Error: Failed to fetch the url' );
        return false;
    }

    // fetch the html from $response and check if the HTML is received
    $html = wp_remote_retrieve_body( $response );
    if ( empty( $html ) ) {
        llm_toolkit_add_status_message( 'error', 'Error: Empty HTML retrieved from the url' );
        return false;
    }

    // convert to HTML-ENTITIES to process special chars correctly
    $html = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );

    // load the html in a DOM structure
    $dom = new DOMDocument();
    @$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

    // remove scripts from html
    $scripts = $dom->getElementsByTagName( 'script' );
    while ( $scripts->length > 0 ) {
        $scripts->item( 0 )->parentNode->removeChild( $scripts->item( 0 ) );
    }

    // remove styles from html
    $styles = $dom->getElementsByTagName( 'style' );
    while ( $styles->length > 0 ) {
        $styles->item( 0 )->parentNode->removeChild( $styles->item( 0 ) );
    }

    // clean up the HTML head
    // keep only SEO relevant meta tags and links
    $head = $dom->getElementsByTagName( 'head' )->item( 0 );
    if ( $head ) {

        // loop through <head> childNodes
        // copy childNodes to array as they update live during deletion
        foreach ( iterator_to_array( $head->childNodes ) as $node ) {

            // process HTML elements only, skip text/comments
            if ($node->nodeType === XML_ELEMENT_NODE) {

                // convert tagnames to lowercase for consistency, e.g: <LINK> to 'link'
                $tag = strtolower( $node->nodeName );

                // remove everything except <meta>, <link>, <title>
                if ( ! in_array( $tag, [ 'meta', 'link', 'title' ] ) ) {
                    $head->removeChild( $node );
                } else {

                    // extra filtering for meta tags, keeping the SEO-relevant ones
                    if ( $tag === 'meta' ) {
                        $nameAttr = $node->getAttribute( 'name' );

                        // tags for robots, description, viewport are relevant for LLMs
                        if ( ! in_array( strtolower( $nameAttr ), [ 'robots', 'description', 'viewport' ] ) ) {
                            continue;
                        }
                    }
                }
            }
        }
    }

    // get content in body tag
    $body = $dom->getElementsByTagName( 'body' )->item( 0 );
    $body_html = '';
    if ( $body ) {
        // loop through childNodes and convert to HTML string
        foreach ( $body->childNodes as $node ) {
            // append to $body_html
            $body_html .= $dom->saveHTML( $node );
        }
    }

    // if no body tag has been found use the full document, including header
    if ( empty( $body_html ) ) {
        $body_html = $dom->saveHTML();
    }

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

    // fallback prompt if no custom prompt has been found
    $fallback_prompt = "Je bent de beste HTML coder en schrijft altijd foutloze html-pagina's. Maak van deze HTML een cleane, semantische en correcte versie, geoptimaliseerd voor het lezen door LLMs met alleen de relevante content zoals hieronder beschreven:

- Behoud de originele paginastructuur.
- Behoud alle originele koppen, paragrafen, lijsten, tabellen en links (<a>, etc.).
- Voeg geen nieuwe inhoud toe.
- Verwijder scripts, styles, classes, icons, navigatie, footers, sidebars, tracking, advertenties, pop-ups, cookie banners en irrelevante elementen.
- Geef enkel de HTML terug, zonder extra tekst of uitleg en zonder ```html.
- Afbeeldingen opnemen inclusief alt tags
- Behoud alleen de volgende tags in de <head>: canonical, hreflang, robots, alternate, title, description, site name, generator, article:modified.";

    // split HTML into chunks of max 30K chars
    $html_chunks = [];
    $chunk_size = 30000;
    $length = mb_strlen( $body_html, 'UTF-8' ); // get the length of the body

    for ( $i = 0; $i < $length; $i+=$chunk_size ) {
        // split the body html into chunks and add them to the array
        $html_chunks[] = mb_substr( $body_html, $i, $chunk_size, 'UTF-8' );
    }

    $optimized_body_chunks = [];
    foreach ( $html_chunks as $chunk ) {
        if ( ! empty( $custom_prompt ) ) {
            $prompt = $custom_prompt . "\n\nHTML:\n$chunk\n\n";
        } else {
            $prompt = $fallback_prompt . "\n\nHTML:\n$chunk\n\n";
        }

        // send the HTML chunk to OpenAI
        $result = llm_toolkit_openai_call( $prompt );

        // if the OpenAI API call fails stop the entire process
        if ( $result === false ) {
            return false;
        }

        // convert the result to the right encoding and add them to the array
        $result = mb_convert_encoding( $result, 'HTML-ENTITIES', 'UTF-8' );
        $optimized_body_chunks[] = $result;
    }

    // create a new DOMDocument for the optimalized HTML
    $new_dom = new DOMDocument();
    // start with a base HTML structure
    @$new_dom->loadHTML( '<?xml encoding="utf-8"?><!DOCTYPE html><html></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

    // add cleaned head to the new document with all childNodes
    if ( $head ) {
        $imported_head = $new_dom->importNode( $head, true );
        $new_dom->documentElement->appendChild( $imported_head );
    }

    // create a new body tag
    $body_wrapper = $new_dom->createElement( 'body' );

    // loop through all optimized HTML chunks
    foreach ( $optimized_body_chunks as $chunk ) {
        // create a temporary DOMDocument to parse the chunk HTML
        $tmp_dom = new DOMDocument();
        @$tmp_dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $chunk . '</body>', LIBXML_HTML_NOIMPLIED| LIBXML_HTML_NODEFDTD );
        $tmp_body = $tmp_dom->getElementsByTagName( 'body' )->item( 0 );

        // import all nodes from the chunk into the main document
        if ( $tmp_body ) {
            foreach ( $tmp_body->childNodes as $node ) {
                // import all nodes and add to the body wrapper
                $imported = $new_dom->importNode( $node, true );
                $body_wrapper->appendChild( $imported );
            }
        }
    }

    // add the complete body to the document
    $new_dom->documentElement->appendChild( $body_wrapper );

    // create the file directory if it doesn't exist yet
    if ( ! is_dir( dirname($file_path) ) ) {
        mkdir( dirname( $file_path ), 0755, true );
    }

    // put optimized content in the file and convert to UTF-8
    file_put_contents( $file_path, mb_convert_encoding( $new_dom->saveHTML(), 'UTF-8', 'HTML-ENTITIES' ) );
    return true;
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

    foreach ( $selected_sitemaps as $sitemap ) {
        $urls = read_sitemap( $sitemap );
        if ( in_array( $url, $urls, true ) ) {
            $sitemap_url = $sitemap;
            break;
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