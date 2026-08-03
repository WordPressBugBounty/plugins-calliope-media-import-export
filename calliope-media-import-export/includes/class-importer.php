<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Importer {

    const MAX_BATCH_SIZE = 50;
    const TEMP_FILE_TTL  = DAY_IN_SECONDS;
    const LOCK_TTL       = 90;

    private $csv_reader;
    private $attachment_matcher;
    private $attachment_writer;
    private $svg_validator;
    private $temp_file_manager;
    private $filesystem;

    public function __construct( ?EIM_Filesystem $filesystem = null ) {
        $this->filesystem = $filesystem ? $filesystem : new EIM_Filesystem();

        $csv_translations = [
            'Could not read the uploaded CSV file.' => __( 'Could not read the uploaded CSV file.', 'calliope-media-import-export' ),
            'Could not open the uploaded CSV file.' => __( 'Could not open the uploaded CSV file.', 'calliope-media-import-export' ),
            'The CSV is empty.' => __( 'The CSV is empty.', 'calliope-media-import-export' ),
            'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.' => __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', 'calliope-media-import-export' ),
            'The CSV has headers but no data rows to import. Export or upload a CSV with at least one media row below the header.' => __( 'The CSV has headers but no data rows to import. Export or upload a CSV with at least one media row below the header.', 'calliope-media-import-export' ),
            'The selected file is a ZIP archive. The simple import tool expects a plain CSV file, not a ZIP or export bundle.' => __( 'The selected file is a ZIP archive. The simple import tool expects a plain CSV file, not a ZIP or export bundle.', 'calliope-media-import-export' ),
            'Semicolon (;)' => __( 'Semicolon (;)', 'calliope-media-import-export' ),
            'Tab' => __( 'Tab', 'calliope-media-import-export' ),
            'Pipe (|)' => __( 'Pipe (|)', 'calliope-media-import-export' ),
            'Comma (,)' => __( 'Comma (,)', 'calliope-media-import-export' ),
            'No importable media rows were found. The CSV may only contain headers, or your export filters may have matched no media items.' => __( 'No importable media rows were found. The CSV may only contain headers, or your export filters may have matched no media items.', 'calliope-media-import-export' ),
            'ID' => __( 'ID', 'calliope-media-import-export' ),
            'Absolute URL' => __( 'Absolute URL', 'calliope-media-import-export' ),
            'Relative Path' => __( 'Relative Path', 'calliope-media-import-export' ),
            'Title' => __( 'Title', 'calliope-media-import-export' ),
            'Alt Text' => __( 'Alt Text', 'calliope-media-import-export' ),
            'Caption' => __( 'Caption', 'calliope-media-import-export' ),
            'Description' => __( 'Description', 'calliope-media-import-export' ),
            'This CSV relies on Relative Path. Use Local Import Mode or make sure the referenced files already exist in uploads.' => __( 'This CSV relies on Relative Path. Use Local Import Mode or make sure the referenced files already exist in uploads.', 'calliope-media-import-export' ),
            ' Example rows: %s.' => __( ' Example rows: %s.', 'calliope-media-import-export' ),
            'Only source columns were detected. Media metadata fields will not be updated from this CSV.' => __( 'Only source columns were detected. Media metadata fields will not be updated from this CSV.', 'calliope-media-import-export' ),
        ];

        $this->csv_reader = new EIM_Csv_Reader(
            [
                'preview_limit' => max( 1, absint( eim_get_setting( 'import.preview_sample_limit', 5 ) ) ),
                'translator' => function( $message ) use ( $csv_translations ) {
                    return isset( $csv_translations[ $message ] ) ? $csv_translations[ $message ] : $message;
                },
                'plural_translator' => function( $single, $plural, $count ) {
                    if ( '%d row is missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.' === $single ) {
                        return _n(
                            '%d row is missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                            '%d rows are missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                            $count,
                            'calliope-media-import-export'
                        );
                    }

                    return 1 === (int) $count ? $single : $plural;
                },
                'definitions_filter' => function( $definitions ) {
                    return apply_filters( 'eim_import_header_definitions', $definitions );
                },
                'error_reporter' => function( $event, $context ) {
                    $this->log_import_event( $event, $context );
                },
            ]
        );

        $this->svg_validator      = new EIM_Svg_Import_Validator( $this->filesystem );
        $this->attachment_matcher = new EIM_Attachment_Matcher();
        $this->attachment_writer  = new EIM_Attachment_Writer(
            $this->attachment_matcher,
            $this->svg_validator,
            [
                'build_item_result' => function( $status, $file, $message, $context = [] ) {
                    return $this->build_item_result( $status, $file, $message, $context );
                },
                'build_import_action_context' => function( $request_context, $row = [], $extra = [] ) {
                    return $this->build_import_action_context( $request_context, $row, $extra );
                },
                'get_result_request_context' => function( $request_context ) {
                    return $this->get_result_request_context( $request_context );
                },
                'log_import_event' => function( $event, $context = [] ) {
                    return $this->log_import_event( $event, $context );
                },
            ],
            $this->filesystem
        );
        $this->attachment_matcher->set_file_fingerprint_resolver( [ $this->attachment_writer, 'get_file_fingerprint' ] );
        $this->temp_file_manager = new EIM_Temp_File_Manager(
            function( $file_path ) {
                return $this->inspect_csv_path( $file_path );
            },
            $this->filesystem
        );

        add_action( 'wp_ajax_eim_validate_csv', [ $this, 'validate_csv' ] );
        add_action( 'wp_ajax_eim_process_batch', [ $this, 'process_batch' ] );
        add_action( 'wp_ajax_eim_get_import_progress', [ $this, 'get_import_progress' ] );
        add_action( 'eim_daily_cleanup_event', [ $this->temp_file_manager, 'cleanup_temp_files' ] );
    }

    public static function activate_plugin() {
        $installed_at_option = defined( 'EIM_INSTALLED_AT_OPTION' ) ? EIM_INSTALLED_AT_OPTION : 'eim_installed_at';
        if ( false === get_option( $installed_at_option, false ) ) {
            add_option( $installed_at_option, time(), '', false );
        }

        if ( ! wp_next_scheduled( 'eim_daily_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'eim_daily_cleanup_event' );
        }
    }

    public static function deactivate_plugin() {
        wp_clear_scheduled_hook( 'eim_daily_cleanup_event' );
    }

    public function validate_csv() {
        $this->ensure_ajax_permissions();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        if ( empty( $_FILES['eim_csv'] ) || empty( $_FILES['eim_csv']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'calliope-media-import-export' ) ], 400 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        $upload_error = isset( $_FILES['eim_csv']['error'] ) ? absint( $_FILES['eim_csv']['error'] ) : UPLOAD_ERR_OK;
        if ( UPLOAD_ERR_OK !== $upload_error ) {
            wp_send_json_error( [ 'message' => $this->get_upload_error_message( $upload_error ) ], 400 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP generates uploaded temp paths; sanitizing or unslashing can corrupt Windows paths.
        $tmp_name = is_string( $_FILES['eim_csv']['tmp_name'] ) ? $_FILES['eim_csv']['tmp_name'] : '';
        if ( ! is_string( $tmp_name ) || '' === $tmp_name || ( ! is_uploaded_file( $tmp_name ) && ! file_exists( $tmp_name ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Error uploading file.', 'calliope-media-import-export' ) ], 400 );
        }

        $inspection = $this->inspect_csv_path( $tmp_name );
        if ( is_wp_error( $inspection ) ) {
            wp_send_json_error( [ 'message' => $inspection->get_error_message() ], 400 );
        }

        $temp_file = $this->create_temp_import_file( $tmp_name, $inspection );
        if ( is_wp_error( $temp_file ) ) {
            wp_send_json_error( [ 'message' => $temp_file->get_error_message() ], 500 );
        }

        wp_send_json_success(
            [
                'file'       => $temp_file['file'],
                'total_rows' => $inspection['total_rows'],
                'preview'    => $this->build_validation_preview( $inspection ),
            ]
        );
    }

    public function get_import_progress() {
        $this->ensure_ajax_permissions();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX request is verified in ensure_ajax_permissions().
        $file_name = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
        $after     = $this->get_request_absint( 'after' );
        $paths     = $this->get_temp_file_paths( $file_name );

        if ( is_wp_error( $paths ) ) {
            wp_send_json_error( [ 'message' => $paths->get_error_message() ], 400 );
        }

        if ( ! file_exists( $paths['progress'] ) ) {
            wp_send_json_success(
                [
                    'entries'       => [],
                    'latest_cursor' => $after,
                ]
            );
        }

        $entries       = [];
        $latest_cursor = $after;
        $handle        = $this->open_read_handle( $paths['progress'] );

        if ( $handle ) {
            while ( ! feof( $handle ) ) {
                $line = fgets( $handle );
                if ( false === $line || '' === trim( $line ) ) {
                    continue;
                }

                $entry = json_decode( $line, true );
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $cursor = isset( $entry['cursor'] ) ? absint( $entry['cursor'] ) : 0;
                if ( $cursor <= 0 ) {
                    continue;
                }

                $latest_cursor = max( $latest_cursor, $cursor );
                if ( $cursor <= $after ) {
                    continue;
                }

                $entries[] = [
                    'cursor' => $cursor,
                    'result' => isset( $entry['result'] ) && is_array( $entry['result'] ) ? $entry['result'] : [],
                ];

                if ( count( $entries ) >= 100 ) {
                    break;
                }
            }

            $this->close_file_handle( $handle );
        }

        wp_send_json_success(
            [
                'entries'       => $entries,
                'latest_cursor' => $latest_cursor,
            ]
        );
    }

    private function get_upload_error_message( $upload_error ) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the server upload limit.', 'calliope-media-import-export' ),
            UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the form upload limit.', 'calliope-media-import-export' ),
            UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', 'calliope-media-import-export' ),
            UPLOAD_ERR_NO_FILE    => __( 'No file uploaded.', 'calliope-media-import-export' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'The server temporary upload folder is missing.', 'calliope-media-import-export' ),
            UPLOAD_ERR_CANT_WRITE => __( 'The uploaded file could not be written to disk.', 'calliope-media-import-export' ),
            UPLOAD_ERR_EXTENSION  => __( 'A server extension stopped the file upload.', 'calliope-media-import-export' ),
        ];

        return isset( $messages[ $upload_error ] ) ? $messages[ $upload_error ] : __( 'Error uploading file.', 'calliope-media-import-export' );
    }

    public function process_batch() {
        $this->ensure_ajax_permissions();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX request is verified in ensure_ajax_permissions().
        $file_name       = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
        $start_row       = $this->get_request_absint( 'start_row' );
        $batch_size      = $this->get_bounded_batch_size();
        $time_limit      = $this->get_batch_time_limit( $batch_size );
        $start_time      = time();
        $local_import    = $this->get_request_bool( 'local_import' );
        $skip_thumbnails = $this->get_request_bool( 'skip_thumbnails' );
        $honor_rel_path  = $this->get_request_bool( 'honor_relative_path', true );
        $results         = [];
        $handle          = null;
        $thumbs_disabled = false;
        $error_message   = '';
        $lock_key        = $this->get_temp_lock_key( $file_name );
        $batch_summary   = $this->get_empty_result_summary();
        $processed_batch = 0;
        $next_row        = $start_row;
        $total_rows      = 0;
        $is_finished     = false;
        $reached_eof     = false;
        $time_limited    = false;
        $request_context = $this->normalize_import_request_context(
            [
                'start_row'           => $start_row,
                'batch_size'          => $batch_size,
                'local_import'        => $local_import,
                'skip_thumbnails'     => $skip_thumbnails,
                'honor_relative_path' => $honor_rel_path,
                'dry_run'             => $this->get_request_bool( 'dry_run' ),
                'duplicate_strategy'  => $this->get_request_string( 'duplicate_strategy', 'skip' ),
                'match_strategy'      => $this->get_request_string( 'match_strategy', 'auto' ),
                'selected_update_fields' => $this->get_request_array( 'selected_update_fields' ),
                'pro_history_id'      => $this->get_request_absint( 'pro_history_id' ),
                'pro_job_id'          => $this->get_request_absint( 'pro_job_id' ),
                'convert_images_format' => $this->get_request_string( 'convert_images_format', 'keep' ),
                'conversion_quality'  => $this->get_request_absint( 'conversion_quality' ),
                'conversion_failure_behavior' => $this->get_request_string( 'conversion_failure_behavior', 'keep_original' ),
                'source'              => 'ajax',
                'file'                => $file_name,
            ]
        );

        $batch_size      = $request_context['batch_size'];
        $time_limit      = $this->get_batch_time_limit_for_context( $this->get_batch_time_limit( $batch_size ), $request_context );
        $this->extend_server_time_limit( $time_limit );
        $local_import    = $request_context['local_import'];
        $skip_thumbnails = $request_context['skip_thumbnails'];
        $honor_rel_path  = $request_context['honor_relative_path'];

        $this->log_import_event(
            'batch_start',
            [
                'file'                => $file_name,
                'start_row'           => $start_row,
                'batch_size'          => $batch_size,
                'time_limit'          => $time_limit,
                'download_timeout'    => $this->get_download_timeout( $request_context ),
                'local_import'        => $local_import,
                'skip_thumbnails'     => $skip_thumbnails,
                'honor_relative_path' => $honor_rel_path,
                'dry_run'             => ! empty( $request_context['dry_run'] ),
                'source'              => 'ajax',
            ]
        );

        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            $this->send_batch_error( $paths->get_error_message(), 400 );
        }

        if ( 0 === $start_row ) {
            $progress_reset = $this->reset_import_progress_log( $file_name );
            if ( is_wp_error( $progress_reset ) ) {
                $this->send_batch_error( $progress_reset->get_error_message(), 500 );
            }
        }

        if ( ! file_exists( $paths['csv'] ) ) {
            $this->send_batch_error( __( 'Temporary file not found. Please upload the CSV again.', 'calliope-media-import-export' ), 404 );
        }

        if ( ! $this->acquire_temp_lock( $lock_key, $this->get_lock_ttl( $time_limit ) ) ) {
            $this->send_batch_error( __( 'Another import request is already processing this file. Please wait a moment and try again.', 'calliope-media-import-export' ), 409 );
        }

        try {
            $meta = $this->read_temp_file_meta( $file_name );
            if ( is_wp_error( $meta ) ) {
                throw new RuntimeException( $meta->get_error_message() );
            }

            $delimiter  = isset( $meta['delimiter'] ) ? (string) $meta['delimiter'] : ',';
            $total_rows = isset( $meta['total_rows'] ) ? absint( $meta['total_rows'] ) : 0;

            if ( $total_rows > 0 && $start_row >= $total_rows ) {
                $this->cleanup_temp_import_file( $file_name );
                $results[]    = [ 'status' => 'FINISHED' ];
                $is_finished  = true;
                $reached_eof  = true;
            }

            if ( empty( $results ) ) {
                $handle = $this->open_read_handle( $paths['csv'] );
                if ( ! $handle ) {
                    throw new RuntimeException( __( 'Could not open the temporary CSV file.', 'calliope-media-import-export' ) );
                }

                $headers = $this->read_csv_row( $handle, $delimiter, true );
                if ( false === $headers ) {
                    throw new RuntimeException( __( 'Could not read the CSV headers.', 'calliope-media-import-export' ) );
                }

                $header_map = $this->map_headers( $headers );
                if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
                    throw new RuntimeException( __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', 'calliope-media-import-export' ) );
                }

                $skipped_rows = 0;
                while ( $skipped_rows < $start_row ) {
                    $row_data = $this->read_csv_row( $handle, $delimiter );
                    if ( false === $row_data ) {
                        $reached_eof = true;
                        break;
                    }

                    if ( $this->is_csv_row_empty( $row_data ) ) {
                        continue;
                    }

                    $skipped_rows++;
                }

                if ( $skip_thumbnails ) {
                    add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
                    $thumbs_disabled = true;
                }

                $current_row = $start_row;

                while ( $processed_batch < $batch_size ) {
                    if ( $this->should_stop_batch_before_next_row( $processed_batch, $start_time, $time_limit, $request_context ) ) {
                        $time_limited = true;
                        break;
                    }

                    $row_data = $this->read_csv_row( $handle, $delimiter );
                    if ( false === $row_data ) {
                        $reached_eof = true;
                        break;
                    }

                    if ( $this->is_csv_row_empty( $row_data ) ) {
                        continue;
                    }

                    $current_row++;
                    $row    = $this->build_row_from_csv( $row_data, $header_map );
                    $result = $this->process_single_item( $row, $local_import, $honor_rel_path, $request_context );

                    $result['row_number'] = $current_row;
                    if ( isset( $result['file'] ) ) {
                        $result['file'] = '#' . $current_row . ' - ' . $result['file'];
                    }

                    $progress_written = $this->append_import_progress( $file_name, $result );
                    if ( is_wp_error( $progress_written ) ) {
                        throw new RuntimeException( $progress_written->get_error_message() );
                    }

                    $results[]       = $result;
                    $batch_summary   = $this->increment_result_summary( $batch_summary, $result );
                    $processed_batch++;
                }

                $next_row    = $start_row + $processed_batch;
                $is_finished = ( $total_rows > 0 && $next_row >= $total_rows ) || $reached_eof;

                if ( $is_finished ) {
                    $this->cleanup_temp_import_file( $file_name );
                }
            }
        } catch ( Exception $exception ) {
            $error_message = $exception->getMessage();
            $this->log_import_event(
                'batch_exception',
                [
                    'file'      => $file_name,
                    'start_row' => $start_row,
                    'message'   => $error_message,
                ]
            );
        }

        if ( $thumbs_disabled ) {
            remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
        }

        if ( is_resource( $handle ) ) {
            fclose( $handle );
        }

        $this->release_temp_lock( $lock_key );

        if ( '' !== $error_message ) {
            $this->send_batch_error( $error_message );
        }

        $response = $this->build_batch_response(
            $results,
            $batch_summary,
            [
                'start_row'       => $start_row,
                'next_row'        => $next_row,
                'processed_rows'  => $processed_batch,
                'batch_size'      => $batch_size,
                'total_rows'      => $total_rows,
                'is_finished'     => $is_finished,
                'time_limited'    => $time_limited,
                'time_limit'      => $time_limit,
                'local_import'    => $local_import,
                'skip_thumbnails' => $skip_thumbnails,
                'honor_rel_path'  => $honor_rel_path,
                'dry_run'         => ! empty( $request_context['dry_run'] ),
                'duplicate_strategy' => $request_context['duplicate_strategy'],
                'pro_history_id'  => isset( $request_context['pro_history_id'] ) ? absint( $request_context['pro_history_id'] ) : 0,
                'pro_job_id'      => isset( $request_context['pro_job_id'] ) ? absint( $request_context['pro_job_id'] ) : 0,
                'convert_images_format' => isset( $request_context['convert_images_format'] ) ? (string) $request_context['convert_images_format'] : 'keep',
                'file'            => $file_name,
            ]
        );

        $this->log_import_event(
            'batch_finish',
            [
                'file'           => $file_name,
                'start_row'      => $start_row,
                'next_row'       => $next_row,
                'processed_rows' => $processed_batch,
                'summary'        => $batch_summary,
                'time_limited'   => $time_limited,
                'is_finished'    => $is_finished,
            ]
        );

        wp_send_json_success( $response );
    }

    public function inspect_csv_path( $file_path ) {
        return $this->inspect_csv_file( $file_path );
    }

    public function run_import_from_path( $file_path, $args = [] ) {
        $context = $this->normalize_import_request_context( $args );
        $time_limit = $this->get_batch_time_limit_for_context( $this->get_batch_time_limit( $context['batch_size'] ), $context );
        $this->extend_server_time_limit( $time_limit );

        if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'eim_import_file_missing', __( 'Import file not found.', 'calliope-media-import-export' ) );
        }

        $inspection = $this->inspect_csv_path( $file_path );
        if ( is_wp_error( $inspection ) ) {
            return $inspection;
        }

        $handle = $this->open_read_handle( $file_path );
        if ( ! $handle ) {
            return new WP_Error( 'eim_import_file_unreadable', __( 'Could not open the import file.', 'calliope-media-import-export' ) );
        }

        $results         = [];
        $summary         = $this->get_empty_result_summary();
        $processed_batch = 0;
        $reached_eof     = false;
        $is_finished     = false;
        $thumbs_disabled = false;
        $start_row       = $context['start_row'];
        $next_row        = $start_row;
        $total_rows      = isset( $inspection['total_rows'] ) ? absint( $inspection['total_rows'] ) : 0;
        $delimiter       = isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',';
        $start_time      = time();
        $time_limited    = false;

        try {
            $headers = $this->read_csv_row( $handle, $delimiter, true );
            if ( false === $headers ) {
                throw new RuntimeException( __( 'Could not read the CSV headers.', 'calliope-media-import-export' ) );
            }

            $header_map = $this->map_headers( $headers );
            if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
                throw new RuntimeException( __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', 'calliope-media-import-export' ) );
            }

            $skipped_rows = 0;
            while ( $skipped_rows < $start_row ) {
                $row_data = $this->read_csv_row( $handle, $delimiter );
                if ( false === $row_data ) {
                    $reached_eof = true;
                    break;
                }

                if ( $this->is_csv_row_empty( $row_data ) ) {
                    continue;
                }

                $skipped_rows++;
            }

            if ( $context['skip_thumbnails'] ) {
                add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
                $thumbs_disabled = true;
            }

            $current_row = $start_row;
            $max_rows    = $context['batch_size'];

            while ( 0 === $max_rows || $processed_batch < $max_rows ) {
                if ( $this->should_stop_batch_before_next_row( $processed_batch, $start_time, $time_limit, $context ) ) {
                    $time_limited = true;
                    break;
                }

                $row_data = $this->read_csv_row( $handle, $delimiter );
                if ( false === $row_data ) {
                    $reached_eof = true;
                    break;
                }

                if ( $this->is_csv_row_empty( $row_data ) ) {
                    continue;
                }

                $current_row++;
                $row    = $this->build_row_from_csv( $row_data, $header_map );
                $result = $this->process_single_item( $row, $context['local_import'], $context['honor_relative_path'], $context );

                $result['row_number'] = $current_row;
                if ( isset( $result['file'] ) ) {
                    $result['file'] = '#' . $current_row . ' - ' . $result['file'];
                }

                $results[]       = $result;
                $summary         = $this->increment_result_summary( $summary, $result );
                $processed_batch++;
            }

            $next_row    = $start_row + $processed_batch;
            $is_finished = ( $total_rows > 0 && $next_row >= $total_rows ) || $reached_eof;
        } catch ( Exception $exception ) {
            if ( $thumbs_disabled ) {
                remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
            }

            if ( is_resource( $handle ) ) {
                $this->close_file_handle( $handle );
            }

            return new WP_Error( 'eim_import_runtime_error', $exception->getMessage() );
        }

        if ( $thumbs_disabled ) {
            remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
        }

        if ( is_resource( $handle ) ) {
            $this->close_file_handle( $handle );
        }

        return $this->build_batch_response(
            $results,
            $summary,
            [
                'start_row'           => $start_row,
                'next_row'            => $next_row,
                'processed_rows'      => $processed_batch,
                'batch_size'          => $context['batch_size'],
                'total_rows'          => $total_rows,
                'is_finished'         => $is_finished,
                'time_limited'        => $time_limited,
                'time_limit'          => $time_limit,
                'local_import'        => $context['local_import'],
                'skip_thumbnails'     => $context['skip_thumbnails'],
                'honor_rel_path'      => $context['honor_relative_path'],
                'dry_run'             => ! empty( $context['dry_run'] ),
                'duplicate_strategy'  => $context['duplicate_strategy'],
                'pro_history_id'      => isset( $context['pro_history_id'] ) ? absint( $context['pro_history_id'] ) : 0,
                'pro_job_id'          => isset( $context['pro_job_id'] ) ? absint( $context['pro_job_id'] ) : 0,
                'convert_images_format' => isset( $context['convert_images_format'] ) ? (string) $context['convert_images_format'] : 'keep',
                'file'                => isset( $context['file'] ) ? (string) $context['file'] : wp_basename( $file_path ),
                'source'              => isset( $context['source'] ) ? (string) $context['source'] : 'programmatic',
            ]
        );
    }

    private function process_single_item( $row, $local_import, $honor_relative_path = true, $request_context = [] ) {
        $row = is_array( $row ) ? $row : [];
        $row = apply_filters(
            'eim_import_row_data',
            $row,
            [
                'local_import'        => (bool) $local_import,
                'honor_relative_path' => (bool) $honor_relative_path,
                'request_context'     => is_array( $request_context ) ? $request_context : [],
            ]
        );

        $url          = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
        $rel_path_raw = isset( $row['rel_path'] ) ? trim( (string) $row['rel_path'] ) : '';
        $rel_path     = $this->sanitize_relative_path( $rel_path_raw );
        $csv_id       = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

        $title       = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
        $alt         = isset( $row['alt'] ) ? sanitize_text_field( (string) $row['alt'] ) : '';
        $caption     = isset( $row['caption'] ) ? sanitize_text_field( (string) $row['caption'] ) : '';
        $description = isset( $row['description'] ) ? wp_kses_post( (string) $row['description'] ) : '';
        $custom_meta = $this->decode_custom_meta_json( isset( $row['custom_meta_json'] ) ? $row['custom_meta_json'] : '' );

        $filename        = isset( $row['file'] ) ? (string) $row['file'] : '';
        $filename        = $filename ? $filename : $this->derive_filename( $url, $rel_path );
        $filename        = $this->normalize_import_filename( $filename, $url, $rel_path );
        $url             = apply_filters( 'eim_pre_import_url', $url, $row );
        $request_context = is_array( $request_context ) ? $request_context : [];
        $advanced_actions_allowed = ! empty( $request_context['advanced_import_actions_allowed'] );
        $dry_run         = ! empty( $request_context['dry_run'] );
        $duplicate_strategy = $this->normalize_duplicate_strategy(
            isset( $request_context['duplicate_strategy'] ) ? $request_context['duplicate_strategy'] : 'skip',
            $advanced_actions_allowed
        );
        $match_strategy = $this->normalize_match_strategy(
            isset( $request_context['match_strategy'] ) ? $request_context['match_strategy'] : 'auto',
            $advanced_actions_allowed
        );
        $selected_update_fields = $advanced_actions_allowed
            ? $this->normalize_selected_update_fields(
                isset( $request_context['selected_update_fields'] ) ? $request_context['selected_update_fields'] : []
            )
            : [];
        $allows_match_without_source = $this->can_attempt_match_without_source( $csv_id, $filename, $match_strategy );
        $context  = [
            'local_import'        => (bool) $local_import,
            'honor_relative_path' => (bool) $honor_relative_path,
            'csv_id'              => $csv_id,
            'url'                 => $url,
            'relative_path'       => $rel_path,
            'filename'            => $filename,
            'dry_run'             => $dry_run,
            'duplicate_strategy'  => $duplicate_strategy,
            'match_strategy'      => $match_strategy,
            'selected_update_fields' => $selected_update_fields,
            'advanced_import_actions_allowed' => $advanced_actions_allowed,
            'custom_meta'         => $custom_meta,
            'request_context'     => $request_context,
        ];

        $this->log_import_event(
            'row_start',
            [
                'filename'            => $filename,
                'csv_id'              => $csv_id,
                'relative_path'       => $rel_path,
                'url'                 => $url,
                'local_import'        => (bool) $local_import,
                'honor_relative_path' => (bool) $honor_relative_path,
                'dry_run'             => $dry_run,
            ]
        );

        $validation = $this->validate_row_via_hooks( $row, $context );
        if ( is_wp_error( $validation ) ) {
            $this->log_import_event(
                'row_validation_error',
                [
                    'filename' => $filename,
                    'message'  => $validation->get_error_message(),
                ]
            );

            return $this->build_item_result(
                'ERROR',
                $filename,
                $validation->get_error_message(),
                [ 'reason' => 'custom_validation_failed' ]
            );
        }

        do_action( 'eim_before_import_media', $row, $context );

        if ( '' === $url && '' === $rel_path && ! $allows_match_without_source ) {
            $this->log_import_event(
                'row_missing_source',
                [
                    'filename' => $filename,
                    'csv_id'   => $csv_id,
                ]
            );

            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'Row is missing Absolute URL and Relative Path, and it does not provide a usable Attachment ID or Filename match.', 'calliope-media-import-export' ),
                [ 'reason' => 'missing_source' ]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $existing_id = 0;
        $deferred_duplicate_id = 0;
        $deferred_duplicate_reason = '';

        if ( 'filename' === $match_strategy && '' !== $filename ) {
            $filename_candidates = array_values(
                array_unique(
                    array_filter(
                        array_map( 'absint', $this->find_attachments_by_name_candidates( $filename, $rel_path ) )
                    )
                )
            );

            if ( count( $filename_candidates ) > 1 ) {
                return $this->build_item_result(
                    'ERROR',
                    $filename,
                    __( 'Filename matching is ambiguous because multiple existing media items share this filename. Add Relative Path or Attachment ID to target a single attachment.', 'calliope-media-import-export' ),
                    [
                        'reason' => 'ambiguous_filename_match',
                    ]
                );
            }

            if ( 1 === count( $filename_candidates ) ) {
                $existing_id = (int) $filename_candidates[0];
            }
        }

        if ( ! $existing_id ) {
            $existing_id = $this->find_existing_attachment_id( $url, $rel_path, null, $filename, '', $match_strategy );
        }

        if ( $existing_id ) {
            if ( 'replace_file' === $duplicate_strategy && ! $dry_run ) {
                $deferred_duplicate_id     = $existing_id;
                $deferred_duplicate_reason = 'duplicate_existing';
            } else {
                $duplicate_result = $this->resolve_duplicate_result(
                    $existing_id,
                    $duplicate_strategy,
                    $dry_run,
                    $filename,
                    $title,
                    $alt,
                    $caption,
                    $description,
                    $url,
                    $rel_path,
                    '',
                    '',
                    $custom_meta,
                    $row,
                    'duplicate_existing',
                    $request_context
                );
                if ( null !== $duplicate_result ) {
                    return $duplicate_result;
                }
            }
        }

        $id_match = $this->maybe_match_existing_attachment_by_csv_id( $csv_id, $url, $rel_path, $match_strategy );
        if ( $id_match ) {
            if ( 'replace_file' === $duplicate_strategy && ! $dry_run ) {
                $deferred_duplicate_id     = $id_match;
                $deferred_duplicate_reason = 'csv_id_match';
            } else {
                $duplicate_result = $this->resolve_duplicate_result(
                    $id_match,
                    $duplicate_strategy,
                    $dry_run,
                    $filename,
                    $title,
                    $alt,
                    $caption,
                    $description,
                    $url,
                    $rel_path,
                    '',
                    '',
                    $custom_meta,
                    $row,
                    'csv_id_match',
                    $request_context
                );
                if ( null !== $duplicate_result ) {
                    return $duplicate_result;
                }
            }
        }

        if ( '' === $url && '' === $rel_path ) {
            $message = in_array( $duplicate_strategy, [ 'replace_file', 'force_new' ], true )
                ? __( 'This import action requires Absolute URL or Relative Path when no existing media match is found.', 'calliope-media-import-export' )
                : __( 'No existing media matched the selected criteria, and the row does not include source data for a new import.', 'calliope-media-import-export' );

            return $this->build_item_result(
                'ERROR',
                $filename,
                $message,
                [ 'reason' => 'missing_source_after_match' ]
            );
        }

        if ( $local_import ) {
            $source_file = $this->resolve_uploads_file_from_source( $url, $rel_path );

            if ( '' === $rel_path && '' === $source_file ) {
                $this->log_import_event(
                    'local_import_missing_relative_path',
                    [
                        'filename' => $filename,
                        'url'      => $url,
                    ]
                );

                return $this->build_item_result(
                    'ERROR',
                    $filename,
                    __( 'Local Import Mode requires a valid "Relative Path" value.', 'calliope-media-import-export' ),
                    [ 'reason' => 'missing_relative_path' ]
                );
            }

            if ( '' === $source_file ) {
                $this->log_import_event(
                    'local_import_file_missing',
                    [
                        'filename'     => $filename,
                        'relative_path'=> $rel_path,
                        'url'          => $url,
                        'checked_path' => $this->build_uploads_candidate_path( $rel_path ),
                    ]
                );

                return $this->build_item_result(
                    'ERROR',
                    $filename,
                    __( 'Local file not found in uploads. Copy the media files into wp-content/uploads or use a reachable Absolute URL.', 'calliope-media-import-export' ),
                    [ 'reason' => 'local_file_missing' ]
                );
            }

            $this->log_import_event(
                'local_import_file_found',
                [
                    'filename'     => $filename,
                    'relative_path'=> $rel_path,
                    'source_file'  => $source_file,
                ]
            );

            if ( $dry_run ) {
                $validated = $this->validate_existing_media_file( $source_file, false );
                if ( is_wp_error( $validated ) ) {
                    $this->log_import_event(
                        'local_import_file_invalid_dry_run',
                        [
                            'filename' => $filename,
                            'message'  => $validated->get_error_message(),
                        ]
                    );

                    return $this->build_item_result(
                        'ERROR',
                        $filename,
                        $validated->get_error_message(),
                        [ 'reason' => 'local_file_invalid_dry_run' ]
                    );
                }

                return $this->build_item_result(
                    'READY',
                    $filename,
                    __( 'Dry run: local file is ready to import.', 'calliope-media-import-export' ),
                    [
                        'reason'        => 'dry_run_ready_local',
                        'import_method' => 'local',
                    ]
                );
            }

            return $this->attach_existing_media_file(
                $source_file,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $row,
                $request_context
            );
        }

        if ( '' === $url ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'Absolute URL is missing. Provide a URL or enable Local Import Mode for Relative Path imports.', 'calliope-media-import-export' ),
                [ 'reason' => 'missing_url' ]
            );
        }

        if ( ! wp_http_validate_url( $url ) ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'The "Absolute URL" value is not valid.', 'calliope-media-import-export' ),
                [ 'reason' => 'invalid_url' ]
            );
        }

        $existing_file = $this->resolve_uploads_file_from_source( $url, $honor_relative_path ? $rel_path : '' );

        if ( '' !== $existing_file ) {
            $this->log_import_event(
                'existing_upload_file_found',
                [
                    'filename'     => $filename,
                    'relative_path'=> $rel_path,
                    'url'          => $url,
                    'source_file'  => $existing_file,
                ]
            );

            if ( $dry_run ) {
                return $this->build_item_result(
                    'READY',
                    $filename,
                    __( 'Dry run: media would reuse the existing file from uploads.', 'calliope-media-import-export' ),
                    [
                        'reason'        => 'dry_run_ready_existing_upload',
                        'import_method' => 'local',
                    ]
                );
            }

            return $this->attach_existing_media_file(
                $existing_file,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $row,
                $request_context
            );
        }

        if ( $this->looks_like_local_upload_url( $url ) ) {
            $this->log_import_event(
                'local_upload_url_missing_file',
                [
                    'filename'     => $filename,
                    'relative_path'=> $rel_path,
                    'url'          => $url,
                    'checked_path' => $this->build_uploads_candidate_path( $rel_path ),
                ]
            );

            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'The URL points to this local uploads folder, but the file is missing on disk.', 'calliope-media-import-export' ),
                [ 'reason' => 'local_upload_url_file_missing' ]
            );
        }

        if ( $dry_run ) {
            return $this->build_item_result(
                'READY',
                $filename,
                __( 'Dry run: media appears ready to import.', 'calliope-media-import-export' ),
                [
                    'reason'        => 'dry_run_ready_remote',
                    'import_method' => 'remote',
                ]
            );
        }

        $download_timeout = $this->get_download_timeout( $request_context, $url );
        $download_start   = microtime( true );

        $this->log_import_event(
            'download_start',
            [
                'filename' => $filename,
                'url'      => $url,
                'timeout'  => $download_timeout,
            ]
        );

        $tmp_file = download_url( $url, $download_timeout );
        if ( is_wp_error( $tmp_file ) ) {
            $this->log_import_event(
                'download_error',
                [
                    'filename' => $filename,
                    'url'      => $url,
                    'timeout'  => $download_timeout,
                    'elapsed'  => round( microtime( true ) - $download_start, 3 ),
                    'message'  => $tmp_file->get_error_message(),
                ]
            );

            return $this->build_item_result(
                'ERROR',
                $filename,
                /* translators: %s: WordPress error message returned while downloading a remote file. */
                sprintf( __( 'Download error: %s', 'calliope-media-import-export' ), $tmp_file->get_error_message() ),
                [ 'reason' => 'download_error' ]
            );
        }

        $this->log_import_event(
            'download_success',
            [
                'filename' => $filename,
                'url'      => $url,
                'timeout'  => $download_timeout,
                'elapsed'  => round( microtime( true ) - $download_start, 3 ),
                'tmp_file' => $tmp_file,
            ]
        );

        $filename   = apply_filters( 'eim_import_filename', $filename, $row );
        $filename   = $this->normalize_import_filename( $filename, $url, $rel_path );
        $file_array = [
            'name'     => $filename ? $filename : 'media-file',
            'tmp_name' => $tmp_file,
        ];

        $svg_validation = $this->maybe_validate_svg_import_file( $tmp_file, $file_array['name'] );
        if ( is_wp_error( $svg_validation ) ) {
            if ( file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }

            return $this->build_item_result(
                'ERROR',
                $filename,
                $svg_validation->get_error_message(),
                [ 'reason' => 'svg_validation_failed' ]
            );
        }

        if ( is_string( $svg_validation ) ) {
            $svg_write = $this->write_sanitized_svg_file(
                $tmp_file,
                $svg_validation,
                'eim_svg_temp_write_failed',
                __( 'The uploaded file could not be written to disk.', 'calliope-media-import-export' )
            );

            if ( is_wp_error( $svg_write ) ) {
                if ( file_exists( $tmp_file ) ) {
                    wp_delete_file( $tmp_file );
                }

                return $this->build_item_result(
                    'ERROR',
                    $filename,
                    $svg_write->get_error_message(),
                    [ 'reason' => 'svg_sanitization_failed' ]
                );
            }
        }

        $fingerprint = $this->get_file_fingerprint( $tmp_file );

        if ( $deferred_duplicate_id ) {
            $duplicate_result = $this->resolve_duplicate_result(
                $deferred_duplicate_id,
                $duplicate_strategy,
                $dry_run,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $fingerprint,
                $tmp_file,
                $custom_meta,
                $row,
                $deferred_duplicate_reason ? $deferred_duplicate_reason : 'duplicate_existing',
                $request_context
            );

            if ( null !== $duplicate_result ) {
                if ( file_exists( $tmp_file ) ) {
                    wp_delete_file( $tmp_file );
                }

                return $duplicate_result;
            }
        }

        $existing_id = $this->find_existing_attachment_id( $url, $rel_path, $tmp_file, $filename, $fingerprint, $match_strategy );

        if ( $existing_id ) {
            $duplicate_result = $this->resolve_duplicate_result(
                $existing_id,
                $duplicate_strategy,
                $dry_run,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $fingerprint,
                $tmp_file,
                $custom_meta,
                $row,
                'duplicate_existing',
                $request_context
            );
            if ( null !== $duplicate_result ) {
                if ( file_exists( $tmp_file ) ) {
                    wp_delete_file( $tmp_file );
                }

                return $duplicate_result;
            }
        }

        $subdir = '';
        if ( $honor_relative_path && '' !== $rel_path ) {
            $dir = dirname( $rel_path );
            if ( $dir && '.' !== $dir ) {
                $subdir = '/' . trim( $dir, '/' );
            }
        }

        $id = $this->media_handle_sideload_with_subdir( $file_array, $subdir );
        if ( is_wp_error( $id ) ) {
            if ( file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }

            return $this->build_item_result(
                'ERROR',
                $filename,
                $id->get_error_message(),
                [ 'reason' => 'media_handle_error' ]
            );
        }

        wp_update_post(
            [
                'ID'           => $id,
                'post_title'   => $title ? $title : $filename,
                'post_excerpt' => $caption,
                'post_content' => $description,
            ]
        );

        $mime = get_post_mime_type( $id );
        if ( $alt && $mime && 0 === strpos( $mime, 'image/' ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', $alt );
        }

        $this->apply_custom_meta( $id, $custom_meta );
        $this->store_source_meta( $id, $url, $rel_path );
        if ( $fingerprint ) {
            $this->store_fingerprint_meta( $id, $fingerprint );
        }

        do_action( 'eim_after_import_image', $id, $row );
        do_action( 'eim_after_import_media', $id, $row );
        do_action( 'eim_after_import_media_with_context', $id, $row, $this->build_import_action_context( $request_context, $row, [ 'attachment_id' => (int) $id, 'action' => 'new_attachment' ] ) );

        return $this->build_item_result(
            'IMPORTED',
            $filename,
            /* translators: %d: attachment ID. */
            sprintf( __( 'Imported successfully (ID %d)', 'calliope-media-import-export' ), (int) $id ),
            [
                'reason'        => 'imported',
                'attachment_id' => (int) $id,
                'import_method' => 'remote',
                'request_context' => $this->get_result_request_context( $request_context ),
            ]
        );
    }

    private function attach_existing_media_file( $file_path, $filename, $title, $alt, $caption, $description, $url, $rel_path, $row, $request_context = [] ) {
        return call_user_func_array( [ $this->attachment_writer, 'attach_existing_media_file' ], func_get_args() );
    }

    private function find_existing_attachment_id( $url, $rel_path, $incoming_file_path = null, $filename = '', $incoming_fingerprint = '', $match_strategy = 'auto' ) {
        return call_user_func_array( [ $this->attachment_matcher, 'find_existing_attachment_id' ], func_get_args() );
    }

    private function find_attachments_by_name_candidates( $filename, $rel_path = '' ) {
        return call_user_func_array( [ $this->attachment_matcher, 'find_attachments_by_name_candidates' ], func_get_args() );
    }

    private function maybe_match_existing_attachment_by_csv_id( $csv_id, $url, $rel_path, $match_strategy = 'auto' ) {
        return call_user_func_array( [ $this->attachment_matcher, 'maybe_match_existing_attachment_by_csv_id' ], func_get_args() );
    }

    private function resolve_duplicate_result( $attachment_id, $strategy, $dry_run, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $incoming_file_path, $custom_meta, $row, $reason, $request_context = [] ) {
        return call_user_func_array( [ $this->attachment_writer, 'resolve_duplicate_result' ], func_get_args() );
    }

    private function apply_custom_meta( $attachment_id, $custom_meta ) {
        return call_user_func_array( [ $this->attachment_writer, 'apply_custom_meta' ], func_get_args() );
    }

    private function decode_custom_meta_json( $value ) {
        return call_user_func_array( [ $this->attachment_writer, 'decode_custom_meta_json' ], func_get_args() );
    }

    private function store_source_meta( $attachment_id, $url, $rel_path ) {
        return call_user_func_array( [ $this->attachment_writer, 'store_source_meta' ], func_get_args() );
    }

    private function store_fingerprint_meta( $attachment_id, $fingerprint ) {
        return call_user_func_array( [ $this->attachment_writer, 'store_fingerprint_meta' ], func_get_args() );
    }

    private function get_file_fingerprint( $file_path ) {
        return call_user_func_array( [ $this->attachment_writer, 'get_file_fingerprint' ], func_get_args() );
    }

    private function normalize_import_filename( $filename, $url = '', $rel_path = '' ) {
        return call_user_func_array( [ $this->attachment_writer, 'normalize_import_filename' ], func_get_args() );
    }

    private function sanitize_relative_path( $rel_path ) {
        return call_user_func_array( [ $this->attachment_writer, 'sanitize_relative_path' ], func_get_args() );
    }

    private function resolve_uploads_file_from_source( $url = '', $rel_path = '' ) {
        return call_user_func_array( [ $this->attachment_writer, 'resolve_uploads_file_from_source' ], func_get_args() );
    }

    private function build_uploads_candidate_path( $rel_path ) {
        return call_user_func_array( [ $this->attachment_writer, 'build_uploads_candidate_path' ], func_get_args() );
    }

    private function looks_like_local_upload_url( $url ) {
        return call_user_func_array( [ $this->attachment_writer, 'looks_like_local_upload_url' ], func_get_args() );
    }

    private function maybe_validate_svg_import_file( $file_path, $filename = '' ) {
        return call_user_func_array( [ $this->svg_validator, 'maybe_validate_svg_import_file' ], func_get_args() );
    }

    private function write_sanitized_svg_file( $file_path, $contents, $error_code, $error_message ) {
        return call_user_func_array( [ $this->svg_validator, 'write_sanitized_svg_file' ], func_get_args() );
    }

    private function media_handle_sideload_with_subdir( $file_array, $subdir = '' ) {
        return call_user_func_array( [ $this->attachment_writer, 'media_handle_sideload_with_subdir' ], func_get_args() );
    }

    private function map_headers( $headers ) {
        return call_user_func_array( [ $this->csv_reader, 'map_headers' ], func_get_args() );
    }

    private function build_row_from_csv( $row_data, $header_map ) {
        return call_user_func_array( [ $this->csv_reader, 'build_row_from_csv' ], func_get_args() );
    }

    private function ensure_ajax_permissions() {
        check_ajax_referer( 'eim_import_nonce', 'nonce' );

        if ( ! eim_current_user_can_manage() ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'calliope-media-import-export' ) ], 403 );
        }
    }

    private function normalize_import_request_context( $args = [] ) {
        $defaults = [
            'start_row'           => 0,
            'batch_size'          => (int) eim_get_setting( 'import.default_batch_size', 25 ),
            'local_import'        => false,
            'skip_thumbnails'     => false,
            'honor_relative_path' => true,
            'dry_run'             => false,
            'duplicate_strategy'  => 'skip',
            'match_strategy'      => 'auto',
            'selected_update_fields' => [],
            'pro_history_id'      => 0,
            'pro_job_id'          => 0,
            'convert_images_format' => 'keep',
            'conversion_quality'  => 82,
            'conversion_failure_behavior' => 'keep_original',
            'source'              => 'runtime',
            'file'                => '',
        ];

        $context = wp_parse_args( is_array( $args ) ? $args : [], $defaults );
        $context['start_row']           = max( 0, absint( $context['start_row'] ) );
        $context['batch_size']          = isset( $context['batch_size'] ) ? absint( $context['batch_size'] ) : 0;
        $context['batch_size']          = $context['batch_size'] > self::MAX_BATCH_SIZE ? self::MAX_BATCH_SIZE : $context['batch_size'];
        $context['source']              = sanitize_key( (string) $context['source'] );
        $context['file']                = sanitize_file_name( (string) $context['file'] );
        $context['advanced_import_actions_allowed'] = $this->advanced_import_actions_allowed( $context, $args );
        $context['local_import']        = ! empty( $context['local_import'] );
        $context['skip_thumbnails']     = ! empty( $context['skip_thumbnails'] );
        $context['honor_relative_path'] = ! isset( $context['honor_relative_path'] ) || ! empty( $context['honor_relative_path'] );
        $context['dry_run']             = ! empty( $context['dry_run'] ) && ! empty( $context['advanced_import_actions_allowed'] );
        $context['duplicate_strategy']  = $this->normalize_duplicate_strategy( $context['duplicate_strategy'], ! empty( $context['advanced_import_actions_allowed'] ) );
        $context['match_strategy']      = $this->normalize_match_strategy( $context['match_strategy'], ! empty( $context['advanced_import_actions_allowed'] ) );
        $context['selected_update_fields'] = ! empty( $context['advanced_import_actions_allowed'] )
            ? $this->normalize_selected_update_fields( $context['selected_update_fields'] )
            : [];
        $context['pro_history_id'] = ! empty( $context['advanced_import_actions_allowed'] ) ? absint( $context['pro_history_id'] ) : 0;
        $context['pro_job_id']     = ! empty( $context['advanced_import_actions_allowed'] ) ? absint( $context['pro_job_id'] ) : 0;

        $conversion_format = sanitize_key( (string) $context['convert_images_format'] );
        $context['convert_images_format'] = ( ! empty( $context['advanced_import_actions_allowed'] ) && in_array( $conversion_format, [ 'keep', 'webp', 'avif' ], true ) )
            ? $conversion_format
            : 'keep';
        $quality = isset( $context['conversion_quality'] ) ? absint( $context['conversion_quality'] ) : 82;
        $context['conversion_quality'] = min( 100, max( 1, $quality ? $quality : 82 ) );
        $failure_behavior = sanitize_key( (string) $context['conversion_failure_behavior'] );
        $context['conversion_failure_behavior'] = ( ! empty( $context['advanced_import_actions_allowed'] ) && in_array( $failure_behavior, [ 'keep_original', 'fail_row' ], true ) )
            ? $failure_behavior
            : 'keep_original';

        $context['batch_size'] = $this->get_safe_batch_size_for_context( $context );

        return apply_filters( 'eim_import_request_context', $context, $args );
    }

    private function get_safe_batch_size_for_context( $context ) {
        $batch_size = isset( $context['batch_size'] ) ? absint( $context['batch_size'] ) : 0;
        if ( $batch_size <= 0 ) {
            $batch_size = absint( eim_get_setting( 'import.default_batch_size', 25 ) );
        }

        $batch_size = min( self::MAX_BATCH_SIZE, max( 1, $batch_size ) );
        $safe_limit = self::MAX_BATCH_SIZE;

        if ( ! empty( $context['advanced_import_actions_allowed'] ) ) {
            $strategy = isset( $context['duplicate_strategy'] ) ? sanitize_key( (string) $context['duplicate_strategy'] ) : 'skip';
            $format   = isset( $context['convert_images_format'] ) ? sanitize_key( (string) $context['convert_images_format'] ) : 'keep';

            if ( 'avif' === $format ) {
                $safe_limit = 1;
            } elseif ( 'webp' === $format ) {
                $safe_limit = 5;
            } elseif ( 'replace_file' === $strategy ) {
                $safe_limit = 10;
            } elseif ( 'skip' !== $strategy ) {
                $safe_limit = 15;
            }
        }

        return min( $batch_size, $safe_limit );
    }

    private function advanced_import_actions_allowed( $context, $args = [] ) {
        return (bool) apply_filters(
            'eim_allow_advanced_import_actions',
            false,
            is_array( $context ) ? $context : [],
            is_array( $args ) ? $args : []
        );
    }

    private function normalize_duplicate_strategy( $strategy, $allow_advanced = true ) {
        return call_user_func_array( [ $this->attachment_matcher, 'normalize_duplicate_strategy' ], func_get_args() );
    }

    private function normalize_match_strategy( $strategy, $allow_advanced = true ) {
        return call_user_func_array( [ $this->attachment_matcher, 'normalize_match_strategy' ], func_get_args() );
    }

    private function normalize_selected_update_fields( $fields ) {
        return call_user_func_array( [ $this->attachment_writer, 'normalize_selected_update_fields' ], func_get_args() );
    }

    private function can_attempt_match_without_source( $csv_id, $filename, $match_strategy ) {
        $match_strategy = $this->normalize_match_strategy( $match_strategy );

        if ( 'attachment_id' === $match_strategy && absint( $csv_id ) ) {
            return true;
        }

        if ( 'filename' === $match_strategy && '' !== trim( (string) $filename ) ) {
            return true;
        }

        return false;
    }

    private function get_request_array( $key ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AJAX request is verified in ensure_ajax_permissions(); $key is an internal field name, not user-provided input.
        if ( ! isset( $_POST[ $key ] ) ) {
            return [];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified in ensure_ajax_permissions(); array contents are sanitized below.
        $value = wp_unslash( $_POST[ $key ] );

        if ( is_array( $value ) ) {
            return array_map( 'sanitize_key', $value );
        }

        return array_map( 'sanitize_key', explode( ',', (string) $value ) );
    }

    private function get_request_bool( $key, $default = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        if ( ! isset( $_POST[ $key ] ) ) {
            return (bool) $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX request is verified in ensure_ajax_permissions().
        $value = filter_var( wp_unslash( $_POST[ $key ] ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        if ( null === $value ) {
            return (bool) $default;
        }

        return (bool) $value;
    }

    private function get_request_absint( $key ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        if ( ! isset( $_POST[ $key ] ) ) {
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX request is verified in ensure_ajax_permissions().
        return absint( wp_unslash( $_POST[ $key ] ) );
    }

    private function get_request_string( $key, $default = '' ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        if ( ! isset( $_POST[ $key ] ) ) {
            return (string) $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in ensure_ajax_permissions().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AJAX request is verified in ensure_ajax_permissions() and sanitized here.
        return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
    }

    private function get_bounded_batch_size() {
        $batch_size = $this->get_request_absint( 'batch_size' );
        if ( $batch_size <= 0 ) {
            $batch_size = absint( eim_get_setting( 'import.default_batch_size', 25 ) );
        }

        return min( self::MAX_BATCH_SIZE, max( 1, $batch_size ) );
    }

    private function get_batch_time_limit( $batch_size ) {
        $batch_size = absint( $batch_size );

        if ( $batch_size >= 100 ) {
            $time_limit = 70;
        } elseif ( $batch_size >= 50 ) {
            $time_limit = 45;
        } elseif ( $batch_size >= 25 ) {
            $time_limit = 30;
        } else {
            $time_limit = 18;
        }

        $server_limit = $this->get_server_execution_limit();
        if ( $server_limit > 0 && ! $this->can_extend_server_time_limit() ) {
            $safe_limit = max( 8, $server_limit - 5 );
            $time_limit = min( $time_limit, $safe_limit );
        }

        /**
         * Filters the soft time limit, in seconds, for a single AJAX import batch.
         *
         * Return 0 to disable the plugin's soft limit and let PHP/server limits decide.
         *
         * @param int $time_limit Soft time limit in seconds.
         * @param int $batch_size Requested rows per batch.
         */
        return max( 0, absint( apply_filters( 'eim_import_batch_time_limit', $time_limit, $batch_size ) ) );
    }

    private function get_batch_time_limit_for_context( $time_limit, $context ) {
        $context    = is_array( $context ) ? $context : [];
        $time_limit = max( 0, absint( $time_limit ) );
        $batch_size = isset( $context['batch_size'] ) ? absint( $context['batch_size'] ) : 0;

        if ( $this->can_extend_server_time_limit() ) {
            if ( $batch_size >= 50 ) {
                $time_limit = max( $time_limit, 180 );
            } elseif ( $batch_size >= 25 ) {
                $time_limit = max( $time_limit, 120 );
            } else {
                $time_limit = max( $time_limit, 60 );
            }
        }

        /**
         * Filters the soft time limit, in seconds, for a single import batch after
         * the full request context is known.
         *
         * @param int   $time_limit Soft time limit in seconds.
         * @param array $context    Normalized import request context.
         */
        return max( 0, absint( apply_filters( 'eim_import_batch_time_limit_for_context', $time_limit, $context ) ) );
    }

    private function should_stop_batch_before_next_row( $processed_batch, $start_time, $time_limit, $context ) {
        $processed_batch = absint( $processed_batch );
        $time_limit      = absint( $time_limit );

        if ( $processed_batch <= 0 || $time_limit <= 0 ) {
            return false;
        }

        $elapsed = max( 0, time() - absint( $start_time ) );
        $guard   = max( 3, min( 12, $this->get_download_timeout( $context ) + 2 ) );

        return $elapsed >= max( 1, $time_limit - $guard );
    }

    private function get_download_timeout( $context = [], $url = '' ) {
        $context = is_array( $context ) ? $context : [];
        $timeout = 5;

        if ( ! empty( $context['local_import'] ) ) {
            $timeout = 3;
        }

        if ( $this->looks_like_local_upload_url( $url ) ) {
            $timeout = 2;
        }

        /**
         * Filters the HTTP timeout, in seconds, used to download a single remote
         * media file during CSV import.
         *
         * @param int   $timeout Timeout in seconds.
         * @param array $context Normalized import request context.
         */
        return max( 1, min( 30, absint( apply_filters( 'eim_import_download_timeout', $timeout, $context, $url ) ) ) );
    }

    private function can_extend_server_time_limit() {
        if ( ! function_exists( 'set_time_limit' ) ) {
            return false;
        }

        $disabled_functions = (string) ini_get( 'disable_functions' );
        if ( '' === $disabled_functions ) {
            return true;
        }

        $disabled_functions = array_map( 'trim', explode( ',', strtolower( $disabled_functions ) ) );
        return ! in_array( 'set_time_limit', $disabled_functions, true );
    }

    private function get_server_execution_limit() {
        $limit = ini_get( 'max_execution_time' );
        if ( false === $limit || '' === $limit ) {
            return 0;
        }

        $limit = absint( $limit );
        return $limit > 0 ? $limit : 0;
    }

    private function extend_server_time_limit( $time_limit ) {
        if ( ! function_exists( 'set_time_limit' ) ) {
            return;
        }

        $time_limit = absint( $time_limit );
        if ( $time_limit <= 0 ) {
            return;
        }

        $target_limit = max( 120, $time_limit + 60 );

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Import batches need a little extra time when the host allows it.
        @set_time_limit( $target_limit );
    }

    private function get_lock_ttl( $time_limit ) {
        $time_limit = absint( $time_limit );
        if ( $time_limit <= 0 ) {
            return self::LOCK_TTL;
        }

        return max( self::LOCK_TTL, $time_limit + 45 );
    }

    private function log_import_event( $event, $context = [] ) {
        $event   = sanitize_key( (string) $event );
        $context = is_array( $context ) ? $context : [];

        if ( '' === $event ) {
            $event = 'event';
        }

        $encoded = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $encoded ) {
            $encoded = '{}';
        }

        error_log( '[EIM_IMPORT] ' . $event . ' | ' . $encoded );
    }

    private function send_batch_error( $message, $status_code = 400 ) {
        $this->log_import_event(
            'batch_error_response',
            [
                'status_code' => absint( $status_code ),
                'message'     => (string) $message,
            ]
        );

        wp_send_json_error(
            [
                'message' => $message,
                'results' => [
                    [
                        'status'  => 'ERROR',
                        'file'    => __( 'System', 'calliope-media-import-export' ),
                        'message' => $message,
                    ],
                ],
            ],
            $status_code
        );
    }

    private function build_validation_preview( $inspection ) {
        $preview = $this->csv_reader->build_validation_preview( $inspection );
        return apply_filters( 'eim_import_preview_data', $preview, $inspection );
    }
    private function get_empty_result_summary() {
        return [
            'processed'   => 0,
            'imported'    => 0,
            'skipped'     => 0,
            'errors'      => 0,
            'processable' => 0,
            'duplicates'  => 0,
            'updated'     => 0,
            'restore_points_created' => 0,
            'converted_images'       => 0,
            'conversion_warnings'    => 0,
            'conversion_errors'      => 0,
            'rollback_restored'      => 0,
            'rollback_failures'      => 0,
        ];
    }

    private function increment_result_summary( $summary, $result ) {
        if ( ! is_array( $summary ) ) {
            $summary = $this->get_empty_result_summary();
        }

        $summary['processed']++;

        $status = isset( $result['status'] ) ? strtoupper( (string) $result['status'] ) : '';
        $reason = isset( $result['context']['reason'] ) ? (string) $result['context']['reason'] : '';
        if ( 'IMPORTED' === $status ) {
            $summary['imported']++;
        } elseif ( 'SKIPPED' === $status ) {
            $summary['skipped']++;
        } elseif ( 'ERROR' === $status ) {
            $summary['errors']++;
        } elseif ( 'READY' === $status ) {
            $summary['processable']++;
        }

        if ( ! empty( $result['context']['duplicate_detected'] ) || in_array( $reason, [ 'duplicate_existing', 'csv_id_match', 'dry_run_duplicate_skip', 'dry_run_update_metadata', 'updated_metadata_only', 'dry_run_update_selected_fields', 'updated_selected_fields_only', 'dry_run_replace_file', 'replaced_existing_file' ], true ) ) {
            $summary['duplicates']++;
        }

        if ( in_array( $reason, [ 'dry_run_update_metadata', 'updated_metadata_only', 'dry_run_update_selected_fields', 'updated_selected_fields_only', 'dry_run_replace_file', 'replaced_existing_file' ], true ) ) {
            $summary['updated']++;
        }

        $context = isset( $result['context'] ) && is_array( $result['context'] ) ? $result['context'] : [];
        foreach ( [ 'restore_points_created', 'converted_images', 'conversion_warnings', 'conversion_errors', 'rollback_restored', 'rollback_failures' ] as $counter_key ) {
            if ( isset( $context[ $counter_key ] ) ) {
                $summary[ $counter_key ] += absint( $context[ $counter_key ] );
            }
        }

        if ( ! empty( $context['restore_point_created'] ) ) {
            $summary['restore_points_created']++;
        }

        if ( ! empty( $context['converted_image'] ) ) {
            $summary['converted_images']++;
        }

        if ( ! empty( $context['conversion_warning'] ) ) {
            $summary['conversion_warnings']++;
        }

        if ( ! empty( $context['conversion_error'] ) ) {
            $summary['conversion_errors']++;
        }

        return $summary;
    }

    private function build_batch_response( $results, $summary, $meta ) {
        $response = [
            'results'  => is_array( $results ) ? array_values( $results ) : [],
            'summary'  => is_array( $summary ) ? $summary : $this->get_empty_result_summary(),
            'meta'     => is_array( $meta ) ? $meta : [],
        ];

        return apply_filters( 'eim_import_batch_response', $response, $results, $summary, $meta );
    }

    private function inspect_csv_file( $file_path ) {
        try {
            return $this->csv_reader->inspect_file( $file_path );
        } catch ( EIM_Csv_Reader_Exception $exception ) {
            return new WP_Error( $exception->get_error_code(), $exception->getMessage() );
        }
    }
    private function read_csv_row( $handle, $delimiter, $strip_bom = false ) {
        return call_user_func_array( [ $this->csv_reader, 'read_csv_row' ], func_get_args() );
    }

    private function is_csv_row_empty( $row ) {
        return call_user_func_array( [ $this->csv_reader, 'is_csv_row_empty' ], func_get_args() );
    }

    private function validate_row_via_hooks( $row, $context ) {
        $validation = apply_filters( 'eim_validate_import_row', true, $row, $context );

        if ( true === $validation || null === $validation ) {
            return true;
        }

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        if ( false === $validation ) {
            return new WP_Error( 'eim_import_row_invalid', __( 'This row did not pass import validation.', 'calliope-media-import-export' ) );
        }

        if ( is_string( $validation ) && '' !== trim( $validation ) ) {
            return new WP_Error( 'eim_import_row_invalid', trim( $validation ) );
        }

        return true;
    }

    private function create_temp_import_file( $source_path, $inspection ) {
        return call_user_func_array( [ $this->temp_file_manager, 'create_temp_import_file' ], func_get_args() );
    }

    private function get_temp_file_paths( $file_name ) {
        return call_user_func_array( [ $this->temp_file_manager, 'get_temp_file_paths' ], func_get_args() );
    }

    private function read_temp_file_meta( $file_name ) {
        return call_user_func_array( [ $this->temp_file_manager, 'read_temp_file_meta' ], func_get_args() );
    }

    private function cleanup_temp_import_file( $file_name ) {
        return call_user_func_array( [ $this->temp_file_manager, 'cleanup_temp_import_file' ], func_get_args() );
    }

    private function reset_import_progress_log( $file_name ) {
        return call_user_func_array( [ $this->temp_file_manager, 'reset_import_progress_log' ], func_get_args() );
    }

    private function append_import_progress( $file_name, $result ) {
        return call_user_func_array( [ $this->temp_file_manager, 'append_import_progress' ], func_get_args() );
    }

    private function get_temp_lock_key( $file_name ) {
        return call_user_func_array( [ $this->temp_file_manager, 'get_temp_lock_key' ], func_get_args() );
    }

    private function acquire_temp_lock( $lock_key, $ttl = null ) {
        return call_user_func_array( [ $this->temp_file_manager, 'acquire_temp_lock' ], func_get_args() );
    }

    private function release_temp_lock( $lock_key ) {
        return call_user_func_array( [ $this->temp_file_manager, 'release_temp_lock' ], func_get_args() );
    }

    private function validate_existing_media_file( $file_path, $write_sanitized_svg = true ) {
        return call_user_func_array( [ $this->attachment_writer, 'validate_existing_media_file' ], func_get_args() );
    }

    private function derive_filename( $url, $rel_path = '' ) {
        return call_user_func_array( [ $this->attachment_writer, 'derive_filename' ], func_get_args() );
    }

    private function build_import_action_context( $request_context, $row = [], $extra = [] ) {
        $request_context = $this->get_result_request_context( $request_context );
        $extra           = is_array( $extra ) ? $extra : [];

        return array_merge(
            [
                'request_context' => $request_context,
                'row'             => is_array( $row ) ? $row : [],
                'dry_run'         => ! empty( $request_context['dry_run'] ),
                'pro_history_id'  => isset( $request_context['pro_history_id'] ) ? absint( $request_context['pro_history_id'] ) : 0,
                'pro_job_id'      => isset( $request_context['pro_job_id'] ) ? absint( $request_context['pro_job_id'] ) : 0,
            ],
            $extra
        );
    }

    private function get_result_request_context( $request_context ) {
        $request_context = is_array( $request_context ) ? $request_context : [];

        return [
            'source'              => isset( $request_context['source'] ) ? sanitize_key( (string) $request_context['source'] ) : 'runtime',
            'file'                => isset( $request_context['file'] ) ? sanitize_file_name( (string) $request_context['file'] ) : '',
            'dry_run'             => ! empty( $request_context['dry_run'] ),
            'local_import'        => ! empty( $request_context['local_import'] ),
            'skip_thumbnails'     => ! empty( $request_context['skip_thumbnails'] ),
            'honor_relative_path' => ! isset( $request_context['honor_relative_path'] ) || ! empty( $request_context['honor_relative_path'] ),
            'duplicate_strategy'  => isset( $request_context['duplicate_strategy'] ) ? sanitize_key( (string) $request_context['duplicate_strategy'] ) : 'skip',
            'match_strategy'      => isset( $request_context['match_strategy'] ) ? sanitize_key( (string) $request_context['match_strategy'] ) : 'auto',
            'selected_update_fields' => isset( $request_context['selected_update_fields'] ) ? $this->normalize_selected_update_fields( $request_context['selected_update_fields'] ) : [],
            'advanced_import_actions_allowed' => ! empty( $request_context['advanced_import_actions_allowed'] ),
            'pro_history_id'      => ! empty( $request_context['advanced_import_actions_allowed'] ) && isset( $request_context['pro_history_id'] ) ? absint( $request_context['pro_history_id'] ) : 0,
            'pro_job_id'          => ! empty( $request_context['advanced_import_actions_allowed'] ) && isset( $request_context['pro_job_id'] ) ? absint( $request_context['pro_job_id'] ) : 0,
            'convert_images_format' => ! empty( $request_context['advanced_import_actions_allowed'] ) && isset( $request_context['convert_images_format'] ) ? sanitize_key( (string) $request_context['convert_images_format'] ) : 'keep',
            'conversion_quality'  => isset( $request_context['conversion_quality'] ) ? min( 100, max( 1, absint( $request_context['conversion_quality'] ) ) ) : 82,
            'conversion_failure_behavior' => ! empty( $request_context['advanced_import_actions_allowed'] ) && isset( $request_context['conversion_failure_behavior'] ) ? sanitize_key( (string) $request_context['conversion_failure_behavior'] ) : 'keep_original',
        ];
    }

    private function build_item_result( $status, $file, $message, $context = [] ) {
        $result = [
            'status'  => (string) $status,
            'file'    => (string) $file,
            'message' => (string) $message,
        ];

        if ( ! empty( $context ) && is_array( $context ) ) {
            $result['context'] = $context;
        }

        return apply_filters( 'eim_import_item_result', $result, $context, $status );
    }


    private function open_read_handle( $file_path ) {
        return call_user_func_array( [ $this->csv_reader, 'open_read_handle' ], func_get_args() );
    }

    private function close_file_handle( $handle ) {
        return call_user_func_array( [ $this->csv_reader, 'close_file_handle' ], func_get_args() );
    }

    public function cleanup_temp_files(  ) {
        return call_user_func_array( [ $this->temp_file_manager, 'cleanup_temp_files' ], func_get_args() );
    }


}
