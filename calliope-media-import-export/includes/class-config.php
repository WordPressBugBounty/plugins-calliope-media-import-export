<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Config {

    const OPTION_KEY = 'eim_settings';

    public static function get_option_key() {
        return (string) apply_filters( 'eim_settings_option_key', self::OPTION_KEY );
    }

    public static function get_defaults() {
        $public_slug = defined( 'EIM_PUBLIC_SLUG' ) ? EIM_PUBLIC_SLUG : 'calliope-media-import-export';

        $defaults = [
            'required_capability' => 'manage_options',
            'import'              => [
                'default_batch_size' => '25',
                'preview_sample_limit' => 5,
                'batch_size_options' => [
                    '10'  => __( '10 (Safe)', 'calliope-media-import-export' ),
                    '25'  => '25',
                    '50'  => '50',
                    '100' => __( '100 (Fast)', 'calliope-media-import-export' ),
                    '500' => __( '500 (Turbo)', 'calliope-media-import-export' ),
                ],
                'options'            => [
                    [
                        'id'          => 'eim_local_import',
                        'label'       => __( 'Local Import Mode', 'calliope-media-import-export' ),
                        'description' => __( 'Use the "Relative Path" column to locate files that already exist in this site\'s uploads folder. No remote download is attempted.', 'calliope-media-import-export' ),
                        'checked'     => false,
                        'feature'     => 'local_import',
                    ],
                    [
                        'id'          => 'eim_honor_relative_path',
                        'label'       => __( 'Honor Relative Path (Keep folders)', 'calliope-media-import-export' ),
                        'description' => __( 'Keep the folder structure from "Relative Path" when importing, and reuse files already present in uploads when the same path exists.', 'calliope-media-import-export' ),
                        'checked'     => true,
                        'feature'     => 'relative_path',
                    ],
                    [
                        'id'          => 'eim_skip_thumbnails',
                        'label'       => __( 'Skip Thumbnail Generation', 'calliope-media-import-export' ),
                        'description' => __( 'Speed up imports by skipping thumbnail generation. Turn this on if you plan to regenerate thumbnails later.', 'calliope-media-import-export' ),
                        'checked'     => false,
                        'feature'     => 'skip_thumbnails',
                    ],
                ],
            ],
            'export'              => [
                'defaults'                  => [
                    'media_type'        => 'image',
                    'attachment_filter' => 'all',
                ],
                'media_type_options'        => [
                    'image'       => __( 'Images', 'calliope-media-import-export' ),
                    'all'         => __( 'All Media', 'calliope-media-import-export' ),
                    'video'       => __( 'Videos', 'calliope-media-import-export' ),
                    'audio'       => __( 'Audio', 'calliope-media-import-export' ),
                    'application' => __( 'Documents (PDF, ZIP, etc.)', 'calliope-media-import-export' ),
                ],
                'attachment_filter_options' => [
                    'all'        => __( 'All Media', 'calliope-media-import-export' ),
                    'unattached' => __( 'Unattached (Not used in posts)', 'calliope-media-import-export' ),
                    'post'       => __( 'Attached to Posts', 'calliope-media-import-export' ),
                    'page'       => __( 'Attached to Pages', 'calliope-media-import-export' ),
                    'product'    => [
                        'label'          => __( 'Attached to Products (WooCommerce)', 'calliope-media-import-export' ),
                        'requires_class' => 'WooCommerce',
                    ],
                ],
            ],
            'features'            => [
                'csv_preview'               => true,
                'batch_import'              => true,
                'local_import'              => true,
                'relative_path'             => true,
                'skip_thumbnails'           => true,
                'duplicate_detection'       => true,
                'advanced_column_mapping'   => false,
                'dry_run'                   => false,
                'background_processing'     => false,
                'scheduled_imports'         => false,
                'external_sources'          => false,
                'advanced_export_filters'   => false,
                'advanced_duplicate_rules'  => false,
                'diagnostics'               => false,
                'cli'                       => false,
            ],
            'pro_features'        => [
                'advanced_column_mapping',
                'dry_run',
                'background_processing',
                'scheduled_imports',
                'external_sources',
                'advanced_export_filters',
                'advanced_duplicate_rules',
                'diagnostics',
                'cli',
            ],
            'urls'                => [
                'documentation' => 'https://pluginswordpress.calliope.com.ar/export-import-media/',
                'support'       => 'https://wordpress.org/support/plugin/' . $public_slug . '/',
                'reviews'       => 'https://wordpress.org/support/plugin/' . $public_slug . '/reviews/#new-post',
                'pro'           => 'https://pluginswordpress.calliope.com.ar/export-import-media/',
            ],
        ];

        return apply_filters( 'eim_config_defaults', $defaults );
    }

    public static function get_settings() {
        $stored = get_option( self::get_option_key(), [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return self::merge_recursive( self::get_defaults(), $stored );
    }

    public static function get( $path, $default = null ) {
        $settings = self::get_settings();
        if ( '' === (string) $path ) {
            return $settings;
        }

        $segments = explode( '.', (string) $path );
        $value    = $settings;

        foreach ( $segments as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return $default;
            }

            $value = $value[ $segment ];
        }

        return $value;
    }

    public static function get_feature_flags() {
        $flags = self::get( 'features', [] );
        if ( ! is_array( $flags ) ) {
            $flags = [];
        }

        return apply_filters( 'eim_feature_flags', $flags, self::get_settings() );
    }

    public static function get_pro_features() {
        $features = self::get( 'pro_features', [] );
        return is_array( $features ) ? $features : [];
    }

    public static function is_pro_feature( $feature ) {
        return in_array( (string) $feature, self::get_pro_features(), true );
    }

    public static function get_import_batch_size_options() {
        $options = self::get( 'import.batch_size_options', [] );
        $options = is_array( $options ) ? $options : [];
        return apply_filters( 'eim_import_batch_size_options', $options );
    }

    public static function get_import_option_definitions() {
        $options = self::get( 'import.options', [] );
        $options = is_array( $options ) ? $options : [];
        return apply_filters( 'eim_admin_import_options', $options );
    }

    public static function get_export_defaults() {
        $defaults = self::get( 'export.defaults', [] );
        $defaults = is_array( $defaults ) ? $defaults : [];
        return apply_filters( 'eim_export_default_filters', $defaults );
    }

    public static function get_export_media_type_options() {
        $options = self::get( 'export.media_type_options', [] );
        $options = is_array( $options ) ? $options : [];
        return apply_filters( 'eim_export_media_type_options', $options );
    }

    public static function get_export_attachment_filter_options() {
        $options = self::get( 'export.attachment_filter_options', [] );
        $options = is_array( $options ) ? $options : [];
        return apply_filters( 'eim_export_attachment_filter_options', $options );
    }

    private static function merge_recursive( $defaults, $values ) {
        foreach ( $values as $key => $value ) {
            if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
                $defaults[ $key ] = self::merge_recursive( $defaults[ $key ], $value );
            } else {
                $defaults[ $key ] = $value;
            }
        }

        return $defaults;
    }
}

