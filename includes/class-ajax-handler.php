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
        add_action('wp_ajax_pi_browse_folders', array(__CLASS__, 'handle_browse_folders'));
        add_action('wp_ajax_pi_refresh_page_dropdown', array(__CLASS__, 'handle_refresh_page_dropdown'));
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
                    'message' => __('You do not have permission to perform this action.', 'html-page-importer')
                ));
            }

            // Check if files were uploaded
            if (empty($_FILES['pi_files'])) {
                ob_end_clean();
                wp_send_json_error(array(
                    'message' => __('No files were uploaded.', 'html-page-importer')
                ));
            }

        // Get import options
        $options = array(
            'page_status' => isset($_POST['page_status']) ? sanitize_text_field($_POST['page_status']) : 'draft',
            'images_folder' => isset($_POST['images_folder']) ? sanitize_text_field($_POST['images_folder']) : '',
            'documents_folder' => isset($_POST['documents_folder']) ? sanitize_text_field($_POST['documents_folder']) : '',
            'block_pattern' => isset($_POST['block_pattern']) ? wp_unslash($_POST['block_pattern']) : '',
            'page_parent' => isset($_POST['page_parent']) ? absint($_POST['page_parent']) : 0
        );

        // Save folder paths as options for next time
        if (!empty($options['images_folder'])) {
            update_option('pi_images_folder', $options['images_folder']);
        }
        if (!empty($options['documents_folder'])) {
            update_option('pi_documents_folder', $options['documents_folder']);
        }

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

        // Clean output buffer before sending JSON
        ob_end_clean();

        // Send response
        wp_send_json_success(array(
            'message' => sprintf(
                __('Import completed. %d succeeded, %d failed.', 'html-page-importer'),
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
                'message' => __('You do not have permission to perform this action.', 'html-page-importer')
            ));
        }

        // Get file index and options
        $file_index = isset($_POST['file_index']) ? absint($_POST['file_index']) : 0;
        $options = isset($_POST['options']) ? $_POST['options'] : array();

        // This would be used for processing individual files with progress updates
        // For now, we'll use the batch import method above

        wp_send_json_success(array(
            'message' => __('File processed successfully', 'html-page-importer')
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
                'message' => __('You do not have permission to perform this action.', 'html-page-importer')
            ));
        }

        // Check if file was uploaded
        if (empty($_FILES['preview_file'])) {
            wp_send_json_error(array(
                'message' => __('No file was uploaded for preview.', 'html-page-importer')
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
            'date' => $extracted['date'] ? date('F j, Y', strtotime($extracted['date'])) : __('Not found', 'html-page-importer'),
            'first_image' => !empty($extracted['first_image']) ? $extracted['first_image'] : __('Not found', 'html-page-importer'),
            'file_name' => $extracted['file_name']
        ));
    }

    /**
     * Handle folder browsing AJAX request
     */
    public static function handle_browse_folders() {
        // Verify nonce
        check_ajax_referer('pi_import_nonce', 'nonce');

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', 'html-page-importer')
            ));
        }

        // Get the requested path
        $path = isset($_POST['path']) ? $_POST['path'] : '';

        // Start from user's home directory or Downloads if no path specified
        if (empty($path)) {
            $path = $_SERVER['HOME'] ?? '/Users';
            // Try to start at Downloads folder
            $downloads = $path . '/Downloads';
            if (is_dir($downloads)) {
                $path = $downloads;
            }
        }

        // Security: Prevent directory traversal attacks
        $path = realpath($path);
        if ($path === false) {
            wp_send_json_error(array(
                'message' => __('Invalid directory path.', 'html-page-importer')
            ));
        }

        // Check if path is readable
        if (!is_dir($path) || !is_readable($path)) {
            wp_send_json_error(array(
                'message' => __('Cannot read directory.', 'html-page-importer')
            ));
        }

        $folders = array();
        $files = scandir($path);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $full_path = $path . '/' . $file;

            if (is_dir($full_path) && is_readable($full_path)) {
                $folders[] = array(
                    'name' => $file,
                    'path' => $full_path,
                    'has_subdirs' => self::has_subdirectories($full_path)
                );
            }
        }

        // Sort folders alphabetically
        usort($folders, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Get parent directory
        $parent = dirname($path);

        wp_send_json_success(array(
            'current_path' => $path,
            'parent_path' => $parent !== $path ? $parent : null,
            'folders' => $folders
        ));
    }

    /**
     * Check if directory has subdirectories
     */
    private static function has_subdirectories($path) {
        if (!is_dir($path) || !is_readable($path)) {
            return false;
        }

        $files = @scandir($path);
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($path . '/' . $file)) {
                return true;
            }
        }

        return false;
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
                'message' => __('You do not have permission to perform this action.', 'html-page-importer')
            ));
        }

        // Get the dropdown options HTML
        ob_start();
        wp_dropdown_pages(array(
            'name' => 'page_parent',
            'id' => 'pi-page-parent',
            'show_option_none' => __('No Parent (Top Level)', 'html-page-importer'),
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
