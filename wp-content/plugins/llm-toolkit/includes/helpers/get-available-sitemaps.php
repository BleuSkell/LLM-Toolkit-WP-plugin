<?php

function llm_toolkit_get_available_sitemaps() {
    $site_user = get_option( 'llm_toolkit_site_username' );
    $site_pass = llm_toolkit_decrypt( get_option( 'llm_toolkit_site_password' ) );
    $sitemap_index = home_url('/sitemap.xml');

    $response = wp_remote_get($sitemap_index, [ 'timeout' => 30 ]);

    if (is_wp_error($response)) {
        llm_toolkit_add_status_message('error', 'Sitemap fetch failed: ' . $response->get_error_message());
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body);

    if (!$xml || !isset($xml->sitemap)) {
        return [];
    }

    $sitemaps = [];
    foreach ($xml->sitemap as $sitemap) {
        $loc = (string)$sitemap->loc;
        $sitemaps[] = $loc;
    }

    return $sitemaps;
}