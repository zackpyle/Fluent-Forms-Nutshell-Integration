<?php
/**
 * Core functionality and hooks for the plugin.
 */
class FF_Nutshell_Core {
    
    // Singleton instance
    private static $instance = null;
    
    // Plugin components
    private $api = null;
    private $field_mapper = null;
    private $form_handler = null;
    private $admin = null;
    
    // Excluded Form IDs
    private $excluded_form_ids = [];
    
    // Logging enabled
    private static $logging_enabled = false;
    
    /**
     * Constructor
     */
    private function __construct() {
        // Load plugin settings
        $this->load_settings();
        
        // Initialize API handler
        $this->api = new FF_Nutshell_API();
        
        // Initialize field mapper
        $this->field_mapper = new FF_Nutshell_Field_Mapper();
        
        // Initialize form handler
        $this->form_handler = new FF_Nutshell_Form_Handler($this->api, $this->field_mapper);
        
        // Initialize admin
        $this->admin = new FF_Nutshell_Admin($this->api, $this->field_mapper);
        
        // Hook into Fluent Forms submission
        add_action('fluentform/submission_inserted', array($this->form_handler, 'process_submission'), 10, 3);
    }
    
    /**
     * Get singleton instance
     * 
     * @return FF_Nutshell_Core
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load plugin settings
     */
    private function load_settings() {
        $excluded_form_ids = get_option('ff_nutshell_excluded_form_ids', '');
        $this->excluded_form_ids = !empty($excluded_form_ids) ? array_map('trim', explode(',', $excluded_form_ids)) : [];
        self::$logging_enabled = get_option('ff_nutshell_enable_logging', false);
    }
    
    /**
     * Get form IDs that should NOT create Nutshell leads
     * 
     * @return array
     */
    public function get_excluded_form_ids() {
        return $this->excluded_form_ids;
    }
    
    /**
     * Check if logging is enabled
     * 
     * @return bool
     */
    public static function is_logging_enabled() {
        return self::$logging_enabled;
    }
    
    /**
     * Log message to WordPress debug.log if logging is enabled
     * 
     * @param string $message Message to log
     */
    public static function log($message) {
        if (!self::$logging_enabled) {
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG === true && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
            error_log('FF Nutshell - ' . $message);
        }
    }
}