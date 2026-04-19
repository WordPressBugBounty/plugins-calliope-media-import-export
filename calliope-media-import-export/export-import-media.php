<?php
/*
Plugin Name: Export/Import Media
Description: Exports and imports media with metadata using CSV.
Version: 1.6.4
Author: Maira Forest
Author URI: https://calliope.com.ar/
License: GPLv2 or later
Text Domain: calliope-media-import-export
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent fatal errors when two copies of the plugin are loaded at the same time.
if ( defined( 'EIM_BOOTSTRAPPED_FILE' ) ) {
    return;
}

define( 'EIM_BOOTSTRAPPED_FILE', __FILE__ );

if ( ! defined( 'EIM_FILE' ) ) {
    define( 'EIM_FILE', __FILE__ );
}

if ( ! defined( 'EIM_VERSION' ) ) {
    define( 'EIM_VERSION', '1.6.4' );
}

if ( ! defined( 'EIM_PUBLIC_SLUG' ) ) {
    define( 'EIM_PUBLIC_SLUG', 'calliope-media-import-export' );
}

if ( ! defined( 'EIM_TEXT_DOMAIN' ) ) {
    define( 'EIM_TEXT_DOMAIN', 'calliope-media-import-export' );
}

if ( ! defined( 'EIM_ADMIN_PAGE_SLUG' ) ) {
    define( 'EIM_ADMIN_PAGE_SLUG', 'export-import-media' );
}

if ( ! defined( 'EIM_PATH' ) ) {
    define( 'EIM_PATH', plugin_dir_path( EIM_FILE ) );
}

if ( ! defined( 'EIM_URL' ) ) {
    define( 'EIM_URL', plugin_dir_url( EIM_FILE ) );
}

if ( ! defined( 'EIM_BASENAME' ) ) {
    define( 'EIM_BASENAME', plugin_basename( EIM_FILE ) );
}

$eim_config_file = EIM_PATH . 'includes/class-config.php';

if ( file_exists( $eim_config_file ) ) {
    require_once $eim_config_file;
} elseif ( ! class_exists( 'EIM_Config', false ) ) {
    /**
     * Fallback config class used when an update is incomplete and the dedicated
     * config file was not uploaded yet.
     */
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
                    'default_batch_size'    => '25',
                    'preview_sample_limit'  => 5,
                    'batch_size_options'    => [
                        '10'  => __( '10 (Safe)', EIM_TEXT_DOMAIN ),
                        '25'  => '25',
                        '50'  => '50',
                        '100' => __( '100 (Fast)', EIM_TEXT_DOMAIN ),
                        '500' => __( '500 (Turbo)', EIM_TEXT_DOMAIN ),
                    ],
                    'options'               => [
                        [
                            'id'          => 'eim_local_import',
                            'label'       => __( 'Local Import Mode', EIM_TEXT_DOMAIN ),
                            'description' => __( 'Use the "Relative Path" column to locate files that already exist in this site\'s uploads folder. No remote download is attempted.', EIM_TEXT_DOMAIN ),
                            'checked'     => false,
                            'feature'     => 'local_import',
                        ],
                        [
                            'id'          => 'eim_honor_relative_path',
                            'label'       => __( 'Honor Relative Path (Keep folders)', EIM_TEXT_DOMAIN ),
                            'description' => __( 'Keep the folder structure from "Relative Path" when importing, and reuse files already present in uploads when the same path exists.', EIM_TEXT_DOMAIN ),
                            'checked'     => true,
                            'feature'     => 'relative_path',
                        ],
                        [
                            'id'          => 'eim_skip_thumbnails',
                            'label'       => __( 'Skip Thumbnail Generation', EIM_TEXT_DOMAIN ),
                            'description' => __( 'Speed up imports by skipping thumbnail generation. Turn this on if you plan to regenerate thumbnails later.', EIM_TEXT_DOMAIN ),
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
                        'image'       => __( 'Images', EIM_TEXT_DOMAIN ),
                        'all'         => __( 'All Media', EIM_TEXT_DOMAIN ),
                        'video'       => __( 'Videos', EIM_TEXT_DOMAIN ),
                        'audio'       => __( 'Audio', EIM_TEXT_DOMAIN ),
                        'application' => __( 'Documents (PDF, ZIP, etc.)', EIM_TEXT_DOMAIN ),
                    ],
                    'attachment_filter_options' => [
                        'all'        => __( 'All Media', EIM_TEXT_DOMAIN ),
                        'unattached' => __( 'Unattached (Not used in posts)', EIM_TEXT_DOMAIN ),
                        'post'       => __( 'Attached to Posts', EIM_TEXT_DOMAIN ),
                        'page'       => __( 'Attached to Pages', EIM_TEXT_DOMAIN ),
                        'product'    => [
                            'label'          => __( 'Attached to Products (WooCommerce)', EIM_TEXT_DOMAIN ),
                            'requires_class' => 'WooCommerce',
                        ],
                    ],
                ],
                'features'            => [
                    'csv_preview'              => true,
                    'batch_import'             => true,
                    'local_import'             => true,
                    'relative_path'            => true,
                    'skip_thumbnails'          => true,
                    'duplicate_detection'      => true,
                    'advanced_column_mapping'  => false,
                    'dry_run'                  => false,
                    'background_processing'    => false,
                    'scheduled_imports'        => false,
                    'external_sources'         => false,
                    'advanced_export_filters'  => false,
                    'advanced_duplicate_rules' => false,
                    'diagnostics'              => false,
                    'cli'                      => false,
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
                    'documentation' => 'https://calliope.com.ar/documentacion-plugin/',
                    'support'       => 'https://wordpress.org/support/plugin/' . $public_slug . '/',
                    'reviews'       => 'https://wordpress.org/support/plugin/' . $public_slug . '/reviews/#new-post',
                    'kofi'          => 'https://ko-fi.com/O4O21MF4QW',
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
            $flags = is_array( $flags ) ? $flags : [];

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
}

