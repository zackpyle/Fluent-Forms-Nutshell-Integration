<?php
/**
 * Handles admin UI and settings pages
 */
class FF_Nutshell_Admin {
    
    //-------------------------------------------------------------------------
    // PROPERTIES & INITIALIZATION
    //-------------------------------------------------------------------------
    
    // API handler
    private $api;
    
    // Field mapper
    private $field_mapper;
    
    // Excluded Form IDs
    private $excluded_form_ids = [];
    
    /**
     * Constructor
     * 
     * @param FF_Nutshell_API $api API handler
     * @param FF_Nutshell_Field_Mapper $field_mapper Field mapper
     */
	public function __construct($api, $field_mapper) {
		$this->api = $api;
		$this->field_mapper = $field_mapper;
		
		// Load excluded form IDs
		$excluded_form_ids = get_option('ff_nutshell_excluded_form_ids', '');
		$this->excluded_form_ids = !empty($excluded_form_ids) ? array_map('trim', explode(',', $excluded_form_ids)) : [];

		// Add admin pages
		add_action('admin_menu', array($this, 'add_admin_menu'));

		// Register settings
		add_action('admin_init', array($this, 'register_settings'));

		// Register AJAX handlers
		add_action('wp_ajax_ff_nutshell_test_connection', array($this, 'ajax_test_connection'));
		add_action('wp_ajax_ff_nutshell_save_mapping', array($this, 'ajax_save_mapping'));
		add_action('wp_ajax_ff_nutshell_get_custom_fields', array($this, 'ajax_get_custom_fields'));
		add_action('wp_ajax_ff_nutshell_refresh_users_cache', array($this, 'ajax_refresh_users_cache'));
		add_action('wp_ajax_ff_nutshell_refresh_stagesets_cache', array($this, 'ajax_refresh_stagesets_cache'));
	}
    
    //-------------------------------------------------------------------------
    // ADMIN MENU REGISTRATION
    //-------------------------------------------------------------------------
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main settings page
        add_submenu_page(
            'fluent_forms',
            'Nutshell Integration',
            'Nutshell Integration',
            'manage_options',
            'ff-nutshell-settings',
            array($this, 'settings_page')
        );
        
