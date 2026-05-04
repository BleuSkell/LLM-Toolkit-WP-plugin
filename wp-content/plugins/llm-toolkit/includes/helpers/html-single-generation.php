<?php

// generate single llm html of a page
add_action( 'wp_footer', 'llm_toolkit_generate_single_js' );
function llm_toolkit_generate_single_js() {

    if ( ! is_admin_bar_showing() ) return;

    // get ajax handler
    $ajax_handler_url = admin_url( 'admin-ajax.php' );

    // generte nonce
    $ajax_nonce = wp_create_nonce( 'llm_toolkit_generate_single_html' );

    ?>

    <script>
        // add loading screen overlay
        const screenOverlay = document.createElement('div');
        screenOverlay.className = 'llm-loader-overlay';

        const loader = document.createElement('div');
        loader.className = 'loader';

        screenOverlay.appendChild(loader);
        document.body.appendChild(screenOverlay)

        function showLoader() {
            screenOverlay.style.display = 'flex';
        }

        function hideLoader() {
            screenOverlay.style.display = 'none';
        }

        // JS function called by admin toolbar button
        function ajaxGenerateLLM ( currentPageUrl ) {

            if ( ! confirm('Generate LLMS HTML of this page. IMPORTANT!: Existing file will be overwritten!' ) ) {
                return;
            }

            const ajax_handler_url = '<?php echo esc_url( $ajax_handler_url ); ?>';
            const ajaxNonce = '<?php echo esc_attr( $ajax_nonce ); ?>';

            showLoader();

            jQuery.post(
                ajax_handler_url,
                {
                    action: 'llm_toolkit_generate_single_html', // call function to generate html
                    url: currentPageUrl,
                    nonce: ajaxNonce,
                },
                function( response ) {
                    hideLoader();

                    if ( response.success ) {
                        window.alert( 'Successfully generated LLM HTML for this page' );
                    } else {
                        window.alert( 'Failed to generate LLM HTML for this page, check logs' );
                    }
                }
            );
        }
    </script>

    <?php
}