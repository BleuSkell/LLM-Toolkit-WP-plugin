<?php

function llm_toolkit_get_available_sitemaps() {
    $sitemap_index_option = get_option( 'llm_toolkit_sitemap_index' );
    if ( empty( $sitemap_index_option ) ) {
        return [];
    }

    $sitemap_index = home_url( '/' . ltrim( $sitemap_index_option, '/' ) );
    $cache_key     = 'llm_toolkit_sitemap_index_' . md5( $sitemap_index );

    // serve from short-lived cache so the index endpoint isn't hit on every admin page render
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    // honor the global sitemap cooldown
    if ( get_transient( 'llm_toolkit_sitemap_cooldown' ) ) {
        return [];
    }

    $site_user = get_option( 'llm_toolkit_site_user' );

    $args = [
        'timeout'     => 30,
        'redirection' => 5,
        'user-agent'  => 'LLM-Toolkit/1.0 (+sitemap-index-fetch)',
        'headers'     => [
            'Authorization' => 'Basic ' . base64_encode( $site_user . ':' . llm_toolkit_decrypt( get_option( 'llm_toolkit_site_pass' ) ) ),
            'Accept'        => 'application/xml,text/xml;q=0.9',
        ],
    ];

    // simple retry for transient 5xx / network errors
    $response = null;
    $code     = 0;
    for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
        $response = wp_remote_get( $sitemap_index, $args );
        $code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        if ( ! is_wp_error( $response ) && $code === 200 ) {
            break;
        }
        if ( $attempt < 2 ) {
            sleep( 2 );
        }
    }

    if ( is_wp_error( $response ) ) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Sitemap index fetch failed: ' . $response->get_error_message() );
        return [];
    }

    if ( $code !== 200 ) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Sitemap index fetch failed: HTTP ' . $code . ' from ' . $sitemap_index );
        return [];
    }

    $body = wp_remote_retrieve_body( $response );

    if ( stripos( ltrim( $body ), '<?xml' ) !== 0 && stripos( ltrim( $body ), '<sitemapindex' ) === false ) {
        llm_toolkit_register_sitemap_failure();
        llm_toolkit_add_status_message( 'error', 'Sitemap index fetch failed: response from ' . $sitemap_index . ' is not XML' );
        return [];
    }

    $previous = libxml_use_internal_errors( true );
    $xml      = simplexml_load_string( $body );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $xml || ! isset( $xml->sitemap ) ) {
        return [];
    }

    $sitemaps = [];
    foreach ( $xml->sitemap as $sitemap ) {
        $sitemaps[] = (string) $sitemap->loc;
    }

    // cache for 30 minutes
    set_transient( $cache_key, $sitemaps, 30 * MINUTE_IN_SECONDS );

    return $sitemaps;
}