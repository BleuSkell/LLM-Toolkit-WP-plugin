<?php
function llm_toolkit_5_2_pro( $api_key, $api_model, $max_tokens ) {

}

function llm_toolkit_5_2( $api_key, $api_model, $max_tokens ) {

}

function llm_toolkit_5_1( $api_key, $api_model, $max_tokens, $prompt ) {
    llm_toolkit_add_status_message( 'success', 'API model: connected to model' );

    if ( $api_model !== 'gpt-5.1' ) {
        llm_toolkit_add_status_message( 'error', 'API model: invalid API model' );
        return false;
    }

    llm_toolkit_add_status_message( 'success', 'API model: fetched correct model ' . $api_model );

    $body = [
        'model' => $api_model,
        'messages' => [
            [
                'role'      => 'user',
                'content'   => $prompt,
            ]
        ],
        'max_completion_tokens' => (int) $max_tokens,
    ];

    llm_toolkit_add_status_message( 'success', 'API model: correctly built body' );

    return wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'      => wp_json_encode( $body ),
            'timeout'   => 120,
        ]
    );
}

function llm_toolkit_5( $api_key, $api_model, $max_tokens ) {

}

function llm_toolkit_5_mini( $api_key, $api_model, $max_tokens ) {

}

function llm_toolkit_5_nano( $api_key, $api_model, $max_tokens ) {

}

function llm_toolkit_4_1( $api_key, $api_model, $max_tokens ) {

}