<?php

class EIM_Csv_Reader_Exception extends RuntimeException {

    private $error_code;

    public function __construct( $error_code, $message ) {
        $this->error_code = (string) $error_code;
        parent::__construct( (string) $message );
    }

    public function get_error_code() {
        return $this->error_code;
    }
}

class EIM_Csv_Reader {

    private $preview_limit = 5;
    private $translator;
    private $plural_translator;
    private $definitions_filter;
    private $error_reporter;

    public function __construct( $options = [] ) {
        $options = is_array( $options ) ? $options : [];

        if ( isset( $options['preview_limit'] ) ) {
            $this->preview_limit = max( 1, (int) $options['preview_limit'] );
        }

        $this->translator          = isset( $options['translator'] ) && is_callable( $options['translator'] ) ? $options['translator'] : null;
        $this->plural_translator   = isset( $options['plural_translator'] ) && is_callable( $options['plural_translator'] ) ? $options['plural_translator'] : null;
        $this->definitions_filter  = isset( $options['definitions_filter'] ) && is_callable( $options['definitions_filter'] ) ? $options['definitions_filter'] : null;
        $this->error_reporter      = isset( $options['error_reporter'] ) && is_callable( $options['error_reporter'] ) ? $options['error_reporter'] : null;
    }

    public function inspect_file( $file_path ) {
        if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
            throw $this->error( 'eim_csv_missing', 'Could not read the uploaded CSV file.' );
        }

        $this->detect_incompatible_import_file( $file_path );
        $delimiter = $this->detect_csv_delimiter( $file_path );
        $handle    = $this->open_read_handle( $file_path );

        if ( ! $handle ) {
            throw $this->error( 'eim_csv_unreadable', 'Could not open the uploaded CSV file.' );
        }

        $headers = $this->read_csv_row( $handle, $delimiter, true );
        if ( false === $headers || $this->is_csv_row_empty( $headers ) ) {
            $this->close_file_handle( $handle );
            throw $this->error( 'eim_csv_empty', 'The CSV is empty.' );
        }

        $header_map = $this->map_headers( $headers );
        if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
            $this->close_file_handle( $handle );
            throw $this->error( 'eim_csv_invalid', 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.' );
        }

        $summary           = $this->empty_summary();
        $preview_rows      = [];
        $missing_row_index = [];
        $row_count         = 0;

        while ( false !== ( $row = $this->read_csv_row( $handle, $delimiter ) ) ) {
            if ( $this->is_csv_row_empty( $row ) ) {
                continue;
            }

            $row_count++;
            $mapped_row = $this->build_row_from_csv( $row, $header_map );
            $summary    = $this->accumulate_csv_summary( $summary, $mapped_row );

            if ( ! empty( $summary['rows_missing_source'] ) && $summary['rows_missing_source'] === count( $missing_row_index ) + 1 && count( $missing_row_index ) < 3 ) {
                $missing_row_index[] = $row_count;
            }

            if ( count( $preview_rows ) < $this->preview_limit ) {
                $preview_rows[] = $this->build_preview_row( $row_count, $mapped_row );
            }
        }

        $this->close_file_handle( $handle );

        if ( $row_count <= 0 ) {
            try {
                $fallback = $this->inspect_file_with_normalized_line_endings( $file_path, $delimiter );
                if ( ! empty( $fallback['total_rows'] ) ) {
                    return $fallback;
                }
            } catch ( EIM_Csv_Reader_Exception $exception ) {
                // Preserve the valid header-only result from the streaming parser.
            }

            return $this->build_inspection_result( $delimiter, $headers, $header_map, $summary, [], [] );
        }

        $summary['recommended_mode'] = $this->determine_recommended_source_mode( $summary );

