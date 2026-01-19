<?php
/**
 * Plugin Name: llm-toolkit
 * Description: Show/generate optimalized html for LLMS by checking the user-agent of the site visitor.
 * Version: 1.3
 * Author: Kyano
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}



/*
 *  bot detection
 */
require_once __DIR__ . '/includes/detection/bot-detection.php';

// add bot detection on plugin activation
register_activation_hook( __FILE__, function() {
    llm_toolkit_sync_detector( true ); // inject detector if plugin enabled
});

// remove bot detection on plugin deactivation
register_deactivation_hook( __FILE__, function() {
    llm_toolkit_sync_detector( false ); // remove detector if plugin disabled
});



/*
 *  initializations
 */
// load styling
function llm_toolkit_styling_callback( $hook ) {
    if ( $hook === 'toplevel_page_llm-toolkit' ) wp_enqueue_style( 'llm-toolkit-page', plugin_dir_url( __FILE__ ) . 'assets/css/settings-page.css' );
    if ( $hook === 'llm-toolkit_page_status-logs' ) wp_enqueue_style( 'llm-toolkit-status-logs-page', plugin_dir_url( __FILE__ ) . 'assets/css/status-logs-page.css' );
    if ( $hook === 'llm-toolkit_page_bot-logs' ) wp_enqueue_style( 'llm-toolkit-bot-logs-page', plugin_dir_url( __FILE__ ) . 'assets/css/bot-logs-page.css' );
}
add_action('admin_enqueue_scripts', 'llm_toolkit_styling_callback');

function llm_toolkit_frontend_styles() {
    wp_enqueue_style( 'llm-toolkit-generate-single-html', plugin_dir_url( __FILE__ ) . 'assets/css/single-llm-html-loader.css' );
}
add_action( 'wp_enqueue_scripts', 'llm_toolkit_frontend_styles' );

// load scripts
function llm_toolkit_scripts_callback( $hook ) {
    if ( $hook === 'toplevel_page_llm-toolkit' ) {
        wp_enqueue_script( 'llm-toolkit-page', plugin_dir_url( __FILE__ ) . 'assets/js/settings-page.js' );
        $script_params = [
            'custom_prompts'    => get_option( 'llm_toolkit_prompts', [] ),
            'prompt_checkboxes' => get_option( 'llm_toolkit_prompt_checkboxes', [] ),
        ];
        wp_localize_script( 'llm-toolkit-page', 'scriptParams', $script_params );
    }
    if ( $hook === 'llm-toolkit_page_status-logs' ) wp_enqueue_script( 'llm-toolkit-status-logs-page', plugin_dir_url( __FILE__ ) . 'assets/js/status-logs-page.js' );
    if ( $hook === 'llm-toolkit_page_bot-logs' ) wp_enqueue_script( 'llm-toolkit-bot-logs-page', plugin_dir_url( __FILE__ ) . 'assets/js/bot-logs-page.js' );
    if ( $hook === 'toplevel_page_llm-toolkit' ) wp_enqueue_script( 'llm-toolkit-draggable-cards', plugin_dir_url( __FILE__ ) . 'assets/js/draggable-cards.js' );
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
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_default_prompt' );
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_prompts', [
        'sanitize_callback' => function($input) {
            if ( is_array( $input ) ) {
                return array_map( 'sanitize_textarea_field', $input );
            }
            return [];
        }
    ]); // custom prompt per sitemap
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_prompt_checkboxes', [
        'sanitize_callback' => function($input) {
            if ( is_array( $input ) ) {
                return array_map( 'sanitize_text_field', $input );
            }
            return [];
        }
    ]); // checkboxes for the custom prompts
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
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_api_key', [
        'sanitize_callback' => function( $input ) {
            return llm_toolkit_encrypt( $input );
        }
    ]);
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_ai_model' );
    register_setting( 'llm_toolkit_settings', 'llm_toolkit_ai_max_tokens' );
});

