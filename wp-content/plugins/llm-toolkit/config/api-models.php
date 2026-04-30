<?php

function llm_toolkit_5_2( $api_key, $api_model, $max_tokens, $prompt ) {
    if ( $api_model !== 'gpt-5.2' ) {
        llm_toolkit_add_status_message( 'error', 'API model config: invalid API model' );
        return false;
    }

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

    return wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'      => wp_json_encode( $body ),
            'timeout'   => 300,
        ]
    );
}


function llm_toolkit_5_1( $api_key, $api_model, $max_tokens, $prompt ) {
    if ( $api_model !== 'gpt-5.1' ) {
        llm_toolkit_add_status_message( 'error', 'API model config: invalid API model' );
        return false;
    }

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

    return wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'      => wp_json_encode( $body ),
            'timeout'   => 300,
        ]
    );
}

function llm_toolkit_4_1( $api_key, $api_model, $max_tokens, $prompt ) {
    if ( $api_model !== 'gpt-4.1' ) {
        llm_toolkit_add_status_message( 'error', 'API model config: invalid API model' );
        return false;
    }

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

    return wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'      => wp_json_encode( $body ),
            'timeout'   => 300,
        ]
    );
}