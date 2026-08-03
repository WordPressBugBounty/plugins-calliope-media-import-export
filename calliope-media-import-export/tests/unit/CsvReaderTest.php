<?php

use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase {

    private $reader;

    protected function setUp(): void {
        $this->reader = new EIM_Csv_Reader( [ 'preview_limit' => 2 ] );
    }

    public function test_detects_semicolon_delimiter_and_skips_excel_directive(): void {
        $inspection = $this->reader->inspect_file( $this->fixture( 'semicolon.csv' ) );

        $this->assertSame( ';', $inspection['delimiter'] );
        $this->assertSame( 1, $inspection['total_rows'] );
        $this->assertSame( 'local', $inspection['summary']['recommended_mode'] );
        $this->assertSame( 'Tercero', $inspection['preview_rows'][0]['title'] );
    }

    public function test_detects_tab_delimiter(): void {
        $this->assertSame( "\t", $this->reader->detect_csv_delimiter( $this->fixture( 'tab.csv' ) ) );
    }

    public function test_strips_utf8_bom_without_changing_text(): void {
        $title = "T\xC3\xADtulo";

        $this->assertSame( $title, $this->reader->strip_utf8_bom( "\xEF\xBB\xBF" . $title ) );
    }

    public function test_inspects_quoted_values_and_builds_summary(): void {
        $inspection = $this->reader->inspect_file( $this->fixture( 'comma.csv' ) );

        $this->assertSame( ',', $inspection['delimiter'] );
        $this->assertSame( 3, $inspection['total_rows'] );
        $this->assertSame( 'mixed', $inspection['summary']['recommended_mode'] );
        $this->assertSame( 1, $inspection['summary']['rows_missing_source'] );
        $this->assertCount( 2, $inspection['preview_rows'] );
        $this->assertSame( 'Title, with comma', $inspection['preview_rows'][0]['title'] );
    }

    public function test_rejects_csv_without_source_columns(): void {
        try {
            $this->reader->inspect_file( $this->fixture( 'invalid.csv' ) );
            $this->fail( 'A CSV without a source column should be rejected.' );
        } catch ( EIM_Csv_Reader_Exception $exception ) {
            $this->assertSame( 'eim_csv_invalid', $exception->get_error_code() );
        }
    }

    public function test_rejects_zip_signature_before_parsing(): void {
        $path = tempnam( sys_get_temp_dir(), 'eim-zip-' );
        file_put_contents( $path, "PK\x03\x04not-a-csv" );

        try {
            $this->reader->inspect_file( $path );
            $this->fail( 'A ZIP archive should be rejected before CSV parsing.' );
        } catch ( EIM_Csv_Reader_Exception $exception ) {
            $this->assertSame( 'eim_csv_zip_upload', $exception->get_error_code() );
        } finally {
            unlink( $path );
        }
    }

    private function fixture( $name ) {
        return dirname( __DIR__ ) . '/fixtures/' . $name;
    }
}
