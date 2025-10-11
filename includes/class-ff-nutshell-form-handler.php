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
	 * Check if an email matches any exclusion patterns from settings
	 *
	 * @param string $email Submitter email
	 * @return bool True if should be excluded
	 */
	private function email_matches_exclusion($email) {
		$patterns_raw = get_option('ff_nutshell_exclusion_email_patterns', '');
		if (empty($patterns_raw)) {
			return false;
		}

		$lines = preg_split('/\r\n|\r|\n/', $patterns_raw);
		$email = strtolower(trim($email));

		foreach ($lines as $line) {
			$pattern = trim($line);
			if ($pattern === '') {
				continue;
			}

			// Allow patterns with or without delimiters; default to case-insensitive
			if ($pattern[0] === '/') {
				// Already delimited (may include flags like /.../i)
				$regex = $pattern;
			} else {
				// No delimiters provided; wrap and make case-insensitive
				$regex = '/' . str_replace('/', '\/', $pattern) . '/i';
			}

			// Validate regex; suppress warnings using @ and check for false
			$matched = @preg_match($regex, $email);
			if ($matched === 1) {
				return true;
			} elseif ($matched === false) {
				FF_Nutshell_Core::log('Invalid email exclusion regex skipped: ' . $pattern);
			}
		}

		return false;
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

		// Create lead in Nutshell and capture result
		$result = $this->create_lead($response, $form);

		// If we have a result, attempt to add a note to the Fluent Forms entry
		if (is_array($result)) {
			$this->add_submission_note($entry_id, $form_id, $result);
		}
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

			// Store agent information for later use
			$agent_email = null;
			$agent_name = null;
			$owner_id = null;

			// 1. Prepare contact data if available
			FF_Nutshell_Core::log('Preparing contact data...');
			$contact_data = $this->field_mapper->get_contact_data($form_data, $form->id);
			$contact_id = null;

			// Email-based exclusion check: if submitter email matches any configured pattern, skip creating a lead
			$submitter_email = '';
			if (!empty($contact_data['emails']) && isset($contact_data['emails'][0]['value'])) {
				$submitter_email = sanitize_email($contact_data['emails'][0]['value']);
			}
			if (!empty($submitter_email) && $this->email_matches_exclusion($submitter_email)) {
				FF_Nutshell_Core::log('Submission excluded by email pattern: ' . $submitter_email . '. Skipping Nutshell lead creation.');
				return [
					'success' => true,
					'message' => 'Submission excluded by email rules'
				];
			}

			if (!empty($contact_data['name']) && !empty($contact_data['emails'])) {
				FF_Nutshell_Core::log('Contact data valid, finding or creating contact...');
				// First check if we have an agent email to use for contact ownership
				$owner_id = null;
				if (isset($form_data['agent_email']) && !empty($form_data['agent_email'])) {
					$agent_email = sanitize_email($form_data['agent_email']);
					$owner_id = $this->api->find_user_by_email($agent_email);
					if ($owner_id) {
						FF_Nutshell_Core::log('DEBUG - Using agent email for contact ownership: ' . $agent_email . ' with ID: ' . $owner_id);
					}
				}
				$contact_id = $this->api->find_or_create_contact($contact_data, $owner_id);
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

			// 5. Agent Assignment - UPDATED to capture agent information with enhanced logging
			$owner_assigned = false;
			$agent_email = '';
			$agent_name = '';

			// Dump all form data keys for debugging (verbose)
			// $form_keys = array_keys($form_data);
			// FF_Nutshell_Core::log('DEBUG - Available form fields: ' . implode(', ', $form_keys));

			// First check for direct agent_email in form data (from hidden field)
			if (isset($form_data['agent_email']) && !empty($form_data['agent_email'])) {
				$agent_email = sanitize_email($form_data['agent_email']);
				FF_Nutshell_Core::log('DEBUG - Form has agent_email field: ' . $agent_email);
				
				// Store agent name if available
				if (isset($form_data['agent_name']) && !empty($form_data['agent_name'])) {
					$agent_name = sanitize_text_field($form_data['agent_name']);
					FF_Nutshell_Core::log('DEBUG - Form has agent_name field: ' . $agent_name);
				}
				
				// Log before API call
				FF_Nutshell_Core::log('DEBUG - About to search for user with email: ' . $agent_email);
				
				$owner_id = $this->api->find_user_by_email($agent_email);
				
				// Log API response
				if ($owner_id) {
					FF_Nutshell_Core::log('DEBUG - Found owner ID: ' . $owner_id . ' for email: ' . $agent_email);
					FF_Nutshell_Core::log('DEBUG - Setting lead_data["links"]["owner"] = ' . $owner_id);
					$lead_data['links']['owner'] = $owner_id;
					$owner_assigned = true;
				} else {
					FF_Nutshell_Core::log('DEBUG - Could not find owner for email: ' . $agent_email . ' - Check if this email exists in Nutshell');
				}
			} else {
				FF_Nutshell_Core::log('DEBUG - No agent_email field found in form data');
			}

			// If no direct agent email, check for agent field mapping
			if (!$owner_assigned && !empty($mapping['agent_id_field']) && isset($form_data[$mapping['agent_id_field']])) {
				$agent_value = $form_data[$mapping['agent_id_field']];
				FF_Nutshell_Core::log('DEBUG - Using mapped agent field: ' . $mapping['agent_id_field'] . ' with value: ' . $agent_value);

				// Is this an email or an ID?
				if (filter_var($agent_value, FILTER_VALIDATE_EMAIL)) {
					// It's an email, look up the ID
					$agent_email = sanitize_email($agent_value);
					$owner_id = $this->api->find_user_by_email($agent_email);
					if ($owner_id) {
						FF_Nutshell_Core::log('Found owner ID: ' . $owner_id . ' for mapped email: ' . $agent_email);
						$lead_data['links']['owner'] = $owner_id;
						$owner_assigned = true;
					} else {
						FF_Nutshell_Core::log('Could not find owner for mapped email: ' . $agent_email);
					}
				} else {
					// Assume it's a direct ID
					FF_Nutshell_Core::log('Using direct owner ID: ' . $agent_value);
					$owner_id = $agent_value;
					$lead_data['links']['owner'] = $owner_id;
					$owner_assigned = true;
				}
			}

			// If no agent assigned yet, use default owner if set
			if (!$owner_assigned && !empty($mapping['default_owner'])) {
				FF_Nutshell_Core::log('Using default owner: ' . $mapping['default_owner']);
				$owner_id = $mapping['default_owner'];
				$lead_data['links']['owner'] = $owner_id;
				$owner_assigned = true;
			}

			// 6. Create the lead in Nutshell
			// FF_Nutshell_Core::log('DEBUG - Final lead data before API call: ' . json_encode($lead_data)); // Verbose; commented to reduce log noise
			
			$response = $this->api->create_lead($lead_data);
			
			if (!$response['success']) {
				FF_Nutshell_Core::log('Failed to create lead: ' . json_encode($response));
				return [
					'success' => false,
					'error' => 'Failed to create lead',
					'api_response' => $response
				];
			}
			
			$lead_payload = $response['data']['leads'][0] ?? [];
			$lead_id = $lead_payload['id'] ?? null;
			$lead_number = isset($lead_payload['number']) ? intval($lead_payload['number']) : null;
			$lead_url = !empty($lead_payload['htmlUrl']) ? $lead_payload['htmlUrl'] : ($lead_number ? ('https://app.nutshell.com/lead/' . $lead_number) : null);
			
			if (!$lead_id) {
				FF_Nutshell_Core::log('Lead created but could not find ID in response');
				return [
					'success' => false,
					'error' => 'Lead created but ID missing',
					'api_response' => $response
				];
			}
			
			FF_Nutshell_Core::log('Created lead with ID: ' . $lead_id . ($lead_number ? (' | Number: ' . $lead_number) : ''));
			
			// Log the owner assignment status
			if ($owner_assigned) {
				FF_Nutshell_Core::log('DEBUG - Lead assigned to agent: ' . $agent_email);
			} else {
				FF_Nutshell_Core::log('DEBUG - Lead not assigned to any specific agent');
			}

			// 7. If lead creation was successful and we need to assign a stageset (pipeline)
			if ($response['success'] && !empty($response['data']['leads'][0]['id'])) {
				$lead_id = $response['data']['leads'][0]['id'];
				$stageset_candidate = null;
				$resolved_stageset_id = null;
				$resolved_stageset_label = null;

				// Determine candidate based on mapping type
				if (isset($mapping['stageset_type'])) {
					if ($mapping['stageset_type'] === 'fixed' && !empty($mapping['stageset_id'])) {
						$stageset_candidate = $mapping['stageset_id'];
						FF_Nutshell_Core::log('Stageset mapping: using fixed candidate: ' . $stageset_candidate);
					} else if ($mapping['stageset_type'] === 'field' && !empty($mapping['stageset_field'])) {
						$field_value = $this->field_mapper->get_field_value($form_data, $mapping['stageset_field']);
						if (!empty($field_value)) {
							$stageset_candidate = $field_value;
							FF_Nutshell_Core::log('Stageset mapping: using field candidate from ' . $mapping['stageset_field'] . ': ' . $stageset_candidate);
						}
					}
				}

				// Resolution order: direct ID -> name lookup -> fallback to '1-stagesets'
				if (!empty($stageset_candidate)) {
					// Try as ID
					if ($this->api->find_stageset_by_id($stageset_candidate)) {
						$resolved_stageset_id = $stageset_candidate;
						FF_Nutshell_Core::log('Stageset resolution: candidate is a valid ID: ' . $resolved_stageset_id);
					} else {
						// Try by name (unique, case-insensitive)
						$by_name_id = $this->api->find_stageset_by_name($stageset_candidate);
						if (!empty($by_name_id)) {
							$resolved_stageset_id = $by_name_id;
							FF_Nutshell_Core::log('Stageset resolution: resolved by name to ID: ' . $resolved_stageset_id);
						} else {
							FF_Nutshell_Core::log('Stageset resolution: no match for candidate "' . $stageset_candidate . '" by ID or name. Falling back to Default (1-stagesets)');
						}
					}
				}

				// Apply fallback if still not resolved
				if (empty($resolved_stageset_id)) {
					$resolved_stageset_id = '1-stagesets';
					FF_Nutshell_Core::log('Stageset resolution: using fallback stageset ID: ' . $resolved_stageset_id);
				}

				// Assign stageset
				FF_Nutshell_Core::log('Assigning lead ' . $lead_id . ' to stageset: ' . $resolved_stageset_id);
				$this->api->set_lead_stageset($lead_id, $resolved_stageset_id);
			}

			// 8. Create a note if configured - UPDATED to include agent attribution
			if ($response['success'] && !empty($response['data']['leads'][0]['id'])) {
				$lead_id = $response['data']['leads'][0]['id'];
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

				// Add agent attribution to note if we have agent info
				if (!empty($note_content)) {
					// Add agent attribution if we have agent information
					if (!empty($agent_name) || !empty($agent_email)) {
						$agent_info = !empty($agent_name) ? $agent_name : $agent_email;
						$note_content = "Lead submitted via " . $agent_info . "'s contact form.\n\n" . $note_content;
					}
					
					$this->api->create_lead_note($lead_id, $note_content);
				}
			}

			// 9. Log the result - updated to use WordPress debug log
			if ($this->logging_enabled) {
				FF_Nutshell_Core::log('Lead creation result: ' . ($response['success'] ? 'SUCCESS' : 'FAILED'));
			}

			// Return structured result with key IDs for downstream usage (e.g., FF entry notes)
			return [
				'success' => $response['success'],
				'lead_id' => $lead_id,
				'lead_number' => $lead_number,
				'lead_url' => $lead_url,
				'contact_id' => isset($contact_id) ? $contact_id : null,
				'account_id' => isset($account_id) ? $account_id : null,
				'owner_id' => isset($owner_id) ? $owner_id : null,
				'agent_email' => isset($agent_email) ? $agent_email : null,
				'agent_name' => isset($agent_name) ? $agent_name : null,
				'stageset_id' => isset($resolved_stageset_id) ? $resolved_stageset_id : null,
				'pipeline' => isset($resolved_stageset_label) ? $resolved_stageset_label : null,
				'api_response' => $response
			];

		} catch (Exception $e) {
			FF_Nutshell_Core::log('Exception: ' . $e->getMessage());

			return [
				'success' => false,
				'message' => $e->getMessage()
			];
		}
	}

	/**
	 * Add a note to the Fluent Forms entry with key Nutshell response info
	 *
	 * @param int   $entry_id Fluent Forms submission ID
	 * @param int   $form_id  Form ID
	 * @param array $result   Result returned by create_lead()
	 */
	private function add_submission_note($entry_id, $form_id, $result) {
		try {
			if (empty($entry_id) || empty($form_id) || !is_array($result)) {
				return;
			}

			$note_lines = [];
			$note_lines[] = 'Nutshell Lead Created';
			if (!empty($result['lead_id'])) {
				$note_lines[] = 'Lead ID: ' . $result['lead_id'];
			}
			// Prefer the lower, numeric lead number for display and URL
			$lead_number = !empty($result['lead_number']) ? intval($result['lead_number']) : null;
			if ($lead_number) {
				$note_lines[] = 'Lead Number: ' . $lead_number;
				$note_lines[] = 'Lead URL: https://app.nutshell.com/lead/' . $lead_number;
			} elseif (!empty($result['lead_id'])) {
				// Fallback: try to derive numeric from lead_id like "9460-leads"
				if (preg_match('/^(\d+)/', (string) $result['lead_id'], $m)) {
					$note_lines[] = 'Lead URL: https://app.nutshell.com/lead/' . $m[1];
				}
			}
			if (!empty($result['contact_id'])) {
				$note_lines[] = 'Contact ID: ' . $result['contact_id'];
			}
			if (!empty($result['account_id'])) {
				$note_lines[] = 'Account ID: ' . $result['account_id'];
			}
			if (!empty($result['owner_id'])) {
				$note_lines[] = 'Owner ID: ' . $result['owner_id'];
			}
			// Prefer a human-friendly pipeline label when available
			if (!empty($result['pipeline'])) {
				$note_lines[] = 'Pipeline: ' . $result['pipeline'];
			} elseif (!empty($result['stageset_id'])) {
				$note_lines[] = 'Pipeline: ' . $result['stageset_id'];
			}
			if (!empty($result['agent_name']) || !empty($result['agent_email'])) {
				$agent = !empty($result['agent_name']) ? $result['agent_name'] : $result['agent_email'];
				$note_lines[] = 'Submitted via agent: ' . $agent;
			}

			$note = implode("\n- ", $note_lines);
			if (strpos($note, 'Nutshell Lead Created') === 0) {
				$note = "- " . $note; // ensure consistent bulleting
			}

			// Write to Fluent Forms logs (preferred and reliable)
			do_action('fluentform/log_data', [
				'parent_source_id' => (int) $form_id,
				'source_type'      => 'submission_item',
				'source_id'        => (int) $entry_id,
				'component'        => 'Nutshell',
				'status'           => (!empty($result['success']) ? 'success' : 'error'),
				'title'            => 'Lead Created',
				'description'      => $note
			]);
			FF_Nutshell_Core::log('Added Fluent Forms log entry for entry ID: ' . $entry_id);
		} catch (\Throwable $e) {
			FF_Nutshell_Core::log('Error adding submission note: ' . $e->getMessage());
		}
	}
}