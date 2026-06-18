<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Importer {

    const MAX_BATCH_SIZE = 50;
    const TEMP_FILE_TTL  = DAY_IN_SECONDS;
    const LOCK_TTL       = 90;

    public function __construct() {
        add_action( 'wp_ajax_eim_validate_csv', [ $this, 'validate_csv' ] );
        add_action( 'wp_ajax_eim_process_batch', [ $this, 'process_batch' ] );
        add_action( 'wp_ajax_eim_get_import_progress', [ $this, 'get_import_progress' ] );
        add_action( 'eim_daily_cleanup_event', [ $this, 'cleanup_temp_files' ] );
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
            $this->reset_import_progress_log( $file_name );
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

                    $this->append_import_progress( $file_name, $result );

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
                $validated = $this->validate_existing_media_file( $source_file );
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
        if ( ! file_exists( $file_path ) ) {
            $this->log_import_event(
                'attach_local_missing',
                [
                    'filename'  => $filename,
                    'file_path' => $file_path,
                    'url'       => $url,
                    'rel_path'  => $rel_path,
                ]
            );

            return $this->build_item_result( 'ERROR', $filename, __( 'Local file not found.', 'calliope-media-import-export' ) );
        }

        if ( ! $this->is_path_inside_uploads( $file_path ) ) {
            $this->log_import_event(
                'attach_local_invalid_path',
                [
                    'filename'  => $filename,
                    'file_path' => $file_path,
                ]
            );

            return $this->build_item_result( 'ERROR', $filename, __( 'Invalid local path.', 'calliope-media-import-export' ) );
        }

        $this->log_import_event(
            'attach_local_start',
            [
                'filename'  => $filename,
                'file_path' => $file_path,
                'bytes'     => filesize( $file_path ),
                'url'       => $url,
                'rel_path'  => $rel_path,
            ]
        );

        $validated = $this->validate_existing_media_file( $file_path );
        if ( is_wp_error( $validated ) ) {
            $this->log_import_event(
                'attach_local_invalid_file',
                [
                    'filename' => $filename,
                    'message'  => $validated->get_error_message(),
                ]
            );

            return $this->build_item_result( 'ERROR', $filename, $validated->get_error_message() );
        }

        $final_filename = $filename ? $filename : $validated['filename'];
        $final_filename = $this->normalize_import_filename( $final_filename, $url, $rel_path );
        $fingerprint    = $this->get_file_fingerprint( $file_path );
        $custom_meta    = $this->decode_custom_meta_json( isset( $row['custom_meta_json'] ) ? $row['custom_meta_json'] : '' );
        $request_context = is_array( $request_context ) ? $request_context : [];
        $advanced_actions_allowed = ! empty( $request_context['advanced_import_actions_allowed'] );
        $existing_id    = $this->find_existing_attachment_id(
            $url,
            $rel_path,
            $file_path,
            $final_filename,
            $fingerprint,
            $this->normalize_match_strategy(
                isset( $request_context['match_strategy'] ) ? $request_context['match_strategy'] : 'auto',
                $advanced_actions_allowed
            )
        );
        $duplicate_strategy = $this->normalize_duplicate_strategy(
            isset( $request_context['duplicate_strategy'] ) ? $request_context['duplicate_strategy'] : 'skip',
            $advanced_actions_allowed
        );

        if ( $existing_id ) {
            $this->log_import_event(
                'attach_local_duplicate',
                [
                    'filename'    => $final_filename,
                    'existing_id' => (int) $existing_id,
                    'strategy'    => $duplicate_strategy,
                ]
            );

            $duplicate_result = $this->resolve_duplicate_result(
                $existing_id,
                $duplicate_strategy,
                ! empty( $request_context['dry_run'] ),
                $final_filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $fingerprint,
                $file_path,
                $custom_meta,
                $row,
                'duplicate_existing',
                $request_context
            );
            if ( null !== $duplicate_result ) {
                return $duplicate_result;
            }
        }

        $attachment = [
            'post_mime_type' => $validated['mime'],
            'post_title'     => $title ? $title : ( $final_filename ? $final_filename : __( 'Media', 'calliope-media-import-export' ) ),
            'post_content'   => $description,
            'post_excerpt'   => $caption,
            'post_status'    => 'inherit',
        ];

        $id = wp_insert_attachment( $attachment, $file_path, 0 );
        if ( is_wp_error( $id ) ) {
            $this->log_import_event(
                'attach_local_insert_error',
                [
                    'filename' => $final_filename,
                    'message'  => $id->get_error_message(),
                ]
            );

            return $this->build_item_result(
                'ERROR',
                $final_filename,
                $id->get_error_message(),
                [ 'reason' => 'wp_insert_attachment_error' ]
            );
        }

        update_attached_file( $id, $file_path );

        $metadata_start = microtime( true );
        if ( 'image/svg+xml' === $validated['mime'] ) {
            wp_update_attachment_metadata( $id, [] );
        } else {
            $attach_data = wp_generate_attachment_metadata( $id, $file_path );
            if ( ! empty( $attach_data ) && ! is_wp_error( $attach_data ) ) {
                wp_update_attachment_metadata( $id, $attach_data );
            }
        }
        $this->log_import_event(
            'attach_local_metadata_done',
            [
                'filename'      => $final_filename,
                'attachment_id' => (int) $id,
                'elapsed'       => round( microtime( true ) - $metadata_start, 3 ),
                'mime'          => $validated['mime'],
            ]
        );

        if ( $alt && 0 === strpos( $validated['mime'], 'image/' ) ) {
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

        $this->log_import_event(
            'attach_local_imported',
            [
                'filename'      => $final_filename,
                'attachment_id' => (int) $id,
            ]
        );

        return $this->build_item_result(
            'IMPORTED',
            $final_filename,
            /* translators: %d: attachment ID. */
            sprintf( __( 'Imported successfully (ID %d)', 'calliope-media-import-export' ), (int) $id ),
            [
                'reason'        => 'imported',
                'attachment_id' => (int) $id,
                'import_method' => 'local',
                'request_context' => $this->get_result_request_context( $request_context ),
            ]
        );
    }

    private function find_existing_attachment_id( $url, $rel_path, $incoming_file_path = null, $filename = '', $incoming_fingerprint = '', $match_strategy = 'auto' ) {
        global $wpdb;

        $url      = is_string( $url ) ? trim( $url ) : '';
        $rel_path = is_string( $rel_path ) ? trim( $rel_path ) : '';
        $filename = is_string( $filename ) ? trim( $filename ) : '';
        $match_strategy = $this->normalize_match_strategy( $match_strategy );

        if ( 'attachment_id' === $match_strategy ) {
            return 0;
        }

        if ( in_array( $match_strategy, [ 'auto', 'source_url' ], true ) && '' !== $url ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for existing imported media.
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_eim_source_url',
                    $url
                )
            );
            if ( $id ) {
                return $id;
            }
        }

        if ( in_array( $match_strategy, [ 'auto', 'relative_path' ], true ) && '' !== $rel_path ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for existing imported media.
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_eim_source_rel_path',
                    $rel_path
                )
            );
            if ( $id ) {
                return $id;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for existing attachments by relative path.
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_wp_attached_file',
                    $rel_path
                )
            );
            if ( $id ) {
                return $id;
            }
        }

        if ( in_array( $match_strategy, [ 'auto', 'source_url' ], true ) && '' !== $url ) {
            $local_id = (int) attachment_url_to_postid( $url );
            if ( $local_id ) {
                return $local_id;
            }
        }

        $has_incoming_file = ! empty( $incoming_file_path ) && is_string( $incoming_file_path ) && file_exists( $incoming_file_path );
        $fingerprint       = '';

        if ( $has_incoming_file ) {
            $fingerprint = $incoming_fingerprint ? (string) $incoming_fingerprint : $this->get_file_fingerprint( $incoming_file_path );
            if ( $fingerprint ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for fingerprint matching.
                $id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                        '_eim_file_fingerprint',
                        $fingerprint
                    )
                );
                if ( $id ) {
                    return $id;
                }

                if ( 0 === strpos( $fingerprint, 'md5:' ) ) {
                    $md5 = substr( $fingerprint, 4 );
                    if ( $md5 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for md5 matching.
                        $id = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                                '_eim_file_hash',
                                $md5
                            )
                        );
                        if ( $id ) {
                            return $id;
                        }
                    }
                }
            }
        }

        if ( in_array( $match_strategy, [ 'auto', 'filename' ], true ) && $filename ) {
            $candidates = $this->find_attachments_by_name_candidates( $filename, $rel_path );

            foreach ( $candidates as $candidate_id ) {
                $candidate_id = absint( $candidate_id );
                if ( ! $candidate_id ) {
                    continue;
                }

                if ( 'filename' === $match_strategy ) {
                    return $candidate_id;
                }

                if ( ! $has_incoming_file || ! $fingerprint ) {
                    continue;
                }

                $candidate_fp = (string) get_post_meta( $candidate_id, '_eim_file_fingerprint', true );
                if ( ! $candidate_fp ) {
                    $candidate_file = get_attached_file( $candidate_id );
                    if ( ! $candidate_file || ! file_exists( $candidate_file ) ) {
                        continue;
                    }

                    $candidate_fp = $this->get_file_fingerprint( $candidate_file );
                    if ( $candidate_fp ) {
                        update_post_meta( $candidate_id, '_eim_file_fingerprint', $candidate_fp );

                        if ( 0 === strpos( $candidate_fp, 'md5:' ) ) {
                            update_post_meta( $candidate_id, '_eim_file_hash', substr( $candidate_fp, 4 ) );
                        }
                    }
                }

                if ( $candidate_fp && hash_equals( $fingerprint, $candidate_fp ) ) {
                    return $candidate_id;
                }
            }
        }

        return 0;
    }

    private function find_attachments_by_name_candidates( $filename, $rel_path = '' ) {
        global $wpdb;

        $filename = trim( (string) $filename );
        if ( '' === $filename ) {
            return [];
        }

        $pathinfo = pathinfo( $filename );
        $base     = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : $filename;
        $ext      = isset( $pathinfo['extension'] ) && '' !== $pathinfo['extension'] ? '.' . $pathinfo['extension'] : '';
        $dir      = '';

        if ( $rel_path ) {
            $dir = dirname( (string) $rel_path );
            if ( $dir && '.' !== $dir ) {
                $dir = trim( $dir, '/' );
            } else {
                $dir = '';
            }
        }

        if ( '' !== $dir ) {
            $exact = $dir . '/' . $filename;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for filename candidates.
            $ids   = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = %s AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s)
                     LIMIT 50",
                    '_wp_attached_file',
                    $exact,
                    $dir . '/' . $base . '-%' . $ext,
                    $dir . '/' . $base . '%' . $ext
                )
            );

            return array_map( 'absint', (array) $ids );
        }

        $like_exact    = '%' . $wpdb->esc_like( '/' . $filename );
        $like_variants = '%' . $wpdb->esc_like( '/' . $base . '-' ) . '%' . $wpdb->esc_like( $ext );
        $like_scaled   = '%' . $wpdb->esc_like( '/' . $base ) . '%' . $wpdb->esc_like( $ext );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for filename candidates.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
                 LIMIT 50",
                '_wp_attached_file',
                $like_exact,
                $like_variants,
                $like_scaled
            )
        );

        return array_map( 'absint', (array) $ids );
    }

    private function maybe_match_existing_attachment_by_csv_id( $csv_id, $url, $rel_path, $match_strategy = 'auto' ) {
        $csv_id = absint( $csv_id );
        if ( ! $csv_id || 'attachment' !== get_post_type( $csv_id ) ) {
            return 0;
        }

        $match_strategy = $this->normalize_match_strategy( $match_strategy );
        if ( ! in_array( $match_strategy, [ 'auto', 'attachment_id' ], true ) ) {
            return 0;
        }

        if ( 'attachment_id' === $match_strategy ) {
            return $csv_id;
        }

        $allow_match = 'attachment_id' === $match_strategy
            ? true
            : (bool) apply_filters( 'eim_allow_csv_id_match', false, $csv_id, $url, $rel_path );
        if ( ! $allow_match ) {
            return 0;
        }

        $attached_file = (string) get_post_meta( $csv_id, '_wp_attached_file', true );
        $stored_url    = (string) get_post_meta( $csv_id, '_eim_source_url', true );
        $stored_rel    = (string) get_post_meta( $csv_id, '_eim_source_rel_path', true );

        if ( $rel_path && ( $attached_file === $rel_path || $stored_rel === $rel_path ) ) {
            return $csv_id;
        }

        if ( $url && $stored_url === $url ) {
            return $csv_id;
        }

        return 0;
    }

    private function resolve_duplicate_result( $attachment_id, $strategy, $dry_run, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $incoming_file_path, $custom_meta, $row, $reason, $request_context = [] ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return null;
        }

        $request_context = is_array( $request_context ) ? $request_context : [];
        $advanced_actions_allowed = ! empty( $request_context['advanced_import_actions_allowed'] );
        $selected_update_fields = $advanced_actions_allowed
            ? $this->normalize_selected_update_fields(
                isset( $request_context['selected_update_fields'] ) ? $request_context['selected_update_fields'] : []
            )
            : [];

        $strategy = apply_filters(
            'eim_duplicate_handling_strategy',
            $this->normalize_duplicate_strategy( $strategy, $advanced_actions_allowed ),
            $attachment_id,
            $row,
            [
                'reason'        => (string) $reason,
                'dry_run'       => (bool) $dry_run,
                'filename'      => (string) $filename,
                'url'           => (string) $url,
                'relative_path' => (string) $rel_path,
                'advanced_import_actions_allowed' => $advanced_actions_allowed,
            ]
        );
        $strategy = $this->normalize_duplicate_strategy( $strategy, $advanced_actions_allowed );

        if ( 'force_new' === $strategy ) {
            return null;
        }

        if ( in_array( $strategy, [ 'update_metadata', 'update_selected_fields' ], true ) ) {
            $update_reason = 'update_selected_fields' === $strategy ? 'updated_selected_fields_only' : 'updated_metadata_only';
            $dry_reason    = 'update_selected_fields' === $strategy ? 'dry_run_update_selected_fields' : 'dry_run_update_metadata';
            if ( $dry_run ) {
                /* translators: %d: attachment ID. */
                $dry_run_message = 'update_selected_fields' === $strategy
                    ? __( 'Dry run: existing media (ID %d) would have its selected fields updated.', 'calliope-media-import-export' )
                    : __( 'Dry run: existing media (ID %d) would have its metadata updated.', 'calliope-media-import-export' );

                return $this->build_item_result(
                    'READY',
                    $filename,
                    sprintf(
                        $dry_run_message,
                        $attachment_id
                    ),
                    [
                        'reason'             => $dry_reason,
                        'attachment_id'      => $attachment_id,
                        'duplicate_detected' => true,
                    ]
                );
            }

            $this->update_existing_attachment_metadata(
                $attachment_id,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $fingerprint,
                $custom_meta,
                $row,
                'update_selected_fields' === $strategy ? $selected_update_fields : [],
                $request_context
            );

            /* translators: %d: attachment ID. */
            $updated_message = 'update_selected_fields' === $strategy
                ? __( 'Updated selected fields for existing media (ID %d)', 'calliope-media-import-export' )
                : __( 'Updated metadata for existing media (ID %d)', 'calliope-media-import-export' );

            return $this->build_item_result(
                'IMPORTED',
                $filename,
                sprintf(
                    $updated_message,
                    $attachment_id
                ),
                [
                    'reason'             => $update_reason,
                    'attachment_id'      => $attachment_id,
                    'duplicate_detected' => true,
                    'import_method'      => 'update_selected_fields' === $strategy ? 'selected-fields-update' : 'metadata-update',
                    'request_context'    => $this->get_result_request_context( $request_context ),
                ]
            );
        }

        if ( 'replace_file' === $strategy ) {
            if ( $dry_run ) {
                return $this->build_item_result(
                    'READY',
                    $filename,
                    /* translators: %d: attachment ID. */
                    /* translators: %d: existing attachment ID. */
                    sprintf( __( 'Dry run: existing media (ID %d) would have its file replaced.', 'calliope-media-import-export' ), $attachment_id ),
                    [
                        'reason'             => 'dry_run_replace_file',
                        'attachment_id'      => $attachment_id,
                        'duplicate_detected' => true,
                    ]
                );
            }

            $replaced = $this->replace_existing_attachment_file(
                $attachment_id,
                $incoming_file_path,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $fingerprint,
                $custom_meta,
                $row,
                $request_context
            );

            if ( is_wp_error( $replaced ) ) {
                return $this->build_item_result(
                    'ERROR',
                    $filename,
                    $replaced->get_error_message(),
                    [
                        'reason'             => 'replace_file_failed',
                        'attachment_id'      => $attachment_id,
                        'duplicate_detected' => true,
                    ]
                );
            }

            return $this->build_item_result(
                'IMPORTED',
                $filename,
                /* translators: %d: attachment ID. */
                sprintf( __( 'Replaced the file for existing media (ID %d)', 'calliope-media-import-export' ), $attachment_id ),
                [
                    'reason'             => 'replaced_existing_file',
                    'attachment_id'      => $attachment_id,
                    'duplicate_detected' => true,
                    'import_method'      => 'replace-file',
                    'request_context'    => $this->get_result_request_context( $request_context ),
                ]
            );
        }

        $this->backfill_source_meta( $attachment_id, $url, $rel_path );
        if ( $fingerprint ) {
            $this->backfill_fingerprint_meta( $attachment_id, $fingerprint );
        }

        return $this->build_item_result(
            'SKIPPED',
            $filename,
            $dry_run
                ? sprintf(
                    /* translators: %d: attachment ID. */
                    __( 'Dry run: duplicate detected (ID %d) and it would be skipped.', 'calliope-media-import-export' ),
                    $attachment_id
                )
                : ( 'csv_id_match' === $reason
                    ? sprintf(
                        /* translators: %d: attachment ID. */
                        __( 'Matched existing attachment (ID %d)', 'calliope-media-import-export' ),
                        $attachment_id
                    )
                    : sprintf(
                        /* translators: %d: attachment ID. */
                        __( 'Duplicate detected (ID %d)', 'calliope-media-import-export' ),
                        $attachment_id
                    ) ),
            [
                'reason'             => $dry_run ? 'dry_run_duplicate_skip' : (string) $reason,
                'attachment_id'      => $attachment_id,
                'duplicate_detected' => true,
            ]
        );
    }

    private function update_existing_attachment_metadata( $attachment_id, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $custom_meta, $row, $selected_fields = [], $request_context = [] ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return;
        }

        $selected_fields = $this->normalize_selected_update_fields( $selected_fields );
        $update_all      = empty( $selected_fields );
        $action_context  = $this->build_import_action_context(
            $request_context,
            $row,
            [
                'attachment_id'          => $attachment_id,
                'action'                 => 'metadata_update',
                'filename'               => (string) $filename,
                'selected_update_fields' => $selected_fields,
                'custom_meta_keys'       => array_keys( is_array( $custom_meta ) ? $custom_meta : [] ),
                'source_url'             => (string) $url,
                'relative_path'          => (string) $rel_path,
                'fingerprint'            => (string) $fingerprint,
            ]
        );

        do_action( 'eim_before_update_existing_media', $attachment_id, $row, $action_context );

        $post_data       = [ 'ID' => $attachment_id ];
        $has_post_update = false;

        $title = is_string( $title ) ? trim( $title ) : '';
        if ( ( $update_all || in_array( 'title', $selected_fields, true ) ) && '' !== $title ) {
            $post_data['post_title'] = $title;
            $has_post_update         = true;
        }

        $caption = is_string( $caption ) ? trim( $caption ) : '';
        if ( ( $update_all || in_array( 'caption', $selected_fields, true ) ) && '' !== $caption ) {
            $post_data['post_excerpt'] = $caption;
            $has_post_update           = true;
        }

        $description = is_string( $description ) ? trim( $description ) : '';
        if ( ( $update_all || in_array( 'description', $selected_fields, true ) ) && '' !== $description ) {
            $post_data['post_content'] = $description;
            $has_post_update           = true;
        }

        if ( $has_post_update ) {
            wp_update_post( $post_data );
        }

        $alt  = is_string( $alt ) ? trim( $alt ) : '';
        $mime = get_post_mime_type( $attachment_id );
        if ( ( $update_all || in_array( 'alt', $selected_fields, true ) ) && '' !== $alt && $mime && 0 === strpos( $mime, 'image/' ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }

        if ( $update_all || in_array( 'custom_meta', $selected_fields, true ) ) {
            $this->apply_custom_meta( $attachment_id, $custom_meta );
        }

        $this->backfill_source_meta( $attachment_id, $url, $rel_path );
        if ( $fingerprint ) {
            $this->backfill_fingerprint_meta( $attachment_id, $fingerprint );
        }

        do_action( 'eim_after_update_existing_media', $attachment_id, $row );
        do_action( 'eim_after_update_existing_media_with_context', $attachment_id, $row, $action_context );
    }

    private function replace_existing_attachment_file( $attachment_id, $source_file_path, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $custom_meta, $row, $request_context = [] ) {
        $attachment_id    = absint( $attachment_id );
        $source_file_path = (string) $source_file_path;

        if ( ! $attachment_id || '' === $source_file_path || ! file_exists( $source_file_path ) ) {
            return new WP_Error( 'eim_replace_source_missing', __( 'The replacement source file is missing.', 'calliope-media-import-export' ) );
        }

        $current_file = get_attached_file( $attachment_id );
        if ( ! $current_file ) {
            return new WP_Error( 'eim_replace_target_missing', __( 'The current attachment file could not be found.', 'calliope-media-import-export' ) );
        }

        if ( wp_normalize_path( $source_file_path ) === wp_normalize_path( $current_file ) ) {
            $this->update_existing_attachment_metadata(
                $attachment_id,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $fingerprint,
                $custom_meta,
                $row,
                [],
                $request_context
            );

            return $attachment_id;
        }

        $target_dir = wp_normalize_path( dirname( $current_file ) );
        if ( ! is_dir( $target_dir ) || ! $this->is_path_inside_uploads( $target_dir ) ) {
            return new WP_Error( 'eim_replace_target_invalid', __( 'The target uploads directory is not valid.', 'calliope-media-import-export' ) );
        }

        $target_name = sanitize_file_name( $filename ? $filename : wp_basename( $source_file_path ) );
        $source_is_svg = $this->is_svg_import_file( $source_file_path, $target_name );
        if ( $source_is_svg ) {
            $svg_validation = $this->maybe_validate_svg_import_file( $source_file_path, $target_name );
            if ( is_wp_error( $svg_validation ) ) {
                return $svg_validation;
            }
        }

        $target_path = wp_normalize_path( trailingslashit( $target_dir ) . $target_name );

        if ( $target_path !== wp_normalize_path( $current_file ) ) {
            $unique_name = wp_unique_filename( $target_dir, $target_name );
            $target_path = wp_normalize_path( trailingslashit( $target_dir ) . $unique_name );
        }

        $action_context = $this->build_import_action_context(
            $request_context,
            $row,
            [
                'attachment_id'    => $attachment_id,
                'action'           => 'file_replace',
                'source_file_path' => wp_normalize_path( $source_file_path ),
                'current_file'     => wp_normalize_path( $current_file ),
                'target_file'      => wp_normalize_path( $target_path ),
                'filename'         => (string) $filename,
                'source_url'       => (string) $url,
                'relative_path'    => (string) $rel_path,
                'fingerprint'      => (string) $fingerprint,
            ]
        );

        do_action( 'eim_before_replace_existing_media_file', $attachment_id, $source_file_path, $row, $action_context );

        if ( ! @copy( $source_file_path, $target_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'eim_replace_copy_failed', __( 'The replacement file could not be copied into uploads.', 'calliope-media-import-export' ) );
        }

        $old_metadata = wp_get_attachment_metadata( $attachment_id );

        update_attached_file( $attachment_id, $target_path );

        $filetype = $source_is_svg
            ? [
                'type' => 'image/svg+xml',
                'ext'  => 'svg',
            ]
            : wp_check_filetype( wp_basename( $target_path ) );
        if ( ! empty( $filetype['type'] ) ) {
            wp_update_post(
                [
                    'ID'             => $attachment_id,
                    'post_mime_type' => $filetype['type'],
                ]
            );
        }

        if ( $source_is_svg ) {
            wp_update_attachment_metadata( $attachment_id, [] );
        } else {
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $target_path );
            if ( ! empty( $attach_data ) && ! is_wp_error( $attach_data ) ) {
                wp_update_attachment_metadata( $attachment_id, $attach_data );
            }
        }

        $this->update_existing_attachment_metadata(
            $attachment_id,
            $filename,
            $title,
            $alt,
            $caption,
            $description,
            $url,
            $rel_path,
            $fingerprint,
            $custom_meta,
            $row,
            [],
            $request_context
        );

        $this->cleanup_attachment_generated_files( $current_file, $old_metadata );

        do_action( 'eim_after_replace_existing_media_file', $attachment_id, $row, $action_context );

        return $attachment_id;
    }

    private function cleanup_attachment_generated_files( $current_file, $metadata ) {
        $current_file = wp_normalize_path( (string) $current_file );
        if ( '' === $current_file ) {
            return;
        }

        $base_dir = wp_normalize_path( dirname( $current_file ) );
        $sizes    = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : [];

        foreach ( $sizes as $size ) {
            if ( empty( $size['file'] ) ) {
                continue;
            }

            $candidate = wp_normalize_path( trailingslashit( $base_dir ) . $size['file'] );
            if ( file_exists( $candidate ) ) {
                wp_delete_file( $candidate );
            }
        }

        if ( file_exists( $current_file ) ) {
            wp_delete_file( $current_file );
        }
    }

    private function apply_custom_meta( $attachment_id, $custom_meta ) {
        $attachment_id = absint( $attachment_id );
        $custom_meta   = is_array( $custom_meta ) ? $custom_meta : [];

        if ( ! $attachment_id || empty( $custom_meta ) ) {
            return;
        }

        foreach ( $custom_meta as $meta_key => $meta_value ) {
            $meta_key = sanitize_key( (string) $meta_key );

            if ( '' === $meta_key ) {
                continue;
            }

            update_post_meta( $attachment_id, $meta_key, is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value ) );
        }
    }

    private function decode_custom_meta_json( $value ) {
        if ( is_array( $value ) ) {
            return $value;
        }

        $decoded = json_decode( (string) $value, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    private function store_source_meta( $attachment_id, $url, $rel_path ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return;
        }

        if ( is_string( $url ) && '' !== trim( $url ) ) {
            update_post_meta( $attachment_id, '_eim_source_url', trim( $url ) );
        }

        if ( is_string( $rel_path ) && '' !== trim( $rel_path ) ) {
            update_post_meta( $attachment_id, '_eim_source_rel_path', trim( $rel_path ) );
        }
    }

    private function backfill_source_meta( $attachment_id, $url, $rel_path ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return;
        }

        $current_url = (string) get_post_meta( $attachment_id, '_eim_source_url', true );
        if ( '' === $current_url && is_string( $url ) && '' !== trim( $url ) ) {
            update_post_meta( $attachment_id, '_eim_source_url', trim( $url ) );
        }

        $current_rel = (string) get_post_meta( $attachment_id, '_eim_source_rel_path', true );
        if ( '' === $current_rel && is_string( $rel_path ) && '' !== trim( $rel_path ) ) {
            update_post_meta( $attachment_id, '_eim_source_rel_path', trim( $rel_path ) );
        }
    }

    private function store_fingerprint_meta( $attachment_id, $fingerprint ) {
        $attachment_id = absint( $attachment_id );
        $fingerprint   = is_string( $fingerprint ) ? trim( $fingerprint ) : '';

        if ( ! $attachment_id || '' === $fingerprint ) {
            return;
        }

        update_post_meta( $attachment_id, '_eim_file_fingerprint', $fingerprint );

        if ( 0 === strpos( $fingerprint, 'md5:' ) ) {
            $md5 = substr( $fingerprint, 4 );
            if ( $md5 ) {
                update_post_meta( $attachment_id, '_eim_file_hash', $md5 );
            }
        }
    }

    private function backfill_fingerprint_meta( $attachment_id, $fingerprint ) {
        $attachment_id = absint( $attachment_id );
        $fingerprint   = is_string( $fingerprint ) ? trim( $fingerprint ) : '';

        if ( ! $attachment_id || '' === $fingerprint ) {
            return;
        }

        $current = (string) get_post_meta( $attachment_id, '_eim_file_fingerprint', true );
        if ( '' === $current ) {
            $this->store_fingerprint_meta( $attachment_id, $fingerprint );
        }
    }

    private function get_file_fingerprint( $file_path ) {
        $file_path = (string) $file_path;

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return '';
        }

        $size = @filesize( $file_path );
        if ( false === $size ) {
            return '';
        }

        $max_full_bytes = (int) apply_filters( 'eim_full_hash_max_bytes', 50 * 1024 * 1024 );
        $chunk_bytes    = (int) apply_filters( 'eim_fingerprint_chunk_bytes', 1024 * 1024 );

        if ( $size > 0 && $size <= $max_full_bytes ) {
            $md5 = @md5_file( $file_path );
            return $md5 ? 'md5:' . $md5 : '';
        }

        $fingerprint = $this->compute_large_file_fingerprint( $file_path, (int) $size, $chunk_bytes );
        return $fingerprint ? 'fp:' . $fingerprint : '';
    }

    private function compute_large_file_fingerprint( $file_path, $size, $chunk_bytes ) {
        $size        = (int) $size;
        $chunk_bytes = max( 1024, (int) $chunk_bytes );

        $handle = $this->open_read_handle( $file_path );
        if ( ! $handle ) {
            return '';
        }

        $first     = $this->read_file_chunk( $handle, $chunk_bytes );
        $first_md5 = false !== $first ? md5( $first ) : '';
        $last_md5  = '';

        if ( $size > $chunk_bytes ) {
            @fseek( $handle, -$chunk_bytes, SEEK_END );
            $last     = $this->read_file_chunk( $handle, $chunk_bytes );
            $last_md5 = false !== $last ? md5( $last ) : '';
        } else {
            $last_md5 = $first_md5;
        }

        $this->close_file_handle( $handle );

        if ( '' === $first_md5 || '' === $last_md5 ) {
            return '';
        }

        return sha1( $size . '|' . $first_md5 . '|' . $last_md5 );
    }

    private function normalize_import_filename( $filename, $url = '', $rel_path = '' ) {
        $filename = is_string( $filename ) ? trim( $filename ) : '';

        if ( '' === $filename ) {
            $filename = $this->derive_filename( (string) $url, (string) $rel_path );
        }

        $filename = wp_basename( strtok( (string) $filename, '?#' ) );
        $filename = remove_accents( $filename );
        $filename = sanitize_file_name( $filename );

        $clean = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $filename );
        if ( is_string( $clean ) && '' !== $clean ) {
            $filename = $clean;
        }

        $filename = preg_replace( '/\.{2,}/', '.', $filename );
        $filename = preg_replace( '/[-_]{2,}/', '-', $filename );
        $filename = preg_replace( '/[-_]+\./', '.', $filename );
        $filename = preg_replace( '/\.[-_]+/', '.', $filename );
        $filename = trim( (string) $filename, ".-_ \t\n\r\0\x0B" );

        if ( '' === $filename ) {
            $filename = 'media-file';
        }

        $max_length = (int) apply_filters( 'eim_import_max_filename_length', 120 );
        $max_length = max( 60, min( 180, $max_length ) );

        return $this->truncate_filename_preserving_extension( $filename, $max_length );
    }

    private function truncate_filename_preserving_extension( $filename, $max_length ) {
        $filename   = (string) $filename;
        $max_length = max( 60, (int) $max_length );

        if ( strlen( $filename ) <= $max_length ) {
            return $filename;
        }

        $info = pathinfo( $filename );
        $ext  = '';
        if ( ! empty( $info['extension'] ) ) {
            $ext = '.' . strtolower( preg_replace( '/[^A-Za-z0-9]+/', '', (string) $info['extension'] ) );
        }

        $base = isset( $info['filename'] ) && '' !== $info['filename'] ? (string) $info['filename'] : 'media-file';
        $hash = substr( sha1( $filename ), 0, 8 );
        $room = $max_length - strlen( $ext ) - strlen( $hash ) - 1;
        $room = max( 20, $room );
        $base = $this->truncate_string_bytes( $base, $room );
        $base = trim( (string) $base, '.-_' );

        if ( '' === $base ) {
            $base = 'media-file';
        }

        return $base . '-' . $hash . $ext;
    }

    private function truncate_string_bytes( $string, $max_bytes ) {
        $string    = (string) $string;
        $max_bytes = max( 1, (int) $max_bytes );

        if ( strlen( $string ) <= $max_bytes ) {
            return $string;
        }

        if ( function_exists( 'mb_strcut' ) ) {
            return mb_strcut( $string, 0, $max_bytes, 'UTF-8' );
        }

        return substr( $string, 0, $max_bytes );
    }

    private function sanitize_relative_path( $rel_path ) {
        $rel_path = (string) $rel_path;
        $rel_path = str_replace( '\\', '/', $rel_path );
        $rel_path = trim( $rel_path );

        if ( '' === $rel_path ) {
            return '';
        }

        $rel_path = strtok( $rel_path, '?#' );
        if ( preg_match( '/[\x00-\x1F\x7F]/', $rel_path ) ) {
            return '';
        }

        if ( preg_match( '#^[a-zA-Z]:#', $rel_path ) ) {
            return '';
        }

        $rel_path = ltrim( $rel_path, '/' );
        $rel_path = preg_replace( '#/+#', '/', $rel_path );

        $segments = explode( '/', $rel_path );
        $safe     = [];

        foreach ( $segments as $segment ) {
            $segment = trim( (string) $segment );

            if ( '' === $segment || '.' === $segment ) {
                continue;
            }

            if ( '..' === $segment ) {
                return '';
            }

            $safe[] = $segment;
        }

        return implode( '/', $safe );
    }

    private function resolve_uploads_file_from_source( $url = '', $rel_path = '' ) {
        $candidates = [];
        $rel_path   = $this->sanitize_relative_path( $rel_path );

        if ( '' !== $rel_path ) {
            $candidates[] = $this->build_uploads_candidate_path( $rel_path );
        }

        $url_rel_path = $this->get_uploads_relative_path_from_url( $url );
        if ( '' !== $url_rel_path ) {
            $candidates[] = $this->build_uploads_candidate_path( $url_rel_path );
        }

        $candidates = array_values( array_unique( array_filter( $candidates ) ) );
        foreach ( $candidates as $candidate ) {
            if ( file_exists( $candidate ) && $this->is_path_inside_uploads( $candidate ) ) {
                return $candidate;
            }
        }

        return '';
    }

    private function build_uploads_candidate_path( $rel_path ) {
        $rel_path = $this->sanitize_relative_path( $rel_path );
        if ( '' === $rel_path ) {
            return '';
        }

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
            return '';
        }

        return wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . $rel_path );
    }

    private function get_uploads_relative_path_from_url( $url ) {
        $url = is_string( $url ) ? trim( $url ) : '';
        if ( '' === $url ) {
            return '';
        }

        $url_parts = wp_parse_url( $url );
        if ( empty( $url_parts['path'] ) ) {
            return '';
        }

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['baseurl'] ) ) {
            return '';
        }

        $base_parts = wp_parse_url( $upload_dir['baseurl'] );
        if ( ! $this->url_parts_match_host( $url_parts, $base_parts ) ) {
            return '';
        }

        $url_path  = '/' . ltrim( rawurldecode( str_replace( '\\', '/', (string) $url_parts['path'] ) ), '/' );
        $url_path  = preg_replace( '#/+#', '/', $url_path );
        $base_path = isset( $base_parts['path'] ) ? '/' . trim( rawurldecode( str_replace( '\\', '/', (string) $base_parts['path'] ) ), '/' ) : '';
        $base_path = preg_replace( '#/+#', '/', $base_path );

        if ( '' !== $base_path && 0 === strpos( $url_path . '/', trailingslashit( $base_path ) ) ) {
            return $this->sanitize_relative_path( substr( $url_path, strlen( $base_path ) ) );
        }

        $marker = '/wp-content/uploads/';
        $pos    = strpos( $url_path, $marker );

        if ( false !== $pos ) {
            return $this->sanitize_relative_path( substr( $url_path, $pos + strlen( $marker ) ) );
        }

        return '';
    }

    private function looks_like_local_upload_url( $url ) {
        return '' !== $this->get_uploads_relative_path_from_url( $url );
    }

    private function url_parts_match_host( $url_parts, $base_parts ) {
        $url_host  = isset( $url_parts['host'] ) ? strtolower( (string) $url_parts['host'] ) : '';
        $base_host = isset( $base_parts['host'] ) ? strtolower( (string) $base_parts['host'] ) : '';

        if ( '' === $url_host || '' === $base_host || $url_host !== $base_host ) {
            return false;
        }

        $url_port  = isset( $url_parts['port'] ) ? (int) $url_parts['port'] : 0;
        $base_port = isset( $base_parts['port'] ) ? (int) $base_parts['port'] : 0;

        return 0 === $url_port || 0 === $base_port || $url_port === $base_port;
    }

    private function is_path_inside_uploads( $file_path ) {
        $upload_dir = wp_upload_dir();
        $base       = realpath( $upload_dir['basedir'] );
        $real       = realpath( $file_path );

        if ( ! $base || ! $real ) {
            return false;
        }

        $base = trailingslashit( wp_normalize_path( $base ) );
        $real = wp_normalize_path( $real );

        return ( $real === untrailingslashit( $base ) || 0 === strpos( $real, $base ) );
    }

    private function get_import_allowed_mimes() {
        $allowed_mimes = get_allowed_mime_types();

        if ( apply_filters( 'eim_allow_svg_imports', true ) ) {
            $allowed_mimes['svg'] = 'image/svg+xml';
        }

        return apply_filters( 'eim_allowed_import_mimes', $allowed_mimes );
    }

    private function is_svg_import_file( $file_path, $filename = '' ) {
        $filename = strtolower( (string) $filename );
        $file_path = (string) $file_path;

        if ( '' !== $filename && preg_match( '/\.svg$/i', $filename ) ) {
            return true;
        }

        if ( '' !== $file_path && preg_match( '/\.svg$/i', $file_path ) ) {
            return true;
        }

        if ( '' === $file_path || ! is_readable( $file_path ) ) {
            return false;
        }

        $contents = file_get_contents( $file_path, false, null, 0, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return is_string( $contents ) && false !== stripos( $contents, '<svg' );
    }

    private function maybe_validate_svg_import_file( $file_path, $filename = '' ) {
        if ( ! $this->is_svg_import_file( $file_path, $filename ) ) {
            return true;
        }

        if ( ! apply_filters( 'eim_allow_svg_imports', true, $file_path, $filename ) ) {
            return new WP_Error( 'eim_svg_import_disabled', __( 'SVG imports are disabled.', 'calliope-media-import-export' ) );
        }

        return $this->validate_safe_svg_file( $file_path );
    }

    private function validate_safe_svg_file( $file_path ) {
        $file_path = (string) $file_path;

        if ( '' === $file_path || ! is_readable( $file_path ) ) {
            return new WP_Error( 'eim_svg_unreadable', __( 'SVG file could not be read.', 'calliope-media-import-export' ) );
        }

        $contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
            return new WP_Error( 'eim_svg_empty', __( 'SVG file is empty.', 'calliope-media-import-export' ) );
        }

        if ( ! preg_match( '/<\s*svg\b/i', $contents ) ) {
            return new WP_Error( 'eim_svg_invalid', __( 'SVG file does not contain valid SVG markup.', 'calliope-media-import-export' ) );
        }

        $unsafe_patterns = [
            '/<\s*script\b/i',
            '/\son[a-z0-9_-]+\s*=/i',
            '/javascript\s*:/i',
            '/<\s*foreignObject\b/i',
            '/<\s*(iframe|object|embed|link|meta|base|form|input|textarea|button|select)\b/i',
            '/<\?xml-stylesheet\b/i',
        ];

        foreach ( $unsafe_patterns as $pattern ) {
            if ( preg_match( $pattern, $contents ) ) {
                return new WP_Error( 'eim_svg_unsafe', __( 'SVG file contains potentially unsafe content.', 'calliope-media-import-export' ) );
            }
        }

        return true;
    }

    private function sideload_svg_file( $file_array, $subdir = '' ) {
        $tmp_name = isset( $file_array['tmp_name'] ) ? (string) $file_array['tmp_name'] : '';
        $filename = isset( $file_array['name'] ) ? sanitize_file_name( (string) $file_array['name'] ) : '';

        if ( '' === $filename ) {
            $filename = 'media-file.svg';
        } elseif ( ! preg_match( '/\.svg$/i', $filename ) ) {
            $filename .= '.svg';
        }

        $svg_validation = $this->maybe_validate_svg_import_file( $tmp_name, $filename );
        if ( is_wp_error( $svg_validation ) ) {
            return $svg_validation;
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'eim_upload_dir_error', $uploads['error'] );
        }

        $subdir = trim( (string) $subdir );
        if ( '' !== $subdir ) {
            $subdir = '/' . trim( $subdir, '/' );
        }

        $target_dir = '' !== $subdir ? trailingslashit( $uploads['basedir'] ) . ltrim( $subdir, '/' ) : $uploads['path'];
        $target_url = '' !== $subdir ? trailingslashit( $uploads['baseurl'] ) . ltrim( $subdir, '/' ) : $uploads['url'];

        if ( false !== strpos( $subdir, '..' ) ) {
            return new WP_Error( 'eim_invalid_subdir', __( 'Invalid target folder.', 'calliope-media-import-export' ) );
        }

        if ( ! file_exists( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'eim_upload_dir_error', __( 'Could not create the target upload folder.', 'calliope-media-import-export' ) );
        }

        $unique_name = wp_unique_filename( $target_dir, $filename );
        $target_path = wp_normalize_path( trailingslashit( $target_dir ) . $unique_name );

        if ( ! @rename( $tmp_name, $target_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( ! @copy( $tmp_name, $target_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                return new WP_Error( 'eim_svg_copy_failed', __( 'The SVG file could not be copied into uploads.', 'calliope-media-import-export' ) );
            }

            wp_delete_file( $tmp_name );
        }

        $stat  = stat( dirname( $target_path ) );
        $perms = $stat ? $stat['mode'] & 0000666 : 0644;
        chmod( $target_path, $perms );

        $attachment = [
            'guid'           => trailingslashit( $target_url ) . wp_basename( $target_path ),
            'post_mime_type' => 'image/svg+xml',
            'post_title'     => preg_replace( '/\.[^.]+$/', '', wp_basename( $target_path ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $id = wp_insert_attachment( $attachment, $target_path, 0 );
        if ( is_wp_error( $id ) ) {
            wp_delete_file( $target_path );
            return $id;
        }

        update_attached_file( $id, $target_path );
        wp_update_attachment_metadata( $id, [] );

        return $id;
    }

    private function media_handle_sideload_with_subdir( $file_array, $subdir = '' ) {
        $subdir = trim( (string) $subdir );

        if ( isset( $file_array['name'] ) ) {
            $file_array['name'] = $this->normalize_import_filename( $file_array['name'] );
        }

        if ( isset( $file_array['tmp_name'] ) && $this->is_svg_import_file( $file_array['tmp_name'], isset( $file_array['name'] ) ? $file_array['name'] : '' ) ) {
            return $this->sideload_svg_file( $file_array, $subdir );
        }

        if ( '' === $subdir ) {
            return media_handle_sideload( $file_array, 0 );
        }

        $subdir = '/' . trim( $subdir, '/' );

        if ( false !== strpos( $subdir, '..' ) ) {
            return new WP_Error( 'eim_invalid_subdir', __( 'Invalid target folder.', 'calliope-media-import-export' ) );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'eim_upload_dir_error', $uploads['error'] );
        }

        $target_dir = trailingslashit( $uploads['basedir'] ) . ltrim( $subdir, '/' );
        if ( ! file_exists( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'eim_upload_dir_error', __( 'Could not create the target upload folder.', 'calliope-media-import-export' ) );
        }

        $filter = function( $upload_paths ) use ( $subdir ) {
            $upload_paths['subdir'] = $subdir;
            $upload_paths['path']   = $upload_paths['basedir'] . $subdir;
            $upload_paths['url']    = $upload_paths['baseurl'] . $subdir;
            return $upload_paths;
        };

        add_filter( 'upload_dir', $filter );
        $id = media_handle_sideload( $file_array, 0 );
        remove_filter( 'upload_dir', $filter );

        return $id;
    }

    private function map_headers( $headers ) {
        $map     = [];
        $headers = array_map( 'strtolower', array_map( 'trim', $headers ) );
        $definitions = $this->get_import_header_definitions();

        foreach ( $headers as $index => $header ) {
            foreach ( $definitions as $key => $options ) {
                $aliases = isset( $options['aliases'] ) ? (array) $options['aliases'] : [];
                if ( isset( $options['label'] ) ) {
                    $aliases[] = (string) $options['label'];
                }
                $aliases = array_map( 'strtolower', array_map( 'trim', $aliases ) );

                if ( in_array( $header, $aliases, true ) ) {
                    $map[ $key ] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function build_row_from_csv( $row_data, $header_map ) {
        $row = [];

        foreach ( $header_map as $key => $index ) {
            $row[ $key ] = isset( $row_data[ $index ] ) ? $row_data[ $index ] : '';
        }

        return $row;
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
        $strategy = sanitize_key( (string) $strategy );
        $allowed  = $allow_advanced
            ? [ 'skip', 'update_metadata', 'update_selected_fields', 'replace_file', 'force_new' ]
            : [ 'skip' ];

        if ( ! in_array( $strategy, $allowed, true ) ) {
            $strategy = 'skip';
        }

        return $strategy;
    }

    private function normalize_match_strategy( $strategy, $allow_advanced = true ) {
        $strategy = sanitize_key( (string) $strategy );
        $allowed  = $allow_advanced
            ? [ 'auto', 'attachment_id', 'source_url', 'relative_path', 'filename' ]
            : [ 'auto' ];

        if ( ! in_array( $strategy, $allowed, true ) ) {
            $strategy = 'auto';
        }

        return $strategy;
    }

    private function normalize_selected_update_fields( $fields ) {
        $allowed = [ 'title', 'alt', 'caption', 'description', 'custom_meta' ];
        $fields  = is_array( $fields ) ? $fields : explode( ',', (string) $fields );
        $fields  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $fields ) ) ) );

        return array_values( array_intersect( $fields, $allowed ) );
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
        $preview = [
            'delimiter'          => isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',',
            'delimiter_label'    => $this->get_delimiter_label( isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',' ),
            'header_count'       => isset( $inspection['headers'] ) && is_array( $inspection['headers'] ) ? count( $inspection['headers'] ) : 0,
            'recognized_columns' => isset( $inspection['recognized_columns'] ) ? (array) $inspection['recognized_columns'] : [],
            'summary'            => isset( $inspection['summary'] ) && is_array( $inspection['summary'] ) ? $inspection['summary'] : [],
            'sample_rows'        => isset( $inspection['preview_rows'] ) ? (array) $inspection['preview_rows'] : [],
            'warnings'           => isset( $inspection['warnings'] ) ? array_values( array_filter( (array) $inspection['warnings'] ) ) : [],
        ];

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
        if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'eim_csv_missing', __( 'Could not read the uploaded CSV file.', 'calliope-media-import-export' ) );
        }

        $compatibility_error = $this->detect_incompatible_import_file( $file_path );
        if ( is_wp_error( $compatibility_error ) ) {
            return $compatibility_error;
        }

        $delimiter = $this->detect_csv_delimiter( $file_path );
        if ( is_wp_error( $delimiter ) ) {
            return $delimiter;
        }

        $handle = $this->open_read_handle( $file_path );
        if ( ! $handle ) {
            return new WP_Error( 'eim_csv_unreadable', __( 'Could not open the uploaded CSV file.', 'calliope-media-import-export' ) );
        }

        $headers = $this->read_csv_row( $handle, $delimiter, true );
        if ( false === $headers || $this->is_csv_row_empty( $headers ) ) {
            $this->close_file_handle( $handle );
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', 'calliope-media-import-export' ) );
        }

        $header_map = $this->map_headers( $headers );
        if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
            $this->close_file_handle( $handle );
            return new WP_Error( 'eim_csv_invalid', __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', 'calliope-media-import-export' ) );
        }

        $summary           = [
            'total_rows'              => 0,
            'rows_with_url'           => 0,
            'rows_with_relative_path' => 0,
            'rows_with_both'          => 0,
            'rows_missing_source'     => 0,
            'recommended_mode'        => 'unknown',
        ];
        $preview_rows      = [];
        $missing_row_index = [];
        $row_count         = 0;
        $preview_limit     = max( 1, absint( eim_get_setting( 'import.preview_sample_limit', 5 ) ) );

        while ( false !== ( $row = $this->read_csv_row( $handle, $delimiter ) ) ) {
            if ( $this->is_csv_row_empty( $row ) ) {
                continue;
            }

            $row_count++;
            $mapped_row = $this->build_row_from_csv( $row, $header_map );
            $summary    = $this->accumulate_csv_summary( $summary, $mapped_row );

            if ( ! empty( $summary['rows_missing_source'] ) && $summary['rows_missing_source'] === count( $missing_row_index ) + 1 && count( $missing_row_index ) < 3 ) {
                $missing_row_index[] = $row_count;
            }

            if ( count( $preview_rows ) < $preview_limit ) {
                $preview_rows[] = $this->build_preview_row( $row_count, $mapped_row );
            }
        }

        $this->close_file_handle( $handle );

        if ( $row_count <= 0 ) {
            $fallback = $this->inspect_csv_file_with_normalized_line_endings( $file_path, $delimiter );
            if ( ! is_wp_error( $fallback ) && ! empty( $fallback['total_rows'] ) ) {
                return $fallback;
            }

            $summary['recommended_mode'] = 'unknown';

            return [
                'delimiter'          => $delimiter,
                'headers'            => $headers,
                'header_map'         => $header_map,
                'recognized_columns' => $this->get_recognized_columns_for_preview( $header_map ),
                'total_rows'         => 0,
                'summary'            => $summary,
                'preview_rows'       => [],
                'warnings'           => [
                    __( 'No importable media rows were found. The CSV may only contain headers, or your export filters may have matched no media items.', 'calliope-media-import-export' ),
                ],
            ];
        }

        $summary['recommended_mode'] = $this->determine_recommended_source_mode( $summary );

        return [
            'delimiter'          => $delimiter,
            'headers'            => $headers,
            'header_map'         => $header_map,
            'recognized_columns' => $this->get_recognized_columns_for_preview( $header_map ),
            'total_rows'         => $row_count,
            'summary'            => $summary,
            'preview_rows'       => $preview_rows,
            'warnings'           => $this->build_csv_warnings( $summary, $header_map, $missing_row_index ),
        ];
    }

    private function inspect_csv_file_with_normalized_line_endings( $file_path, $delimiter ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fallback parser used only after stream parsing finds no rows.
        $contents = @file_get_contents( $file_path );
        if ( false === $contents || '' === $contents ) {
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', 'calliope-media-import-export' ) );
        }

        $contents = $this->strip_utf8_bom( (string) $contents );
        $contents = str_replace( [ "\r\n", "\r" ], "\n", $contents );
        $lines    = preg_split( "/\n/", $contents );
        if ( ! is_array( $lines ) ) {
            return new WP_Error( 'eim_csv_no_rows', __( 'The CSV has headers but no data rows to import. Export or upload a CSV with at least one media row below the header.', 'calliope-media-import-export' ) );
        }

        $rows = [];
        foreach ( $lines as $line ) {
            if ( '' === trim( (string) $line ) ) {
                continue;
            }

            $line = (string) $line;
            if ( empty( $rows ) && preg_match( '/^sep=(.)\s*$/i', trim( $line ), $matches ) ) {
                $delimiter = '\t' === $matches[1] ? "\t" : $matches[1];
                continue;
            }

            $rows[] = str_getcsv( $line, $delimiter );
        }

        if ( empty( $rows ) ) {
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', 'calliope-media-import-export' ) );
        }

        $headers = array_shift( $rows );
        if ( $this->is_csv_row_empty( $headers ) ) {
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', 'calliope-media-import-export' ) );
        }

        $header_map = $this->map_headers( $headers );
        if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
            return new WP_Error( 'eim_csv_invalid', __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', 'calliope-media-import-export' ) );
        }

        $summary           = [
            'total_rows'              => 0,
            'rows_with_url'           => 0,
            'rows_with_relative_path' => 0,
            'rows_with_both'          => 0,
            'rows_missing_source'     => 0,
            'recommended_mode'        => 'unknown',
        ];
        $preview_rows      = [];
        $missing_row_index = [];
        $row_count         = 0;
        $preview_limit     = max( 1, absint( eim_get_setting( 'import.preview_sample_limit', 5 ) ) );

        foreach ( $rows as $row ) {
            if ( $this->is_csv_row_empty( $row ) ) {
                continue;
            }

            $row_count++;
            $mapped_row = $this->build_row_from_csv( $row, $header_map );
            $summary    = $this->accumulate_csv_summary( $summary, $mapped_row );

            if ( ! empty( $summary['rows_missing_source'] ) && $summary['rows_missing_source'] === count( $missing_row_index ) + 1 && count( $missing_row_index ) < 3 ) {
                $missing_row_index[] = $row_count;
            }

            if ( count( $preview_rows ) < $preview_limit ) {
                $preview_rows[] = $this->build_preview_row( $row_count, $mapped_row );
            }
        }

        if ( $row_count <= 0 ) {
            $summary['recommended_mode'] = 'unknown';

            return [
                'delimiter'          => $delimiter,
                'headers'            => $headers,
                'header_map'         => $header_map,
                'recognized_columns' => $this->get_recognized_columns_for_preview( $header_map ),
                'total_rows'         => 0,
                'summary'            => $summary,
                'preview_rows'       => [],
                'warnings'           => [
                    __( 'No importable media rows were found. The CSV may only contain headers, or your export filters may have matched no media items.', 'calliope-media-import-export' ),
                ],
            ];
        }

        $summary['recommended_mode'] = $this->determine_recommended_source_mode( $summary );

        return [
            'delimiter'          => $delimiter,
            'headers'            => $headers,
            'header_map'         => $header_map,
            'recognized_columns' => $this->get_recognized_columns_for_preview( $header_map ),
            'total_rows'         => $row_count,
            'summary'            => $summary,
            'preview_rows'       => $preview_rows,
            'warnings'           => $this->build_csv_warnings( $summary, $header_map, $missing_row_index ),
        ];
    }

    private function detect_incompatible_import_file( $file_path ) {
        $signature = $this->read_file_signature( $file_path, 4 );

        if ( false !== $signature && in_array( $signature, [ "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" ], true ) ) {
            return new WP_Error(
                'eim_csv_zip_upload',
                __( 'The selected file is a ZIP archive. The simple import tool expects a plain CSV file, not a ZIP or export bundle.', 'calliope-media-import-export' )
            );
        }

        return null;
    }

    private function detect_csv_delimiter( $file_path ) {
        $handle = $this->open_read_handle( $file_path );
        if ( ! $handle ) {
            return new WP_Error( 'eim_csv_unreadable', __( 'Could not open the uploaded CSV file.', 'calliope-media-import-export' ) );
        }

        $sample_lines = [];
        while ( count( $sample_lines ) < 5 && false !== ( $line = fgets( $handle ) ) ) {
            $line = $this->strip_utf8_bom( (string) $line );
            if ( '' === trim( $line ) ) {
                continue;
            }

            $sample_lines[] = $line;
        }
        $this->close_file_handle( $handle );

        if ( empty( $sample_lines ) ) {
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', 'calliope-media-import-export' ) );
        }

        if ( isset( $sample_lines[0] ) && preg_match( '/^sep=(.)\s*$/i', trim( (string) $sample_lines[0] ), $matches ) ) {
            return '\t' === $matches[1] ? "\t" : $matches[1];
        }

        $candidates = [ ',', ';', "\t", '|' ];
        $best       = ',';
        $best_score = -1;

        foreach ( $candidates as $candidate ) {
            $counts = [];
            $score  = 0;

            foreach ( $sample_lines as $line ) {
                $column_count = count( str_getcsv( $line, $candidate ) );
                $counts[]     = $column_count;
                if ( $column_count > 1 ) {
                    $score += $column_count;
                }
            }

            if ( count( $counts ) === count( array_filter( $counts, function( $count ) {
                return $count > 1;
            } ) ) ) {
                $score += 100;
            }

            if ( count( array_unique( $counts ) ) === 1 && ! empty( $counts ) && $counts[0] > 1 ) {
                $score += 200;
            }

            if ( $score > $best_score ) {
                $best       = $candidate;
                $best_score = $score;
            }
        }

        return $best;
    }

    private function read_file_signature( $file_path, $length = 4 ) {
        $length = max( 1, absint( $length ) );
        $handle = $this->open_read_handle( $file_path );

        if ( ! $handle ) {
            return false;
        }

        $signature = $this->read_file_chunk( $handle, $length );
        $this->close_file_handle( $handle );

        return is_string( $signature ) ? $signature : false;
    }

    private function get_delimiter_label( $delimiter ) {
        switch ( (string) $delimiter ) {
            case ';':
                return __( 'Semicolon (;)', 'calliope-media-import-export' );
            case "\t":
                return __( 'Tab', 'calliope-media-import-export' );
            case '|':
                return __( 'Pipe (|)', 'calliope-media-import-export' );
            case ',':
            default:
                return __( 'Comma (,)', 'calliope-media-import-export' );
        }
    }

    private function read_csv_row( $handle, $delimiter, $strip_bom = false ) {
        if ( ! is_resource( $handle ) ) {
            return false;
        }

        $row = fgetcsv( $handle, 0, $delimiter );
        if ( false === $row ) {
            return false;
        }

        if ( $strip_bom && isset( $row[0] ) ) {
            $row[0] = $this->strip_utf8_bom( (string) $row[0] );

            if ( 1 === count( $row ) && preg_match( '/^sep=.+$/i', trim( (string) $row[0] ) ) ) {
                $row = fgetcsv( $handle, 0, $delimiter );
                if ( false === $row ) {
                    return false;
                }

                if ( isset( $row[0] ) ) {
                    $row[0] = $this->strip_utf8_bom( (string) $row[0] );
                }
            }
        }

        return $row;
    }

    private function strip_utf8_bom( $value ) {
        return preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value );
    }

    private function is_csv_row_empty( $row ) {
        if ( ! is_array( $row ) ) {
            return true;
        }

        foreach ( $row as $value ) {
            if ( null !== $value && '' !== trim( (string) $value ) ) {
                return false;
            }
        }

        return true;
    }

    private function accumulate_csv_summary( $summary, $row ) {
        $summary = is_array( $summary ) ? $summary : [];

        $url      = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
        $rel_path = isset( $row['rel_path'] ) ? trim( (string) $row['rel_path'] ) : '';
        $has_url  = ( '' !== $url );
        $has_path = ( '' !== $rel_path );

        $summary['total_rows'] = isset( $summary['total_rows'] ) ? absint( $summary['total_rows'] ) + 1 : 1;

        if ( $has_url ) {
            $summary['rows_with_url'] = isset( $summary['rows_with_url'] ) ? absint( $summary['rows_with_url'] ) + 1 : 1;
        }

        if ( $has_path ) {
            $summary['rows_with_relative_path'] = isset( $summary['rows_with_relative_path'] ) ? absint( $summary['rows_with_relative_path'] ) + 1 : 1;
        }

        if ( $has_url && $has_path ) {
            $summary['rows_with_both'] = isset( $summary['rows_with_both'] ) ? absint( $summary['rows_with_both'] ) + 1 : 1;
        }

        if ( ! $has_url && ! $has_path ) {
            $summary['rows_missing_source'] = isset( $summary['rows_missing_source'] ) ? absint( $summary['rows_missing_source'] ) + 1 : 1;
        }

        return $summary;
    }

    private function build_preview_row( $row_number, $row ) {
        return [
            'row_number'    => absint( $row_number ),
            'source'        => isset( $row['url'] ) ? trim( (string) $row['url'] ) : '',
            'relative_path' => isset( $row['rel_path'] ) ? trim( (string) $row['rel_path'] ) : '',
            'title'         => isset( $row['title'] ) ? trim( (string) $row['title'] ) : '',
            'alt'           => isset( $row['alt'] ) ? trim( (string) $row['alt'] ) : '',
        ];
    }

    private function determine_recommended_source_mode( $summary ) {
        $with_url   = isset( $summary['rows_with_url'] ) ? absint( $summary['rows_with_url'] ) : 0;
        $with_path  = isset( $summary['rows_with_relative_path'] ) ? absint( $summary['rows_with_relative_path'] ) : 0;
        $total_rows = isset( $summary['total_rows'] ) ? absint( $summary['total_rows'] ) : 0;

        if ( $total_rows <= 0 ) {
            return 'unknown';
        }

        if ( $with_url > 0 && 0 === $with_path ) {
            return 'remote';
        }

        if ( $with_path > 0 && 0 === $with_url ) {
            return 'local';
        }

        if ( $with_url > 0 && $with_path > 0 ) {
            return 'mixed';
        }

        return 'unknown';
    }

    private function get_recognized_columns_for_preview( $header_map ) {
        $definitions = $this->get_import_header_definitions();

        $recognized = [];
        foreach ( $definitions as $key => $definition ) {
            if ( isset( $header_map[ $key ] ) && ! empty( $definition['label'] ) ) {
                $recognized[] = (string) $definition['label'];
            }
        }

        return $recognized;
    }

    private function get_import_header_definitions() {
        $definitions = [
            'id'          => [
                'aliases' => [ 'id', 'attachment id', 'media id', 'id del adjunto', 'id do anexo', 'id allegato', 'id de la pièce jointe' ],
                'label'   => __( 'ID', 'calliope-media-import-export' ),
            ],
            'url'         => [
                'aliases' => [ 'absolute url', 'url', 'absolute_url', 'source url', 'source_url', 'url absoluta', 'url absoluto', 'url absolue', 'url assoluto', '绝对 url' ],
                'label'   => __( 'Absolute URL', 'calliope-media-import-export' ),
            ],
            'rel_path'    => [
                'aliases' => [ 'relative path', 'relative_path', 'path', 'ruta relativa', 'caminho relativo', 'percorso relativo', 'chemin relatif', '相对路径' ],
                'label'   => __( 'Relative Path', 'calliope-media-import-export' ),
            ],
            'title'       => [
                'aliases' => [ 'title', 'post_title', 'título', 'titulo', 'titre', 'titolo', '标题' ],
                'label'   => __( 'Title', 'calliope-media-import-export' ),
            ],
            'alt'         => [
                'aliases' => [ 'alt text', 'alt', 'alternative text', 'texto alternativo', 'texto alt', 'texte alternatif', 'testo alternativo', '替代文本' ],
                'label'   => __( 'Alt Text', 'calliope-media-import-export' ),
            ],
            'caption'     => [
                'aliases' => [ 'caption', 'post_excerpt', 'subtítulo', 'subtitulo', 'legenda', 'légende', 'didascalia' ],
                'label'   => __( 'Caption', 'calliope-media-import-export' ),
            ],
            'description' => [
                'aliases' => [ 'description', 'post_content', 'descripción', 'descripcion', 'descrição', 'descricao', 'descrizione', '描述' ],
                'label'   => __( 'Description', 'calliope-media-import-export' ),
            ],
        ];

        return apply_filters( 'eim_import_header_definitions', $definitions );
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

    private function build_csv_warnings( $summary, $header_map, $missing_row_index ) {
        $warnings = [];

        if ( ! isset( $header_map['url'] ) && isset( $header_map['rel_path'] ) ) {
            $warnings[] = __( 'This CSV relies on Relative Path. Use Local Import Mode or make sure the referenced files already exist in uploads.', 'calliope-media-import-export' );
        }

        if ( isset( $summary['rows_missing_source'] ) && absint( $summary['rows_missing_source'] ) > 0 ) {
            $count = absint( $summary['rows_missing_source'] );
            $rows  = implode( ', ', array_map( 'absint', (array) $missing_row_index ) );
            /* translators: %s: comma-separated CSV row numbers. */
            $note  = $rows ? sprintf( __( ' Example rows: %s.', 'calliope-media-import-export' ), $rows ) : '';

            $warnings[] = sprintf(
                /* translators: %d: number of CSV rows missing source fields. */
                _n(
                    '%d row is missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                    '%d rows are missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                    $count,
                    'calliope-media-import-export'
                ),
                $count
            ) . $note;
        }

        if ( ! isset( $header_map['title'] ) && ! isset( $header_map['alt'] ) && ! isset( $header_map['caption'] ) && ! isset( $header_map['description'] ) ) {
            $warnings[] = __( 'Only source columns were detected. Media metadata fields will not be updated from this CSV.', 'calliope-media-import-export' );
        }

        return array_values( array_filter( $warnings ) );
    }

    private function create_temp_import_file( $source_path, $inspection ) {
        $temp_dir = $this->ensure_temp_dir();
        if ( is_wp_error( $temp_dir ) ) {
            return $temp_dir;
        }

        $token        = str_replace( '-', '', wp_generate_uuid4() );
        $base_name    = 'import-' . sanitize_key( $token );
        $csv_filename = $base_name . '.csv';
        $csv_path     = trailingslashit( $temp_dir ) . $csv_filename;
        $meta_path    = trailingslashit( $temp_dir ) . $base_name . '.meta.json';

        if ( ! $this->copy_file_streaming( $source_path, $csv_path ) ) {
            return new WP_Error( 'eim_temp_copy_failed', __( 'Could not prepare the temporary CSV file.', 'calliope-media-import-export' ) );
        }

        $meta = [
            'delimiter'  => isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',',
            'total_rows' => isset( $inspection['total_rows'] ) ? absint( $inspection['total_rows'] ) : 0,
            'created_at' => time(),
            'created_by' => get_current_user_id(),
        ];

        $encoded_meta = wp_json_encode( $meta );
        if ( false === $encoded_meta || false === @file_put_contents( $meta_path, $encoded_meta, LOCK_EX ) ) {
            wp_delete_file( $csv_path );
            return new WP_Error( 'eim_temp_meta_failed', __( 'Could not store temporary import metadata.', 'calliope-media-import-export' ) );
        }

        $this->reset_import_progress_log( $csv_filename );

        return [
            'file' => $csv_filename,
        ];
    }

    private function ensure_temp_dir() {
        $temp_dir = $this->get_temp_dir();

        if ( ! file_exists( $temp_dir ) && ! wp_mkdir_p( $temp_dir ) ) {
            return new WP_Error( 'eim_temp_dir_failed', __( 'Could not create the temporary import folder.', 'calliope-media-import-export' ) );
        }

        if ( ! is_dir( $temp_dir ) || ! $this->is_path_writable( $temp_dir ) ) {
            return new WP_Error( 'eim_temp_dir_unwritable', __( 'The temporary import folder is not writable.', 'calliope-media-import-export' ) );
        }

        $this->write_temp_dir_guards( $temp_dir );

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
            if ( ! file_exists( $path ) ) {
                @file_put_contents( $path, $contents, LOCK_EX );
            }
        }
    }

    private function copy_file_streaming( $source_path, $destination_path ) {
        $source = $this->open_read_handle( $source_path );
        if ( ! $source ) {
            return false;
        }

        $destination = $this->open_write_handle( $destination_path );
        if ( ! $destination ) {
            $this->close_file_handle( $source );
            return false;
        }

        $copied = stream_copy_to_stream( $source, $destination );

        $this->close_file_handle( $source );
        $this->close_file_handle( $destination );

        return false !== $copied;
    }

    private function get_temp_file_paths( $file_name ) {
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

    private function read_temp_file_meta( $file_name ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }

        if ( ! file_exists( $paths['meta'] ) ) {
            if ( file_exists( $paths['csv'] ) ) {
                return $this->rebuild_temp_file_meta( $paths['csv'], $paths['meta'] );
            }

            return new WP_Error( 'eim_temp_meta_missing', __( 'Temporary import metadata not found. Please upload the CSV again.', 'calliope-media-import-export' ) );
        }

        $raw_meta = @file_get_contents( $paths['meta'] );
        if ( false === $raw_meta || '' === trim( (string) $raw_meta ) ) {
            return $this->rebuild_temp_file_meta( $paths['csv'], $paths['meta'] );
        }

        $meta = json_decode( $raw_meta, true );
        if ( ! is_array( $meta ) ) {
            return $this->rebuild_temp_file_meta( $paths['csv'], $paths['meta'] );
        }

        return $meta;
    }

    private function rebuild_temp_file_meta( $csv_path, $meta_path ) {
        if ( ! file_exists( $csv_path ) ) {
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
        if ( false !== $encoded_meta ) {
            @file_put_contents( $meta_path, $encoded_meta, LOCK_EX );
        }

        return $meta;
    }

    private function cleanup_temp_import_file( $file_name ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return;
        }

        if ( file_exists( $paths['csv'] ) ) {
            wp_delete_file( $paths['csv'] );
        }

        if ( file_exists( $paths['meta'] ) ) {
            wp_delete_file( $paths['meta'] );
        }

        if ( file_exists( $paths['progress'] ) ) {
            wp_delete_file( $paths['progress'] );
        }
    }

    private function reset_import_progress_log( $file_name ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            return;
        }

        if ( file_exists( $paths['progress'] ) ) {
            wp_delete_file( $paths['progress'] );
        }

        @file_put_contents( $paths['progress'], '', LOCK_EX );
    }

    private function append_import_progress( $file_name, $result ) {
        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) || ! is_array( $result ) ) {
            return;
        }

        $cursor = isset( $result['row_number'] ) ? absint( $result['row_number'] ) : 0;
        if ( $cursor <= 0 ) {
            return;
        }

        $entry = [
            'cursor'     => $cursor,
            'created_at' => time(),
            'result'     => $result,
        ];

        $encoded = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $encoded ) {
            return;
        }

        @file_put_contents( $paths['progress'], $encoded . "\n", FILE_APPEND | LOCK_EX );
    }

    private function get_temp_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'eim-temp/';
    }

    private function get_temp_lock_key( $file_name ) {
        return 'eim_import_lock_' . md5( (string) $file_name );
    }

    private function acquire_temp_lock( $lock_key, $ttl = null ) {
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

    private function release_temp_lock( $lock_key ) {
        delete_transient( $lock_key );
    }

    private function validate_existing_media_file( $file_path ) {
        $filename      = wp_basename( $file_path );
        if ( $this->is_svg_import_file( $file_path, $filename ) ) {
            $svg_validation = $this->maybe_validate_svg_import_file( $file_path, $filename );
            if ( is_wp_error( $svg_validation ) ) {
                return $svg_validation;
            }

            return [
                'mime'     => 'image/svg+xml',
                'ext'      => 'svg',
                'filename' => sanitize_file_name( $filename ),
            ];
        }

        $allowed_mimes = apply_filters( 'eim_allowed_local_mimes', $this->get_import_allowed_mimes() );
        $filetype      = wp_check_filetype_and_ext( $file_path, $filename, $allowed_mimes );
        $mime          = ! empty( $filetype['type'] ) ? (string) $filetype['type'] : '';
        $ext           = ! empty( $filetype['ext'] ) ? (string) $filetype['ext'] : '';
        $major_type    = strtok( $mime, '/' );

        if ( '' === $mime || '' === $ext ) {
            return new WP_Error( 'eim_local_type_invalid', __( 'Local file type is not allowed.', 'calliope-media-import-export' ) );
        }

        if ( ! in_array( $major_type, [ 'image', 'video', 'audio', 'application' ], true ) ) {
            return new WP_Error( 'eim_local_type_invalid', __( 'Local file type is not supported by this plugin.', 'calliope-media-import-export' ) );
        }

        if ( ! empty( $filetype['proper_filename'] ) ) {
            $filename = $filetype['proper_filename'];
        }

        return [
            'mime'     => $mime,
            'ext'      => $ext,
            'filename' => sanitize_file_name( $filename ),
        ];
    }

    private function derive_filename( $url, $rel_path = '' ) {
        $candidate = '';

        if ( '' !== $rel_path ) {
            $candidate = wp_basename( $rel_path );
        } elseif ( '' !== $url ) {
            $path = wp_parse_url( $url, PHP_URL_PATH );
            if ( is_string( $path ) && '' !== $path ) {
                $candidate = wp_basename( $path );
            }
        }

        $candidate = urldecode( (string) $candidate );
        $candidate = sanitize_file_name( $candidate );

        return '' !== $candidate ? $candidate : 'media-file';
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
        // Help PHP read legacy CSV files that use old Mac-style CR line endings.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort compatibility setting before opening a stream.
        @ini_set( 'auto_detect_line_endings', '1' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV and binary files for import processing.
        return @fopen( $file_path, 'rb' );
    }

    private function open_write_handle( $file_path ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV and temporary files for import processing.
        return @fopen( $file_path, 'wb' );
    }

    private function close_file_handle( $handle ) {
        if ( is_resource( $handle ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing an already opened stream handle.
            fclose( $handle );
        }
    }

    private function read_file_chunk( $handle, $length ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading a small binary chunk from an already opened stream handle.
        return fread( $handle, $length );
    }

    private function is_path_writable( $path ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Temp directory validation before creating import files.
        return is_writable( $path );
    }

    public function cleanup_temp_files() {
        $temp_dir = $this->get_temp_dir();
        if ( ! is_dir( $temp_dir ) ) {
            return;
        }

        $files = @scandir( $temp_dir );
        if ( ! is_array( $files ) ) {
            return;
        }

        $cutoff = time() - self::TEMP_FILE_TTL;
        foreach ( $files as $file ) {
            if ( in_array( $file, [ '.', '..', 'index.php', '.htaccess', 'web.config' ], true ) ) {
                continue;
            }

            $file_path = trailingslashit( $temp_dir ) . $file;
            if ( ! is_file( $file_path ) ) {
                continue;
            }

            $last_modified = @filemtime( $file_path );
            if ( false !== $last_modified && $last_modified < $cutoff ) {
                wp_delete_file( $file_path );
            }
        }
    }
}
