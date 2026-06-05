<?php
function llm_toolkit_parse_log_line( string $line ): array|false {
    $pattern = '/^(\S+) - - \[([^\]]+)\] "([^"]*)" (\d+) (\S+) "([^"]*)" "([^"]*)"/';

    $result = preg_match( $pattern, $line, $matches );

    if ( $result === false ) {
        return false;
    }

    if ( $result === 0 ) {
        return false;
    }

    return $matches;
}

