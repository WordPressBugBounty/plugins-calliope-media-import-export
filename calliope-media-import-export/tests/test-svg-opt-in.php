<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

class WP_Error {
    private $code;
    private $message;

    public function __construct( $code, $message ) {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

class EIM_Svg_Optin_Test_Filesystem {
    public $method = 'direct';

    public function get_contents( $path ) {
        return file_get_contents( $path );
    }

    public function put_contents( $path, $contents, $mode = false ) {
        return false !== file_put_contents( $path, $contents );
    }
}

$GLOBALS['eim_svg_filter_override'] = null;

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function __( $message, $domain = null ) {
    return $message;
}

function apply_filters( $hook, $value ) {
    if ( 'eim_allow_svg_imports' === $hook && null !== $GLOBALS['eim_svg_filter_override'] ) {
        return (bool) $GLOBALS['eim_svg_filter_override'];
    }

    return $value;
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', (string) $path );
}

function eim_svg_optin_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-filesystem.php';
require_once dirname( __DIR__ ) . '/includes/class-svg-import-validator.php';

$fixture = tempnam( sys_get_temp_dir(), 'eim-svg-optin-' );
file_put_contents( $fixture, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" /></svg>' );

try {
    $filesystem = new EIM_Filesystem( new EIM_Svg_Optin_Test_Filesystem() );
    $validator = new EIM_Svg_Import_Validator( $filesystem );

    eim_svg_optin_assert( false === $validator->allows_svg_imports(), 'SVG imports must be disabled by default.' );

    $disabled = $validator->maybe_validate_svg_import_file( $fixture, 'sample.svg' );
    eim_svg_optin_assert( is_wp_error( $disabled ), 'SVG import must be rejected when opt-in is off.' );
    eim_svg_optin_assert( 'eim_svg_import_disabled' === $disabled->get_error_code(), 'Disabled SVG import must use the expected error code.' );

    $validator->set_svg_imports_allowed( true );
    eim_svg_optin_assert( true === $validator->allows_svg_imports(), 'Runtime opt-in must enable SVG validation.' );

    $validator->set_svg_imports_allowed( false );
    $GLOBALS['eim_svg_filter_override'] = true;
    eim_svg_optin_assert( true === $validator->allows_svg_imports(), 'Existing eim_allow_svg_imports filter must still be able to opt in.' );

    $GLOBALS['eim_svg_filter_override'] = false;
    $validator->set_svg_imports_allowed( true );
    eim_svg_optin_assert( false === $validator->allows_svg_imports(), 'Existing eim_allow_svg_imports filter must still be able to force SVG imports off.' );

    echo "SVG opt-in tests passed.\n";
} finally {
    @unlink( $fixture );
}
