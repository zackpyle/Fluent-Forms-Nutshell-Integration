<?php
/**
 * Handles communications with the Nutshell API
 */
class FF_Nutshell_API {
    
    // API credentials
    private $username;
    private $password;
	
	// Cache users
    private $users_cache = [];
    
    // API URL
    private $api_url = 'https://app.nutshell.com/rest/';
    
    /**
     * Constructor
     * 
     * @param string $username API username
     * @param string $password API password
     */
    public function __construct($username = '', $password = '') {
		$this->username = !empty($username) ? sanitize_email($username) : get_option('ff_nutshell_api_username', '');
		$this->password = !empty($password) ? sanitize_textarea_field($password) : get_option('ff_nutshell_api_password', '');

		// Ensure API URL is allowed with strict validation
		$allowed_api_domains = ['app.nutshell.com'];
		$api_domain = parse_url($this->api_url, PHP_URL_HOST);

		if (!$api_domain || !in_array($api_domain, $allowed_api_domains, true)) {
			FF_Nutshell_Core::log('Warning: Invalid API domain detected: ' . esc_html($api_domain));
			$this->api_url = 'https://app.nutshell.com/rest/';
		}

		// Validate API URL format
		$api_scheme = parse_url($this->api_url, PHP_URL_SCHEME);
		if (!$api_scheme || !in_array($api_scheme, ['https'], true)) {
			FF_Nutshell_Core::log('Warning: Insecure API URL scheme detected');
			$this->api_url = 'https://app.nutshell.com/rest/';
		}
	}
    
    /**
	 * Create a lead in Nutshell
	 * 
	 * @param array $lead_data Lead data formatted for Nutshell API
	 * @return array Response from API
	 */
	public function create_lead($lead_data) {
		// Create a lead payload with only valid fields - using description only, not name
		$payload = [
			'leads' => [
				[
					'description' => $lead_data['description'] ?? 'New Lead'
					// Removed 'name' as it's not a valid field according to support
				]
			]
		];

		// Add contact association - this appears to be a requirement
		if (!empty($lead_data['links']) && !empty($lead_data['links']['contacts'])) {
			$payload['leads'][0]['links'] = [
				'contacts' => $lead_data['links']['contacts']
			];
		}

		// Add custom fields if they exist
		if (!empty($lead_data['customFields'])) {
			$payload['leads'][0]['customFields'] = $lead_data['customFields'];
		}

		// Add other links if they exist (accounts, sources, owner, etc.)
		if (!empty($lead_data['links'])) {
			if (!isset($payload['leads'][0]['links'])) {
				$payload['leads'][0]['links'] = [];
			}

			foreach ($lead_data['links'] as $link_type => $link_values) {
				// Skip contacts if we already added them
				if ($link_type !== 'contacts' || !isset($payload['leads'][0]['links']['contacts'])) {
					$payload['leads'][0]['links'][$link_type] = $link_values;
				}
			}
		}

		// FF_Nutshell_Core::log('Lead payload: ' . json_encode($payload, JSON_PRETTY_PRINT)); // Verbose; commented to reduce log noise

		// Make the API request
		return $this->make_request('leads', $payload, 'POST');
	}
	
	/**
     * Get or retrieve users from cache
     * 
     * @param bool $force_refresh Force refresh of the cache
     * @return array Array of users
     */
    public function get_users_with_cache($force_refresh = false) {
        // If cache is empty or refresh is forced, fetch users
        if (empty($this->users_cache) || $force_refresh) {
            // Try to load from WordPress options first (persistent cache)
            $cached_users = get_option('ff_nutshell_users_cache');
            $cache_time = get_option('ff_nutshell_users_cache_time', 0);
            
            // If cache exists and is less than 24 hours old, use it
            if (!$force_refresh && !empty($cached_users) && (time() - $cache_time) < 86400) {
                $this->users_cache = $cached_users;
                FF_Nutshell_Core::log('Using cached users list from options');
            } else {
                // Otherwise fetch fresh data
                FF_Nutshell_Core::log('Fetching fresh users list from API');
                $response = $this->make_request('users', [], 'GET');
                
                if ($response['success'] && !empty($response['data']['users'])) {
                    $this->users_cache = $response['data']['users'];
                    
                    // Save to WordPress options for persistent cache
                    update_option('ff_nutshell_users_cache', $this->users_cache);
                    update_option('ff_nutshell_users_cache_time', time());
                    
                    FF_Nutshell_Core::log('Cached ' . count($this->users_cache) . ' users');
                } else {
                    FF_Nutshell_Core::log('Failed to fetch users: ' . json_encode($response));
                    // Return empty array if fetch fails
                    return [];
                }
            }
        }
        
        return $this->users_cache;
    }
    
