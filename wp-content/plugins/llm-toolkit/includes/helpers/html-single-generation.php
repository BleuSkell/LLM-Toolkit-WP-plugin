<?php

// generate single llm html of a page
add_action( 'wp_footer', 'llm_toolkit_generate_single_js' );
function llm_toolkit_generate_single_js() {

    if ( ! is_admin_bar_showing() ) return;

    // get ajax handler
    $ajax_handler_url = admin_url( 'admin-ajax.php' );

    ?>

    <script>

        // JS function called by admin toolbar button
        function ajaxGenerateLLM ( currentPageUrl ) {

            if ( ! confirm('Generate LLMS HTML of this page. IMPORTANT!: Existing file will be overwritten!' ) ) {
                return;
            }

            const ajax_handler_url = '<?php echo esc_url( $ajax_handler_url ); ?>';

            jQuery.post(
                ajax_handler_url,
                {
                    action: 'llm_toolkit_generate_single_html', // call function to generate html
                    url: currentPageUrl,
                },
                function( response ) {
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