function llm_toolkit_page() {

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
    $available_sitemaps = llm_toolkit_get_available_sitemaps();
    $selected_sitemaps  = $sitemaps;

    // load configs
    require_once __DIR__ . '/config/config-selected-bots.php';

    // syncs bot detection code with admin settings when saved
    update_option( 'llm_toolkit_enabled', $enabled ); // save in wp_options
    llm_toolkit_sync_detector( (bool) $enabled ); // set llm_toolkit_enabled to true/false and tells the method what to do
    ?>

    <div class="wrap">
        <h1 style="margin-bottom:25px;">LLM Toolkit Settings</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'llm_toolkit_settings' ); ?>
            <?php do_settings_sections( 'llm_toolkit_settings' ); ?>

            <div class="llm-flex">
                <div class="card-flex">

                    <div class="card-container">
                        <div class="card-list">
                            <div class="llm-card" draggable="true">
                                <div style="display: flex;">
                                    <svg version="1.1" id="Draggable--Streamline-Carbon"
                                         xmlns="http://www.w3.org/2000/svg"
                                         xmlns:xlink="http://www.w3.org/1999/xlink" x="0" y="0" viewBox="0 0 16 16"
                                         xml:space="preserve" enable-background="new 0 0 32 32" height="16" width="16">
                                          <desc>
                                              Draggable Streamline Icon: https://streamlinehq.com
                                          </desc>
                                        <title>draggable</title>
                                        <path d="M5 3h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 3h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M5 7h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 7h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M5 11h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 11h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path id="_Transparent_Rectangle_" d="M0 0h16v16H0Z" fill="none"
                                              stroke-width="0.5"></path>
                                    </svg>
                                    <h2 class="title">General Settings</h2>
                                </div>

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
                                    <p class="description">This includes the selected sitemaps into the cron.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-container">
                        <div class="card-list">
                            <div class="llm-card" draggable="true">
                                <div style="display: flex;">
                                    <svg version="1.1" id="Draggable--Streamline-Carbon"
                                         xmlns="http://www.w3.org/2000/svg"
                                         xmlns:xlink="http://www.w3.org/1999/xlink" x="0" y="0" viewBox="0 0 16 16"
                                         xml:space="preserve" enable-background="new 0 0 32 32" height="16" width="16">
                                          <desc>
                                              Draggable Streamline Icon: https://streamlinehq.com
                                          </desc>
                                        <title>draggable</title>
                                        <path d="M5 3h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 3h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M5 7h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 7h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M5 11h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 11h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path id="_Transparent_Rectangle_" d="M0 0h16v16H0Z" fill="none"
                                              stroke-width="0.5"></path>
                                    </svg>
                                    <h2 class="title">Prompts</h2>
                                </div>

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
                                        <label class="llm-label prompt-label" data-target="prompt-<?php echo esc_attr( $sitemap_key ); ?>">
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
                                            <p class="description">Create a custom prompt for this specific sitemap.</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card-container">
                        <div class="card-list">
                            <div class="llm-card" draggable="true">
                                <div style="display: flex;">
                                    <svg version="1.1" id="Draggable--Streamline-Carbon"
                                         xmlns="http://www.w3.org/2000/svg"
                                         xmlns:xlink="http://www.w3.org/1999/xlink" x="0" y="0" viewBox="0 0 16 16"
                                         xml:space="preserve" enable-background="new 0 0 32 32" height="16" width="16">
                                          <desc>
                                              Draggable Streamline Icon: https://streamlinehq.com
                                          </desc>
                                        <title>draggable</title>
                                        <path d="M5 3h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 3h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M5 7h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 7h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M5 11h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path d="M9 11h2v2h-2Z" fill="#000000" stroke-width="0.5"></path>
                                        <path id="_Transparent_Rectangle_" d="M0 0h16v16H0Z" fill="none"
                                              stroke-width="0.5"></path>
                                    </svg>
                                    <h2 class="title">Bots to Detect</h2>
                                </div>

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
                    </div>
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

function bot_logs_page() {
    ?>

    <div class="wrap">
        <h1>LLM Toolkit – Bot Logs</h1>

        <div class="llm-admin-card">
            <h3>Bot Logs</h3>

            <div class="llm-logs-box" id="llm-bot-logs-container">
                <ul id="llm-bot-logs">
                    <?php
                    $logs = llm_toolkit_get_bot_requests_from_access_log( 200 );

                    foreach ( $logs as $log ) {
                        echo "<li class='llm-log-request'>[" . esc_html( $log[ 'time' ] ) . "] " . esc_html( $log[ 'user_agent' ] ) ."</li>";
                    }
                    ?>
                </ul>
            </div>
        </div>
    </div>

    <?php
}



/*
 *   admin pages helper methods
 */
require_once __DIR__ . '/includes/helpers/encryption.php';

require_once __DIR__ . '/includes/helpers/status-logs.php';

require_once __DIR__ . '/includes/helpers/bot-logs.php';

require_once __DIR__ . '/includes/helpers/cron-toggle.php';

require_once __DIR__ . '/includes/helpers/html-single-generation.php';

require_once __DIR__ . '/includes/helpers/get-available-sitemaps.php';



/*
 *   llm html generation methods
 */
require_once __DIR__ . '/includes/html-generation/html-generation.php';



/*
 *   cron job
 */
require_once __DIR__ . '/includes/cron.php';
