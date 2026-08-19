<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
    private $code;
    private $message;

    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

class EIM_Filesystem {
    public function get_contents( $path, $error_code = '' ) {
        return file_get_contents( $path );
    }
}

class EIM_Regression_WPDB {
    public $postmeta = 'wp_postmeta';

    public function prepare( $query, ...$args ) {
        return [ $query, $args ];
    }

    public function get_var( $prepared ) {
        $args = is_array( $prepared ) && isset( $prepared[1] ) ? $prepared[1] : [];
        if ( isset( $args[0], $args[1] ) && '_eim_file_fingerprint' === $args[0] && 'md5:testfingerprint' === $args[1] ) {
            return 77;
        }
        return 0;
    }
}

$GLOBALS['eim_regression_download_calls'] = 0;
$GLOBALS['eim_regression_download_mode'] = 'timeout';

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function __( $message, $domain = null ) {
    return $message;
}

function absint( $value ) {
    return abs( (int) $value );
}

function sanitize_file_name( $value ) {
    return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

function wp_json_encode( $value, $flags = 0 ) {
    return json_encode( $value, $flags );
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', (string) $path );
}

function apply_filters( $hook, $value ) {
    return $value;
}

$GLOBALS['eim_regression_post_meta'] = [];

function get_post_meta( $post_id, $key, $single = false ) {
    return isset( $GLOBALS['eim_regression_post_meta'][ $post_id ][ $key ] )
        ? $GLOBALS['eim_regression_post_meta'][ $post_id ][ $key ]
        : '';
}

function download_url( $url, $timeout ) {
    $GLOBALS['eim_regression_download_calls']++;

    if ( 1 === $GLOBALS['eim_regression_download_calls'] ) {
        if ( 'bad_gateway' === $GLOBALS['eim_regression_download_mode'] ) {
            return new WP_Error( 'http_request_failed', 'Bad Gateway' );
        }

        return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
    }

    $file = tempnam( sys_get_temp_dir(), 'eim-retry-' );
    file_put_contents( $file, 'downloaded' );
    return $file;
}

function eim_regression_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-svg-import-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-attachment-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-importer.php';

$svgish = tempnam( sys_get_temp_dir(), 'eim-svgish-' );
file_put_contents( $svgish, '<!doctype html><html><svg></svg></html>' );

$validator = new EIM_Svg_Import_Validator( new EIM_Filesystem() );
eim_regression_assert(
    false === $validator->is_svg_import_file( $svgish, 'photo.png' ),
    'A PNG filename must not be routed through SVG sanitization because its downloaded bytes contain <svg>.'
);
eim_regression_assert(
    true === $validator->is_svg_import_file( $svgish, '' ),
    'An extensionless temporary file can still use content sniffing as a last-resort SVG detector.'
);

$reflection = new ReflectionClass( 'EIM_Importer' );
$importer   = $reflection->newInstanceWithoutConstructor();

$verified_source_method = $reflection->getMethod( 'is_previously_verified_source_match' );
$verified_source_method->setAccessible( true );
$GLOBALS['eim_regression_post_meta'][55] = [
    '_eim_file_fingerprint' => 'md5:verified',
    '_eim_source_url' => 'https://source.example/wp-content/uploads/photo.png',
    '_eim_source_rel_path' => '2026/08/photo.png',
];
eim_regression_assert(
    true === $verified_source_method->invoke( $importer, 55, 'https://source.example/wp-content/uploads/photo.png', '' ),
    'A previously fingerprint-verified source URL match must be reusable without another remote download.'
);
$GLOBALS['eim_regression_post_meta'][56] = [
    '_eim_file_fingerprint' => '',
    '_eim_source_url' => 'https://source.example/wp-content/uploads/photo.png',
    '_eim_source_rel_path' => '2026/08/photo.png',
];
eim_regression_assert(
    false === $verified_source_method->invoke( $importer, 56, 'https://source.example/wp-content/uploads/photo.png', '' ),
    'Source metadata without a stored fingerprint must not bypass first-time content verification.'
);

$type_method = $reflection->getMethod( 'validate_downloaded_file_type' );
$type_method->setAccessible( true );
$type_result = $type_method->invoke( $importer, $svgish, 'photo.png' );
eim_regression_assert(
    is_wp_error( $type_result ) && 'eim_download_type_mismatch' === $type_result->get_error_code(),
    'HTML/SVG content masquerading as PNG must fail with the file-type mismatch error.'
);

$tmp_svgish = $svgish . '.tmp';
copy( $svgish, $tmp_svgish );
eim_regression_assert(
    true === $validator->is_svg_import_file( $tmp_svgish, '' ),
    'A .tmp download path without an original filename extension can still use SVG content sniffing.'
);
unlink( $tmp_svgish );
unlink( $svgish );

$png = tempnam( sys_get_temp_dir(), 'eim-png-' );
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
$type_png = $type_method->invoke( $importer, $png, 'valid.png' );
eim_regression_assert( true === $type_png, 'A real PNG still passes the stricter downloaded-content validation.' );
unlink( $png );

$GLOBALS['wpdb'] = new EIM_Regression_WPDB();
$matcher = new EIM_Attachment_Matcher();
eim_regression_assert(
    77 === $matcher->find_existing_attachment_id_by_fingerprint( 'md5:testfingerprint' ),
    'Fingerprint-only rematching can find an existing attachment without falling back to source URL or path.'
);

$retry_method = $reflection->getMethod( 'download_remote_file_with_retry' );
$retry_method->setAccessible( true );
$GLOBALS['eim_regression_download_calls'] = 0;
$GLOBALS['eim_regression_download_mode'] = 'timeout';
$downloaded = $retry_method->invoke( $importer, 'https://example.invalid/video.mp4', 60, 'video.mp4' );
eim_regression_assert( ! is_wp_error( $downloaded ) && file_exists( $downloaded ), 'A timeout should be retried once.' );
eim_regression_assert( 2 === $GLOBALS['eim_regression_download_calls'], 'The transient timeout regression should make exactly two attempts.' );
unlink( $downloaded );

$GLOBALS['eim_regression_download_calls'] = 0;
$GLOBALS['eim_regression_download_mode'] = 'bad_gateway';
$downloaded = $retry_method->invoke( $importer, 'https://example.invalid/image.png', 60, 'image.png' );
eim_regression_assert( ! is_wp_error( $downloaded ) && file_exists( $downloaded ), 'A transient Bad Gateway response should be retried once.' );
eim_regression_assert( 2 === $GLOBALS['eim_regression_download_calls'], 'The Bad Gateway regression should make exactly two attempts.' );
unlink( $downloaded );

echo "Import regression tests passed.\n";
