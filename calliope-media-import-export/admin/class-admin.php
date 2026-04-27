<?php
/**
 * Admin UI and admin-only behavior for the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIM_Admin {

    const LOCKED_PRO_EXPORT_PROFILES_PAGE = 'eim-pro-locked-export-profiles';
    const LOCKED_PRO_IMPORT_PROFILES_PAGE = 'eim-pro-locked-import-profiles';
    const LOCKED_PRO_HISTORY_PAGE         = 'eim-pro-locked-history';
    const LOCKED_PRO_SCHEDULES_PAGE       = 'eim-pro-locked-scheduled-jobs';
    const LOCKED_PRO_TEMPLATES_PAGE       = 'eim-pro-locked-templates';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'current_screen', [ $this, 'maybe_hide_admin_notices' ], 0 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'plugin_action_links_' . EIM_BASENAME, [ $this, 'add_settings_link' ] );
        add_action( 'admin_post_eim_download_sample_csv', [ $this, 'download_sample_csv' ] );
    }

    public function maybe_hide_admin_notices( $screen ) {
        if ( ! is_object( $screen ) ) {
            return;
        }

        $is_target = ( isset( $screen->id ) && in_array( $screen->id, $this->get_main_screen_ids(), true ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug does not change state.
        if ( ! $is_target && isset( $_GET['page'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug does not change state.
            $page      = sanitize_text_field( wp_unslash( $_GET['page'] ) );
            $is_target = ( EIM_ADMIN_PAGE_SLUG === $page );
        }

        if ( $is_target ) {
            remove_all_actions( 'admin_notices' );
            remove_all_actions( 'all_admin_notices' );
            remove_all_actions( 'network_admin_notices' );
            remove_all_actions( 'user_admin_notices' );
        }
    }

    public function register_menu() {
        add_menu_page(
            esc_html__( 'Export/Import Media', 'calliope-media-import-export' ),
            esc_html__( 'Export/Import Media', 'calliope-media-import-export' ),
            eim_get_required_capability(),
            EIM_ADMIN_PAGE_SLUG,
            [ $this, 'render_admin_page' ],
            'dashicons-images-alt2',
            58
        );
    }

    public function render_locked_menu_styles() {
        if ( function_exists( 'eim_is_pro_active' ) && eim_is_pro_active() ) {
            return;
        }
        ?>
        <style>
            #adminmenu .wp-submenu a[href*="page=eim-pro-locked-"] {
                color: #9da3ae !important;
            }

            #adminmenu .wp-submenu a[href*="page=eim-pro-locked-"]::after {
                content: "\f160";
                font-family: dashicons;
                font-size: 14px;
                margin-left: 6px;
                vertical-align: middle;
            }
        </style>
        <?php
    }

    public function enqueue_assets( $hook ) {
        if ( ! $this->is_admin_page_request( $hook ) ) {
            return;
        }

        $style_version  = file_exists( EIM_PATH . 'assets/css/style.css' ) ? (string) filemtime( EIM_PATH . 'assets/css/style.css' ) : EIM_VERSION;
        $script_version = file_exists( EIM_PATH . 'assets/js/importer.js' ) ? (string) filemtime( EIM_PATH . 'assets/js/importer.js' ) : EIM_VERSION;

        wp_enqueue_style(
            'eim-custom-styles',
            EIM_URL . 'assets/css/style.css',
            [],
            $style_version
        );

        if ( $this->is_locked_pro_page_request( $hook ) ) {
            return;
        }

        wp_enqueue_script(
            'eim-importer-js',
            EIM_URL . 'assets/js/importer.js',
            [ 'jquery' ],
            $script_version,
            true
        );

        $script_data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'eim_import_nonce' ),
            'i18n'     => $this->get_import_script_i18n(),
            'fallback_i18n' => $this->get_import_script_i18n_defaults(),
            'config'   => [
                'default_batch' => eim_get_setting( 'import.default_batch_size', '25' ),
            ],
        ];

        wp_localize_script(
            'eim-importer-js',
            'eim_ajax',
            apply_filters( 'eim_admin_script_data', $script_data )
        );

    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( $this->get_admin_page_url( EIM_ADMIN_PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'calliope-media-import-export' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function render_admin_page() {
        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'calliope-media-import-export' ) );
        }

        $context = $this->get_page_context();

        do_action( 'eim_admin_before_page', $context );
        ?>
        <div class="wrap eim-admin-shell">
            <?php $this->render_page_header( $context ); ?>

            <div class="eim-admin-columns">
                <div class="eim-admin-main">
                    <?php $this->render_export_section( $context ); ?>
                    <?php do_action( 'eim_admin_after_export_section', $context ); ?>
                    <?php if ( empty( $context['is_pro_active'] ) ) : ?>
                        <?php $this->render_pro_spotlight_banner( $context ); ?>
                    <?php endif; ?>
                    <?php $this->render_import_section( $context ); ?>
                    <?php do_action( 'eim_admin_after_import_section', $context ); ?>
                    <?php $this->render_import_preview_panel(); ?>
                    <?php $this->render_progress_panel(); ?>
                    <?php do_action( 'eim_admin_after_primary_flow', $context ); ?>
                    <?php if ( empty( $context['is_pro_active'] ) ) : ?>
                        <?php $this->render_pro_showcase_section( $context ); ?>
                    <?php endif; ?>
                    <?php $this->render_footer_review( $context ); ?>
                </div>
                <?php do_action( 'eim_admin_sidebar', $context ); ?>
            </div>
        </div>
        <?php
        do_action( 'eim_admin_after_page', $context );
    }

    public function download_sample_csv() {
        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'calliope-media-import-export' ) );
        }

        check_admin_referer( 'eim_download_sample_csv' );

        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Allow longer CSV downloads when the environment permits it.
            @set_time_limit( 0 );
        }
        while ( ob_get_level() > 0 ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Clear previous output before streaming CSV headers.
            @ob_end_clean();
        }

        nocache_headers();

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="eim-sample.csv"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a CSV directly to the browser output.
        $output  = fopen( 'php://output', 'w' );
        $headers = class_exists( 'EIM_Exporter' ) && method_exists( 'EIM_Exporter', 'get_canonical_csv_headers' )
            ? EIM_Exporter::get_canonical_csv_headers()
            : [ 'ID', 'Absolute URL', 'Relative Path', 'File', 'Alt Text', 'Caption', 'Description', 'Title' ];

        $sample_image_url = defined( 'EIM_URL' ) ? EIM_URL . 'assets/images/eim-sample.png' : '';

        fputcsv( $output, $headers );
        fputcsv(
            $output,
            [
                '',
                $sample_image_url,
                '',
                'eim-sample.png',
                'Sample alt text',
                'Sample caption',
                'Sample description',
                'Sample title',
            ]
        );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the browser output stream after writing the CSV.
        fclose( $output );
        exit;
    }

    private function is_admin_page_request( $hook ) {
        $is_target = in_array( $hook, $this->get_main_screen_ids(), true );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug does not change state.
        if ( ! $is_target && isset( $_GET['page'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug does not change state.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug for menu routing only.
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

            if ( EIM_ADMIN_PAGE_SLUG === $page ) {
                $is_target = true;
            }
        }

        return $is_target;
    }

    private function get_main_screen_ids() {
        return [
            'media_page_' . EIM_ADMIN_PAGE_SLUG,
            'toplevel_page_' . EIM_ADMIN_PAGE_SLUG,
        ];
    }

    private function get_admin_page_url( $page, $args = [] ) {
        $args = array_merge(
            [
                'page' => sanitize_key( (string) $page ),
            ],
            is_array( $args ) ? $args : []
        );

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private function is_locked_pro_page_request( $hook ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug for menu routing only.
        if ( ! isset( $_GET['page'] ) ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page slug does not change state.
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

        return isset( $this->get_locked_pro_pages()[ $page ] );
    }

    private function get_locked_pro_pages() {
        return [
            self::LOCKED_PRO_EXPORT_PROFILES_PAGE => __( 'Export Profiles', 'calliope-media-import-export' ),
            self::LOCKED_PRO_IMPORT_PROFILES_PAGE => __( 'Import Profiles', 'calliope-media-import-export' ),
            self::LOCKED_PRO_HISTORY_PAGE         => __( 'History', 'calliope-media-import-export' ),
            self::LOCKED_PRO_SCHEDULES_PAGE       => __( 'Scheduled Jobs', 'calliope-media-import-export' ),
            self::LOCKED_PRO_TEMPLATES_PAGE       => __( 'Export Presets', 'calliope-media-import-export' ),
        ];
    }

    private function get_page_context() {
        $urls = eim_get_setting( 'urls', [] );

        return [
            'version'             => EIM_VERSION,
            'documentation_url'   => isset( $urls['documentation'] ) ? $urls['documentation'] : '',
            'support_url'         => isset( $urls['support'] ) ? $urls['support'] : '',
            'review_url'          => isset( $urls['reviews'] ) ? $urls['reviews'] : '',
            'pro_url'             => isset( $urls['pro'] ) ? $urls['pro'] : '',
            'is_pro_active'       => function_exists( 'eim_is_pro_active' ) ? eim_is_pro_active() : false,
            'sample_url'          => wp_nonce_url( admin_url( 'admin-post.php?action=eim_download_sample_csv' ), 'eim_download_sample_csv' ),
            'export_action_url'   => admin_url( 'admin-post.php?action=eim_export_csv' ),
            'export_defaults'     => EIM_Config::get_export_defaults(),
            'export_media_types'  => EIM_Config::get_export_media_type_options(),
            'attachment_filters'  => EIM_Config::get_export_attachment_filter_options(),
            'import_batch_sizes'  => EIM_Config::get_import_batch_size_options(),
            'import_options'      => EIM_Config::get_import_option_definitions(),
        ];
    }

    public function render_locked_pro_page() {
        if ( ! eim_current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'calliope-media-import-export' ) );
        }

        $context      = $this->get_page_context();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading current admin page slug for locked-page rendering only.
        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $pages        = $this->get_locked_pro_pages();
        $page_title   = isset( $pages[ $current_page ] ) ? $pages[ $current_page ] : __( 'Export/Import Media Pro', 'calliope-media-import-export' );

        ?>
        <div class="wrap eim-admin-shell">
            <?php $this->render_page_header( $context ); ?>

            <div class="eim-card eim-pro-locked-page-intro">
                <span class="eim-pro-lock-pill"><?php esc_html_e( 'Pro', 'calliope-media-import-export' ); ?></span>
                <h2><?php echo esc_html( $page_title ); ?></h2>
                <p><?php esc_html_e( 'This premium workspace is available in Export/Import Media Pro.', 'calliope-media-import-export' ); ?></p>
                <p><?php esc_html_e( 'The free plugin stays focused on straightforward CSV export/import with preview and duplicate prevention. Pro adds reusable profiles, background workflows, and controlled rules for matching and updating existing media.', 'calliope-media-import-export' ); ?></p>
                <a href="<?php echo esc_url( $context['pro_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'Buy Pro Version', 'calliope-media-import-export' ); ?>
                </a>
            </div>

            <?php $this->render_locked_pro_export_panel( $context, true ); ?>
            <?php $this->render_locked_pro_import_panel( $context, true ); ?>
        </div>
        <?php
    }

    private function get_import_script_i18n() {
        return [
            'select_csv'                  => esc_html__( 'Please select a CSV file.', 'calliope-media-import-export' ),
            'validating'                  => esc_html__( 'Validating file...', 'calliope-media-import-export' ),
            'validation_failed'           => esc_html__( 'Validation failed:', 'calliope-media-import-export' ),
            'validation_success'          => esc_html__( 'Validation successful.', 'calliope-media-import-export' ),
            'file_ready'                  => esc_html__( 'File ready. Total media rows:', 'calliope-media-import-export' ),
            'empty_csv'                   => esc_html__( 'The CSV file is empty.', 'calliope-media-import-export' ),
            'server_error'                => esc_html__( 'Server communication error.', 'calliope-media-import-export' ),
            'permission_error'            => esc_html__( 'You do not have permission to run this import.', 'calliope-media-import-export' ),
            'stopping_process'            => esc_html__( 'Stopping the process...', 'calliope-media-import-export' ),
            'invalid_response'            => esc_html__( 'Invalid response from server.', 'calliope-media-import-export' ),
            'batch_failed'                => esc_html__( 'Batch failed:', 'calliope-media-import-export' ),
            'network_retrying'            => esc_html__( 'Network error. Retrying...', 'calliope-media-import-export' ),
            'network_stopped'             => esc_html__( 'Network error. Import stopped after repeated failures.', 'calliope-media-import-export' ),
            'process_complete'            => esc_html__( 'Import completed.', 'calliope-media-import-export' ),
            'process_stopped'             => esc_html__( 'Import stopped by user.', 'calliope-media-import-export' ),
            'processing_batch'            => esc_html__( 'Processing media rows...', 'calliope-media-import-export' ),
            'batch_summary'               => esc_html__( 'Batch summary', 'calliope-media-import-export' ),
            'log_empty'                   => esc_html__( 'Log is empty. Nothing to download.', 'calliope-media-import-export' ),
            'log_status_info'             => esc_html__( 'Info', 'calliope-media-import-export' ),
            'log_status_warning'          => esc_html__( 'Warning', 'calliope-media-import-export' ),
            'log_status_error'            => esc_html__( 'Error', 'calliope-media-import-export' ),
            'log_status_skipped'          => esc_html__( 'Skipped', 'calliope-media-import-export' ),
            'log_status_imported'         => esc_html__( 'Imported', 'calliope-media-import-export' ),
            'log_status_finished'         => esc_html__( 'Done', 'calliope-media-import-export' ),
            'preview_title'               => esc_html__( 'CSV Preview', 'calliope-media-import-export' ),
            'preview_description'         => esc_html__( 'Review the file before starting the import.', 'calliope-media-import-export' ),
            'preview_not_available'       => esc_html__( 'Preview data is not available for this file.', 'calliope-media-import-export' ),
            'preview_total_rows'          => esc_html__( 'Media rows', 'calliope-media-import-export' ),
            'preview_header_count'        => esc_html__( 'Columns detected', 'calliope-media-import-export' ),
            'preview_delimiter'           => esc_html__( 'Delimiter', 'calliope-media-import-export' ),
            'preview_recognized_columns'  => esc_html__( 'Recognized columns', 'calliope-media-import-export' ),
            'preview_sample_rows'         => esc_html__( 'Sample rows', 'calliope-media-import-export' ),
            'preview_warnings'            => esc_html__( 'Warnings', 'calliope-media-import-export' ),
            'preview_rows_with_url'       => esc_html__( 'Rows with Absolute URL', 'calliope-media-import-export' ),
            'preview_rows_with_relative'  => esc_html__( 'Rows with Relative Path', 'calliope-media-import-export' ),
            'preview_rows_with_both'      => esc_html__( 'Rows with both', 'calliope-media-import-export' ),
            'preview_rows_missing_source' => esc_html__( 'Rows missing source', 'calliope-media-import-export' ),
            'preview_recommended_mode'    => esc_html__( 'Suggested source mode', 'calliope-media-import-export' ),
            'preview_mode_remote'         => esc_html__( 'Remote URL', 'calliope-media-import-export' ),
            'preview_mode_local'          => esc_html__( 'Local path', 'calliope-media-import-export' ),
            'preview_mode_mixed'          => esc_html__( 'Mixed', 'calliope-media-import-export' ),
            'preview_mode_unknown'        => esc_html__( 'Unknown', 'calliope-media-import-export' ),
            'preview_column_row'          => esc_html__( 'Row', 'calliope-media-import-export' ),
            'preview_column_source'       => esc_html__( 'Absolute URL', 'calliope-media-import-export' ),
            'preview_column_relative'     => esc_html__( 'Relative Path', 'calliope-media-import-export' ),
            'preview_column_title'        => esc_html__( 'Title', 'calliope-media-import-export' ),
            'preview_column_alt'          => esc_html__( 'Alt Text', 'calliope-media-import-export' ),
            'preview_empty_value'         => esc_html__( 'Not provided', 'calliope-media-import-export' ),
            'summary_title'               => esc_html__( 'Import Summary', 'calliope-media-import-export' ),
            'summary_processed'           => esc_html__( 'Processed', 'calliope-media-import-export' ),
            'summary_imported'            => esc_html__( 'Imported', 'calliope-media-import-export' ),
            'summary_skipped'             => esc_html__( 'Skipped', 'calliope-media-import-export' ),
            'summary_errors'              => esc_html__( 'Errors', 'calliope-media-import-export' ),
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
        $banner_classes = 'eim-main-banner';
        if ( ! empty( $context['is_pro_active'] ) ) {
            $banner_classes .= ' is-pro-active';
        }
        ?>
        <div class="<?php echo esc_attr( $banner_classes ); ?>">
            <div class="eim-banner-main">
                <div class="eim-banner-topbar">
                    <?php if ( empty( $context['is_pro_active'] ) ) : ?>
                        <span class="eim-banner-kicker"><?php esc_html_e( 'Free plugin', 'calliope-media-import-export' ); ?></span>
                    <?php endif; ?>
                    <div class="eim-banner-actions">
                        <?php if ( ! empty( $context['documentation_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $context['documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="eim-banner-link"><?php esc_html_e( 'Documentation', 'calliope-media-import-export' ); ?></a>
                        <?php endif; ?>
                        <?php if ( ! empty( $context['support_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $context['support_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="eim-banner-link"><?php esc_html_e( 'Support forum', 'calliope-media-import-export' ); ?></a>
                        <?php endif; ?>
                        <?php do_action( 'eim_admin_banner_actions', $context ); ?>
                    </div>
                </div>
                <div class="eim-banner-content">
                    <div class="eim-banner-logo" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="8 12 12 16 16 12"></polyline><line x1="12" y1="8" x2="12" y2="16"></line></svg>
                    </div>
                    <div class="eim-banner-text">
                        <h1><?php esc_html_e( 'Export/Import Media', 'calliope-media-import-export' ); ?></h1>
                        <p><?php esc_html_e( 'A clean CSV workflow for exporting, previewing, and importing media while helping you avoid duplicate attachments.', 'calliope-media-import-export' ); ?></p>
                    </div>
                </div>
                <div class="eim-banner-highlights" aria-label="<?php esc_attr_e( 'Free plugin highlights', 'calliope-media-import-export' ); ?>">
                    <span class="eim-banner-chip"><?php esc_html_e( 'Preview before import', 'calliope-media-import-export' ); ?></span>
                    <span class="eim-banner-chip"><?php esc_html_e( 'Duplicate-safe free workflow', 'calliope-media-import-export' ); ?></span>
                    <span class="eim-banner-chip"><?php esc_html_e( 'Core metadata columns', 'calliope-media-import-export' ); ?></span>
                    <span class="eim-banner-chip"><?php esc_html_e( 'Batch processing', 'calliope-media-import-export' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_export_section( $context ) {
        ?>
        <div id="eim-export-section" class="eim-card">
            <div class="eim-section-heading">
                <span class="eim-step-pill"><?php esc_html_e( 'Step 1', 'calliope-media-import-export' ); ?></span>
                <h2><?php esc_html_e( 'Export Media Library', 'calliope-media-import-export' ); ?></h2>
                <p><?php esc_html_e( 'Generate a clean CSV with media URLs, relative paths, and core fields like alt text, title, caption, and description.', 'calliope-media-import-export' ); ?></p>
            </div>

            <div class="eim-inline-hints">
                <span><?php esc_html_e( 'Useful for migrations, audits, and content cleanup.', 'calliope-media-import-export' ); ?></span>
                <span><?php esc_html_e( 'Filter by date, media type, or attachment context before exporting.', 'calliope-media-import-export' ); ?></span>
            </div>

            <form method="post" action="<?php echo esc_url( $context['export_action_url'] ); ?>">
                <?php wp_nonce_field( 'eim_export_action', 'eim_export_nonce' ); ?>

                <p>
                    <label><strong><?php esc_html_e( 'Date Range (Optional):', 'calliope-media-import-export' ); ?></strong></label><br>
                    <input type="date" name="eim_start_date" id="eim_start_date" />
                    <?php esc_html_e( 'to', 'calliope-media-import-export' ); ?>
                    <input type="date" name="eim_end_date" id="eim_end_date" />
                </p>

                    <p>
                        <label for="eim_media_type"><strong><?php esc_html_e( 'Media Type:', 'calliope-media-import-export' ); ?></strong></label><br>
                        <select name="eim_media_type" id="eim_media_type" style="min-width: 200px;">
                            <?php echo $this->build_select_options( $context['export_media_types'], isset( $context['export_defaults']['media_type'] ) ? $context['export_defaults']['media_type'] : 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>
                    </p>

                    <p>
                        <label for="eim_attachment_filter"><strong><?php esc_html_e( 'Filter by Attachment:', 'calliope-media-import-export' ); ?></strong></label><br>
                        <select name="eim_attachment_filter" id="eim_attachment_filter" style="min-width: 200px;">
                            <?php echo $this->build_select_options( $context['attachment_filters'], isset( $context['export_defaults']['attachment_filter'] ) ? $context['export_defaults']['attachment_filter'] : 'all' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>
                    </p>

                <?php do_action( 'eim_export_form_fields_after', $context ); ?>

                <?php submit_button( esc_html__( 'Export to CSV', 'calliope-media-import-export' ), 'secondary', 'eim_export_csv', true, [ 'id' => 'eim_export_csv' ] ); ?>
            </form>
        </div>
        <?php
    }

    private function render_import_section( $context ) {
        ?>
        <div id="eim-import-section" class="eim-card">
            <div class="eim-section-heading">
                <span class="eim-step-pill"><?php esc_html_e( 'Step 2', 'calliope-media-import-export' ); ?></span>
                <h2><?php esc_html_e( 'Import from CSV', 'calliope-media-import-export' ); ?></h2>
                <p><?php esc_html_e( 'Upload a CSV, validate it first, review the preview, and import media in batches while the free workflow skips existing matches it detects.', 'calliope-media-import-export' ); ?></p>
            </div>

            <div class="eim-inline-hints">
                <span><?php esc_html_e( 'Best for standard one-off imports into the media library.', 'calliope-media-import-export' ); ?></span>
                <span><?php esc_html_e( 'Existing matches are skipped in free instead of being updated in place.', 'calliope-media-import-export' ); ?></span>
            </div>

            <div class="eim-import-tools">
                <p class="eim-import-tools-action">
                    <a href="<?php echo esc_url( $context['sample_url'] ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Download sample CSV', 'calliope-media-import-export' ); ?>
                    </a>
                </p>
            </div>

            <form id="eim-import-form" method="post" enctype="multipart/form-data">
                <div id="eim-drop-zone" class="eim-drop-zone">
                    <div id="eim-drop-content-default">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        <p><?php esc_html_e( 'Drag and drop a CSV file here or click to upload', 'calliope-media-import-export' ); ?></p>
                    </div>

                    <div id="eim-drop-content-success" style="display:none;">
                        <div class="eim-file-card">
                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#27ae60" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span id="eim-file-name-display"></span>
                            <span id="eim-remove-file" title="<?php esc_attr_e( 'Remove file', 'calliope-media-import-export' ); ?>">&times;</span>
                        </div>
                    </div>
                </div>

                <input type="file" name="eim_csv" id="eim_csv" accept=".csv" />

                <div class="eim-inline-options">
                    <div>
                        <label for="batch_size"><strong><?php esc_html_e( 'Rows per batch:', 'calliope-media-import-export' ); ?></strong></label><br>
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

                <button type="button" class="button button-primary" id="eim-start-button"><?php esc_html_e( 'Start Import', 'calliope-media-import-export' ); ?></button>
                <button type="button" class="button" id="eim-stop-button" style="display:none;"><?php esc_html_e( 'Stop Process', 'calliope-media-import-export' ); ?></button>
            </form>
        </div>
        <?php
    }

    private function render_locked_pro_export_panel( $context, $full_page = false ) {
        if ( ! empty( $context['is_pro_active'] ) || empty( $context['pro_url'] ) ) {
            return;
        }
        ?>
        <div class="eim-card eim-pro-locked-card<?php echo $full_page ? ' is-full-page' : ''; ?>">
            <div class="eim-pro-locked-head">
                <div>
                    <span class="eim-pro-lock-pill"><?php esc_html_e( 'Pro', 'calliope-media-import-export' ); ?></span>
                    <h3><?php esc_html_e( 'Advanced Export Workflows', 'calliope-media-import-export' ); ?></h3>
                    <p><?php esc_html_e( 'Free covers quick CSV exports. Pro adds saved export profiles, richer packages, and optional background runs for repeatable migrations and larger media libraries.', 'calliope-media-import-export' ); ?></p>
                </div>
                <a href="<?php echo esc_url( $context['pro_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'Buy Pro Version', 'calliope-media-import-export' ); ?>
                </a>
            </div>

            <fieldset class="eim-pro-locked-fieldset" disabled>
                <div class="eim-pro-locked-grid">
                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Saved export profile', 'calliope-media-import-export' ); ?></strong></label><br />
                        <select>
                            <option><?php esc_html_e( 'Select an export profile', 'calliope-media-import-export' ); ?></option>
                        </select>
                    </p>

                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Save current export setup as profile', 'calliope-media-import-export' ); ?></strong></label><br />
                        <input type="text" class="regular-text" placeholder="<?php echo esc_attr__( 'Export profile name', 'calliope-media-import-export' ); ?>" />
                    </p>
                </div>

                <div class="eim-pro-locked-grid">
                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Export preset', 'calliope-media-import-export' ); ?></strong></label><br />
                        <select>
                            <option><?php esc_html_e( 'Migration Export', 'calliope-media-import-export' ); ?></option>
                        </select>
                    </p>

                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Format', 'calliope-media-import-export' ); ?></strong></label><br />
                        <select>
                            <option><?php esc_html_e( 'CSV (comma-separated)', 'calliope-media-import-export' ); ?></option>
                        </select>
                    </p>

                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Package', 'calliope-media-import-export' ); ?></strong></label><br />
                        <select>
                            <option><?php esc_html_e( 'CSV only', 'calliope-media-import-export' ); ?></option>
                        </select>
                    </p>
                </div>

                <div class="eim-pro-locked-actions">
                    <button type="button" class="button button-primary" disabled><?php esc_html_e( 'Run Background Export', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'Manage Export Profiles', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'View Background Runs', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'Manage Presets', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'View History', 'calliope-media-import-export' ); ?></button>
                </div>
            </fieldset>
        </div>
        <?php
    }

    private function render_locked_pro_import_panel( $context, $full_page = false ) {
        if ( ! empty( $context['is_pro_active'] ) || empty( $context['pro_url'] ) ) {
            return;
        }
        ?>
        <div class="eim-card eim-pro-locked-card<?php echo $full_page ? ' is-full-page' : ''; ?>">
            <div class="eim-pro-locked-head">
                <div>
                    <span class="eim-pro-lock-pill"><?php esc_html_e( 'Pro', 'calliope-media-import-export' ); ?></span>
                    <h3><?php esc_html_e( 'Advanced Import Workflows', 'calliope-media-import-export' ); ?></h3>
                    <p><?php esc_html_e( 'Free handles one-off CSV imports with preview and duplicate prevention. Pro adds saved workflows for matching existing attachments, refreshing trusted metadata, replacing files, and reusing advanced rules later.', 'calliope-media-import-export' ); ?></p>
                </div>
                <a href="<?php echo esc_url( $context['pro_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'Buy Pro Version', 'calliope-media-import-export' ); ?>
                </a>
            </div>

            <fieldset class="eim-pro-locked-fieldset" disabled>
                <div class="eim-pro-locked-grid">
                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Reusable import profiles', 'calliope-media-import-export' ); ?></strong></label><br />
                        <small><?php esc_html_e( 'Save repeatable remote CSV, server-file, mapping, and background import settings for future runs.', 'calliope-media-import-export' ); ?></small>
                    </p>

                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Match existing media + refresh metadata', 'calliope-media-import-export' ); ?></strong></label><br />
                        <small><?php esc_html_e( 'Find existing attachments by Attachment ID, Source URL, Relative Path, or Filename before deciding whether to skip, update, or replace them.', 'calliope-media-import-export' ); ?></small>
                    </p>

                    <p class="eim-pro-locked-option">
                        <label><strong><?php esc_html_e( 'Selective metadata refresh + file replacement', 'calliope-media-import-export' ); ?></strong></label><br />
                        <small><?php esc_html_e( 'Refresh only the fields you trust, or replace an outdated file while keeping the attachment record intact.', 'calliope-media-import-export' ); ?></small>
                    </p>
                </div>

                <div class="eim-pro-locked-actions">
                    <button type="button" class="button button-primary" disabled><?php esc_html_e( 'Run Background Import', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'Manage Import Profiles', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'View Background Runs', 'calliope-media-import-export' ); ?></button>
                    <button type="button" class="button" disabled><?php esc_html_e( 'View History', 'calliope-media-import-export' ); ?></button>
                </div>
            </fieldset>
        </div>
        <?php
    }

    private function render_pro_spotlight_banner( $context ) {
        if ( empty( $context['pro_url'] ) ) {
            return;
        }
        ?>
        <div class="eim-card eim-pro-spotlight-card">
            <div class="eim-pro-spotlight-copy">
                <span class="eim-pro-eyebrow"><?php esc_html_e( 'Pro add-on', 'calliope-media-import-export' ); ?></span>
                <h2><?php esc_html_e( 'Upgrade when you need to work with existing media, not just import new rows.', 'calliope-media-import-export' ); ?></h2>
                <p><?php esc_html_e( 'The free plugin stays intentionally focused on clean one-off CSV import and export. Pro adds controlled matching against existing attachments, selective metadata refreshes, safer replace-file workflows, and reusable setups for larger libraries.', 'calliope-media-import-export' ); ?></p>
            </div>
            <div class="eim-pro-spotlight-actions">
                <a href="<?php echo esc_url( $context['pro_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary"><?php esc_html_e( 'Explore Pro', 'calliope-media-import-export' ); ?></a>
            </div>
        </div>
        <?php
    }

    private function render_pro_showcase_section( $context ) {
        if ( empty( $context['pro_url'] ) ) {
            return;
        }

        $features = [
            [
                'title'       => __( 'Update existing media metadata', 'calliope-media-import-export' ),
                'description' => __( 'Match existing attachments and refresh trusted fields like alt text, title, caption, and description.', 'calliope-media-import-export' ),
            ],
            [
                'title'       => __( 'Saved profiles', 'calliope-media-import-export' ),
                'description' => __( 'Reuse export and import setups without rebuilding the same workflow every time.', 'calliope-media-import-export' ),
            ],
            [
                'title'       => __( 'Remote or server-side CSV sources', 'calliope-media-import-export' ),
                'description' => __( 'Run workflows from trusted CSV sources without manually uploading a file every time.', 'calliope-media-import-export' ),
            ],
            [
                'title'       => __( 'Replace files safely and review history', 'calliope-media-import-export' ),
                'description' => __( 'Replace outdated media more carefully and keep a record of what happened during heavier runs.', 'calliope-media-import-export' ),
            ],
        ];
        ?>
        <div class="eim-card eim-pro-showcase-card">
            <div class="eim-pro-showcase-heading">
                <div>
                    <span class="eim-pro-eyebrow"><?php esc_html_e( 'Also available in Pro', 'calliope-media-import-export' ); ?></span>
                    <h3><?php esc_html_e( 'Advanced workflows for teams managing larger or messier media libraries', 'calliope-media-import-export' ); ?></h3>
                    <p><?php esc_html_e( 'The free version stays clean on purpose. Pro adds a few stronger tools when you need controlled updates, repeatable workflows, and more operational confidence.', 'calliope-media-import-export' ); ?></p>
                </div>
                <a href="<?php echo esc_url( $context['pro_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'See Pro details', 'calliope-media-import-export' ); ?></a>
            </div>

            <div class="eim-pro-teaser-grid" aria-hidden="true">
                <?php foreach ( $features as $feature ) : ?>
                    <div class="eim-pro-teaser-card">
                        <span class="eim-pro-teaser-badge"><?php esc_html_e( 'Pro', 'calliope-media-import-export' ); ?></span>
                        <h4><?php echo esc_html( $feature['title'] ); ?></h4>
                        <p><?php echo esc_html( $feature['description'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
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
            <h3><?php esc_html_e( 'CSV Preview', 'calliope-media-import-export' ); ?></h3>
            <p><?php esc_html_e( 'Review the file before starting the import.', 'calliope-media-import-export' ); ?></p>
            <div id="eim-preview-content"></div>
        </div>
        <?php
    }

    private function render_progress_panel() {
        ?>
        <div id="eimp-progress-container" class="eim-card" style="display:none; margin-top: 20px;">
            <h3><?php esc_html_e( 'Import Progress:', 'calliope-media-import-export' ); ?></h3>

            <div style="background: #eee; border: 1px solid #ccc; padding: 5px; border-radius: 5px;">
                <div id="eimp-progress-bar" style="width: 0%; height: 24px; background-color: #0073aa; text-align: center; line-height: 24px; color: white; font-weight: bold; transition: width 0.1s ease; border-radius: 3px;">0%</div>
            </div>

            <div class="eim-warning-message"><?php esc_html_e( 'Keep this tab open while the current import is running.', 'calliope-media-import-export' ); ?></div>

            <div id="eim-import-result-summary" style="display:none;"></div>

            <div id="eimp-log" style="background: #fafafa; border: 1px solid #ccc; border-top: none; padding: 10px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 13px; margin-top: 5px;"></div>

            <div style="margin-top: 15px; text-align: right;">
                <button type="button" class="button" id="eim-download-log" style="display:none;"><?php esc_html_e( 'Download Log (.txt)', 'calliope-media-import-export' ); ?></button>
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
            <div class="eim-footer-review-copy">
                <strong><?php esc_html_e( 'Enjoying Export/Import Media?', 'calliope-media-import-export' ); ?></strong>
                <?php
                /* translators: 1: opening review link tag, 2: closing review link tag, 3: inline five-star SVG markup. */
                $review_text = __( 'A %1$s%3$s%2$s review helps the plugin grow and keeps improvements coming.', 'calliope-media-import-export' );
                printf(
                    wp_kses( $review_text, $allowed ),
                    '<a href="' . esc_url( $context['review_url'] ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Rate 5 stars', 'calliope-media-import-export' ) . '">',
                    '</a>',
                    wp_kses( str_repeat( $stars_svg, 5 ), $allowed )
                );
                ?>
            </div>
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
