<?php
/*
Plugin Name: Export/Import Media
Description: CSV export/import for your media library with preview, batch processing, duplicate prevention, and core metadata columns.
Version: 1.7.9
Requires at least: 5.6
Requires PHP: 7.4
Author: CalliopeWP
Author URI: https://pluginswordpress.calliope.com.ar/
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
    define( 'EIM_VERSION', '1.7.9' );
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

require_once EIM_PATH . 'includes/class-config.php';
require_once EIM_PATH . 'includes/class-eim-service-registry.php';

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

if ( ! function_exists( 'eim_get_service' ) ) {
    /**
     * Fetch a bootstrapped plugin service.
     *
     * @param string|null $service Service key. Pass null to retrieve the full map.
     * @return mixed|null
     */
    function eim_get_service( $service = null ) {
        $services = EIM_Service_Registry::all();

        if ( null === $service || '' === $service ) {
            return $services;
        }

        return EIM_Service_Registry::get( $service );
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

        EIM_Service_Registry::set_services( $services );

        do_action( 'eim_plugin_ready', $services );
    }
}

add_action( 'plugins_loaded', 'eim_init_plugin' );

if ( class_exists( 'EIM_Importer' ) ) {
    register_activation_hook( EIM_FILE, [ 'EIM_Importer', 'activate_plugin' ] );
    register_deactivation_hook( EIM_FILE, [ 'EIM_Importer', 'deactivate_plugin' ] );
}
