<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Temp_File_Manager {

    const TEMP_FILE_TTL = DAY_IN_SECONDS;
    const LOCK_TTL      = 90;

    private $csv_inspector;
    private $filesystem;

    public function __construct( $csv_inspector, ?EIM_Filesystem $filesystem = null ) {
        if ( ! is_callable( $csv_inspector ) ) {
            throw new InvalidArgumentException( 'A CSV inspector callback is required.' );
        }

        $this->csv_inspector = $csv_inspector;
        $this->filesystem    = $filesystem ? $filesystem : new EIM_Filesystem();
    }

    private function inspect_csv_file( $file_path ) {
        return call_user_func( $this->csv_inspector, $file_path );
    }

    public function create_temp_import_file( $source_path, $inspection ) {
        $temp_dir = $this->ensure_temp_dir();
        if ( is_wp_error( $temp_dir ) ) {
            return $temp_dir;
        }

        $token        = str_replace( '-', '', wp_generate_uuid4() );
        $base_name    = 'import-' . sanitize_key( $token );
        $csv_filename = $base_name . '.csv';
        $csv_path     = trailingslashit( $temp_dir ) . $csv_filename;
        $meta_path    = trailingslashit( $temp_dir ) . $base_name . '.meta.json';

        $copied = $this->copy_file_streaming( $source_path, $csv_path );
        if ( is_wp_error( $copied ) ) {
            $this->filesystem->delete( $csv_path, 'temp_partial_csv_cleanup_failed' );
            return $this->wrap_filesystem_error( 'eim_temp_copy_failed', __( 'Could not prepare the temporary CSV file.', 'calliope-media-import-export' ), $copied );
        }

        $meta = [
            'delimiter'  => isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',',
            'total_rows' => isset( $inspection['total_rows'] ) ? absint( $inspection['total_rows'] ) : 0,
            'created_at' => time(),
            'created_by' => get_current_user_id(),
        ];

        $encoded_meta = wp_json_encode( $meta );
        if ( false === $encoded_meta ) {
            $this->filesystem->delete( $csv_path, 'temp_csv_cleanup_failed' );
            return new WP_Error( 'eim_temp_meta_failed', __( 'Could not store temporary import metadata.', 'calliope-media-import-export' ) );
        }

        $meta_written = $this->filesystem->put_contents( $meta_path, $encoded_meta, 'temp_meta_write_failed' );
        if ( is_wp_error( $meta_written ) ) {
            $this->filesystem->delete( $csv_path, 'temp_csv_cleanup_failed' );
            return $this->wrap_filesystem_error( 'eim_temp_meta_failed', __( 'Could not store temporary import metadata.', 'calliope-media-import-export' ), $meta_written );
        }

        $progress_reset = $this->reset_import_progress_log( $csv_filename );
        if ( is_wp_error( $progress_reset ) ) {
            $this->cleanup_temp_import_file( $csv_filename );
            return $progress_reset;
        }

        return [
            'file' => $csv_filename,
        ];
    }

    private function ensure_temp_dir() {
        $temp_dir = $this->get_temp_dir();

        $created = $this->filesystem->mkdir( $temp_dir, 'temp_dir_create_failed' );
        if ( is_wp_error( $created ) ) {
            return $this->wrap_filesystem_error( 'eim_temp_dir_failed', __( 'Could not create the temporary import folder.', 'calliope-media-import-export' ), $created );
        }

        if ( ! $this->filesystem->is_dir( $temp_dir ) || ! $this->filesystem->is_writable( $temp_dir ) ) {
            return new WP_Error( 'eim_temp_dir_unwritable', __( 'The temporary import folder is not writable.', 'calliope-media-import-export' ) );
        }

        $guards_written = $this->write_temp_dir_guards( $temp_dir );
        if ( is_wp_error( $guards_written ) ) {
            return $guards_written;
        }

        return $temp_dir;
    }

    private function write_temp_dir_guards( $temp_dir ) {
        $guards = [
            'index.php'  => "<?php\n// Silence is golden.\n",
            '.htaccess'  => "Deny from all\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
        ];

        foreach ( $guards as $filename => $contents ) {
            $path = trailingslashit( $temp_dir ) . $filename;
            if ( ! $this->filesystem->exists( $path ) ) {
                $written = $this->filesystem->put_contents( $path, $contents, 'temp_guard_write_failed' );
                if ( is_wp_error( $written ) ) {
                    return $this->wrap_filesystem_error( 'eim_temp_guard_failed', __( 'Could not protect the temporary import folder.', 'calliope-media-import-export' ), $written );
                }
            }
        }

        return true;
    }

    private function copy_file_streaming( $source_path, $destination_path ) {
        return $this->filesystem->copy( $source_path, $destination_path, true, 'temp_csv_copy_failed' );
    }

    public function get_temp_file_paths( $file_name ) {
        $file_name = sanitize_file_name( (string) $file_name );

        if ( '' === $file_name || ! preg_match( '/^import-[a-z0-9]+\.csv$/', $file_name ) ) {
            return new WP_Error( 'eim_temp_name_invalid', __( 'Invalid temporary file name.', 'calliope-media-import-export' ) );
        }

        $temp_dir  = $this->get_temp_dir();
        $meta_name = str_replace( '.csv', '.meta.json', $file_name );

        return [
            'csv'  => trailingslashit( $temp_dir ) . $file_name,
            'meta' => trailingslashit( $temp_dir ) . $meta_name,
            'progress' => trailingslashit( $temp_dir ) . str_replace( '.csv', '.progress.jsonl', $file_name ),
        ];
    }

    public function read_temp_file_meta( $file_name ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }

        if ( ! $this->filesystem->exists( $paths['meta'] ) ) {
            if ( $this->filesystem->exists( $paths['csv'] ) ) {
                return $this->rebuild_temp_file_meta( $paths['csv'], $paths['meta'] );
            }

            return new WP_Error( 'eim_temp_meta_missing', __( 'Temporary import metadata not found. Please upload the CSV again.', 'calliope-media-import-export' ) );
        }

        $raw_meta = $this->filesystem->get_contents( $paths['meta'], 'temp_meta_read_failed' );
        if ( is_wp_error( $raw_meta ) ) {
            return $this->wrap_filesystem_error( 'eim_temp_meta_unreadable', __( 'Temporary import metadata could not be read. Please upload the CSV again.', 'calliope-media-import-export' ), $raw_meta );
        }

        if ( '' === trim( (string) $raw_meta ) ) {
            return $this->rebuild_temp_file_meta( $paths['csv'], $paths['meta'] );
        }

        $meta = json_decode( $raw_meta, true );
        if ( ! is_array( $meta ) ) {
            return $this->rebuild_temp_file_meta( $paths['csv'], $paths['meta'] );
        }

        return $meta;
    }

    private function rebuild_temp_file_meta( $csv_path, $meta_path ) {
        if ( ! $this->filesystem->exists( $csv_path ) ) {
            return new WP_Error( 'eim_temp_meta_invalid', __( 'Temporary import metadata is invalid. Please upload the CSV again.', 'calliope-media-import-export' ) );
        }

        $inspection = $this->inspect_csv_file( $csv_path );
        if ( is_wp_error( $inspection ) ) {
            return new WP_Error( 'eim_temp_meta_invalid', __( 'Temporary import metadata is invalid. Please upload the CSV again.', 'calliope-media-import-export' ) );
        }

        $meta = [
            'delimiter'  => isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',',
            'total_rows' => isset( $inspection['total_rows'] ) ? absint( $inspection['total_rows'] ) : 0,
            'created_at' => time(),
            'created_by' => get_current_user_id(),
        ];

        $encoded_meta = wp_json_encode( $meta );
        if ( false === $encoded_meta ) {
            return new WP_Error( 'eim_temp_meta_invalid', __( 'Temporary import metadata is invalid. Please upload the CSV again.', 'calliope-media-import-export' ) );
        }

        $written = $this->filesystem->put_contents( $meta_path, $encoded_meta, 'temp_meta_rebuild_write_failed' );
        if ( is_wp_error( $written ) ) {
            return $this->wrap_filesystem_error( 'eim_temp_meta_rebuild_failed', __( 'Temporary import metadata could not be rebuilt. Please upload the CSV again.', 'calliope-media-import-export' ), $written );
        }

        return $meta;
    }

    public function cleanup_temp_import_file( $file_name ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return;
        }

        foreach ( [ 'csv', 'meta', 'progress' ] as $path_key ) {
            $this->filesystem->delete( $paths[ $path_key ], 'temp_import_cleanup_failed' );
        }
    }

    public function reset_import_progress_log( $file_name ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }

        $deleted = $this->filesystem->delete( $paths['progress'], 'temp_progress_reset_delete_failed' );
        if ( is_wp_error( $deleted ) ) {
            return $this->wrap_filesystem_error( 'eim_temp_progress_reset_failed', __( 'Could not reset the import progress log.', 'calliope-media-import-export' ), $deleted );
        }

        $written = $this->filesystem->put_contents( $paths['progress'], '', 'temp_progress_reset_write_failed' );
        if ( is_wp_error( $written ) ) {
            return $this->wrap_filesystem_error( 'eim_temp_progress_reset_failed', __( 'Could not reset the import progress log.', 'calliope-media-import-export' ), $written );
        }

        return true;
    }

    public function append_import_progress( $file_name, $result ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }

        if ( ! is_array( $result ) ) {
            return new WP_Error( 'eim_temp_progress_invalid', __( 'Invalid import progress entry.', 'calliope-media-import-export' ) );
        }

        $cursor = isset( $result['row_number'] ) ? absint( $result['row_number'] ) : 0;
        if ( $cursor <= 0 ) {
            return new WP_Error( 'eim_temp_progress_invalid', __( 'Invalid import progress cursor.', 'calliope-media-import-export' ) );
        }

        $entry = [
            'cursor'     => $cursor,
            'created_at' => time(),
            'result'     => $result,
        ];

        $encoded = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $encoded ) {
            return new WP_Error( 'eim_temp_progress_encode_failed', __( 'Could not encode the import progress entry.', 'calliope-media-import-export' ) );
        }

        $written = $this->filesystem->append_contents( $paths['progress'], $encoded . "\n", 'temp_progress_append_failed' );
        if ( is_wp_error( $written ) ) {
            return $this->wrap_filesystem_error( 'eim_temp_progress_write_failed', __( 'Could not write the import progress log.', 'calliope-media-import-export' ), $written );
        }

        return true;
    }

    private function get_temp_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'eim-temp/';
    }

    public function get_temp_lock_key( $file_name ) {
        return 'eim_import_lock_' . md5( (string) $file_name );
    }

    public function acquire_temp_lock( $lock_key, $ttl = null ) {
        $ttl      = null === $ttl ? self::LOCK_TTL : max( 30, absint( $ttl ) );
        $existing = get_transient( $lock_key );

        if ( $existing ) {
            $started_at = is_array( $existing ) && isset( $existing['started_at'] ) ? absint( $existing['started_at'] ) : absint( $existing );
            $age        = $started_at > 0 ? time() - $started_at : 0;

            if ( $started_at > 0 && $age > $ttl ) {
                delete_transient( $lock_key );
            } else {
                return false;
            }
        }

        return set_transient(
            $lock_key,
            [
                'started_at' => time(),
            ],
            $ttl
        );
    }

    public function release_temp_lock( $lock_key ) {
        delete_transient( $lock_key );
    }

    public function cleanup_temp_files() {
        $temp_dir = $this->get_temp_dir();
        if ( ! $this->filesystem->is_dir( $temp_dir ) ) {
            return;
        }

        $files = $this->filesystem->dirlist( $temp_dir, 'temp_cleanup_dirlist_failed' );
        if ( is_wp_error( $files ) ) {
            return;
        }

        $cutoff = time() - self::TEMP_FILE_TTL;
        foreach ( $files as $file_key => $details ) {
            $file = is_array( $details ) && ! empty( $details['name'] ) ? (string) $details['name'] : (string) $file_key;
            if ( in_array( $file, [ '.', '..', 'index.php', '.htaccess', 'web.config' ], true ) ) {
                continue;
            }

            $file_path = trailingslashit( $temp_dir ) . $file;
            if ( ! is_array( $details ) || ( isset( $details['type'] ) && 'f' !== $details['type'] ) ) {
                continue;
            }

            $last_modified = isset( $details['lastmodunix'] ) ? (int) $details['lastmodunix'] : $this->filesystem->mtime( $file_path, 'temp_cleanup_mtime_failed' );
            if ( is_wp_error( $last_modified ) ) {
                continue;
            }

            if ( false !== $last_modified && $last_modified < $cutoff ) {
                $this->filesystem->delete( $file_path, 'temp_cleanup_delete_failed' );
            }
        }
    }

    private function wrap_filesystem_error( $code, $message, $filesystem_error ) {
        $data = is_wp_error( $filesystem_error ) ? $filesystem_error->get_error_data() : [];
        return new WP_Error( $code, $message, is_array( $data ) ? $data : [] );
    }

}