        return $this->build_inspection_result(
            $delimiter,
            $headers,
            $header_map,
            $summary,
            $preview_rows,
            $this->build_csv_warnings( $summary, $header_map, $missing_row_index )
        );
    }

    public function build_validation_preview( $inspection ) {
        $inspection = is_array( $inspection ) ? $inspection : [];
        $delimiter  = isset( $inspection['delimiter'] ) ? (string) $inspection['delimiter'] : ',';

        return [
            'delimiter'          => $delimiter,
            'delimiter_label'    => $this->get_delimiter_label( $delimiter ),
            'header_count'       => isset( $inspection['headers'] ) && is_array( $inspection['headers'] ) ? count( $inspection['headers'] ) : 0,
            'recognized_columns' => isset( $inspection['recognized_columns'] ) ? (array) $inspection['recognized_columns'] : [],
            'summary'            => isset( $inspection['summary'] ) && is_array( $inspection['summary'] ) ? $inspection['summary'] : [],
            'sample_rows'        => isset( $inspection['preview_rows'] ) ? (array) $inspection['preview_rows'] : [],
            'warnings'           => isset( $inspection['warnings'] ) ? array_values( array_filter( (array) $inspection['warnings'] ) ) : [],
        ];
    }

    public function detect_csv_delimiter( $file_path ) {
        $handle = $this->open_read_handle( $file_path );
        if ( ! $handle ) {
            throw $this->error( 'eim_csv_unreadable', 'Could not open the uploaded CSV file.' );
        }

        $sample_lines = [];
        while ( count( $sample_lines ) < 5 && false !== ( $line = fgets( $handle ) ) ) {
            $line = $this->strip_utf8_bom( (string) $line );
            if ( '' !== trim( $line ) ) {
                $sample_lines[] = $line;
            }
        }
        $this->close_file_handle( $handle );

        if ( empty( $sample_lines ) ) {
            throw $this->error( 'eim_csv_empty', 'The CSV is empty.' );
        }

        if ( preg_match( '/^sep=(.)\s*$/i', trim( (string) $sample_lines[0] ), $matches ) ) {
            return '\\t' === $matches[1] ? "\t" : $matches[1];
        }

        $best       = ',';
        $best_score = -1;

        foreach ( [ ',', ';', "\t", '|' ] as $candidate ) {
            $counts = [];
            $score  = 0;

            foreach ( $sample_lines as $line ) {
                $column_count = count( str_getcsv( $line, $candidate, '"', '\\' ) );
                $counts[]     = $column_count;
                if ( $column_count > 1 ) {
                    $score += $column_count;
                }
            }

            $multi_column_counts = array_filter(
                $counts,
                function( $count ) {
                    return $count > 1;
                }
            );

            if ( count( $counts ) === count( $multi_column_counts ) ) {
                $score += 100;
            }

            if ( count( array_unique( $counts ) ) === 1 && ! empty( $counts ) && $counts[0] > 1 ) {
                $score += 200;
            }

            if ( $score > $best_score ) {
                $best       = $candidate;
                $best_score = $score;
            }
        }

        return $best;
    }

    public function read_file_signature( $file_path, $length = 4 ) {
        $handle = $this->open_read_handle( $file_path );
        if ( ! $handle ) {
            return false;
        }

        $signature = $this->read_file_chunk( $handle, max( 1, (int) $length ) );
        $this->close_file_handle( $handle );

        return is_string( $signature ) ? $signature : false;
    }

    public function get_delimiter_label( $delimiter ) {
        switch ( (string) $delimiter ) {
            case ';':
                return $this->translate( 'Semicolon (;)' );
            case "\t":
                return $this->translate( 'Tab' );
            case '|':
                return $this->translate( 'Pipe (|)' );
            case ',':
            default:
                return $this->translate( 'Comma (,)' );
        }
    }

    public function read_csv_row( $handle, $delimiter, $strip_bom = false ) {
        if ( ! is_resource( $handle ) ) {
            return false;
        }

        $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
        if ( false === $row ) {
            return false;
        }

        if ( $strip_bom && isset( $row[0] ) ) {
            $row[0] = $this->strip_utf8_bom( (string) $row[0] );

            $first_line = implode( (string) $delimiter, array_map( 'strval', $row ) );
            if ( preg_match( '/^sep=.+$/i', trim( $first_line ) ) ) {
                $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
                if ( false === $row ) {
                    return false;
                }

                if ( isset( $row[0] ) ) {
                    $row[0] = $this->strip_utf8_bom( (string) $row[0] );
                }
            }
        }

        return $row;
    }

    public function strip_utf8_bom( $value ) {
        return preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value );
    }

    public function is_csv_row_empty( $row ) {
        if ( ! is_array( $row ) ) {
            return true;
        }

        foreach ( $row as $value ) {
            if ( null !== $value && '' !== trim( (string) $value ) ) {
                return false;
            }
        }

        return true;
    }

    public function map_headers( $headers ) {
        $map     = [];
        $headers = array_map( 'strtolower', array_map( 'trim', (array) $headers ) );

        foreach ( $headers as $index => $header ) {
            foreach ( $this->get_import_header_definitions() as $key => $options ) {
                $aliases = isset( $options['aliases'] ) ? (array) $options['aliases'] : [];
                if ( isset( $options['label'] ) ) {
                    $aliases[] = (string) $options['label'];
                }
                $aliases = array_map( 'strtolower', array_map( 'trim', $aliases ) );

                if ( in_array( $header, $aliases, true ) ) {
                    $map[ $key ] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    public function build_row_from_csv( $row_data, $header_map ) {
        $row = [];
        foreach ( (array) $header_map as $key => $index ) {
            $row[ $key ] = isset( $row_data[ $index ] ) ? $row_data[ $index ] : '';
        }
        return $row;
    }

    public function open_read_handle( $file_path ) {
        $warning = '';
        $this->capture_native_warning(
            function() {
                return ini_set( 'auto_detect_line_endings', '1' );
            },
            $warning
        );

        $handle = $this->capture_native_warning(
            function() use ( $file_path ) {
                return fopen( $file_path, 'rb' );
            },
            $warning
        );

        if ( ! is_resource( $handle ) ) {
            $this->report_file_error( 'csv_stream_open_failed', $file_path, $warning );
            return false;
        }

        return $handle;
    }

    public function close_file_handle( $handle ) {
        if ( is_resource( $handle ) ) {
            fclose( $handle );
        }
    }

    private function inspect_file_with_normalized_line_endings( $file_path, $delimiter ) {
        $warning  = '';
        $contents = $this->capture_native_warning(
            function() use ( $file_path ) {
                return file_get_contents( $file_path );
            },
            $warning
        );
        if ( false === $contents ) {
            $this->report_file_error( 'csv_fallback_read_failed', $file_path, $warning );
            throw $this->error( 'eim_csv_empty', 'The CSV is empty.' );
        }

        if ( '' === $contents ) {
            throw $this->error( 'eim_csv_empty', 'The CSV is empty.' );
        }

        $contents = $this->strip_utf8_bom( (string) $contents );
        $lines    = preg_split( "/\n/", str_replace( [ "\r\n", "\r" ], "\n", $contents ) );
        if ( ! is_array( $lines ) ) {
            throw $this->error( 'eim_csv_no_rows', 'The CSV has headers but no data rows to import. Export or upload a CSV with at least one media row below the header.' );
        }

        $rows = [];
        foreach ( $lines as $line ) {
            if ( '' === trim( (string) $line ) ) {
                continue;
            }

            $line = (string) $line;
            if ( empty( $rows ) && preg_match( '/^sep=(.)\s*$/i', trim( $line ), $matches ) ) {
                $delimiter = '\\t' === $matches[1] ? "\t" : $matches[1];
                continue;
            }
            $rows[] = str_getcsv( $line, $delimiter, '"', '\\' );
        }

        if ( empty( $rows ) ) {
            throw $this->error( 'eim_csv_empty', 'The CSV is empty.' );
        }

        $headers = array_shift( $rows );
        if ( $this->is_csv_row_empty( $headers ) ) {
            throw $this->error( 'eim_csv_empty', 'The CSV is empty.' );
        }

        $header_map = $this->map_headers( $headers );
        if ( ! isset( $header_map['url'] ) && ! isset( $header_map['rel_path'] ) ) {
            throw $this->error( 'eim_csv_invalid', 'Invalid CSV. Missing "Absolute URL" or "Relative Path" column.' );
        }

        $summary           = $this->empty_summary();
        $preview_rows      = [];
        $missing_row_index = [];
        $row_count         = 0;

        foreach ( $rows as $row ) {
            if ( $this->is_csv_row_empty( $row ) ) {
                continue;
            }

            $row_count++;
            $mapped_row = $this->build_row_from_csv( $row, $header_map );
            $summary    = $this->accumulate_csv_summary( $summary, $mapped_row );

            if ( ! empty( $summary['rows_missing_source'] ) && $summary['rows_missing_source'] === count( $missing_row_index ) + 1 && count( $missing_row_index ) < 3 ) {
                $missing_row_index[] = $row_count;
            }

            if ( count( $preview_rows ) < $this->preview_limit ) {
                $preview_rows[] = $this->build_preview_row( $row_count, $mapped_row );
            }
        }

        if ( $row_count <= 0 ) {
            return $this->build_inspection_result( $delimiter, $headers, $header_map, $summary, [], [] );
        }

        $summary['recommended_mode'] = $this->determine_recommended_source_mode( $summary );

        return $this->build_inspection_result(
            $delimiter,
            $headers,
            $header_map,
            $summary,
            $preview_rows,
            $this->build_csv_warnings( $summary, $header_map, $missing_row_index )
        );
    }

    private function detect_incompatible_import_file( $file_path ) {
        $signature = $this->read_file_signature( $file_path, 4 );
        if ( false !== $signature && in_array( $signature, [ "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" ], true ) ) {
            throw $this->error( 'eim_csv_zip_upload', 'The selected file is a ZIP archive. The simple import tool expects a plain CSV file, not a ZIP or export bundle.' );
        }
    }

    private function empty_summary() {
        return [
            'total_rows'              => 0,
            'rows_with_url'           => 0,
            'rows_with_relative_path' => 0,
            'rows_with_both'          => 0,
            'rows_missing_source'     => 0,
            'recommended_mode'        => 'unknown',
        ];
    }

    private function accumulate_csv_summary( $summary, $row ) {
        $url      = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
        $rel_path = isset( $row['rel_path'] ) ? trim( (string) $row['rel_path'] ) : '';
        $has_url  = '' !== $url;
        $has_path = '' !== $rel_path;

        $summary['total_rows']++;
        $summary['rows_with_url']           += $has_url ? 1 : 0;
        $summary['rows_with_relative_path'] += $has_path ? 1 : 0;
        $summary['rows_with_both']          += $has_url && $has_path ? 1 : 0;
        $summary['rows_missing_source']     += ! $has_url && ! $has_path ? 1 : 0;

        return $summary;
    }

    private function build_preview_row( $row_number, $row ) {
        return [
            'row_number'    => max( 0, (int) $row_number ),
            'source'        => isset( $row['url'] ) ? trim( (string) $row['url'] ) : '',
            'relative_path' => isset( $row['rel_path'] ) ? trim( (string) $row['rel_path'] ) : '',
            'title'         => isset( $row['title'] ) ? trim( (string) $row['title'] ) : '',
            'alt'           => isset( $row['alt'] ) ? trim( (string) $row['alt'] ) : '',
        ];
    }

    private function determine_recommended_source_mode( $summary ) {
        $with_url   = isset( $summary['rows_with_url'] ) ? (int) $summary['rows_with_url'] : 0;
        $with_path  = isset( $summary['rows_with_relative_path'] ) ? (int) $summary['rows_with_relative_path'] : 0;
        $total_rows = isset( $summary['total_rows'] ) ? (int) $summary['total_rows'] : 0;

        if ( $total_rows <= 0 ) {
            return 'unknown';
        }
        if ( $with_url > 0 && 0 === $with_path ) {
            return 'remote';
        }
        if ( $with_path > 0 && 0 === $with_url ) {
            return 'local';
        }
        return $with_url > 0 && $with_path > 0 ? 'mixed' : 'unknown';
    }

    private function build_inspection_result( $delimiter, $headers, $header_map, $summary, $preview_rows, $warnings ) {
        if ( empty( $summary['total_rows'] ) && empty( $warnings ) ) {
            $warnings[] = $this->translate( 'No importable media rows were found. The CSV may only contain headers, or your export filters may have matched no media items.' );
        }

        return [
            'delimiter'          => $delimiter,
            'headers'            => $headers,
            'header_map'         => $header_map,
            'recognized_columns' => $this->get_recognized_columns_for_preview( $header_map ),
            'total_rows'         => (int) $summary['total_rows'],
            'summary'            => $summary,
            'preview_rows'       => $preview_rows,
            'warnings'           => $warnings,
        ];
    }

    private function get_recognized_columns_for_preview( $header_map ) {
        $recognized = [];
        foreach ( $this->get_import_header_definitions() as $key => $definition ) {
            if ( isset( $header_map[ $key ] ) && ! empty( $definition['label'] ) ) {
                $recognized[] = (string) $definition['label'];
            }
        }
        return $recognized;
    }

    private function get_import_header_definitions() {
        $definitions = [
            'id' => [ 'aliases' => [ 'id', 'attachment id', 'media id', 'id del adjunto', 'id do anexo', 'id allegato', 'id de la pièce jointe' ], 'label' => $this->translate( 'ID' ) ],
            'url' => [ 'aliases' => [ 'absolute url', 'url', 'absolute_url', 'source url', 'source_url', 'url absoluta', 'url absoluto', 'url absolue', 'url assoluto', '绝对 url' ], 'label' => $this->translate( 'Absolute URL' ) ],
            'rel_path' => [ 'aliases' => [ 'relative path', 'relative_path', 'path', 'ruta relativa', 'caminho relativo', 'percorso relativo', 'chemin relatif', '相对路径' ], 'label' => $this->translate( 'Relative Path' ) ],
            'title' => [ 'aliases' => [ 'title', 'post_title', 'título', 'titulo', 'titre', 'titolo', '标题' ], 'label' => $this->translate( 'Title' ) ],
            'alt' => [ 'aliases' => [ 'alt text', 'alt', 'alternative text', 'texto alternativo', 'texto alt', 'texte alternatif', 'testo alternativo', '替代文本' ], 'label' => $this->translate( 'Alt Text' ) ],
            'caption' => [ 'aliases' => [ 'caption', 'post_excerpt', 'subtítulo', 'subtitulo', 'legenda', 'légende', 'didascalia' ], 'label' => $this->translate( 'Caption' ) ],
            'description' => [ 'aliases' => [ 'description', 'post_content', 'descripción', 'descripcion', 'descrição', 'descricao', 'descrizione', '描述' ], 'label' => $this->translate( 'Description' ) ],
        ];

        return $this->definitions_filter ? call_user_func( $this->definitions_filter, $definitions ) : $definitions;
    }

    private function build_csv_warnings( $summary, $header_map, $missing_row_index ) {
        $warnings = [];

        if ( ! isset( $header_map['url'] ) && isset( $header_map['rel_path'] ) ) {
            $warnings[] = $this->translate( 'This CSV relies on Relative Path. Use Local Import Mode or make sure the referenced files already exist in uploads.' );
        }

        if ( ! empty( $summary['rows_missing_source'] ) ) {
            $count = (int) $summary['rows_missing_source'];
            $rows  = implode( ', ', array_map( 'intval', (array) $missing_row_index ) );
            $note  = $rows ? sprintf( $this->translate( ' Example rows: %s.' ), $rows ) : '';
            $warnings[] = sprintf(
                $this->translate_plural(
                    '%d row is missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                    '%d rows are missing both Absolute URL and Relative Path and will fail unless the CSV is corrected.',
                    $count
                ),
                $count
            ) . $note;
        }

        if ( ! isset( $header_map['title'] ) && ! isset( $header_map['alt'] ) && ! isset( $header_map['caption'] ) && ! isset( $header_map['description'] ) ) {
            $warnings[] = $this->translate( 'Only source columns were detected. Media metadata fields will not be updated from this CSV.' );
        }

        return array_values( array_filter( $warnings ) );
    }

    private function read_file_chunk( $handle, $length ) {
        return fread( $handle, $length );
    }

    private function error( $code, $message ) {
        return new EIM_Csv_Reader_Exception( $code, $this->translate( $message ) );
    }

    private function translate( $message ) {
        return $this->translator ? (string) call_user_func( $this->translator, $message ) : (string) $message;
    }

    private function translate_plural( $single, $plural, $count ) {
        if ( $this->plural_translator ) {
            return (string) call_user_func( $this->plural_translator, $single, $plural, $count );
        }
        return 1 === (int) $count ? $single : $plural;
    }

    private function report_file_error( $event, $path, $warning ) {
        if ( $this->error_reporter ) {
            call_user_func(
                $this->error_reporter,
                $event,
                [
                    'operation' => 'read',
                    'path'      => str_replace( '\\', '/', (string) $path ),
                    'warning'   => (string) $warning,
                ]
            );
        }
    }

    private function capture_native_warning( $callback, &$warning ) {
        $warning = '';
        set_error_handler(
            function( $severity, $message ) use ( &$warning ) {
                $warning = (string) $message;
                return true;
            }
        );

        try {
            return call_user_func( $callback );
        } finally {
            restore_error_handler();
        }
    }
}
