<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code, $message, $data = [] ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }

    public function get_error_messages() {
        return [ $this->message ];
    }
}

class EIM_Svg_Test_Filesystem {
    public $method = 'direct';

    public function get_contents( $path ) {
        return file_get_contents( $path );
    }

    public function put_contents( $path, $contents, $mode = false ) {
        return false !== file_put_contents( $path, $contents );
    }
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function __( $message, $domain = null ) {
    return $message;
}

function apply_filters( $hook, $value ) {
    return $value;
}

function do_action( $hook ) {
}

function wp_json_encode( $value, $flags = 0 ) {
    return json_encode( $value, $flags );
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', (string) $path );
}

function eim_svg_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-filesystem.php';
require_once dirname( __DIR__ ) . '/includes/class-svg-import-validator.php';

$fixture = tempnam( sys_get_temp_dir(), 'eim-svg-' );
$dirty   = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)"><script>alert(1)</script><a href="https://attacker.example/track"><rect width="10" height="10" fill="red" /></a><use xlink:href="#local" /></svg>';
file_put_contents( $fixture, $dirty );

try {
    $filesystem = new EIM_Filesystem( new EIM_Svg_Test_Filesystem() );
    $validator  = new EIM_Svg_Import_Validator( $filesystem );
    $clean     = $validator->validate_safe_svg_file( $fixture );

    eim_svg_assert( is_string( $clean ), 'Unsafe-but-parseable SVG should return sanitized markup.' );
    eim_svg_assert( false === stripos( $clean, '<script' ), 'Script elements should be removed.' );
    eim_svg_assert( false === stripos( $clean, 'onload=' ), 'Event attributes should be removed.' );
    eim_svg_assert( false === stripos( $clean, 'attacker.example' ), 'Remote references should be removed.' );
    eim_svg_assert( false !== strpos( $clean, '#local' ), 'Local fragment references should remain available.' );

    file_put_contents( $fixture, '<svg xmlns="http://www.w3.org/2000/svg" &#111;nload="alert(1)"></svg>' );
    $invalid = $validator->validate_safe_svg_file( $fixture );
    eim_svg_assert( is_wp_error( $invalid ), 'Malformed entity-obfuscated attribute names should fail closed.' );

    echo "SVG validator tests passed.\n";
} finally {
    unlink( $fixture );
}
