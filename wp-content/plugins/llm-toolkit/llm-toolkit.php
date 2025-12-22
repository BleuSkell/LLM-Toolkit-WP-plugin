<?php
/**
 * Plugin Name: llm-toolkit
 * Description: Show/generate optimalized html for LLMS by checking the user-agent of the site visitor.
 * Version: 1.2
 * Author: Kyano
 */

/*
 *  initializations
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/bot-detection.php';

// add bot detection on plugin activation
register_activation_hook( __FILE__, function() {
    llm_toolkit_sync_detector( true ); // inject detector if plugin enabled
});

// remove bot detection on plugin deactivation
register_deactivation_hook( __FILE__, function() {
    llm_toolkit_sync_detector( false ); // remove detector if plugin disabled
});

// load styling
function llm_toolkit_styling_callback( $hook ) {
    if ( $hook === 'toplevel_page_llm-toolkit' ) wp_enqueue_style( 'llm-toolkit-page', plugin_dir_url( __FILE__ ) . 'assets/css/settings-page.css' );
    if ( $hook === 'llm-toolkit_page_status-logs' ) wp_enqueue_style( 'llm-toolkit-status-logs-page', plugin_dir_url( __FILE__ ) . 'assets/css/status-logs-page.css' );
    if ( $hook === 'llm-toolkit_page_bot-logs' ) wp_enqueue_style( 'llm-toolkit-bot-logs-page', plugin_dir_url( __FILE__ ) . 'assets/css/bot-logs-page.css' );
}
add_action('admin_enqueue_scripts', 'llm_toolkit_styling_callback');

// load scripts
function llm_toolkit_scripts_callback( $hook ) {
    if ( $hook === 'toplevel_page_llm-toolkit' ) wp_enqueue_script( 'llm-toolkit-page', plugin_dir_url( __FILE__ ) . 'assets/js/settings-page.js' );
    if ( $hook === 'llm-toolkit_page_status-logs' ) wp_enqueue_script( 'llm-toolkit-status-logs-page', plugin_dir_url( __FILE__ ) . 'assets/js/status-logs-page.js' );
    if ( $hook === 'llm-toolkit_page_bot-logs' ) wp_enqueue_script( 'llm-toolkit-bot-logs-page', plugin_dir_url( __FILE__ ) . 'assets/js/bot-logs-page.js' );
}
add_action( 'admin_enqueue_scripts', 'llm_toolkit_scripts_callback' );

// add custom admin toolbar button
function manual_llm_html_generation( $wp_admin_bar ) {

    if ( ! is_admin_bar_showing() ) return; // only load if toolbar is showing
    if ( is_admin() ) return; // exclude from wp-admin pages

    $current_url = home_url( add_query_arg( [], $_SERVER[ 'REQUEST_URI' ] ) );

    $args = [
        'id'    => 'manual_llm_html_generation',
        'title' => 'Generate LLM HTML',
        'href'  => '#',
        'meta'  => [
            'class'     => 'llm-html-generation-button',
            'title'     => 'Generate LLM HTML for this page',
            'onclick'   => "ajaxGenerateLLM('$current_url')", // call ajax handler in settings-page.js
        ]
    ];

    // add the button with arguments
    $wp_admin_bar->add_node( $args );
}
add_action( 'admin_bar_menu', 'manual_llm_html_generation', 100 );

// add ajax handler
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_admin_bar_showing() || ! is_user_logged_in() ) {
        return;
    }

    wp_enqueue_script(
        'llm-toolkit-frontend',
        plugin_dir_url( __FILE__ ) . 'assets/js/settings-page.js',
        ['jquery'],
        null,
        true
    );

    wp_localize_script(
        'llm-toolkit-frontend',
        'llmToolkit',
        [
            'ajaxHandlerUrl' => admin_url( 'admin-ajax.php' ) // set ajax handler url to be used in ajaxGenerateLLM
        ]
    );
});



/*
 *   admin pages
 */
// add the admin menu
function llm_toolkit_menu() {
    add_menu_page(
        'LLM Toolkit',
        'LLM Toolkit',
        'manage_options',
        'llm-toolkit',
        'llm_toolkit_page',
        'dashicons-code-standards',
        20
    );
    add_submenu_page(
        'llm-toolkit',
        'Status Logs',
        'Status Logs',
        'manage_options',
        'status-logs',
        'status_logs_page'
    );
    add_submenu_page(
        'llm-toolkit',
        'Bot Logs',
        'Bot Logs',
        'manage_options',
        'bot-logs',
        'bot_logs_page'
    );
}
add_action( 'admin_menu', 'llm_toolkit_menu' );

