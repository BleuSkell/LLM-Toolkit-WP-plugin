<?php
function llm_toolkit_encrypt( $value ) {
    $key    = hash_hmac( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, 'secret' );
    $iv     = substr( hash_hmac( 'sha256', LOGGED_IN_KEY . NONCE_SALT, 'secret' ), 0, 64 );

    return base64_encode( openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv ) );
}

function llm_toolkit_decrypt( $cipher ) {
    $key    = hash_hmac( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, 'secret' );
    $iv     = substr( hash_hmac( 'sha256', LOGGED_IN_KEY . NONCE_SALT, 'secret' ), 0, 64 );

    return openssl_decrypt( base64_decode( $cipher ), 'AES-256-CBC', $key, 0, $iv );
}