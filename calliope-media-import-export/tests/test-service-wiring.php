<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['eim_test_actions'] = [];

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code = '', $message = '', $data = [] ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_data() {
        return $this->data;
    }

    public function get_error_messages() {
        return '' === $this->message ? [] : [ $this->message ];
    }
}

class EIM_Test_Direct_Filesystem {
    public $method = 'direct';

    public function size( $path ) {
        return filesize( $path );
    }
}

function absint( $value ) {
    return abs( (int) $value );
}

function eim_get_setting( $path, $default = null ) {
    return $default;
}

function __( $message, $domain = null ) {
    return $message;
}

function _n( $single, $plural, $count, $domain = null ) {
    return 1 === (int) $count ? $single : $plural;
}

function apply_filters( $hook, $value ) {
    return $value;
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function do_action( $hook ) {
}

function wp_json_encode( $value, $flags = 0 ) {
    return json_encode( $value, $flags );
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', (string) $path );
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function add_action( $hook, $callback ) {
    $GLOBALS['eim_test_actions'][ $hook ] = $callback;
}

function eim_wiring_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$includes = dirname( __DIR__ ) . '/includes/';
require_once $includes . 'class-csv-reader.php';
require_once $includes . 'class-filesystem.php';
require_once $includes . 'class-attachment-matcher.php';
require_once $includes . 'class-svg-import-validator.php';
require_once $includes . 'class-attachment-writer.php';
require_once $includes . 'class-temp-file-manager.php';
require_once $includes . 'class-importer.php';

$filesystem = new EIM_Filesystem( new EIM_Test_Direct_Filesystem() );
$importer   = new EIM_Importer( $filesystem );
$reflection = new ReflectionClass( $importer );
$services   = [
    'csv_reader'         => 'EIM_Csv_Reader',
    'attachment_matcher' => 'EIM_Attachment_Matcher',
    'attachment_writer'  => 'EIM_Attachment_Writer',
    'svg_validator'      => 'EIM_Svg_Import_Validator',
    'temp_file_manager'  => 'EIM_Temp_File_Manager',
    'filesystem'         => 'EIM_Filesystem',
];

foreach ( $services as $property_name => $expected_class ) {
    $property = $reflection->getProperty( $property_name );
    if ( PHP_VERSION_ID < 80100 ) {
        $property->setAccessible( true );
    }
    eim_wiring_assert( is_a( $property->getValue( $importer ), $expected_class ), "{$property_name} should contain {$expected_class}." );
}

$matcher = new EIM_Attachment_Matcher();
eim_wiring_assert( 'skip' === $matcher->normalize_duplicate_strategy( 'replace_file', false ), 'Free-mode duplicate strategy should stay restricted to skip.' );
eim_wiring_assert( 'filename' === $matcher->normalize_match_strategy( 'filename', true ), 'Advanced filename matching should remain available when explicitly allowed.' );

$writer_property = $reflection->getProperty( 'attachment_writer' );
if ( PHP_VERSION_ID < 80100 ) {
    $writer_property->setAccessible( true );
}
$writer = $writer_property->getValue( $importer );
eim_wiring_assert( [ 'rating' => 5 ] === $writer->decode_custom_meta_json( '{"rating":5}' ), 'Writer should decode custom metadata JSON.' );
eim_wiring_assert( [ 'title', 'alt' ] === $writer->normalize_selected_update_fields( [ 'title', 'invalid', 'alt', 'title' ] ), 'Writer should normalize selected metadata fields.' );

$fingerprint_fixture = tempnam( sys_get_temp_dir(), 'eim-fingerprint-' );
file_put_contents( $fingerprint_fixture, 'fingerprint fixture' );
eim_wiring_assert( 'md5:' . md5_file( $fingerprint_fixture ) === $writer->get_file_fingerprint( $fingerprint_fixture ), 'Writer should own small-file fingerprint calculation.' );
unlink( $fingerprint_fixture );

$cleanup_callback = isset( $GLOBALS['eim_test_actions']['eim_daily_cleanup_event'] ) ? $GLOBALS['eim_test_actions']['eim_daily_cleanup_event'] : null;
eim_wiring_assert( is_array( $cleanup_callback ), 'Daily cleanup callback should be registered.' );
eim_wiring_assert( $cleanup_callback[0] instanceof EIM_Temp_File_Manager, 'Daily cleanup should be owned by the temp file manager.' );

echo "Importer service wiring tests passed.\n";