// register plugin settings
add_action( 'admin_init', function() {
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_enabled' ); // plugin turn on/off
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_cron_enabled' ); // cron turn on/off
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_allowed_bots', [
        'sanitize_callback' => function($input) {
            if ( is_string( $input ) && str_contains( $input, '|||' ) ) {
                return array_filter( array_map( 'trim', explode( '|||', $input ) ) );
            }
            // fallback
            return [];
        }
    ]); // bot selection
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_prompt' ); // custom prompt
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_selected_sitemaps', [
        'sanitize_callback' => function( $input ) {
            if ( is_array( $input ) ) {
                return array_map( 'sanitize_text_field', $input );
            }
            // fallback
            return [];
        }
    ]); // sitemap selection
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_bot_detection' ); // choose detection method
});

function llm_toolkit_page() {

    // get settings from database
    $enabled            = get_option( 'llm_toolkit_enabled', '1' );
    $cron_enabled       = get_option( 'llm_toolkit_cron_enabled', '0' );
    $allowed_bots       = get_option( 'llm_toolkit_allowed_bots', [] );
    $prompt             = get_option( 'llm_toolkit_prompt' );
    $sitemaps           = get_option( 'llm_toolkit_selected_sitemaps', [] );
    $bot_detection      = get_option( 'llm_toolkit_bot_detection', 'plugin' );

    // get sitemaps
    $available_sitemaps = llm_toolkit_get_available_sitemaps();
    $selected_sitemaps  = $sitemaps;

    // load configs
    require_once __DIR__ . '/config/config-selected-bots.php';

    // syncs bot detection code with admin settings when saved
    update_option( 'llm_toolkit_enabled', $enabled ); // save in wp_options so wp remembers
    llm_toolkit_sync_detector( (bool) $enabled ); // set llm_toolkit_enabled to true/false and tells the method what to do
    ?>

    <div class="wrap">
        <h1 style="margin-bottom:25px;">LLM Toolkit Settings</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'llm_toolkit_settings' ); ?>
            <?php do_settings_sections( 'llm_toolkit_settings' ); ?>

            <div class="llm-flex">

                <!-- left settings card -->
                <div class="llm-card">
                    <h2 class="title">General Settings</h2>

                    <div class="llm-field">
                        <label class="llm-label">Enable plugin</label>
                        <input type="checkbox" name="llm_toolkit_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
                    </div>

                    <div class="llm-field">
                        <label class="llm-label">Enable cron job</label>
                        <input type="checkbox" name="llm_toolkit_cron_enabled" value="1" <?php checked( $cron_enabled, '1' ); ?> />
                        <p class="description">Runs the LLM HTML generator periodically.</p>
                    </div>

                    <div class="llm-field">
                        <label class="llm-label">Select which sitemaps to read</label>

                        <?php if ( ! empty( $available_sitemaps ) ) : ?>
                            <?php foreach ( $available_sitemaps as $sitemap ) : ?>
                                <?php $checked = in_array( $sitemap, (array) $selected_sitemaps ) ? 'checked' : ''; ?>
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="llm_toolkit_selected_sitemaps[]" value="<?php echo esc_attr( $sitemap ); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html( basename( $sitemap ) ); ?>
                                </label>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <?php llm_toolkit_add_status_message( 'error', 'No sitemap_index.xml found or child sitemaps of sitemap_index.xml detected' ); ?>
                        <?php endif; ?>
                        <p class="description">This tells the cron which sitemaps to generate llm html for.</p>
                    </div>

                    <div class="llm-field">
                        <label class="llm-label">Prompt</label>
                        <textarea name="llm_toolkit_prompt" rows="8" placeholder="Enter instructions for the LLM generator"><?php echo esc_textarea( $prompt ); ?></textarea>
                        <p class="description">This prompt controls how the LLM generates optimized HTML.</p>
                    </div>
                </div>

                <!-- right settings card -->
                <div class="llm-card">
                    <h2 class="title">Bots to Detect</h2>

                    <div class="llm-bot-add">
                        <input type="text" id="bot-input" placeholder="Bot name or keyword">
                        <button type="button" id="add-bot" class="button button-secondary">Add</button>
                    </div>

                    <p class="description" style="margin-bottom: 15px;">Used to detect bots and serve optimized HTML.</p>

                    <table class="widefat fixed striped llm-table" id="bot-list-table">
                        <thead>
                        <tr>
                            <th>Bot name</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                        </thead>
                        <tbody id="llm-bot-body">
                        <?php if ( ! empty( $allowed_bots ) ) : ?>
                            <?php foreach ( $allowed_bots as $bot ) : ?>
                                <tr class="bot-row">
                                    <td><?php echo esc_html( $bot ); ?></td>
                                    <td>
                                        <button type="button" class="button button-small button-link-delete remove-bot">
                                            <span class="dashicons dashicons-trash"></span> Remove
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <input type="hidden" name="llm_toolkit_allowed_bots" id="bots-hidden">
                </div>
            </div>

            <p style="margin-top:20px;"><?php submit_button(); ?></p>
        </form>
    </div>

    <?php
}

