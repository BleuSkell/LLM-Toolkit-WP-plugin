<?php
function llm_toolkit_write_to_db( array $tally ): void {
    global $wpdb;
    $table      = $wpdb->prefix . "ai_counts";
    $timestamp  = current_time( 'd-m-Y H:i:s' );

    foreach ( $tally as $ua => $categories ) {
        foreach ( $categories as $category => $hits ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $table (UA, referral, page_req, category, hits, timestamp)
                VALUES (%s, %s, %s, %s, %d, %s)
                ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)",
                $ua, $category, $hits, $timestamp
            ));
        }
    }
}