if ( ! function_exists( 'eim_get_setting' ) ) {
    /**
     * Get a plugin setting using dot notation.
     *
     * @param string $path    Dot notation path.
     * @param mixed  $default Default value when setting does not exist.
     * @return mixed
     */
    function eim_get_setting( $path, $default = null ) {
        return EIM_Config::get( $path, $default );
    }
}

if ( ! function_exists( 'eim_get_public_slug' ) ) {
    /**
     * Get the canonical public plugin slug.
     *
     * @return string
     */
    function eim_get_public_slug() {
        return (string) apply_filters( 'eim_public_slug', EIM_PUBLIC_SLUG );
    }
}

if ( ! function_exists( 'eim_get_required_capability' ) ) {
    /**
     * Capability required to access plugin features.
     *
     * Filterable so future extensions can align permissions without forking core.
     *
     * @return string
     */
    function eim_get_required_capability() {
        $default    = eim_get_setting( 'required_capability', 'manage_options' );
        $capability = apply_filters( 'eim_required_capability', $default );

        if ( ! is_string( $capability ) || '' === trim( $capability ) ) {
            return 'manage_options';
        }

        return trim( $capability );
    }
}

if ( ! function_exists( 'eim_current_user_can_manage' ) ) {
    /**
     * Check whether the current user can access the plugin.
     *
     * @return bool
     */
    function eim_current_user_can_manage() {
        return current_user_can( eim_get_required_capability() );
    }
}

if ( ! function_exists( 'eim_is_pro_active' ) ) {
    /**
     * Whether a Pro add-on is active.
     *
     * The add-on can define a constant or use the filter to report itself.
     *
     * @return bool
     */
    function eim_is_pro_active() {
        $is_active = (
            defined( 'EIM_PRO_VERSION' ) ||
            defined( 'EXPORT_IMPORT_MEDIA_PRO_VERSION' ) ||
            class_exists( 'EIM_Pro_Plugin', false ) ||
            class_exists( 'EIM_Pro_Bootstrap', false )
        );

        return (bool) apply_filters( 'eim_is_pro_active', $is_active );
    }
}

if ( ! function_exists( 'eim_is_feature_enabled' ) ) {
    /**
     * Check whether a feature flag is enabled.
     *
     * @param string $feature Feature slug.
     * @return bool
     */
    function eim_is_feature_enabled( $feature ) {
        $feature = sanitize_key( (string) $feature );
        if ( '' === $feature ) {
            return false;
        }

        $flags   = EIM_Config::get_feature_flags();
        $enabled = ! empty( $flags[ $feature ] );

        return (bool) apply_filters( 'eim_is_feature_enabled', $enabled, $feature, $flags );
    }
}

if ( ! class_exists( 'EIM_Importer', false ) ) {
    require_once EIM_PATH . 'includes/class-importer.php';
}

if ( ! class_exists( 'EIM_Admin', false ) ) {
    require_once EIM_PATH . 'admin/class-admin.php';
}

if ( ! class_exists( 'EIM_Exporter', false ) ) {
    require_once EIM_PATH . 'includes/class-exporter.php';
}

if ( ! function_exists( 'eim_init_plugin' ) ) {
    /**
     * Bootstrap plugin services.
     */
    function eim_init_plugin() {
        static $booted = false;

        if ( $booted ) {
            return;
        }

        $booted = true;

        load_plugin_textdomain( EIM_TEXT_DOMAIN, false, dirname( EIM_BASENAME ) . '/languages' );

        $services = [];

        if ( class_exists( 'EIM_Admin' ) ) {
            $services['admin'] = new EIM_Admin();
        }

        if ( class_exists( 'EIM_Importer' ) ) {
            $services['importer'] = new EIM_Importer();
        }

        if ( class_exists( 'EIM_Exporter' ) ) {
            $services['exporter'] = new EIM_Exporter();
        }

        do_action( 'eim_plugin_ready', $services );
    }
}

add_action( 'plugins_loaded', 'eim_init_plugin' );

if ( class_exists( 'EIM_Importer' ) ) {
    register_activation_hook( EIM_FILE, [ 'EIM_Importer', 'activate_plugin' ] );
    register_deactivation_hook( EIM_FILE, [ 'EIM_Importer', 'deactivate_plugin' ] );
}
