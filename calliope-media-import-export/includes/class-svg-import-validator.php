<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Svg_Import_Validator {

    private $filesystem;

    public function __construct( ?EIM_Filesystem $filesystem = null ) {
        $this->filesystem = $filesystem ? $filesystem : new EIM_Filesystem();
    }

    public function is_svg_import_file( $file_path, $filename = '' ) {
        $filename  = strtolower( trim( (string) $filename ) );
        $file_path = (string) $file_path;

        // When an explicit filename includes an extension, trust that declared
        // type for routing. Content sniffing must never turn a .png/.jpg/etc.
        // into an SVG merely because downloaded HTML or metadata contains
        // the string "<svg".
        if ( '' !== $filename ) {
            $filename_ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
            if ( '' !== $filename_ext ) {
                return 'svg' === $filename_ext;
            }
        }

        if ( '' !== $file_path ) {
            $path_ext = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );
            if ( 'svg' === $path_ext ) {
                return true;
            }

            // WordPress temporary downloads may end in .tmp/.temp; those names
            // do not describe the original media type, so allow the final
            // content-sniff fallback below when no filename extension exists.
            if ( '' !== $path_ext && ! in_array( $path_ext, [ 'tmp', 'temp' ], true ) ) {
                return false;
            }
        }

        if ( '' === $file_path || ! is_readable( $file_path ) ) {
            return false;
        }

        // Content sniffing is a last resort only when neither source carries an
        // extension (for example a temporary upload path with no original name).
        $contents = $this->filesystem->get_contents( $file_path, 'svg_detection_read_failed' );
        return ! is_wp_error( $contents ) && is_string( $contents ) && false !== stripos( substr( ltrim( $contents ), 0, 512 ), '<svg' );
    }

    public function maybe_validate_svg_import_file( $file_path, $filename = '' ) {
        if ( ! $this->is_svg_import_file( $file_path, $filename ) ) {
            return true;
        }

        if ( ! apply_filters( 'eim_allow_svg_imports', true, $file_path, $filename ) ) {
            return new WP_Error( 'eim_svg_import_disabled', __( 'SVG imports are disabled.', 'calliope-media-import-export' ) );
        }

        return $this->validate_safe_svg_file( $file_path );
    }

    public function validate_safe_svg_file( $file_path ) {
        $file_path = (string) $file_path;

        if ( '' === $file_path || ! is_readable( $file_path ) ) {
            return new WP_Error( 'eim_svg_unreadable', __( 'SVG file could not be read.', 'calliope-media-import-export' ) );
        }

        $contents = $this->filesystem->get_contents( $file_path, 'svg_validation_read_failed' );
        if ( is_wp_error( $contents ) ) {
            return new WP_Error( 'eim_svg_unreadable', __( 'SVG file could not be read.', 'calliope-media-import-export' ), $contents->get_error_data() );
        }

        if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
            return new WP_Error( 'eim_svg_empty', __( 'SVG file is empty.', 'calliope-media-import-export' ) );
        }

        if ( ! class_exists( '\enshrined\svgSanitize\Sanitizer' ) || ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'eim_svg_sanitizer_unavailable', __( 'SVG file contains potentially unsafe content.', 'calliope-media-import-export' ) );
        }

        try {
            $sanitizer = new \enshrined\svgSanitize\Sanitizer();
            $sanitizer->removeRemoteReferences( true );
            $clean = $sanitizer->sanitize( $contents );
        } catch ( \Throwable $exception ) {
            return new WP_Error( 'eim_svg_unsafe', __( 'SVG file contains potentially unsafe content.', 'calliope-media-import-export' ) );
        }

        if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
            return new WP_Error( 'eim_svg_unsafe', __( 'SVG file contains potentially unsafe content.', 'calliope-media-import-export' ) );
        }

        $clean = $this->remove_remote_svg_references( $clean );
        if ( is_wp_error( $clean ) ) {
            return $clean;
        }

        if ( ! $this->is_sanitized_svg_markup( $clean ) ) {
            return new WP_Error( 'eim_svg_invalid', __( 'SVG file does not contain valid SVG markup.', 'calliope-media-import-export' ) );
        }

        return $clean;
    }

    private function remove_remote_svg_references( $contents ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'eim_svg_sanitizer_unavailable', __( 'SVG file contains potentially unsafe content.', 'calliope-media-import-export' ) );
        }

        $previous = libxml_use_internal_errors( true );
        $document = new DOMDocument();
        $loaded   = $document->loadXML( (string) $contents );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded || ! $document->documentElement ) {
            return new WP_Error( 'eim_svg_invalid', __( 'SVG file does not contain valid SVG markup.', 'calliope-media-import-export' ) );
        }

        foreach ( $document->getElementsByTagName( '*' ) as $element ) {
            if ( ! $element instanceof DOMElement || ! $element->hasAttributes() ) {
                continue;
            }

            for ( $index = $element->attributes->length - 1; $index >= 0; $index-- ) {
                $attribute = $element->attributes->item( $index );
                if ( ! $attribute instanceof DOMAttr ) {
                    continue;
                }

                if ( $this->svg_attribute_has_remote_reference( $attribute->nodeName, $attribute->value ) ) {
                    $element->removeAttributeNode( $attribute );
                }
            }
        }

        $clean = $document->saveXML( $document->documentElement, LIBXML_NOEMPTYTAG );

        return is_string( $clean ) ? $clean : new WP_Error( 'eim_svg_invalid', __( 'SVG file does not contain valid SVG markup.', 'calliope-media-import-export' ) );
    }

    private function svg_attribute_has_remote_reference( $attribute_name, $value ) {
        $attribute_name = strtolower( (string) $attribute_name );
        $url_attributes = [
            'clip-path',
            'cursor',
            'fill',
            'filter',
            'href',
            'marker-end',
            'marker-mid',
            'marker-start',
            'mask',
            'poster',
            'src',
            'srcset',
            'stroke',
            'style',
            'xlink:href',
        ];

        if ( ! in_array( $attribute_name, $url_attributes, true ) ) {
            return false;
        }

        $decoded = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $tokens  = preg_split( '/[\s,]+/', trim( $decoded ) );

        foreach ( (array) $tokens as $token ) {
            if ( $this->svg_value_starts_with_remote_reference( $token ) ) {
                return true;
            }
        }

        if ( false === stripos( $decoded, 'url(' ) ) {
            return false;
        }

        return (bool) preg_match( '/url\(\s*[\'"]?\s*((?:https?|ftp|file):|\/\/)/i', $decoded );
    }

    private function svg_value_starts_with_remote_reference( $value ) {
        $value = preg_replace( '/[\x00-\x20]+/', '', (string) $value );
        $value = strtolower( trim( $value, '\'"' ) );

        if ( '' === $value ) {
            return false;
        }

        return 0 === strpos( $value, '//' )
            || 0 === strpos( $value, 'http:' )
            || 0 === strpos( $value, 'https:' )
            || 0 === strpos( $value, 'ftp:' )
            || 0 === strpos( $value, 'file:' );
    }

    private function is_sanitized_svg_markup( $contents ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return false;
        }

        $previous = libxml_use_internal_errors( true );
        $document = new DOMDocument();
        $loaded   = $document->loadXML( (string) $contents );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded || ! $document->documentElement ) {
            return false;
        }

        return 'svg' === strtolower( $document->documentElement->localName );
    }

    public function write_sanitized_svg_file( $file_path, $contents, $error_code, $error_message ) {
        $file_path = (string) $file_path;
        $contents  = (string) $contents;

        if ( '' === $file_path || '' === $contents ) {
            return new WP_Error( $error_code, $error_message );
        }

        $written = $this->filesystem->put_contents( $file_path, $contents, 'svg_sanitized_write_failed' );
        if ( is_wp_error( $written ) ) {
            return new WP_Error( $error_code, $error_message, $written->get_error_data() );
        }

        return true;
    }

}
