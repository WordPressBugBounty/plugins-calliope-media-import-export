jQuery(document).ready(function($) {
    const importForm = $('#eim-import-form');
    const startButton = $('#eim-start-button');
    const stopButton = $('#eim-stop-button');
    const progressBar = $('#eimp-progress-bar');
    const progressContainer = $('#eimp-progress-container');
    const logContainer = $('#eimp-log');
    const fileInput = $('#eim_csv');
    const dropZone = $('#eim-drop-zone');
    const dropContentDefault = $('#eim-drop-content-default');
    const dropContentSuccess = $('#eim-drop-content-success');
    const fileNameDisplay = $('#eim-file-name-display');
    const removeFileBtn = $('#eim-remove-file');
    const downloadLogBtn = $('#eim-download-log');
    const previewPanel = $('#eim-preview-panel');
    const previewContent = $('#eim-preview-content');
    const resultSummaryContainer = $('#eim-import-result-summary');

    const ajaxUrl = (window.eim_ajax && eim_ajax.ajax_url) ? eim_ajax.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    const nonce = (window.eim_ajax && eim_ajax.nonce) ? eim_ajax.nonce : '';
    const i18n = (window.eim_ajax && eim_ajax.i18n) ? eim_ajax.i18n : {};
    const fallbackI18n = (window.eim_ajax && eim_ajax.fallback_i18n) ? eim_ajax.fallback_i18n : {};
    const config = (window.eim_ajax && eim_ajax.config) ? eim_ajax.config : {};

    let totalRows = 0;
    let currentRow = 0;
    let currentFile = '';
    let isImportStopped = false;
    let isValidating = false;
    let autoStartAfterValidation = false;
    let batchRetryCount = 0;
    let lockRetryCount = 0;
    let runtimeBatchSize = 0;
    let importSummary = createEmptySummary();

    const maxBatchRetries = 5;
    const maxLockRetries = 12;

    function t(key) {
        if (i18n && Object.prototype.hasOwnProperty.call(i18n, key) && String(i18n[key] || '') !== '') {
            return String(i18n[key]);
        }

        if (fallbackI18n && Object.prototype.hasOwnProperty.call(fallbackI18n, key)) {
            return String(fallbackI18n[key] || '');
        }

        return '';
    }

    function createEmptySummary() {
        return {
            processed: 0,
            imported: 0,
            skipped: 0,
            errors: 0
        };
    }


    function getSelectedBatchSize() {
        return parseInt($('#batch_size').val(), 10) || parseInt(config.default_batch || 25, 10) || 25;
    }

    function getRuntimeBatchSize() {
        const selectedBatchSize = getSelectedBatchSize();

        if (!runtimeBatchSize || runtimeBatchSize > selectedBatchSize) {
            runtimeBatchSize = selectedBatchSize;
        }

        return Math.max(1, runtimeBatchSize);
    }

    function reduceRuntimeBatchSize() {
        const currentBatchSize = getRuntimeBatchSize();
        let nextBatchSize = currentBatchSize;

        if (currentBatchSize > 100) {
            nextBatchSize = 100;
        } else if (currentBatchSize > 50) {
            nextBatchSize = 50;
        } else if (currentBatchSize > 25) {
            nextBatchSize = 25;
        } else if (currentBatchSize > 10) {
            nextBatchSize = 10;
        }

        if (nextBatchSize < currentBatchSize) {
            runtimeBatchSize = nextBatchSize;
        }
    }

    function isBusyLockResponse(xhr) {
        if (!(xhr && parseInt(xhr.status, 10) === 409)) {
            return false;
        }

        return extractAjaxError(xhr, '').toLowerCase().indexOf('another import request') !== -1;
    }

    function getRetryDelay(xhr) {
        if (isBusyLockResponse(xhr)) {
            return 10000;
        }

        if (xhr && parseInt(xhr.status, 10) >= 500) {
            return 15000;
        }

        return 8000;
    }

    function setFileSelectedUI(filename) {
        dropContentDefault.hide();
        dropContentSuccess.css('display', 'flex').show();
        fileNameDisplay.text(filename);
        dropZone.css('border-style', 'solid').css('border-color', '#27ae60');
    }

    function resetPreviewUI() {
        previewContent.empty();
        previewPanel.hide();
    }

    function resetImportSummaryUI() {
        importSummary = createEmptySummary();
        resultSummaryContainer.hide().empty();
    }

    function resetFileUI() {
        fileInput.val('');
        dropContentSuccess.hide();
        dropContentDefault.show();
        dropZone.css('border-style', 'dashed').css('border-color', '#34D3F5');

        currentFile = '';
        totalRows = 0;
        currentRow = 0;
        batchRetryCount = 0;
        isImportStopped = false;
        isValidating = false;
        autoStartAfterValidation = false;

        startButton.prop('disabled', false).show();
        stopButton.hide().prop('disabled', false);
        logContainer.empty();
        progressContainer.hide();
        downloadLogBtn.hide();
        updateProgress(0);
        resetPreviewUI();
        resetImportSummaryUI();

        return false;
    }

    function showProgressUI() {
        if (progressContainer.length) {
            progressContainer.show();
        }
    }

    function updateProgress(percent) {
        const safePercent = Math.min(100, Math.max(0, percent));
        progressBar.css('width', safePercent + '%').text(Math.round(safePercent) + '%');
    }

    function getStatusLabel(status) {
        switch (String(status || '').toUpperCase()) {
            case 'SKIPPED':
                return t('log_status_skipped');
            case 'IMPORTED':
                return t('log_status_imported');
            case 'ERROR':
                return t('log_status_error');
            case 'WARN':
                return t('log_status_warning');
            case 'FIN':
            case 'FINISHED':
                return t('log_status_finished');
            case 'INFO':
            default:
                return t('log_status_info');
        }
    }

    function logMessage(message, status, details = '') {
        if (!logContainer.length) {
            return;
        }

        let statusColor = '#333';
        switch (status) {
            case 'SKIPPED':
                statusColor = '#e67e22';
                break;
            case 'IMPORTED':
                statusColor = '#27ae60';
                break;
            case 'ERROR':
                statusColor = '#c0392b';
                break;
            case 'INFO':
                statusColor = '#2980b9';
                break;
            case 'WARN':
                statusColor = '#b7791f';
                break;
            case 'FIN':
                statusColor = '#0073aa';
                break;
        }

        const row = $('<div>');
        const labelText = getStatusLabel(status) || String(status || '');
        const label = $('<strong>').css('color', statusColor).text(`${labelText}:`);

        row.append(label);
        row.append(document.createTextNode(` ${String(message || '')}`));

        if (details) {
            const detail = $('<small>').append($('<i>').text(`(${String(details)})`));
            row.append(document.createTextNode(' '));
            row.append(detail);
        }

        logContainer.append(row);
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }

    function extractAjaxError(response, fallback = '') {
        if (response && response.data) {
            if (response.data.message) {
                return String(response.data.message);
            }

            if (response.data.results && response.data.results.length && response.data.results[0].message) {
                return String(response.data.results[0].message);
            }
        }

        if (response && response.responseJSON) {
            return extractAjaxError(response.responseJSON, fallback);
        }

        if (response && response.responseText) {
            const responseText = String(response.responseText).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (responseText) {
                return responseText.slice(0, 220);
            }
        }

        if (response && response.status) {
            const statusText = response.statusText ? ` ${response.statusText}` : '';
            return `HTTP ${response.status}${statusText}`;
        }

        return fallback || t('server_error');
    }
    function getModeLabel(mode) {
        switch (String(mode || '')) {
            case 'remote':
                return t('preview_mode_remote');
            case 'local':
                return t('preview_mode_local');
            case 'mixed':
                return t('preview_mode_mixed');
            default:
                return t('preview_mode_unknown');
        }
    }

    function createPreviewStat(label, value) {
        const stat = $('<div>').addClass('eim-preview-stat');
        stat.append($('<span>').addClass('eim-preview-stat-label').text(label));
        stat.append($('<strong>').addClass('eim-preview-stat-value').text(String(value)));
        return stat;
    }

    function createSummaryChip(label, value, modifier) {
        const chip = $('<div>').addClass(`eim-summary-chip eim-summary-chip-${modifier}`);
        chip.append($('<span>').addClass('eim-summary-chip-label').text(label));
        chip.append($('<strong>').addClass('eim-summary-chip-value').text(String(value)));
        return chip;
    }

    function renderImportSummary() {
        if (!resultSummaryContainer.length || importSummary.processed <= 0) {
            resultSummaryContainer.hide().empty();
            return;
        }

        const wrapper = $('<div>').addClass('eim-import-summary');
        wrapper.append($('<h4>').text(t('summary_title')));

        const chips = $('<div>').addClass('eim-summary-chips');
        chips.append(createSummaryChip(t('summary_processed'), importSummary.processed, 'processed'));
        chips.append(createSummaryChip(t('summary_imported'), importSummary.imported, 'imported'));
        chips.append(createSummaryChip(t('summary_skipped'), importSummary.skipped, 'skipped'));
        chips.append(createSummaryChip(t('summary_errors'), importSummary.errors, 'errors'));

        wrapper.append(chips);
        resultSummaryContainer.empty().append(wrapper).show();
    }

    function renderPreview(preview) {
        previewContent.empty();

        if (!preview || typeof preview !== 'object') {
            previewContent.append(
                $('<p>').addClass('eim-preview-empty').text(t('preview_not_available'))
            );
            previewPanel.show();
            return;
        }

        const summary = preview.summary || {};
        const summaryGrid = $('<div>').addClass('eim-preview-grid');
        summaryGrid.append(createPreviewStat(t('preview_total_rows'), summary.total_rows || totalRows || 0));
        summaryGrid.append(createPreviewStat(t('preview_header_count'), preview.header_count || 0));
        summaryGrid.append(createPreviewStat(t('preview_delimiter'), preview.delimiter_label || preview.delimiter || ','));
        summaryGrid.append(createPreviewStat(t('preview_rows_with_url'), summary.rows_with_url || 0));
        summaryGrid.append(createPreviewStat(t('preview_rows_with_relative'), summary.rows_with_relative_path || 0));
        summaryGrid.append(createPreviewStat(t('preview_rows_with_both'), summary.rows_with_both || 0));
        summaryGrid.append(createPreviewStat(t('preview_rows_missing_source'), summary.rows_missing_source || 0));
        summaryGrid.append(createPreviewStat(t('preview_recommended_mode'), getModeLabel(summary.recommended_mode)));
        previewContent.append(summaryGrid);

        if (preview.recognized_columns && preview.recognized_columns.length) {
            const recognized = $('<div>').addClass('eim-preview-section');
            recognized.append($('<h4>').text(t('preview_recognized_columns')));

            const badges = $('<div>').addClass('eim-preview-badges');
            preview.recognized_columns.forEach(function(label) {
                badges.append($('<span>').addClass('eim-preview-badge').text(String(label)));
            });
            recognized.append(badges);
            previewContent.append(recognized);
        }

        if (preview.warnings && preview.warnings.length) {
            const warnings = $('<div>').addClass('eim-preview-section eim-preview-warning-box');
            warnings.append($('<h4>').text(t('preview_warnings')));

            const list = $('<ul>').addClass('eim-preview-warning-list');
            preview.warnings.forEach(function(warning) {
                list.append($('<li>').text(String(warning)));
            });
            warnings.append(list);
            previewContent.append(warnings);
        }

        if (preview.sample_rows && preview.sample_rows.length) {
            const sample = $('<div>').addClass('eim-preview-section');
            sample.append($('<h4>').text(t('preview_sample_rows')));

            const table = $('<table>').addClass('widefat striped eim-preview-table');
            const thead = $('<thead>');
            const headRow = $('<tr>');
            headRow.append($('<th>').text(t('preview_column_row')));
            headRow.append($('<th>').text(t('preview_column_source')));
            headRow.append($('<th>').text(t('preview_column_relative')));
            headRow.append($('<th>').text(t('preview_column_title')));
            headRow.append($('<th>').text(t('preview_column_alt')));
            thead.append(headRow);
            table.append(thead);

            const tbody = $('<tbody>');
            preview.sample_rows.forEach(function(row) {
                const bodyRow = $('<tr>');
                bodyRow.append($('<td>').text(String(row.row_number || '')));
                bodyRow.append($('<td>').text(String(row.source || t('preview_empty_value'))));
                bodyRow.append($('<td>').text(String(row.relative_path || t('preview_empty_value'))));
                bodyRow.append($('<td>').text(String(row.title || t('preview_empty_value'))));
                bodyRow.append($('<td>').text(String(row.alt || t('preview_empty_value'))));
                tbody.append(bodyRow);
            });
            table.append(tbody);
            sample.append(table);
            previewContent.append(sample);
        }

        previewPanel.show();
    }

    function formatSummaryLine(summary) {
        const parts = [
            `${t('summary_processed')}: ${summary.processed || 0}`,
            `${t('summary_imported')}: ${summary.imported || 0}`,
            `${t('summary_skipped')}: ${summary.skipped || 0}`,
            `${t('summary_errors')}: ${summary.errors || 0}`
        ];

        return parts.join(' | ');
    }

    function mergeSummary(target, source) {
        target.processed += parseInt(source.processed || 0, 10);
        target.imported += parseInt(source.imported || 0, 10);
        target.skipped += parseInt(source.skipped || 0, 10);
        target.errors += parseInt(source.errors || 0, 10);
        return target;
    }

    function resetStateOnError() {
        isValidating = false;
        autoStartAfterValidation = false;
        currentFile = '';
        batchRetryCount = 0;
        startButton.prop('disabled', false);
        resetPreviewUI();
    }

    function validateCsvFile() {
        if (isValidating || !fileInput[0].files.length) {
            return;
        }

        isValidating = true;
        autoStartAfterValidation = !!autoStartAfterValidation;
        currentFile = '';
        totalRows = 0;
        showProgressUI();
        resetImportSummaryUI();
        resetPreviewUI();
        logContainer.empty();
        downloadLogBtn.hide();
        updateProgress(0);
        progressBar.css('background-color', '#2980b9');
        startButton.prop('disabled', true);
        logMessage(t('validating'), 'INFO');

        const formData = new FormData(importForm[0]);
        formData.append('action', 'eim_validate_csv');
        formData.append('nonce', nonce);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        })
            .done(function(response) {
                if (!(response && response.success && response.data)) {
                    logMessage(
                        t('validation_failed'),
                        'ERROR',
                        extractAjaxError(response, t('invalid_response'))
                    );
                    resetStateOnError();
                    return;
                }

                totalRows = parseInt(response.data.total_rows, 10) || 0;
                currentFile = response.data.file || '';

                if (!currentFile || totalRows <= 0) {
                    logMessage(t('empty_csv'), 'ERROR');
                    resetStateOnError();
                    return;
                }

                renderPreview(response.data.preview || null);
                logMessage(
                    t('validation_success'),
                    'INFO',
                    `${t('file_ready')} ${totalRows}`
                );

                $(document).trigger('eim:validationSuccess', [{
                    file: currentFile,
                    totalRows: totalRows,
                    preview: response.data.preview || null
                }]);

                isValidating = false;
                startButton.prop('disabled', false);

                if (autoStartAfterValidation) {
                    autoStartAfterValidation = false;
                    startBatchProcess();
                }
            })
            .fail(function(xhr, status, error) {
                logMessage(
                    t('validation_failed'),
                    'ERROR',
                    extractAjaxError(
                        xhr || null,
                        error || status || t('server_error')
                    )
                );
                $(document).trigger('eim:validationFailed', [{
                    error: extractAjaxError(
                        xhr || null,
                        error || status || t('server_error')
                    )
                }]);
                resetStateOnError();
            });
    }

    function startBatchProcess() {
        isImportStopped = false;
        currentRow = 0;
        batchRetryCount = 0;
        lockRetryCount = 0;
        runtimeBatchSize = getSelectedBatchSize();
        importSummary = createEmptySummary();

        showProgressUI();
        updateProgress(0);
        progressBar.css('background-color', '#0073aa');
        resultSummaryContainer.hide().empty();
        startButton.hide().prop('disabled', false);
        stopButton.show().prop('disabled', false);
        downloadLogBtn.hide();

        const selectedUpdateFields = $('input[name="selected_update_fields[]"]:checked').map(function() {
            return String($(this).val() || '');
        }).get();

        $(document).trigger('eim:importStart', [{
            file: currentFile,
            totalRows: totalRows,
            batchSize: getRuntimeBatchSize(),
            dryRun: $('#eim_dry_run').is(':checked'),
            duplicateStrategy: $('#eim_duplicate_strategy').length ? $('#eim_duplicate_strategy').val() : 'skip',
            matchStrategy: $('#eim_match_strategy').length ? $('#eim_match_strategy').val() : 'auto',
            selectedUpdateFields: selectedUpdateFields
        }]);

        processBatch();
    }

    function processBatch() {
        if (isImportStopped || currentRow >= totalRows) {
            finishImport(false);
            return;
        }

        const batchSize = getRuntimeBatchSize();
        const startLabel = currentRow + 1;
        const endLabel = totalRows ? Math.min(totalRows, currentRow + batchSize) : currentRow + batchSize;

        logMessage(
            t('processing_batch'),
            'INFO',
            `${startLabel}-${endLabel}`
        );

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'eim_process_batch',
                nonce: nonce,
                file: currentFile,
                start_row: currentRow,
                batch_size: batchSize,
                local_import: $('#eim_local_import').is(':checked'),
                skip_thumbnails: $('#eim_skip_thumbnails').is(':checked'),
                honor_relative_path: $('#eim_honor_relative_path').is(':checked'),
                dry_run: $('#eim_dry_run').is(':checked'),
                duplicate_strategy: $('#eim_duplicate_strategy').length ? $('#eim_duplicate_strategy').val() : 'skip',
                match_strategy: $('#eim_match_strategy').length ? $('#eim_match_strategy').val() : 'auto',
                selected_update_fields: $('input[name="selected_update_fields[]"]:checked').map(function() {
                    return String($(this).val() || '');
                }).get()
            },
            dataType: 'json'
        })
            .done(function(response) {
                if (!(response && response.success && response.data)) {
                    logMessage(
                        t('batch_failed'),
                        'ERROR',
                        extractAjaxError(response, t('invalid_response'))
                    );
                    finishImport(true);
                    return;
                }

                batchRetryCount = 0;
                lockRetryCount = 0;
                processResultsSequentially(
                    response.data.results || [],
                    response.data.summary || createEmptySummary(),
                    response.data.meta || {}
                );
            })
            .fail(function(xhr, status, error) {
                const fallback = error || status || t('server_error');
                const ajaxError = extractAjaxError(xhr || null, fallback);

                if (isBusyLockResponse(xhr)) {
                    lockRetryCount++;

                    if (lockRetryCount <= maxLockRetries) {
                        logMessage(
                            t('network_retrying'),
                            'WARN',
                            `${ajaxError} (${lockRetryCount}/${maxLockRetries})`
                        );
                        setTimeout(processBatch, getRetryDelay(xhr));
                        return;
                    }
                } else {
                    lockRetryCount = 0;
                    reduceRuntimeBatchSize();
                }

                if (batchRetryCount < maxBatchRetries) {
                    batchRetryCount++;
                    logMessage(
                        t('network_retrying'),
                        'WARN',
                        `${fallback} (${batchRetryCount}/${maxBatchRetries})`
                    );
                    setTimeout(processBatch, getRetryDelay(xhr));
                    return;
                }

                logMessage(
                    t('network_stopped'),
                    'ERROR',
                    ajaxError
                );
                finishImport(true);
            });
    }

    function processResultsSequentially(results, batchSummary, meta) {
        const items = Array.isArray(results) ? results : [];
        const batchMeta = meta || {};
        const safeBatchSummary = batchSummary || createEmptySummary();
        const rowBase = currentRow;
        let index = 0;
        let displayedRows = 0;

        function next() {
            if (index >= items.length) {
                const nextRow = parseInt(batchMeta.next_row || (rowBase + safeBatchSummary.processed), 10) || rowBase;
                currentRow = Math.max(currentRow, nextRow);
                mergeSummary(importSummary, safeBatchSummary);
                renderImportSummary();

                $(document).trigger('eim:batchProcessed', [{
                    results: items,
                    summary: safeBatchSummary,
                    meta: batchMeta,
                    aggregateSummary: $.extend({}, importSummary)
                }]);

                if (safeBatchSummary.processed > 0) {
                    logMessage(t('batch_summary'), 'INFO', formatSummaryLine(safeBatchSummary));
                }

                if (batchMeta.is_finished || currentRow >= totalRows) {
                    logMessage(t('process_complete'), 'FIN');
                    finishImport(false);
                } else if (isImportStopped) {
                    logMessage(t('process_stopped'), 'FIN');
                    finishImport(false);
                } else {
                    const nextBatchDelay = parseInt(batchMeta.batch_size || 0, 10) >= 100 ? 50 : 500;
                    setTimeout(processBatch, nextBatchDelay);
                }
                return;
            }

            const result = items[index];
            index++;

            if (result && result.status === 'FINISHED') {
                currentRow = totalRows;
                updateProgress(100);
                setTimeout(next, 25);
                return;
            }

            if (result) {
                logMessage(result.file || '', result.status || 'INFO', result.message || '');
            }

            displayedRows++;
            updateProgress(((rowBase + displayedRows) / Math.max(totalRows, 1)) * 100);
            const rowLogDelay = parseInt(batchMeta.batch_size || 0, 10) >= 100 ? 0 : 5;
            setTimeout(next, rowLogDelay);
        }

        next();
    }

    function finishImport(isError) {
        startButton.show().prop('disabled', false);
        stopButton.hide().prop('disabled', false);
        downloadLogBtn.show();

        if (isError) {
            progressBar.css('background-color', '#c0392b');
        } else if (!isImportStopped && totalRows > 0) {
            updateProgress(100);
        }

        if (importSummary.processed > 0) {
            logMessage(t('summary_title'), 'FIN', formatSummaryLine(importSummary));
            renderImportSummary();
        }

        $(document).trigger(isError ? 'eim:importFailed' : 'eim:importFinished', [{
            file: currentFile,
            totalRows: totalRows,
            summary: $.extend({}, importSummary),
            stopped: !!isImportStopped
        }]);

        currentFile = '';
        isValidating = false;
        autoStartAfterValidation = false;
        batchRetryCount = 0;
    }

    removeFileBtn.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (isValidating || stopButton.is(':visible')) {
            return;
        }

        resetFileUI();
    });

    dropZone.on('click', function() {
        if (isValidating || stopButton.is(':visible')) {
            return;
        }

        fileInput.trigger('click');
    });

    fileInput.on('click', function(e) {
        e.stopPropagation();
    });

    $(document).on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
    });

    dropZone.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!currentFile && !fileInput.val()) {
            $(this).addClass('dragover').css('background-color', '#e6f7ff').css('border-color', '#FF4081');
        }
    });

    dropZone.on('dragleave dragend drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover').css('background-color', '');
    });

    dropZone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (isValidating || stopButton.is(':visible')) {
            return;
        }

        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            fileInput[0].files = files;
            setFileSelectedUI(files[0].name);
            autoStartAfterValidation = false;
            validateCsvFile();
        }
    });

    fileInput.on('change', function() {
        if (isValidating || stopButton.is(':visible')) {
            return;
        }

        if (this.files.length > 0) {
            setFileSelectedUI(this.files[0].name);
            autoStartAfterValidation = false;
            validateCsvFile();
        }
    });

    downloadLogBtn.on('click', function(e) {
        e.preventDefault();
        let logContent = '';

        $('#eimp-log div').each(function() {
            logContent += $(this).text().trim() + '\r\n';
        });

        if (!logContent) {
            alert(t('log_empty'));
            return;
        }

        const blob = new Blob([logContent], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');

        a.href = url;
        a.download = 'import-log-' + new Date().toISOString().slice(0, 10) + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        setTimeout(function() {
            URL.revokeObjectURL(url);
        }, 0);
    });

    startButton.on('click', function(e) {
        e.preventDefault();

        if ($(this).prop('disabled')) {
            return;
        }

        if (!fileInput[0].files.length) {
            alert(t('select_csv'));
            return;
        }

        if (!currentFile || totalRows <= 0) {
            autoStartAfterValidation = true;
            validateCsvFile();
            return;
        }

        startBatchProcess();
    });

    stopButton.on('click', function() {
        isImportStopped = true;
        $(this).prop('disabled', true);
        logMessage(t('stopping_process'), 'INFO');
    });
});
