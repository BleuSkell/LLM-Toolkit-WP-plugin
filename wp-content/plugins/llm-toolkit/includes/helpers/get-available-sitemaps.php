<?php

function llm_toolkit_get_available_sitemaps() {
    $sitemap_index = home_url( '/sitemap.xml' );
    $xml = @simplexml_load_file( $sitemap_index );

    if ( ! $xml || ! isset( $xml->sitemap ) ) {
        return [];
    }

    $sitemaps = [];
    foreach ( $xml->sitemap as $sitemap ) {
        $loc = (string) $sitemap->loc;
        $sitemaps[] = $loc;
    }

    return $sitemaps;
}