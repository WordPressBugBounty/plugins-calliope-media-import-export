<?php
/**
 * Internal plugin service registry.
 *
 * @package ExportImportMedia
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'EIM_Service_Registry', false ) ) {
    /**
     * Store and retrieve bootstrapped plugin services.
     */
    class EIM_Service_Registry {

        /**
         * Registered services.
         *
         * @var array
         */
        protected static $services = [];

        /**
         * Persist the full service map.
         *
         * @param array $services Services keyed by slug.
         * @return void
         */
        public static function set_services( array $services ) {
            $registry = [];

            foreach ( $services as $key => $service ) {
                $key = sanitize_key( (string) $key );

                if ( '' === $key ) {
                    continue;
                }

                $registry[ $key ] = $service;
            }

            self::$services = $registry;
        }

        /**
         * Retrieve a single service by key.
         *
         * @param string $key Service key.
         * @return mixed|null
         */
        public static function get( $key ) {
            $key = sanitize_key( (string) $key );

            if ( '' === $key || ! isset( self::$services[ $key ] ) ) {
                return null;
            }

            return self::$services[ $key ];
        }

        /**
         * Whether a service is registered.
         *
         * @param string $key Service key.
         * @return bool
         */
        public static function has( $key ) {
            $key = sanitize_key( (string) $key );

            return '' !== $key && isset( self::$services[ $key ] );
        }

        /**
         * Retrieve the full service map.
         *
         * @return array
         */
        public static function all() {
            return self::$services;
        }
    }
}
