<?php

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

function __( $message, $domain = null ) {
    return $message;
}

function apply_filters( $hook, $value ) {
    return $value;
}

function get_option( $key, $default = false ) {
    if ( 'eim_settings' === $key ) {
        // Simulate a legacy installation where the previous UI default was
        // persisted as checked=true. Updating the plugin must not resurrect it.
        return [
            'import' => [
                'options' => [
                    2 => [
                        'id'      => 'eim_skip_thumbnails',
                        'checked' => true,
                    ],
                ],
            ],
        ];
    }

    return $default;
}

function eim_thumbnail_default_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-config.php';

$defaults = EIM_Config::get_defaults();
$options  = isset( $defaults['import']['options'] ) && is_array( $defaults['import']['options'] )
    ? $defaults['import']['options']
    : [];

$thumbnail_option = null;
foreach ( $options as $option ) {
    if ( isset( $option['id'] ) && 'eim_skip_thumbnails' === $option['id'] ) {
        $thumbnail_option = $option;
        break;
    }
}

eim_thumbnail_default_assert( is_array( $thumbnail_option ), 'Skip Thumbnail Generation option must exist.' );
eim_thumbnail_default_assert( empty( $thumbnail_option['checked'] ), 'Skip Thumbnail Generation must be unchecked in clean defaults.' );
eim_thumbnail_default_assert( isset( $thumbnail_option['feature'] ) && 'skip_thumbnails' === $thumbnail_option['feature'], 'Thumbnail option must remain wired to the skip_thumbnails feature.' );

$runtime_options = EIM_Config::get_import_option_definitions();
$runtime_thumbnail_option = null;
foreach ( $runtime_options as $option ) {
    if ( isset( $option['id'] ) && 'eim_skip_thumbnails' === $option['id'] ) {
        $runtime_thumbnail_option = $option;
        break;
    }
}

eim_thumbnail_default_assert( is_array( $runtime_thumbnail_option ), 'Runtime Skip Thumbnail Generation option must exist.' );
eim_thumbnail_default_assert( empty( $runtime_thumbnail_option['checked'] ), 'Legacy stored checked=true must not override the current thumbnail default.' );

echo "Thumbnail default tests passed.\n";
