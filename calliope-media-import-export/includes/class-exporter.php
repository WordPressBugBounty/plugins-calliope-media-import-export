<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Exporter {

    private $filter_parent_type = '';

    public function __construct() {
        add_action( 'admin_post_eim_export_csv', [ $this, 'handle_export' ] );
    }

    public static function get_csv_column_definitions() {
        $definitions = [
            'id'          => [
                'label'    => __( 'ID', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_id_column' ],
            ],
            'url'         => [
                'label'    => __( 'Absolute URL', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_url_column' ],
            ],
            'rel_path'    => [
                'label'    => __( 'Relative Path', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_relative_path_column' ],
            ],
            'file'        => [
                'label'    => __( 'File', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_file_column' ],
            ],
            'alt'         => [
                'label'    => __( 'Alt Text', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_alt_column' ],
            ],
            'caption'     => [
                'label'    => __( 'Caption', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_caption_column' ],
            ],
            'description' => [
                'label'    => __( 'Description', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_description_column' ],
            ],
            'title'       => [
                'label'    => __( 'Title', 'calliope-media-import-export' ),
                'resolver' => [ __CLASS__, 'resolve_title_column' ],
            ],
        ];

        return apply_filters( 'eim_export_column_definitions', $definitions );
    }

    public function get_column_definitions( $context = [] ) {
        $context     = is_array( $context ) ? $context : [];
        $definitions = self::get_csv_column_definitions();

        return apply_filters( 'eim_export_column_definitions_for_context', $definitions, $context );
    }

    public static function get_csv_headers( $column_keys = null ) {
        $exporter = function_exists( 'eim_get_service' ) ? eim_get_service( 'exporter' ) : null;

        if ( $exporter instanceof self ) {
            return $exporter->get_column_headers( [], $column_keys );
        }

        $headers = [];
        foreach ( self::get_csv_column_definitions() as $definition ) {
            if ( is_array( $definition ) && isset( $definition['label'] ) ) {
                $headers[] = (string) $definition['label'];
            }
        }

        return apply_filters( 'eim_export_headers', $headers, null, null, [] );
    }

    public function get_column_headers( $context = [], $column_keys = null ) {
        $column_keys  = $this->resolve_export_column_keys( $column_keys, $context );
        $definitions  = $this->get_column_definitions( $context );
        $headers      = [];

        foreach ( $column_keys as $key ) {
            if ( isset( $definitions[ $key ] ) && is_array( $definitions[ $key ] ) && isset( $definitions[ $key ]['label'] ) ) {
                $headers[] = (string) $definitions[ $key ]['label'];
            }
        }

        return apply_filters( 'eim_export_headers', $headers, $column_keys, $context, $definitions );
    }

    public function handle_export() {
        check_admin_referer( 'eim_export_action', 'eim_export_nonce' );

        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'calliope-media-import-export' ) );
        }

        $context     = $this->normalize_export_context( $_POST );
        $column_keys = isset( $context['column_keys'] ) ? $context['column_keys'] : null;
        $filename    = $this->get_export_filename( $context );

        ignore_user_abort( true );
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long exports may need additional execution time.
        @set_time_limit( 0 );
        nocache_headers();

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a CSV directly to the browser output.
        $output = fopen( 'php://output', 'w' );
        $this->write_csv_to_stream( $output, $context, $column_keys );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the browser output stream after writing the CSV.
        fclose( $output );
        exit;
    }

    public function normalize_export_context( $request = [] ) {
        $defaults = EIM_Config::get_export_defaults();
        $request  = is_array( $request ) ? $request : [];

        $context = [
            'start_date'        => $this->get_request_string( $request, 'eim_start_date' ),
            'end_date'          => $this->get_request_string( $request, 'eim_end_date' ),
            'media_type'        => $this->get_request_string( $request, 'eim_media_type' ),
            'attachment_filter' => $this->get_request_string( $request, 'eim_attachment_filter' ),
            'template'          => $this->get_request_string( $request, 'eim_template' ),
            'format'            => $this->get_request_string( $request, 'eim_export_format', 'csv' ),
            'orderby'           => $this->get_request_string( $request, 'eim_orderby', 'date' ),
            'order'             => strtoupper( $this->get_request_string( $request, 'eim_order', 'DESC' ) ),
            'delta_since'       => $this->get_request_string( $request, 'eim_delta_since' ),
            'column_keys'       => $this->normalize_column_keys_from_request( $request ),
            'author_id'         => $this->get_request_absint( $request, 'eim_author_id' ),
            'parent_post_type'  => $this->get_request_string( $request, 'eim_parent_post_type' ),
            'mime_subtype'      => $this->get_request_string( $request, 'eim_mime_subtype' ),
            'has_alt_text'      => $this->normalize_nullable_flag( $this->get_request_string( $request, 'eim_has_alt_text' ) ),
            'has_title'         => $this->normalize_nullable_flag( $this->get_request_string( $request, 'eim_has_title' ) ),
            'has_caption'       => $this->normalize_nullable_flag( $this->get_request_string( $request, 'eim_has_caption' ) ),
            'has_description'   => $this->normalize_nullable_flag( $this->get_request_string( $request, 'eim_has_description' ) ),
            'min_file_size'     => $this->get_request_absint( $request, 'eim_min_file_size' ),
            'max_file_size'     => $this->get_request_absint( $request, 'eim_max_file_size' ),
            'custom_meta_key'   => $this->get_request_string( $request, 'eim_custom_meta_key' ),
            'custom_meta_value' => $this->get_request_string( $request, 'eim_custom_meta_value' ),
        ];

        if ( '' === $context['media_type'] ) {
            $context['media_type'] = isset( $defaults['media_type'] ) ? (string) $defaults['media_type'] : 'image';
        }

        if ( '' === $context['attachment_filter'] ) {
            $context['attachment_filter'] = isset( $defaults['attachment_filter'] ) ? (string) $defaults['attachment_filter'] : 'all';
        }

        if ( ! in_array( $context['order'], [ 'ASC', 'DESC' ], true ) ) {
            $context['order'] = 'DESC';
        }

        $allowed_orderby = [ 'date', 'title', 'ID', 'author', 'modified' ];
        if ( ! in_array( $context['orderby'], $allowed_orderby, true ) ) {
            $context['orderby'] = 'date';
        }

        return apply_filters( 'eim_export_request_context', $context, $request );
    }

    public function query_attachment_ids( $context = [] ) {
        $context = $this->normalize_export_context( $context );
        $args    = $this->build_query_args( $context );

        $this->attach_parent_filters( $context );
        $query = new WP_Query( $args );
        $this->detach_parent_filters();

        return ! empty( $query->posts ) ? array_map( 'absint', $query->posts ) : [];
    }

    public function get_attachments_for_export( $context = [] ) {
        $attachments = [];

        foreach ( $this->query_attachment_ids( $context ) as $attachment_id ) {
            $attachment = get_post( $attachment_id );
            if ( $attachment instanceof WP_Post ) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    public function generate_export_dataset( $context = [], $column_keys = null ) {
        $context     = $this->normalize_export_context( $context );
        $column_keys = $this->resolve_export_column_keys( $column_keys, $context );
        $headers     = $this->get_column_headers( $context, $column_keys );
        $rows        = [];

        foreach ( $this->get_attachments_for_export( $context ) as $attachment ) {
            $rows[] = $this->build_export_row( $attachment, $column_keys, $context );
        }

        return [
            'headers'     => $headers,
            'rows'        => $rows,
            'column_keys' => $column_keys,
            'context'     => $context,
        ];
    }

    public function write_csv_to_stream( $stream, $context = [], $column_keys = null ) {
        if ( ! is_resource( $stream ) ) {
            return false;
        }

        $dataset = $this->generate_export_dataset( $context, $column_keys );
        fputcsv( $stream, $dataset['headers'] );

        foreach ( $dataset['rows'] as $row ) {
            fputcsv( $stream, $row );
        }

        return true;
    }

    public function export_to_file( $file_path, $context = [], $column_keys = null ) {
        $file_path = wp_normalize_path( (string) $file_path );
        if ( '' === $file_path ) {
            return new WP_Error( 'eim_export_file_path_missing', __( 'Export file path is required.', 'calliope-media-import-export' ) );
        }

        $directory = wp_normalize_path( dirname( $file_path ) );
        if ( ! is_dir( $directory ) ) {
            wp_mkdir_p( $directory );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing the export CSV to a generated file path.
        $handle = @fopen( $file_path, 'wb' );
        if ( ! $handle ) {
            return new WP_Error( 'eim_export_file_unwritable', __( 'Could not create the export file.', 'calliope-media-import-export' ) );
        }

        $written = $this->write_csv_to_stream( $handle, $context, $column_keys );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the generated export file after writing the CSV.
        fclose( $handle );

        if ( ! $written ) {
            return new WP_Error( 'eim_export_file_write_failed', __( 'Could not write the export file.', 'calliope-media-import-export' ) );
        }

        return $file_path;
    }

    public function resolve_export_column_keys( $column_keys = null, $context = [] ) {
        $definitions = $this->get_column_definitions( $context );
        $available   = array_keys( $definitions );

        if ( null === $column_keys ) {
            $resolved = $available;
        } else {
            $resolved = [];
            foreach ( (array) $column_keys as $column_key ) {
                $column_key = sanitize_key( (string) $column_key );
                if ( '' !== $column_key && isset( $definitions[ $column_key ] ) ) {
                    $resolved[] = $column_key;
                }
            }

            $resolved = array_values( array_unique( $resolved ) );
            if ( empty( $resolved ) ) {
                $resolved = $available;
            }
        }

        return apply_filters( 'eim_export_resolved_column_keys', $resolved, $column_keys, $context, $definitions );
    }

    public function build_query_args( $context ) {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => isset( $context['orderby'] ) ? $context['orderby'] : 'date',
            'order'          => isset( $context['order'] ) ? $context['order'] : 'DESC',
        ];

        $mime_type = $this->get_post_mime_type_from_context( $context );
        if ( '' !== $mime_type ) {
            $args['post_mime_type'] = $mime_type;
        }

        $date_query = $this->build_date_query( $context );
        if ( ! empty( $date_query ) ) {
            $args['date_query'] = [ $date_query ];
        }

        if ( 'unattached' === $context['attachment_filter'] ) {
            $args['post_parent'] = 0;
        }

        if ( ! empty( $context['author_id'] ) ) {
            $args['author'] = absint( $context['author_id'] );
        }

        return apply_filters( 'eim_export_query_args', $args, $context );
    }

    public function get_post_mime_type_from_context( $context ) {
        switch ( isset( $context['media_type'] ) ? $context['media_type'] : 'image' ) {
            case 'all':
                return '';
            case 'video':
                return 'video';
            case 'audio':
                return 'audio';
            case 'application':
                return 'application';
            case 'image':
            default:
                return 'image';
        }
    }

    public function build_date_query( $context ) {
        $date_query = [];

        if ( ! empty( $context['start_date'] ) ) {
            $date_query['after'] = $context['start_date'];
        }

        if ( ! empty( $context['end_date'] ) ) {
            $date_query['before'] = $context['end_date'];
        }

        if ( ! empty( $context['delta_since'] ) ) {
            $date_query['after']     = $context['delta_since'];
            $date_query['inclusive'] = false;
            $date_query['column']    = 'post_modified_gmt';
        }

        if ( ! empty( $date_query ) && ! isset( $date_query['inclusive'] ) ) {
            $date_query['inclusive'] = true;
        }

        return $date_query;
    }

    public function attach_parent_filters( $context ) {
        if ( empty( $context['attachment_filter'] ) ) {
            return;
        }

        if ( in_array( $context['attachment_filter'], [ 'post', 'page', 'product' ], true ) ) {
            $this->filter_parent_type = $context['attachment_filter'];
            add_filter( 'posts_join', [ $this, 'join_parent_post_type' ] );
            add_filter( 'posts_where', [ $this, 'where_parent_post_type' ] );
        }
    }

    public function detach_parent_filters() {
        remove_filter( 'posts_join', [ $this, 'join_parent_post_type' ] );
        remove_filter( 'posts_where', [ $this, 'where_parent_post_type' ] );
        $this->filter_parent_type = '';
    }

    public function get_export_filename( $context ) {
        $filename = 'media-export-' . gmdate( 'Y-m-d' ) . '.csv';
        return sanitize_file_name( (string) apply_filters( 'eim_export_filename', $filename, $context ) );
    }

    public function build_export_assoc_row( $attachment, $column_keys = null, $context = [] ) {
        $definitions = $this->get_column_definitions( $context );
        $column_keys = $this->resolve_export_column_keys( $column_keys, $context );
        $assoc_row   = [];

        foreach ( $column_keys as $key ) {
            if ( ! isset( $definitions[ $key ] ) || ! is_array( $definitions[ $key ] ) ) {
                continue;
            }

            $definition = $definitions[ $key ];
            $value      = '';

            if ( isset( $definition['resolver'] ) && is_callable( $definition['resolver'] ) ) {
                $value = call_user_func( $definition['resolver'], $attachment, $context, $definition );
            } elseif ( array_key_exists( 'value', $definition ) ) {
                $value = $definition['value'];
            }

            $assoc_row[ $key ] = $value;
        }

        return apply_filters( 'eim_export_row_assoc', $assoc_row, $attachment, $context, $column_keys, $definitions );
    }

    public function build_export_row( $attachment, $column_keys = null, $context = [] ) {
        $assoc_row = $this->build_export_assoc_row( $attachment, $column_keys, $context );
        $row       = array_values( $assoc_row );

        return apply_filters( 'eim_export_row_data', $row, $attachment, $assoc_row, $context, $column_keys );
    }

    public static function resolve_id_column( $attachment ) {
        return (int) $attachment->ID;
    }

    public static function resolve_url_column( $attachment ) {
        return (string) wp_get_attachment_url( $attachment->ID );
    }

    public static function resolve_relative_path_column( $attachment ) {
        $path = (string) get_post_meta( $attachment->ID, '_wp_attached_file', true );
        return '' !== $path ? '/' . ltrim( $path, '/' ) : '';
    }

    public static function resolve_file_column( $attachment ) {
        $path = (string) get_post_meta( $attachment->ID, '_wp_attached_file', true );
        return '' !== $path ? basename( $path ) : '';
    }

    public static function resolve_alt_column( $attachment ) {
        return (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
    }

    public static function resolve_caption_column( $attachment ) {
        return (string) $attachment->post_excerpt;
    }

    public static function resolve_description_column( $attachment ) {
        return (string) $attachment->post_content;
    }

    public static function resolve_title_column( $attachment ) {
        return (string) $attachment->post_title;
    }

    public function join_parent_post_type( $join ) {
        global $wpdb;
        $join .= " LEFT JOIN {$wpdb->posts} as parent_post ON ({$wpdb->posts}.post_parent = parent_post.ID) ";
        return $join;
    }

    public function where_parent_post_type( $where ) {
        if ( ! empty( $this->filter_parent_type ) ) {
            global $wpdb;
            $where .= $wpdb->prepare( ' AND parent_post.post_type = %s ', $this->filter_parent_type );
        }

        return $where;
    }

    private function get_request_string( $request, $key, $default = '' ) {
        if ( ! isset( $request[ $key ] ) ) {
            return $default;
        }

        return sanitize_text_field( wp_unslash( $request[ $key ] ) );
    }

    private function get_request_absint( $request, $key ) {
        return isset( $request[ $key ] ) ? absint( wp_unslash( $request[ $key ] ) ) : 0;
    }

    private function normalize_column_keys_from_request( $request ) {
        if ( isset( $request['eim_columns'] ) ) {
            return array_values(
                array_filter(
                    array_map(
                        'sanitize_key',
                        (array) wp_unslash( $request['eim_columns'] )
                    )
                )
            );
        }

        if ( isset( $request['eim_column_keys'] ) ) {
            $raw = wp_unslash( $request['eim_column_keys'] );
            if ( is_array( $raw ) ) {
                return array_values( array_filter( array_map( 'sanitize_key', $raw ) ) );
            }

            if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                return array_values(
                    array_filter(
                        array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) )
                    )
                );
            }
        }

        return null;
    }

    private function normalize_nullable_flag( $value ) {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

        if ( '' === $value ) {
            return null;
        }

        if ( in_array( $value, [ '1', 'true', 'yes', 'with', 'has' ], true ) ) {
            return true;
        }

        if ( in_array( $value, [ '0', 'false', 'no', 'without', 'missing' ], true ) ) {
            return false;
        }

        return null;
    }
}
