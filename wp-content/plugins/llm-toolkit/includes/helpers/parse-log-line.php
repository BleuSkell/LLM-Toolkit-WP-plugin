<?php
function llm_toolkit_parse_log_line( string $line ): array|false {
    $pattern = '/^(\s+) - - \[([^\]]+)\] "([^"]*)" \d+ \d+ "([^"]*)" "([^"]*)"/';
    if ( ! preg_match( $pattern, $line, $matches ) ) return false;

    return [
        'ip'        => $matches[1],
        'timestamp' => $matches[2],
        'request'   => $matches[3],
        'status'    => $matches[4],
        'byte_size' => $matches[5],
        'referral'  => $matches[6],
        'ua'        => $matches[7],
    ];
}