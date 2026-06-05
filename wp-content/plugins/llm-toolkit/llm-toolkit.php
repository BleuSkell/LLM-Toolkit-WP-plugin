<?php
/**
 * Plugin Name: llm-toolkit
 * Description: Show/generate optimalized html for LLMS by checking the user-agent of the site visitor.
 * Version: 1.4
 * Author: Kyano
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}



/*
 *  disable lazy loading when this plugin fetches a page (X-LLM-Fetch header present)
 *  this ensures all image src attributes contain real URLs instead of JS placeholders
 */
if ( ! empty( $_SERVER['HTTP_X_LLM_FETCH'] ) ) {
    // disable WordPress core lazy loading
    add_filter( 'wp_lazy_loading_enabled', '__return_false' );
    // disable WP Rocket lazy load
    add_filter( 'do_rocket_lazyload', '__return_false' );
    // disable BJ Lazy Load
    add_filter( 'bj_lazy_load_run_filter', '__return_false' );
    // disable Smush lazy load
    add_filter( 'wp_smush_should_skip_lazy_load', '__return_true' );
}



/*
 *  AI counter
 */
require_once __DIR__ . '/includes/ai-counter/counter-db-installer.php';
require_once __DIR__ . '/includes/ai-counter/counter-cron.php';



/*
 *  bot detection
 */
require_once __DIR__ . '/includes/detection/bot-detection.php';

// add bot detection on plugin activation
register_activation_hook( __FILE__, function() {
    llm_toolkit_add_status_message( 'success', 'LLM Toolkit enabled.' );

    llm_toolkit_sync_detector( true ); // inject detector if plugin enabled
    llm_toolkit_write_selected_bots_file();
    llm_toolkit_add_status_message( 'success', 'Injected bot detection code.' );

    // plan cron when admin toggle is enabled
    if ( get_option( 'llm_toolkit_cron_enabled', false ) == true ) {
        if ( ! wp_next_scheduled( 'llm_generate_batch' ) ) {
            wp_schedule_event( time() + 60, 'every_5_minutes', 'llm_generate_batch' );
        }

        llm_toolkit_add_status_message( 'cron', 'Cron started on ' . current_time( 'Y-m-d H:i:s' ) . ' generating LLM HTML files' );
    }

    // install ai counter database tables
    db_installer_callback();

    // register cron to count bots
    if ( ! wp_next_scheduled( 'llm_count_bots' ) ) {
        wp_schedule_event(
            strtotime( 'tomorrow midnight' ),
            'daily',
            'llm_count_bots'
        );
    }
});

// remove bot detection on plugin deactivation
register_deactivation_hook( __FILE__, function() {
    llm_toolkit_add_status_message( 'success', 'LLM Toolkit disabled' );

    llm_toolkit_sync_detector( false ); // remove detector if plugin disabled
    llm_toolkit_add_status_message( 'success', 'Removed bot detection code.' );

    // unschedule cron
    $timestamp = wp_next_scheduled( 'llm_generate_batch' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'llm_generate_batch' );
        llm_toolkit_add_status_message( 'cron', 'Cron stopped on ' . current_time( 'd-m-Y H:i:s' ) . ' completing last batch before stopping' );
    }

    // unschedule cron to count bots
    $timestamp_count = wp_next_scheduled( 'llm_count_bots' );
    if ( $timestamp_count ) {
        wp_unschedule_event( $timestamp_count, 'llm_count_bots' );
        llm_toolkit_add_status_message( 'cron', 'AI counter cron stopped on ' . current_time( 'd-m-Y H:i:s' ) );
    }
});



/*
 *  initializations
 */
// load styling
function llm_toolkit_styling_callback( $hook ) {
    if ( $hook === 'toplevel_page_llm-toolkit' ) wp_enqueue_style( 'llm-toolkit-page', plugin_dir_url( __FILE__ ) . 'assets/css/bot-visits.css' );
    if ( $hook === 'llm-toolkit_page_status-logs' ) wp_enqueue_style( 'llm-toolkit-status-logs-page', plugin_dir_url( __FILE__ ) . 'assets/css/status-logs-page.css' );
    if ( $hook === 'llm-toolkit_page_bot-requests' ) wp_enqueue_style( 'llm-toolkit-bot-requests-page', plugin_dir_url( __FILE__ ) . 'assets/css/bot-requests-page.css' );
    if ( $hook === 'llm-toolkit_page_settings-page' ) wp_enqueue_style( 'llm-toolkit-settings-page', plugin_dir_url( __FILE__ ) . 'assets/css/settings-page.css' );
}
add_action('admin_enqueue_scripts', 'llm_toolkit_styling_callback');

