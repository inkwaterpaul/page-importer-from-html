<?php
/**
 * Plugin Name: Page Importer from HTML Files
 * Plugin URI: https://inkandwater.co.uk/page-importer
 * Description: Import HTML files as WordPress pages. Extracts title from h1, content from page-content div, and date from small tag. Supports optional block patterns with {content} placeholder and parent page selection to maintain URL structure.
 * Version: 1.0.0
 * Author: Ink & Water
 * Author URI: https://inkandwater.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: html-page-importer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PI_VERSION', '1.0.0');
define('PI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PI_PLUGIN_FILE', __FILE__);

// Include required files
require_once PI_PLUGIN_DIR . 'includes/class-logger.php';
require_once PI_PLUGIN_DIR . 'includes/class-content-extractor.php';
require_once PI_PLUGIN_DIR . 'includes/class-importer.php';
require_once PI_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once PI_PLUGIN_DIR . 'includes/class-admin-ui.php';

/**
 * Main plugin class
 */
class HTML_Page_Importer {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Initialize AJAX handlers
        PI_AJAX_Handler::init();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('HTML Page Importer', 'html-page-importer'),
            __('HTML Page Importer', 'html-page-importer'),
            'manage_options',
            'html-page-importer',
            array('PI_Admin_UI', 'render_page'),
            'dashicons-upload',
            30
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin page
        if ('toplevel_page_html-page-importer' !== $hook) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'pi-admin-style',
            PI_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            PI_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'pi-admin-script',
            PI_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            PI_VERSION,
            true
        );

        // Localize script
        wp_localize_script('pi-admin-script', 'piAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pi_import_nonce'),
            'strings' => array(
                'processing' => __('Processing...', 'html-page-importer'),
                'success' => __('Import completed!', 'html-page-importer'),
                'error' => __('An error occurred.', 'html-page-importer'),
            )
        ));
    }
}

/**
 * Initialize the plugin
 */
function pi_init() {
    return HTML_Page_Importer::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'pi_init');
