<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Account_Manager {
    
    private static $instance = null;
    private $api;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api = NCWI_API::get_instance();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Handle account creation during WP registration
        add_action('user_register', [$this, 'handle_user_registration'], 10, 1);
        
        // Handle account creation form submission
        add_action('wp_ajax_ncwi_create_account', [$this, 'ajax_create_account']);
        add_action('wp_ajax_ncwi_link_account', [$this, 'ajax_link_account']);
        
        // Email verification
        add_action('init', [$this, 'handle_email_verification']);
        add_action('init', [$this, 'handle_deployer_verification']);
        
    }
    
    /**
     * Create a new Nextcloud account
     */
    public function create_account($user_id, $data = []) {
    error_log('NCWI Debug - data username: ' . ($data['username'] ?? 'EMPTY'));
error_log('NCWI Debug - data email: ' . ($data['email'] ?? 'EMPTY'));
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('invalid_user', __('Invalid user', 'nc-woo-integration'));
        }
        
        // Prepare account data
        $account_data = wp_parse_args($data, [
            'email' => $user->user_email,
            'username' => $this->generate_nc_username($user),
            'wp_user_id' => $user_id,
            'quota' => get_option('ncwi_trial_quota', '1GB')
        ]);
        
        error_log('NCWI Debug - account_data username: ' . ($account_data['username'] ?? 'EMPTY'));
error_log('NCWI Debug - account_data email: ' . ($account_data['email'] ?? 'EMPTY'));
error_log('NCWI Debug - account_data wp_user_id: ' . ($account_data['wp_user_id'] ?? 'EMPTY'));
error_log('NCWI Debug - Checking API instance: ' . get_class($this->api));
error_log('NCWI Debug - account_data right before API call: ' . json_encode($account_data));

        // Create account via API
        $result = $this->api->create_nextcloud_account($account_data);
        error_log('NCWI Debug - API call completed, result type: ' . gettype($result));
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (empty($result['success'])) {
            // Check if it's pending verification
            if (isset($result['data']['status']) && $result['data']['status'] === 'pending_verification') {
                // Store account as pending
                $account_id = $this->store_account([
                    'user_id' => $user_id,
                    'nc_username' => $account_data['username'],
                    'nc_email' => $account_data['email'],
                    'nc_server' => get_option('ncwi_nextcloud_api_url'),
                    'nc_user_id' => 'pending_' . $user_id,
                    'status' => 'pending_verification'
                ]);
                
                if (is_wp_error($account_id)) {
                    return $account_id;
                }
                
                return [
                    'account_id' => $account_id,
                    'status' => 'pending_verification',
                    'message' => $result['data']['message']
                ];
            }
            
            return new WP_Error('api_error', __('Account creation failed', 'nc-woo-integration'));
        }
        
        if (empty($result['data'])) {
            return new WP_Error('api_error', __('No data from API', 'nc-woo-integration'));
        }
        
        // Store account in database
        $account_id = $this->store_account([
            'user_id' => $user_id,
            'nc_username' => $result['data']['username'] ?? $account_data['username'],
            'nc_email' => $result['data']['email'] ?? $account_data['email'],
            'nc_server' => $result['data']['server'] ?? get_option('ncwi_nextcloud_api_url'),
            'nc_user_id' => $result['data']['nc_user_id'] ?? $result['data']['user_id'] ?? $account_data['username'],
            'status' => 'active'
        ]);
        
        if (is_wp_error($account_id)) {
            return $account_id;
        }
        
        // Send welcome email with credentials
        if (!empty($result['password'])) {
    $this->send_welcome_email($account_id, array_merge($result['data'], [
        'password' => $result['password']
    ]));
}
        
        return $account_id;
    }
    
    /**
     * Send welcome email with credentials
     */
   private function send_welcome_email($account_id, $account_data) {
    $subject = sprintf(__('Your Nextcloud account is created - %s', 'nc-woo-integration'), get_bloginfo('name'));
    
    $server_url = $account_data['server'] ?? get_option('ncwi_nextcloud_api_url');
    
    $message = sprintf(
    __("Hello,\n\nJe Nextcloud account is created!\n\nYour account:\nUsername: %s\nServer: %s\n\nTo set your password:\n1. Go to the server URL above\n2. Click on 'Reset password'\n3. Enter your username '%s' in\n4. You will receive an email to set your password\n\nYours sincerely,\n%s", 'nc-woo-integration'),
    $account_data['username'],
    $server_url,
    $account_data['username'],
    get_bloginfo('name')
);
    
    wp_mail($account_data['email'], $subject, $message);
}

   
    
    /**
     * Link existing Nextcloud account
     */
    public function link_existing_account($user_id, $nc_credentials) {
        // Validate credentials
        $validated = $this->validate_nc_credentials($nc_credentials);
        if (is_wp_error($validated)) {
            return $validated;
        }
        
        // Link via API
        $result = $this->api->link_existing_account([
            'nc_username' => $nc_credentials['username'],
            'nc_email' => $nc_credentials['email'],
            'wp_user_id' => $user_id
        ]);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
       $account_data = [
        'user_id' => $user_id,
        'nc_username' => $nc_credentials['username'],
        'nc_email' => $nc_credentials['email'],
        'nc_server' => get_option('ncwi_nextcloud_api_url'),
        'nc_user_id' => $nc_credentials['email'], // Email is the NC user ID
        'status' => 'active'
    ];
    
    // Store account in database
    $account_id = $this->store_account($account_data);
    
    return $account_id;
}

    
    
  /**
 * Get all Nextcloud accounts for a user
 */
