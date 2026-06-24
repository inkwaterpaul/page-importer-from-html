<?php
/**
 * Admin UI Class
 * Handles the admin interface for file selection and import
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PI_Admin_UI {

    /**
     * Render the main admin page
     */
    public static function render_page() {

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', PI_NAME));
        }

        ?>
        <div class="wrap pi-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="pi-container">
                <div class="pi-card">

                    <!-- Tab Navigation -->
                    <div class="pi-tabs">
                        <button type="button" class="pi-tab active" data-tab="file-upload">
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e('Upload Files', PI_NAME); ?>
                        </button>
                        <button type="button" class="pi-tab" data-tab="folder-import">
                            <span class="dashicons dashicons-category"></span>
                            <?php _e('Import Folder', PI_NAME); ?>
                        </button>
                    </div>

                    <!-- Tab: Upload Files -->
                    <div class="pi-tab-content" id="pi-tab-file-upload">
                        <h2><?php _e('Import HTML Files as Pages', PI_NAME); ?></h2>
                        <p class="description">
                            <?php _e('Select one or more HTML files to import as WordPress pages. The importer will extract:', PI_NAME); ?>
                        </p>
                        <ul class="pi-features">
                            <li><?php _e('Title from <code>&lt;h1&gt;</code> tag', PI_NAME); ?></li>
                            <li><?php _e('Content from <code>&lt;div class="page-content"&gt;</code>', PI_NAME); ?></li>
                        </ul>

                        <form id="pi-import-form" enctype="multipart/form-data">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="pi-files"><?php _e('Select HTML Files', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="file"
                                               id="pi-files"
                                               name="pi_files[]"
                                               accept=".html,.htm"
                                               multiple
                                               required>
                                        <p class="description">
                                            <?php _e('You can select multiple HTML files at once. Files are processed in batches of 10.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-page-status"><?php _e('Page Status', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <select id="pi-page-status" name="page_status">
                                            <option value="draft"><?php _e('Draft', PI_NAME); ?></option>
                                            <option value="publish"><?php _e('Published', PI_NAME); ?></option>
                                            <option value="pending"><?php _e('Pending Review', PI_NAME); ?></option>
                                        </select>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-page-parent"><?php _e('Parent Page', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        wp_dropdown_pages(array(
                                            'name'             => 'page_parent',
                                            'id'               => 'pi-page-parent',
                                            'show_option_none' => __('No Parent (Top Level)', PI_NAME),
                                            'option_none_value' => '0',
                                            'hierarchical'     => true,
                                            'selected'         => 0
                                        ));
                                        ?>
                                        <p class="description">
                                            <?php _e('Select a parent page to maintain URL structure.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-block-pattern"><?php _e('Block Pattern (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <textarea
                                            id="pi-block-pattern"
                                            name="block_pattern"
                                            rows="8"
                                            class="large-text code"><?php echo esc_textarea('<!-- wp:columns {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns alignwide" style="margin-top:var(--wp--preset--spacing--50);margin-bottom:var(--wp--preset--spacing--50)"><!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:octopus/page-navigation /--></div>
<!-- /wp:column -->

<!-- wp:column {"width":"75%"} -->
<div class="wp-block-column" style="flex-basis:75%">{content}</div>
<!-- /wp:column --></div>
<!-- /wp:columns -->'); ?></textarea>
                                        <p class="description">
                                            <?php _e('Enter a block pattern to wrap around the imported content. Use <code>{content}</code> as the placeholder.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-images-files"><?php _e('Images (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="file"
                                               id="pi-images-files"
                                               name="pi_images_files[]"
                                               multiple
                                               accept="image/*,.svg">
                                        <p class="description">
                                            <?php _e('Select the image files referenced in your HTML files. Only images actually linked in the HTML will be uploaded. The first image will be set as the featured image.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-document-files"><?php _e('Documents (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="file"
                                               id="pi-document-files"
                                               name="pi_document_files[]"
                                               multiple
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.csv">
                                        <p class="description">
                                            <?php _e('Select the document files (PDF, DOCX, XLS, etc.) referenced in your HTML files. Only documents actually linked in the HTML will be uploaded.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" class="button button-primary button-large" id="pi-import-btn">
                                    <span class="dashicons dashicons-upload"></span>
                                    <?php _e('Import Files', PI_NAME); ?>
                                </button>
                            </p>
                        </form>

                        <div id="pi-preview" class="pi-card pi-preview" style="display: none;">
                            <h3><?php _e('Preview - First File', PI_NAME); ?></h3>
                            <div id="pi-preview-content">
                                <div class="pi-preview-loading">
                                    <span class="spinner is-active"></span>
                                    <p><?php _e('Loading preview...', PI_NAME); ?></p>
                                </div>
                            </div>
                        </div>

                        <div id="pi-progress" class="pi-progress" style="display: none;">
                            <h3><?php _e('Import Progress', PI_NAME); ?></h3>
                            <div class="pi-progress-bar">
                                <div class="pi-progress-bar-fill" id="pi-progress-bar"></div>
                            </div>
                            <p class="pi-progress-text" id="pi-progress-text">0%</p>
                        </div>

                        <div id="pi-results" class="pi-results" style="display: none;">
                            <h3><?php _e('Import Results', PI_NAME); ?></h3>
                            <div id="pi-results-content"></div>
                        </div>
                    </div>

                    <!-- Tab: Import Folder -->
                    <div class="pi-tab-content" id="pi-tab-folder-import" style="display: none;">
                        <h2><?php _e('Import Folder of HTML Files', PI_NAME); ?></h2>
                        <p class="description">
                            <?php _e('Select a folder on the server containing HTML files. Subfolders will be created as parent pages, preserving the folder hierarchy. Images referenced by relative paths in each HTML file are automatically uploaded from their location on disk.', PI_NAME); ?>
                        </p>

                        <form id="pi-folder-import-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="pi-html-zip"><?php _e('HTML Files (ZIP)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="file"
                                               id="pi-html-zip"
                                               accept=".zip"
                                               required>
                                        <p class="description">
                                            <?php _e('ZIP your HTML folder and upload it here. Subfolders inside the ZIP become parent pages, preserving the hierarchy.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-folder-page-status"><?php _e('Page Status', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <select id="pi-folder-page-status" name="page_status">
                                            <option value="draft"><?php _e('Draft', PI_NAME); ?></option>
                                            <option value="publish"><?php _e('Published', PI_NAME); ?></option>
                                            <option value="pending"><?php _e('Pending Review', PI_NAME); ?></option>
                                        </select>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-folder-page-parent"><?php _e('Parent Page', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        wp_dropdown_pages(array(
                                            'name'             => 'page_parent',
                                            'id'               => 'pi-folder-page-parent',
                                            'show_option_none' => __('No Parent (Top Level)', PI_NAME),
                                            'option_none_value' => '0',
                                            'hierarchical'     => true,
                                            'selected'         => 0
                                        ));
                                        ?>
                                        <p class="description">
                                            <?php _e('All top-level files in the selected folder will be placed under this page.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-folder-block-pattern"><?php _e('Block Pattern (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <textarea
                                            id="pi-folder-block-pattern"
                                            name="block_pattern"
                                            rows="8"
                                            class="large-text code"><?php echo esc_textarea('<!-- wp:columns {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns alignwide" style="margin-top:var(--wp--preset--spacing--50);margin-bottom:var(--wp--preset--spacing--50)"><!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:octopus/page-navigation /--></div>
<!-- /wp:column -->

<!-- wp:column {"width":"75%"} -->
<div class="wp-block-column" style="flex-basis:75%">{content}</div>
<!-- /wp:column --></div>
<!-- /wp:columns -->'); ?></textarea>
                                        <p class="description">
                                            <?php _e('Wrap imported content in a block pattern. Use <code>{content}</code> as the placeholder.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                            </table>

                            <p class="submit">
                                <button type="submit" class="button button-primary button-large" id="pi-folder-import-btn">
                                    <span class="dashicons dashicons-category"></span>
                                    <?php _e('Import Folder', PI_NAME); ?>
                                </button>
                            </p>
                        </form>

                        <div id="pi-folder-progress" class="pi-progress" style="display: none;">
                            <h3><?php _e('Import Progress', PI_NAME); ?></h3>
                            <div class="pi-progress-bar">
                                <div class="pi-progress-bar-fill pi-progress-bar-fill-indeterminate"></div>
                            </div>
                            <p class="pi-progress-text"><?php _e('Scanning folder and importing pages...', PI_NAME); ?></p>
                        </div>

                        <div id="pi-folder-results" class="pi-results" style="display: none;">
                            <h3><?php _e('Import Results', PI_NAME); ?></h3>
                            <div id="pi-folder-results-content"></div>
                        </div>
                    </div>

                </div>

                <div class="pi-sidebar">
                    <div class="pi-card">
                        <h3><?php _e('File Upload', PI_NAME); ?></h3>
                        <ol>
                            <li><?php _e('Select one or more HTML files', PI_NAME); ?></li>
                            <li><?php _e('Choose page status and parent', PI_NAME); ?></li>
                            <li><?php _e('Click "Import Files"', PI_NAME); ?></li>
                        </ol>
                    </div>

                    <div class="pi-card">
                        <h3><?php _e('Folder Import', PI_NAME); ?></h3>
                        <ol>
                            <li><?php _e('ZIP your HTML folder and upload it', PI_NAME); ?></li>
                            <li><?php _e('Choose parent page and status', PI_NAME); ?></li>
                            <li><?php _e('Click "Import Folder"', PI_NAME); ?></li>
                            <li><?php _e('After import, select your images and documents folders to upload only the referenced files', PI_NAME); ?></li>
                        </ol>
                        <p class="description"><?php _e('Subfolders in the ZIP become parent pages. Images and documents are uploaded in a second step — only files actually referenced in the HTML.', PI_NAME); ?></p>
                    </div>

                    <div class="pi-card">
                        <h3><?php _e('HTML Requirements', PI_NAME); ?></h3>
                        <ul>
                            <li><?php _e('Must contain an <code>&lt;h1&gt;</code> for the title', PI_NAME); ?></li>
                            <li><?php _e('Content from <code>&lt;div class="page-content"&gt;</code>', PI_NAME); ?></li>
                            <li><?php _e('Import as drafts first to review', PI_NAME); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

     <?php
    }
}