	/**
	 * Find a contact in Nutshell by email
	 * 
	 * @param string $email Email to search for
	 * @return string|null Contact ID if found, null otherwise
	 */
	public function find_contact_by_email($email) {
		$email = sanitize_email($email);
    	$email_encoded = urlencode($email);
		FF_Nutshell_Core::log('Searching for contact with email: ' . $email);

		$response = $this->make_request("contacts?q={$email_encoded}", [], 'GET');

		if (!$response['success'] || empty($response['data']['contacts'])) {
			FF_Nutshell_Core::log('No contacts found for email: ' . $email);
			return null;
		}

		// Find exact email match
		foreach ($response['data']['contacts'] as $contact) {
			if (isset($contact['emails']) && is_array($contact['emails'])) {
				foreach ($contact['emails'] as $email_data) {
					// Check for both possible structures
					if ((isset($email_data['value']) && strtolower($email_data['value']) === strtolower($email)) ||
						(isset($email_data['email']) && strtolower($email_data['email']) === strtolower($email))) {
						FF_Nutshell_Core::log('Found contact match: ' . $contact['id']);
						return $contact['id'];
					}
				}
			}
		}

		FF_Nutshell_Core::log('No exact match found for email: ' . $email);
		return null;
	}
    
	/**
	 * Create a contact in Nutshell
	 * 
	 * @param array $contact_data Contact data formatted for Nutshell API
	 * @param string|null $owner_id Optional owner ID to assign to the contact
	 * @return string|null Contact ID if created, null on error
	 */
	public function create_contact($contact_data, $owner_id = null) {
		// Check if we have the minimum required data
		if (empty($contact_data['name']) || empty($contact_data['emails'])) {
			FF_Nutshell_Core::log('Cannot create contact: missing name or email');
			return null;
		}

		// Prepare payload structure according to Nutshell API requirements
		// Make sure the emails structure is correct
		$payload = [
			'contacts' => [
				[
					'name' => $contact_data['name'],
					'emails' => $contact_data['emails'] // Should already be in the correct format
				]
			]
		];
		
		// Add owner if provided
		if (!empty($owner_id)) {
			FF_Nutshell_Core::log('Setting contact owner to: ' . $owner_id);
			$payload['contacts'][0]['links'] = [
				'owner' => $owner_id
			];
		}

		// Add phones if set
		if (!empty($contact_data['phones']) && is_array($contact_data['phones'])) {
			$payload['contacts'][0]['phones'] = $contact_data['phones'];
		}

		// FF_Nutshell_Core::log('Contact payload: ' . json_encode($payload, JSON_PRETTY_PRINT)); // Verbose; commented to reduce log noise

		// Make the API request
		$response = $this->make_request('contacts', $payload, 'POST');

		// Check for success
		if (!$response['success']) {
			FF_Nutshell_Core::log('Failed to create contact: ' . 
					 (is_string($response['message']) ? $response['message'] : json_encode($response['message'])));
			return null;
		}

		// Return the ID of the newly created contact
		if (!empty($response['data']['contacts']) && is_array($response['data']['contacts']) && count($response['data']['contacts']) > 0) {
			return $response['data']['contacts'][0]['id'] ?? null;
		} else if (!empty($response['data']['id'])) {
			return $response['data']['id'];
		}

		FF_Nutshell_Core::log('Created contact but could not find ID in response');
		return null;
	}
    
