<?php
if (!defined('ABSPATH')) exit;

function db_installer_callback(): void {
    llm_toolkit_add_status_message('success', 'AI counter: Installing counter database tables');
    global $wpdb;
    $table_name = $wpdb->prefix . "ai_counts";

    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table_name (
            ID INT NOT NULL AUTO_INCREMENT,
            UA VARCHAR(255) NOT NULL,
            referral VARCHAR(500) NULL DEFAULT NULL,
            category ENUM('crawler', 'answer_engine', 'trad_search_crawler', 'ai_referrals') NOT NULL,
            hits INT NOT NULL DEFAULT 1,
            timestamp DATE NOT NULL,
            UNIQUE KEY daily_unique (UA, referral, category, timestamp),
        PRIMARY KEY (ID));
    ");
}