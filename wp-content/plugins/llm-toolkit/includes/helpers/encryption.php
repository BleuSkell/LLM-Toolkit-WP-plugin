<?php
function llm_toolkit_encrypt( $value ) {
    $key        = hash_hmac( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, 'secret', true );
    $iv         = openssl_random_pseudo_bytes( 16 );
    $ciphertext = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

    return base64_encode( $iv . $ciphertext );
}

function llm_toolkit_decrypt( $cipher ) {
    if ( empty( $cipher ) ) return '';
    $key    = hash_hmac( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, 'secret', true );
    $data   = base64_decode( $cipher );

    if ( $data === false ) {
        llm_toolkit_add_status_message( 'error', 'Error decrypting the data' );
        return '';
    }

    $iv     = substr( $data, 0, 16 );
    $ct     = substr( $data, 16 );

    $result = openssl_decrypt( $ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

    if ( $result === false ) {
        llm_toolkit_add_status_message( 'error', 'Error decrypting the data, openssl error string: ' . openssl_error_string() );
        return '';
    }

    return $result;
}