<?php
/**
 * Admin UI and admin-only behavior for the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'current_screen', [ $this, 'maybe_hide_admin_notices' ], 0 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_eim_dismiss_review_notice', [ $this, 'dismiss_review_notice' ] );
        add_filter( 'plugin_action_links_' . EIM_BASENAME, [ $this, 'add_settings_link' ] );
        add_action( 'admin_post_eim_download_sample_csv', [ $this, 'download_sample_csv' ] );
    }

    public function maybe_hide_admin_notices( $screen ) {
        if ( ! is_object( $screen ) ) {
            return;
        }

        $is_target = ( isset( $screen->id ) && 'media_page_' . EIM_ADMIN_PAGE_SLUG === $screen->id );

        if ( ! $is_target && isset( $_GET['page'] ) ) {
            $is_target = ( EIM_ADMIN_PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) ) );
        }

        if ( $is_target ) {
            remove_all_actions( 'admin_notices' );
            remove_all_actions( 'all_admin_notices' );
            remove_all_actions( 'network_admin_notices' );
            remove_all_actions( 'user_admin_notices' );
        }
    }

    public function register_menu() {
        add_media_page(
            esc_html__( 'Export/Import Media', EIM_TEXT_DOMAIN ),
            esc_html__( 'Export/Import Media', EIM_TEXT_DOMAIN ),
            eim_get_required_capability(),
            EIM_ADMIN_PAGE_SLUG,
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( ! $this->is_admin_page_request( $hook ) ) {
            return;
        }

        wp_enqueue_style(
            'eim-custom-styles',
            EIM_URL . 'assets/css/style.css',
            [],
            EIM_VERSION
        );

        wp_enqueue_script(
            'eim-importer-js',
            EIM_URL . 'assets/js/importer.js',
            [ 'jquery' ],
            EIM_VERSION,
            true
        );

        $script_data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'eim_import_nonce' ),
            'i18n'     => $this->get_import_script_i18n(),
            'fallback_i18n' => $this->get_import_script_i18n_defaults(),
            'config'   => [
                'is_pro_active' => eim_is_pro_active(),
                'features'      => EIM_Config::get_feature_flags(),
                'default_batch' => eim_get_setting( 'import.default_batch_size', '25' ),
            ],
        ];

        wp_localize_script(
            'eim-importer-js',
            'eim_ajax',
            apply_filters( 'eim_admin_script_data', $script_data )
        );

        wp_add_inline_script(
            'eim-importer-js',
            '
            jQuery(document).on("click", ".eim-review-notice-top .notice-dismiss", function() {
                jQuery.ajax({
                    url: eim_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "eim_dismiss_review_notice",
                        nonce: eim_ajax.nonce
                    }
                });
            });
        '
        );
    }

    public function dismiss_review_notice() {
        check_ajax_referer( 'eim_import_nonce', 'nonce' );

        if ( ! eim_current_user_can_manage() ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', EIM_TEXT_DOMAIN ) ] );
        }

        update_user_meta( get_current_user_id(), 'eim_review_notice_dismissed', 1 );
        wp_send_json_success();
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'upload.php?page=' . EIM_ADMIN_PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', EIM_TEXT_DOMAIN ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function render_admin_page() {
        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', EIM_TEXT_DOMAIN ) );
        }

        $context = $this->get_page_context();

        do_action( 'eim_admin_before_page', $context );
        ?>
        <div class="wrap eim-admin-shell">
            <?php $this->render_page_header( $context ); ?>

            <div class="eim-admin-columns">
                <div class="eim-admin-main">
                    <?php $this->render_review_notice( $context ); ?>
                    <?php $this->render_export_section( $context ); ?>
                    <?php do_action( 'eim_admin_after_export_section', $context ); ?>
                    <?php $this->render_import_section( $context ); ?>
                    <?php do_action( 'eim_admin_after_import_section', $context ); ?>
                    <?php $this->render_import_preview_panel(); ?>
                    <?php $this->render_progress_panel(); ?>
                    <?php $this->render_footer_review( $context ); ?>
                </div>
            </div>

            <?php do_action( 'eim_admin_sidebar', $context ); ?>
        </div>
        <?php
        do_action( 'eim_admin_after_page', $context );
    }

    public function download_sample_csv() {
        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', EIM_TEXT_DOMAIN ) );
        }

        check_admin_referer( 'eim_download_sample_csv' );

        @set_time_limit( 0 );
        nocache_headers();

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="eim-sample.csv"' );

        $output  = fopen( 'php://output', 'w' );
        $headers = class_exists( 'EIM_Exporter' ) ? EIM_Exporter::get_csv_headers() : [ 'ID', 'Absolute URL', 'Relative Path', 'File', 'Alt Text', 'Caption', 'Description', 'Title' ];

        fputcsv( $output, $headers );
        fputcsv(
            $output,
            [
                '',
                'https://example.com/wp-content/uploads/2025/08/sample.jpg',
                '/2025/08/sample.jpg',
                'sample.jpg',
                'Sample alt text',
                'Sample caption',
                'Sample description',
                'Sample title',
            ]
        );

        fclose( $output );
        exit;
    }

    private function is_admin_page_request( $hook ) {
        $is_target = ( 'media_page_' . EIM_ADMIN_PAGE_SLUG === $hook );

        if ( ! $is_target && isset( $_GET['page'] ) && EIM_ADMIN_PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
            $is_target = true;
        }

        return $is_target;
    }

    private function get_page_context() {
        $urls = eim_get_setting( 'urls', [] );

        return [
            'version'             => EIM_VERSION,
            'is_pro_active'       => eim_is_pro_active(),
            'features'            => EIM_Config::get_feature_flags(),
            'documentation_url'   => isset( $urls['documentation'] ) ? $urls['documentation'] : '',
            'support_url'         => isset( $urls['support'] ) ? $urls['support'] : '',
            'review_url'          => isset( $urls['reviews'] ) ? $urls['reviews'] : '',
            'kofi_url'            => isset( $urls['kofi'] ) ? $urls['kofi'] : '',
            'sample_url'          => wp_nonce_url( admin_url( 'admin-post.php?action=eim_download_sample_csv' ), 'eim_download_sample_csv' ),
            'export_action_url'   => admin_url( 'admin-post.php?action=eim_export_csv' ),
            'export_defaults'     => EIM_Config::get_export_defaults(),
            'export_media_types'  => EIM_Config::get_export_media_type_options(),
            'attachment_filters'  => EIM_Config::get_export_attachment_filter_options(),
            'import_batch_sizes'  => EIM_Config::get_import_batch_size_options(),
            'import_options'      => EIM_Config::get_import_option_definitions(),
        ];
    }

    private function get_import_script_i18n() {
        return [
            'select_csv'                  => esc_html__( 'Please select a CSV file.', EIM_TEXT_DOMAIN ),
            'validating'                  => esc_html__( 'Validating file...', EIM_TEXT_DOMAIN ),
            'validation_failed'           => esc_html__( 'Validation failed:', EIM_TEXT_DOMAIN ),
            'validation_success'          => esc_html__( 'Validation successful.', EIM_TEXT_DOMAIN ),
            'file_ready'                  => esc_html__( 'File ready. Total media rows:', EIM_TEXT_DOMAIN ),
            'empty_csv'                   => esc_html__( 'The CSV file is empty.', EIM_TEXT_DOMAIN ),
            'server_error'                => esc_html__( 'Server communication error.', EIM_TEXT_DOMAIN ),
            'permission_error'            => esc_html__( 'You do not have permission to run this import.', EIM_TEXT_DOMAIN ),
            'stopping_process'            => esc_html__( 'Stopping the process...', EIM_TEXT_DOMAIN ),
            'invalid_response'            => esc_html__( 'Invalid response from server.', EIM_TEXT_DOMAIN ),
            'batch_failed'                => esc_html__( 'Batch failed:', EIM_TEXT_DOMAIN ),
            'network_retrying'            => esc_html__( 'Network error. Retrying...', EIM_TEXT_DOMAIN ),
            'network_stopped'             => esc_html__( 'Network error. Import stopped after repeated failures.', EIM_TEXT_DOMAIN ),
            'process_complete'            => esc_html__( 'Import completed.', EIM_TEXT_DOMAIN ),
            'process_stopped'             => esc_html__( 'Import stopped by user.', EIM_TEXT_DOMAIN ),
            'processing_batch'            => esc_html__( 'Processing media rows...', EIM_TEXT_DOMAIN ),
            'batch_summary'               => esc_html__( 'Batch summary', EIM_TEXT_DOMAIN ),
            'log_empty'                   => esc_html__( 'Log is empty. Nothing to download.', EIM_TEXT_DOMAIN ),
            'log_status_info'             => esc_html__( 'Info', EIM_TEXT_DOMAIN ),
            'log_status_warning'          => esc_html__( 'Warning', EIM_TEXT_DOMAIN ),
            'log_status_error'            => esc_html__( 'Error', EIM_TEXT_DOMAIN ),
            'log_status_skipped'          => esc_html__( 'Skipped', EIM_TEXT_DOMAIN ),
            'log_status_imported'         => esc_html__( 'Imported', EIM_TEXT_DOMAIN ),
            'log_status_finished'         => esc_html__( 'Done', EIM_TEXT_DOMAIN ),
            'preview_title'               => esc_html__( 'CSV Preview', EIM_TEXT_DOMAIN ),
            'preview_description'         => esc_html__( 'Review the file before starting the import.', EIM_TEXT_DOMAIN ),
            'preview_not_available'       => esc_html__( 'Preview data is not available for this file.', EIM_TEXT_DOMAIN ),
            'preview_total_rows'          => esc_html__( 'Media rows', EIM_TEXT_DOMAIN ),
            'preview_header_count'        => esc_html__( 'Columns detected', EIM_TEXT_DOMAIN ),
            'preview_delimiter'           => esc_html__( 'Delimiter', EIM_TEXT_DOMAIN ),
            'preview_recognized_columns'  => esc_html__( 'Recognized columns', EIM_TEXT_DOMAIN ),
            'preview_sample_rows'         => esc_html__( 'Sample rows', EIM_TEXT_DOMAIN ),
            'preview_warnings'            => esc_html__( 'Warnings', EIM_TEXT_DOMAIN ),
            'preview_rows_with_url'       => esc_html__( 'Rows with Absolute URL', EIM_TEXT_DOMAIN ),
            'preview_rows_with_relative'  => esc_html__( 'Rows with Relative Path', EIM_TEXT_DOMAIN ),
            'preview_rows_with_both'      => esc_html__( 'Rows with both', EIM_TEXT_DOMAIN ),
            'preview_rows_missing_source' => esc_html__( 'Rows missing source', EIM_TEXT_DOMAIN ),
            'preview_recommended_mode'    => esc_html__( 'Suggested source mode', EIM_TEXT_DOMAIN ),
            'preview_mode_remote'         => esc_html__( 'Remote URL', EIM_TEXT_DOMAIN ),
            'preview_mode_local'          => esc_html__( 'Local path', EIM_TEXT_DOMAIN ),
            'preview_mode_mixed'          => esc_html__( 'Mixed', EIM_TEXT_DOMAIN ),
            'preview_mode_unknown'        => esc_html__( 'Unknown', EIM_TEXT_DOMAIN ),
            'preview_column_row'          => esc_html__( 'Row', EIM_TEXT_DOMAIN ),
            'preview_column_source'       => esc_html__( 'Absolute URL', EIM_TEXT_DOMAIN ),
            'preview_column_relative'     => esc_html__( 'Relative Path', EIM_TEXT_DOMAIN ),
            'preview_column_title'        => esc_html__( 'Title', EIM_TEXT_DOMAIN ),
            'preview_column_alt'          => esc_html__( 'Alt Text', EIM_TEXT_DOMAIN ),
            'preview_empty_value'         => esc_html__( 'Not provided', EIM_TEXT_DOMAIN ),
            'summary_title'               => esc_html__( 'Import Summary', EIM_TEXT_DOMAIN ),
            'summary_processed'           => esc_html__( 'Processed', EIM_TEXT_DOMAIN ),
            'summary_imported'            => esc_html__( 'Imported', EIM_TEXT_DOMAIN ),
            'summary_skipped'             => esc_html__( 'Skipped', EIM_TEXT_DOMAIN ),
            'summary_errors'              => esc_html__( 'Errors', EIM_TEXT_DOMAIN ),
        ];
    }

    private function get_import_script_i18n_defaults() {
        return [
            'select_csv'                  => 'Please select a CSV file.',
            'validating'                  => 'Validating file...',
            'validation_failed'           => 'Validation failed:',
            'validation_success'          => 'Validation successful.',
            'file_ready'                  => 'File ready. Total media rows:',
            'empty_csv'                   => 'The CSV file is empty.',
            'server_error'                => 'Server communication error.',
            'permission_error'            => 'You do not have permission to run this import.',
            'stopping_process'            => 'Stopping the process...',
            'invalid_response'            => 'Invalid response from server.',
            'batch_failed'                => 'Batch failed:',
            'network_retrying'            => 'Network error. Retrying...',
            'network_stopped'             => 'Network error. Import stopped after repeated failures.',
            'process_complete'            => 'Import completed.',
            'process_stopped'             => 'Import stopped by user.',
            'processing_batch'            => 'Processing media rows...',
            'batch_summary'               => 'Batch summary',
            'log_empty'                   => 'Log is empty. Nothing to download.',
            'log_status_info'             => 'Info',
            'log_status_warning'          => 'Warning',
            'log_status_error'            => 'Error',
            'log_status_skipped'          => 'Skipped',
            'log_status_imported'         => 'Imported',
            'log_status_finished'         => 'Done',
            'preview_title'               => 'CSV Preview',
            'preview_description'         => 'Review the file before starting the import.',
            'preview_not_available'       => 'Preview data is not available for this file.',
            'preview_total_rows'          => 'Media rows',
            'preview_header_count'        => 'Columns detected',
            'preview_delimiter'           => 'Delimiter',
            'preview_recognized_columns'  => 'Recognized columns',
            'preview_sample_rows'         => 'Sample rows',
            'preview_warnings'            => 'Warnings',
            'preview_rows_with_url'       => 'Rows with Absolute URL',
            'preview_rows_with_relative'  => 'Rows with Relative Path',
            'preview_rows_with_both'      => 'Rows with both',
            'preview_rows_missing_source' => 'Rows missing source',
            'preview_recommended_mode'    => 'Suggested source mode',
            'preview_mode_remote'         => 'Remote URL',
            'preview_mode_local'          => 'Local path',
            'preview_mode_mixed'          => 'Mixed',
            'preview_mode_unknown'        => 'Unknown',
            'preview_column_row'          => 'Row',
            'preview_column_source'       => 'Absolute URL',
            'preview_column_relative'     => 'Relative Path',
            'preview_column_title'        => 'Title',
            'preview_column_alt'          => 'Alt Text',
            'preview_empty_value'         => 'Not provided',
            'summary_title'               => 'Import Summary',
            'summary_processed'           => 'Processed',
            'summary_imported'            => 'Imported',
            'summary_skipped'             => 'Skipped',
            'summary_errors'              => 'Errors',
        ];
    }

    private function render_page_header( $context ) {
        ?>
        <div class="eim-main-banner">
            <div class="eim-banner-content">
                <div class="eim-banner-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="8 12 12 16 16 12"></polyline><line x1="12" y1="8" x2="12" y2="16"></line></svg>
                </div>
                <div class="eim-banner-text">
                    <h3><?php esc_html_e( 'Export/Import Media', EIM_TEXT_DOMAIN ); ?></h3>
                    <p><?php esc_html_e( 'Your complete solution to import and export the media library.', EIM_TEXT_DOMAIN ); ?></p>
                </div>
            </div>
            <div class="eim-banner-actions">
                <?php if ( ! empty( $context['documentation_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $context['documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="eim-banner-link"><?php esc_html_e( 'Documentation', EIM_TEXT_DOMAIN ); ?></a>
                <?php endif; ?>
                <?php if ( ! empty( $context['support_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $context['support_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="eim-banner-link"><?php esc_html_e( 'Support forum', EIM_TEXT_DOMAIN ); ?></a>
                <?php endif; ?>
                <?php do_action( 'eim_admin_banner_actions', $context ); ?>
            </div>
        </div>
        <?php
    }

    private function render_review_notice( $context ) {
        if ( get_user_meta( get_current_user_id(), 'eim_review_notice_dismissed', true ) || empty( $context['review_url'] ) ) {
            return;
        }
        ?>
        <div class="notice notice-info is-dismissible eim-review-notice-top" style="margin-bottom: 20px;">
            <p>
                <?php
                printf(
                    wp_kses(
                        __( 'You are using Export/Import Media! If you find it useful, please consider <a href="%s" target="_blank" rel="noopener noreferrer">leaving a review</a>. It really helps!', EIM_TEXT_DOMAIN ),
                        [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
                    ),
                    esc_url( $context['review_url'] )
                );
                ?>
            </p>
        </div>
        <?php
    }

    private function render_export_section( $context ) {
        ?>
        <div id="eim-export-section" class="eim-card">
            <h2><?php esc_html_e( '1. Export Media Library', EIM_TEXT_DOMAIN ); ?></h2>
            <p><?php esc_html_e( 'Generate a CSV file with the information of all media files in your media library.', EIM_TEXT_DOMAIN ); ?></p>

            <form method="post" action="<?php echo esc_url( $context['export_action_url'] ); ?>">
                <?php wp_nonce_field( 'eim_export_action', 'eim_export_nonce' ); ?>

                <p>
                    <label><strong><?php esc_html_e( 'Date Range (Optional):', EIM_TEXT_DOMAIN ); ?></strong></label><br>
                    <input type="date" name="eim_start_date" id="eim_start_date" />
                    <?php esc_html_e( 'to', EIM_TEXT_DOMAIN ); ?>
                    <input type="date" name="eim_end_date" id="eim_end_date" />
                </p>

                    <p>
                        <label for="eim_media_type"><strong><?php esc_html_e( 'Media Type:', EIM_TEXT_DOMAIN ); ?></strong></label><br>
                        <select name="eim_media_type" id="eim_media_type" style="min-width: 200px;">
                            <?php echo $this->build_select_options( $context['export_media_types'], isset( $context['export_defaults']['media_type'] ) ? $context['export_defaults']['media_type'] : 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>
                    </p>

                    <p>
                        <label for="eim_attachment_filter"><strong><?php esc_html_e( 'Filter by Attachment:', EIM_TEXT_DOMAIN ); ?></strong></label><br>
                        <select name="eim_attachment_filter" id="eim_attachment_filter" style="min-width: 200px;">
                            <?php echo $this->build_select_options( $context['attachment_filters'], isset( $context['export_defaults']['attachment_filter'] ) ? $context['export_defaults']['attachment_filter'] : 'all' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>
                    </p>

                <?php do_action( 'eim_export_form_fields_after', $context ); ?>

                <?php submit_button( esc_html__( 'Export to CSV', EIM_TEXT_DOMAIN ), 'secondary', 'eim_export_csv', true, [ 'id' => 'eim_export_csv' ] ); ?>
            </form>
        </div>
        <?php
    }

    private function render_import_section( $context ) {
        ?>
        <div id="eim-import-section" class="eim-card">
            <h2><?php esc_html_e( '2. Import from CSV', EIM_TEXT_DOMAIN ); ?></h2>
            <p><?php esc_html_e( 'Upload a media CSV. The plugin will validate it first, show a preview, and then import it in batches.', EIM_TEXT_DOMAIN ); ?></p>

            <p style="margin-top: 0;">
                <a href="<?php echo esc_url( $context['sample_url'] ); ?>" class="button button-secondary">
                    <?php esc_html_e( 'Download sample CSV', EIM_TEXT_DOMAIN ); ?>
                </a>
            </p>

            <form id="eim-import-form" method="post" enctype="multipart/form-data">
                <div id="eim-drop-zone" class="eim-drop-zone">
                    <div id="eim-drop-content-default">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        <p><?php esc_html_e( 'Drag and drop a CSV file here or click to upload', EIM_TEXT_DOMAIN ); ?></p>
                    </div>

                    <div id="eim-drop-content-success" style="display:none;">
                        <div class="eim-file-card">
                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#27ae60" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span id="eim-file-name-display"></span>
                            <span id="eim-remove-file" title="<?php esc_attr_e( 'Remove file', EIM_TEXT_DOMAIN ); ?>">&times;</span>
                        </div>
                    </div>
                </div>

                <input type="file" name="eim_csv" id="eim_csv" accept=".csv" />

                <div class="eim-inline-options">
                    <div>
                        <label for="batch_size"><strong><?php esc_html_e( 'Rows per batch:', EIM_TEXT_DOMAIN ); ?></strong></label><br>
                        <select name="batch_size" id="batch_size">
                            <?php foreach ( $context['import_batch_sizes'] as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $value, (string) eim_get_setting( 'import.default_batch_size', '25' ) ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php foreach ( $context['import_options'] as $option ) : ?>
                    <?php $this->render_import_option( $option ); ?>
                <?php endforeach; ?>

                <?php do_action( 'eim_import_form_fields_after', $context ); ?>

                <button type="button" class="button button-primary" id="eim-start-button"><?php esc_html_e( 'Start Import', EIM_TEXT_DOMAIN ); ?></button>
                <button type="button" class="button" id="eim-stop-button" style="display:none;"><?php esc_html_e( 'Stop Process', EIM_TEXT_DOMAIN ); ?></button>
            </form>
        </div>
        <?php
    }

    private function render_import_option( $option ) {
        $id          = isset( $option['id'] ) ? sanitize_key( $option['id'] ) : '';
        $label       = isset( $option['label'] ) ? (string) $option['label'] : '';
        $description = isset( $option['description'] ) ? (string) $option['description'] : '';
        $checked     = ! empty( $option['checked'] );
        $feature     = isset( $option['feature'] ) ? sanitize_key( (string) $option['feature'] ) : '';
        $is_locked   = ! empty( $option['pro_only'] ) || ( $feature && ! eim_is_feature_enabled( $feature ) && EIM_Config::is_pro_feature( $feature ) );

        if ( '' === $id || '' === $label ) {
            return;
        }
        ?>
        <p class="eim-option-row<?php echo $is_locked ? ' is-locked' : ''; ?>">
            <label>
                <input type="checkbox" name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $checked ); ?> <?php disabled( $is_locked ); ?> />
                <strong><?php echo esc_html( $label ); ?></strong>
            </label>
            <?php if ( '' !== $description ) : ?>
                <br>
                <small class="eim-option-help"><?php echo esc_html( $description ); ?></small>
            <?php endif; ?>
        </p>
        <?php
    }

    private function render_import_preview_panel() {
        ?>
        <div id="eim-preview-panel" class="eim-card" style="display:none;">
            <h3><?php esc_html_e( 'CSV Preview', EIM_TEXT_DOMAIN ); ?></h3>
            <p><?php esc_html_e( 'Review the file before starting the import.', EIM_TEXT_DOMAIN ); ?></p>
            <div id="eim-preview-content"></div>
        </div>
        <?php
    }

    private function render_progress_panel() {
        ?>
        <div id="eimp-progress-container" class="eim-card" style="display:none; margin-top: 20px;">
            <h3><?php esc_html_e( 'Import Progress:', EIM_TEXT_DOMAIN ); ?></h3>

            <div style="background: #eee; border: 1px solid #ccc; padding: 5px; border-radius: 5px;">
                <div id="eimp-progress-bar" style="width: 0%; height: 24px; background-color: #0073aa; text-align: center; line-height: 24px; color: white; font-weight: bold; transition: width 0.1s ease; border-radius: 3px;">0%</div>
            </div>

            <div class="eim-warning-message"><?php esc_html_e( 'Keep this tab open while the current import is running.', EIM_TEXT_DOMAIN ); ?></div>

            <div id="eim-import-result-summary" style="display:none;"></div>

            <div id="eimp-log" style="background: #fafafa; border: 1px solid #ccc; border-top: none; padding: 10px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 13px; margin-top: 5px;"></div>

            <div style="margin-top: 15px; text-align: right;">
                <button type="button" class="button" id="eim-download-log" style="display:none;"><?php esc_html_e( 'Download Log (.txt)', EIM_TEXT_DOMAIN ); ?></button>
            </div>
        </div>
        <?php
    }

    private function render_footer_review( $context ) {
        if ( empty( $context['review_url'] ) ) {
            return;
        }

        $stars_svg = '<svg width="18" height="18" viewBox="0 0 24 24"><path fill="#ffb900" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
        $allowed   = [
            'a'    => [ 'href' => [], 'target' => [], 'rel' => [], 'aria-label' => [] ],
            'svg'  => [ 'width' => [], 'height' => [], 'viewBox' => [] ],
            'path' => [ 'fill' => [], 'd' => [] ],
        ];
        ?>
        <div class="eim-footer-review">
            <?php
            printf(
                wp_kses(
                    __( 'If you like our plugin, a %1$s%3$s%2$s rating will help us a lot, thanks in advance!', EIM_TEXT_DOMAIN ),
                    $allowed
                ),
                '<a href="' . esc_url( $context['review_url'] ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Rate 5 stars', EIM_TEXT_DOMAIN ) . '">',
                '</a>',
                str_repeat( $stars_svg, 5 )
            );
            ?>
        </div>
        <?php
    }

    private function build_select_options( $options, $selected_value ) {
        $html = '';

        foreach ( (array) $options as $value => $definition ) {
            $label = $definition;

            if ( is_array( $definition ) ) {
                if ( ! empty( $definition['requires_class'] ) && ! class_exists( $definition['requires_class'] ) ) {
                    continue;
                }

                if ( ! empty( $definition['feature'] ) && ! eim_is_feature_enabled( $definition['feature'] ) ) {
                    continue;
                }

                $label = isset( $definition['label'] ) ? $definition['label'] : '';
            }

            $html .= sprintf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $value ),
                selected( (string) $value, (string) $selected_value, false ),
                esc_html( (string) $label )
            );
        }

        return $html;
    }
}

