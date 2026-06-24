<?php
/**
 * AJAX Handler Class
 * Handles AJAX requests for file import
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PI_AJAX_Handler {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_pi_import_files', array(__CLASS__, 'handle_import'));
        add_action('wp_ajax_pi_process_file', array(__CLASS__, 'handle_process_file'));
        add_action('wp_ajax_pi_preview_file', array(__CLASS__, 'handle_preview'));
        add_action('wp_ajax_pi_refresh_page_dropdown', array(__CLASS__, 'handle_refresh_page_dropdown'));
        add_action('wp_ajax_pi_import_folder', array(__CLASS__, 'handle_import_folder'));
        add_action('wp_ajax_pi_upload_media', array(__CLASS__, 'handle_upload_media'));
    }

    /**
     * Handle file import AJAX request
     */
    public static function handle_import() {
        // Start output buffering to catch any PHP errors/warnings
        ob_start();

        try {
            // Verify nonce
            check_ajax_referer('pi_import_nonce', 'nonce');

            // Check user capabilities
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array(
                    'message' => __('You do not have permission to perform this action.', PI_NAME )
                ));
            }

            // Check if files were uploaded
            if (empty($_FILES['pi_files'])) {
                ob_end_clean();
                wp_send_json_error(array(
                    'message' => __('No files were uploaded.', PI_NAME )
                ));
            }

        // Save uploaded images and documents to temp directories
        $temp_base = null;
        $images_temp = '';
        $docs_temp = '';

        if (!empty($_FILES['pi_images_files']['name'][0]) || !empty($_FILES['pi_document_files']['name'][0])) {
            $upload_dir = wp_upload_dir();
            $temp_base  = $upload_dir['basedir'] . '/pi-temp/' . uniqid();
        }

        if (!empty($_FILES['pi_images_files']['name'][0])) {
            $images_temp = $temp_base . '/images';
            wp_mkdir_p($images_temp);
            $img = $_FILES['pi_images_files'];
            for ($j = 0; $j < count($img['name']); $j++) {
                if ($img['error'][$j] === UPLOAD_ERR_OK) {
                    $fname = sanitize_file_name(basename($img['name'][$j]));
                    move_uploaded_file($img['tmp_name'][$j], $images_temp . '/' . $fname);
                }
            }
        }

        if (!empty($_FILES['pi_document_files']['name'][0])) {
            $docs_temp = $temp_base . '/docs';
            wp_mkdir_p($docs_temp);
            $doc = $_FILES['pi_document_files'];
            for ($j = 0; $j < count($doc['name']); $j++) {
                if ($doc['error'][$j] === UPLOAD_ERR_OK) {
                    $fname = sanitize_file_name(basename($doc['name'][$j]));
                    move_uploaded_file($doc['tmp_name'][$j], $docs_temp . '/' . $fname);
                }
            }
        }

        // Get import options
        $options = array(
            'page_status' => isset($_POST['page_status']) ? sanitize_text_field($_POST['page_status']) : 'draft',
            'images_folder' => $images_temp,
            'documents_folder' => $docs_temp,
            'block_pattern' => isset($_POST['block_pattern']) ? wp_unslash($_POST['block_pattern']) : '',
            'page_parent' => isset($_POST['page_parent']) ? absint($_POST['page_parent']) : 0
        );

        // Process uploaded files
        $files = $_FILES['pi_files'];
        $file_count = count($files['name']);
        $file_paths = array();

        // Initialize results array for tracking
        $validation_errors = array();

        // Validate and prepare files
        for ($i = 0; $i < $file_count; $i++) {
            $file = array(
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            );

            // Validate file - collect errors but don't stop
            $validation = PI_Content_Extractor::validate_file($file);

            if (is_wp_error($validation)) {
                $validation_errors[] = array(
                    'file' => $file['name'],
                    'error' => $validation->get_error_message()
                );
                continue; // Skip this file and move to the next
            }

            $file_paths[] = array(
                'path' => $file['tmp_name'],
                'name' => $file['name']
            );
        }

        // Import files
        $results = array(
            'success' => array(),
            'failed' => $validation_errors, // Start with validation errors
            'total' => $file_count
        );

        foreach ($file_paths as $file_info) {
            try {
                // Suppress any PHP warnings/errors that might corrupt JSON
                error_reporting(E_ERROR);
                $result = PI_Importer::import_file($file_info['path'], $options);
                error_reporting(E_ALL);

                if (is_wp_error($result)) {
                    $results['failed'][] = array(
                        'file' => $file_info['name'],
                        'error' => $result->get_error_message()
                    );
                } else {
                    $results['success'][] = $result;

                    // Log the import
                    PI_Logger::log_import($result['page_id'], $file_info['name'], 'success');
                }
            } catch (Exception $e) {
                // Catch any exceptions and add to failed list
                $results['failed'][] = array(
                    'file' => $file_info['name'],
                    'error' => 'Exception: ' . $e->getMessage()
                );
            } catch (Error $e) {
                // Catch fatal errors (PHP 7+)
                $results['failed'][] = array(
                    'file' => $file_info['name'],
                    'error' => 'Fatal error: ' . $e->getMessage()
                );
            }
        }

        // Clean up temp directories
        if ($temp_base) {
            self::cleanup_temp_dir($temp_base);
        }

        // Clean output buffer before sending JSON
        ob_end_clean();

        // Send response
        wp_send_json_success(array(
            'message' => sprintf(
                __('Import completed. %d succeeded, %d failed.', PI_NAME ),
                count($results['success']),
                count($results['failed'])
            ),
            'results' => $results
        ));

        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array(
                'message' => 'An error occurred: ' . $e->getMessage()
            ));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array(
                'message' => 'A fatal error occurred: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Handle single file processing (for progress tracking)
     */
    public static function handle_process_file() {
        // Verify nonce
        check_ajax_referer('pi_import_nonce', 'nonce');

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', PI_NAME )
            ));
        }

        // Get file index and options
        $file_index = isset($_POST['file_index']) ? absint($_POST['file_index']) : 0;
        $options = isset($_POST['options']) ? $_POST['options'] : array();

        // This would be used for processing individual files with progress updates
        // For now, we'll use the batch import method above

        wp_send_json_success(array(
            'message' => __('File processed successfully', PI_NAME )
        ));
    }

    /**
     * Handle file preview AJAX request
     */
    public static function handle_preview() {
        // Verify nonce
        check_ajax_referer('pi_import_nonce', 'nonce');

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', PI_NAME )
            ));
        }

        // Check if file was uploaded
        if (empty($_FILES['preview_file'])) {
            wp_send_json_error(array(
                'message' => __('No file was uploaded for preview.', PI_NAME )
            ));
        }

        $file = $_FILES['preview_file'];

        // Validate file
        $validation = PI_Content_Extractor::validate_file($file);

        if (is_wp_error($validation)) {
            wp_send_json_error(array(
                'message' => $validation->get_error_message()
            ));
        }

        // Extract content from file
        $extracted = PI_Content_Extractor::extract_from_file($file['tmp_name']);

        if (is_wp_error($extracted)) {
            wp_send_json_error(array(
                'message' => $extracted->get_error_message()
            ));
        }

        // Truncate content for preview (first 500 characters)
        $content_preview = $extracted['content'];
        if (strlen($content_preview) > 500) {
            $content_preview = substr($content_preview, 0, 500) . '...';
        }

        // Send success response with extracted data
        wp_send_json_success(array(
            'title' => $extracted['title'],
            'content' => $content_preview,
            'content_full' => strlen($extracted['content']) . ' characters',
            'date' => $extracted['date'] ? date('F j, Y', strtotime($extracted['date'])) : __('Not found', PI_NAME ),
            'first_image' => !empty($extracted['first_image']) ? $extracted['first_image'] : __('Not found', PI_NAME ),
            'file_name' => $extracted['file_name']
        ));
    }

    /**
     * Recursively delete a temporary directory
     */
    private static function cleanup_temp_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff((array) @scandir($dir), array('.', '..'));
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? self::cleanup_temp_dir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Handle folder import AJAX request.
     * Accepts three ZIP uploads (HTML folder, images, documents), extracts them
     * to temp dirs, then runs the existing import logic.
     */
    public static function handle_import_folder() {
        ob_start();

        $temp_base = null;

        try {
            check_ajax_referer('pi_import_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('You do not have permission to perform this action.', PI_NAME)));
            }

            if (empty($_FILES['pi_html_zip']['tmp_name'])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('No HTML ZIP file was uploaded.', PI_NAME)));
            }

            if (!class_exists('ZipArchive')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('ZipArchive is not available on this server. Please contact your host.', PI_NAME)));
            }

            // Create temp dirs inside uploads — guaranteed writable on all hosts
            $upload_dir = wp_upload_dir();
            $temp_base  = $upload_dir['basedir'] . '/pi-temp/' . uniqid();
            $html_temp  = $temp_base . '/html';
            wp_mkdir_p($html_temp);

            // Extract HTML ZIP preserving folder hierarchy
            $result = self::extract_zip($html_temp, $_FILES['pi_html_zip']['tmp_name'], false);
            if (is_wp_error($result)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $result->get_error_message()));
            }

            $options = array(
                'page_status'      => isset($_POST['page_status']) ? sanitize_text_field($_POST['page_status']) : 'draft',
                'images_folder'    => '',
                'documents_folder' => '',
                'block_pattern'    => isset($_POST['block_pattern']) ? wp_unslash($_POST['block_pattern']) : '',
                'page_parent'      => isset($_POST['page_parent']) ? absint($_POST['page_parent']) : 0,
            );

            @set_time_limit(600);

            $results = PI_Importer::import_folder($html_temp, $options);

            self::cleanup_temp_dir($temp_base);
            $temp_base = null;

            ob_end_clean();

            if (is_wp_error($results)) {
                wp_send_json_error(array('message' => $results->get_error_message()));
            }

            // Collect all pending image/doc filenames across successfully imported pages
            $pending_images = array();
            $pending_docs   = array();
            foreach ($results['success'] as $item) {
                if (!empty($item['pending_images'])) {
                    $pending_images = array_merge($pending_images, $item['pending_images']);
                }
                if (!empty($item['pending_docs'])) {
                    $pending_docs = array_merge($pending_docs, $item['pending_docs']);
                }
            }

            wp_send_json_success(array(
                'message'        => sprintf(
                    __('Folder import completed. %d succeeded, %d failed.', PI_NAME),
                    $results['success_count'],
                    $results['failed_count']
                ),
                'results'        => $results,
                'pending_images' => array_values(array_unique($pending_images)),
                'pending_docs'   => array_values(array_unique($pending_docs)),
            ));

        } catch (Exception $e) {
            if ($temp_base) self::cleanup_temp_dir($temp_base);
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            if ($temp_base) self::cleanup_temp_dir($temp_base);
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Extract a ZIP file into $target_dir.
     *
     * $flatten = false  →  preserve folder hierarchy, stripping any single common
     *                      root folder (handles "zip the folder" vs "zip the contents")
     * $flatten = true   →  write every file directly into $target_dir root (for
     *                      images/docs where the importer only needs the filename)
     */
    private static function extract_zip($target_dir, $zip_path, $flatten) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new WP_Error('bad_zip', __('Could not open ZIP file. Please ensure it is a valid ZIP.', PI_NAME));
        }

        // Detect a single common root folder to strip
        // (handles the case where the user zipped the folder itself rather than its contents)
        $root_prefix = '';
        if (!$flatten && $zip->numFiles > 0) {
            $first = $zip->getNameIndex(0);
            $slash = strpos($first, '/');
            if ($slash !== false) {
                $candidate = substr($first, 0, $slash + 1);
                $all_share = true;
                for ($i = 1; $i < $zip->numFiles; $i++) {
                    if (strpos($zip->getNameIndex($i), $candidate) !== 0) {
                        $all_share = false;
                        break;
                    }
                }
                if ($all_share) {
                    $root_prefix = $candidate;
                }
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip directories and hidden/system files
            if (substr($name, -1) === '/' || basename($name)[0] === '.') {
                continue;
            }

            if ($flatten) {
                $filename = sanitize_file_name(basename($name));
                if (empty($filename)) continue;
                $target_path = $target_dir . '/' . $filename;
            } else {
                $rel = $root_prefix ? substr($name, strlen($root_prefix)) : $name;
                $rel = preg_replace('/\.\.+[\/\\\\]/', '', $rel);
                $rel = ltrim($rel, '/\\');
                if (empty($rel)) continue;
                $target_path = $target_dir . '/' . $rel;
                wp_mkdir_p(dirname($target_path));
            }

            // Stream each entry to avoid loading large files entirely into memory
            $fp_in = $zip->getStream($name);
            if ($fp_in === false) continue;

            $fp_out = fopen($target_path, 'wb');
            if ($fp_out === false) {
                fclose($fp_in);
                continue;
            }

            stream_copy_to_stream($fp_in, $fp_out);
            fclose($fp_in);
            fclose($fp_out);
        }

        $zip->close();
        return true;
    }

    /**
     * Phase 2: accept uploaded image/doc files, match them to pages that have
     * unresolved pending media, upload to the WP media library, and update content.
     */
    public static function handle_upload_media() {
        ob_start();
        $temp_dir = null;

        try {
            check_ajax_referer('pi_import_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('You do not have permission to perform this action.', PI_NAME)));
            }

            if (empty($_FILES['media_files']['name'][0])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('No files were uploaded.', PI_NAME)));
            }

            $upload_dir = wp_upload_dir();
            $temp_dir   = $upload_dir['basedir'] . '/pi-temp/' . uniqid();
            wp_mkdir_p($temp_dir);

            $files         = $_FILES['media_files'];
            $files_by_name = array();

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $filename                  = sanitize_file_name(basename($files['name'][$i]));
                $target                    = $temp_dir . '/' . $filename;
                move_uploaded_file($files['tmp_name'][$i], $target);
                $files_by_name[$filename]  = $target;
            }

            @set_time_limit(300);

            $results = PI_Importer::process_pending_media($files_by_name);

            self::cleanup_temp_dir($temp_dir);
            $temp_dir = null;

            ob_end_clean();

            wp_send_json_success(array(
                'message' => sprintf(
                    __('%d image(s) and %d document(s) processed across %d page(s).', PI_NAME),
                    $results['images_updated'],
                    $results['docs_updated'],
                    $results['pages_updated']
                ),
                'results' => $results,
            ));

        } catch (Exception $e) {
            if ($temp_dir) self::cleanup_temp_dir($temp_dir);
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            if ($temp_dir) self::cleanup_temp_dir($temp_dir);
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Handle refresh page dropdown AJAX request
     */
    public static function handle_refresh_page_dropdown() {
        // Verify nonce
        check_ajax_referer('pi_import_nonce', 'nonce');

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', PI_NAME )
            ));
        }

        // Get the dropdown options HTML
        ob_start();
        wp_dropdown_pages(array(
            'name' => 'page_parent',
            'id' => 'pi-page-parent',
            'show_option_none' => __('No Parent (Top Level)', PI_NAME ),
            'option_none_value' => '0',
            'hierarchical' => true,
            'selected' => 0,
            'echo' => 1
        ));
        $dropdown_html = ob_get_clean();

        // Extract just the options from the select element
        preg_match('/<select[^>]*>(.*?)<\/select>/s', $dropdown_html, $matches);
        $options_html = isset($matches[1]) ? $matches[1] : '';

        wp_send_json_success(array(
            'html' => $options_html
        ));
    }
}
