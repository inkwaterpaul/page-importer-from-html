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
                block_pattern: $('#pi-block-pattern').val(),
                page_parent: $('#pi-page-parent').val(),
                image_files: Array.from(document.getElementById('pi-images-files').files),
                document_files: Array.from(document.getElementById('pi-document-files').files)
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
            formData.append('block_pattern', options.block_pattern);
            formData.append('page_parent', options.page_parent);

            batch.forEach(function(file) {
                formData.append('pi_files[]', file);
            });

            // Include image and document files with every batch
            options.image_files.forEach(function(file) {
                formData.append('pi_images_files[]', file);
            });
            options.document_files.forEach(function(file) {
                formData.append('pi_document_files[]', file);
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

            const htmlZip = document.getElementById('pi-html-zip');
            if (!htmlZip.files.length) {
                alert('Please select a ZIP file containing your HTML folder.');
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
            formData.append('page_status', $('#pi-folder-page-status').val());
            formData.append('page_parent', $('#pi-folder-page-parent').val());
            formData.append('block_pattern', $('#pi-folder-block-pattern').val());

            formData.append('pi_html_zip', htmlZip.files[0]);

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

                        const pendingImages = response.data.pending_images || [];
                        const pendingDocs   = response.data.pending_docs   || [];
                        if (pendingImages.length > 0 || pendingDocs.length > 0) {
                            showPendingMediaUI(pendingImages, pendingDocs);
                        }
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
        // Phase 2: pending media upload
        // =========================================================

        function showPendingMediaUI(pendingImages, pendingDocs) {
            const allCount = pendingImages.length + pendingDocs.length;

            const imageSet = new Set(pendingImages.map(function(f) { return f.toLowerCase(); }));
            const docSet   = new Set(pendingDocs.map(function(f)   { return f.toLowerCase(); }));

            let html = '<div class="pi-card pi-pending-media" id="pi-pending-media-section">';
            html += '<h3>Step 2 &mdash; Upload Missing Media</h3>';
            html += '<p>Your pages were imported but <strong>' + allCount + ' file(s)</strong> referenced in the HTML were not found. ';
            html += 'Select your images and documents <strong>folders</strong> below — only the files that are actually needed will be uploaded.</p>';

            if (pendingImages.length > 0) {
                html += '<div class="pi-media-picker" style="margin-bottom:16px;">';
                html += '<label><strong>Images</strong> &mdash; ' + pendingImages.length + ' file(s) needed</label><br>';
                html += '<div id="pi-images-picker-list"><input type="file" webkitdirectory directory multiple style="margin-top:4px;display:block;"></div>';
                html += '<p class="pi-folder-match" id="pi-images-match" style="margin:4px 0 0;color:#888;">No folder selected</p>';
                html += '<div id="pi-images-missing-list" style="display:none;margin-top:6px;"></div>';
                html += '<button type="button" class="button button-small" id="pi-add-images-folder" style="margin-top:8px;display:none;">+ Add another images folder</button>';
                html += '</div>';
            }

            if (pendingDocs.length > 0) {
                html += '<div class="pi-media-picker" style="margin-bottom:16px;">';
                html += '<label><strong>Documents</strong> &mdash; ' + pendingDocs.length + ' file(s) needed</label><br>';
                html += '<div id="pi-docs-picker-list"><input type="file" webkitdirectory directory multiple style="margin-top:4px;display:block;"></div>';
                html += '<p class="pi-folder-match" id="pi-docs-match" style="margin:4px 0 0;color:#888;">No folder selected</p>';
                html += '<div id="pi-docs-missing-list" style="display:none;margin-top:6px;"></div>';
                html += '<button type="button" class="button button-small" id="pi-add-docs-folder" style="margin-top:8px;display:none;">+ Add another documents folder</button>';
                html += '</div>';
            }

            html += '<button type="button" class="button button-primary" id="pi-upload-media-btn" disabled>Upload Matched Files</button>';
            html += '<div id="pi-media-upload-progress" style="display:none;margin-top:12px;">';
            html += '<div class="pi-progress-bar"><div class="pi-progress-bar-fill" id="pi-media-progress-bar" style="width:0%"></div></div>';
            html += '<p class="pi-progress-text" id="pi-media-progress-text">Uploading...</p>';
            html += '</div>';
            html += '<div id="pi-media-upload-results"></div>';
            html += '</div>';

            $folderResults.append(html);

            // Cumulative matched file collections (accumulate across multiple folder picks)
            const matchedImages     = [];
            const matchedImageNames = new Set();
            const matchedDocs       = [];
            const matchedDocNames   = new Set();

            function updateUploadButton() {
                $('#pi-upload-media-btn').prop('disabled', matchedImages.length === 0 && matchedDocs.length === 0);
            }

            function handleFolderSelect(fileList, nameSet, matchedFiles, matchedNames, $status, $missingList, $addBtn, type) {
                Array.from(fileList).forEach(function(file) {
                    const lower = file.name.toLowerCase();
                    if (nameSet.has(lower) && !matchedNames.has(lower)) {
                        matchedFiles.push(file);
                        matchedNames.add(lower);
                    }
                });

                const missing = [];
                nameSet.forEach(function(name) {
                    if (!matchedNames.has(name)) missing.push(name);
                });

                let msg = '<span style="color:#00a32a;">&#10003; Found ' + matchedFiles.length + ' of ' + nameSet.size + ' needed ' + type + '</span>';
                if (missing.length > 0) {
                    msg += ' &mdash; <span style="color:#d63638;">' + missing.length + ' still missing</span>';
                }
                $status.html(msg);

                if (missing.length > 0) {
                    let listHtml = '<details><summary style="cursor:pointer;color:#d63638;font-size:13px;">Show ' + missing.length + ' missing filenames</summary>';
                    listHtml += '<div style="max-height:130px;overflow-y:auto;background:#f8f8f8;padding:6px 10px;margin-top:4px;border:1px solid #ddd;font-size:12px;line-height:1.7;">';
                    listHtml += missing.map(escapeHtml).join('<br>');
                    listHtml += '</div></details>';
                    $missingList.html(listHtml).show();
                    $addBtn.show();
                } else {
                    $missingList.hide();
                    $addBtn.hide();
                }

                updateUploadButton();
            }

            function attachImagePicker($input) {
                $input.on('change', function() {
                    handleFolderSelect(this.files, imageSet, matchedImages, matchedImageNames,
                        $('#pi-images-match'), $('#pi-images-missing-list'), $('#pi-add-images-folder'), 'images');
                });
            }

            function attachDocPicker($input) {
                $input.on('change', function() {
                    handleFolderSelect(this.files, docSet, matchedDocs, matchedDocNames,
                        $('#pi-docs-match'), $('#pi-docs-missing-list'), $('#pi-add-docs-folder'), 'documents');
                });
            }

            if (pendingImages.length > 0) {
                attachImagePicker($('#pi-images-picker-list').find('input[type=file]'));
                $('#pi-add-images-folder').on('click', function() {
                    const $input = $('<input type="file" webkitdirectory directory multiple style="display:block;margin-top:6px;">');
                    $('#pi-images-picker-list').append($input);
                    attachImagePicker($input);
                    $input[0].click();
                });
            }

            if (pendingDocs.length > 0) {
                attachDocPicker($('#pi-docs-picker-list').find('input[type=file]'));
                $('#pi-add-docs-folder').on('click', function() {
                    const $input = $('<input type="file" webkitdirectory directory multiple style="display:block;margin-top:6px;">');
                    $('#pi-docs-picker-list').append($input);
                    attachDocPicker($input);
                    $input[0].click();
                });
            }

            $('#pi-upload-media-btn').on('click', function() {
                const allFiles = matchedImages.concat(matchedDocs);
                if (!allFiles.length) return;

                const $btn      = $(this);
                const $progress = $('#pi-media-upload-progress');
                const $bar      = $('#pi-media-progress-bar');
                const $text     = $('#pi-media-progress-text');
                const $results  = $('#pi-media-upload-results');

                const batchSize = 15;
                const batches   = [];
                for (let i = 0; i < allFiles.length; i += batchSize) {
                    batches.push(allFiles.slice(i, i + batchSize));
                }

                let imagesUpdated = 0;
                let docsUpdated   = 0;
                let pagesUpdated  = 0;
                let stillMissing  = [];

                $btn.prop('disabled', true).text('Uploading...');
                $progress.show();
                $results.html('');

                function uploadBatch(index) {
                    if (index >= batches.length) {
                        $btn.prop('disabled', false).text('Upload Matched Files');
                        $progress.hide();

                        let html = '<div class="pi-notice success" style="margin-top:12px;">';
                        html += '<strong>Done!</strong> ' + imagesUpdated + ' image(s) and ' + docsUpdated + ' document(s) uploaded across ' + pagesUpdated + ' page(s).';
                        html += '</div>';

                        if (stillMissing.length > 0) {
                            const unique = stillMissing.filter(function(v, i, a) { return a.indexOf(v) === i; });
                            html += '<p style="margin-top:8px;"><strong>Still missing (' + unique.length + '):</strong> ' + unique.map(escapeHtml).join(', ') + '</p>';
                        }

                        $results.html(html);
                        return;
                    }

                    $bar.css('width', Math.round(((index) / batches.length) * 100) + '%');
                    $text.text('Batch ' + (index + 1) + ' of ' + batches.length + '...');

                    const formData = new FormData();
                    formData.append('action', 'pi_upload_media');
                    formData.append('nonce', piAjax.nonce);
                    batches[index].forEach(function(f) {
                        formData.append('media_files[]', f);
                    });

                    $.ajax({
                        url: piAjax.ajaxurl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            if (response.success && response.data.results) {
                                const r   = response.data.results;
                                imagesUpdated += r.images_updated || 0;
                                docsUpdated   += r.docs_updated   || 0;
                                pagesUpdated  += r.pages_updated  || 0;
                                if (r.still_missing) {
                                    stillMissing = stillMissing.concat(r.still_missing);
                                }
                            }
                            uploadBatch(index + 1);
                        },
                        error: function(xhr, status, error) {
                            $results.html('<div class="pi-notice error">Batch ' + (index + 1) + ' failed: ' + escapeHtml(error) + '</div>');
                            $btn.prop('disabled', false).text('Upload Matched Files');
                            $progress.hide();
                        }
                    });
                }

                uploadBatch(0);
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
