<?php

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

$GLOBALS['eim_test_attachment_urls'] = [];
$GLOBALS['eim_test_post_meta']       = [];
$GLOBALS['eim_test_post_types']      = [];
$GLOBALS['eim_test_attached_files']  = [];
$GLOBALS['eim_test_filters']         = [];

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
    }
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
    function attachment_url_to_postid( $url ) {
        return isset( $GLOBALS['eim_test_attachment_urls'][ $url ] )
            ? (int) $GLOBALS['eim_test_attachment_urls'][ $url ]
            : 0;
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        $value = isset( $GLOBALS['eim_test_post_meta'][ $post_id ][ $key ] )
            ? $GLOBALS['eim_test_post_meta'][ $post_id ][ $key ]
            : '';

        return $single ? $value : [ $value ];
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        $GLOBALS['eim_test_post_meta'][ $post_id ][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_attached_file' ) ) {
    function get_attached_file( $post_id ) {
        return isset( $GLOBALS['eim_test_attached_files'][ $post_id ] )
            ? $GLOBALS['eim_test_attached_files'][ $post_id ]
            : false;
    }
}

if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $post_id ) {
        return isset( $GLOBALS['eim_test_post_types'][ $post_id ] )
            ? $GLOBALS['eim_test_post_types'][ $post_id ]
            : false;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook_name, $value ) {
        return array_key_exists( $hook_name, $GLOBALS['eim_test_filters'] )
            ? $GLOBALS['eim_test_filters'][ $hook_name ]
            : $value;
    }
}

require_once dirname( __DIR__ ) . '/includes/class-csv-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-attachment-matcher.php';