public function get_user_accounts($user_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ncwi_accounts';
    
    // Get accounts from local database
    $accounts = $wpdb->get_results($wpdb->prepare(
        "SELECT a.*, 
                GROUP_CONCAT(DISTINCT s.subscription_id) as subscription_ids,
                GROUP_CONCAT(DISTINCT s.quota) as quotas
         FROM $table_name a
         LEFT JOIN {$wpdb->prefix}ncwi_account_subscriptions s ON a.id = s.account_id
         WHERE a.user_id = %d
         GROUP BY a.id
         ORDER BY a.created_at DESC",
        $user_id
    ), ARRAY_A);

    if (!$accounts) {
        return [];
    }
    
    // Get active accounts from Deployer API
    $api_result = $this->api->get_user_accounts($user_id);
    $active_accounts = [];
    
    if (!is_wp_error($api_result) && !empty($api_result['success']) && !empty($api_result['data']['nc_users'])) {
        // API returns ['success' => true, 'data' => ['nc_users' => [...], 'total_count' => x]]
        foreach ($api_result['data']['nc_users'] as $api_account) {
            $active_accounts[] = $api_account['user_id']; 
        }
    }
    
    // Process accounts and filter out deleted ones
    $valid_accounts = [];
    
    foreach ($accounts as &$account) {
        // Skip if no API data available (don't mark as deleted)
        if (empty($active_accounts)) {
            // No API data, just include the account without marking as deleted
            $valid_accounts[] = $account;
            continue;
        }
        
        // Check if account still exists in Deployer
        if (!in_array($account['nc_user_id'], $active_accounts)) {
            // Account doesn't exist anymore, mark as deleted
            $wpdb->update(
                $wpdb->prefix . 'ncwi_accounts',
                ['status' => 'deleted'],
                ['id' => $account['id']],
                ['%s'],
                ['%d']
            );
            
            // Skip this account - don't add to valid accounts
            continue;
        }
        
        // Account exists, add extra info from API if available
        if (!is_wp_error($api_result) && !empty($api_result['data']['nc_users'])) {
            foreach ($api_result['data']['nc_users'] as $api_account) {
                if ($api_account['user_id'] === $account['nc_user_id']) {
                   
                    $account['current_quota'] = $api_account['quota'] ?? null;
                    $account['server_url'] = $api_account['server_url'] ?? $account['nc_server'];
                    break;
                }
            }
        }
        
        // Process subscription info
        if (!empty($account['subscription_ids'])) {
            $subscription_ids = explode(',', $account['subscription_ids']);
            $quotas = explode(',', $account['quotas']);
            
            $account['subscriptions'] = [];
            
            foreach ($subscription_ids as $index => $sub_id) {
                if (function_exists('wcs_get_subscription')) {
                    $subscription = wcs_get_subscription($sub_id);
                    if ($subscription) {
                        $account['subscriptions'][] = [
                            'id' => $sub_id,
                            'status' => $subscription->get_status(),
                            'quota' => isset($quotas[$index]) ? $quotas[$index] : '',
                            'next_payment' => $subscription->get_date('next_payment')
                        ];
                    }
                } else {
                    // Fallback if subscriptions plugin not active
                    $account['subscriptions'][] = [
                        'id' => $sub_id,
                        'status' => 'unknown',
                        'quota' => isset($quotas[$index]) ? $quotas[$index] : '',
                        'next_payment' => null
                    ];
                }
            }
        } else {
            $account['subscriptions'] = [];
        }
        
        // Clean up temporary fields
        unset($account['subscription_ids']);
        unset($account['quotas']);
        
        // Add to valid accounts
        $valid_accounts[] = $account;
    }
    
    return $valid_accounts;
}
    
    /**
     * Delete Nextcloud account
     */
    public function delete_account($account_id, $user_id) {
        global $wpdb;
        
        // Verify ownership
        $account = $this->get_account($account_id);
        if (!$account || $account['user_id'] != $user_id) {
            return new WP_Error('unauthorized', __('No access to this account', 'nc-woo-integration'));
        }
        
        // Check for active subscriptions
        $table_name = $wpdb->prefix . 'ncwi_account_subscriptions';
        $active_subs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE account_id = %d AND status = 'active'",
            $account_id
        ));
        
        if ($active_subs > 0) {
            return new WP_Error('active_subscriptions', __('Can not delete account with active subscriptions', 'nc-woo-integration'));
        }
        
        // Delete from database
        $wpdb->delete($wpdb->prefix . 'ncwi_accounts', ['id' => $account_id]);
        $wpdb->delete($wpdb->prefix . 'ncwi_account_subscriptions', ['account_id' => $account_id]);
        
        return true;
    }
    
    /**
     * Handle user registration
     */
    public function handle_user_registration($user_id) {
        // Check if auto-create is enabled
        if (get_option('ncwi_auto_create_on_register', 'no') !== 'yes') {
            return;
        }
        
        // Create trial account
        $this->create_account($user_id, [
            'quota' => get_option('ncwi_trial_quota', '1GB')
        ]);
    }
    
    /**
     * AJAX handler for account creation
     */
    public function ajax_create_account() {
        check_ajax_referer('ncwi_ajax_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You have to be logged in', 'nc-woo-integration'));
        }
        
        $user_id = get_current_user_id();
        $username = sanitize_user($_POST['username'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');

        error_log('NCWI Debug - POST data: ' . json_encode($_POST));
error_log('NCWI Debug - username from POST: ' . ($_POST['username'] ?? 'NOT SET'));
error_log('NCWI Debug - email from POST: ' . ($_POST['email'] ?? 'NOT SET'));
        
        if (empty($username) || empty($email)) {
            wp_send_json_error(__('Username and email are required', 'nc-woo-integration'));
        }
        
        $result = $this->create_account($user_id, [
            'username' => $username,
            'email' => $email
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'message' => __('Account successfully created. Check your email for verification.', 'nc-woo-integration'),
            'account_id' => $result
        ]);
    }
    
    /**
     * AJAX handler for linking existing account
     */
    public function ajax_link_account() {
        check_ajax_referer('ncwi_ajax_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You have to be logged in', 'nc-woo-integration'));
        }
        
        $user_id = get_current_user_id();
        $username = sanitize_user($_POST['username'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($email) || empty($password)) {
            wp_send_json_error(__('All fields are required', 'nc-woo-integration'));
        }
        
        $result = $this->link_existing_account($user_id, [
            'username' => $username,
            'email' => $email,
            'password' => $password
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'message' => __('Account ssuccessfully linked', 'nc-woo-integration'),
            'account_id' => $result
        ]);
    }
    
    /**
     * Handle email verification
     */
    public function handle_email_verification() {
        if (!isset($_GET['ncwi_verify']) || !isset($_GET['token'])) {
            return;
        }
        
        $account_id = intval($_GET['ncwi_verify']);
        $token = sanitize_text_field($_GET['token']);
        
        $account = $this->get_account($account_id);
        if (!$account) {
            wp_die(__('Invalid verification link', 'nc-woo-integration'));
        }
        
        // Verify token
        $stored_token = get_transient('ncwi_verify_' . $account_id);
        if ($stored_token !== $token) {
            wp_die(__('Verification token expired or invalid', 'nc-woo-integration'));
        }
        
        // Verify via Nextcloud API
        $result = $this->api->verify_nextcloud_email($account['nc_user_id'], $token);
        
        if (is_wp_error($result)) {
            wp_die(__('Verification failed: ', 'nc-woo-integration') . $result->get_error_message());
        }
        
        // Update account status
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ncwi_accounts',
            ['status' => 'active'],
            ['id' => $account_id]
        );
        
        // Delete token
        delete_transient('ncwi_verify_' . $account_id);
        
        // Redirect to success page
        wp_redirect(wc_get_account_endpoint_url('nextcloud-accounts') . '?verified=1');
        exit;
    }

    /**
 * Handle email verification from Deployer
 */
