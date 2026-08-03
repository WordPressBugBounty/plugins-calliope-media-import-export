<?php

use PHPUnit\Framework\TestCase;

final class EIM_Test_Wpdb {

    public $postmeta = 'wp_postmeta';
    public $meta_ids = [];
    public $column_results = [];

    public function prepare( $query, ...$args ) {
        return [
            'query' => $query,
            'args'  => $args,
        ];
    }

    public function get_var( $prepared ) {
        $key   = isset( $prepared['args'][0] ) ? $prepared['args'][0] : '';
        $value = isset( $prepared['args'][1] ) ? $prepared['args'][1] : '';

        return isset( $this->meta_ids[ $key ][ $value ] )
            ? $this->meta_ids[ $key ][ $value ]
            : null;
    }

    public function get_col( $prepared ) {
        return $this->column_results;
    }

    public function esc_like( $value ) {
        return addcslashes( $value, '_%\\' );
    }
}

final class AttachmentMatcherTest extends TestCase {

    private $matcher;
    private $wpdb;

    protected function setUp(): void {
        $GLOBALS['eim_test_attachment_urls'] = [];
        $GLOBALS['eim_test_post_meta']       = [];
        $GLOBALS['eim_test_post_types']      = [];
        $GLOBALS['eim_test_attached_files']  = [];
        $GLOBALS['eim_test_filters']         = [];

        $this->wpdb       = new EIM_Test_Wpdb();
        $GLOBALS['wpdb']  = $this->wpdb;
        $this->matcher    = new EIM_Attachment_Matcher();
    }

    public function test_normalizes_duplicate_strategy_for_free_and_advanced_modes(): void {
        $this->assertSame( 'replace_file', $this->matcher->normalize_duplicate_strategy( 'replace_file', true ) );
        $this->assertSame( 'skip', $this->matcher->normalize_duplicate_strategy( 'replace_file', false ) );
        $this->assertSame( 'skip', $this->matcher->normalize_duplicate_strategy( 'unknown', true ) );
    }

    public function test_normalizes_match_strategy_for_free_and_advanced_modes(): void {
        $this->assertSame( 'relative_path', $this->matcher->normalize_match_strategy( 'relative_path', true ) );
        $this->assertSame( 'auto', $this->matcher->normalize_match_strategy( 'relative_path', false ) );
        $this->assertSame( 'auto', $this->matcher->normalize_match_strategy( 'unknown', true ) );
    }

    public function test_finds_existing_attachment_by_stored_source_url(): void {
        $url = 'https://example.com/media/a.jpg';
        $this->wpdb->meta_ids['_eim_source_url'][ $url ] = 321;

        $this->assertSame( 321, $this->matcher->find_existing_attachment_id( $url, '', null, '', '', 'source_url' ) );
    }

    public function test_falls_back_to_wordpress_attachment_url_lookup(): void {
        $url = 'https://example.com/media/b.jpg';
        $GLOBALS['eim_test_attachment_urls'][ $url ] = 432;

        $this->assertSame( 432, $this->matcher->find_existing_attachment_id( $url, '', null, '', '', 'source_url' ) );
    }

    public function test_finds_existing_attachment_by_attached_relative_path(): void {
        $path = '2026/08/c.jpg';
        $this->wpdb->meta_ids['_wp_attached_file'][ $path ] = 543;

        $this->assertSame( 543, $this->matcher->find_existing_attachment_id( '', $path, null, '', '', 'relative_path' ) );
    }

    public function test_finds_existing_attachment_by_incoming_fingerprint(): void {
        $fingerprint = 'md5:0123456789abcdef';
        $this->wpdb->meta_ids['_eim_file_fingerprint'][ $fingerprint ] = 654;

        $this->assertSame(
            654,
            $this->matcher->find_existing_attachment_id(
                '',
                '',
                dirname( __DIR__ ) . '/fixtures/comma.csv',
                '',
                $fingerprint,
                'auto'
            )
        );
    }

    public function test_csv_attachment_id_requires_explicit_or_approved_matching(): void {
        $attachment_id = 765;
        $path          = '2026/08/d.jpg';
        $GLOBALS['eim_test_post_types'][ $attachment_id ] = 'attachment';
        $GLOBALS['eim_test_post_meta'][ $attachment_id ]['_wp_attached_file'] = $path;

        $this->assertSame(
            $attachment_id,
            $this->matcher->maybe_match_existing_attachment_by_csv_id( $attachment_id, '', $path, 'attachment_id' )
        );
        $this->assertSame( 0, $this->matcher->maybe_match_existing_attachment_by_csv_id( $attachment_id, '', $path, 'auto' ) );

        $GLOBALS['eim_test_filters']['eim_allow_csv_id_match'] = true;
        $this->assertSame(
            $attachment_id,
            $this->matcher->maybe_match_existing_attachment_by_csv_id( $attachment_id, '', $path, 'auto' )
        );
    }
}
