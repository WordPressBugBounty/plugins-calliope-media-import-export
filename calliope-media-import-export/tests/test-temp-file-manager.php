<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['eim_temp_test_root']       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eim-temp-manager-' . uniqid( '', true );
$GLOBALS['eim_temp_test_transients'] = [];

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code, $message, $data = [] ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
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

class EIM_Temp_Test_Filesystem {
    public $method = 'direct';

    public function put_contents( $path, $contents, $mode = false ) {
        return false !== file_put_contents( $path, $contents );
    }

    public function get_contents( $path ) {
        return file_get_contents( $path );
    }

    public function copy( $source, $destination, $overwrite = false, $mode = false ) {
        if ( ! $overwrite && file_exists( $destination ) ) {
            return false;
        }
        return copy( $source, $destination );
    }

    public function delete( $path, $recursive = false, $type = false ) {
        return ! file_exists( $path ) || unlink( $path );
    }

    public function mkdir( $path, $mode = false ) {
        return is_dir( $path ) || mkdir( $path, $mode ? $mode : 0755, true );
    }

    public function exists( $path ) {
        return file_exists( $path );
    }

    public function is_dir( $path ) {
        return is_dir( $path );
    }

    public function is_file( $path ) {
        return is_file( $path );
    }

    public function is_writable( $path ) {
        return is_writable( $path );
    }

    public function mtime( $path ) {
        return filemtime( $path );
    }

    public function size( $path ) {
        return filesize( $path );
    }

    public function dirlist( $path, $include_hidden = true, $recursive = false ) {
        $result = [];
        foreach ( scandir( $path ) as $name ) {
            if ( '.' === $name || '..' === $name ) {
                continue;
            }
            $file_path       = $path . DIRECTORY_SEPARATOR . $name;
            $result[ $name ] = [
                'name'        => $name,
                'type'        => is_dir( $file_path ) ? 'd' : 'f',
                'lastmodunix' => filemtime( $file_path ),
            ];
        }
        return $result;
    }
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function __( $message, $domain = null ) {
    return $message;
}

function do_action( $hook ) {
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', (string) $path );
}

function absint( $value ) {
    return abs( (int) $value );
}

function trailingslashit( $value ) {
    return rtrim( $value, '/\\' ) . '/';
}

function wp_upload_dir() {
    return [ 'basedir' => $GLOBALS['eim_temp_test_root'] ];
}

function wp_mkdir_p( $path ) {
    return is_dir( $path ) || mkdir( $path, 0700, true );
}

function wp_generate_uuid4() {
    return sprintf( '%s-%s-4%s-a%s-%s', bin2hex( random_bytes( 4 ) ), bin2hex( random_bytes( 2 ) ), bin2hex( random_bytes( 2 ) ), bin2hex( random_bytes( 2 ) ), bin2hex( random_bytes( 6 ) ) );
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_file_name( $value ) {
    return preg_replace( '/[^A-Za-z0-9._-]/', '', basename( (string) $value ) );
}

function wp_json_encode( $value, $flags = 0 ) {
    return json_encode( $value, $flags );
}

function get_current_user_id() {
    return 7;
}

function wp_delete_file( $path ) {
    return ! file_exists( $path ) || unlink( $path );
}

function get_transient( $key ) {
    return isset( $GLOBALS['eim_temp_test_transients'][ $key ] ) ? $GLOBALS['eim_temp_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl ) {
    $GLOBALS['eim_temp_test_transients'][ $key ] = $value;
    return true;
}

function delete_transient( $key ) {
    unset( $GLOBALS['eim_temp_test_transients'][ $key ] );
    return true;
}

function eim_temp_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-csv-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-filesystem.php';
require_once dirname( __DIR__ ) . '/includes/class-temp-file-manager.php';

$root        = $GLOBALS['eim_temp_test_root'];
$source_file = $root . DIRECTORY_SEPARATOR . 'source.csv';
mkdir( $root, 0700, true );
file_put_contents( $source_file, "Absolute URL,Title\nhttps://example.com/a.jpg,Example\n" );

try {
    $reader     = new EIM_Csv_Reader();
    $inspection = $reader->inspect_file( $source_file );
    $filesystem = new EIM_Filesystem( new EIM_Temp_Test_Filesystem() );
    $manager    = new EIM_Temp_File_Manager(
        function( $file_path ) use ( $reader ) {
            try {
                return $reader->inspect_file( $file_path );
            } catch ( EIM_Csv_Reader_Exception $exception ) {
                return new WP_Error( $exception->get_error_code(), $exception->getMessage() );
            }
        },
        $filesystem
    );

    $created = $manager->create_temp_import_file( $source_file, $inspection );
    eim_temp_assert( ! is_wp_error( $created ) && ! empty( $created['file'] ), 'Temporary import file should be created.' );

    $paths = $manager->get_temp_file_paths( $created['file'] );
    eim_temp_assert( file_exists( $paths['csv'] ), 'Temporary CSV should exist.' );
    eim_temp_assert( file_exists( $paths['meta'] ), 'Temporary metadata should exist.' );
    eim_temp_assert( file_exists( $paths['progress'] ), 'Progress log should be initialized.' );

    $meta = $manager->read_temp_file_meta( $created['file'] );
    eim_temp_assert( 1 === $meta['total_rows'] && 7 === $meta['created_by'], 'Temporary metadata should preserve inspection and owner data.' );

    $manager->append_import_progress( $created['file'], [ 'row_number' => 1, 'status' => 'IMPORTED' ] );
    eim_temp_assert( false !== strpos( file_get_contents( $paths['progress'] ), '"row_number":1' ), 'Progress entries should be appended.' );

    $lock_key = $manager->get_temp_lock_key( $created['file'] );
    eim_temp_assert( true === $manager->acquire_temp_lock( $lock_key ), 'First lock acquisition should succeed.' );
    eim_temp_assert( false === $manager->acquire_temp_lock( $lock_key ), 'Concurrent lock acquisition should fail.' );
    $manager->release_temp_lock( $lock_key );
    eim_temp_assert( true === $manager->acquire_temp_lock( $lock_key ), 'Released lock should be acquirable again.' );
    $manager->release_temp_lock( $lock_key );

    $manager->cleanup_temp_import_file( $created['file'] );
    eim_temp_assert( ! file_exists( $paths['csv'] ) && ! file_exists( $paths['meta'] ) && ! file_exists( $paths['progress'] ), 'Import cleanup should remove all files for the token.' );

    $stale_file = dirname( $paths['csv'] ) . DIRECTORY_SEPARATOR . 'stale.tmp';
    file_put_contents( $stale_file, 'stale' );
    touch( $stale_file, time() - DAY_IN_SECONDS - 60 );
    $manager->cleanup_temp_files();
    eim_temp_assert( ! file_exists( $stale_file ), 'Scheduled cleanup should remove expired files through the filesystem service.' );

    echo "Temp file manager tests passed.\n";
} finally {
    $temp_dir = $root . DIRECTORY_SEPARATOR . 'eim-temp';
    foreach ( [ 'index.php', '.htaccess', 'web.config' ] as $guard ) {
        $guard_path = $temp_dir . DIRECTORY_SEPARATOR . $guard;
        if ( file_exists( $guard_path ) ) {
            unlink( $guard_path );
        }
    }
    if ( is_dir( $temp_dir ) ) {
        rmdir( $temp_dir );
    }
    if ( file_exists( $source_file ) ) {
        unlink( $source_file );
    }
    if ( is_dir( $root ) ) {
        rmdir( $root );
    }
}