        // Field mapping page (hidden from menu)
        add_submenu_page(
            null, // No parent, hidden page
            'Nutshell Field Mapping',
            'Nutshell Field Mapping',
            'manage_options',
            'ff-nutshell-field-mapping',
            array($this, 'field_mapping_page')
        );
    }
    
    //-------------------------------------------------------------------------
    // SETTINGS REGISTRATION
    //-------------------------------------------------------------------------
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'ff_nutshell_settings', 
            'ff_nutshell_api_username', 
            ['sanitize_callback' => [$this, 'sanitize_api_username']]
        );
        register_setting(
            'ff_nutshell_settings', 
            'ff_nutshell_api_password', 
            ['sanitize_callback' => [$this, 'sanitize_api_password']]
        );
        register_setting(
            'ff_nutshell_settings', 
            'ff_nutshell_excluded_form_ids', 
            ['sanitize_callback' => [$this, 'sanitize_excluded_form_ids']]
        );
        register_setting(
            'ff_nutshell_settings', 
            'ff_nutshell_enable_logging', 
            ['sanitize_callback' => [$this, 'sanitize_boolean_field']]
        );

        add_settings_section(
            'ff_nutshell_settings_section',
            'Nutshell API Settings',
            array($this, 'settings_section_callback'),
            'ff-nutshell-settings'
        );

        add_settings_field(
            'ff_nutshell_api_username',
            'Nutshell Email',
            array($this, 'username_field_callback'),
            'ff-nutshell-settings',
            'ff_nutshell_settings_section'
        );

        add_settings_field(
            'ff_nutshell_api_password',
            'Nutshell API Token',
            array($this, 'password_field_callback'),
            'ff-nutshell-settings',
            'ff_nutshell_settings_section'
        );

        add_settings_field(
            'ff_nutshell_enable_logging',
            'Enable Logging',
            array($this, 'logging_field_callback'),
            'ff-nutshell-settings',
            'ff_nutshell_settings_section'
        );
    }
    
    //-------------------------------------------------------------------------
    // SETTINGS FIELD CALLBACKS
    //-------------------------------------------------------------------------
    
    /**
     * Settings section description
     */
	public function settings_section_callback() {
		echo '<p>' . esc_html__('Enter your Nutshell API credentials and specify which Fluent Forms should create leads in Nutshell.', 'ff-nutshell') . '</p>';

		printf(
			'<p>%s <a href="%s" target="_blank">%s</a>.</p>',
			esc_html__('You need your Nutshell email address and an API token (not your regular password).', 'ff-nutshell'),
			esc_url('https://app.nutshell.com/auth/api-key-manage'),
			esc_html__('Get your API token here', 'ff-nutshell')
		);
	}
    
    /**
     * Username field
     */
    public function username_field_callback() {
        $value = get_option('ff_nutshell_api_username', '');
        echo '<input type="text" name="ff_nutshell_api_username" value="' . esc_attr($value) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__('Your Nutshell email address', 'ff-nutshell') . '</p>';

    }

    /**
     * Password field
     */
    public function password_field_callback() {
        $value = get_option('ff_nutshell_api_password', '');
        echo '<input type="password" name="ff_nutshell_api_password" value="' . esc_attr($value) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__('Your Nutshell API token', 'ff-nutshell') . '</p>';
    }
    
    /**
     * Logging field
     */
    public function logging_field_callback() {
        $value = get_option('ff_nutshell_enable_logging', false);
        echo '<input type="checkbox" name="ff_nutshell_enable_logging" value="1" ' . checked(1, $value, false) . '>';
		echo '<p class="description">' . esc_html__('Enable logging of API requests and responses for debugging (logs will be written to WordPress debug.log when WP_DEBUG and WP_DEBUG_LOG are enabled)', 'ff-nutshell') . '</p>';

    }
    
    //-------------------------------------------------------------------------
    // PAGE RENDERING
    //-------------------------------------------------------------------------
    
    /**
     * Settings page HTML
     */
    public function settings_page() {
        ?>
        <style>
            /* General Layout Styles */
            .ff-nutshell-section {
                margin-top: 30px;
                padding: 15px;
                background-color: #f8f8f8;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .ff-nutshell-section-sm {
                max-width: 600px;
            }
            .ff-nutshell-section-md {
                max-width: 800px;
            }
            .ff-nutshell-mt-10 {
                margin-top: 10px;
            }
            .ff-nutshell-mt-20 {
                margin-top: 20px;
            }
            .ff-nutshell-mb-20 {
                margin-bottom: 20px;
            }
            
            /* Grid Layout */
            .ff-nutshell-forms-grid {
                margin-top: 15px;
                display: grid;
                grid-template-columns: 80px minmax(200px, 1fr) 100px 180px;
                gap: 10px;
                align-items: center;
            }
            
            /* Table Headers */
            .ff-nutshell-grid-header {
                font-weight: bold;
                border-bottom: 1px solid #ddd;
                padding-bottom: 8px;
            }
            
            /* Grid Cells */
            .ff-nutshell-grid-cell {
                padding: 8px 0;
            }
            .ff-nutshell-grid-cell-span {
                grid-column: 1 / span 4;
                padding: 10px 0;
            }
            
            /* Status Colors */
            .ff-nutshell-status-included {
                color: #46b450;
                font-weight: bold;
            }
            .ff-nutshell-status-excluded {
                color: #dc3232;
                font-weight: bold;
            }
            
            /* Notification Areas */
            .ff-nutshell-notification {
                margin-top: 10px;
                padding: 10px;
                border-left: 4px solid #ccc;
                display: none;
            }
            
            /* Containers */
            .ff-nutshell-fields-container {
                margin-top: 10px;
            }
            .ff-nutshell-values-container {
                margin-left: 20px;
                margin-top: 5px;
            }
            
            /* Utility */
            .ff-nutshell-hidden {
                display: none;
            }
        </style>
        
        <div class="wrap">
            <h1><?php echo esc_html__('Fluent Forms - Nutshell Integration', 'ff-nutshell'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('ff_nutshell_settings');
                do_settings_sections('ff-nutshell-settings');
                submit_button('Save Settings');
                ?>
                
                <!-- API Connection Testing Section -->
                <div class="api-connection-section ff-nutshell-section ff-nutshell-section-sm ff-nutshell-mt-20">
                    <h3><?php echo esc_html__('Test API Connection', 'ff-nutshell'); ?></h3>
                    <p><?php echo esc_html__('After saving your credentials, you can test the connection to Nutshell:', 'ff-nutshell'); ?></p>
                    
                    <button type="button" class="button button-secondary" id="test-connection"><?php echo esc_html__('Test Nutshell Connection', 'ff-nutshell'); ?></button>
                    
                    <div id="connection-test-result" class="ff-nutshell-notification"><span id="connection-result"></span></div>
                </div>
            </form>
            
            <!-- Available Forms Section -->
            <div class="ff-nutshell-section ff-nutshell-section-md">
                <h3><?php echo esc_html__('Available Forms', 'ff-nutshell'); ?></h3>
                <p class="description"><?php echo esc_html__('This list shows all available Fluent Forms. Configure each form to set up how it integrates with Nutshell.', 'ff-nutshell'); ?></p>
                
                <div class="ff-nutshell-forms-grid">
                    <!-- Header row -->
                    <div class="ff-nutshell-grid-header"><?php echo esc_html__('Form ID', 'ff-nutshell'); ?></div>
                    <div class="ff-nutshell-grid-header"><?php echo esc_html__('Form Title', 'ff-nutshell'); ?></div>
                    <div class="ff-nutshell-grid-header"><?php echo esc_html__('Status', 'ff-nutshell'); ?></div>
                    <div class="ff-nutshell-grid-header"><?php echo esc_html__('Actions', 'ff-nutshell'); ?></div>
                    
                    <?php
                    // Get all Fluent Forms
                    if (class_exists('FluentForm\App\Models\Form')) {
                        $forms = wpFluent()->table('fluentform_forms')->get();
                        $excluded_ids = get_option('ff_nutshell_excluded_form_ids', '');
                        $excluded_ids = !empty($excluded_ids) ? array_map('trim', explode(',', $excluded_ids)) : [];

                        foreach ($forms as $form) {
                            $excluded = in_array($form->id, $excluded_ids);
                            $status_class = $excluded ? 'ff-nutshell-status-excluded' : 'ff-nutshell-status-included';
                            $status_text = $excluded ? __('Excluded', 'ff-nutshell') : __('Included', 'ff-nutshell');
                            
                            // Form ID cell
                            echo '<div class="ff-nutshell-grid-cell"><code>' . esc_html($form->id) . '</code></div>';
                            
                            // Form Title cell
                            echo '<div class="ff-nutshell-grid-cell">' . esc_html($form->title) . '</div>';
                            
                            // Status cell
                            echo '<div class="ff-nutshell-grid-cell ' . esc_attr($status_class) . '">' . esc_html($status_text) . '</div>';
                            
                            // Actions cell
                            echo '<div class="ff-nutshell-grid-cell"><a href="' . esc_url(admin_url('admin.php?page=ff-nutshell-field-mapping&form_id=' . $form->id)) . '" class="button button-small">' . esc_html__('Configure Form', 'ff-nutshell') . '</a></div>';
                        }
                    } else {
                        echo '<div class="ff-nutshell-grid-cell-span">' . esc_html__('Fluent Forms plugin not active or detected', 'ff-nutshell') . '</div>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- Pipeline Management & Reference Section -->
            <div class="ff-nutshell-section ff-nutshell-section-md">
                <h3><?php echo esc_html__('Nutshell Pipeline Management', 'ff-nutshell'); ?></h3>
                
                <!-- Pipeline Reference List -->
                <div class="ff-nutshell-mb-20">
                    <h4><?php echo esc_html__('Pipeline IDs Reference', 'ff-nutshell'); ?></h4>
                    <p class="description"><?php echo esc_html__('Use these IDs when creating form fields that will be mapped to Nutshell pipelines:', 'ff-nutshell'); ?></p>
                    
                    <?php
                    // Get the stagesets/pipelines
                    $stagesets = $this->api->get_stagesets_with_cache();

                    if (empty($stagesets)) {
                        echo '<p><em>' . esc_html__('No pipelines found. Please check your API connection or click "Refresh Pipelines Cache".', 'ff-nutshell') . '</em></p>';
                    } else {
                        echo '<table class="widefat" style="max-width: 600px;">';
                        echo '<thead>';
                        echo '<tr>';
                        echo '<th>' . esc_html__('Pipeline Name', 'ff-nutshell') . '</th>';
                        echo '<th>' . esc_html__('Pipeline ID', 'ff-nutshell') . '</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';

                        foreach ($stagesets as $stageset) {
                            // Get ID and name using different possible structures
                            $id = isset($stageset['id']) ? $stageset['id'] : (isset($stageset['_id']) ? $stageset['_id'] : '');
                            $name = isset($stageset['name']) ? $stageset['name'] : (isset($stageset['title']) ? $stageset['title'] : '');

                            if (!empty($id) && !empty($name)) {
                                echo '<tr>';
                                echo '<td>' . esc_html($name) . '</td>';
                                echo '<td><code>' . esc_html($id) . '</code></td>';
                                echo '</tr>';
                            }
                        }

                        echo '</tbody>';
                        echo '</table>';

                        echo '<p class="description ff-nutshell-mt-10">' . esc_html__('Note: These IDs should be used as values in dropdown fields that you intend to map to pipelines.', 'ff-nutshell') . '</p>';
                    }
                    ?>
                </div>
                
                <!-- Pipeline Cache Management -->
                <div class="ff-nutshell-mt-20">
                    <h4><?php echo esc_html__('Pipeline Cache Management', 'ff-nutshell'); ?></h4>
                    <p><?php echo esc_html__('Refresh the cache of Nutshell pipelines:', 'ff-nutshell'); ?></p>
                    
                    <button class="button button-secondary" id="refresh-stagesets-cache"><?php echo esc_html__('Refresh Pipelines Cache', 'ff-nutshell'); ?></button>
                    
                    <div id="pipelines-cache-result" class="ff-nutshell-notification"><span id="pipelines-result"></span></div>
                </div>
            </div>
            
            <!-- Users Cache Section -->
            <div class="cache-section ff-nutshell-section ff-nutshell-section-sm">
                <h3><?php echo esc_html__('Users Cache Management', 'ff-nutshell'); ?></h3>
                <p><?php echo esc_html__('Refresh the cache of Nutshell users:', 'ff-nutshell'); ?></p>
                
                <button class="button button-secondary" id="refresh-users-cache"><?php echo esc_html__('Refresh Users Cache', 'ff-nutshell'); ?></button>
                
                <div id="users-cache-result" class="ff-nutshell-notification"><span id="users-result"></span></div>
            </div>
            
            <script>
                jQuery(document).ready(function($) {
                    // Test API Connection
                    $('#test-connection').on('click', function(e) {
                        e.preventDefault();
                        
                        $('#connection-test-result').show().css('border-left-color', '#ccc');
                        $('#connection-result').text('<?php echo esc_js(__('Testing connection to Nutshell API...', 'ff-nutshell')); ?>');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ff_nutshell_test_connection',
								username: $('input[name="ff_nutshell_api_username"]').val(),
								password: $('input[name="ff_nutshell_api_password"]').val(),
								_ajax_nonce: '<?php echo esc_js(wp_create_nonce('ff_nutshell_test_connection')); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#connection-test-result').css('border-left-color', '#46b450');
                                    $('#connection-result').html('<strong><?php echo esc_js(__('Success!', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Connection to Nutshell API established.', 'ff-nutshell')); ?>');
                                } else {
                                    $('#connection-test-result').css('border-left-color', '#dc3232');
                                    $('#connection-result').html('<strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> ' + response.data.message);
                                }
                            },
                            error: function() {
                                $('#connection-test-result').css('border-left-color', '#dc3232');
                                $('#connection-result').html('<strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Could not process request. Please try again.', 'ff-nutshell')); ?>');
                            }
                        });
                    });

                    // Refresh Nutshell User Cache
                    $('#refresh-users-cache').on('click', function(e) {
                        e.preventDefault();

                        $('#users-cache-result').show().css('border-left-color', '#ccc');
                        $('#users-result').text('<?php echo esc_js(__('Refreshing Nutshell users cache...', 'ff-nutshell')); ?>');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ff_nutshell_refresh_users_cache',
								_ajax_nonce: '<?php echo esc_js(wp_create_nonce('ff_nutshell_refresh_users_cache')); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#users-cache-result').css('border-left-color', '#46b450');
                                    $('#users-result').html('<strong><?php echo esc_js(__('Success!', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Users cache refreshed.', 'ff-nutshell')); ?> ' + response.data.count + ' <?php echo esc_js(__('users cached.', 'ff-nutshell')); ?>');
                                } else {
                                    $('#users-cache-result').css('border-left-color', '#dc3232');
                                    $('#users-result').html('<strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> ' + response.data.message);
                                }
                            },
                            error: function() {
                                $('#users-cache-result').css('border-left-color', '#dc3232');
                                $('#users-result').html('<strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Could not process request. Please try again.', 'ff-nutshell')); ?>');
                            }
                        });
                    });
                    
                    // Refresh Pipeline Cache
                    $('#refresh-stagesets-cache').on('click', function(e) {
                        e.preventDefault();

                        $('#pipelines-cache-result').show().css('border-left-color', '#ccc');
                        $('#pipelines-result').text('<?php echo esc_js(__('Refreshing Nutshell pipelines cache...', 'ff-nutshell')); ?>');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ff_nutshell_refresh_stagesets_cache',
								_ajax_nonce: '<?php echo esc_js(wp_create_nonce('ff_nutshell_refresh_stagesets_cache')); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#pipelines-cache-result').css('border-left-color', '#46b450');
                                    $('#pipelines-result').html('<strong><?php echo esc_js(__('Success!', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Pipelines cache refreshed.', 'ff-nutshell')); ?> ' + response.data.count + ' <?php echo esc_js(__('pipelines cached.', 'ff-nutshell')); ?>');
                                } else {
                                    $('#pipelines-cache-result').css('border-left-color', '#dc3232');
                                    $('#pipelines-result').html('<strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> ' + response.data.message);
                                }
                            },
                            error: function() {
                                $('#pipelines-cache-result').css('border-left-color', '#dc3232');
                                $('#pipelines-result').html('<strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Could not process request. Please try again.', 'ff-nutshell')); ?>');
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * Field mapping page
     */
    public function field_mapping_page() {
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

        if (!$form_id) {
            echo '<div class="wrap"><h1>' . esc_html__('Error', 'ff-nutshell') . '</h1><p>' . esc_html__('No form ID specified.', 'ff-nutshell') . '</p></div>';
            return;
        }

        // Get form details
        $form = wpFluent()->table('fluentform_forms')->where('id', $form_id)->first();

        if (!$form) {
            echo '<div class="wrap"><h1>' . esc_html__('Error', 'ff-nutshell') . '</h1><p>' . esc_html__('Form not found.', 'ff-nutshell') . '</p></div>';
            return;
        }

        // Get form fields
        $form_fields = $this->field_mapper->get_form_fields($form_id);

        // Get current mappings for this specific form
        $current_mapping = $this->field_mapper->get_field_mappings($form_id);

        // Get custom fields from Nutshell - load them automatically
        $custom_fields = $this->api->get_lead_custom_fields();
        
        // Get Nutshell sources
        $sources = $this->api->get_sources();
        
        // Get Nutshell stagesets (pipelines)
        $stagesets = $this->api->get_stagesets_with_cache();

        ?>
        <div class="wrap">
            <h1><?php printf(
                esc_html__( 'Nutshell Integration for “%s”', 'ff-nutshell' ),
                esc_html( $form->title )
            ); ?></h1>
            <p class="description"><?php echo esc_html__('Configure how this form integrates with Nutshell CRM.', 'ff-nutshell'); ?></p>

            <div id="mapping-result" class="ff-nutshell-notification ff-nutshell-mb-20"></div>

            <form id="field-mapping-form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">

                <h2><?php echo esc_html__('Integration Status', 'ff-nutshell'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Include in Nutshell Integration', 'ff-nutshell'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span><?php echo esc_html__('Include in Nutshell Integration', 'ff-nutshell'); ?></span></legend>
                                <p>
                                    <label>
                                        <input type="radio" name="mapping[include_in_nutshell]" value="1" <?php checked(!in_array($form_id, $this->excluded_form_ids)); ?>>
                                        <?php echo esc_html__('Yes - Create Nutshell leads from this form', 'ff-nutshell'); ?>
                                    </label>
                                    <br>
                                    <label>
                                        <input type="radio" name="mapping[include_in_nutshell]" value="0" <?php checked(in_array($form_id, $this->excluded_form_ids)); ?>>
                                        <?php echo esc_html__('No - Do not create Nutshell leads from this form', 'ff-nutshell'); ?>
                                    </label>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <div id="nutshell-field-mapping" class="<?php echo in_array($form_id, $this->excluded_form_ids) ? 'ff-nutshell-hidden' : ''; ?>">
                    <h2><?php echo esc_html__('Lead Fields', 'ff-nutshell'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Lead Name/Description', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[description]">
                                    <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($form_fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected(isset($current_mapping['description']) && $current_mapping['description'] === $field_key); ?>>
                                            <?php echo esc_html($field_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h2><?php echo esc_html__('Contact Fields', 'ff-nutshell'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('First Name', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[contact_first_name]">
                                    <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($form_fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected(isset($current_mapping['contact_first_name']) && $current_mapping['contact_first_name'] === $field_key); ?>>
                                            <?php echo esc_html($field_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Last Name', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[contact_last_name]">
                                    <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($form_fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected(isset($current_mapping['contact_last_name']) && $current_mapping['contact_last_name'] === $field_key); ?>>
                                            <?php echo esc_html($field_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Email', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[contact_email]">
                                <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($form_fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected(isset($current_mapping['contact_email']) && $current_mapping['contact_email'] === $field_key); ?>>
                                            <?php echo esc_html($field_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Phone', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[contact_phone]">
                                    <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($form_fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected(isset($current_mapping['contact_phone']) && $current_mapping['contact_phone'] === $field_key); ?>>
                                            <?php echo esc_html($field_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h2><?php echo esc_html__('Account Fields', 'ff-nutshell'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Company/Account Name', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[account_name]">
                                    <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($form_fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected(isset($current_mapping['account_name']) && $current_mapping['account_name'] === $field_key); ?>>
                                            <?php echo esc_html($field_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <h2><?php echo esc_html__('Lead Notes', 'ff-nutshell'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Note Content', 'ff-nutshell'); ?></th>
                            <td>
                                <div class="note-mapping-options">
                                    <p>
                                        <input type="radio" name="mapping[note_type]" value="field" 
                                            id="note_type_field" 
                                            <?php checked(!isset($current_mapping['note_type']) || $current_mapping['note_type'] === 'field'); ?>>
                                        <label for="note_type_field"><?php echo esc_html__('Map to form field', 'ff-nutshell'); ?></label>
                                        <select name="mapping[note_field]" 
                                                class="form-field-select"
                                                id="note_field">
                                            <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                            <?php foreach ($form_fields as $field_key => $field_label): ?>
                                                <?php 
                                                $selected = isset($current_mapping['note_field']) && 
                                                          $current_mapping['note_field'] === $field_key &&
                                                          (!isset($current_mapping['note_type']) || 
                                                          $current_mapping['note_type'] === 'field');
                                                ?>
                                                <option value="<?php echo esc_attr($field_key); ?>" <?php selected($selected); ?>>
                                                    <?php echo esc_html($field_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>

                                    <p>
                                        <input type="radio" name="mapping[note_type]" value="template" 
                                            id="note_type_template"
                                            <?php checked(isset($current_mapping['note_type']) && $current_mapping['note_type'] === 'template'); ?>>
                                        <label for="note_type_template"><?php echo esc_html__('Use template with dynamic values', 'ff-nutshell'); ?></label>
                                        <div class="ff-nutshell-mt-10">
                                            <textarea name="mapping[note_template]" id="note_template" rows="5" cols="60" 
                                                <?php echo (isset($current_mapping['note_type']) && $current_mapping['note_type'] === 'template') ? '' : 'disabled'; ?>
                                                placeholder="<?php echo esc_attr__('Enter your note template here. Use {{field_name}} to insert values from form fields.', 'ff-nutshell'); ?>"
                                            ><?php echo isset($current_mapping['note_template']) ? esc_textarea($current_mapping['note_template']) : ''; ?></textarea>

                                            <div class="fields-reference-container ff-nutshell-mt-10">
                                                <h4><?php echo esc_html__('Available Fields for Templates', 'ff-nutshell'); ?></h4>
                                                <p class="description"><?php printf(
                                                    /* translators: %1$s and %3$s are code tags, %2$s is the field placeholder syntax, %4$s is an example */
                                                    esc_html__('Use %1$s%2$s%3$s to insert form field values. For example: %1$sNew lead from %4$s%3$s', 'ff-nutshell'),
                                                    '<code>',
                                                    '{{field_name}}',
                                                    '</code>',
                                                    '{{email}}'
                                                ); ?></p>

                                                <style>
                                                    .fields-reference-table {
                                                        width: 100%;
                                                        max-width: 500px; 
                                                        border-collapse: collapse;
                                                        margin-top: 10px;
                                                    }
                                                    .fields-reference-table th,
                                                    .fields-reference-table td {
                                                        padding: 8px 10px;
                                                        text-align: left;
                                                        font-weight: normal;
                                                    }
                                                    .fields-reference-table th {
                                                        font-weight: bold;
                                                    }
                                                </style>

                                                <table class="fields-reference-table widefat">
                                                    <thead>
                                                        <tr>
                                                            <th><?php echo esc_html__('Field Name', 'ff-nutshell'); ?></th>
                                                            <th><?php echo esc_html__('Template Code', 'ff-nutshell'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($form_fields as $field_key => $field_label): ?>
                                                            <tr>
                                                                <td><?php echo esc_html($field_label); ?></td>
                                                                <td><code>{{<?php echo esc_html($field_key); ?>}}</code></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <h2><?php echo esc_html__('Custom Fields', 'ff-nutshell'); ?></h2>
                    <?php if (empty($custom_fields)): ?>
                        <p><?php echo esc_html__('No custom fields were found in your Nutshell account or there was an error retrieving them. Please check your API credentials.', 'ff-nutshell'); ?></p>
                    <?php else: ?>
                        <table class="form-table" id="custom-fields-table">
                            <?php foreach ($custom_fields as $field): ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html($field['title']); ?> (<?php echo esc_html($field['type']); ?>)</th>
                                    <td>
                                        <?php if ($field['type'] === 'enum-multiple' && isset($field['enum']) && is_array($field['enum'])): ?>
                                            <!-- For enum-multiple types, provide options to map or use fixed values -->
                                            <div class="enum-multiple-mapping-options">
                                                <p>
                                                    <input type="radio" name="mapping[custom_field_type][<?php echo esc_attr($field['id']); ?>]" value="field" 
                                                        id="field_type_<?php echo esc_attr($field['id']); ?>_field" 
                                                        <?php checked(!isset($current_mapping['custom_field_type'][$field['id']]) || $current_mapping['custom_field_type'][$field['id']] === 'field'); ?>>
                                                    <label for="field_type_<?php echo esc_attr($field['id']); ?>_field">Map to form field:</label>
                                                    <select name="mapping[custom_fields][<?php echo esc_attr($field['id']); ?>]" 
                                                          class="form-field-select"
                                                          id="field_map_<?php echo esc_attr($field['id']); ?>">
                                                        <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                                        <?php foreach ($form_fields as $field_key => $field_label): ?>
                                                            <?php 
                                                            $selected = isset($current_mapping['custom_fields'][$field['id']]) && 
                                                                      $current_mapping['custom_fields'][$field['id']] === $field_key &&
                                                                      (!isset($current_mapping['custom_field_type'][$field['id']]) || 
                                                                      $current_mapping['custom_field_type'][$field['id']] === 'field');
                                                            ?>
                                                            <option value="<?php echo esc_attr($field_key); ?>" <?php selected($selected); ?>>
                                                                <?php echo esc_html($field_label); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </p>

                                                <p>
                                                    <input type="radio" name="mapping[custom_field_type][<?php echo esc_attr($field['id']); ?>]" value="fixed" 
                                                        id="field_type_<?php echo esc_attr($field['id']); ?>_fixed"
                                                        <?php checked(isset($current_mapping['custom_field_type'][$field['id']]) && $current_mapping['custom_field_type'][$field['id']] === 'fixed'); ?>>
                                                    <label for="field_type_<?php echo esc_attr($field['id']); ?>_fixed">Use fixed value(s):</label>
                                                    <div class="fixed-values-container ff-nutshell-values-container">
                                                        <?php foreach ($field['enum'] as $enum_value): ?>
                                                            <?php 
                                                            $checked = isset($current_mapping['custom_field_fixed'][$field['id']]) && 
                                                                      is_array($current_mapping['custom_field_fixed'][$field['id']]) &&
                                                                      in_array($enum_value, $current_mapping['custom_field_fixed'][$field['id']]);
                                                            ?>
                                                            <div>
                                                                <input type="checkbox" 
                                                                    name="mapping[custom_field_fixed][<?php echo esc_attr($field['id']); ?>][]" 
                                                                    value="<?php echo esc_attr($enum_value); ?>"
                                                                    id="fixed_value_<?php echo esc_attr($field['id'] . '_' . sanitize_title($enum_value)); ?>"
                                                                    <?php checked($checked); ?>>
                                                                <label for="fixed_value_<?php echo esc_attr($field['id'] . '_' . sanitize_title($enum_value)); ?>">
                                                                    <?php echo esc_html($enum_value); ?>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </p>
                                            </div>
                                        <?php else: ?>
                                            <!-- For regular fields, just show the form field dropdown -->
                                            <select name="mapping[custom_fields][<?php echo esc_attr($field['id']); ?>]">
                                                <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                                <?php foreach ($form_fields as $field_key => $field_label): ?>
                                                    <?php 
                                                    $selected = isset($current_mapping['custom_fields'][$field['id']]) && 
                                                              $current_mapping['custom_fields'][$field['id']] === $field_key;
                                                    ?>
                                                    <option value="<?php echo esc_attr($field_key); ?>" <?php selected($selected); ?>>
                                                        <?php echo esc_html($field_label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>

                                        <p class="description">
                                            <?php if ($field['type'] === 'enum-multiple'): ?>
                                                <?php echo esc_html__('Choose to map this field to a form field or select fixed values.', 'ff-nutshell'); ?>
                                            <?php else: ?>
                                                <?php echo esc_html__('Select a form field to map to this Nutshell custom field.', 'ff-nutshell'); ?>
                                            <?php endif; ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                    
                    <h2><?php echo esc_html__('Lead Source', 'ff-nutshell'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Source', 'ff-nutshell'); ?></th>
                            <td>
                                <select name="mapping[source_id]">
                                    <option value=""><?php echo esc_html__('-- No Source --', 'ff-nutshell'); ?></option>
                                    <?php foreach ($sources as $source): ?>
                                        <?php if (isset($source['id']) && isset($source['name'])): ?>
                                            <option value="<?php echo esc_attr($source['id']); ?>" <?php selected(isset($current_mapping['source_id']) && $current_mapping['source_id'] === $source['id']); ?>>
                                                <?php echo esc_html($source['name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php echo esc_html__('Select a source for leads created from this form. Leave blank if you don\'t want to assign a source.', 'ff-nutshell'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php echo esc_html__('Pipeline', 'ff-nutshell'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Pipeline', 'ff-nutshell'); ?></th>
                            <td>
                                <div class="stageset-mapping-options">
                                    <p>
                                        <input type="radio" name="mapping[stageset_type]" value="field" 
                                            id="stageset_type_field" 
                                            <?php checked(!isset($current_mapping['stageset_type']) || $current_mapping['stageset_type'] === 'field'); ?>>
                                        <label for="stageset_type_field"><?php echo esc_html__('Map to form field:', 'ff-nutshell'); ?></label>
                                        <select name="mapping[stageset_field]" 
                                                class="form-field-select"
                                                id="stageset_field">
                                            <option value=""><?php echo esc_html__('-- Select Form Field --', 'ff-nutshell'); ?></option>
                                            <?php foreach ($form_fields as $field_key => $field_label): ?>
                                                <?php 
                                                $selected = isset($current_mapping['stageset_field']) && 
                                                          $current_mapping['stageset_field'] === $field_key &&
                                                          (!isset($current_mapping['stageset_type']) || 
                                                          $current_mapping['stageset_type'] === 'field');
                                                ?>
                                                <option value="<?php echo esc_attr($field_key); ?>" <?php selected($selected); ?>>
                                                    <?php echo esc_html($field_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>

                                    <p>
                                        <input type="radio" name="mapping[stageset_type]" value="fixed" 
                                            id="stageset_type_fixed"
                                            <?php checked(isset($current_mapping['stageset_type']) && $current_mapping['stageset_type'] === 'fixed'); ?>>
                                        <label for="stageset_type_fixed">Use fixed value:</label>
                                        <select name="mapping[stageset_id]" 
                                                id="stageset_id"
                                                <?php echo (isset($current_mapping['stageset_type']) && $current_mapping['stageset_type'] === 'fixed') ? '' : 'disabled'; ?>>
                                            <option value=""><?php echo esc_html__('-- Select Pipeline --', 'ff-nutshell'); ?></option>
                                            <?php 
                                            if (empty($stagesets)) {
                                                echo '<option value="">No pipelines found - check API connection</option>';
                                            } else {
                                                foreach ($stagesets as $stageset): 
                                                    // Check for id and name using different possible key structures
                                                    $id = isset($stageset['id']) ? $stageset['id'] : (isset($stageset['_id']) ? $stageset['_id'] : '');
                                                    $name = isset($stageset['name']) ? $stageset['name'] : (isset($stageset['title']) ? $stageset['title'] : '');

                                                    if (!empty($id) && !empty($name)): 
                                                        ?>
                                                        <option value="<?php echo esc_attr($id); ?>" <?php selected(isset($current_mapping['stageset_id']) && $current_mapping['stageset_id'] === $id); ?>>
                                                            <?php echo esc_html($name); ?>
                                                        </option>
                                                        <?php 
                                                    endif;
                                                endforeach;
                                            }
                                            ?>
                                        </select>
                                    </p>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__('Select a pipeline for leads created from this form. You can either map to a form field or use a fixed value.', 'ff-nutshell'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div><!-- Close #nutshell-field-mapping -->

                <p class="submit">
                    <button type="submit" class="button button-primary" id="save-mapping"><?php echo esc_html__('Save Settings', 'ff-nutshell'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ff-nutshell-settings')); ?>" class="button button-secondary"><?php echo esc_html__('Back to Settings', 'ff-nutshell'); ?></a>
                </p>
            </form>

            <script>
                jQuery(document).ready(function($) {
                    // Function to handle the state of form fields
                    function updateFieldStates(radioInput) {
                        var fieldId = radioInput.attr('name').match(/\[(.*?)\]/)[1];
                        var isField = radioInput.val() === 'field';
                        var container = radioInput.closest('td');
                        // Disable/enable the form field select
                        container.find('.form-field-select').prop('disabled', !isField);
                        // Disable/enable the fixed value checkboxes
                        container.find('.fixed-values-container input[type="checkbox"]').prop('disabled', isField);
                    }
                    
                    // Set initial state for all enum-multiple fields
                    $('input[name^="mapping[custom_field_type]"]:checked').each(function() {
                        updateFieldStates($(this));
                    });
                    
                    // Handle radio button changes
                    $('input[name^="mapping[custom_field_type]"]').change(function() {
                        updateFieldStates($(this));
                    });
                    
                    // Function to handle note content state
                    function updateNoteFieldStates() {
                        var isField = $('input[name="mapping[note_type]"]:checked').val() === 'field';
                        $('#note_field').prop('disabled', !isField);
                        $('#note_template').prop('disabled', isField);

                        // Hide or show the fields reference section based on selection
                        if (isField) {
                            $('.fields-reference-container').hide();
                        } else {
                            $('.fields-reference-container').show();
                        }
                    }

                    // Set initial state for note fields
                    updateNoteFieldStates();

                    // Handle note radio button changes
                    $('input[name="mapping[note_type]"]').change(function() {
                        updateNoteFieldStates();
                    });

                    // Function to handle stageset selection states
                    function updateStagesetFieldStates() {
                        var isField = $('input[name="mapping[stageset_type]"]:checked').val() === 'field';
                        $('#stageset_field').prop('disabled', !isField);
                        $('#stageset_id').prop('disabled', isField);
                    }

                    // Set initial state for stageset selection
                    updateStagesetFieldStates();

                    // Handle stageset radio button changes
                    $('input[name="mapping[stageset_type]"]').change(function() {
                        updateStagesetFieldStates();
                    });
                    
                    // Handle include/exclude toggle
                    $('input[name="mapping[include_in_nutshell]"]').change(function() {
                        if ($(this).val() === "1") {
                            $('#nutshell-field-mapping').slideDown();
                        } else {
                            $('#nutshell-field-mapping').slideUp();
                        }
                    });

                    // Save mapping
                    $('#field-mapping-form').on('submit', function(e) {
                        e.preventDefault();

                        var formData = $(this).serialize();

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ff_nutshell_save_mapping',
                                mapping_data: formData,
								_ajax_nonce: '<?php echo esc_js(wp_create_nonce('ff_nutshell_save_mapping')); ?>'
                            },
                            success: function(response) {
                                $('#mapping-result').show();

                                if (response.success) {
                                    $('#mapping-result').css('border-left-color', '#46b450')
                                        .html('<p><strong><?php echo esc_js(__('Success!', 'ff-nutshell')); ?></strong> ' + response.data.message + '</p>');
                                } else {
                                    $('#mapping-result').css('border-left-color', '#dc3232')
                                        .html('<p><strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> ' + response.data.message + '</p>');
                                }

                                // Scroll to top of page
                                $('html, body').animate({ scrollTop: 0 }, 'fast');
                            },
                            error: function() {
                                $('#mapping-result').show()
                                    .css('border-left-color', '#dc3232')
                                    .html('<p><strong><?php echo esc_js(__('Error:', 'ff-nutshell')); ?></strong> <?php echo esc_js(__('Could not save settings. Please try again.', 'ff-nutshell')); ?></p>');

                                // Scroll to top of page
                                $('html, body').animate({ scrollTop: 0 }, 'fast');
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }
    
    //-------------------------------------------------------------------------
    // AJAX HANDLERS
    //-------------------------------------------------------------------------
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }
        
        // Verify nonce with strict checking
        if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_ajax_nonce']), 'ff_nutshell_test_connection')) {
            wp_send_json_error(['message' => 'Security check failed.']);
            return;
        }

        // Check if required parameters exist
        if (!isset($_POST['username']) || !isset($_POST['password'])) {
            wp_send_json_error(['message' => 'Missing required parameters.']);
            return;
        }

        // Get API credentials from form with sanitization
        $username = sanitize_email($_POST['username']);
        $password = sanitize_textarea_field($_POST['password']);

        // Validate input
        if (empty($username) || empty($password)) {
            wp_send_json_error(['message' => 'API credentials cannot be empty.']);
            return;
        }

        // Test connection
        $api = new FF_Nutshell_API($username, $password);
        $result = $api->test_connection();

        if ($result) {
            wp_send_json_success(['message' => 'Connection successful! Your API credentials are valid.']);
        } else {
            wp_send_json_error(['message' => 'Connection failed. Please check your API credentials and try again.']);
        }
    }
    
    /**
     * AJAX: Clear the Nutshell User Cache
     */
    public function ajax_refresh_users_cache() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }
        
        // Verify nonce with strict checking
        if (!isset($_POST['_ajax_nonce'])) {
            wp_send_json_error(['message' => 'Security token missing.']);
            return;
        }

        $nonce = sanitize_text_field($_POST['_ajax_nonce']);
        if (!wp_verify_nonce($nonce, 'ff_nutshell_refresh_users_cache')) {
            wp_send_json_error(['message' => 'Security check failed.']);
            return;
        }

        // Rate limiting to prevent abuse
        $last_refresh = get_transient('ff_nutshell_users_cache_last_refresh');
        if ($last_refresh && (time() - $last_refresh) < 30) { // 30 seconds rate limit
            wp_send_json_error(['message' => 'Please wait before refreshing again.']);
            return;
        }
        set_transient('ff_nutshell_users_cache_last_refresh', time(), 60);

        // Force refresh users cache
        $users = $this->api->get_users_with_cache(true);

        if (!empty($users)) {
            wp_send_json_success(['message' => 'Users cache refreshed successfully.', 'count' => count($users)]);
        } else {
            wp_send_json_error(['message' => 'Failed to refresh users cache. Please check your API connection.']);
        }
    }
    
    /**
     * AJAX: Clear the Nutshell Pipeline Cache
     */
    public function ajax_refresh_stagesets_cache() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }
		
		// Verify nonce with strict checking
		if (!isset($_POST['_ajax_nonce'])) {
			wp_send_json_error(['message' => 'Security token missing.']);
			return;
		}

		$nonce = sanitize_text_field($_POST['_ajax_nonce']);
		if (!wp_verify_nonce($nonce, 'ff_nutshell_refresh_stagesets_cache')) {
			wp_send_json_error(['message' => 'Security check failed.']);
			return;
		}

		// Rate limiting to prevent abuse
		$last_refresh = get_transient('ff_nutshell_stagesets_cache_last_refresh');
		if ($last_refresh && (time() - $last_refresh) < 30) { // 30 seconds rate limit
			wp_send_json_error(['message' => 'Please wait before refreshing again.']);
			return;
		}
		set_transient('ff_nutshell_stagesets_cache_last_refresh', time(), 60);

        // Force refresh stagesets cache
        $stagesets = $this->api->get_stagesets_with_cache(true);

        if (!empty($stagesets)) {
            wp_send_json_success(['message' => 'Pipelines cache refreshed successfully.', 'count' => count($stagesets)]);
        } else {
            wp_send_json_error(['message' => 'Failed to refresh pipelines cache. Please check your API connection.']);
        }
    }
    
    /**
     * AJAX: Save field mapping
     */
    public function ajax_save_mapping() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

		// Verify nonce with strict checking
		if (!isset($_POST['_ajax_nonce'])) {
			wp_send_json_error(['message' => 'Security token missing.']);
			return;
		}

		$nonce = sanitize_text_field($_POST['_ajax_nonce']);
		if (!wp_verify_nonce($nonce, 'ff_nutshell_save_mapping')) {
			wp_send_json_error(['message' => 'Security check failed.']);
			return;
		}

        // Validate mapping_data exists
        if (!isset($_POST['mapping_data']) || empty($_POST['mapping_data'])) {
            wp_send_json_error(['message' => 'No mapping data provided.']);
            return;
        }

        // Get the raw mapping data
        $raw_mapping_data = wp_unslash($_POST['mapping_data']);
        
        // Parse form data
        $mapping_data = [];
        parse_str($raw_mapping_data, $mapping_data);

        // Check for required data
        if (empty($mapping_data['mapping']) || empty($mapping_data['form_id'])) {
            wp_send_json_error(['message' => 'No mapping data or form ID provided.']);
            return;
        }

        // Validate and sanitize form_id
        $form_id = absint($mapping_data['form_id']);
        if ($form_id <= 0) {
            wp_send_json_error(['message' => 'Invalid form ID.']);
            return;
        }
        
        // Verify the form exists
        $form_exists = wpFluent()->table('fluentform_forms')->where('id', $form_id)->exists();
        if (!$form_exists) {
            wp_send_json_error(['message' => 'Form does not exist.']);
            return;
        }
        
        // Get current excluded form IDs
        $excluded_form_ids = get_option('ff_nutshell_excluded_form_ids', '');
        $excluded_form_ids = !empty($excluded_form_ids) ? array_map('trim', explode(',', $excluded_form_ids)) : [];
        
        // Update excluded form IDs based on the include_in_nutshell setting
        if (isset($mapping_data['mapping']['include_in_nutshell'])) {
            $include_value = sanitize_text_field($mapping_data['mapping']['include_in_nutshell']);
            
            if ($include_value === "0") {
                // Exclude this form
                if (!in_array($form_id, $excluded_form_ids)) {
                    $excluded_form_ids[] = $form_id;
                }
            } else {
                // Include this form (remove from excluded list if present)
                $excluded_form_ids = array_diff($excluded_form_ids, [$form_id]);
            }
            
            // Save updated excluded form IDs
            update_option('ff_nutshell_excluded_form_ids', implode(',', array_map('absint', $excluded_form_ids)));
            
            // Remove the include_in_nutshell from mapping data as it's not a field mapping
            unset($mapping_data['mapping']['include_in_nutshell']);
        }

        // Sanitize all mapping data recursively
        $sanitized_mapping = $this->sanitize_mapping_data($mapping_data['mapping']);

        // Save mapping for this specific form
        $this->field_mapper->save_field_mappings($form_id, $sanitized_mapping);

        wp_send_json_success(['message' => 'Settings saved successfully for form ID: ' . $form_id]);
    }
    
    /**
     * Recursively sanitize mapping data
     * 
     * @param array $data Mapping data to sanitize
     * @return array Sanitized mapping data
     */
    private function sanitize_mapping_data($data) {
		if (!is_array($data)) {
			return sanitize_text_field($data);
		}

		$sanitized = [];
		foreach ($data as $key => $value) {
			$sanitized_key = sanitize_text_field($key);

			if (is_array($value)) {
				$sanitized[$sanitized_key] = $this->sanitize_mapping_data($value);
			} else {
				// Special handling for textarea content that should preserve line breaks
				if ($sanitized_key === 'note_template') {
					$sanitized[$sanitized_key] = sanitize_textarea_field($value);
				} else {
					$sanitized[$sanitized_key] = sanitize_text_field($value);
				}
			}
		}

		return $sanitized;
	}
    
    /**
     * Sanitize API username (email)
     * 
     * @param string $input User input
     * @return string Sanitized email
     */
    public function sanitize_api_username($input) {
        // Sanitize as email since username is an email address
        $sanitized = sanitize_email($input);
        
        // Log if the sanitized value differs from input (potential attack)
        if ($sanitized !== $input) {
            FF_Nutshell_Core::log('Suspicious API username input sanitized');
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize API password/token
     * 
     * @param string $input User input
     * @return string Sanitized API token
     */
    public function sanitize_api_password($input) {
        // API tokens may contain special characters and punctuation that should be preserved
        // Use sanitize_textarea_field to remove HTML but preserve punctuation and other valid token characters
        $sanitized = sanitize_textarea_field($input);
        
        // Log if the sanitized value differs from input (potential attack)
        if ($sanitized !== $input) {
            FF_Nutshell_Core::log('Suspicious API token input sanitized');
        }
        
        return $sanitized;
    }
    
    
    /**
     * Sanitize excluded form IDs
     * 
     * @param string $input Comma-separated form IDs
     * @return string Sanitized comma-separated form IDs
     */
    public function sanitize_excluded_form_ids($input) {
        if (empty($input)) {
            return '';
        }
        
        // Split by comma
        $ids = explode(',', $input);
        
        // Sanitize each ID as an integer
        $sanitized_ids = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if (is_numeric($id)) {
                $sanitized_ids[] = absint($id);
            }
        }
        
        // Join back with commas
        return implode(',', $sanitized_ids);
    }
    
    /**
     * Sanitize boolean field
     * 
     * @param mixed $input User input
     * @return bool Sanitized boolean value
     */
    public function sanitize_boolean_field($input) {
        // Convert various inputs to true/false
        return (bool) $input;
    }

    /**
     * AJAX: Get custom fields
     */
    public function ajax_get_custom_fields() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }
		
		// Verify nonce
		if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'ff_nutshell_get_custom_fields')) {
			wp_send_json_error(['message' => 'Security check failed.']);
			return;
		}

        // Get custom fields from API
        $custom_fields = $this->api->get_lead_custom_fields();

        if (empty($custom_fields)) {
            wp_send_json_error(['message' => 'Could not retrieve custom fields. Please check your API credentials.']);
            return;
        }

        wp_send_json_success(['custom_fields' => $custom_fields]);
    }
}