function llm_toolkit_frontend_styles() {
    wp_enqueue_style( 'llm-toolkit-generate-single-html', plugin_dir_url( __FILE__ ) . 'assets/css/single-llm-html-loader.css' );
}
add_action( 'wp_enqueue_scripts', 'llm_toolkit_frontend_styles' );

// load scripts
function llm_toolkit_scripts_callback( $hook ) {
    if ( $hook === 'toplevel_page_llm-toolkit' ) {
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true );
        wp_enqueue_script( 'llm-toolkit-page', plugin_dir_url( __FILE__ ) . 'assets/js/bot-visits-page.js', ['chartjs'], null, true );
    }
    if ( $hook === 'llm-toolkit_page_status-logs' ) wp_enqueue_script( 'llm-toolkit-status-logs-page', plugin_dir_url( __FILE__ ) . 'assets/js/status-logs-page.js' );
    if ( $hook === 'llm-toolkit_page_bot-requests' ) wp_enqueue_script( 'llm-toolkit-bot-requests-page', plugin_dir_url( __FILE__ ) . 'assets/js/bot-requests-page.js' );
    if ( $hook === 'llm-toolkit_page_settings-page' ) {
        wp_enqueue_script( 'llm-toolkit-settings-page', plugin_dir_url( __FILE__ ) . 'assets/js/settings-page.js' );
        $script_params = [
                'custom_prompts'    => get_option( 'llm_toolkit_prompts', [] ),
                'prompt_checkboxes' => get_option( 'llm_toolkit_prompt_checkboxes', [] ),
        ];
        wp_localize_script( 'llm-toolkit-settings-page', 'scriptParams', $script_params );
    }
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
            'onclick'   => 'ajaxGenerateLLM(' . wp_json_encode( $current_url ) . ')', // call ajax handler
        ]
    ];

    // add the button with arguments
    $wp_admin_bar->add_node( $args );
}
add_action( 'admin_bar_menu', 'manual_llm_html_generation', 100 );



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
        'Bot Requests',
        'Bot Requests',
        'manage_options',
        'bot-requests',
        'bot_requests_page'
    );
    add_submenu_page(
        'llm-toolkit',
        'Settings',
        'Settings',
        'manage_options',
        'settings-page',
        'settings_page'
    );
}
add_action( 'admin_menu', 'llm_toolkit_menu' );

// register plugin settings
add_action( 'admin_init', function() {
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_enabled', [ 'sanitize_callback' => 'absint' ] ); // plugin turn on/off
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_cron_enabled', [ 'sanitize_callback' => 'absint' ] ); // cron turn on/off
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_allowed_bots', [
        'sanitize_callback' => function($input) {
            if ( is_string( $input ) && str_contains( $input, '|||' ) ) {
                return array_filter( array_map( 'trim', explode( '|||', $input ) ) );
            }
            if ( is_string( $input ) ) {
                return [ sanitize_text_field( $input ) ];
            }
            // fallback
            return [];
        }
    ]); // bot selection
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_default_prompt', [ 'sanitize_callback' => 'sanitize_textarea_field' ] ); // default prompt for all sitemaps
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_prompts', [
        'sanitize_callback' => function($input) {
            if ( is_array( $input ) ) {
                return array_map( 'sanitize_textarea_field', $input );
            }
            // fallback
            return [];
        }
    ]); // custom prompt per sitemap
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_prompt_checkboxes', [
        'sanitize_callback' => function($input) {
            if ( is_array( $input ) ) {
                return array_map( 'sanitize_text_field', $input );
            }
            // fallback
            return [];
        }
    ]); // checkboxes for the custom prompts
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_sitemap_index', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_selected_sitemaps', [
        'sanitize_callback' => function( $input ) {
            if ( is_array( $input ) ) {
                return array_map( 'esc_url_raw', $input );
            }
            // fallback
            return [];
        }
    ]); // sitemap selection
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_bot_detection' ); // choose detection method
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_api_key', [
        'sanitize_callback' => function( $input ) {
            return llm_toolkit_encrypt( $input );
        }
    ]); // openai api key
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_ai_model', [ 'sanitize_callback' => 'sanitize_text_field' ] ); // ai model
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_ai_max_tokens', [ 'sanitize_callback' => 'absint' ] ); // max tokens
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_enable_site_user_credentials', [ 'sanitize_callback' => 'absint' ] ); // enable/disable website user credentials for htaccess protections
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_site_user', [ 'sanitize_callback' => 'sanitize_text_field' ] ); // website username for htaccess protections
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_site_pass', [
        'sanitize_callback' => function( $input ) {
            // if user credentials turned off empty password
            if ( empty( get_option( 'llm_toolkit_enable_site_user_credentials' ) ) ) {
                return '';
            }

            // if credentials turned on but input left empty use old password
            if ( empty( $input ) ) {
                return get_option( 'llm_toolkit_site_pass', '' );
            }

            // set new password
            return llm_toolkit_encrypt( $input );
        }
    ]); // website password for htaccess protections
});

