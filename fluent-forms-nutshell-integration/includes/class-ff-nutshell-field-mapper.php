<?php
/**
 * Handles mapping of Fluent Form fields to Nutshell CRM fields
 */
class FF_Nutshell_Field_Mapper {
    
    // Store custom field definitions from Nutshell
    private $custom_fields = [];
    
    // Store field mappings
    private $field_maps = [];
    
    // Current form ID
    private $current_form_id = 0;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Default is empty
    }
    
    /**
     * Load field mappings from WordPress options for a specific form
     * 
     * @param int $form_id Form ID
     */
    public function load_field_mappings($form_id) {
        $this->current_form_id = $form_id;
        
        // Get all mappings
        $all_mappings = get_option('ff_nutshell_field_mappings', []);
        
        // Get mapping for this specific form
        if (isset($all_mappings[$form_id])) {
            $this->field_maps[$form_id] = $all_mappings[$form_id];
        } else {
            // If no mapping exists for this form, use default
            $this->field_maps[$form_id] = $this->get_default_mapping();
        }
    }
    
    /**
     * Get default field mapping
     * 
     * @return array Default field mapping
     */
    private function get_default_mapping() {
        return [
            // Lead fields
            'description' => '',
            'name' => '',
            
            // Contact fields
            'contact_first_name' => '',
            'contact_last_name' => '',
            'contact_email' => '',
            'contact_phone' => '',
            
            // Account fields
            'account_name' => '',
            
            // Custom fields
            'custom_fields' => []
        ];
    }
    
    /**
     * Set Nutshell custom fields
     * 
     * @param array $custom_fields Custom fields
     */
    public function set_custom_fields($custom_fields) {
        $this->custom_fields = $custom_fields;
    }
    
    /**
     * Map form data to Nutshell lead format
     * 
     * @param array $form_data Form data
     * @param int $form_id Form ID
     * @return array Lead data
     */
    public function map_to_lead($form_data, $form_id) {
		// Make sure we have mappings for this form
		if (!isset($this->field_maps[$form_id])) {
			$this->load_field_mappings($form_id);
		}

		// Initialize lead data structure - removed 'name' field
		$lead = [
			'description' => '', // Company or lead name
			'customFields' => [],
			'links' => [
				'contacts' => [],
				'accounts' => [],
				'sources' => []
			]
		];

		// Map standard lead fields
		$this->map_standard_fields($lead, $form_data, $form_id);

		// Map custom fields
		$this->map_custom_fields($lead, $form_data, $form_id);

		// Add a source if specified for this form
		$mapping = $this->field_maps[$form_id];
		if (!empty($mapping['source_id'])) {
			$lead['links']['sources'] = [$mapping['source_id']];
		}

		return $lead;
	}
	
	/**
		 * Process a template string with dynamic values from form data
		 * 
		 * @param string $template Template string with {{field_name}} placeholders
		 * @param array $form_data Form data 
		 * @return string Processed template with values inserted
		 */
	public function process_template($template, $form_data) {
		// Sanitize template input
		$template = sanitize_text_field($template);

		// Find all {{field_name}} patterns in the template
		preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
		if (empty($matches[1])) {
			return esc_html($template);
		}

		$processed_template = $template;
		
		// Replace each placeholder with its value
		foreach ($matches[1] as $field_name) {
			// Validate field name format to prevent injection attacks
			if (!preg_match('/^[a-zA-Z0-9_.\[\]]+$/', $field_name)) {
				continue; // Skip invalid field names
			}

			$field_name = sanitize_text_field($field_name);
			$field_value = $this->get_field_value($form_data, $field_name);

			// Handle array values (like checkboxes) by converting to comma-separated string
			if (is_array($field_value)) {
				$field_value = implode(', ', array_map('sanitize_text_field', $field_value));
			} else {
				$field_value = sanitize_text_field($field_value);
			}

			// Use preg_quote to escape special characters in the field name
			$pattern = '/\{\{' . preg_quote($field_name, '/') . '\}\}/';
			$processed_template = preg_replace($pattern, $field_value, $processed_template);
		}

		return $processed_template;
	}
    
	/**
	 * Map standard lead fields
	 * 
	 * @param array $lead Lead data (passed by reference)
	 * @param array $form_data Form data
	 * @param int $form_id Form ID
	 */
	private function map_standard_fields(&$lead, $form_data, $form_id) {
		$mapping = $this->field_maps[$form_id];

		// Add debugging
		error_log('FF Nutshell - Mapping standard fields for form ID: ' . $form_id);
		error_log('FF Nutshell - Standard field mapping: ' . json_encode([
			'description' => isset($mapping['description']) ? $mapping['description'] : 'not set'
		]));

		// Handle the description field
		$description_set = false;

		// Special handling for 'names' field as the lead description
		if (!empty($mapping['description'])) {
			if ($mapping['description'] === 'names' && isset($form_data['names']) && is_array($form_data['names'])) {
				// If mapped to 'names' array
				$first = isset($form_data['names']['first_name']) ? $form_data['names']['first_name'] : '';
				$last = isset($form_data['names']['last_name']) ? $form_data['names']['last_name'] : '';

				if (!empty($first) || !empty($last)) {
					$lead['description'] = trim($first . ' ' . $last);
					error_log('FF Nutshell - Created description from names array: ' . $lead['description']);
					$description_set = true;
				}
			} else {
				// Try regular field value extraction
				$description_value = $this->get_field_value($form_data, $mapping['description']);
				if (!empty($description_value)) {
					$lead['description'] = sanitize_text_field($description_value);
					error_log('FF Nutshell - Set description from field: ' . $lead['description']);
					$description_set = true;
				}
			}
		}

		// If description isn't set, try to use fallbacks
		if (!$description_set) {
			// Try to use contact name as fallback
			if (isset($form_data['names']) && is_array($form_data['names'])) {
				$first = isset($form_data['names']['first_name']) ? $form_data['names']['first_name'] : '';
				$last = isset($form_data['names']['last_name']) ? $form_data['names']['last_name'] : '';

				if (!empty($first) || !empty($last)) {
					$lead['description'] = 'Lead from ' . trim($first . ' ' . $last);
					error_log('FF Nutshell - Set description from contact name fallback: ' . $lead['description']);
					return;
				}
			}

			// Try to use email as fallback
			if (isset($form_data['email'])) {
				$lead['description'] = 'Lead from ' . sanitize_text_field($form_data['email']);
				error_log('FF Nutshell - Set description from email fallback: ' . $lead['description']);
			} else {
				$default_text = 'Lead from website form #' . time();
				$lead['description'] = $default_text;
				error_log('FF Nutshell - Set description from generic fallback: ' . $lead['description']);
			}
		}
	}
    
	/**
	 * Map custom fields
	 * 
	 * @param array $lead Lead data (passed by reference)
	 * @param array $form_data Form data
	 * @param int $form_id Form ID
	 */
	private function map_custom_fields(&$lead, $form_data, $form_id) {
		$mapping = $this->field_maps[$form_id];

		if (!isset($mapping['custom_fields']) || !is_array($mapping['custom_fields'])) {
			return;
		}

		foreach ($mapping['custom_fields'] as $nutshell_field => $form_field) {
			// Check if this is a fixed value field (for enum-multiple)
			if (isset($mapping['custom_field_type'][$nutshell_field]) && 
				$mapping['custom_field_type'][$nutshell_field] === 'fixed' &&
				isset($mapping['custom_field_fixed'][$nutshell_field])) {

				// Use the fixed values directly
				$lead['customFields'][$nutshell_field] = $mapping['custom_field_fixed'][$nutshell_field];
			} 
			// Otherwise map from form field if a mapping exists
			elseif (!empty($form_field)) {
				// Get field value, supporting nested fields
				$field_value = $this->get_field_value($form_data, $form_field);

				// Only add if the field has a value
				if (!empty($field_value)) {
					// Handle different field types here
					// For enum-multiple, if the form field contains a comma-separated list, split it
					if (isset($this->custom_fields) && is_array($this->custom_fields)) {
						foreach ($this->custom_fields as $custom_field) {
							if ($custom_field['id'] === $nutshell_field && $custom_field['type'] === 'enum-multiple') {
								// This is an enum-multiple field, check if the form data has multiple values
								if (strpos($field_value, ',') !== false) {
									// Split by comma and trim whitespace
									$values = array_map('trim', explode(',', $field_value));
									$lead['customFields'][$nutshell_field] = $values;
									continue 2; // Skip to the next iteration of the outer loop
								}
							}
						}
					}

					// Standard field handling
					$lead['customFields'][$nutshell_field] = sanitize_text_field($field_value);
				}
			}
		}
	}
	
	/**
	 * Format phone number for Nutshell API
	 * 
	 * @param string $phone_number Raw phone number
	 * @return array Formatted phone object
	 */
	private function format_phone_number($phone_number) {
		// Remove all non-digit characters to get clean digits
		$clean_number = preg_replace('/[^0-9]/', '', $phone_number);

		// Default to US country code if not specified
		$country_code = '1';

		// If the number starts with a plus sign, it might have a country code
		if (substr($phone_number, 0, 1) === '+') {
			if (strlen($clean_number) > 10) {
				// Extract country code (assuming it's at the beginning and 1-3 digits)
				$country_code = substr($clean_number, 0, strlen($clean_number) - 10);
				$clean_number = substr($clean_number, strlen($country_code)); // Remove country code from number
			}
		}

		// Format the number for display
		$formatted_number = '';
		if (strlen($clean_number) === 10) {
			// US format: XXX-XXX-XXXX
			$formatted_number = substr($clean_number, 0, 3) . '-' . 
							   substr($clean_number, 3, 3) . '-' . 
							   substr($clean_number, 6);
		} else {
			// Just use the clean number if it's not a 10-digit US number
			$formatted_number = $clean_number;
		}

		error_log('FF Nutshell - Formatting phone: ' . $phone_number . ' to ' . $formatted_number);

		// Return the phone object structured exactly as in the documentation
		return [
			"isPrimary" => true,
			"name" => "phone",
			"value" => [
				"countryCode" => $country_code,
				"number" => $clean_number,
				"numberFormatted" => $formatted_number,
				"E164" => "+{$country_code}{$clean_number}",
				"countryCodeAndNumber" => "+{$country_code} {$formatted_number}"
			]
		];
	}
    
	/**
	 * Get contact data from form submission with updated phone handling
	 * 
	 * @param array $form_data Form data
	 * @param int $form_id Form ID (optional)
	 * @return array Contact data
	 */
	public function get_contact_data($form_data, $form_id = null) {
		// If form_id is provided but not loaded yet, load it
		if ($form_id !== null && (!isset($this->field_maps[$form_id]) || $this->current_form_id !== $form_id)) {
			$this->load_field_mappings($form_id);
		}

		// Use current form ID if none provided
		$form_id = $form_id !== null ? $form_id : $this->current_form_id;

		// If we still don't have mappings, return empty data
		if (!isset($this->field_maps[$form_id])) {
			return ['name' => '', 'emails' => [], 'phones' => []];
		}

		$mapping = $this->field_maps[$form_id];
		$contact = [
			'name' => '',
			'emails' => [],
			'phones' => []
		];

		// First name and last name handling
		if (!empty($mapping['contact_first_name'])) {
			$first_name = $this->get_field_value($form_data, $mapping['contact_first_name']);

			$last_name = '';
			if (!empty($mapping['contact_last_name'])) {
				$last_name = $this->get_field_value($form_data, $mapping['contact_last_name']);
			}

			if (!empty($first_name) || !empty($last_name)) {
				$contact['name'] = trim($first_name . ' ' . $last_name);
			}
		}

		// Email handling - FIXED STRUCTURE
		if (!empty($mapping['contact_email'])) {
			$email = $this->get_field_value($form_data, $mapping['contact_email']);
			if (!empty($email)) {
				$contact['emails'] = [
					[
						'name' => 'personal',
						'value' => sanitize_email($email),
						'isPrimary' => true
					]
				];

				// Debug the email structure
				error_log('FF Nutshell - Formatted contact email data: ' . json_encode($contact['emails']));
			}
		}

		// Phone with updated format
		if (!empty($mapping['contact_phone'])) {
			$phone = $this->get_field_value($form_data, $mapping['contact_phone']);
			if (!empty($phone)) {
				$contact['phones'] = [$this->format_phone_number($phone)];

				// Debug the phone structure
				error_log('FF Nutshell - Formatted contact phone data: ' . json_encode($contact['phones']));
			}
		}

		return $contact;
	}

	/**
	 * Helper function to get field value, supporting nested fields with both dot and bracket notation
	 * 
	 * @param array $form_data Form data
	 * @param string $field_name Field name (can be nested like 'names.first_name' or 'names[first_name]')
	 * @return string Field value or empty string if not found
	 */
	public function get_field_value($form_data, $field_name) {
		// Sanitize and validate field name to prevent injection attacks
		$field_name = sanitize_text_field($field_name);
		
		// Validate field name format
		if (!preg_match('/^[a-zA-Z0-9_\.\[\]]+$/', $field_name)) {
			FF_Nutshell_Core::log('Invalid field name format: ' . esc_html($field_name));
			return '';
		}

		// Handle direct field access
		if (isset($form_data[$field_name])) {
			return $form_data[$field_name];
		}

		// Handle bracket notation like names[first_name]
		if (strpos($field_name, '[') !== false && strpos($field_name, ']') !== false) {
			$matches = [];
			preg_match('/^([^\[]+)\[([^\]]+)\]$/', $field_name, $matches);
			
			if (count($matches) === 3) {
				$parent = sanitize_text_field($matches[1]);
				$child = sanitize_text_field($matches[2]);
				
				if (isset($form_data[$parent]) && is_array($form_data[$parent]) && isset($form_data[$parent][$child])) {
					return $form_data[$parent][$child];
				}
			}
		}

		// Handle dot notation like names.first_name
		if (strpos($field_name, '.') !== false) {
			$parts = explode('.', $field_name);
			
			if (count($parts) === 2) {
				$parent = sanitize_text_field($parts[0]);
				$child = sanitize_text_field($parts[1]);
				
				// Validate parts to prevent injection
				if (!preg_match('/^[a-zA-Z0-9_]+$/', $parent) || !preg_match('/^[a-zA-Z0-9_]+$/', $child)) {
					FF_Nutshell_Core::log('Invalid field name parts: ' . esc_html($parent) . '.' . esc_html($child));
					return '';
				}
				
				if (isset($form_data[$parent]) && is_array($form_data[$parent]) && isset($form_data[$parent][$child])) {
					return $form_data[$parent][$child];
				}
			}
		}

		// Try to find a field that ends with the requested name (for nested fields)
		// Use a limited depth search to prevent potential DoS
		$result = $this->search_nested_field($form_data, $field_name, 0, 3);
		if ($result !== null) {
			return $result;
		}

		// If we get here, the field wasn't found
		return '';
	}

	/**
	 * Search for a field in nested data with depth limit
	 * 
	 * @param array $data Data to search in
	 * @param string $field_name Field name to search for
	 * @param int $current_depth Current recursion depth
	 * @param int $max_depth Maximum recursion depth
	 * @return mixed|null Field value if found, null otherwise
	 */
	private function search_nested_field($data, $field_name, $current_depth = 0, $max_depth = 3) {
		// Prevent excessive recursion
		if ($current_depth >= $max_depth || !is_array($data)) {
			return null;
		}

		foreach ($data as $key => $value) {
			// Check if this key matches our field name
			if ($key === $field_name) {
				return $value;
			}

			// Recursively search nested arrays with depth limit
			if (is_array($value)) {
				// Direct match in this array
				if (isset($value[$field_name])) {
					return $value[$field_name];
				}

				// Recursive search
				$result = $this->search_nested_field($value, $field_name, $current_depth + 1, $max_depth);
				if ($result !== null) {
					return $result;
				}
			}
		}

		return null;
	}

    
	/**
	 * Get account data from form submission
	 * 
	 * @param array $form_data Form data
	 * @param int $form_id Form ID (optional)
	 * @return array Account data
	 */
	public function get_account_data($form_data, $form_id = null) {
		// If form_id is provided but not loaded yet, load it
		if ($form_id !== null && (!isset($this->field_maps[$form_id]) || $this->current_form_id !== $form_id)) {
			$this->load_field_mappings($form_id);
		}

		// Use current form ID if none provided
		$form_id = $form_id !== null ? $form_id : $this->current_form_id;

		// If we still don't have mappings, return empty data
		if (!isset($this->field_maps[$form_id])) {
			return ['name' => ''];
		}

		$mapping = $this->field_maps[$form_id];
		$account = [
			'name' => ''
		];

		// Account name (usually company name)
		if (!empty($mapping['account_name'])) {
			if ($mapping['account_name'] === 'names' && isset($form_data['names']) && is_array($form_data['names'])) {
				// Handle special case of "names" field mapped to account name
				$first = isset($form_data['names']['first_name']) ? $form_data['names']['first_name'] : '';
				$last = isset($form_data['names']['last_name']) ? $form_data['names']['last_name'] : '';

				if (!empty($first) || !empty($last)) {
					$account['name'] = trim($first . ' ' . $last);
					error_log('FF Nutshell - Created account name from names array: ' . $account['name']);
				}
			} else {
				// Regular field handling
				$account_name = $this->get_field_value($form_data, $mapping['account_name']);
				if (!empty($account_name)) {
					$account['name'] = sanitize_text_field($account_name);
					error_log('FF Nutshell - Account name from field: ' . $account['name']);
				}
			}
		}

		return $account;
	}
    
    /**
     * Save field mappings to WordPress options
     * 
     * @param int $form_id Form ID
     * @param array $mappings Field mappings
     */
    public function save_field_mappings($form_id, $mappings) {
        // Get all existing mappings
        $all_mappings = get_option('ff_nutshell_field_mappings', []);
        
        // Update mapping for this form
        $all_mappings[$form_id] = $mappings;
        
        // Save all mappings
        update_option('ff_nutshell_field_mappings', $all_mappings);
        
        // Update current instance
        $this->field_maps[$form_id] = $mappings;
        $this->current_form_id = $form_id;
    }
    
    /**
     * Get current field mappings for a specific form
     * 
     * @param int $form_id Form ID
     * @return array Field mappings
     */
    public function get_field_mappings($form_id) {
        // Load mappings if not already loaded
        if (!isset($this->field_maps[$form_id])) {
            $this->load_field_mappings($form_id);
        }
        
        return $this->field_maps[$form_id];
    }
    
    /**
     * Get form fields using Fluent Forms API
     * 
     * @param int $form_id Form ID
     * @return array Form fields
     */
    public function get_form_fields($form_id) {
        // Try Fluent Forms API method first
        $fields = $this->get_form_fields_from_api($form_id);
        
        if (!empty($fields)) {
            return $fields;
        }
        
        // Fall back to custom extraction
        return $this->get_form_fields_from_structure($form_id);
    }
    
	/**
	 * Get form fields using Fluent Forms API with improved nested field handling
	 * 
	 * @param int $form_id Form ID
	 * @return array Form fields
	 */
	private function get_form_fields_from_api($form_id) {
		// Check if Fluent Forms API is available
		if (!function_exists('wpFluent') || !class_exists('FluentForm\App\Modules\Form\FormFieldsParser')) {
			return [];
		}

		try {
			// Get form data
			$form = wpFluent()->table('fluentform_forms')->where('id', $form_id)->first();

			if (!$form) {
				FF_Nutshell_Core::log('Form not found for ID: ' . $form_id);
				return [];
			}

			// Use Fluent Forms' own parser
			$formFields = \FluentForm\App\Modules\Form\FormFieldsParser::getInputs($form, ['admin_label', 'raw']);

			if (is_array($formFields)) {
				$result = [];

				foreach ($formFields as $fieldName => $field) {
					// Check if this is a nested field with bracket notation
					if (strpos($fieldName, '[') !== false && strpos($fieldName, ']') !== false) {
						// Extract parts from bracket notation (e.g., "names[first_name]" -> "names", "first_name")
						preg_match('/^([^\[]+)\[([^\]]+)\]$/', $fieldName, $matches);

						if (count($matches) === 3) {
							$parent = $matches[1];
							$child = $matches[2];

							// Get a user-friendly label
							$label = '';
							if (isset($field['admin_label']) && !empty($field['admin_label'])) {
								$label = $field['admin_label'];
							} else {
								// Create a better label by formatting the child part
								$label = ucfirst($child);
							}

							// Store with bracket notation as key but show user-friendly label
							$result[$fieldName] = $label;
						} else {
							// Fallback for other formats
							$label = isset($field['admin_label']) && !empty($field['admin_label']) 
								? $field['admin_label'] 
								: ucfirst(str_replace('_', ' ', $fieldName));

							$result[$fieldName] = $label;
						}
					} else {
						// Regular field
						$label = isset($field['admin_label']) && !empty($field['admin_label']) 
							? $field['admin_label'] 
							: ucfirst(str_replace('_', ' ', $fieldName));

						$result[$fieldName] = $label;
					}
				}

				return $result;
			}

			return [];
		} catch (Exception $e) {
			// Log error
			error_log('Error getting form fields: ' . $e->getMessage());
			return [];
		}
	}
    
	/**
	 * Get form fields from form structure with debugging
	 * 
	 * @param int $form_id Form ID
	 * @return array Form fields
	 */
	private function get_form_fields_from_structure($form_id) {
		error_log('FF Nutshell - Using fallback method to get form fields for form ID: ' . $form_id);

		$form = wpFluent()->table('fluentform_forms')->where('id', $form_id)->first();

		if (!$form) {
			error_log('FF Nutshell - Form not found');
			return [];
		}

		$form_fields = [];
		$form_structure = json_decode($form->form_fields, true);

		if (!$form_structure || !isset($form_structure['fields'])) {
			error_log('FF Nutshell - Invalid form structure');
			return [];
		}

		error_log('FF Nutshell - Form structure: ' . json_encode($form_structure['fields']));

		// Extract field names and labels
		$this->extract_form_fields($form_structure['fields'], $form_fields);

		error_log('FF Nutshell - Extracted fields: ' . json_encode($form_fields));
		return $form_fields;
	}
    
	/**
	 * Extract fields from form structure with debugging
	 * 
	 * @param array $fields Form fields
	 * @param array $form_fields Result array (passed by reference)
	 * @param string $parent_name Parent field name
	 */
	private function extract_form_fields($fields, &$form_fields, $parent_name = '') {
		foreach ($fields as $field) {
			// Skip if element isn't set
			if (!isset($field['element'])) {
				continue;
			}

			error_log('FF Nutshell - Processing field element: ' . $field['element'] . (isset($field['attributes']['name']) ? ' (name: ' . $field['attributes']['name'] . ')' : ''));

			// Debug the field structure
			error_log('FF Nutshell - Field structure: ' . json_encode($field));

			// Handle different field elements
			switch ($field['element']) {
				// Handle complex field types first
				case 'container':
				case 'address':
				case 'input_name':
				case 'input_file':
					error_log('FF Nutshell - Processing container field: ' . $field['element']);

					// For containers with fields property
					if (isset($field['fields']) && is_array($field['fields'])) {
						$container_name = isset($field['attributes']['name']) ? $field['attributes']['name'] : '';
						error_log('FF Nutshell - Container name: ' . $container_name);
						error_log('FF Nutshell - Container fields: ' . json_encode(array_keys($field['fields'])));

						$this->extract_form_fields($field['fields'], $form_fields, $container_name);
					}

					// For containers with columns property
					if (isset($field['columns']) && is_array($field['columns'])) {
						error_log('FF Nutshell - Container has columns: ' . count($field['columns']));

						foreach ($field['columns'] as $column) {
							if (isset($column['fields']) && is_array($column['fields'])) {
								error_log('FF Nutshell - Processing column fields: ' . json_encode(array_keys($column['fields'])));
								$this->extract_form_fields($column['fields'], $form_fields, $parent_name);
							}
						}
					}
					break;

				default:
					// Standard fields with a name attribute
					if (isset($field['attributes']['name'])) {
						$field_name = $field['attributes']['name'];

						// For fields inside containers like name fields (first_name, last_name)
						if ($parent_name && strpos($field_name, $parent_name) !== 0) { // Only add prefix if not already prefixed
							$field_name = empty($parent_name) ? $field_name : $parent_name . '.' . $field_name;
						}

						error_log('FF Nutshell - Field name with potential parent: ' . $field_name);

						// Get label from various possible sources
						$label = '';
						if (isset($field['settings']['label'])) {
							$label = $field['settings']['label'];
						} elseif (isset($field['settings']['admin_field_label'])) {
							$label = $field['settings']['admin_field_label'];
						} elseif (isset($field['settings']['placeholder'])) {
							$label = $field['settings']['placeholder'];
						} else {
							$label = ucfirst(str_replace('_', ' ', $field_name));
						}

						error_log('FF Nutshell - Adding field: ' . $field_name . ' => ' . $label);

						// Add field to list
						$form_fields[$field_name] = $label;
					}

					// Also check for nested fields (for any field type just in case)
					if (isset($field['fields']) && is_array($field['fields'])) {
						$container_name = isset($field['attributes']['name']) ? $field['attributes']['name'] : $parent_name;
						error_log('FF Nutshell - Field has nested fields, container name: ' . $container_name);
						$this->extract_form_fields($field['fields'], $form_fields, $container_name);
					}

					// Check for columns in any field type
					if (isset($field['columns']) && is_array($field['columns'])) {
						error_log('FF Nutshell - Field has columns');
						foreach ($field['columns'] as $column) {
							if (isset($column['fields']) && is_array($column['fields'])) {
								error_log('FF Nutshell - Processing column fields');
								$this->extract_form_fields($column['fields'], $form_fields, $parent_name);
							}
						}
					}
					break;
			}
		}
	}
}