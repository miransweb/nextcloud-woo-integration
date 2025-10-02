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
       // $this->nextcloud_api_url = get_option('ncwi_nextcloud_api_url');
    }

   
    
    /**
     * Create or get shop user
     */
    public function create_shop_user($data) {
        $shop_user_id = intval($data['wp_user_id']);
        
        // First check if shop user already exists
        $check_endpoint = $this->deployer_api_url . '/api/shop-users/' . $shop_user_id . '/';
        $check_response = $this->make_deployer_request('GET', $check_endpoint);
        
        if (!is_wp_error($check_response)) {
            $response_code = wp_remote_retrieve_response_code($check_response);
            
            if ($response_code === 200) {
                // Shop user exists already
                error_log('NCWI: Shop user already exists, not creating duplicate');
                $existing_data = json_decode(wp_remote_retrieve_body($check_response), true);
                
                // Maybe update email if changed
                if (isset($existing_data['email']) && $existing_data['email'] !== $data['email']) {
                    $update_endpoint = $this->deployer_api_url . '/api/shop-users/' . $shop_user_id . '/';
                    $update_response = $this->make_deployer_request('PATCH', $update_endpoint, [
                        'email' => $data['email']
                    ]);
                }
                
                return $existing_data;
            }
        }
        
        // Only create if doesn't exist
        $endpoint = $this->deployer_api_url . '/api/shop-users/';
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
     * Check if NC user exists
     */
    public function check_nc_user_exists($email_or_userid, $server_url = null) {
    $endpoint = $this->deployer_api_url . '/api/users/quota/';  
    
    $params = [
        'user_id' => $email_or_userid,
        'server_url' => $server_url
    ];
    
    $url = add_query_arg($params, $endpoint);
    $response = $this->make_deployer_request('GET', $url);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 200) {
        // User exists if we get a 200 response from quota endpoint
        return true;  
    }
    
    return false;
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
        error_log('NCWI Debug - create_nextcloud_account called with data: ' . json_encode($data));

        if (!session_id()) {
        session_start();
    }
    
    $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
    $server_url = $nc_data['server'] ?? null;
    
    if (!$server_url) {
        return new WP_Error('missing_server_url', __('Server URL is required for account creation', 'nc-woo-integration'));
    }
        
        // CRITICAL FIX: First check if NC user already exists
        $nc_user_exists = $this->check_nc_user_exists($data['email'], $server_url);
        
        if ($nc_user_exists) {
            error_log('NCWI: NC user with email ' . $data['email'] . ' already exists in Deployer');
            
            // User already exists in NC/Deployer - just link to shop_user
            $shop_user_id = intval($data['wp_user_id']);
            
            // Create or update shop_user
            $user = get_user_by('id', $data['wp_user_id']);
            $shop_user_result = $this->create_shop_user([
                'email' => $data['email'],
                'wp_user_id' => $data['wp_user_id'],
                'name' => $user ? $user->display_name : $data['username']
            ]);
            
            if (is_wp_error($shop_user_result)) {
                error_log('NCWI ERROR: Failed to create shop_user: ' . $shop_user_result->get_error_message());
                // Continue anyway - NC user exists
            }
            
            // Link existing NC user to shop_user
            $link_endpoint = $this->deployer_api_url . '/api/shop-users/link/';
            $link_body = [
                'shop_user_id' => $shop_user_id,
                'user_id' => $data['email'], // NC email as user_id
                'server_url' => $this->nextcloud_api_url
            ];
            
            $link_response = $this->make_deployer_request('POST', $link_endpoint, $link_body);
            
            if (!is_wp_error($link_response)) {
                error_log('NCWI: Successfully linked existing NC user to shop_user');
            } else {
                error_log('NCWI: Failed to link NC user to shop_user: ' . $link_response->get_error_message());
            }
            
            return [
                'success' => true,
                'data' => [
                    'username' => $data['email'],
                    'email' => $data['email'],
                    'server' => $this->nextcloud_api_url,
                    'nc_user_id' => $data['email'],
                    'existing_user' => true
                ]
            ];
        }
        
        // User doesn't exist yet - proceed with creation
        error_log('NCWI: NC user does not exist yet, proceeding with creation');
        
        // First ensure shop user exists
        $user = get_user_by('id', $data['wp_user_id']);
        $shop_user_result = $this->create_shop_user([
            'email' => $data['email'],
            'wp_user_id' => $data['wp_user_id'],
            'name' => $user ? $user->display_name : $data['username']
        ]);
        
        if (is_wp_error($shop_user_result) && $shop_user_result->get_error_code() !== 'shop_user_exists') {
            return $shop_user_result;
        }
        
        // Get shop_user_id as integer
        $shop_user_id = intval($data['wp_user_id']);
        
        // Check if account is already active locally
        global $wpdb;
        $existing_active = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts 
             WHERE user_id = %d AND status = 'active' AND nc_email = %s",
            $data['wp_user_id'],
            $data['email']
        ));
        
        // If account is already active locally, skip verification
        if (!$existing_active) {
            // Check email verification
            $verification_status = $this->check_email_verification($shop_user_id, $data['email']);
            
            if (is_wp_error($verification_status)) {
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
                }
            }
        }
        
        // Create NC user
        $endpoint = $this->deployer_api_url . '/api/users/';
        
        // Generate a secure password
        $password = $this->generate_nextcloud_password(16);
        
        $body = [
            'shop_user_id' => $shop_user_id,
            'email' => sanitize_email($data['email']),
            'username' => $data['username'] ?? $data['email'],
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
        // For existing NC users (like trial users upgrading)
        // We only need to create/update the shop_user and link them
        
        $shop_user_id = intval($data['wp_user_id']);
        
        // Create or get shop_user
        $user = get_user_by('id', $data['wp_user_id']);
        $shop_user_result = $this->create_shop_user([
            'email' => $data['nc_email'],
            'wp_user_id' => $data['wp_user_id'],
            'name' => $user ? $user->display_name : $data['nc_username']
        ]);
        
        if (is_wp_error($shop_user_result)) {
            return $shop_user_result;
        }
        
        // Link the existing NC user to shop_user
        $endpoint = $this->deployer_api_url . '/api/shop-users/link/';
        
        $body = [
            'shop_user_id' => $shop_user_id,
            'user_id' => $data['nc_email'], // Use email as user_id for NC
            'server_url' => $data['server_url'] ?? $this->nextcloud_api_url
        ];
        
        $response = $this->make_deployer_request('POST', $endpoint, $body);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200 && $response_code !== 201) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            return new WP_Error('link_failed', $error_body['error'] ?? 'Failed to link account');
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

        if (empty($server_url)) {
        return new WP_Error('missing_server_url', __('Server URL is required', 'nc-woo-integration'));
    }
        
        $body = [
            'user_id' => $nc_user_id,
            'quota' => $quota,
            'server_url' => $server_url
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
     * Update user group - moves user between TGC groups
     * This method is called when subscription status changes
     */
    public function update_user_group($nc_user_id, $new_group, $server_url = null) {
        // Define TGC groups
        $tgc_groups = [
            'paid' => 'tgcusers_paid',
            'trial' => 'tgcusers_trial',
            'unsubscribed' => 'tgcusers_unsubscribed'
        ];

        if (empty($server_url)) {
        return new WP_Error('missing_server_url', __('Server URL is required', 'nc-woo-integration'));
    }
        
        // Validate group
        if (!isset($tgc_groups[$new_group])) {
            return new WP_Error('invalid_group', __('Invalid group specified', 'nc-woo-integration'));
        }
        
        $target_group = $tgc_groups[$new_group];
        $server_url = $server_url;
        
        // First, remove user from all TGC groups
        foreach ($tgc_groups as $group_key => $group_name) {
            if ($group_key !== $new_group) {
                $this->remove_user_from_group($nc_user_id, $server_url, $group_name);
            }
        }
        
        // Now add user to the target group
        $add_result = $this->add_user_to_group($nc_user_id, $server_url, $target_group);
        
        if (is_wp_error($add_result)) {
            error_log(sprintf(
                'NCWI ERROR: Failed to add user %s to group %s: %s',
                $nc_user_id,
                $target_group,
                $add_result->get_error_message()
            ));
            return $add_result;
        }
        
        return $add_result;
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
     * Generate a password that meets Nextcloud requirements
     * At least 1 uppercase, 1 lowercase, 1 number, 1 special character
     */
    private function generate_nextcloud_password($length = 16) {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        
        // Ensure at least one of each type
        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        
        // Fill the rest randomly
        $all_chars = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all_chars[random_int(0, strlen($all_chars) - 1)];
        }
        
        // Shuffle the password so required chars aren't always at the start
        return str_shuffle($password);
    }
    
    /**
     * Make authenticated request to Deployer API
     */
    private function make_deployer_request($method, $url, $body = null) {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        // Method 1: API-KEY header (as in old plugin)
        if ($this->deployer_api_key) {
            $headers['API-KEY'] = $this->deployer_api_key;
        }
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ];
        
        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = wp_json_encode($body);
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
                if ($response_code !== 200 && $response_code !== 201) {
                    error_log('NCWI API Response Body: ' . $response_body);
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Validate API configuration
     */
    public function validate_configuration() {
        return true; 
    }
}