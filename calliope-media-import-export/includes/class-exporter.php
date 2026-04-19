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
                'label'    => 'ID',
                'resolver' => [ __CLASS__, 'resolve_id_column' ],
            ],
            'url'         => [
                'label'    => 'Absolute URL',
                'resolver' => [ __CLASS__, 'resolve_url_column' ],
            ],
            'rel_path'    => [
                'label'    => 'Relative Path',
                'resolver' => [ __CLASS__, 'resolve_relative_path_column' ],
            ],
            'file'        => [
                'label'    => 'File',
                'resolver' => [ __CLASS__, 'resolve_file_column' ],
            ],
            'alt'         => [
                'label'    => 'Alt Text',
                'resolver' => [ __CLASS__, 'resolve_alt_column' ],
            ],
            'caption'     => [
                'label'    => 'Caption',
                'resolver' => [ __CLASS__, 'resolve_caption_column' ],
            ],
            'description' => [
                'label'    => 'Description',
                'resolver' => [ __CLASS__, 'resolve_description_column' ],
            ],
            'title'       => [
                'label'    => 'Title',
                'resolver' => [ __CLASS__, 'resolve_title_column' ],
            ],
        ];

        return apply_filters( 'eim_export_column_definitions', $definitions );
    }

    public static function get_csv_headers() {
        $headers = [];

        foreach ( self::get_csv_column_definitions() as $definition ) {
            if ( is_array( $definition ) && isset( $definition['label'] ) ) {
                $headers[] = (string) $definition['label'];
            }
        }

        return apply_filters( 'eim_export_headers', $headers );
    }

    public function handle_export() {
        check_admin_referer( 'eim_export_action', 'eim_export_nonce' );

        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', EIM_TEXT_DOMAIN ) );
        }

        $context = $this->get_export_request_context();
        $args    = $this->build_query_args( $context );

        $this->attach_parent_filters( $context );
        $query = new WP_Query( $args );
        $this->detach_parent_filters();

        $filename = $this->get_export_filename( $context );

        ignore_user_abort( true );
        @set_time_limit( 0 );
        nocache_headers();

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, self::get_csv_headers() );

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $attachment_id ) {
                $attachment = get_post( $attachment_id );
                if ( ! $attachment ) {
                    continue;
                }

                fputcsv( $output, $this->build_export_row( $attachment ) );
            }
        }

        fclose( $output );
        exit;
    }

    private function get_export_request_context() {
        $defaults = EIM_Config::get_export_defaults();
        $context  = [
            'start_date'        => isset( $_POST['eim_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['eim_start_date'] ) ) : '',
            'end_date'          => isset( $_POST['eim_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['eim_end_date'] ) ) : '',
            'media_type'        => isset( $_POST['eim_media_type'] ) ? sanitize_text_field( wp_unslash( $_POST['eim_media_type'] ) ) : '',
            'attachment_filter' => isset( $_POST['eim_attachment_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['eim_attachment_filter'] ) ) : '',
        ];

        if ( '' === $context['media_type'] ) {
            $context['media_type'] = isset( $defaults['media_type'] ) ? (string) $defaults['media_type'] : 'image';
        }

        if ( '' === $context['attachment_filter'] ) {
            $context['attachment_filter'] = isset( $defaults['attachment_filter'] ) ? (string) $defaults['attachment_filter'] : 'all';
        }

        return apply_filters( 'eim_export_request_context', $context );
    }

    private function build_query_args( $context ) {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
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

        return apply_filters( 'eim_export_query_args', $args, $context );
    }

    private function get_post_mime_type_from_context( $context ) {
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

    private function build_date_query( $context ) {
        $date_query = [];

        if ( ! empty( $context['start_date'] ) ) {
            $date_query['after'] = $context['start_date'];
        }

        if ( ! empty( $context['end_date'] ) ) {
            $date_query['before'] = $context['end_date'];
        }

        if ( ! empty( $date_query ) ) {
            $date_query['inclusive'] = true;
        }

        return $date_query;
    }

    private function attach_parent_filters( $context ) {
        if ( empty( $context['attachment_filter'] ) ) {
            return;
        }

        if ( in_array( $context['attachment_filter'], [ 'post', 'page', 'product' ], true ) ) {
            $this->filter_parent_type = $context['attachment_filter'];
            add_filter( 'posts_join', [ $this, 'join_parent_post_type' ] );
            add_filter( 'posts_where', [ $this, 'where_parent_post_type' ] );
        }
    }

    private function detach_parent_filters() {
        remove_filter( 'posts_join', [ $this, 'join_parent_post_type' ] );
        remove_filter( 'posts_where', [ $this, 'where_parent_post_type' ] );
        $this->filter_parent_type = '';
    }

    private function get_export_filename( $context ) {
        $filename = 'media-export-' . gmdate( 'Y-m-d' ) . '.csv';
        return sanitize_file_name( (string) apply_filters( 'eim_export_filename', $filename, $context ) );
    }

    private function build_export_row( $attachment ) {
        $assoc_row = [];

        foreach ( self::get_csv_column_definitions() as $key => $definition ) {
            $value = '';

            if ( is_array( $definition ) && isset( $definition['resolver'] ) && is_callable( $definition['resolver'] ) ) {
                $value = call_user_func( $definition['resolver'], $attachment );
            } elseif ( is_array( $definition ) && array_key_exists( 'value', $definition ) ) {
                $value = $definition['value'];
            }

            $assoc_row[ $key ] = $value;
        }

        $assoc_row = apply_filters( 'eim_export_row_assoc', $assoc_row, $attachment );
        $row       = array_values( $assoc_row );

        return apply_filters( 'eim_export_row_data', $row, $attachment, $assoc_row );
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
}

