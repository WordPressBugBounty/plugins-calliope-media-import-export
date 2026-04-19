<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Importer {

    const MAX_BATCH_SIZE = 500;
    const TEMP_FILE_TTL  = DAY_IN_SECONDS;
    const LOCK_TTL       = 90;

    public function __construct() {
        add_action( 'wp_ajax_eim_validate_csv', [ $this, 'validate_csv' ] );
        add_action( 'wp_ajax_eim_process_batch', [ $this, 'process_batch' ] );
        add_action( 'eim_daily_cleanup_event', [ $this, 'cleanup_temp_files' ] );
    }

    public static function activate_plugin() {
        if ( ! wp_next_scheduled( 'eim_daily_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'eim_daily_cleanup_event' );
        }
    }

    public static function deactivate_plugin() {
        wp_clear_scheduled_hook( 'eim_daily_cleanup_event' );
    }

    public function validate_csv() {
        $this->ensure_ajax_permissions();

        if ( empty( $_FILES['eim_csv'] ) || empty( $_FILES['eim_csv']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', EIM_TEXT_DOMAIN ) ], 400 );
        }

        $tmp_name = wp_unslash( $_FILES['eim_csv']['tmp_name'] );
        if ( ! is_string( $tmp_name ) || '' === $tmp_name || ( ! is_uploaded_file( $tmp_name ) && ! file_exists( $tmp_name ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Error uploading file.', EIM_TEXT_DOMAIN ) ], 400 );
        }

        $inspection = $this->inspect_csv_file( $tmp_name );
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

    public function process_batch() {
        $this->ensure_ajax_permissions();

        @set_time_limit( 0 );

        $start_time      = time();
        $time_limit      = 20;
        $file_name       = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
        $start_row       = $this->get_request_absint( 'start_row' );
        $batch_size      = $this->get_bounded_batch_size();
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

        $paths = $this->get_temp_file_paths( $file_name );
        if ( is_wp_error( $paths ) ) {
            $this->send_batch_error( $paths->get_error_message(), 400 );
        }

        if ( ! file_exists( $paths['csv'] ) ) {
            $this->send_batch_error( __( 'Temporary file not found. Please upload the CSV again.', EIM_TEXT_DOMAIN ), 404 );
        }

        if ( ! $this->acquire_temp_lock( $lock_key ) ) {
            $this->send_batch_error( __( 'Another import request is already processing this file. Please wait a moment and try again.', EIM_TEXT_DOMAIN ), 409 );
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
                $handle = @fopen( $paths['csv'], 'rb' );
                if ( ! $handle ) {
                    throw new RuntimeException( __( 'Could not open the temporary CSV file.', EIM_TEXT_DOMAIN ) );
                }

                $headers = $this->read_csv_row( $handle, $delimiter, true );
                if ( false === $headers ) {
                    throw new RuntimeException( __( 'Could not read the CSV headers.', EIM_TEXT_DOMAIN ) );
                }

                $header_map = $this->map_headers( $headers );
                if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
                    throw new RuntimeException( __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', EIM_TEXT_DOMAIN ) );
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
                    if ( ( time() - $start_time ) >= $time_limit ) {
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
                    $result = $this->process_single_item( $row, $local_import, $honor_rel_path );

                    $result['row_number'] = $current_row;
                    if ( isset( $result['file'] ) ) {
                        $result['file'] = '#' . $current_row . ' - ' . $result['file'];
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

        wp_send_json_success(
            $this->build_batch_response(
                $results,
                $batch_summary,
                [
                    'start_row'       => $start_row,
                    'next_row'        => $next_row,
                    'processed_rows'  => $processed_batch,
                    'total_rows'      => $total_rows,
                    'is_finished'     => $is_finished,
                    'local_import'    => $local_import,
                    'skip_thumbnails' => $skip_thumbnails,
                    'honor_rel_path'  => $honor_rel_path,
                    'file'            => $file_name,
                ]
            )
        );
    }

    private function process_single_item( $row, $local_import, $honor_relative_path = true ) {
        $row = is_array( $row ) ? $row : [];
        $row = apply_filters(
            'eim_import_row_data',
            $row,
            [
                'local_import'        => (bool) $local_import,
                'honor_relative_path' => (bool) $honor_relative_path,
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

        $filename = $this->derive_filename( $url, $rel_path );
        $url      = apply_filters( 'eim_pre_import_url', $url, $row );
        $context  = [
            'local_import'        => (bool) $local_import,
            'honor_relative_path' => (bool) $honor_relative_path,
            'csv_id'              => $csv_id,
            'url'                 => $url,
            'relative_path'       => $rel_path,
            'filename'            => $filename,
        ];

        $validation = $this->validate_row_via_hooks( $row, $context );
        if ( is_wp_error( $validation ) ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                $validation->get_error_message(),
                [ 'reason' => 'custom_validation_failed' ]
            );
        }

        do_action( 'eim_before_import_media', $row, $context );

        if ( '' === $url && '' === $rel_path ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'Row is missing both "Absolute URL" and "Relative Path".', EIM_TEXT_DOMAIN ),
                [ 'reason' => 'missing_source' ]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $existing_id = $this->find_existing_attachment_id( $url, $rel_path, null, $filename, '' );
        if ( $existing_id ) {
            $this->backfill_source_meta( $existing_id, $url, $rel_path );
            return $this->build_item_result(
                'SKIPPED',
                $filename,
                sprintf( __( 'Duplicate detected (ID %d)', EIM_TEXT_DOMAIN ), (int) $existing_id ),
                [
                    'reason'        => 'duplicate_existing',
                    'attachment_id' => (int) $existing_id,
                ]
            );
        }

        $id_match = $this->maybe_match_existing_attachment_by_csv_id( $csv_id, $url, $rel_path );
        if ( $id_match ) {
            $this->backfill_source_meta( $id_match, $url, $rel_path );
            return $this->build_item_result(
                'SKIPPED',
                $filename,
                sprintf( __( 'Matched existing attachment (ID %d)', EIM_TEXT_DOMAIN ), (int) $id_match ),
                [
                    'reason'        => 'csv_id_match',
                    'attachment_id' => (int) $id_match,
                ]
            );
        }

        if ( $local_import ) {
            if ( '' === $rel_path ) {
                return $this->build_item_result(
                    'ERROR',
                    $filename,
                    __( 'Local Import Mode requires a valid "Relative Path" value.', EIM_TEXT_DOMAIN ),
                    [ 'reason' => 'missing_relative_path' ]
                );
            }

            $upload_dir  = wp_upload_dir();
            $source_file = trailingslashit( $upload_dir['basedir'] ) . ltrim( $rel_path, '/' );

            return $this->attach_existing_media_file(
                $source_file,
                $filename,
                $title,
                $alt,
                $caption,
                $description,
                $url,
                $rel_path,
                $row
            );
        }

        if ( '' === $url ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'Absolute URL is missing. Provide a URL or enable Local Import Mode for Relative Path imports.', EIM_TEXT_DOMAIN ),
                [ 'reason' => 'missing_url' ]
            );
        }

        if ( ! wp_http_validate_url( $url ) ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                __( 'The "Absolute URL" value is not valid.', EIM_TEXT_DOMAIN ),
                [ 'reason' => 'invalid_url' ]
            );
        }

        if ( $honor_relative_path && '' !== $rel_path ) {
            $upload_dir    = wp_upload_dir();
            $existing_file = trailingslashit( $upload_dir['basedir'] ) . ltrim( $rel_path, '/' );

            if ( file_exists( $existing_file ) && $this->is_path_inside_uploads( $existing_file ) ) {
                return $this->attach_existing_media_file(
                    $existing_file,
                    $filename,
                    $title,
                    $alt,
                    $caption,
                    $description,
                    $url,
                    $rel_path,
                    $row
                );
            }
        }

        $tmp_file = download_url( $url );
        if ( is_wp_error( $tmp_file ) ) {
            return $this->build_item_result(
                'ERROR',
                $filename,
                sprintf( __( 'Download error: %s', EIM_TEXT_DOMAIN ), $tmp_file->get_error_message() ),
                [ 'reason' => 'download_error' ]
            );
        }

        $filename   = apply_filters( 'eim_import_filename', $filename, $row );
        $filename   = sanitize_file_name( (string) $filename );
        $file_array = [
            'name'     => $filename ? $filename : 'media-file',
            'tmp_name' => $tmp_file,
        ];

        $fingerprint = $this->get_file_fingerprint( $tmp_file );
        $existing_id = $this->find_existing_attachment_id( $url, $rel_path, $tmp_file, $filename, $fingerprint );

        if ( $existing_id ) {
            @unlink( $tmp_file );
            $this->backfill_source_meta( $existing_id, $url, $rel_path );
            if ( $fingerprint ) {
                $this->backfill_fingerprint_meta( $existing_id, $fingerprint );
            }

            return $this->build_item_result(
                'SKIPPED',
                $filename,
                sprintf( __( 'Duplicate detected (ID %d)', EIM_TEXT_DOMAIN ), (int) $existing_id ),
                [
                    'reason'        => 'duplicate_existing',
                    'attachment_id' => (int) $existing_id,
                ]
            );
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
                @unlink( $tmp_file );
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

        $this->store_source_meta( $id, $url, $rel_path );
        if ( $fingerprint ) {
            $this->store_fingerprint_meta( $id, $fingerprint );
        }

        do_action( 'eim_after_import_image', $id, $row );
        do_action( 'eim_after_import_media', $id, $row );

        return $this->build_item_result(
            'IMPORTED',
            $filename,
            sprintf( __( 'Imported successfully (ID %d)', EIM_TEXT_DOMAIN ), (int) $id ),
            [
                'reason'        => 'imported',
                'attachment_id' => (int) $id,
                'import_method' => 'remote',
            ]
        );
    }

    private function attach_existing_media_file( $file_path, $filename, $title, $alt, $caption, $description, $url, $rel_path, $row ) {
        if ( ! file_exists( $file_path ) ) {
            return $this->build_item_result( 'ERROR', $filename, __( 'Local file not found.', EIM_TEXT_DOMAIN ) );
        }

        if ( ! $this->is_path_inside_uploads( $file_path ) ) {
            return $this->build_item_result( 'ERROR', $filename, __( 'Invalid local path.', EIM_TEXT_DOMAIN ) );
        }

        $validated = $this->validate_existing_media_file( $file_path );
        if ( is_wp_error( $validated ) ) {
            return $this->build_item_result( 'ERROR', $filename, $validated->get_error_message() );
        }

        $final_filename = $filename ? $filename : $validated['filename'];
        $fingerprint    = $this->get_file_fingerprint( $file_path );
        $existing_id    = $this->find_existing_attachment_id( $url, $rel_path, $file_path, $final_filename, $fingerprint );

        if ( $existing_id ) {
            $this->backfill_source_meta( $existing_id, $url, $rel_path );
            if ( $fingerprint ) {
                $this->backfill_fingerprint_meta( $existing_id, $fingerprint );
            }

            return $this->build_item_result(
                'SKIPPED',
                $final_filename,
                sprintf( __( 'Duplicate detected (ID %d)', EIM_TEXT_DOMAIN ), (int) $existing_id ),
                [
                    'reason'        => 'duplicate_existing',
                    'attachment_id' => (int) $existing_id,
                ]
            );
        }

        $attachment = [
            'post_mime_type' => $validated['mime'],
            'post_title'     => $title ? $title : ( $final_filename ? $final_filename : __( 'Media', EIM_TEXT_DOMAIN ) ),
            'post_content'   => $description,
            'post_excerpt'   => $caption,
            'post_status'    => 'inherit',
        ];

        $id = wp_insert_attachment( $attachment, $file_path, 0 );
        if ( is_wp_error( $id ) ) {
            return $this->build_item_result(
                'ERROR',
                $final_filename,
                $id->get_error_message(),
                [ 'reason' => 'wp_insert_attachment_error' ]
            );
        }

        update_attached_file( $id, $file_path );

        $attach_data = wp_generate_attachment_metadata( $id, $file_path );
        if ( ! empty( $attach_data ) && ! is_wp_error( $attach_data ) ) {
            wp_update_attachment_metadata( $id, $attach_data );
        }

        if ( $alt && 0 === strpos( $validated['mime'], 'image/' ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', $alt );
        }

        $this->store_source_meta( $id, $url, $rel_path );
        if ( $fingerprint ) {
            $this->store_fingerprint_meta( $id, $fingerprint );
        }

        do_action( 'eim_after_import_image', $id, $row );
        do_action( 'eim_after_import_media', $id, $row );

        return $this->build_item_result(
            'IMPORTED',
            $final_filename,
            sprintf( __( 'Imported successfully (ID %d)', EIM_TEXT_DOMAIN ), (int) $id ),
            [
                'reason'        => 'imported',
                'attachment_id' => (int) $id,
                'import_method' => 'local',
            ]
        );
    }

    private function find_existing_attachment_id( $url, $rel_path, $incoming_file_path = null, $filename = '', $incoming_fingerprint = '' ) {
        global $wpdb;

        $url      = is_string( $url ) ? trim( $url ) : '';
        $rel_path = is_string( $rel_path ) ? trim( $rel_path ) : '';
        $filename = is_string( $filename ) ? trim( $filename ) : '';

        if ( '' !== $url ) {
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

        if ( '' !== $rel_path ) {
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

        if ( '' !== $url ) {
            $local_id = (int) attachment_url_to_postid( $url );
            if ( $local_id ) {
                return $local_id;
            }
        }

        if ( empty( $incoming_file_path ) || ! is_string( $incoming_file_path ) || ! file_exists( $incoming_file_path ) ) {
            return 0;
        }

        $fingerprint = $incoming_fingerprint ? (string) $incoming_fingerprint : $this->get_file_fingerprint( $incoming_file_path );
        if ( $fingerprint ) {
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

        if ( $filename && $fingerprint ) {
            $candidates = $this->find_attachments_by_name_candidates( $filename, $rel_path );

            foreach ( $candidates as $candidate_id ) {
                $candidate_id = absint( $candidate_id );
                if ( ! $candidate_id ) {
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

    private function maybe_match_existing_attachment_by_csv_id( $csv_id, $url, $rel_path ) {
        $csv_id = absint( $csv_id );
        if ( ! $csv_id || 'attachment' !== get_post_type( $csv_id ) ) {
            return 0;
        }

        $allow_match = (bool) apply_filters( 'eim_allow_csv_id_match', false, $csv_id, $url, $rel_path );
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

        $handle = @fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return '';
        }

        $first     = @fread( $handle, $chunk_bytes );
        $first_md5 = false !== $first ? md5( $first ) : '';
        $last_md5  = '';

        if ( $size > $chunk_bytes ) {
            @fseek( $handle, -$chunk_bytes, SEEK_END );
            $last     = @fread( $handle, $chunk_bytes );
            $last_md5 = false !== $last ? md5( $last ) : '';
        } else {
            $last_md5 = $first_md5;
        }

        @fclose( $handle );

        if ( '' === $first_md5 || '' === $last_md5 ) {
            return '';
        }

        return sha1( $size . '|' . $first_md5 . '|' . $last_md5 );
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

    private function media_handle_sideload_with_subdir( $file_array, $subdir = '' ) {
        $subdir = trim( (string) $subdir );

        if ( '' === $subdir ) {
            return media_handle_sideload( $file_array, 0 );
        }

        $subdir = '/' . trim( $subdir, '/' );

        if ( false !== strpos( $subdir, '..' ) ) {
            return new WP_Error( 'eim_invalid_subdir', __( 'Invalid target folder.', EIM_TEXT_DOMAIN ) );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'eim_upload_dir_error', $uploads['error'] );
        }

        $target_dir = trailingslashit( $uploads['basedir'] ) . ltrim( $subdir, '/' );
        if ( ! file_exists( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'eim_upload_dir_error', __( 'Could not create the target upload folder.', EIM_TEXT_DOMAIN ) );
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
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', EIM_TEXT_DOMAIN ) ], 403 );
        }
    }

    private function get_request_bool( $key, $default = false ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return (bool) $default;
        }

        $value = filter_var( wp_unslash( $_POST[ $key ] ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        if ( null === $value ) {
            return (bool) $default;
        }

        return (bool) $value;
    }

    private function get_request_absint( $key ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return 0;
        }

        return absint( wp_unslash( $_POST[ $key ] ) );
    }

    private function get_bounded_batch_size() {
        $batch_size = $this->get_request_absint( 'batch_size' );
        if ( $batch_size <= 0 ) {
            $batch_size = absint( eim_get_setting( 'import.default_batch_size', 25 ) );
        }

        return min( self::MAX_BATCH_SIZE, max( 1, $batch_size ) );
    }

    private function send_batch_error( $message, $status_code = 400 ) {
        wp_send_json_error(
            [
                'message' => $message,
                'results' => [
                    [
                        'status'  => 'ERROR',
                        'file'    => __( 'System', EIM_TEXT_DOMAIN ),
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
            'processed' => 0,
            'imported'  => 0,
            'skipped'   => 0,
            'errors'    => 0,
        ];
    }

    private function increment_result_summary( $summary, $result ) {
        if ( ! is_array( $summary ) ) {
            $summary = $this->get_empty_result_summary();
        }

        $summary['processed']++;

        $status = isset( $result['status'] ) ? strtoupper( (string) $result['status'] ) : '';
        if ( 'IMPORTED' === $status ) {
            $summary['imported']++;
        } elseif ( 'SKIPPED' === $status ) {
            $summary['skipped']++;
        } elseif ( 'ERROR' === $status ) {
            $summary['errors']++;
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
            return new WP_Error( 'eim_csv_missing', __( 'Could not read the uploaded CSV file.', EIM_TEXT_DOMAIN ) );
        }

        $delimiter = $this->detect_csv_delimiter( $file_path );
        if ( is_wp_error( $delimiter ) ) {
            return $delimiter;
        }

        $handle = @fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'eim_csv_unreadable', __( 'Could not open the uploaded CSV file.', EIM_TEXT_DOMAIN ) );
        }

        $headers = $this->read_csv_row( $handle, $delimiter, true );
        if ( false === $headers || $this->is_csv_row_empty( $headers ) ) {
            fclose( $handle );
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', EIM_TEXT_DOMAIN ) );
        }

        $header_map = $this->map_headers( $headers );
        if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
            fclose( $handle );
            return new WP_Error( 'eim_csv_invalid', __( 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.', EIM_TEXT_DOMAIN ) );
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

        fclose( $handle );

        if ( $row_count <= 0 ) {
            return new WP_Error( 'eim_csv_no_rows', __( 'The CSV contains no data rows.', EIM_TEXT_DOMAIN ) );
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

    private function detect_csv_delimiter( $file_path ) {
        $handle = @fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'eim_csv_unreadable', __( 'Could not open the uploaded CSV file.', EIM_TEXT_DOMAIN ) );
        }

        $sample_lines = [];
        while ( count( $sample_lines ) < 5 && false !== ( $line = fgets( $handle ) ) ) {
            $line = $this->strip_utf8_bom( (string) $line );
            if ( '' === trim( $line ) ) {
                continue;
            }

            $sample_lines[] = $line;
        }
        fclose( $handle );

        if ( empty( $sample_lines ) ) {
            return new WP_Error( 'eim_csv_empty', __( 'The CSV is empty.', EIM_TEXT_DOMAIN ) );
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

    private function get_delimiter_label( $delimiter ) {
        switch ( (string) $delimiter ) {
            case ';':
                return __( 'Semicolon (;)', EIM_TEXT_DOMAIN );
            case "\t":
                return __( 'Tab', EIM_TEXT_DOMAIN );
            case '|':
                return __( 'Pipe (|)', EIM_TEXT_DOMAIN );
            case ',':
            default:
                return __( 'Comma (,)', EIM_TEXT_DOMAIN );
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
                'aliases' => [ 'id', 'attachment id', 'media id' ],
                'label'   => 'ID',
            ],
            'url'         => [
                'aliases' => [ 'absolute url', 'url' ],
                'label'   => 'Absolute URL',
            ],
            'rel_path'    => [
                'aliases' => [ 'relative path', 'path' ],
                'label'   => 'Relative Path',
            ],
            'title'       => [
                'aliases' => [ 'title', 'post_title' ],
                'label'   => 'Title',
            ],
            'alt'         => [
                'aliases' => [ 'alt text', 'alt' ],
                'label'   => 'Alt Text',
            ],
            'caption'     => [
                'aliases' => [ 'caption', 'post_excerpt' ],
                'label'   => 'Caption',
            ],
            'description' => [
                'aliases' => [ 'description', 'post_content' ],
                'label'   => 'Description',
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
            return new WP_Error( 'eim_import_row_invalid', __( 'This row did not pass import validation.', EIM_TEXT_DOMAIN ) );
        }

        if ( is_string( $validation ) && '' !== trim( $validation ) ) {
            return new WP_Error( 'eim_import_row_invalid', trim( $validation ) );
        }

        return true;
    }

    private function build_csv_warnings( $summary, $header_map, $missing_row_index ) {
        $warnings = [];

        if ( ! isset( $header_map['url'] ) && isset( $header_map['rel_path'] ) ) {
            $warnings[] = __( 'This CSV relies on Relative Path. Use Local Import Mode or make sure the referenced files already exist in uploads.', EIM_TEXT_DOMAIN );
        }

        if ( isset( $summary['rows_missing_source'] ) && absint( $summary['rows_missing_source'] ) > 0 ) {
            $count = absint( $summary['rows_missing_source'] );
            $rows  = implode( ', ', array_map( 'absint', (array) $missing_row_index ) );
            $note  = $rows ? sprintf( __( ' Example rows: %s.', EIM_TEXT_DOMAIN ), $rows ) : '';

            $warnings[] = sprintf(
                /* translators: %d: number of CSV rows missing source fields. */
                _n(
                    '%d row is missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                    '%d rows are missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                    $count,
                    EIM_TEXT_DOMAIN
                ),
                $count
            ) . $note;
        }

        if ( ! isset( $header_map['title'] ) && ! isset( $header_map['alt'] ) && ! isset( $header_map['caption'] ) && ! isset( $header_map['description'] ) ) {
            $warnings[] = __( 'Only source columns were detected. Media metadata fields will not be updated from this CSV.', EIM_TEXT_DOMAIN );
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
            return new WP_Error( 'eim_temp_copy_failed', __( 'Could not prepare the temporary CSV file.', EIM_TEXT_DOMAIN ) );
        }

        $meta = [
            'delimiter'  => isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',',
            'total_rows' => isset( $inspection['total_rows'] ) ? absint( $inspection['total_rows'] ) : 0,
            'created_at' => time(),
            'created_by' => get_current_user_id(),
        ];

        $encoded_meta = wp_json_encode( $meta );
        if ( false === $encoded_meta || false === @file_put_contents( $meta_path, $encoded_meta, LOCK_EX ) ) {
            @unlink( $csv_path );
            return new WP_Error( 'eim_temp_meta_failed', __( 'Could not store temporary import metadata.', EIM_TEXT_DOMAIN ) );
        }

        return [
            'file' => $csv_filename,
        ];
    }

    private function ensure_temp_dir() {
        $temp_dir = $this->get_temp_dir();

        if ( ! file_exists( $temp_dir ) && ! wp_mkdir_p( $temp_dir ) ) {
            return new WP_Error( 'eim_temp_dir_failed', __( 'Could not create the temporary import folder.', EIM_TEXT_DOMAIN ) );
        }

        if ( ! is_dir( $temp_dir ) || ! is_writable( $temp_dir ) ) {
            return new WP_Error( 'eim_temp_dir_unwritable', __( 'The temporary import folder is not writable.', EIM_TEXT_DOMAIN ) );
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
        $source = @fopen( $source_path, 'rb' );
        if ( ! $source ) {
            return false;
        }

        $destination = @fopen( $destination_path, 'wb' );
        if ( ! $destination ) {
            fclose( $source );
            return false;
        }

        $copied = stream_copy_to_stream( $source, $destination );

        fclose( $source );
        fclose( $destination );

        return false !== $copied;
    }

    private function get_temp_file_paths( $file_name ) {
        $file_name = sanitize_file_name( (string) $file_name );

        if ( '' === $file_name || ! preg_match( '/^import-[a-z0-9]+\.csv$/', $file_name ) ) {
            return new WP_Error( 'eim_temp_name_invalid', __( 'Invalid temporary file name.', EIM_TEXT_DOMAIN ) );
        }

        $temp_dir  = $this->get_temp_dir();
        $meta_name = str_replace( '.csv', '.meta.json', $file_name );

        return [
            'csv'  => trailingslashit( $temp_dir ) . $file_name,
            'meta' => trailingslashit( $temp_dir ) . $meta_name,
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

            return new WP_Error( 'eim_temp_meta_missing', __( 'Temporary import metadata not found. Please upload the CSV again.', EIM_TEXT_DOMAIN ) );
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
            return new WP_Error( 'eim_temp_meta_invalid', __( 'Temporary import metadata is invalid. Please upload the CSV again.', EIM_TEXT_DOMAIN ) );
        }

        $inspection = $this->inspect_csv_file( $csv_path );
        if ( is_wp_error( $inspection ) ) {
            return new WP_Error( 'eim_temp_meta_invalid', __( 'Temporary import metadata is invalid. Please upload the CSV again.', EIM_TEXT_DOMAIN ) );
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
            @unlink( $paths['csv'] );
        }

        if ( file_exists( $paths['meta'] ) ) {
            @unlink( $paths['meta'] );
        }
    }

    private function get_temp_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'eim-temp/';
    }

    private function get_temp_lock_key( $file_name ) {
        return 'eim_import_lock_' . md5( (string) $file_name );
    }

    private function acquire_temp_lock( $lock_key ) {
        if ( get_transient( $lock_key ) ) {
            return false;
        }

        return set_transient( $lock_key, time(), self::LOCK_TTL );
    }

    private function release_temp_lock( $lock_key ) {
        delete_transient( $lock_key );
    }

    private function validate_existing_media_file( $file_path ) {
        $allowed_mimes = apply_filters( 'eim_allowed_local_mimes', get_allowed_mime_types() );
        $filename      = wp_basename( $file_path );
        $filetype      = wp_check_filetype_and_ext( $file_path, $filename, $allowed_mimes );
        $mime          = ! empty( $filetype['type'] ) ? (string) $filetype['type'] : '';
        $ext           = ! empty( $filetype['ext'] ) ? (string) $filetype['ext'] : '';
        $major_type    = strtok( $mime, '/' );

        if ( '' === $mime || '' === $ext ) {
            return new WP_Error( 'eim_local_type_invalid', __( 'Local file type is not allowed.', EIM_TEXT_DOMAIN ) );
        }

        if ( ! in_array( $major_type, [ 'image', 'video', 'audio', 'application' ], true ) ) {
            return new WP_Error( 'eim_local_type_invalid', __( 'Local file type is not supported by this plugin.', EIM_TEXT_DOMAIN ) );
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
                @unlink( $file_path );
            }
        }
    }
}

