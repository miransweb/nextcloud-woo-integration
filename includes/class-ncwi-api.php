<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_API {
    
    private static $instance = null;
    private $deployer_api_url;
    private $deployer_api_key;
    private $nextcloud_api_url;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_settings();
    }
    
    private function load_settings() {
        $this->deployer_api_url = get_option('ncwi_deployer_api_url');
        $this->deployer_api_key = get_option('ncwi_deployer_api_key');
        $this->nextcloud_api_url = get_option('ncwi_nextcloud_api_url');
    }
    
    /**
     * Create or get shop user
     */
    public function create_shop_user($data) {
        $endpoint = $this->deployer_api_url . '/api/shop-users/';
        
        // Use WP user ID as integer shop_user_id
        $shop_user_id = intval($data['wp_user_id']);
        
        $body = [
            'shop_user_id' => $shop_user_id,
            'name' => $data['name'] ?? $data['email'],
            'email' => sanitize_email($data['email'])
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        // Add the shop_user_id to the result for further use
        if (is_array($result)) {
            $result['shop_user_id'] = $shop_user_id;
        }
        
        return $result;
    }
    
    /**
     * Send email verification
     */
    public function send_email_verification($shop_user_id, $email) {
        $endpoint = $this->deployer_api_url . '/api/email/verification/send/';
        
        $body = [
            'shop_user_id' => intval($shop_user_id),
            'email' => sanitize_email($email)
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Check email verification status
     */
    public function check_email_verification($shop_user_id, $email = null) {
        $endpoint = $this->deployer_api_url . '/api/email/verification/check/';
        
        // Use GET request with query parameters - include email if provided
        $params = ['shop_user_id' => intval($shop_user_id)];
        if ($email) {
            $params['email'] = sanitize_email($email);
        }
        
        $url = add_query_arg($params, $endpoint);
        
        $response = $this->make_deployer_request('GET', $url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Create Nextcloud account via Deployer
     */
    public function create_nextcloud_account($data) {
        // First ensure shop user exists
        $user = get_user_by('id', $data['wp_user_id']);
        $shop_user_result = $this->create_shop_user([
            'email' => $data['email'],
            'wp_user_id' => $data['wp_user_id'],
            'name' => $user ? $user->display_name : $data['username']
        ]);
        
        if (is_wp_error($shop_user_result)) {
            return $shop_user_result;
        }
        
        // Get shop_user_id as integer
        $shop_user_id = intval($data['wp_user_id']);
        
        // Check if we should skip email verification (for testing)
        $skip_verification = get_option('ncwi_skip_email_verification', 'no') === 'yes';
        
        if (!$skip_verification) {
            // Check if email is verified - include email parameter
            $verification_status = $this->check_email_verification($shop_user_id, $data['email']);
            
            if (is_wp_error($verification_status)) {
                // If check fails, try to send verification email
                error_log('NCWI: Email verification check failed: ' . $verification_status->get_error_message());
                
                // Send verification email
                $verify_result = $this->send_email_verification($shop_user_id, $data['email']);
                
                if (!is_wp_error($verify_result)) {
                    return [
                        'success' => false,
                        'data' => [
                            'status' => 'pending_verification',
                            'message' => __('Een verificatie email is verstuurd naar ' . $data['email'] . '. Controleer je inbox en klik op de verificatie link.', 'nc-woo-integration'),
                            'verification_sent' => true
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'data' => [
                            'status' => 'pending_verification',
                            'message' => __('Email verificatie is vereist maar de verificatie email kon niet worden verstuurd. Neem contact op met de beheerder.', 'nc-woo-integration'),
                            'verification_sent' => false,
                            'error' => $verify_result->get_error_message()
                        ]
                    ];
                }
            } else if (!isset($verification_status['verified']) || !$verification_status['verified']) {
                // Email not verified, send verification
                $verify_result = $this->send_email_verification($shop_user_id, $data['email']);
                
                if (!is_wp_error($verify_result)) {
                    return [
                        'success' => false,
                        'data' => [
                            'status' => 'pending_verification',
                            'message' => __('Een verificatie email is verstuurd naar ' . $data['email'] . '. Controleer je inbox en klik op de verificatie link.', 'nc-woo-integration'),
                            'verification_sent' => true
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'data' => [
                            'status' => 'pending_verification',
                            'message' => __('Email verificatie is vereist maar de verificatie email kon niet worden verstuurd. Neem contact op met de beheerder.', 'nc-woo-integration'),
                            'verification_sent' => false,
                            'error' => $verify_result->get_error_message()
                        ]
                    ];
                }
            }
        }
        
        // Try to create NC user (only if verified or skip is enabled)
        $endpoint = $this->deployer_api_url . '/api/users/';
        
        // Generate a secure password
        $password = wp_generate_password(16, true, true); // Simplified password for testing
        
        $body = [
            'shop_user_id' => $shop_user_id,
            'email' => sanitize_email($data['email']),
            'password' => $password
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $result = json_decode($response_body, true);
        
        // Check for email verification error
        if ($response_code === 400 && isset($result['error']) && $result['error'] === 'Email not verified') {
            // Try to send verification email now
            $verify_result = $this->send_email_verification($shop_user_id, $data['email']);
            
            if (!is_wp_error($verify_result)) {
                return [
                    'success' => false,
                    'data' => [
                        'status' => 'pending_verification',
                        'message' => __('Een verificatie email is verstuurd naar ' . $data['email'] . '. Controleer je inbox en klik op de verificatie link.', 'nc-woo-integration'),
                        'verification_sent' => true
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'data' => [
                        'status' => 'pending_verification',
                        'message' => __('Email verificatie is vereist. De beheerder moet de email verificatie in Deployer uitschakelen voor testing.', 'nc-woo-integration'),
                        'verification_required' => true
                    ]
                ];
            }
        }
        
        // Check for other errors
        if ($response_code !== 200 && $response_code !== 201) {
            return new WP_Error('api_error', 
                sprintf(__('NC user creation failed: %s', 'nc-woo-integration'), 
                    $result['error'] ?? $result['detail'] ?? 'Unknown error')
            );
        }
        
        // Add password to result so it can be sent to user
        if (is_array($result)) {
            $result['password'] = $password;
            $result['success'] = true;
            
            // Get username from response
            $nc_username = $result['userid'] ?? $result['username'] ?? $data['email'];
            $server_url = $result['server_url'] ?? $this->nextcloud_api_url;
            
            // Ensure we have required fields
            if (!isset($result['data'])) {
                $result['data'] = [
                    'username' => $nc_username,
                    'email' => $data['email'],
                    'server' => $server_url,
                    'nc_user_id' => $nc_username
                ];
            }
            
            // Add new user to trial group
            $this->add_user_to_group($nc_username, $server_url, 'tgcusers_trial');
        }
        
        return $result;
    }
    
    /**
     * Link existing Nextcloud account to WP user
     */
    public function link_existing_account($data) {
        // First ensure shop user exists
        $user = get_user_by('id', $data['wp_user_id']);
        $shop_user_result = $this->create_shop_user([
            'email' => $data['nc_email'],
            'wp_user_id' => $data['wp_user_id'],
            'name' => $user ? $user->display_name : $data['nc_username']
        ]);
        
        if (is_wp_error($shop_user_result)) {
            return $shop_user_result;
        }
        
        $shop_user_id = intval($data['wp_user_id']);
        
        // Link the NC user
        $endpoint = $this->deployer_api_url . '/api/shop-users/link/';
        
        $body = [
            'shop_user_id' => $shop_user_id,
            'user_id' => sanitize_user($data['nc_username']), // NC username
            'server_url' => $data['server_url'] ?? $this->nextcloud_api_url
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Get all Nextcloud accounts for a WP user
     */
    public function get_user_accounts($wp_user_id) {
        // Check if API is properly configured
        if (empty($this->deployer_api_url) || empty($this->deployer_api_key)) {
            return new WP_Error('not_configured', __('API is niet geconfigureerd', 'nc-woo-integration'));
        }
        
        $endpoint = $this->deployer_api_url . '/api/shop-users/nc-users/';
        
        // Use integer shop_user_id
        $shop_user_id = intval($wp_user_id);
        
        $url = add_query_arg(['shop_user_id' => $shop_user_id], $endpoint);
        
        $response = $this->make_deployer_request('GET', $url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Handle different response codes
        if ($response_code === 404) {
            // No accounts found is not an error
            return ['success' => true, 'data' => []];
        }
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', 
                sprintf(__('API returned status code %d', 'nc-woo-integration'), $response_code)
            );
        }
        
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from API', 'nc-woo-integration'));
        }
        
        // Ensure we have the expected structure
        if (!is_array($data)) {
            $data = [];
        }
        
        return ['success' => true, 'data' => $data];
    }
    
    /**
     * Update user quota
     */
    public function update_user_quota($nc_user_id, $quota, $server_url = null) {
        $endpoint = $this->deployer_api_url . '/api/subscriptions/update/';
        
        $body = [
            'user_id' => $nc_user_id,
            'quota' => $quota,
            'server_url' => $server_url ?? $this->nextcloud_api_url
        ];
        
        $response = $this->make_deployer_request('PUT', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Subscribe user (activate subscription)
     */
    public function subscribe_user($nc_user_id, $server_url, $quota) {
        $endpoint = $this->deployer_api_url . '/api/subscriptions/activate/';
        
        $body = [
            'user_id' => $nc_user_id,
            'server_url' => $server_url,
            'quota' => $quota
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Unsubscribe user (deactivate subscription)
     */
    public function unsubscribe_user($nc_user_id, $server_url) {
        $endpoint = $this->deployer_api_url . '/api/subscriptions/deactivate/';
        
        $body = [
            'user_id' => $nc_user_id,
            'server_url' => $server_url
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Check if subscription is downgradable
     */
    public function check_downgradable($nc_user_id, $server_url, $new_quota) {
        $endpoint = $this->deployer_api_url . '/api/subscriptions/downgradable/';
        
        $url = add_query_arg([
            'user_id' => $nc_user_id,
            'server_url' => $server_url,
            'quota' => $new_quota
        ], $endpoint);
        
        $response = $this->make_deployer_request('GET', $url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Add user to Nextcloud group
     */
    public function add_user_to_group($user_id, $server_url, $group) {
        $endpoint = $this->deployer_api_url . '/api/users/groups/add/';
        
        $body = [
            'user_id' => $user_id,
            'server_url' => $server_url,
            'group' => $group
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Remove user from Nextcloud group
     */
    public function remove_user_from_group($user_id, $server_url, $group) {
        $endpoint = $this->deployer_api_url . '/api/users/groups/remove/';
        
        $body = [
            'user_id' => $user_id,
            'server_url' => $server_url,
            'group' => $group
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Verify Nextcloud email
     */
    public function verify_nextcloud_email($nc_user_id, $verification_token) {
        $endpoint = $this->nextcloud_api_url . '/ocs/v2.php/apps/registration/verify/' . $verification_token;
        
        $response = wp_remote_get($endpoint, [
            'headers' => [
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
 * Verify email token with Deployer
 */
public function verify_deployer_email_token($token) {
    $endpoint = $this->deployer_api_url . '/api/email/verification/verify/' . $token . '/';
    
    $response = $this->make_deployer_request('GET', $endpoint);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    
    if ($response_code !== 200) {
        return new WP_Error('verification_failed', __('Verificatie token ongeldig of verlopen', 'nc-woo-integration'));
    }
    
    return true;
}
    
    /**
     * Make authenticated request to Deployer API
     */
    private function make_deployer_request($method, $url, $body = null) {
        // Try different authentication methods based on what the API expects
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        // Method 1: API-KEY header (zoals in de oude plugin)
        if ($this->deployer_api_key) {
            $headers['API-KEY'] = $this->deployer_api_key;
        }
        
        // Method 2: Also try Authorization Bearer
        // $headers['Authorization'] = 'Bearer ' . $this->deployer_api_key;
        
        // Method 3: Or try X-API-Key
        // $headers['X-API-Key'] = $this->deployer_api_key;
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ];
        
        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = wp_json_encode($body);
        }
        
        // Enhanced debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('NCWI API Request: ' . $method . ' ' . $url);
            error_log('NCWI API Headers: ' . print_r($headers, true));
            if ($body) {
                error_log('NCWI API Body: ' . print_r($body, true));
            }
        }
        
        $response = wp_remote_request($url, $args);
        
        // Enhanced response logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (is_wp_error($response)) {
                error_log('NCWI API Error: ' . $response->get_error_message());
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                error_log('NCWI API Response Code: ' . $response_code);
                error_log('NCWI API Response Body: ' . $response_body);
            }
        }
        
        return $response;
    }
    
    /**
     * Validate API configuration
     */
    public function validate_configuration() {
        $errors = [];
        
        if (empty($this->deployer_api_url)) {
            $errors[] = __('Deployer API URL is niet geconfigureerd', 'nc-woo-integration');
        }
        
        if (empty($this->deployer_api_key)) {
            $errors[] = __('Deployer API Key is niet geconfigureerd', 'nc-woo-integration');
        }
        
        if (empty($this->nextcloud_api_url)) {
            $errors[] = __('Nextcloud API URL is niet geconfigureerd', 'nc-woo-integration');
        }
        
        if (!empty($errors)) {
            return new WP_Error('ncwi_config_error', implode(', ', $errors));
        }
        
        // Test API connection with servers endpoint (we know this works)
        $test_response = $this->make_deployer_request('GET', $this->deployer_api_url . '/api/servers/');
        
        if (is_wp_error($test_response)) {
            return new WP_Error('ncwi_connection_error', __('Kan geen verbinding maken met Deployer API', 'nc-woo-integration'));
        }
        
        $response_code = wp_remote_retrieve_response_code($test_response);
        
        // 200 means success
        if ($response_code === 200) {
            return true;
        }
        
        // 401/403 means API works but auth failed
        if ($response_code === 401 || $response_code === 403) {
            return new WP_Error('ncwi_auth_error', __('Deployer API authenticatie mislukt - controleer je API key', 'nc-woo-integration'));
        }
        
        // Other error
        return new WP_Error('ncwi_api_error', sprintf(__('Deployer API returned status code %d', 'nc-woo-integration'), $response_code));
    }
}