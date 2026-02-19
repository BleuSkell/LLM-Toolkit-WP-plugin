<?php

function llm_toolkit_get_available_sitemaps()
{
    $sitemap_index = home_url('/sitemap.xml');

    $response = wp_remote_get($sitemap_index, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('ontwikkel:Staging123')
        ]
    ]);

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