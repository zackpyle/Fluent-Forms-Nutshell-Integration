<?php
/**
 * Handles Fluent Forms form submissions and processes them for Nutshell CRM.
 */
class FF_Nutshell_Form_Handler {
    
    // API handler
    private $api;
    
    // Field mapper
    private $field_mapper;
    
    // Excluded Form IDs
    private $excluded_form_ids = [];
    
    // Logging enabled
    private $logging_enabled = false;
    
    /**
     * Constructor
     * 
     * @param FF_Nutshell_API $api API handler
     * @param FF_Nutshell_Field_Mapper $field_mapper Field mapper
     */
    public function __construct($api, $field_mapper) {
        $this->api = $api;
        $this->field_mapper = $field_mapper;
        
        // Load settings
        $this->load_settings();
    }
    
    /**
	 * Load settings
	 */
	private function load_settings() {
		$excluded_form_ids = get_option('ff_nutshell_excluded_form_ids', '');
		$this->excluded_form_ids = !empty($excluded_form_ids) ? array_map('trim', explode(',', $excluded_form_ids)) : [];
		$this->logging_enabled = get_option('ff_nutshell_enable_logging', false);
	}
    
	/**
	 * Process form submission with debugging
	 * 
	 * @param int $entry_id Entry ID
	 * @param array $form_data Form data
	 * @param object $form Form object
	 */
	public function process_submission($entry_id, $form_data, $form) {
		// Validate input parameters
		$entry_id = absint($entry_id);
		$form_id = isset($form->id) ? absint($form->id) : 0;

		if (!$entry_id || !$form_id) {
			FF_Nutshell_Core::log('Invalid entry ID or form ID');
			return;
		}

		// Debug the form data structure (with sanitized output)
		FF_Nutshell_Core::log('Processing submission for form ID: ' . $form_id);

		// Check if this form is excluded from creating Nutshell leads
		if (in_array($form_id, $this->excluded_form_ids)) {
			FF_Nutshell_Core::log('Form ID ' . $form_id . ' is excluded. Skipping...');
			return;
		}
		
		// Verify form exists using prepared statement via wpFluent
		$form_exists = wpFluent()->table('fluentform_forms')
			->where('id', $form_id)
			->exists();

		if (!$form_exists) {
			FF_Nutshell_Core::log('Form does not exist with ID: ' . $form_id);
			return;
		}

		// Get form data from database to make sure we have complete data
		// Using prepared statements via wpFluent to prevent SQL injection
		$submission = wpFluent()->table('fluentform_submissions')
			->where('id', $entry_id)
			->first();

		if (!$submission) {
			FF_Nutshell_Core::log('Submission not found for entry ID: ' . $entry_id);
			return;
		}

		// Get form fields and decode JSON
		$response = json_decode($submission->response, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			FF_Nutshell_Core::log('Error decoding JSON: ' . json_last_error_msg());
			return;
		}

		// Sanitize form response data recursively before processing
		$response = $this->sanitize_form_data($response);

		// Create lead in Nutshell
		$this->create_lead($response, $form);
	}

