<?php
/**
 * Plugin Name: Fluent Forms Nutshell Integration
 * Description: Integrates Fluent Forms with Nutshell CRM via REST API
 * Version: 1.9.0
 * Author: PYLE/DIGITAL
 * Text Domain: ff-nutshell
 * Requires PHP: 7.2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
// Define plugin constants
define('FF_NUTSHELL_VERSION', '1.9.0');
define('FF_NUTSHELL_PATH', plugin_dir_path(__FILE__));
define('FF_NUTSHELL_URL', plugin_dir_url(__FILE__));
define('FF_NUTSHELL_MIN_PHP_VERSION', '7.2');

/**
 * Check if the PHP version meets the minimum requirement
 *
 * @return bool True if meets requirements, false otherwise
 */
function ff_nutshell_check_php_version() {
    if (version_compare(PHP_VERSION, FF_NUTSHELL_MIN_PHP_VERSION, '<')) {
        return false;
    }
    return true;
}

/**
 * Display admin notice for PHP version requirement
 */
function ff_nutshell_php_version_notice() {
    $message = sprintf(
        /* translators: 1: Current PHP version, 2: Required PHP version */
        __('Error: Fluent Forms Nutshell Integration requires PHP version %2$s or greater. Your current PHP version is %1$s. Please upgrade PHP or contact your host for assistance.', 'ff-nutshell'),
        PHP_VERSION,
        FF_NUTSHELL_MIN_PHP_VERSION
    );
    
    echo '<div class="error"><p>' . $message . '</p></div>';
}

/**
 * Prevent activation on incompatible PHP versions
 */
function ff_nutshell_activation_check() {
    // Ensure user has appropriate permissions
    if (!current_user_can('activate_plugins')) {
        wp_die(__('You do not have sufficient permissions to activate this plugin.', 'ff-nutshell'));
    }
    
    if (!ff_nutshell_check_php_version()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                __('Fluent Forms Nutshell Integration requires PHP version %1$s or greater. Your server is currently running PHP version %2$s. Please upgrade PHP or contact your host for assistance.', 'ff-nutshell'),
                FF_NUTSHELL_MIN_PHP_VERSION,
                PHP_VERSION
            ),
            'Plugin Activation Error',
            array('back_link' => true)
        );
    }
}

/**
 * Plugin activation function
 * Sets up required directories and default options
 */
function ff_nutshell_activate() {
    // Create includes directory if it doesn't exist
    $includes_dir = plugin_dir_path(dirname(__FILE__));
    if (!file_exists($includes_dir)) {
        $success = wp_mkdir_p($includes_dir);
        if (!$success) {
            // Log error or show admin notice
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Failed to create required directories for Fluent Forms Nutshell Integration.</p></div>';
            });
        }
    }
    
    // Set default options if they don't exist
    if (!get_option('ff_nutshell_api_username')) {
        add_option('ff_nutshell_api_username', '');
    }
    
    if (!get_option('ff_nutshell_api_password')) {
        add_option('ff_nutshell_api_password', '');
    }
    
    if (!get_option('ff_nutshell_excluded_form_ids')) {
        add_option('ff_nutshell_excluded_form_ids', '');
    }
    
    if (!get_option('ff_nutshell_enable_logging')) {
        add_option('ff_nutshell_enable_logging', false);
    }

    // Initialize email exclusion patterns (newline-separated regex list)
    if (get_option('ff_nutshell_exclusion_email_patterns', null) === null) {
        add_option('ff_nutshell_exclusion_email_patterns', '');
    }
}

// Check PHP version before plugin activation
register_activation_hook(__FILE__, 'ff_nutshell_activation_check');

// Register the activation function
register_activation_hook(__FILE__, 'ff_nutshell_activate');

// Proceed with plugin loading only if PHP version is compatible
if (ff_nutshell_check_php_version()) {
    // Include required files
    require_once FF_NUTSHELL_PATH . 'includes/class-ff-nutshell-api.php';
    require_once FF_NUTSHELL_PATH . 'includes/class-ff-nutshell-field-mapper.php';
    require_once FF_NUTSHELL_PATH . 'includes/class-ff-nutshell-form-handler.php';
    require_once FF_NUTSHELL_PATH . 'includes/class-ff-nutshell-admin.php';
    require_once FF_NUTSHELL_PATH . 'includes/class-ff-nutshell-core.php';
    
    // Initialize the plugin
    function ff_nutshell_init() {
        // Check if Fluent Forms is active
        if (class_exists('FluentForm\App\Models\Form')) {
            // Load plugin
            FF_Nutshell_Core::get_instance();
        }
    }
    add_action('plugins_loaded', 'ff_nutshell_init');
} else {
    // Show admin notice if PHP version is incompatible
    add_action('admin_notices', 'ff_nutshell_php_version_notice');
}