    /**
     * Find or create a contact in Nutshell
     * 
     * @param array $contact_data Contact data formatted for Nutshell API
     * @param string|null $owner_id Optional owner ID to assign to the contact
     * @return string|null Contact ID if found or created, null on error
     */
	public function find_or_create_contact($contact_data, $owner_id = null) {
		FF_Nutshell_Core::log('Starting find_or_create_contact');

		// Check if contact has email
		if (empty($contact_data['emails']) || !isset($contact_data['emails'][0]) || empty($contact_data['emails'][0]['value'])) {
			FF_Nutshell_Core::log('No valid email found in contact data');
			return null;
		}

		$email = $contact_data['emails'][0]['value'];
		FF_Nutshell_Core::log('Looking for contact with email: ' . $email);

		// Try to find existing contact by email
		$contact_id = $this->find_contact_by_email($email);

		// If found, return the ID
		if ($contact_id) {
			FF_Nutshell_Core::log('Found existing contact with ID: ' . $contact_id);
			return $contact_id;
		}

		FF_Nutshell_Core::log('No existing contact found, creating new one');
		// If not found, create new contact with owner if provided
		return $this->create_contact($contact_data, $owner_id);
	}
	
	/**
	 * Find user by email with improved caching and auto-refresh
	 * 
	 * @param string $email User email to search for
	 * @return string|null User ID if found, null otherwise
	 */
	public function find_user_by_email($email) {
		FF_Nutshell_Core::log('Looking for user with email: ' . $email);

		// Normalize email for case-insensitive comparison
		$email = strtolower(trim($email));

		// First check in current cache without forcing refresh
		$users = $this->get_users_with_cache(false);

		// Loop through cached users
		foreach ($users as $user) {
			if (isset($user['emails']) && is_array($user['emails'])) {
				foreach ($user['emails'] as $user_email) {
					if (strtolower(trim($user_email)) === $email) {
						FF_Nutshell_Core::log('Found user match: ' . $user['id'] . ' for email: ' . $email);
						return $user['id'];
					}
				}
			}
		}

		// If user not found in cache, try a fresh fetch - this is the key improvement
		if (count($users) > 0) {
			FF_Nutshell_Core::log('User not found in cache, forcing refresh to check for recent additions');
			$users = $this->get_users_with_cache(true); // Force refresh

			// Check again with fresh data
			foreach ($users as $user) {
				if (isset($user['emails']) && is_array($user['emails'])) {
					foreach ($user['emails'] as $user_email) {
						if (strtolower(trim($user_email)) === $email) {
							FF_Nutshell_Core::log('Found user match after refresh: ' . $user['id'] . ' for email: ' . $email);
							return $user['id'];
						}
					}
				}
			}
		}

		FF_Nutshell_Core::log('No user found for email: ' . $email . ' even after cache refresh');
		return null;
	}
	
	/**
	 * Find a stageset (pipeline) by ID with cache auto-refresh
	 * 
	 * @param string $stageset_id Stageset ID to search for
	 * @return bool True if found, false otherwise
	 */
	public function find_stageset_by_id($stageset_id) {
		FF_Nutshell_Core::log('Looking for stageset with ID: ' . $stageset_id);

		// First check in current cache without forcing refresh
		$stagesets = $this->get_stagesets_with_cache(false);

		// Loop through cached stagesets
		foreach ($stagesets as $stageset) {
			// Get ID using different possible structures
			$id = isset($stageset['id']) ? $stageset['id'] : (isset($stageset['_id']) ? $stageset['_id'] : '');

			if ($id === $stageset_id) {
				FF_Nutshell_Core::log('Found stageset match: ' . $id);
				return true;
			}
		}

		// If stageset not found in cache, try a fresh fetch - this is the key improvement
		if (count($stagesets) > 0) {
			FF_Nutshell_Core::log('Stageset not found in cache, forcing refresh to check for recent additions');
			$stagesets = $this->get_stagesets_with_cache(true); // Force refresh

			// Check again with fresh data
			foreach ($stagesets as $stageset) {
				// Get ID using different possible structures
				$id = isset($stageset['id']) ? $stageset['id'] : (isset($stageset['_id']) ? $stageset['_id'] : '');

				if ($id === $stageset_id) {
					FF_Nutshell_Core::log('Found stageset match after refresh: ' . $id);
					return true;
				}
			}
		}

		FF_Nutshell_Core::log('No stageset found for ID: ' . $stageset_id . ' even after cache refresh');
		return false;
	}
    
