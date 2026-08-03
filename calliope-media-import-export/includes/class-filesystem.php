<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Filesystem {

    private $filesystem;
    private $error_listeners = [];
    private $last_error;

    public function __construct( $filesystem = null ) {
        if ( is_object( $filesystem ) ) {
            $this->filesystem = $filesystem;
        }
    }

    public function add_error_listener( $listener ) {
        if ( is_callable( $listener ) ) {
            $this->error_listeners[] = $listener;
        }
    }

    public function get_last_error() {
        return $this->last_error;
    }

    public function put_contents( $path, $contents, $event = 'filesystem_write_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        $mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
        if ( ! $filesystem->put_contents( $path, (string) $contents, $mode ) ) {
            return $this->failure( $event, 'eim_filesystem_write_failed', __( 'The file could not be written.', 'calliope-media-import-export' ), 'put_contents', $path );
        }

        return true;
    }

    public function append_contents( $path, $contents, $event = 'filesystem_append_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        if ( $this->is_direct( $filesystem ) ) {
            $handle = $this->open_stream( $path, 'ab', $event );
            if ( is_wp_error( $handle ) ) {
                return $handle;
            }

            $warning = '';
            $locked  = $this->capture_native_warning(
                function() use ( $handle ) {
                    return flock( $handle, LOCK_EX );
                },
                $warning
            );

            if ( ! $locked ) {
                $this->close_stream( $handle );
                return $this->failure( $event, 'eim_filesystem_lock_failed', __( 'The file could not be locked for writing.', 'calliope-media-import-export' ), 'flock', $path, $warning );
            }

            $contents = (string) $contents;
            $written  = $this->capture_native_warning(
                function() use ( $handle, $contents ) {
                    return fwrite( $handle, $contents );
                },
                $warning
            );

            $flushed = $this->capture_native_warning(
                function() use ( $handle ) {
                    return fflush( $handle );
                },
                $flush_warning
            );
            flock( $handle, LOCK_UN );
            $closed = $this->close_stream( $handle, $path, $event );

            if ( false === $written || $written < strlen( $contents ) ) {
                return $this->failure( $event, 'eim_filesystem_append_failed', __( 'The file could not be appended.', 'calliope-media-import-export' ), 'fwrite', $path, $warning );
            }

            if ( ! $flushed ) {
                return $this->failure( $event, 'eim_filesystem_flush_failed', __( 'The appended file data could not be flushed to disk.', 'calliope-media-import-export' ), 'fflush', $path, $flush_warning );
            }

            if ( is_wp_error( $closed ) ) {
                return $closed;
            }

            return true;
        }

        $existing = '';
        if ( $filesystem->exists( $path ) ) {
            $existing = $filesystem->get_contents( $path );
            if ( false === $existing ) {
                return $this->failure( $event, 'eim_filesystem_read_failed', __( 'The existing file could not be read before appending.', 'calliope-media-import-export' ), 'get_contents', $path );
            }
        }

        return $this->put_contents( $path, (string) $existing . (string) $contents, $event );
    }

    public function get_contents( $path, $event = 'filesystem_read_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        $contents = $filesystem->get_contents( $path );
        if ( false === $contents ) {
            return $this->failure( $event, 'eim_filesystem_read_failed', __( 'The file could not be read.', 'calliope-media-import-export' ), 'get_contents', $path );
        }

        return $contents;
    }

    public function copy( $source, $destination, $overwrite = false, $event = 'filesystem_copy_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $destination ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        $mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
        if ( ! $filesystem->copy( $source, $destination, (bool) $overwrite, $mode ) ) {
            return $this->failure(
                $event,
                'eim_filesystem_copy_failed',
                __( 'The file could not be copied.', 'calliope-media-import-export' ),
                'copy',
                $destination,
                '',
                [ 'source' => $this->normalize_path( $source ) ]
            );
        }

        return true;
    }

    public function delete( $path, $event = 'filesystem_delete_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        if ( ! $filesystem->exists( $path ) ) {
            return true;
        }

        if ( ! $filesystem->delete( $path, false, 'f' ) ) {
            return $this->failure( $event, 'eim_filesystem_delete_failed', __( 'The file could not be deleted.', 'calliope-media-import-export' ), 'delete', $path );
        }

        return true;
    }

    public function mkdir( $path, $event = 'filesystem_mkdir_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        if ( $filesystem->is_dir( $path ) ) {
            return true;
        }

        $mode = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
        if ( ! $filesystem->mkdir( $path, $mode ) ) {
            return $this->failure( $event, 'eim_filesystem_mkdir_failed', __( 'The directory could not be created.', 'calliope-media-import-export' ), 'mkdir', $path );
        }

        return true;
    }

    public function exists( $path ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        return ! is_wp_error( $filesystem ) && $filesystem->exists( $path );
    }

    public function is_dir( $path ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        return ! is_wp_error( $filesystem ) && $filesystem->is_dir( $path );
    }

    public function is_file( $path ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        return ! is_wp_error( $filesystem ) && $filesystem->is_file( $path );
    }

    public function is_writable( $path ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        return ! is_wp_error( $filesystem ) && $filesystem->is_writable( $path );
    }

    public function dirlist( $path, $event = 'filesystem_dirlist_failed' ) {
        $filesystem = $this->get_filesystem( $path );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        $files = $filesystem->dirlist( $path, true, false );
        if ( false === $files ) {
            return $this->failure( $event, 'eim_filesystem_dirlist_failed', __( 'The directory contents could not be read.', 'calliope-media-import-export' ), 'dirlist', $path );
        }

        return $files;
    }

    public function mtime( $path, $event = 'filesystem_mtime_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        $mtime = $filesystem->mtime( $path );
        if ( false === $mtime ) {
            return $this->failure( $event, 'eim_filesystem_mtime_failed', __( 'The file modification time could not be read.', 'calliope-media-import-export' ), 'mtime', $path );
        }

        return (int) $mtime;
    }

    public function size( $path, $event = 'filesystem_size_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        $size = $filesystem->size( $path );
        if ( false === $size ) {
            return $this->failure( $event, 'eim_filesystem_size_failed', __( 'The file size could not be read.', 'calliope-media-import-export' ), 'size', $path );
        }

        return (int) $size;
    }

    public function md5_file( $path, $event = 'filesystem_hash_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        if ( ! $this->is_direct( $filesystem ) ) {
            $contents = $filesystem->get_contents( $path );
            if ( false === $contents ) {
                return $this->failure( $event, 'eim_filesystem_hash_failed', __( 'The file could not be read for fingerprinting.', 'calliope-media-import-export' ), 'get_contents', $path );
            }

            return md5( $contents );
        }

        $warning = '';
        $hash    = $this->capture_native_warning(
            function() use ( $path ) {
                return hash_file( 'md5', $path );
            },
            $warning
        );

        if ( false === $hash ) {
            return $this->failure( $event, 'eim_filesystem_hash_failed', __( 'The file fingerprint could not be calculated.', 'calliope-media-import-export' ), 'hash_file', $path, $warning );
        }

        return $hash;
    }

    public function open_stream( $path, $mode, $event = 'filesystem_stream_open_failed' ) {
        $filesystem = $this->get_filesystem( dirname( (string) $path ) );
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }

        if ( ! $this->is_direct( $filesystem ) ) {
            return $this->failure(
                $event,
                'eim_filesystem_stream_unsupported',
                __( 'Streaming file access requires the direct WordPress filesystem method.', 'calliope-media-import-export' ),
                'fopen',
                $path
            );
        }

        $warning = '';
        $handle  = $this->capture_native_warning(
            function() use ( $path, $mode ) {
                return fopen( $path, $mode );
            },
            $warning
        );

        if ( ! is_resource( $handle ) ) {
            return $this->failure( $event, 'eim_filesystem_stream_open_failed', __( 'The file stream could not be opened.', 'calliope-media-import-export' ), 'fopen', $path, $warning );
        }

        return $handle;
    }

    public function close_stream( $handle, $path = '', $event = 'filesystem_stream_close_failed' ) {
        if ( ! is_resource( $handle ) ) {
            return true;
        }

        $warning = '';
        $closed  = $this->capture_native_warning(
            function() use ( $handle ) {
                return fclose( $handle );
            },
            $warning
        );

        if ( ! $closed && '' !== (string) $path ) {
            return $this->failure( $event, 'eim_filesystem_stream_close_failed', __( 'The file stream could not be closed cleanly.', 'calliope-media-import-export' ), 'fclose', $path, $warning );
        }

        return (bool) $closed;
    }

    public function seek_stream( $handle, $offset, $whence, $path, $event = 'filesystem_stream_seek_failed' ) {
        $warning = '';
        $result  = $this->capture_native_warning(
            function() use ( $handle, $offset, $whence ) {
                return fseek( $handle, $offset, $whence );
            },
            $warning
        );

        if ( 0 !== $result ) {
            return $this->failure( $event, 'eim_filesystem_stream_seek_failed', __( 'The file stream could not be repositioned.', 'calliope-media-import-export' ), 'fseek', $path, $warning );
        }

        return true;
    }

    public function read_stream( $handle, $length, $path, $event = 'filesystem_stream_read_failed' ) {
        $warning = '';
        $contents = $this->capture_native_warning(
            function() use ( $handle, $length ) {
                return fread( $handle, $length );
            },
            $warning
        );

        if ( false === $contents ) {
            return $this->failure( $event, 'eim_filesystem_stream_read_failed', __( 'The file stream could not be read.', 'calliope-media-import-export' ), 'fread', $path, $warning );
        }

        return $contents;
    }

    public function write_csv_row( $handle, $row, $path = 'php://output', $event = 'filesystem_csv_write_failed' ) {
        $warning = '';
        $written = $this->capture_native_warning(
            function() use ( $handle, $row ) {
                return fputcsv( $handle, $row, ',', '"', '\\' );
            },
            $warning
        );

        if ( false === $written ) {
            return $this->failure( $event, 'eim_filesystem_csv_write_failed', __( 'The CSV row could not be written.', 'calliope-media-import-export' ), 'fputcsv', $path, $warning );
        }

        return true;
    }

    private function get_filesystem( $context = '' ) {
        if ( is_object( $this->filesystem ) ) {
            return $this->filesystem;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;

        $initialized = WP_Filesystem( false, $context );
        if ( ! $initialized || ! is_object( $wp_filesystem ) ) {
            return $this->failure( 'filesystem_initialization_failed', 'eim_filesystem_unavailable', __( 'WordPress could not initialize filesystem access.', 'calliope-media-import-export' ), 'WP_Filesystem', $context );
        }

        $this->filesystem = $wp_filesystem;
        return $this->filesystem;
    }

    private function is_direct( $filesystem ) {
        if ( isset( $filesystem->method ) ) {
            return 'direct' === (string) $filesystem->method;
        }

        return false !== stripos( get_class( $filesystem ), 'direct' );
    }

    private function failure( $event, $code, $message, $operation, $path, $warning = '', $extra = [] ) {
        $details = array_merge(
            [
                'operation'         => (string) $operation,
                'path'              => $this->normalize_path( $path ),
                'filesystem_method' => $this->get_method_name(),
                'warning'           => (string) $warning,
                'filesystem_errors' => $this->get_filesystem_errors(),
                'parent_exists'     => is_dir( dirname( (string) $path ) ),
                'parent_writable'   => is_writable( dirname( (string) $path ) ),
            ],
            is_array( $extra ) ? $extra : []
        );

        $error            = new WP_Error( $code, $message, $details );
        $this->last_error = $error;

        foreach ( $this->error_listeners as $listener ) {
            call_user_func( $listener, $event, $details, $error );
        }

        do_action( 'eim_filesystem_error', $event, $details, $error );

        if ( empty( $this->error_listeners ) ) {
            $encoded = wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            error_log( '[EIM_FILESYSTEM] ' . sanitize_key( (string) $event ) . ' | ' . ( false === $encoded ? '{}' : $encoded ) );
        }

        return $error;
    }

    private function get_method_name() {
        if ( ! is_object( $this->filesystem ) ) {
            return 'unavailable';
        }

        return isset( $this->filesystem->method ) ? (string) $this->filesystem->method : get_class( $this->filesystem );
    }

    private function get_filesystem_errors() {
        if ( ! is_object( $this->filesystem ) || ! isset( $this->filesystem->errors ) || ! is_wp_error( $this->filesystem->errors ) ) {
            return [];
        }

        return $this->filesystem->errors->get_error_messages();
    }

    private function capture_native_warning( $callback, &$warning ) {
        $warning = '';
        set_error_handler(
            function( $severity, $message ) use ( &$warning ) {
                $warning = (string) $message;
                return true;
            }
        );

        try {
            return call_user_func( $callback );
        } finally {
            restore_error_handler();
        }
    }

    private function normalize_path( $path ) {
        return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( (string) $path ) : str_replace( '\\', '/', (string) $path );
    }
}
