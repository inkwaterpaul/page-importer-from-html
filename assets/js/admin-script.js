/**
 * HTML Page Importer - Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // =========================================================
        // Tab switching
        // =========================================================

        $('.pi-tab').on('click', function() {
            const tab = $(this).data('tab');
            $('.pi-tab').removeClass('active');
            $(this).addClass('active');
            $('.pi-tab-content').hide();
            $('#pi-tab-' + tab).show();
        });

        // =========================================================
        // File Upload tab
        // =========================================================

        const $form = $('#pi-import-form');
        const $fileInput = $('#pi-files');
        const $submitBtn = $('#pi-import-btn');
        const $progress = $('#pi-progress');
        const $progressBar = $('#pi-progress-bar');
        const $progressText = $('#pi-progress-text');
        const $results = $('#pi-results');
        const $resultsContent = $('#pi-results-content');

        $form.on('submit', function(e) {
            e.preventDefault();

            if (!$fileInput[0].files.length) {
                alert(piAjax.strings.error + ' Please select at least one file.');
                return;
            }

            const files = Array.from($fileInput[0].files);
            const totalFiles = files.length;
            const batchSize = 10;
            const batches = [];

            for (let i = 0; i < totalFiles; i += batchSize) {
                batches.push(files.slice(i, i + batchSize));
            }

            const options = {
                page_status: $('#pi-page-status').val(),
                images_folder: $('#pi-images-folder').val(),
                documents_folder: $('#pi-documents-folder').val(),
                block_pattern: $('#pi-block-pattern').val(),
                page_parent: $('#pi-page-parent').val()
            };

            $submitBtn.prop('disabled', true).html(
                '<span class="dashicons dashicons-upload"></span> ' + piAjax.strings.processing
            );

            $progress.show();
            $results.hide();
            updateProgress(0, 0, totalFiles);

            const aggregatedResults = {
                success: [],
                failed: [],
                total: totalFiles
            };

            processBatches(batches, 0, options, aggregatedResults, totalFiles);
        });

        function processBatches(batches, currentBatchIndex, options, aggregatedResults, totalFiles) {
            if (currentBatchIndex >= batches.length) {
                updateProgress(100, totalFiles, totalFiles);
                displayResults(aggregatedResults, $resultsContent, $results);

                $form[0].reset();

                showNotice('success', sprintf(
                    'Import completed. %d succeeded, %d failed.',
                    aggregatedResults.success.length,
                    aggregatedResults.failed.length
                ));

                $submitBtn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-upload"></span> Import Files'
                );

                refreshParentPageDropdown();

                setTimeout(function() {
                    $progress.fadeOut();
                }, 2000);

                return;
            }

            const batch = batches[currentBatchIndex];
            const processedFiles = currentBatchIndex * 10;
            updateProgress((processedFiles / totalFiles) * 100, processedFiles, totalFiles, currentBatchIndex + 1, batches.length);

            const formData = new FormData();
            formData.append('action', 'pi_import_files');
            formData.append('nonce', piAjax.nonce);
            formData.append('page_status', options.page_status);
            formData.append('images_folder', options.images_folder);
            formData.append('documents_folder', options.documents_folder);
            formData.append('block_pattern', options.block_pattern);
            formData.append('page_parent', options.page_parent);

            batch.forEach(function(file) {
                formData.append('pi_files[]', file);
            });

            $.ajax({
                url: piAjax.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success && response.data.results) {
                        aggregatedResults.success.push(...response.data.results.success);
                        aggregatedResults.failed.push(...response.data.results.failed);
                    } else {
                        batch.forEach(function(file) {
                            aggregatedResults.failed.push({
                                file: file.name,
                                error: (response.data && response.data.message) || 'Batch processing failed'
                            });
                        });
                    }
                    processBatches(batches, currentBatchIndex + 1, options, aggregatedResults, totalFiles);
                },
                error: function(xhr, status, error) {
                    let errorMessage = error;
                    if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMessage = response.data.message;
                            }
                        } catch (e) {
                            errorMessage = 'Server error occurred';
                        }
                    }
                    batch.forEach(function(file) {
                        aggregatedResults.failed.push({ file: file.name, error: errorMessage });
                    });
                    processBatches(batches, currentBatchIndex + 1, options, aggregatedResults, totalFiles);
                }
            });
        }

        function updateProgress(percent, processed, total, currentBatch, totalBatches) {
            percent = Math.round(percent);
            $progressBar.css('width', percent + '%');

            let progressText = percent + '%';
            if (typeof processed !== 'undefined' && typeof total !== 'undefined') {
                progressText += ' (' + processed + '/' + total + ' files';
                if (typeof currentBatch !== 'undefined' && typeof totalBatches !== 'undefined') {
                    progressText += ', batch ' + currentBatch + '/' + totalBatches;
                }
                progressText += ')';
            }
            $progressText.text(progressText);
        }

        // File preview on selection
        $fileInput.on('change', function() {
            if (this.files.length > 0) {
                previewFirstFile(this.files[0]);
            } else {
                $('#pi-preview').hide();
            }
        });

        function previewFirstFile(file) {
            const $preview = $('#pi-preview');
            const $previewContent = $('#pi-preview-content');

            $preview.show();
            $previewContent.html(
                '<div class="pi-preview-loading"><span class="spinner is-active"></span><p>Loading preview...</p></div>'
            );
            $results.hide();

            const formData = new FormData();
            formData.append('action', 'pi_preview_file');
            formData.append('nonce', piAjax.nonce);
            formData.append('preview_file', file);

            $.ajax({
                url: piAjax.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        let html = '<div class="pi-preview-data">';
                        html += '<div class="pi-preview-item"><strong>File:</strong> ' + escapeHtml(response.data.file_name) + '</div>';
                        html += '<div class="pi-preview-item"><strong>Title:</strong> <span class="pi-preview-title">' + escapeHtml(response.data.title) + '</span></div>';
                        html += '<div class="pi-preview-item"><strong>First Image:</strong> ' + escapeHtml(response.data.first_image) + '</div>';
                        html += '<div class="pi-preview-item"><strong>Content Preview:</strong> <span class="pi-preview-length">(' + response.data.content_full + ')</span>';
                        html += '<div class="pi-preview-content-text">' + response.data.content + '</div></div>';
                        html += '<div class="pi-notice success"><strong>✓ Preview successful!</strong> Looks good.</div>';
                        html += '</div>';
                        $previewContent.html(html);
                    } else {
                        const msg = (response.data && response.data.message) ? response.data.message : 'Could not load preview';
                        $previewContent.html('<div class="pi-notice error"><strong>Preview Error:</strong> ' + escapeHtml(msg) + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    $previewContent.html('<div class="pi-notice error"><strong>Error:</strong> Could not load preview. ' + escapeHtml(error) + '</div>');
                }
            });
        }

        // =========================================================
        // Folder Import tab
        // =========================================================

        const $folderForm = $('#pi-folder-import-form');
        const $folderImportBtn = $('#pi-folder-import-btn');
        const $folderProgress = $('#pi-folder-progress');
        const $folderResults = $('#pi-folder-results');
        const $folderResultsContent = $('#pi-folder-results-content');

        $folderForm.on('submit', function(e) {
            e.preventDefault();

            const htmlFolder = $('#pi-html-folder').val().trim();
            if (!htmlFolder) {
                alert('Please enter or browse to a folder path.');
                return;
            }

            $folderImportBtn.prop('disabled', true).html(
                '<span class="dashicons dashicons-category"></span> ' + piAjax.strings.processing
            );

            $folderProgress.show();
            $folderResults.hide();

            const formData = new FormData();
            formData.append('action', 'pi_import_folder');
            formData.append('nonce', piAjax.nonce);
            formData.append('html_folder', htmlFolder);
            formData.append('page_status', $('#pi-folder-page-status').val());
            formData.append('page_parent', $('#pi-folder-page-parent').val());
            formData.append('block_pattern', $('#pi-folder-block-pattern').val());
            formData.append('images_folder', $('#pi-folder-images-folder').val());
            formData.append('documents_folder', $('#pi-folder-documents-folder').val());

            $.ajax({
                url: piAjax.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 600000,
                success: function(response) {
                    $folderProgress.hide();
                    $folderImportBtn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-category"></span> Import Folder'
                    );

                    if (response.success && response.data.results) {
                        displayResults(response.data.results, $folderResultsContent, $folderResults);
                        showNotice('success', response.data.message);
                        refreshParentPageDropdown();
                        refreshFolderPageDropdown();
                    } else {
                        const msg = (response.data && response.data.message) ? response.data.message : 'Import failed.';
                        showNotice('error', msg);
                    }
                },
                error: function(xhr, status, error) {
                    $folderProgress.hide();
                    $folderImportBtn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-category"></span> Import Folder'
                    );
                    let msg = error || 'Server error occurred';
                    if (xhr.responseText) {
                        try {
                            const r = JSON.parse(xhr.responseText);
                            if (r.data && r.data.message) msg = r.data.message;
                        } catch (e) {}
                    }
                    showNotice('error', 'Import failed: ' + msg);
                }
            });
        });

        // =========================================================
        // Shared: display results
        // =========================================================

        function displayResults(results, $container, $wrapper) {
            $wrapper.show();

            let html = '<div class="pi-summary">';
            html += '<div class="pi-summary-item"><span class="pi-summary-value">' + (results.total || (results.success.length + results.failed.length)) + '</span><span class="pi-summary-label">Total Files</span></div>';
            html += '<div class="pi-summary-item"><span class="pi-summary-value" style="color:#00a32a;">' + results.success.length + '</span><span class="pi-summary-label">Successful</span></div>';
            html += '<div class="pi-summary-item"><span class="pi-summary-value" style="color:#d63638;">' + results.failed.length + '</span><span class="pi-summary-label">Failed</span></div>';
            html += '</div>';

            if (results.success.length > 0) {
                html += '<h4 class="success-header">&#10003; Successfully Imported</h4>';
                html += '<ul class="pi-results-list">';
                results.success.forEach(function(item) {
                    html += '<li class="success">';
                    html += '<div class="result-info">';
                    html += '<div class="result-title">' + escapeHtml(item.page_title) + '</div>';
                    html += '<div class="result-file">' + escapeHtml(item.file_name);
                    if (item.featured_image) {
                        html += ' <span class="result-date">&bull; Image: ' + escapeHtml(item.featured_image) + '</span>';
                    }
                    html += '</div></div>';
                    html += '<div class="result-actions">';
                    html += '<a href="' + escapeHtml(item.edit_url) + '" class="button button-small">Edit</a> ';
                    html += '<a href="' + escapeHtml(item.view_url) + '" class="button button-small" target="_blank">View</a>';
                    html += '</div></li>';
                });
                html += '</ul>';
            }

            if (results.failed.length > 0) {
                html += '<h4 class="failed-header">&#9888; Failed Imports</h4>';
                html += '<div style="margin-bottom:10px;">';
                html += '<button type="button" class="button button-small pi-copy-failed-files" data-files="' +
                        escapeHtml(JSON.stringify(results.failed.map(function(i) { return i.file; }))) + '">';
                html += '<span class="dashicons dashicons-clipboard" style="font-size:16px;line-height:1.2;"></span> Copy Failed Files List';
                html += '</button></div>';
                html += '<ul class="pi-results-list">';
                results.failed.forEach(function(item) {
                    html += '<li class="error">';
                    html += '<div class="result-info">';
                    html += '<div class="result-title">' + escapeHtml(item.file) + '</div>';
                    html += '<div class="result-error"><strong>Error:</strong> ' + escapeHtml(item.error) + '</div>';
                    html += '</div></li>';
                });
                html += '</ul>';
            }

            $container.html(html);

            $container.find('.pi-copy-failed-files').on('click', function() {
                const files = JSON.parse($(this).data('files'));
                const text = files.join('\n');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function() {
                        showNotice('success', 'Failed files list copied to clipboard!');
                    }).catch(function() { fallbackCopy(text); });
                } else {
                    fallbackCopy(text);
                }
            });
        }

        // =========================================================
        // Folder browser modal
        // =========================================================

        let currentFolderPath = '';
        let currentBrowseTarget = '';

        const browseTargetTitles = {
            'images': 'Select Images Folder',
            'documents': 'Select Documents Folder',
            'html-folder': 'Select HTML Files Folder',
            'folder-images': 'Select Images Folder',
            'folder-documents': 'Select Documents Folder'
        };

        const browseTargetInputs = {
            'images': '#pi-images-folder',
            'documents': '#pi-documents-folder',
            'html-folder': '#pi-html-folder',
            'folder-images': '#pi-folder-images-folder',
            'folder-documents': '#pi-folder-documents-folder'
        };

        // Legacy button IDs (file upload tab)
        $('#pi-browse-folder').on('click', function() {
            openFolderBrowser('images');
        });
        $('#pi-browse-documents-folder').on('click', function() {
            openFolderBrowser('documents');
        });

        // Generic browse buttons (folder import tab uses data-browse-target)
        $(document).on('click', '.pi-browse-btn', function() {
            openFolderBrowser($(this).data('browse-target'));
        });

        function openFolderBrowser(target) {
            currentBrowseTarget = target;
            const title = browseTargetTitles[target] || 'Select Folder';
            $('#pi-modal-title').text(title);
            // Pre-populate with existing path if any
            const existing = $(browseTargetInputs[target]).val();
            $('#pi-folder-browser-modal').fadeIn();
            loadFolders(existing || '');
        }

        $('#pi-folder-browser-modal .pi-modal-close, #pi-folder-cancel').on('click', function() {
            $('#pi-folder-browser-modal').fadeOut();
        });

        $('#pi-folder-browser-modal').on('click', function(e) {
            if ($(e.target).is('#pi-folder-browser-modal')) {
                $(this).fadeOut();
            }
        });

        $('#pi-folder-select').on('click', function() {
            if (currentFolderPath && browseTargetInputs[currentBrowseTarget]) {
                $(browseTargetInputs[currentBrowseTarget]).val(currentFolderPath);
                $('#pi-folder-browser-modal').fadeOut();
            }
        });

        function loadFolders(path) {
            const $folderList = $('#pi-folder-list');
            $folderList.html(
                '<div class="pi-folder-loading"><span class="spinner is-active"></span><p>Loading folders...</p></div>'
            );

            $.ajax({
                url: piAjax.ajaxurl,
                type: 'POST',
                data: { action: 'pi_browse_folders', nonce: piAjax.nonce, path: path },
                success: function(response) {
                    if (response.success) {
                        displayFolders(response.data);
                    } else {
                        const msg = (response.data && response.data.message) ? response.data.message : 'Could not load folders';
                        $folderList.html('<div class="pi-folder-empty"><strong>Error:</strong> ' + escapeHtml(msg) + '</div>');
                    }
                },
                error: function() {
                    $folderList.html('<div class="pi-folder-empty"><strong>Error:</strong> Could not load folders.</div>');
                }
            });
        }

        function displayFolders(data) {
            currentFolderPath = data.current_path;
            $('#pi-current-path').text(data.current_path);

            const $folderList = $('#pi-folder-list');
            let html = '';

            if (data.parent_path) {
                html += '<div class="pi-folder-item parent-folder" data-path="' + escapeHtml(data.parent_path) + '">';
                html += '<span class="pi-folder-icon">↰</span>';
                html += '<span class="pi-folder-name">..</span>';
                html += '</div>';
            }

            if (data.folders.length === 0) {
                html += '<div class="pi-folder-empty">No accessible subdirectories found.</div>';
            } else {
                data.folders.forEach(function(folder) {
                    html += '<div class="pi-folder-item" data-path="' + escapeHtml(folder.path) + '">';
                    html += '<span class="pi-folder-icon">&#128193;</span>';
                    html += '<span class="pi-folder-name">' + escapeHtml(folder.name) + '</span>';
                    if (folder.has_subdirs) {
                        html += '<span class="pi-folder-arrow">&rarr;</span>';
                    }
                    html += '</div>';
                });
            }

            $folderList.html(html);

            $folderList.find('.pi-folder-item').on('click', function() {
                loadFolders($(this).data('path'));
            });
        }

        // =========================================================
        // Shared helpers
        // =========================================================

        function refreshParentPageDropdown() {
            $.ajax({
                url: piAjax.ajaxurl,
                type: 'POST',
                data: { action: 'pi_refresh_page_dropdown', nonce: piAjax.nonce },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $('#pi-page-parent').html(response.data.html);
                    }
                }
            });
        }

        function refreshFolderPageDropdown() {
            $.ajax({
                url: piAjax.ajaxurl,
                type: 'POST',
                data: { action: 'pi_refresh_page_dropdown', nonce: piAjax.nonce },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $('#pi-folder-page-parent').html(response.data.html);
                    }
                }
            });
        }

        function showNotice(type, message) {
            const $notice = $('<div class="pi-notice ' + type + '">' + escapeHtml(message) + '</div>');
            $('.pi-tab-content:visible').prepend($notice);
            setTimeout(function() {
                $notice.fadeOut(function() { $(this).remove(); });
            }, 5000);
        }

        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showNotice('success', 'Copied to clipboard!');
            } catch (err) {
                showNotice('error', 'Failed to copy to clipboard');
            }
            document.body.removeChild(textarea);
        }

        function sprintf(format) {
            const args = Array.prototype.slice.call(arguments, 1);
            let i = 0;
            return format.replace(/%[sd]/g, function() { return args[i++]; });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

    });

})(jQuery);