    /**
     * Find an account by name
     * 
     * @param string $name Account name to search for
     * @return string|null Account ID if found, null otherwise
     */
    public function find_account_by_name($name) {
        $name = urlencode($name);
        $response = $this->make_request("accounts?q={$name}", [], 'GET');
        
        if (!$response['success'] || empty($response['data']['accounts'])) {
            return null;
        }
        
        // Find exact name match
        foreach ($response['data']['accounts'] as $account) {
            if (strtolower($account['name']) === strtolower($name)) {
                return $account['id'];
            }
        }
        
        return null;
    }
    
    /**
     * Create an account in Nutshell
     * 
     * @param array $account_data Account data formatted for Nutshell API
     * @return string|null Account ID if created, null on error
     */
    public function create_account($account_data) {
		// Check if we have the minimum required data
		if (empty($account_data['name'])) {
			FF_Nutshell_Core::log('Cannot create account: missing name');
			return null;
		}

		$payload = [
			'accounts' => [$account_data]
		];

		// FF_Nutshell_Core::log('Account payload: ' . json_encode($payload, JSON_PRETTY_PRINT)); // Verbose; commented to reduce log noise

		$response = $this->make_request('accounts', $payload, 'POST');

		if (!$response['success']) {
			FF_Nutshell_Core::log('Failed to create account: API error');
			return null;
		}

		// The ID is in data.accounts[0].id
		if (!empty($response['data']['accounts']) && 
			is_array($response['data']['accounts']) && 
			count($response['data']['accounts']) > 0 && 
			!empty($response['data']['accounts'][0]['id'])) {

			$account_id = $response['data']['accounts'][0]['id'];
			FF_Nutshell_Core::log('Successfully created account with ID: ' . $account_id);
			return $account_id;
		}

		FF_Nutshell_Core::log('Created account but could not find ID in response');
		return null;
	}
    
    /**
     * Find or create an account in Nutshell
     * 
     * @param array $account_data Account data formatted for Nutshell API
     * @return string|null Account ID if found or created, null on error
     */
    public function find_or_create_account($account_data) {
		// Check if account has a name
		if (empty($account_data['name'])) {
			FF_Nutshell_Core::log('Cannot find/create account: missing name');
			return null;
		}

		$name = $account_data['name'];
		FF_Nutshell_Core::log('Finding or creating account with name: ' . $name);

		// Try to find existing account by name
		$account_id = $this->find_account_by_name($name);

		// If found, return the ID
		if ($account_id) {
			FF_Nutshell_Core::log('Found existing account with ID: ' . $account_id);
			return $account_id;
		}

		// If not found, create new account
		FF_Nutshell_Core::log('No existing account found, creating new one');
		return $this->create_account($account_data);
	}
    
    /**
	 * Get custom fields for leads from Nutshell
	 * 
	 * @return array Array of custom fields
	 */
	public function get_lead_custom_fields() {
		$response = $this->make_request('leads/customfields/attributes', [], 'GET');

		if (!$response['success']) {
			FF_Nutshell_Core::log('Failed to get custom fields: ' . json_encode($response));
			return [];
		}

		// Custom fields are in the 'customFields' key of the response
		if (!empty($response['data']['customFields']) && is_array($response['data']['customFields'])) {
			return $response['data']['customFields'];
		}

		return [];
	}
    
