<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Attachment_Matcher {

    private $file_fingerprint_resolver;

    public function set_file_fingerprint_resolver( $resolver ) {
        if ( ! is_callable( $resolver ) ) {
            throw new InvalidArgumentException( 'A file fingerprint resolver callback is required.' );
        }

        $this->file_fingerprint_resolver = $resolver;
    }

    private function resolve_file_fingerprint( $file_path ) {
        if ( ! is_callable( $this->file_fingerprint_resolver ) ) {
            return '';
        }

        return (string) call_user_func( $this->file_fingerprint_resolver, $file_path );
    }

    public function find_existing_attachment_id( $url, $rel_path, $incoming_file_path = null, $filename = '', $incoming_fingerprint = '', $match_strategy = 'auto' ) {
        global $wpdb;

        $url      = is_string( $url ) ? trim( $url ) : '';
        $rel_path = is_string( $rel_path ) ? trim( $rel_path ) : '';
        $filename = is_string( $filename ) ? trim( $filename ) : '';
        $match_strategy = $this->normalize_match_strategy( $match_strategy );

        if ( 'attachment_id' === $match_strategy ) {
            return 0;
        }

        if ( in_array( $match_strategy, [ 'auto', 'source_url' ], true ) && '' !== $url ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for existing imported media.
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_eim_source_url',
                    $url
                )
            );
            if ( $id ) {
                return $id;
            }
        }

        if ( in_array( $match_strategy, [ 'auto', 'relative_path' ], true ) && '' !== $rel_path ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for existing imported media.
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_eim_source_rel_path',
                    $rel_path
                )
            );
            if ( $id ) {
                return $id;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for existing attachments by relative path.
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_wp_attached_file',
                    $rel_path
                )
            );
            if ( $id ) {
                return $id;
            }
        }

        if ( in_array( $match_strategy, [ 'auto', 'source_url' ], true ) && '' !== $url ) {
            $local_id = (int) attachment_url_to_postid( $url );
            if ( $local_id ) {
                return $local_id;
            }
        }

        $has_incoming_file = ! empty( $incoming_file_path ) && is_string( $incoming_file_path ) && file_exists( $incoming_file_path );
        $fingerprint       = '';

        if ( $has_incoming_file ) {
            $fingerprint = $incoming_fingerprint ? (string) $incoming_fingerprint : $this->resolve_file_fingerprint( $incoming_file_path );
            if ( $fingerprint ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for fingerprint matching.
                $id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                        '_eim_file_fingerprint',
                        $fingerprint
                    )
                );
                if ( $id ) {
                    return $id;
                }

                if ( 0 === strpos( $fingerprint, 'md5:' ) ) {
                    $md5 = substr( $fingerprint, 4 );
                    if ( $md5 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for md5 matching.
                        $id = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                                '_eim_file_hash',
                                $md5
                            )
                        );
                        if ( $id ) {
                            return $id;
                        }
                    }
                }
            }
        }

        if ( in_array( $match_strategy, [ 'auto', 'filename' ], true ) && $filename ) {
            $candidates = $this->find_attachments_by_name_candidates( $filename, $rel_path );

            foreach ( $candidates as $candidate_id ) {
                $candidate_id = absint( $candidate_id );
                if ( ! $candidate_id ) {
                    continue;
                }

                if ( 'filename' === $match_strategy ) {
                    return $candidate_id;
                }

                if ( ! $has_incoming_file || ! $fingerprint ) {
                    continue;
                }

                $candidate_fp = (string) get_post_meta( $candidate_id, '_eim_file_fingerprint', true );
                if ( ! $candidate_fp ) {
                    $candidate_file = get_attached_file( $candidate_id );
                    if ( ! $candidate_file || ! file_exists( $candidate_file ) ) {
                        continue;
                    }

                    $candidate_fp = $this->resolve_file_fingerprint( $candidate_file );
                    if ( $candidate_fp ) {
                        update_post_meta( $candidate_id, '_eim_file_fingerprint', $candidate_fp );

                        if ( 0 === strpos( $candidate_fp, 'md5:' ) ) {
                            update_post_meta( $candidate_id, '_eim_file_hash', substr( $candidate_fp, 4 ) );
                        }
                    }
                }

                if ( $candidate_fp && hash_equals( $fingerprint, $candidate_fp ) ) {
                    return $candidate_id;
                }
            }
        }

        return 0;
    }

    public function find_existing_attachment_id_by_fingerprint( $fingerprint ) {
        global $wpdb;

        $fingerprint = is_string( $fingerprint ) ? trim( $fingerprint ) : '';
        if ( '' === $fingerprint ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared fingerprint lookup for a downloaded incoming media file.
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_eim_file_fingerprint',
                $fingerprint
            )
        );

        if ( $id ) {
            return $id;
        }

        if ( 0 === strpos( $fingerprint, 'md5:' ) ) {
            $md5 = substr( $fingerprint, 4 );
            if ( $md5 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backward-compatible MD5 fingerprint lookup.
                return (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                        '_eim_file_hash',
                        $md5
                    )
                );
            }
        }

        return 0;
    }

    public function find_attachments_by_name_candidates( $filename, $rel_path = '' ) {
        global $wpdb;

        $filename = trim( (string) $filename );
        if ( '' === $filename ) {
            return [];
        }

        $pathinfo = pathinfo( $filename );
        $base     = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : $filename;
        $ext      = isset( $pathinfo['extension'] ) && '' !== $pathinfo['extension'] ? '.' . $pathinfo['extension'] : '';
        $dir      = '';

        if ( $rel_path ) {
            $dir = dirname( (string) $rel_path );
            if ( $dir && '.' !== $dir ) {
                $dir = trim( $dir, '/' );
            } else {
                $dir = '';
            }
        }

        if ( '' !== $dir ) {
            $exact = $dir . '/' . $filename;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for filename candidates.
            $ids   = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = %s AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s)
                     LIMIT 50",
                    '_wp_attached_file',
                    $exact,
                    $dir . '/' . $base . '-%' . $ext,
                    $dir . '/' . $base . '%' . $ext
                )
            );

            return array_map( 'absint', (array) $ids );
        }

        $like_exact    = '%' . $wpdb->esc_like( '/' . $filename );
        $like_variants = '%' . $wpdb->esc_like( '/' . $base . '-' ) . '%' . $wpdb->esc_like( $ext );
        $like_scaled   = '%' . $wpdb->esc_like( '/' . $base ) . '%' . $wpdb->esc_like( $ext );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared postmeta lookup for filename candidates.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
                 LIMIT 50",
                '_wp_attached_file',
                $like_exact,
                $like_variants,
                $like_scaled
            )
        );

        return array_map( 'absint', (array) $ids );
    }

    public function maybe_match_existing_attachment_by_csv_id( $csv_id, $url, $rel_path, $match_strategy = 'auto' ) {
        $csv_id = absint( $csv_id );
        if ( ! $csv_id || 'attachment' !== get_post_type( $csv_id ) ) {
            return 0;
        }

        $match_strategy = $this->normalize_match_strategy( $match_strategy );
        if ( ! in_array( $match_strategy, [ 'auto', 'attachment_id' ], true ) ) {
            return 0;
        }

        if ( 'attachment_id' === $match_strategy ) {
            return $csv_id;
        }

        $allow_match = 'attachment_id' === $match_strategy
            ? true
            : (bool) apply_filters( 'eim_allow_csv_id_match', false, $csv_id, $url, $rel_path );
        if ( ! $allow_match ) {
            return 0;
        }

        $attached_file = (string) get_post_meta( $csv_id, '_wp_attached_file', true );
        $stored_url    = (string) get_post_meta( $csv_id, '_eim_source_url', true );
        $stored_rel    = (string) get_post_meta( $csv_id, '_eim_source_rel_path', true );

        if ( $rel_path && ( $attached_file === $rel_path || $stored_rel === $rel_path ) ) {
            return $csv_id;
        }

        if ( $url && $stored_url === $url ) {
            return $csv_id;
        }

        return 0;
    }

    public function normalize_duplicate_strategy( $strategy, $allow_advanced = true ) {
        $strategy = sanitize_key( (string) $strategy );
        $allowed  = $allow_advanced
            ? [ 'skip', 'update_metadata', 'update_selected_fields', 'replace_file', 'force_new' ]
            : [ 'skip' ];

        if ( ! in_array( $strategy, $allowed, true ) ) {
            $strategy = 'skip';
        }

        return $strategy;
    }

    public function normalize_match_strategy( $strategy, $allow_advanced = true ) {
        $strategy = sanitize_key( (string) $strategy );
        $allowed  = $allow_advanced
            ? [ 'auto', 'attachment_id', 'source_url', 'relative_path', 'filename' ]
            : [ 'auto' ];

        if ( ! in_array( $strategy, $allowed, true ) ) {
            $strategy = 'auto';
        }

        return $strategy;
    }

}