// syncs bot detection code with admin settings when saved
add_action( 'update_option_llm_toolkit_enabled', function( $old, $new ) {
    llm_toolkit_sync_detector( (bool) $new );
}, 10, 2);

function llm_toolkit_page() {
    ?>

    <div class="wrap">
        <h1>LLM Toolkit Dashboard</h1>

        <div class="llm-card">

            <canvas id="llm-bot-chart" height="50"></canvas>

            <div class="llm-visits-table-container">
                <table
                        id="llm-bot-visits-table"
                        class="llm-visits-table"
                ></table>
            </div>

            <div style="display: flex; align-items: center; gap: 5px;">
                <input type="checkbox" id="view-per-month"/>
                <label for="view-per-month">View per month</label>
            </div>
        </div>
    </div>

    <?php
}

function status_logs_page() {
    ?>

    <div class="wrap">
        <h1 style="margin-bottom:25px;">LLM Toolkit — Status Logs</h1>

        <div class="llm-status-card">

            <h2 class="llm-subtitle">Generation Summary</h2>
            <p class="llm-summary" id="llm-summary-text"></p>

            <h2 class="llm-subtitle" style="margin-top:25px;">Live Activity Log</h2>

            <div id="llm-status-list-container" class="llm-log-box">
                <ul id="llm-status-list" class="llm-log-list">
                    <?php
                    $logs = llm_toolkit_get_file_logs( 100 );

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

function bot_requests_page() {
    ?>

    <div class="wrap">
        <h1>LLM Toolkit – Bot Requests</h1>

        <div class="llm-admin-card">
            <h3>Bot Requests</h3>

            <div class="llm-requests-box" id="llm-bot-requests-container">
                <ul id="llm-bot-requests"></ul>
            </div>
        </div>
    </div>

    <?php
}

function settings_page() {
    // get settings from database
    $enabled            = get_option( 'llm_toolkit_enabled', '1' );
    $cron_enabled       = get_option( 'llm_toolkit_cron_enabled', '0' );
    $allowed_bots       = get_option( 'llm_toolkit_allowed_bots', [] );
    $sitemaps           = get_option( 'llm_toolkit_selected_sitemaps', [] );
    $bot_detection      = get_option( 'llm_toolkit_bot_detection', 'plugin' );

    // prompts
    $default_prompt     = get_option( 'llm_toolkit_default_prompt' );
    $prompts            = get_option( 'llm_toolkit_prompts', [] );
    $prompt_checkboxes  = get_option( 'llm_toolkit_prompt_checkboxes', [] );

    $api_key            = llm_toolkit_decrypt( get_option( 'llm_toolkit_api_key' ) );
    $model              = get_option( 'llm_toolkit_ai_model' );
    $max_tokens         = get_option( 'llm_toolkit_ai_max_tokens' );

    // get sitemaps
    $sitemap_index      = get_option( 'llm_toolkit_sitemap_index' );
    $available_sitemaps = llm_toolkit_get_available_sitemaps();
    $selected_sitemaps  = $sitemaps;

    // get website login credentials
    $enable_site_user   = get_option( 'llm_toolkit_enable_site_user_credentials', '0' );
    $site_user          = get_option( 'llm_toolkit_site_user', '' );
    $site_pass          = get_option( 'llm_toolkit_site_pass', '' );
    $placeholder        = ! empty( $site_pass ) ? "(Password set)" : "Enter a password";
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
                            <label class="llm-label">Website user configuration</label>
                            <p class="description">Enable user credentials</p>
                            <input type="checkbox" name="llm_toolkit_enable_site_user_credentials" id="enable_credentials" value="1" <?php checked( $enable_site_user, '1' ); ?> />
                            <div class="site_credentials" style="<?php echo ( $enable_site_user === '1' ) ? 'display:flex; flex-direction:column;' : 'display:none;'; ?>">
                                <p class="description">Username</p>
                                <input type="text" name="llm_toolkit_site_user" value="<?php echo esc_attr( $site_user ); ?>" />
                                <p class="description">Password</p>
                                <input type="password" name="llm_toolkit_site_pass" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
                            </div>
                        </div>

                        <div class="llm-field">
                            <label class="llm-label">AI configuration</label>
                            <p class="description">API key</p>
                            <input type="text" name="llm_toolkit_api_key" id="llm_toolkit_api_key" placeholder="API key" value="<?php echo esc_attr( $api_key ); ?>" required />

                            <p class="description">AI model</p>
                            <select name="llm_toolkit_ai_model" id="llm_toolkit_ai_model">
                                <option value="<?php echo esc_attr( $model ); ?>"><?php echo esc_attr( $model ); ?></option>
                                <option value="gpt-5.2">gpt-5.2</option>
                                <option value="gpt-5.1">gpt-5.1</option>
                                <option value="gpt-4.1">gpt-4.1</option>
                            </select>

                            <p class="description">Maximum amount of tokens</p>
                            <input type="number" name="llm_toolkit_ai_max_tokens" id="llm_toolkit_ai_max_tokens" placeholder="Max tokens" value="<?php echo esc_attr( $max_tokens ); ?>" required />
                        </div>

                        <div class="llm-field">
                            <label class="llm-label">Set sitemap index</label>

                            <input type="text" placeholder="sitemap_index.xml" name="llm_toolkit_sitemap_index" value="<?php echo esc_attr( $sitemap_index ); ?>" />
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
                                <p>-- No sitemap index xml found or child sitemaps of <?php echo esc_attr( $sitemap_index ) ?> detected --</p>
                                <?php llm_toolkit_add_status_message( 'error', 'No sitemap index xml found or child sitemaps of ' . $sitemap_index . ' detected' ); ?>
                            <?php endif; ?>
                            <p class="description">This includes the selected sitemaps into the cron.</p>
                        </div>
                    </div>

                    <!-- middle settings card -->
                    <div class="llm-card">
                        <h2 class="title">Prompts</h2>

                        <div class="llm-field" >
                            <label class="llm-label">Default prompt</label>
                            <textarea name="llm_toolkit_default_prompt" rows="8" ><?php echo esc_attr( $default_prompt ); ?></textarea>
                            <p class="description">Customize the default prompt used for all the sitemaps.</p>
                        </div>

                        <?php foreach ( $available_sitemaps as $sitemap ) : ?>
                            <?php
                            $sitemap_key    = md5( $sitemap );
                            $is_custom      = ! empty( $prompt_checkboxes[ $sitemap_key ] );
                            $is_enabled     = in_array( $sitemap, $selected_sitemaps );
                            $prompt_value   = $prompts[ $sitemap_key ] ?? '';
                            ?>
                            <div
                                    class="llm-field prompt-block"
                                    data-sitemap="<?php echo esc_attr( $sitemap ); ?>"
                                    style="<?php echo $is_enabled ? '' : 'display:none;'; ?>"
                            >
                                <label
                                        class="llm-label prompt-label"
                                        data-prompt="prompt-<?php echo esc_attr( $sitemap_key ); ?>"
                                >
                                    <?php echo esc_html( basename( $sitemap ) ); ?>
                                    <span class="chevron"></span>
                                </label>
                                <textarea
                                        name="llm_toolkit_prompts[<?php echo esc_attr( $sitemap_key ); ?>]"
                                        id="prompt-<?php echo esc_attr( $sitemap_key ); ?>"
                                        rows="8"
                                        placeholder="Enter instructions for the LLM generator"
                                    <?php echo $is_custom ? '' : 'style="display: none;"'; ?>
                                ><?php echo esc_textarea( $prompt_value ); ?></textarea>

                                <div>
                                    <input type="checkbox"
                                           class="llm-prompt-checkbox"
                                           name="llm_toolkit_prompt_checkboxes[<?php echo esc_attr( $sitemap_key ); ?>]"
                                           data-target="prompt-<?php echo esc_attr( $sitemap_key ); ?>"
                                            <?php checked( ! empty( $prompt_checkboxes[ $sitemap_key ] ) ); ?>
                                    />
                                    <p class="description">Create a custom prompt for this specific sitemap (Optional).</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
                                            <button
                                                    type="button"
                                                    class="button button-small button-link-delete remove-bot"
                                                    style="display: flex; align-items: center;"
                                            >
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



/*
 *   admin pages helper methods
 */
require_once __DIR__ . '/includes/helpers/encryption.php';

require_once __DIR__ . '/includes/helpers/status-logs.php';

require_once __DIR__ . '/includes/helpers/bot-requests.php';

require_once __DIR__ . '/includes/ai-counter/counter-ajax.php';

require_once __DIR__ . '/includes/helpers/cron-toggle.php';

require_once __DIR__ . '/includes/helpers/html-single-generation.php';

require_once __DIR__ . '/includes/helpers/get-available-sitemaps.php';



/*
 *   llm html generation methods
 */
require_once __DIR__ . '/includes/html-generation/html-generation.php';

// get sitemaps
require_once __DIR__ . '/includes/helpers/get-available-sitemaps.php';

// load config files
require_once __DIR__ . '/config/config-selected-bots.php';



/*
 *   cron job
 */
require_once __DIR__ . '/includes/cron.php';