    /**
     * Get sources from Nutshell
     * 
     * @return array Array of sources
     */
	public function get_sources() {
		$response = $this->make_request('sources', [], 'GET');

		if (!$response['success']) {
			return [];
		}

		return isset($response['data']['sources']) ? $response['data']['sources'] : [];
	}
	

	/**
	 * Get or retrieve stagesets (pipelines) from cache
	 * 
	 * @param bool $force_refresh Force refresh of the cache
	 * @return array Array of stagesets
	 */
	public function get_stagesets_with_cache($force_refresh = false) {
		// Try to load from WordPress options first
		$cached_stagesets = get_option('ff_nutshell_stagesets_cache');

		// If cache exists and we're not forcing a refresh, use it
		if (!$force_refresh && !empty($cached_stagesets)) {
			return $cached_stagesets;
		} else {
			// Otherwise fetch fresh data
			$response = $this->make_request('stagesets', [], 'GET');

			if ($response['success']) {
				// The stagesets might be under a different key than 'stagesets'
				$stagesets = [];

				// Try to find stagesets in the response
				if (!empty($response['data']['stagesets'])) {
					$stagesets = $response['data']['stagesets'];
				} else if (!empty($response['data']['stageSets'])) {
					// Alternative capitalization
					$stagesets = $response['data']['stageSets'];
				} else {
					// If it's directly in the data
					if (is_array($response['data']) && !isset($response['data']['meta'])) {
						$stagesets = $response['data'];
					} else {
						// Look for any array that might contain stagesets
						foreach ($response['data'] as $key => $value) {
							if (is_array($value) && count($value) > 0 && isset($value[0]['id']) && isset($value[0]['name'])) {
								$stagesets = $value;
								break;
							}
						}
					}
				}

				// Save to WordPress options for persistent cache
				update_option('ff_nutshell_stagesets_cache', $stagesets);

				return $stagesets;
			} else {
				// Return empty array if fetch fails
				return [];
			}
		}
	}

	/**
	 * Set the stageset (pipeline) for a lead
	 * 
	 * @param string $lead_id Lead ID
	 * @param string $stageset_id Stageset ID
	 * @return array Response from API
	 */
	public function set_lead_stageset($lead_id, $stageset_id) {
		$payload = ['stageset' => $stageset_id];
		return $this->make_request('leads/' . $lead_id . '/stageset', $payload, 'POST');
	}
	
	/**
	 * Create a note attached to a lead
	 * 
	 * @param string $lead_id Lead ID
	 * @param string $note_body Note content
	 * @return array Response from API
	 */
	public function create_lead_note($lead_id, $note_body) {
		// Correct payload structure with "data" wrapper
		$payload = [
			'data' => [
				'body' => $note_body,
				'links' => [
					'parent' => $lead_id
				]
			]
		];

		return $this->make_request('notes', $payload, 'POST');
	}
	
    
    /**
     * Get users from Nutshell (potential lead owners)
     * 
     * @return array Array of users
     */
    public function get_users() {
        $response = $this->make_request('users', [], 'GET');
        
        if (!$response['success']) {
            return [];
        }
        
        return $response['data']['users'];
    }
    
	/**
	 * Make a request to the Nutshell API with detailed logging
	 * 
	 * @param string $endpoint API endpoint
	 * @param array $data Request data
	 * @param string $method HTTP method
	 * @return array Response data
	 */
	public function make_request($endpoint, $data = [], $method = 'POST') {
		// Sanitize and validate endpoint
		$endpoint = sanitize_text_field($endpoint);
		if (strpos($endpoint, '../') !== false || strpos($endpoint, './') !== false) {
			FF_Nutshell_Core::log('Security warning: Invalid endpoint path detected');
			return [
				'success' => false,
				'message' => 'Invalid endpoint path',
			];
		}

		// Validate HTTP method
		$allowed_methods = ['GET', 'POST', 'PATCH', 'DELETE'];
		if (!in_array(strtoupper($method), $allowed_methods, true)) {
			FF_Nutshell_Core::log('Security warning: Invalid HTTP method: ' . esc_html($method));
			return [
				'success' => false,
				'message' => 'Invalid HTTP method',
			];
		}

		// Build full API URL
		$url = esc_url_raw($this->api_url . $endpoint);

		// Create authorization header using email and API token
		$auth = base64_encode($this->username . ':' . $this->password);

		// Set up request arguments
		$args = [
			'method'    => $method,
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . $auth,
				'Accept'        => '*/*',
			],
			'timeout'   => 30,
			'sslverify' => true, // Enforce SSL verification
		];