public function handle_deployer_verification() {
    if (!isset($_GET['ncwi_deployer_verify']) || !isset($_GET['token'])) {
        return;
    }
    
    $token = sanitize_text_field($_GET['token']);
    
    // Verify token via Deployer API
    $api = NCWI_API::get_instance();
    $result = $api->verify_deployer_email_token($token);
    
    if (is_wp_error($result)) {
        wp_die(__('Email verification failed: ', 'nc-woo-integration') . $result->get_error_message());
    }

    
    
    // Find pending accounts for current user (if logged in) or based on session
    if (is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        
       $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ncwi_accounts 
             SET nc_user_id = nc_email, 
                 status = 'active' 
             WHERE user_id = %d 
             AND nc_user_id LIKE 'pending_%'",
            $user_id
        ));
       
    }
    
    // Success! Redirect to account page
    wp_redirect(wc_get_account_endpoint_url('nextcloud-accounts') . '?deployer_verified=1');
    exit;
}
    
    /**
     * Generate unique Nextcloud username
     */
    private function generate_nc_username($user) {
        $base_username = sanitize_user($user->user_login);
        $username = $base_username;
        $counter = 1;
        
        // Check if username exists
        while ($this->nc_username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Check if Nextcloud username exists
     */
    private function nc_username_exists($username) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_accounts WHERE nc_username = %s",
            $username
        ));
        
        return $exists > 0;
    }
    
    /**
     * Validate Nextcloud credentials
     */
    private function validate_nc_credentials($credentials) {
        // Basic validation
        if (empty($credentials['username']) || empty($credentials['email']) || empty($credentials['password'])) {
            return new WP_Error('missing_fields', __('All fields are required', 'nc-woo-integration'));
        }
        
        if (!is_email($credentials['email'])) {
            return new WP_Error('invalid_email', __('Invalid email address', 'nc-woo-integration'));
        }
        
        // Additional validation can be added here
        
        return true;
    }
    
    /**
     * Store account in database
     */
    private function store_account($data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ncwi_accounts',
            $data,
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            return new WP_Error('db_error', __('Database error saving account', 'nc-woo-integration'));
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get single account
     */
    private function get_account($account_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d",
            $account_id
        ), ARRAY_A);
    }
    
    /**
     * Send verification email
     */
    private function send_verification_email($account_id, $account_data) {
        $token = wp_generate_password(32, false);
        set_transient('ncwi_verify_' . $account_id, $token, DAY_IN_SECONDS);
        
        $verification_url = add_query_arg([
            'ncwi_verify' => $account_id,
            'token' => $token
        ], home_url());
        
        $subject = sprintf(__('Verifieer je Nextcloud account - %s', 'nc-woo-integration'), get_bloginfo('name'));
        
        $message = sprintf(
            __("Hallo,\n\nBedankt voor het aanmaken van een Nextcloud account.\n\nKlik op de volgende link om je email adres te verifiëren:\n%s\n\nJe Nextcloud gegevens:\nGebruikersnaam: %s\nWachtwoord: %s\nServer: %s\n\nBewaar deze gegevens goed!\n\nDeze link is 24 uur geldig.\n\nMet vriendelijke groet,\n%s", 'nc-woo-integration'),
            $verification_url,
            $account_data['username'],
            $account_data['password'] ?? '[Gebruik je bestaande wachtwoord]',
            $account_data['server'],
            get_bloginfo('name')
        );
        
        wp_mail($account_data['email'], $subject, $message);
    }
}