function status_logs_page() {
    ?>

    <div class="wrap">
        <h1 style="margin-bottom:25px;">LLM Toolkit — Status Logs</h1>

        <div class="llm-status-card">

            <h2 class="llm-subtitle">Generation Summary</h2>
            <p class="llm-summary" id="llm-summary-text">
                <strong><?php echo esc_attr( $generated_files ); ?></strong> of <strong><?php echo esc_attr( $total_pages ); ?></strong> pages currently have LLMS HTML.
            </p>

            <h2 class="llm-subtitle" style="margin-top:25px;">Live Activity Log</h2>

            <div id="llm-status-list-container" class="llm-log-box">
                <ul id="llm-status-list" class="llm-log-list">
                    <?php
                    $logs = get_option( 'llm_toolkit_status_logs', [] );

                    foreach ( $logs as $log ) {
                        if ( $log[ 'type' ] === 'bot_request_logs' ) continue;

                        $color = "#000";
                        $label = "Info";

                        if ( $log[ 'type' ] === 'cron' ) {
                            $color = "#2271b1";
                            $label = "Cron";
                        }
                        if ( $log[ 'type' ] === 'success' ) {
                            $color = "#049723";
                            $label = "Success";
                        }
                        if ( $log[ 'type' ] === 'error' ) {
                            $color = "#c60000";
                            $label = "Error";
                        }

                        echo "
                                <li class='llm-log-item'>
                                    <span class='llm-badge' style='background:$color;'>$label</span>
                                    <span class='llm-log-text'>[" . esc_html( $log[ 'time' ] ) . "] " . esc_html( $log[ 'message' ] ) . "</span>
                                </li>
                            ";
                    }
                    ?>
                </ul>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="llm-clear-form">
                <input type="hidden" name="action" value="llm_toolkit_clear_status" />
                <?php wp_nonce_field( 'llm_toolkit_clear_status_nonce' ); ?>
                <input type="submit" class="button button-secondary" value="Clear Status" />
            </form>
        </div>
    </div>

    <?php
}

function bot_logs_page() {
    ?>

    <div class="wrap">
        <h1>LLM Toolkit – Bot Logs</h1>

        <div class="llm-admin-card">
            <h3>Bot Logs</h3>

            <div class="llm-logs-box" id="llm-bot-logs-container">
                <ul id="llm-bot-logs">
                    <?php
                    $logs = get_option( 'llm_toolkit_bot_logs', [] );

                    foreach ( $logs as $log ) {
                        $class = 'llm-log-default';
                        if ( $log[ 'type' ] === 'bot_request_logs' ) {
                            $class = 'llm-log-request';
                        }

                        echo "<li class='$class'>[" . esc_html( $log[ 'time' ] ) . "] " . esc_html( $log[ 'message' ] ) . "</li>";
                    }
                    ?>
                </ul>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="llm-clear-form">
                <input type="hidden" name="action" value="llm_toolkit_clear_bot_logs" />
                <?php wp_nonce_field( 'llm_toolkit_clear_bot_logs_nonce' ); ?>
                <input type="submit" class="button button-secondary" value="Clear Bot Logs" />
            </form>
        </div>
    </div>

    <?php
}



/*
 *   admin pages helper methods
 */
require_once __DIR__ . '/includes/helpers/status-logs.php';

require_once __DIR__ . '/includes/helpers/bot-logs.php';

require_once __DIR__ . '/includes/helpers/cron-toggle.php';

require_once __DIR__ . '/includes/helpers/html-single-generation.php';

require_once __DIR__ . '/includes/helpers/get-available-sitemaps.php';



/*
 *   llm html generation methods
 */
require_once __DIR__ . '/includes/html-generation.php';



/*
 *   cron job
 */
require_once __DIR__ . '/includes/cron.php';