		// Add request body for POST/PATCH methods
		if (($method === 'POST' || $method === 'PATCH') && !empty($data)) {
			$args['body'] = wp_json_encode($data);
			if (json_last_error() !== JSON_ERROR_NONE) {
				FF_Nutshell_Core::log('JSON encoding error: ' . json_last_error_msg());
				return [
					'success' => false,
					'message' => 'Error encoding request data',
				];
			}
		}

		// Create detailed log record for support - WITHOUT sensitive data
		$log_request = [
			'endpoint' => esc_url_raw($url),
			'method' => $method,
			'headers' => [
				'Content-Type' => $args['headers']['Content-Type'],
				'Accept' => $args['headers']['Accept'],
				'Authorization' => 'Basic **REDACTED**'
			],
			// Redact potentially sensitive data in logs
			'body' => isset($args['body']) ? $this->redact_sensitive_data(json_decode($args['body'], true)) : null,
		];

		// FF_Nutshell_Core::log('API REQUEST: ' . wp_json_encode($log_request, JSON_PRETTY_PRINT)); // Very verbose; commented to reduce log noise

		// Make the API request
		$response = wp_remote_request($url, $args);

		// Check for errors
		if (is_wp_error($response)) {
			FF_Nutshell_Core::log('WP Error: ' . esc_html($response->get_error_message()));
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		// Parse response
		$body = wp_remote_retrieve_body($response);
		$code = wp_remote_retrieve_response_code($response);
		$headers = wp_remote_retrieve_headers($response);

		// Create detailed log record of response - redact sensitive data
		$log_response = [
			'code' => $code,
			'headers' => $headers->getAll(),
			// Redact potentially sensitive data from response
			'body' => $this->redact_sensitive_data(json_decode($body, true)),
		];

		// FF_Nutshell_Core::log('API RESPONSE: ' . wp_json_encode($log_response, JSON_PRETTY_PRINT)); // Very verbose; commented to reduce log noise

		// Check response code
		if ($code < 200 || $code >= 300) {
			return [
				'success' => false,
				'code'    => $code,
				'message' => $body,
			];
		}

		return [
			'success' => true,
			'code'    => $code,
			'data'    => json_decode($body, true),
		];
	}

	/**
	 * Redact sensitive data from logs
	 * 
	 * @param mixed $data Data to redact
	 * @return mixed Redacted data
	 */
	private function redact_sensitive_data($data) {
		// If not an array or object, return as is
		if (!is_array($data)) {
			return $data;
		}

		$sensitive_keys = ['password', 'token', 'api_key', 'secret', 'email', 'phone', 'address'];
		$redacted = [];

		foreach ($data as $key => $value) {
			// Check if key contains sensitive information
			$is_sensitive = false;
			foreach ($sensitive_keys as $sensitive_key) {
				if (stripos($key, $sensitive_key) !== false) {
					$is_sensitive = true;
					break;
				}
			}

			if ($is_sensitive) {
				// Redact sensitive data
				$redacted[$key] = is_array($value) ? '[REDACTED]' : '[REDACTED]';
			} else if (is_array($value)) {
				// Recursively check nested arrays
				$redacted[$key] = $this->redact_sensitive_data($value);
			} else {
				// Keep non-sensitive data
				$redacted[$key] = $value;
			}
		}

		return $redacted;
	}
    
    /**
     * Test API connection
     * 
     * @return bool True if connection successful, false otherwise
     */
    public function test_connection() {
        $response = $this->make_request('users', [], 'GET');
        return $response['success'];
    }
}