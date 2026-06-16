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
                                            class="large-text code"
                                            placeholder='<!-- wp:group -->
<div class="wp-block-group">
{content}
</div>
<!-- /wp:group -->'></textarea>
                                        <p class="description">
                                            <?php _e('Enter a block pattern to wrap around the imported content. Use <code>{content}</code> as the placeholder.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-images-folder"><?php _e('Images Folder (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="pi-images-folder"
                                               name="images_folder"
                                               class="regular-text"
                                               value="<?php echo esc_attr(get_option('pi_images_folder', '')); ?>"
                                               placeholder="/path/to/images/folder">
                                        <button type="button" id="pi-browse-folder" class="button" data-browse-target="images"><?php _e('Browse', PI_NAME); ?></button>
                                        <p class="description">
                                            <?php _e('Folder containing images referenced in the HTML files. The first image will be set as the featured image.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-documents-folder"><?php _e('Documents Folder (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="pi-documents-folder"
                                               name="documents_folder"
                                               class="regular-text"
                                               value="<?php echo esc_attr(get_option('pi_documents_folder', '')); ?>"
                                               placeholder="/path/to/documents/folder">
                                        <button type="button" id="pi-browse-documents-folder" class="button" data-browse-target="documents"><?php _e('Browse', PI_NAME); ?></button>
                                        <p class="description">
                                            <?php _e('Folder containing documents (PDF, DOCX, XLS, etc.). Document URLs in the content will be updated.', PI_NAME); ?>
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
                                        <label for="pi-html-folder"><?php _e('HTML Files Folder', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="pi-html-folder"
                                               name="html_folder"
                                               class="regular-text"
                                               value="<?php echo esc_attr(get_option('pi_html_folder', '')); ?>"
                                               placeholder="/path/to/html/folder"
                                               required>
                                        <button type="button" class="button pi-browse-btn" data-browse-target="html-folder"><?php _e('Browse', PI_NAME); ?></button>
                                        <p class="description">
                                            <?php _e('The root folder to scan. HTML files in this folder become top-level pages (under the selected parent). Each subfolder becomes a parent page containing its HTML files.', PI_NAME); ?>
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
                                            class="large-text code"
                                            placeholder='<!-- wp:group -->
<div class="wp-block-group">
{content}
</div>
<!-- /wp:group -->'></textarea>
                                        <p class="description">
                                            <?php _e('Wrap imported content in a block pattern. Use <code>{content}</code> as the placeholder.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-folder-images-folder"><?php _e('Images Folder', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="pi-folder-images-folder"
                                               name="images_folder"
                                               class="regular-text"
                                               value="<?php echo esc_attr(get_option('pi_images_folder', '')); ?>"
                                               placeholder="/path/to/images/folder">
                                        <button type="button" class="button pi-browse-btn" data-browse-target="folder-images"><?php _e('Browse', PI_NAME); ?></button>
                                        <p class="description">
                                            <?php _e('The folder containing images referenced in the HTML files. You can specify multiple paths separated by commas. The first image in each page will be set as the featured image.', PI_NAME); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="pi-folder-documents-folder"><?php _e('Documents Folder (Optional)', PI_NAME); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="pi-folder-documents-folder"
                                               name="documents_folder"
                                               class="regular-text"
                                               value="<?php echo esc_attr(get_option('pi_documents_folder', '')); ?>"
                                               placeholder="/path/to/documents/folder">
                                        <button type="button" class="button pi-browse-btn" data-browse-target="folder-documents"><?php _e('Browse', PI_NAME); ?></button>
                                        <p class="description">
                                            <?php _e('Optional: folder containing documents (PDF, DOCX, etc.) to upload and link.', PI_NAME); ?>
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
                            <li><?php _e('Enter or browse to a folder path', PI_NAME); ?></li>
                            <li><?php _e('Choose parent page and status', PI_NAME); ?></li>
                            <li><?php _e('Click "Import Folder"', PI_NAME); ?></li>
                        </ol>
                        <p class="description"><?php _e('Subfolders become parent pages. Specify the images folder so referenced images are uploaded automatically.', PI_NAME); ?></p>
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

        <!-- Folder Browser Modal -->
        <div id="pi-folder-browser-modal" class="pi-modal" style="display: none;">
            <div class="pi-modal-content">
                <div class="pi-modal-header">
                    <h2 id="pi-modal-title"><?php _e('Select Folder', PI_NAME); ?></h2>
                    <button type="button" class="pi-modal-close">&times;</button>
                </div>
                <div class="pi-modal-body">
                    <div class="pi-folder-path">
                        <strong><?php _e('Current Path:', PI_NAME); ?></strong>
                        <span id="pi-current-path">/</span>
                    </div>
                    <div class="pi-folder-list" id="pi-folder-list">
                        <div class="pi-folder-loading">
                            <span class="spinner is-active"></span>
                            <p><?php _e('Loading folders...', PI_NAME); ?></p>
                        </div>
                    </div>
                </div>
                <div class="pi-modal-footer">
                    <button type="button" class="button button-secondary" id="pi-folder-cancel"><?php _e('Cancel', PI_NAME); ?></button>
                    <button type="button" class="button button-primary" id="pi-folder-select"><?php _e('Select This Folder', PI_NAME); ?></button>
                </div>
            </div>
        </div>
     <?php
    }
}
