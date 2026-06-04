<?php
function llm_toolkit_write_to_db( array $tally ): void {
    global $wpdb;
    $table      = $wpdb->prefix . "ai_counts";
    $timestamp  = current_time( 'mysql' );

    foreach ( $tally as $category => $user_agents ) {
        foreach ( $user_agents as $ua => $referrals ) {
            foreach ( $referrals as $referral => $hits ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "
                            INSERT INTO {$table}
                            (
                                UA,
                                referral,
                                category,
                                hits,
                                timestamp
                            )
                            VALUES
                            (
                                %s,
                                %s,
                                %s,
                                %d,
                                %s
                            )
                            ON DUPLICATE KEY UPDATE
                                hits = hits + VALUES(hits),
                                timestamp = VALUES(timestamp)
                            ",
                        $ua,
                        $referral,
                        $category,
                        $hits,
                        $timestamp
                    )
                );
            }
        }
    }
}