	/**
	 * Sanitize form data recursively
	 * 
	 * @param array $data Form data to sanitize
	 * @return array Sanitized form data
	 */
	private function sanitize_form_data($data) {
		if (!is_array($data)) {
			return is_scalar($data) ? sanitize_text_field($data) : '';
		}

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->sanitize_form_data($value);
			} else {
				// Sanitize based on field type/name if needed
				if (stripos($key, 'email') !== false) {
					$data[$key] = sanitize_email($value);
				} elseif (stripos($key, 'url') !== false) {
					$data[$key] = esc_url_raw($value);
				} else {
					$data[$key] = sanitize_text_field($value);
				}
			}
		}

		return $data;
	}

    
	/**
	 * Create lead in Nutshell with enhanced debugging
	 * 
	 * @param array $form_data Form data
	 * @param object $form Form object
	 * @return array Result
	 */
	private function create_lead($form_data, $form) {
		try {
			FF_Nutshell_Core::log('Creating lead for form ID: ' . $form->id);

			// Load form-specific mappings
			$this->field_mapper->load_field_mappings($form->id);
			$mapping = $this->field_mapper->get_field_mappings($form->id);

			// 1. Prepare contact data if available
			FF_Nutshell_Core::log('Preparing contact data...');
			$contact_data = $this->field_mapper->get_contact_data($form_data, $form->id);
			$contact_id = null;

			if (!empty($contact_data['name']) && !empty($contact_data['emails'])) {
				FF_Nutshell_Core::log('Contact data valid, finding or creating contact...');
				$contact_id = $this->api->find_or_create_contact($contact_data);
				FF_Nutshell_Core::log('Contact ID: ' . ($contact_id ? $contact_id : 'not created'));
			} else {
				FF_Nutshell_Core::log('Invalid contact data: ' . json_encode($contact_data));
			}

			// 2. Prepare account data if available
			$account_data = $this->field_mapper->get_account_data($form_data, $form->id);
			$account_id = null;

			if (!empty($account_data['name'])) {
				FF_Nutshell_Core::log('Creating account with name: ' . $account_data['name']);
				$account_id = $this->api->find_or_create_account($account_data);
				FF_Nutshell_Core::log('Account ID: ' . ($account_id ? $account_id : 'not created'));
			}

			// 3. Map form data to Nutshell lead fields
			$lead_data = $this->field_mapper->map_to_lead($form_data, $form->id);

			// 4. Link contact and account if found or created
			if ($contact_id) {
				$lead_data['links']['contacts'] = [$contact_id];
			}

			if ($account_id) {
				$lead_data['links']['accounts'] = [$account_id];
				FF_Nutshell_Core::log('Added account ' . $account_id . ' to lead links');
			}

			// 5. Agent Assignment
			$owner_assigned = false;

			// First check for direct agent_email in form data (from hidden field)
			if (isset($form_data['agent_email']) && !empty($form_data['agent_email'])) {
				FF_Nutshell_Core::log('Form has agent_email field: ' . $form_data['agent_email']);
				$owner_id = $this->api->find_user_by_email($form_data['agent_email']);
				if ($owner_id) {
					FF_Nutshell_Core::log('Found owner ID: ' . $owner_id . ' for email: ' . $form_data['agent_email']);
					$lead_data['links']['owner'] = $owner_id;
					$owner_assigned = true;
				} else {
					FF_Nutshell_Core::log('Could not find owner for email: ' . $form_data['agent_email']);
				}
			}

			// If no direct agent email, check for agent field mapping
			if (!$owner_assigned && !empty($mapping['agent_id_field']) && isset($form_data[$mapping['agent_id_field']])) {
				$agent_value = $form_data[$mapping['agent_id_field']];
				FF_Nutshell_Core::log('Using mapped agent field: ' . $mapping['agent_id_field'] . ' with value: ' . $agent_value);

				// Is this an email or an ID?
				if (filter_var($agent_value, FILTER_VALIDATE_EMAIL)) {
					// It's an email, look up the ID
					$owner_id = $this->api->find_user_by_email($agent_value);
					if ($owner_id) {
						FF_Nutshell_Core::log('Found owner ID: ' . $owner_id . ' for mapped email: ' . $agent_value);
						$lead_data['links']['owner'] = $owner_id;
						$owner_assigned = true;
					} else {
						FF_Nutshell_Core::log('Could not find owner for mapped email: ' . $agent_value);
					}
				} else {
					// Assume it's a direct ID
					FF_Nutshell_Core::log('Using direct owner ID: ' . $agent_value);
					$lead_data['links']['owner'] = $agent_value;
					$owner_assigned = true;
				}
			}

			// If no agent assigned yet, use default owner if set
			if (!$owner_assigned && !empty($mapping['default_owner'])) {
				FF_Nutshell_Core::log('Using default owner: ' . $mapping['default_owner']);
				$lead_data['links']['owner'] = $mapping['default_owner'];
				$owner_assigned = true;
			}

			// 6. Create the lead
			$result = $this->api->create_lead($lead_data);

			// 7. If lead creation was successful and we need to assign a stageset (pipeline)
			if ($result['success'] && !empty($result['data']['leads'][0]['id'])) {
				$lead_id = $result['data']['leads'][0]['id'];
				$stageset_id = null;

				// Determine stageset ID based on mapping type
				if (isset($mapping['stageset_type'])) {
					if ($mapping['stageset_type'] === 'fixed' && !empty($mapping['stageset_id'])) {
						// Use fixed stageset ID
						$stageset_id = $mapping['stageset_id'];
					} else if ($mapping['stageset_type'] === 'field' && !empty($mapping['stageset_field'])) {
						// Get stageset ID from form field
						$field_value = $this->field_mapper->get_field_value($form_data, $mapping['stageset_field']);
						if (!empty($field_value)) {
							$stageset_id = $field_value;
						}
					}
				}

				// Assign stageset if we determined an ID, but first validate it exists
				if (!empty($stageset_id)) {
					// Check if the stageset ID exists in Nutshell (with cache refresh if needed)
					if ($this->api->find_stageset_by_id($stageset_id)) {
						FF_Nutshell_Core::log('Assigning lead to verified stageset ID: ' . $stageset_id);
						$this->api->set_lead_stageset($lead_id, $stageset_id);
					} else {
						FF_Nutshell_Core::log('Warning: Stageset ID: ' . $stageset_id . ' not found in Nutshell, skipping assignment');
					}
				}
			}

			// 8. Create a note if configured
			if ($result['success'] && !empty($result['data']['leads'][0]['id'])) {
				$lead_id = $result['data']['leads'][0]['id'];
				$note_content = null;

				// Determine note content based on mapping type
				if (isset($mapping['note_type'])) {
					if ($mapping['note_type'] === 'field' && !empty($mapping['note_field'])) {
						// Get note content from form field
						$note_content = $this->field_mapper->get_field_value($form_data, $mapping['note_field']);
					} else if ($mapping['note_type'] === 'template' && !empty($mapping['note_template'])) {
						// Process template with dynamic values
						$note_content = $this->field_mapper->process_template($mapping['note_template'], $form_data);
					}
				}

				// Create note if we have content
				if (!empty($note_content)) {
					$this->api->create_lead_note($lead_id, $note_content);
				}
			}

			// 9. Log the result - updated to use WordPress debug log
			if ($this->logging_enabled) {
				FF_Nutshell_Core::log('Lead creation result: ' . ($result['success'] ? 'SUCCESS' : 'FAILED'));
			}

			return $result;

		} catch (Exception $e) {
			FF_Nutshell_Core::log('Exception: ' . $e->getMessage());

			return [
				'success' => false,
				'message' => $e->getMessage()
			];
		}
	}
}