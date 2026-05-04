<?php
require_once __DIR__ . '/html-generation-helpers.php';

// method to generate a llm optimized html file through OpenAI
function generate_llm_html( $url, $file_path, $sitemap_url = null ) {
    // extend php execution time for large API calls (no effect if PHP is in safe mode)
    @set_time_limit( 360 );

    $site_user = get_option( 'llm_toolkit_site_user' );

    // fetch the url and check for errors
    // X-LLM-Fetch header signals WordPress to disable lazy loading (see filter below)
    $response = wp_remote_get( $url, [
        'timeout'   => 30,
        'headers'   => [
            'Authorization' => 'Basic ' . base64_encode( $site_user . ':' . llm_toolkit_decrypt( get_option( 'llm_toolkit_site_pass' ) ) ),
            'X-LLM-Fetch'  => '1',
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
    $libxml_previous = libxml_use_internal_errors( true );
    @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    libxml_use_internal_errors( $libxml_previous );

    // --- extract page context from <head> before stripping ---

    // extract page title
    $page_title = '';
    $titles = $dom->getElementsByTagName( 'title' );
    if ( $titles->length > 0 ) {
        $page_title = trim( $titles->item( 0 )->textContent );
    }

    // extract meta description and language
    $page_description = '';
    $page_locale      = '';
    foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
        if ( strtolower( $meta->getAttribute( 'name' ) ) === 'description' ) {
            $page_description = $meta->getAttribute( 'content' );
        }
        if ( $meta->getAttribute( 'property' ) === 'og:locale' ) {
            $page_locale = $meta->getAttribute( 'content' );
        }
    }
    // fall back to <html lang="...">
    if ( empty( $page_locale ) ) {
        $html_els = $dom->getElementsByTagName( 'html' );
        if ( $html_els->length > 0 ) {
            $page_locale = $html_els->item( 0 )->getAttribute( 'lang' );
        }
    }

    // extract canonical url and hreflang alternates
    $page_canonical = $url;
    $hreflang_lines = [];
    foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
        if ( $link->getAttribute( 'rel' ) === 'canonical' ) {
            $page_canonical = $link->getAttribute( 'href' );
        }
        if ( $link->getAttribute( 'rel' ) === 'alternate' && $link->hasAttribute( 'hreflang' ) ) {
            $hreflang_lines[] = $link->getAttribute( 'hreflang' ) . ': ' . $link->getAttribute( 'href' );
        }
    }

    // extract existing JSON-LD structured data BEFORE scripts are removed
    $existing_schema = '';
    foreach ( $dom->getElementsByTagName( 'script' ) as $script ) {
        if ( $script->getAttribute( 'type' ) === 'application/ld+json' ) {
            // compact the JSON to minimise tokens
            $decoded = json_decode( trim( $script->textContent ), true );
            $existing_schema = $decoded ? json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : trim( $script->textContent );
            break;
        }
    }

    // --- strip noise from dom ---

    // remove all scripts (including JSON-LD — already extracted above)
    $scripts = $dom->getElementsByTagName( 'script' );
    while ( $scripts->length > 0 ) {
        $scripts->item( 0 )->parentNode->removeChild( $scripts->item( 0 ) );
    }

    // remove all styles
    $styles = $dom->getElementsByTagName( 'style' );
    while ( $styles->length > 0 ) {
        $styles->item( 0 )->parentNode->removeChild( $styles->item( 0 ) );
    }

    // remove the entire <head> — metadata is already extracted above
    $heads = $dom->getElementsByTagName( 'head' );
    while ( $heads->length > 0 ) {
        $heads->item( 0 )->parentNode->removeChild( $heads->item( 0 ) );
    }

    // remove deprecated empty <a name="..."> anchors (no href, only name attr)
    $anchors_to_remove = [];
    foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
        if ( $a->hasAttribute( 'name' ) && ! $a->hasAttribute( 'href' ) && trim( $a->textContent ) === '' ) {
            $anchors_to_remove[] = $a;
        }
    }
    foreach ( $anchors_to_remove as $el ) {
        if ( $el->parentNode ) {
            $el->parentNode->removeChild( $el );
        }
    }

    // promote title → alt on images with empty alt, so the context survives attribute stripping
    foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
        $title = $img->getAttribute( 'title' );
        if ( $img->getAttribute( 'alt' ) === '' && $title !== '' ) {
            $img->setAttribute( 'alt', $title );
        }
    }

    // remove decorative images (empty alt AND no title) — these add no value for bots
    $imgs_to_remove = [];
    foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
        if ( $img->getAttribute( 'alt' ) === '' && $img->getAttribute( 'title' ) === '' ) {
            $imgs_to_remove[] = $img;
        }
    }
    foreach ( $imgs_to_remove as $el ) {
        // if the parent <figure> becomes empty after removal, remove that too
        $parent = $el->parentNode;
        if ( $el->parentNode ) {
            $el->parentNode->removeChild( $el );
        }
        if ( $parent && $parent->nodeName === 'figure' && trim( $parent->textContent ) === '' ) {
            if ( $parent->parentNode ) {
                $parent->parentNode->removeChild( $parent );
            }
        }
    }

    // --- strip all presentational / noisy attributes to reduce token usage ---
    $xpath = new DOMXPath( $dom );

    // resolve lazy-loaded images BEFORE removing noscript/iframe/svg
    // use a $resolved flag instead of break N to avoid accidentally skipping other images
    $lazy_candidates = [ 'data-src', 'data-lazy-src', 'data-lazy', 'data-original', 'data-ll-src', 'data-wp-lazy' ];
    foreach ( iterator_to_array( $dom->getElementsByTagName( 'img' ) ) as $img ) {
        $current_src = $img->getAttribute( 'src' );
        if ( strpos( $current_src, 'data:' ) !== 0 && $current_src !== '' ) {
            continue; // src is already a real url, skip
        }

        $resolved = false;

        // 1. try data-* attributes on the img itself
        foreach ( $lazy_candidates as $candidate ) {
            $real_src = $img->getAttribute( $candidate );
            if ( ! empty( $real_src ) && strpos( $real_src, 'data:' ) !== 0 ) {
                $img->setAttribute( 'src', $real_src );
                $resolved = true;
                break;
            }
        }

        // 2. try srcset or data-srcset — extract the first real URL
        if ( ! $resolved ) {
            foreach ( [ 'srcset', 'data-srcset' ] as $srcset_attr ) {
                $srcset = $img->getAttribute( $srcset_attr );
                if ( empty( $srcset ) ) continue;
                $srcset_parts = preg_split( '/,\s+/', trim( $srcset ) );
                foreach ( $srcset_parts as $part ) {
                    $srcset_url = trim( preg_replace( '/\s+\S+$/', '', trim( $part ) ) );
                    if ( ! empty( $srcset_url ) && strpos( $srcset_url, 'data:' ) !== 0 ) {
                        $img->setAttribute( 'src', $srcset_url );
                        $resolved = true;
                        break 2;
                    }
                }
            }
        }

        // 3. try a <noscript> sibling — walk up to 3 parent levels (img > a > figure > section)
        if ( ! $resolved ) {
            $check_node = $img;
            for ( $level = 0; $level < 3; $level++ ) {
                $check_node = $check_node ? $check_node->parentNode : null;
                if ( ! $check_node ) break;
                $sibling = $check_node->firstChild;
                while ( $sibling ) {
                    if ( $sibling->nodeName === 'noscript' ) {
                        $noscript_dom = new DOMDocument();
                        $noscript_prev = libxml_use_internal_errors( true );
                        @$noscript_dom->loadHTML( '<body>' . $sibling->textContent . '</body>' );
                        libxml_clear_errors();
                        libxml_use_internal_errors( $noscript_prev );

                        $noscript_imgs = $noscript_dom->getElementsByTagName( 'img' );
                        if ( $noscript_imgs->length > 0 ) {
                            $real_src = $noscript_imgs->item( 0 )->getAttribute( 'src' );
                            if ( ! empty( $real_src ) && strpos( $real_src, 'data:' ) !== 0 ) {
                                $img->setAttribute( 'src', $real_src );
                                $resolved = true;
                                break 2;
                            }
                        }
                    }
                    $sibling = $sibling->nextSibling;
                }
            }
        }
    }

    // remove noscript and iframe elements (tracking, embeds — no content value)
    foreach ( ['noscript', 'iframe', 'svg'] as $tag ) {
        $els = $dom->getElementsByTagName( $tag );
        while ( $els->length > 0 ) {
            $els->item( 0 )->parentNode->removeChild( $els->item( 0 ) );
        }
    }

    // strip presentational attributes from every element
    $noisy_attrs = [
        'class', 'id', 'style', 'width', 'height', 'target', 'rel',
        'loading', 'decoding', 'fetchpriority', 'crossorigin', 'title',
        'tabindex', 'role', 'sizes', 'srcset',
    ];
    foreach ( $xpath->query( '//*' ) as $el ) {
        foreach ( $noisy_attrs as $attr ) {
            $el->removeAttribute( $attr );
        }
        // strip all data-* and aria-* attributes
        $drop = [];
        foreach ( $el->attributes as $attr ) {
            if ( strpos( $attr->name, 'data-' ) === 0 || strpos( $attr->name, 'aria-' ) === 0 ) {
                $drop[] = $attr->name;
            }
        }
        foreach ( $drop as $attr_name ) {
            $el->removeAttribute( $attr_name );
        }
    }

    // remove HTML comments
    $comment_nodes = [];
    foreach ( $xpath->query( '//comment()' ) as $comment ) {
        $comment_nodes[] = $comment;
    }
    foreach ( $comment_nodes as $comment ) {
        if ( $comment->parentNode ) {
            $comment->parentNode->removeChild( $comment );
        }
    }

    // remove elements that are now empty (no text, no child elements)
    foreach ( ['li', 'p', 'figure', 'figcaption', 'a', 'span', 'div', 'section', 'ul', 'ol'] as $tag ) {
        $changed = true;
        while ( $changed ) {
            $changed = false;
            $els     = iterator_to_array( $dom->getElementsByTagName( $tag ) );
            foreach ( $els as $el ) {
                if ( ! $el->hasChildNodes() && trim( $el->textContent ) === '' ) {
                    if ( $el->parentNode ) {
                        $el->parentNode->removeChild( $el );
                        $changed = true;
                    }
                }
            }
        }
    }

    // save the cleaned html (body content only)
    $cleaned_html = $dom->saveHTML();

    // collapse whitespace to reduce token count further
    $cleaned_html = preg_replace( '/\s+/', ' ', $cleaned_html );
    $cleaned_html = preg_replace( '/> </', '><', $cleaned_html );
    $cleaned_html = trim( $cleaned_html );

    // truncate very large pages (e.g. product listings) to avoid API timeouts
    // ~60 000 chars ≈ 15 000 tokens — enough to cover any page's key content
    $max_html_chars = 60000;
    $html_truncated = false;
    if ( mb_strlen( $cleaned_html ) > $max_html_chars ) {
        $cleaned_html  = mb_substr( $cleaned_html, 0, $max_html_chars );
        // cut at last complete tag so we don't send a broken tag to the AI
        $last_tag_end  = strrpos( $cleaned_html, '>' );
        if ( $last_tag_end !== false ) {
            $cleaned_html = substr( $cleaned_html, 0, $last_tag_end + 1 );
        }
        $html_truncated = true;
    }

    // build prompt context block
    $context_lines   = [];
    $context_lines[] = 'PAGE URL: ' . $url;
    if ( $html_truncated ) $context_lines[] = 'NOTE: HTML was truncated due to page size. Focus on the content that is present.';
    if ( ! empty( $page_canonical ) )    $context_lines[] = 'CANONICAL URL: ' . $page_canonical;
    if ( ! empty( $page_title ) )        $context_lines[] = 'PAGE TITLE: ' . $page_title;
    if ( ! empty( $page_description ) )  $context_lines[] = 'META DESCRIPTION: ' . $page_description;
    if ( ! empty( $page_locale ) )       $context_lines[] = 'LANGUAGE: ' . $page_locale;
    if ( ! empty( $hreflang_lines ) )    $context_lines[] = 'HREFLANG ALTERNATES:' . "\n  " . implode( "\n  ", $hreflang_lines );
    if ( ! empty( $existing_schema ) )   $context_lines[] = 'EXISTING STRUCTURED DATA (use as reference, expand where possible):' . "\n" . $existing_schema;

    $page_context_block = "=== PAGE CONTEXT ===\n" . implode( "\n", $context_lines ) . "\n=== END PAGE CONTEXT ===";

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
    $active_prompt = ! empty( $custom_prompt ) ? $custom_prompt : $default_prompt;
    $prompt = $active_prompt . "\n\n" . $page_context_block . "\n\nHTML:\n" . $cleaned_html;

    // call the openai api
    $result = llm_toolkit_openai_call( $prompt );
    if ( $result === false ) {
        llm_toolkit_add_status_message( 'error', 'Generate HTML: OpenAI call failed' );
        return false;
    }

    // encode the result into UTF-8
    $optimized_body = mb_convert_encoding( $result, 'UTF8', 'auto' );

    // check if openai returned content
    if ( ! empty( $optimized_body ) ) {

        // build <head> from extracted metadata
        $head  = "<!DOCTYPE html>\n";
        $head .= '<html lang="' . esc_attr( $page_locale ?: 'nl' ) . '">' . "\n";
        $head .= "<head>\n";
        $head .= '  <meta charset="UTF-8">' . "\n";
        $head .= '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";

        if ( ! empty( $page_title ) ) {
            $head .= '  <title>' . htmlspecialchars( $page_title, ENT_QUOTES, 'UTF-8' ) . '</title>' . "\n";
        }
        if ( ! empty( $page_description ) ) {
            $head .= '  <meta name="description" content="' . esc_attr( $page_description ) . '">' . "\n";
        }
        if ( ! empty( $page_canonical ) ) {
            $head .= '  <link rel="canonical" href="' . esc_url( $page_canonical ) . '">' . "\n";
        }
        $head .= '  <meta name="robots" content="index, follow">' . "\n";

        foreach ( $hreflang_lines as $hreflang_line ) {
            // format: "nl: https://..." → split on first ": "
            $parts = explode( ': ', $hreflang_line, 2 );
            if ( count( $parts ) === 2 ) {
                $head .= '  <link rel="alternate" hreflang="' . esc_attr( trim( $parts[0] ) ) . '" href="' . esc_url( trim( $parts[1] ) ) . '">' . "\n";
            }
        }

        if ( ! empty( $existing_schema ) ) {
            $head .= '  <script type="application/ld+json">' . "\n"
                . str_replace( '</', '<\/', $existing_schema )
                . "\n" . '  </script>' . "\n";
        }

        $head .= "</head>\n";

        // strip any <html>, <head>, <!DOCTYPE> the AI may have generated in its body output
        $optimized_body = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $optimized_body );
        $optimized_body = preg_replace( '/<html[^>]*>/i', '', $optimized_body );
        $optimized_body = preg_replace( '/<\/html>/i', '', $optimized_body );
        $optimized_body = preg_replace( '/<head>.*?<\/head>/is', '', $optimized_body );
        $optimized_body = trim( $optimized_body );

        $optimized_html = $head . $optimized_body . "\n</html>";

        // create the file directory if it doesn't exist yet
        if ( ! is_dir( dirname( $file_path ) ) ) {
            mkdir( dirname( $file_path ), 0755, true );
        }

        if ( file_put_contents( $file_path, $optimized_html ) === false) {
            llm_toolkit_add_status_message( 'error', 'Generate HTML: Failed to write file: ' . $file_path );
            return false;
        }

        file_put_contents( $file_path, $optimized_html );
        return true;
    }

    llm_toolkit_add_status_message( 'error', 'Generate HTML: OpenAI returned an empty output' );
    return false;
}

// generate single llm html with admin toolbar button
function llm_toolkit_generate_single_html( $url ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    check_ajax_referer( 'llm_toolkit_generate_single_html', 'nonce' );

    if ( empty( $_POST['url'] ) ) {
        llm_toolkit_add_status_message( 'error', 'Error generate single: No URL received' );
        wp_send_json_error( 'No URL received' ); // display error alert in generate single js
        return;
    }

    $url = esc_url_raw( $_POST[ 'url' ] );

    $url_host = wp_parse_url( $url, PHP_URL_HOST );
    $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( $url_host !== $site_host ) {
        wp_send_json_error( 'URL must be on the same domain as the site.' );
    }

    $path = wp_parse_url( $url, PHP_URL_PATH );

    $file_slug = trim( $path, '/' ) ?: 'index';
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

        if ( $sitemap_url === '' ) {
            llm_toolkit_add_status_message( 'error', "Error generate single: URL not found in any selected sitemap." );
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