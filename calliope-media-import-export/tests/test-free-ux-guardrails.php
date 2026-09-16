<?php

function eim_ux_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$root  = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-admin.php' );
$js    = file_get_contents( $root . '/assets/js/importer.js' );

eim_ux_assert( false === strpos( $admin, "add_action( 'current_screen', [ \$this, 'maybe_hide_admin_notices' ], 0 );" ), 'The plugin screen must not suppress all WordPress admin notices.' );
eim_ux_assert( false === strpos( $admin, '$this->render_pro_spotlight_banner( $context );' ), 'A Pro spotlight must not interrupt the core Export -> Import flow.' );
eim_ux_assert( false === strpos( $admin, '$this->render_review_popup( $context );' ), 'The intrusive review popup must not render in addition to the footer review request.' );
eim_ux_assert( false !== strpos( $admin, 'id="eim-start-button" disabled' ), 'Start Import must be disabled before a CSV is selected and validated.' );
eim_ux_assert( false !== strpos( $js, "startButton.prop('disabled', true).show();" ), 'Removing the CSV must return Start Import to its disabled initial state.' );
eim_ux_assert( false !== strpos( $js, "function resetStateOnError()" ) && false !== strpos( $js, "batchRetryCount = 0;\n        startButton.prop('disabled', true);\n        resetPreviewUI();" ), 'Any CSV validation failure must leave Start Import disabled.' );
eim_ux_assert( false !== strpos( $js, "if (this.files.length > 0)" ) && false !== strpos( $js, "resetFileUI();" ), 'Clearing the native CSV input must reset the file UI and disable Start Import.' );
eim_ux_assert( false !== strpos( $js, "startButton.prop('disabled', true);\n            alert(t('select_csv'));" ), 'A stale enabled Start Import button must self-disable if no CSV is selected.' );
eim_ux_assert( false !== strpos( $js, "completionHasErrors" ) && false !== strpos( $js, "t('summary_errors')" ), 'Imports that finish with row errors must report an explicit error count instead of a clean-success message.' );
eim_ux_assert( false !== strpos( $admin, 'role="progressbar"' ) && false !== strpos( $admin, 'aria-valuenow="0"' ), 'Import progress must expose progressbar semantics.' );
eim_ux_assert( false !== strpos( $js, ".attr('aria-valuenow', String(Math.round(safePercent)))" ), 'ARIA progress value must stay synchronized with visual progress.' );
eim_ux_assert( false !== strpos( $admin, 'id="eim-import-result-summary" aria-live="polite"' ), 'Import summary must announce updates accessibly.' );
eim_ux_assert( false !== strpos( $admin, 'id="eimp-log" role="log"' ), 'Import log must expose log semantics to assistive technology.' );
eim_ux_assert( false === strpos( $admin, '<div class="eim-pro-teaser-grid" aria-hidden="true">' ), 'Informative Pro teaser content must not be hidden from assistive technology.' );
eim_ux_assert( false !== strpos( $admin, "esc_html__( 'Export/Import Media', 'calliope-media-import-export' ) . '</a>';" ), 'Plugin-list action must describe the destination instead of pretending there is a separate Settings screen.' );

echo "Free UX guardrail tests passed.\n";
