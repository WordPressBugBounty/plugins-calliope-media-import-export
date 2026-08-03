<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code = '', $message = '', $data = [] ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_data() {
        return $this->data;
    }

    public function get_error_messages() {
        return '' === $this->message ? [] : [ $this->message ];
    }
}

class EIM_Failing_Filesystem_Transport {
    public $method = 'direct';

    public function put_contents( $path, $contents, $mode = false ) {
        return false;
    }
}

class EIM_Failing_Remote_Filesystem_Transport {
    public $method = 'ftpext';

    public function exists( $path ) {
        return true;
    }

    public function get_contents( $path ) {
        return false;
    }
}

function __( $message, $domain = null ) {
    return $message;
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
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

function eim_filesystem_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-filesystem.php';

$events     = [];
$filesystem = new EIM_Filesystem( new EIM_Failing_Filesystem_Transport() );
$filesystem->add_error_listener(
    function( $event, $details ) use ( &$events ) {
        $events[] = [ 'event' => $event, 'details' => $details ];
    }
);

$write_error = $filesystem->put_contents( __DIR__ . '/cannot-write.tmp', 'content', 'test_write_failed' );
eim_filesystem_assert( is_wp_error( $write_error ), 'Failed put_contents should return WP_Error.' );
eim_filesystem_assert( 'eim_filesystem_write_failed' === $write_error->get_error_code(), 'Write failure should keep the filesystem error code.' );
eim_filesystem_assert( 'put_contents' === $write_error->get_error_data()['operation'], 'Write failure should identify the operation.' );
eim_filesystem_assert( 'direct' === $write_error->get_error_data()['filesystem_method'], 'Write failure should identify the active transport.' );
eim_filesystem_assert( 'test_write_failed' === $events[0]['event'], 'Write failure should notify diagnostic listeners.' );

$read_only_stream = fopen( __FILE__, 'rb' );
$csv_error        = $filesystem->write_csv_row( $read_only_stream, [ 'cannot', 'write' ], __FILE__, 'test_csv_write_failed' );
fclose( $read_only_stream );
eim_filesystem_assert( is_wp_error( $csv_error ), 'Failed fputcsv should return WP_Error.' );
eim_filesystem_assert( 'fputcsv' === $csv_error->get_error_data()['operation'], 'CSV failure should identify fputcsv.' );
eim_filesystem_assert( '' !== $csv_error->get_error_data()['warning'], 'CSV failure should preserve the native warning.' );
eim_filesystem_assert( 'test_csv_write_failed' === $events[1]['event'], 'CSV failure should notify diagnostic listeners.' );

$remote_events     = [];
$remote_filesystem = new EIM_Filesystem( new EIM_Failing_Remote_Filesystem_Transport() );
$remote_filesystem->add_error_listener(
    function( $event, $details ) use ( &$remote_events ) {
        $remote_events[] = [ 'event' => $event, 'details' => $details ];
    }
);

$append_error = $remote_filesystem->append_contents( '/remote/progress.jsonl', "entry\n", 'test_append_failed' );
eim_filesystem_assert( is_wp_error( $append_error ), 'Failed remote append should return WP_Error.' );
eim_filesystem_assert( 'get_contents' === $append_error->get_error_data()['operation'], 'Remote append failure should identify the failed read step.' );
eim_filesystem_assert( 'ftpext' === $append_error->get_error_data()['filesystem_method'], 'Remote append failure should identify the remote transport.' );
eim_filesystem_assert( 'test_append_failed' === $remote_events[0]['event'], 'Append failure should notify diagnostic listeners.' );

echo "Filesystem failure tests passed.\n";
