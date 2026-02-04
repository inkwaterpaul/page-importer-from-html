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
            wp_die(__('You do not have sufficient permissions to access this page.', 'html-page-importer'));
        }

        ?>
        <div class="wrap pi-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="pi-container">
                <div class="pi-card">
                    <h2><?php _e('Import HTML Files as Pages', 'html-page-importer'); ?></h2>
                    <p class="description">
                        <?php _e('Select one or more HTML files to import as WordPress pages. The importer will extract:', 'html-page-importer'); ?>
                    </p>
                    <ul class="pi-features">
                        <li><?php _e('Title from <code>&lt;h1&gt;</code> tag', 'html-page-importer'); ?></li>
                        <li><?php _e('Content from <code>&lt;div class="page-content"&gt;</code>', 'html-page-importer'); ?></li>
                    </ul>

                    <form id="pi-import-form" enctype="multipart/form-data">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="pi-files"><?php _e('Select HTML Files', 'html-page-importer'); ?></label>
                                </th>
                                <td>
                                    <input type="file"
                                           id="pi-files"
                                           name="pi_files[]"
                                           accept=".html,.htm"
                                           multiple
                                           required>
                                    <p class="description">
                                        <?php _e('You can select multiple HTML files at once. Files are processed in batches of 10 to handle large imports.', 'html-page-importer'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="pi-page-status"><?php _e('Page Status', 'html-page-importer'); ?></label>
                                </th>
                                <td>
                                    <select id="pi-page-status" name="page_status">
                                        <option value="draft"><?php _e('Draft', 'html-page-importer'); ?></option>
                                        <option value="publish"><?php _e('Published', 'html-page-importer'); ?></option>
                                        <option value="pending"><?php _e('Pending Review', 'html-page-importer'); ?></option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="pi-page-parent"><?php _e('Parent Page', 'html-page-importer'); ?></label>
                                </th>
                                <td>
                                    <?php
                                    wp_dropdown_pages(array(
                                        'name' => 'page_parent',
                                        'id' => 'pi-page-parent',
                                        'show_option_none' => __('No Parent (Top Level)', 'html-page-importer'),
                                        'option_none_value' => '0',
                                        'hierarchical' => true,
                                        'selected' => 0
                                    ));
                                    ?>
                                    <p class="description">
                                        <?php _e('Select a parent page to maintain URL structure. Leave as "No Parent" for top-level pages.', 'html-page-importer'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="pi-block-pattern"><?php _e('Block Pattern (Optional)', 'html-page-importer'); ?></label>
                                </th>
                                <td>
                                    <textarea
                                        id="pi-block-pattern"
                                        name="block_pattern"
                                        rows="8"
                                        class="large-text code"
                                        placeholder='<!-- wp:group -->
<div class="wp-block-group">
{content}
</div>
<!-- /wp:group -->'></textarea>
                                    <p class="description">
                                        <?php _e('Enter a block pattern to wrap around the imported content. Use <code>{content}</code> as a placeholder for where the extracted content should be inserted.', 'html-page-importer'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="pi-images-folder"><?php _e('Images Folder (Optional)', 'html-page-importer'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="pi-images-folder"
                                           name="images_folder"
                                           class="regular-text"
                                           value="<?php echo esc_attr(get_option('pi_images_folder', '')); ?>"
                                           placeholder="/path/to/images/folder">
                                    <button type="button" id="pi-browse-folder" class="button"><?php _e('Browse', 'html-page-importer'); ?></button>
                                    <p class="description">
                                        <?php _e('Select the folder containing images. You can specify multiple paths separated by commas. The first image from each HTML file will be set as the featured image.', 'html-page-importer'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="pi-documents-folder"><?php _e('Documents Folder (Optional)', 'html-page-importer'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="pi-documents-folder"
                                           name="documents_folder"
                                           class="regular-text"
                                           value="<?php echo esc_attr(get_option('pi_documents_folder', '')); ?>"
                                           placeholder="/path/to/documents/folder">
                                    <button type="button" id="pi-browse-documents-folder" class="button"><?php _e('Browse', 'html-page-importer'); ?></button>
                                    <p class="description">
                                        <?php _e('Select the folder containing documents (PDF, DOCX, XLS, etc.). Document URLs in the content will be updated to point to uploaded files.', 'html-page-importer'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-large" id="pi-import-btn">
                                <span class="dashicons dashicons-upload"></span>
                                <?php _e('Import Files', 'html-page-importer'); ?>
                            </button>
                        </p>
                    </form>

                    <div id="pi-preview" class="pi-card pi-preview" style="display: none;">
                        <h3><?php _e('Preview - First File', 'html-page-importer'); ?></h3>
                        <div id="pi-preview-content">
                            <div class="pi-preview-loading">
                                <span class="spinner is-active"></span>
                                <p><?php _e('Loading preview...', 'html-page-importer'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div id="pi-progress" class="pi-progress" style="display: none;">
                        <h3><?php _e('Import Progress', 'html-page-importer'); ?></h3>
                        <div class="pi-progress-bar">
                            <div class="pi-progress-bar-fill" id="pi-progress-bar"></div>
                        </div>
                        <p class="pi-progress-text" id="pi-progress-text">0%</p>
                    </div>

                    <div id="pi-results" class="pi-results" style="display: none;">
                        <h3><?php _e('Import Results', 'html-page-importer'); ?></h3>
                        <div id="pi-results-content"></div>
                    </div>
                </div>

                <div class="pi-sidebar">
                    <div class="pi-card">
                        <h3><?php _e('Instructions', 'html-page-importer'); ?></h3>
                        <ol>
                            <li><?php _e('Click "Select HTML Files" to choose files from your computer', 'html-page-importer'); ?></li>
                            <li><?php _e('Configure page settings (status, author, category)', 'html-page-importer'); ?></li>
                            <li><?php _e('Click "Import Files" to start the import process', 'html-page-importer'); ?></li>
                            <li><?php _e('Wait for the import to complete', 'html-page-importer'); ?></li>
                        </ol>
                    </div>

                    <div class="pi-card">
                        <h3><?php _e('Tips', 'html-page-importer'); ?></h3>
                        <ul>
                            <li><?php _e('HTML files must contain an &lt;h1&gt; tag for the title', 'html-page-importer'); ?></li>
                            <li><?php _e('Content should be within a &lt;div class="page-content"&gt;', 'html-page-importer'); ?></li>
                            <li><?php _e('Import as drafts first to review before publishing', 'html-page-importer'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Folder Browser Modal -->
        <div id="pi-folder-browser-modal" class="pi-modal" style="display: none;">
            <div class="pi-modal-content">
                <div class="pi-modal-header">
                    <h2><?php _e('Select Images Folder', 'html-page-importer'); ?></h2>
                    <button type="button" class="pi-modal-close">&times;</button>
                </div>
                <div class="pi-modal-body">
                    <div class="pi-folder-path">
                        <strong><?php _e('Current Path:', 'html-page-importer'); ?></strong>
                        <span id="pi-current-path">/</span>
                    </div>
                    <div class="pi-folder-list" id="pi-folder-list">
                        <div class="pi-folder-loading">
                            <span class="spinner is-active"></span>
                            <p><?php _e('Loading folders...', 'html-page-importer'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="pi-modal-footer">
                    <button type="button" class="button button-secondary" id="pi-folder-cancel"><?php _e('Cancel', 'html-page-importer'); ?></button>
                    <button type="button" class="button button-primary" id="pi-folder-select"><?php _e('Select This Folder', 'html-page-importer'); ?></button>
                </div>
            </div>
        </div>
     <?php
    }
}
