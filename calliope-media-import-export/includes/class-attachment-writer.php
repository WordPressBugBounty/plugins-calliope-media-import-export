<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Attachment_Writer {

    private $matcher;
    private $svg_validator;
    private $callbacks;
    private $filesystem;

    public function __construct( EIM_Attachment_Matcher $matcher, EIM_Svg_Import_Validator $svg_validator, $callbacks = [], ?EIM_Filesystem $filesystem = null ) {
        $this->matcher       = $matcher;
        $this->svg_validator = $svg_validator;
        $this->callbacks     = is_array( $callbacks ) ? $callbacks : [];
        $this->filesystem    = $filesystem ? $filesystem : new EIM_Filesystem();
    }

    private function invoke_callback( $name, $arguments, $default = null ) {
        if ( empty( $this->callbacks[ $name ] ) || ! is_callable( $this->callbacks[ $name ] ) ) {
            return $default;
        }

        return call_user_func_array( $this->callbacks[ $name ], $arguments );
    }

    private function build_item_result( $status, $file, $message, $context = [] ) {
        return $this->invoke_callback( 'build_item_result', func_get_args(), [] );
    }

    private function build_import_action_context( $request_context, $row = [], $extra = [] ) {
        return $this->invoke_callback( 'build_import_action_context', func_get_args(), [] );
    }

    private function get_result_request_context( $request_context ) {
        return $this->invoke_callback( 'get_result_request_context', func_get_args(), [] );
    }

    private function log_import_event( $event, $context = [] ) {
        return $this->invoke_callback( 'log_import_event', func_get_args() );
    }

    public function attach_existing_media_file( $file_path, $filename, $title, $alt, $caption, $description, $url, $rel_path, $row, $request_context = [] ) {
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

        $file_size = $this->filesystem->size( $file_path, 'attachment_size_read_failed' );
        $this->log_import_event(
            'attach_local_start',
            [
                'filename'  => $filename,
                'file_path' => $file_path,
                'bytes'     => is_wp_error( $file_size ) ? 0 : $file_size,
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
        $existing_id    = $this->matcher->find_existing_attachment_id(
            $url,
            $rel_path,
            $file_path,
            $final_filename,
            $fingerprint,
            $this->matcher->normalize_match_strategy(
                isset( $request_context['match_strategy'] ) ? $request_context['match_strategy'] : 'auto',
                $advanced_actions_allowed
            )
        );
        $duplicate_strategy = $this->matcher->normalize_duplicate_strategy(
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

    public function resolve_duplicate_result( $attachment_id, $strategy, $dry_run, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $incoming_file_path, $custom_meta, $row, $reason, $request_context = [] ) {
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
            $this->matcher->normalize_duplicate_strategy( $strategy, $advanced_actions_allowed ),
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
        $strategy = $this->matcher->normalize_duplicate_strategy( $strategy, $advanced_actions_allowed );

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

    public function update_existing_attachment_metadata( $attachment_id, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $custom_meta, $row, $selected_fields = [], $request_context = [] ) {
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

    public function replace_existing_attachment_file( $attachment_id, $source_file_path, $filename, $title, $alt, $caption, $description, $url, $rel_path, $fingerprint, $custom_meta, $row, $request_context = [] ) {
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
        $source_is_svg = $this->svg_validator->is_svg_import_file( $source_file_path, $target_name );
        $clean_svg     = null;
        if ( $source_is_svg ) {
            $svg_validation = $this->svg_validator->maybe_validate_svg_import_file( $source_file_path, $target_name );
            if ( is_wp_error( $svg_validation ) ) {
                return $svg_validation;
            }

            if ( is_string( $svg_validation ) ) {
                $clean_svg = $svg_validation;
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

        if ( $source_is_svg && is_string( $clean_svg ) ) {
            $svg_write = $this->svg_validator->write_sanitized_svg_file(
                $target_path,
                $clean_svg,
                'eim_replace_copy_failed',
                __( 'The replacement file could not be copied into uploads.', 'calliope-media-import-export' )
            );

            if ( is_wp_error( $svg_write ) ) {
                return $svg_write;
            }
        } else {
            $copied = $this->filesystem->copy( $source_file_path, $target_path, true, 'attachment_replace_copy_failed' );
            if ( is_wp_error( $copied ) ) {
                return new WP_Error( 'eim_replace_copy_failed', __( 'The replacement file could not be copied into uploads.', 'calliope-media-import-export' ), $copied->get_error_data() );
            }
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

    public function apply_custom_meta( $attachment_id, $custom_meta ) {
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

    public function decode_custom_meta_json( $value ) {
        if ( is_array( $value ) ) {
            return $value;
        }

        $decoded = json_decode( (string) $value, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    public function store_source_meta( $attachment_id, $url, $rel_path ) {
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

    public function store_fingerprint_meta( $attachment_id, $fingerprint ) {
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

    public function get_file_fingerprint( $file_path ) {
        $file_path = (string) $file_path;

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return '';
        }

        $size = $this->filesystem->size( $file_path, 'attachment_fingerprint_size_failed' );
        if ( is_wp_error( $size ) ) {
            return '';
        }

        $max_full_bytes = (int) apply_filters( 'eim_full_hash_max_bytes', 50 * 1024 * 1024 );
        $chunk_bytes    = (int) apply_filters( 'eim_fingerprint_chunk_bytes', 1024 * 1024 );

        if ( $size > 0 && $size <= $max_full_bytes ) {
            $md5 = $this->filesystem->md5_file( $file_path, 'attachment_fingerprint_hash_failed' );
            return ! is_wp_error( $md5 ) && $md5 ? 'md5:' . $md5 : '';
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

        $first     = $this->read_file_chunk( $handle, $chunk_bytes, $file_path );
        $first_md5 = false !== $first ? md5( $first ) : '';
        $last_md5  = '';

        if ( $size > $chunk_bytes ) {
            $seek = $this->filesystem->seek_stream( $handle, -$chunk_bytes, SEEK_END, $file_path, 'attachment_fingerprint_seek_failed' );
            if ( is_wp_error( $seek ) ) {
                $this->close_file_handle( $handle );
                return '';
            }
            $last     = $this->read_file_chunk( $handle, $chunk_bytes, $file_path );
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

    private function open_read_handle( $file_path ) {
        $handle = $this->filesystem->open_stream( $file_path, 'rb', 'attachment_fingerprint_stream_open_failed' );
        return is_wp_error( $handle ) ? false : $handle;
    }

    private function close_file_handle( $handle ) {
        $this->filesystem->close_stream( $handle );
    }

    private function read_file_chunk( $handle, $length, $file_path ) {
        $contents = $this->filesystem->read_stream( $handle, $length, $file_path, 'attachment_fingerprint_read_failed' );
        return is_wp_error( $contents ) ? false : $contents;
    }

    public function normalize_import_filename( $filename, $url = '', $rel_path = '' ) {
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

    public function sanitize_relative_path( $rel_path ) {
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

    public function resolve_uploads_file_from_source( $url = '', $rel_path = '' ) {
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

    public function build_uploads_candidate_path( $rel_path ) {
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

    public function looks_like_local_upload_url( $url ) {
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

    private function sideload_svg_file( $file_array, $subdir = '' ) {
        $tmp_name = isset( $file_array['tmp_name'] ) ? (string) $file_array['tmp_name'] : '';
        $filename = isset( $file_array['name'] ) ? sanitize_file_name( (string) $file_array['name'] ) : '';

        if ( '' === $filename ) {
            $filename = 'media-file.svg';
        } elseif ( ! preg_match( '/\.svg$/i', $filename ) ) {
            $filename .= '.svg';
        }

        $svg_validation = $this->svg_validator->maybe_validate_svg_import_file( $tmp_name, $filename );
        if ( is_wp_error( $svg_validation ) ) {
            return $svg_validation;
        }
        $clean_svg = is_string( $svg_validation ) ? $svg_validation : '';

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

        $svg_write = $this->svg_validator->write_sanitized_svg_file(
            $target_path,
            $clean_svg,
            'eim_svg_copy_failed',
            __( 'The SVG file could not be copied into uploads.', 'calliope-media-import-export' )
        );

        if ( is_wp_error( $svg_write ) ) {
            wp_delete_file( $target_path );
            return $svg_write;
        }

        wp_delete_file( $tmp_name );

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

    public function media_handle_sideload_with_subdir( $file_array, $subdir = '' ) {
        $subdir = trim( (string) $subdir );

        if ( isset( $file_array['name'] ) ) {
            $file_array['name'] = $this->normalize_import_filename( $file_array['name'] );
        }

        if ( isset( $file_array['tmp_name'] ) && $this->svg_validator->is_svg_import_file( $file_array['tmp_name'], isset( $file_array['name'] ) ? $file_array['name'] : '' ) ) {
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

    public function normalize_selected_update_fields( $fields ) {
        $allowed = [ 'title', 'alt', 'caption', 'description', 'custom_meta' ];
        $fields  = is_array( $fields ) ? $fields : explode( ',', (string) $fields );
        $fields  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $fields ) ) ) );

        return array_values( array_intersect( $fields, $allowed ) );
    }

    public function validate_existing_media_file( $file_path, $write_sanitized_svg = true ) {
        $filename      = wp_basename( $file_path );
        if ( $this->svg_validator->is_svg_import_file( $file_path, $filename ) ) {
            $svg_validation = $this->svg_validator->maybe_validate_svg_import_file( $file_path, $filename );
            if ( is_wp_error( $svg_validation ) ) {
                return $svg_validation;
            }

            if ( $write_sanitized_svg && is_string( $svg_validation ) ) {
                $svg_write = $this->svg_validator->write_sanitized_svg_file(
                    $file_path,
                    $svg_validation,
                    'eim_svg_copy_failed',
                    __( 'The SVG file could not be copied into uploads.', 'calliope-media-import-export' )
                );

                if ( is_wp_error( $svg_write ) ) {
                    return $svg_write;
                }
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

    public function derive_filename( $url, $rel_path = '' ) {
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

}
