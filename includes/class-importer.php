<?php
/**
 * Importer Class
 * Handles creating WordPress pages from extracted HTML content
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PI_Importer {

    /**
     * Import a single HTML file as a page
     *
     * @param string $file_path Path to HTML file
     * @param array $options Import options (page_status, page_author, page_category)
     * @return array|WP_Error Result array or WP_Error on failure
     */
    public static function import_file($file_path, $options = array()) {
        try {
            // Default options
            $defaults = array(
                'page_status' => 'draft',
                'block_pattern' => '',
                'page_parent' => 0,
                'documents_folder' => '',
                'source_file_path' => ''
            );

            $options = wp_parse_args($options, $defaults);

            // Extract content from HTML file
            // Don't convert to blocks if we're using a block pattern
            $use_blocks = empty($options['block_pattern']);
            $extracted = PI_Content_Extractor::extract_from_file($file_path, $use_blocks);

            if (is_wp_error($extracted)) {
                return $extracted;
            }

            // Apply block pattern if provided
            $content = $extracted['content'];
            if (!empty($options['block_pattern'])) {
                // Trim whitespace from content to avoid extra spacing
                $content = trim($content);
                // Replace placeholder with actual content
                $content = str_replace('{content}', $content, $options['block_pattern']);
            }

        // Prepare page data
        $page_data = array(
            'post_title'    => wp_strip_all_tags($extracted['title']),
            'post_content'  => $content,
            'post_status'   => $options['page_status'],
            'post_author'   => get_current_user_id(),
            'post_type'     => 'page'
        );

        // Add page parent if provided
        if (!empty($options['page_parent']) && $options['page_parent'] != '0') {
            $page_data['post_parent'] = (int) $options['page_parent'];
        }

        // Insert the page
        $page_id = wp_insert_post($page_data, true);

        if (is_wp_error($page_id)) {
            return $page_id;
        }

        // Store original filename as page meta
        update_post_meta($page_id, '_pi_original_filename', $extracted['file_name']);
        update_post_meta($page_id, '_pi_import_date', current_time('mysql'));

        // Handle featured image if available
        $featured_image_id = null;
        if (!empty($extracted['first_image'])) {
            if (!empty($options['images_folder'])) {
                $featured_image_id = self::set_featured_image($page_id, $extracted['first_image'], $options['images_folder']);
            } elseif (!empty($options['source_file_path'])) {
                // Resolve first image relative to the source HTML file
                $source_dir = dirname($options['source_file_path']);
                // extract_first_image returns a bare filename; look for it alongside the HTML file
                $resolved = realpath($source_dir . '/' . $extracted['first_image']);
                if ($resolved && is_file($resolved)) {
                    $attach_id = self::upload_image($resolved, $page_id);
                    if ($attach_id) {
                        set_post_thumbnail($page_id, $attach_id);
                        $featured_image_id = $attach_id;
                    }
                }
            }
        }

        // Handle all image URLs in content - either from images folder or resolved relative to source file
        if (!empty($options['images_folder']) || !empty($options['source_file_path'])) {
            self::update_image_urls($page_id, $options['images_folder'] ?? '', $options['source_file_path'] ?? '');
        }

        // Handle document URL replacements if documents folder is provided
        if (!empty($options['documents_folder'])) {
            self::update_document_urls($page_id, $options['documents_folder']);
        }

        return array(
            'success' => true,
            'page_id' => $page_id,
            'page_title' => $extracted['title'],
            'featured_image' => $featured_image_id ? 'Set' : 'Not found',
            'file_name' => $extracted['file_name'],
            'edit_url' => get_edit_post_link($page_id, 'raw'),
            'view_url' => get_permalink($page_id)
        );

        } catch (Exception $e) {
            return new WP_Error('import_exception', 'Exception: ' . $e->getMessage());
        } catch (Error $e) {
            return new WP_Error('import_error', 'Fatal error: ' . $e->getMessage());
        }
    }

    /**
     * Import multiple HTML files
     *
     * @param array $files Array of file paths
     * @param array $options Import options
     * @return array Results array with success/failure for each file
     */
    public static function import_multiple_files($files, $options = array()) {
        $results = array(
            'success' => array(),
            'failed' => array(),
            'total' => count($files),
            'success_count' => 0,
            'failed_count' => 0
        );

        foreach ($files as $file_path) {
            $result = self::import_file($file_path, $options);

            if (is_wp_error($result)) {
                $results['failed'][] = array(
                    'file' => basename($file_path),
                    'error' => $result->get_error_message()
                );
                $results['failed_count']++;
            } else {
                $results['success'][] = $result;
                $results['success_count']++;
            }
        }

        return $results;
    }

    /**
     * Check if a page with the same title already exists
     *
     * @param string $title Page title
     * @return bool True if exists, false otherwise
     */
    public static function page_exists($title) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT ID FROM $wpdb->pages WHERE page_title = %s AND page_type = 'page' AND page_status != 'trash' LIMIT 1",
            $title
        );

        $result = $wpdb->get_var($query);

        return !empty($result);
    }

    /**
     * Get import statistics
     *
     * @return array Statistics array
     */
    public static function get_import_stats() {
        global $wpdb;

        $total_imported = $wpdb->get_var(
            "SELECT COUNT(*) FROM $wpdb->pagemeta WHERE meta_key = '_pi_import_date'"
        );

        $recent_imports = $wpdb->get_results(
            "SELECT p.ID, p.page_title, pm.meta_value as import_date
            FROM $wpdb->pages p
            INNER JOIN $wpdb->pagemeta pm ON p.ID = pm.page_id
            WHERE pm.meta_key = '_pi_import_date'
            ORDER BY pm.meta_value DESC
            LIMIT 10"
        );

        return array(
            'total' => (int) $total_imported,
            'recent' => $recent_imports
        );
    }

    /**
     * Set featured image for a page
     *
     * @param int $page_id Page ID
     * @param string $image_filename Image filename
     * @param string $images_folder Path to images folder(s) - can be comma-separated
     * @return int|bool Attachment ID or false on failure
     */
    private static function set_featured_image($page_id, $image_filename, $images_folder) {
        // Split by comma if multiple folders provided
        $folders = array_map('trim', explode(',', $images_folder));

        $image_path = null;

        // Search for image in all specified folders
        foreach ($folders as $folder) {
            $folder = rtrim($folder, '/') . '/';
            $test_path = $folder . $image_filename;

            if (file_exists($test_path)) {
                $image_path = $test_path;
                break;
            }
        }

        // Check if file exists in any folder
        if (!$image_path) {
            return false;
        }

        // Check if this image is already in the media library
        $existing = self::get_attachment_by_filename($image_filename);
        if ($existing) {
            set_post_thumbnail($page_id, $existing);
            return $existing;
        }

        // Upload image to WordPress media library
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Get the file type
        $filetype = wp_check_filetype($image_filename);

        // Prepare upload
        $upload_dir = wp_upload_dir();
        $new_filename = wp_unique_filename($upload_dir['path'], $image_filename);
        $new_file_path = $upload_dir['path'] . '/' . $new_filename;

        // Copy file to uploads directory
        if (!copy($image_path, $new_file_path)) {
            return false;
        }

        // Prepare attachment data
        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . $new_filename,
            'page_mime_type' => $filetype['type'],
            'page_title' => preg_replace('/\.[^.]+$/', '', $image_filename),
            'page_content' => '',
            'page_status' => 'inherit'
        );

        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $new_file_path, $page_id);

        if (!is_wp_error($attach_id)) {
            // Generate metadata
            $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);

            // Set as featured image
            set_post_thumbnail($page_id, $attach_id);

            return $attach_id;
        }

        return false;
    }

    /**
     * Get attachment ID by filename
     *
     * @param string $filename Filename
     * @return int|bool Attachment ID or false
     */
    private static function get_attachment_by_filename($filename) {
        global $wpdb;

        $attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s",
            '%' . $wpdb->esc_like($filename)
        ));

        return $attachment ? (int) $attachment : false;
    }

    /**
     * Update image URLs in page content
     * Finds images and uploads them to media library, then updates URLs.
     * Resolves relative URLs against $source_file_path when available.
     *
     * @param int $page_id Page ID
     * @param string $images_folder Path to images folder(s) - can be comma-separated
     * @param string $source_file_path Absolute path to the source HTML file (enables relative URL resolution)
     * @return void
     */
    private static function update_image_urls($page_id, $images_folder = '', $source_file_path = '') {
        $page = get_post($page_id);
        if (!$page) {
            return;
        }

        $content = $page->post_content;

        // Split by comma if multiple folders provided
        $folders = !empty($images_folder) ? array_map('trim', explode(',', $images_folder)) : array();
        $source_dir = !empty($source_file_path) ? dirname($source_file_path) : '';

        // Find all image sources in content
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches);

        if (empty($matches[1])) {
            return;
        }

        $replacements = array();

        foreach ($matches[1] as $img_src) {
            // Skip absolute URLs
            if (preg_match('#^https?://#', $img_src)) {
                continue;
            }

            $img_src_decoded = urldecode($img_src);
            $image_path = null;

            // First: try resolving relative to the source HTML file's directory
            if (!empty($source_dir)) {
                $path_without_qs = preg_replace('/\?.*$/', '', $img_src_decoded);
                $resolved = realpath($source_dir . '/' . $path_without_qs);
                if ($resolved && file_exists($resolved) && is_file($resolved)) {
                    $image_path = $resolved;
                }
            }

            // Fallback: search in the specified images folder(s) by filename
            if (!$image_path && !empty($folders)) {
                $filename = preg_replace('/\?.*$/', '', basename($img_src_decoded));
                foreach ($folders as $folder) {
                    $folder = rtrim($folder, '/') . '/';
                    $test_path = $folder . $filename;
                    if (file_exists($test_path)) {
                        $image_path = $test_path;
                        break;
                    }
                }
            }

            if (!$image_path) {
                continue;
            }

            // Upload image to media library
            $attachment_id = self::upload_image($image_path, $page_id);

            if ($attachment_id) {
                $new_url = wp_get_attachment_url($attachment_id);
                $replacements[$img_src] = $new_url;
            }
        }

        // Replace URLs in content
        if (!empty($replacements)) {
            foreach ($replacements as $old_url => $new_url) {
                $content = str_replace('src="' . $old_url . '"', 'src="' . $new_url . '"', $content);
            }

            // Update page content
            wp_update_post(array(
                'ID' => $page_id,
                'post_content' => $content
            ));
        }
    }

    /**
     * Upload image to WordPress media library
     *
     * @param string $file_path Full path to image
     * @param int $page_id Parent page ID
     * @return int|bool Attachment ID or false on failure
     */
    private static function upload_image($file_path, $page_id) {
        $filename = basename($file_path);

        // Check if this image is already in the media library
        $existing = self::get_attachment_by_filename($filename);
        if ($existing) {
            return $existing;
        }

        // Upload image to WordPress media library
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Get the file type
        $filetype = wp_check_filetype($filename);

        // Prepare upload
        $upload_dir = wp_upload_dir();
        $new_filename = wp_unique_filename($upload_dir['path'], $filename);
        $new_file_path = $upload_dir['path'] . '/' . $new_filename;

        // Copy file to uploads directory
        if (!copy($file_path, $new_file_path)) {
            return false;
        }

        // Prepare attachment data
        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . $new_filename,
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $new_file_path, $page_id);

        if (!is_wp_error($attach_id)) {
            // Generate metadata
            $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);

            return $attach_id;
        }

        return false;
    }

    /**
     * Update document URLs in page content
     * Finds document links and uploads them to media library, then updates URLs
     *
     * @param int $page_id Page ID
     * @param string $documents_folder Path to documents folder
     * @return void
     */
    private static function update_document_urls($page_id, $documents_folder) {
        $page = get_post($page_id);
        if (!$page) {
            return;
        }

        $content = $page->post_content;
        $documents_folder = rtrim($documents_folder, '/') . '/';

        // Document extensions to look for
        $doc_extensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv');
        $pattern = '/href="([^"]+\.(' . implode('|', $doc_extensions) . '))"/i';

        // Find all document links
        preg_match_all($pattern, $content, $matches);

        if (empty($matches[1])) {
            return;
        }

        $replacements = array();

        foreach ($matches[1] as $doc_url) {
            // Extract filename from URL
            $filename = basename($doc_url);
            $doc_path = $documents_folder . $filename;

            // Check if file exists
            if (!file_exists($doc_path)) {
                continue;
            }

            // Upload document to media library
            $attachment_id = self::upload_document($doc_path, $page_id);

            if ($attachment_id) {
                $new_url = wp_get_attachment_url($attachment_id);
                $replacements[$doc_url] = $new_url;
            }
        }

        // Replace URLs in content
        if (!empty($replacements)) {
            foreach ($replacements as $old_url => $new_url) {
                $content = str_replace('href="' . $old_url . '"', 'href="' . $new_url . '"', $content);
            }

            // Update page content
            wp_update_post(array(
                'ID' => $page_id,
                'post_content' => $content
            ));
        }
    }

    /**
     * Import all HTML files from a folder, recursively maintaining folder hierarchy as page hierarchy.
     *
     * @param string $folder_path Absolute path to the root folder
     * @param array $options Import options
     * @return array|WP_Error Results array or WP_Error
     */
    public static function import_folder($folder_path, $options = array()) {
        $defaults = array(
            'page_status' => 'draft',
            'block_pattern' => '',
            'page_parent' => 0,
            'documents_folder' => '',
            'images_folder' => ''
        );
        $options = wp_parse_args($options, $defaults);

        $folder_path = realpath($folder_path);

        if (!$folder_path || !is_dir($folder_path) || !is_readable($folder_path)) {
            return new WP_Error('invalid_folder', __('Invalid or unreadable folder path', PI_NAME));
        }

        $results = array(
            'success' => array(),
            'failed' => array()
        );

        self::import_folder_recursive($folder_path, (int) $options['page_parent'], $options, $results, true);

        $results['total'] = count($results['success']) + count($results['failed']);
        $results['success_count'] = count($results['success']);
        $results['failed_count'] = count($results['failed']);

        return $results;
    }

    /**
     * Recursively import HTML files from a directory.
     *
     * If a folder contains index.html (or index.htm), that file is imported as the folder's own page
     * and everything else in the folder (files and subfolders) becomes a child of it.
     *
     * If no index.html exists:
     *   - Root folder: files are imported directly under $parent_id (no folder page created).
     *   - Subfolders: a placeholder page is created from the folder name.
     *
     * @param bool $is_root True only for the top-level selected folder.
     */
    private static function import_folder_recursive($folder_path, $parent_id, $options, &$results, $is_root = false) {
        $items = @scandir($folder_path);
        if ($items === false) {
            return;
        }

        $html_files = array();
        $index_file = null;
        $subdirs = array();

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if ($item[0] === '.') continue;

            $full_path = $folder_path . '/' . $item;

            if (is_file($full_path)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, array('html', 'htm'))) {
                    if (strtolower(pathinfo($item, PATHINFO_FILENAME)) === 'index') {
                        $index_file = $full_path;
                    } else {
                        $html_files[] = $full_path;
                    }
                }
            } elseif (is_dir($full_path) && is_readable($full_path)) {
                $subdirs[] = $full_path;
            }
        }

        // Determine the page that acts as parent for everything inside this folder.
        $this_folder_parent_id = $parent_id;

        if ($index_file !== null) {
            // index.html becomes this folder's own page.
            $index_options = $options;
            $index_options['page_parent'] = $parent_id;
            $index_options['source_file_path'] = $index_file;

            $result = self::import_file($index_file, $index_options);

            if (is_wp_error($result)) {
                $results['failed'][] = array(
                    'file' => $index_file,
                    'error' => $result->get_error_message()
                );
            } else {
                $results['success'][] = $result;
                PI_Logger::log_import($result['page_id'], basename($index_file), 'success');
                $this_folder_parent_id = $result['page_id'];
            }
        } elseif (!$is_root) {
            // Subfolder with no index.html: create a placeholder page from the folder name.
            $folder_page_id = self::get_or_create_folder_page(basename($folder_path), $parent_id, $options['page_status']);
            if (!is_wp_error($folder_page_id)) {
                $this_folder_parent_id = $folder_page_id;
            }
        }
        // Root folder with no index.html: files go directly under the selected parent ($parent_id unchanged).

        $file_options = $options;
        $file_options['page_parent'] = $this_folder_parent_id;

        foreach ($html_files as $file_path) {
            $file_options['source_file_path'] = $file_path;
            $result = self::import_file($file_path, $file_options);

            if (is_wp_error($result)) {
                $results['failed'][] = array(
                    'file' => $file_path,
                    'error' => $result->get_error_message()
                );
            } else {
                $results['success'][] = $result;
                PI_Logger::log_import($result['page_id'], basename($file_path), 'success');
            }
        }

        foreach ($subdirs as $subdir) {
            self::import_folder_recursive($subdir, $this_folder_parent_id, $options, $results, false);
        }
    }

    /**
     * Get an existing page for a folder, or create one.
     * Converts folder names like "my-folder" or "my_folder" to "My Folder".
     *
     * @param string $folder_name Folder basename
     * @param int $parent_id Parent page ID
     * @param string $status Post status for newly created pages
     * @return int|WP_Error Page ID or WP_Error
     */
    private static function get_or_create_folder_page($folder_name, $parent_id, $status) {
        $title = ucwords(str_replace(array('-', '_'), ' ', $folder_name));

        $existing = get_posts(array(
            'post_type'      => 'page',
            'post_parent'    => (int) $parent_id,
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'title'          => $title,
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ));

        if (!empty($existing)) {
            return $existing[0];
        }

        $page_data = array(
            'post_title'   => $title,
            'post_name'    => sanitize_title($folder_name),
            'post_content' => '',
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
            'post_type'    => 'page',
            'post_parent'  => (int) $parent_id
        );

        return wp_insert_post($page_data, true);
    }

    /**
     * Upload document to WordPress media library
     *
     * @param string $file_path Full path to document
     * @param int $page_id Parent page ID
     * @return int|bool Attachment ID or false on failure
     */
    private static function upload_document($file_path, $page_id) {
        $filename = basename($file_path);

        // Check if this document is already in the media library
        $existing = self::get_attachment_by_filename($filename);
        if ($existing) {
            return $existing;
        }

        // Upload document to WordPress media library
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Get the file type
        $filetype = wp_check_filetype($filename);

        // Prepare upload
        $upload_dir = wp_upload_dir();
        $new_filename = wp_unique_filename($upload_dir['path'], $filename);
        $new_file_path = $upload_dir['path'] . '/' . $new_filename;

        // Copy file to uploads directory
        if (!copy($file_path, $new_file_path)) {
            return false;
        }

        // Prepare attachment data
        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . $new_filename,
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $new_file_path, $page_id);

        if (!is_wp_error($attach_id)) {
            // Generate metadata (not needed for documents but good practice)
            $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);

            return $attach_id;
        }

        return false;
    }
}
