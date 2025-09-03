<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Ajax {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Account management
        add_action('wp_ajax_ncwi_unlink_account', [$this, 'ajax_unlink_account']);
        add_action('wp_ajax_ncwi_delete_account', [$this, 'ajax_delete_account']);
        
        // Subscription management
        add_action('wp_ajax_ncwi_link_subscription', [$this, 'ajax_link_subscription']);
        add_action('wp_ajax_ncwi_unlink_subscription', [$this, 'ajax_unlink_subscription']);
        
        // Account info
        add_action('wp_ajax_ncwi_refresh_account', [$this, 'ajax_refresh_account']);
        add_action('wp_ajax_ncwi_get_account_details', [$this, 'ajax_get_account_details']);
    // Add verification hooks
    add_action('wp_ajax_ncwi_resend_verification', [$this, 'ajax_resend_verification']);
    add_action('wp_ajax_ncwi_check_verification', [$this, 'ajax_check_verification']);
}
    
    /**
     * AJAX: Unlink account
     */
    public function ajax_unlink_account() {
        NCWI_Security::validate_ajax_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
        }
        
        $account_id = intval($_POST['account_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$account_id) {
            wp_send_json_error(__('Ongeldig account ID', 'nc-woo-integration'));
        }
        
        // Verify ownership
        global $wpdb;
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d AND user_id = %d",
            $account_id,
            $user_id
        ));
        
        if (!$account) {
            wp_send_json_error(__('Account niet gevonden', 'nc-woo-integration'));
        }
        
        // Check for active subscriptions
        $active_subs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_account_subscriptions 
             WHERE account_id = %d AND status = 'active'",
            $account_id
        ));
        
        if ($active_subs > 0) {
            wp_send_json_error(__('Kan account met actieve subscripties niet ontkoppelen', 'nc-woo-integration'));
        }
        
        // Update account status
        $wpdb->update(
            $wpdb->prefix . 'ncwi_accounts',
            ['status' => 'unlinked'],
            ['id' => $account_id],
            ['%s'],
            ['%d']
        );
        
        wp_send_json_success([
            'message' => __('Account succesvol ontkoppeld', 'nc-woo-integration')
        ]);
    }
    
    /**
     * AJAX: Delete account
     */
    public function ajax_delete_account() {
        NCWI_Security::validate_ajax_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
        }
        
        $account_id = intval($_POST['account_id'] ?? 0);
        $user_id = get_current_user_id();
        
        $account_manager = NCWI_Account_Manager::get_instance();
        $result = $account_manager->delete_account($account_id, $user_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'message' => __('Account succesvol verwijderd', 'nc-woo-integration')
        ]);
    }
    /**
 * AJAX: Check verification status
 */
public function ajax_check_verification() {
    NCWI_Security::validate_ajax_nonce();
    
    if (!is_user_logged_in()) {
        wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
    }
    
    $account_id = intval($_POST['account_id'] ?? 0);
    $user_id = get_current_user_id();
    
    // Verify ownership
    global $wpdb;
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d AND user_id = %d",
        $account_id,
        $user_id
    ));
    
    if (!$account) {
        wp_send_json_error(__('Account niet gevonden', 'nc-woo-integration'));
    }
    
    // Use the WP user ID as shop_user_id
    $shop_user_id = intval($user_id);
    
    // Check verification status via API
    $api = NCWI_API::get_instance();
    $result = $api->check_email_verification($shop_user_id, $account->nc_email);
    
    if (is_wp_error($result)) {
        wp_send_json_error(__('Kon verificatie status niet checken: ', 'nc-woo-integration') . $result->get_error_message());
    }
    
    $is_verified = isset($result['verified']) && $result['verified'] === true;
    
    // If verified, update local account status
    if ($is_verified && $account->status === 'pending_verification') {
        $wpdb->update(
            $wpdb->prefix . 'ncwi_accounts',
            ['status' => 'active'],
            ['id' => $account_id],
            ['%s'],
            ['%d']
        );
        
        // Try to complete NC user creation if needed
        $api->create_nextcloud_account([
            'wp_user_id' => $user_id,
            'username' => $account->nc_username,
            'email' => $account->nc_email,
            'quota' => get_option('ncwi_trial_quota', '1GB')
        ]);
    }
    
    wp_send_json_success([
        'verified' => $is_verified,
        'message' => $is_verified 
            ? __('Email is geverifieerd!', 'nc-woo-integration')
            : __('Email is nog niet geverifieerd', 'nc-woo-integration')
    ]);
}

    /**
     * AJAX: Link subscription to account
     */
    public function ajax_link_subscription() {
        NCWI_Security::validate_ajax_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $account_id = intval($_POST['account_id'] ?? 0);
        $user_id = get_current_user_id();
        
        // Validate subscription ownership
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription || $subscription->get_user_id() != $user_id) {
            wp_send_json_error(__('Ongeldige subscription', 'nc-woo-integration'));
        }
        
        // Validate account ownership
        global $wpdb;
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d AND user_id = %d",
            $account_id,
            $user_id
        ));
        
        if (!$account) {
            wp_send_json_error(__('Ongeldig account', 'nc-woo-integration'));
        }
        
        // Link subscription
        $subscription_handler = NCWI_Subscription_Handler::get_instance();
        $result = $subscription_handler->link_subscription_to_account($subscription_id, $account_id);
        
        if (!$result) {
            wp_send_json_error(__('Koppeling mislukt', 'nc-woo-integration'));
        }
        
        // Trigger status sync
        $subscription_handler->handle_subscription_status_change(
            $subscription,
            $subscription->get_status(),
            $subscription->get_status()
        );
        
        wp_send_json_success([
            'message' => __('Subscription succesvol gekoppeld', 'nc-woo-integration')
        ]);
    }
    
    /**
     * AJAX: Unlink subscription from account
     */
    public function ajax_unlink_subscription() {
        NCWI_Security::validate_ajax_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $account_id = intval($_POST['account_id'] ?? 0);
        $user_id = get_current_user_id();
        
        // Validate ownership
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription || $subscription->get_user_id() != $user_id) {
            wp_send_json_error(__('Ongeldige subscription', 'nc-woo-integration'));
        }
        
        // Unlink subscription
        $subscription_handler = NCWI_Subscription_Handler::get_instance();
        $result = $subscription_handler->unlink_subscription_from_account($subscription_id, $account_id);
        
        if (!$result) {
            wp_send_json_error(__('Ontkoppeling mislukt', 'nc-woo-integration'));
        }
        
        wp_send_json_success([
            'message' => __('Subscription succesvol ontkoppeld', 'nc-woo-integration')
        ]);
    }
    
    /**
     * AJAX: Refresh account info
     */
    public function ajax_refresh_account() {
        NCWI_Security::validate_ajax_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
        }
        
        $account_id = intval($_POST['account_id'] ?? 0);
        $user_id = get_current_user_id();
        
        // Get account
        global $wpdb;
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d AND user_id = %d",
            $account_id,
            $user_id
        ), ARRAY_A);
        
        if (!$account) {
            wp_send_json_error(__('Account niet gevonden', 'nc-woo-integration'));
        }
        
        // Get fresh data from API
        $api = NCWI_API::get_instance();
        $api_data = $api->get_user_accounts($user_id);
        
        if (is_wp_error($api_data)) {
            wp_send_json_error($api_data->get_error_message());
        }
        
        // Find matching account
        $updated_info = null;
        if (!empty($api_data['data'])) {
            foreach ($api_data['data'] as $api_account) {
                if ($api_account['nc_user_id'] === $account['nc_user_id']) {
                    $updated_info = $api_account;
                    break;
                }
            }
        }
        
        if (!$updated_info) {
            wp_send_json_error(__('Account info niet gevonden', 'nc-woo-integration'));
        }
        
        wp_send_json_success([
            'account' => array_merge($account, $updated_info)
        ]);
    }

    /**
 * AJAX: Resend verification email
 */
public function ajax_resend_verification() {
    NCWI_Security::validate_ajax_nonce();
    
    if (!is_user_logged_in()) {
        wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
    }
    
    $account_id = intval($_POST['account_id'] ?? 0);
    $email = sanitize_email($_POST['email'] ?? '');
    $user_id = get_current_user_id();
    
    if (!$account_id || !$email) {
        wp_send_json_error(__('Ongeldige gegevens', 'nc-woo-integration'));
    }
    
    // Verify ownership
    global $wpdb;
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d AND user_id = %d",
        $account_id,
        $user_id
    ));
    
    if (!$account) {
        wp_send_json_error(__('Account niet gevonden', 'nc-woo-integration'));
    }
    
    // Call API to resend verification
    $api = NCWI_API::get_instance();
    $shop_user_id = intval($user_id);
    $result = $api->send_email_verification($shop_user_id, $email);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    
    wp_send_json_success([
        'message' => __('Verificatie email opnieuw verstuurd!', 'nc-woo-integration')
    ]);
}
    
 /**
     * AJAX: Get account details
     */
    public function ajax_get_account_details() {
        try {
            NCWI_Security::validate_ajax_nonce();
            
            if (!is_user_logged_in()) {
                wp_send_json_error(__('Je moet ingelogd zijn', 'nc-woo-integration'));
            }
            
            $account_id = intval($_POST['account_id'] ?? 0);
            $user_id = get_current_user_id();
            
            if (!$account_id) {
                wp_send_json_error(__('Ongeldig account ID', 'nc-woo-integration'));
            }
            
            $account_manager = NCWI_Account_Manager::get_instance();
            $accounts = $account_manager->get_user_accounts($user_id);
            
            $account = null;
            foreach ($accounts as $acc) {
                if ($acc['id'] == $account_id) {
                    $account = $acc;
                    break;
                }
            }
            
            if (!$account) {
                wp_send_json_error(__('Account niet gevonden', 'nc-woo-integration'));
            }
            
            // Get available subscriptions to link
            $available_subs = [];
            
            // Check if WooCommerce Subscriptions is active
            if (function_exists('wcs_get_subscriptions')) {
                $subscriptions = wcs_get_subscriptions(['customer_id' => $user_id]);
                
                foreach ($subscriptions as $subscription) {
                    // Check if already linked to ANY account
                    global $wpdb;
                    $linked = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_account_subscriptions 
                         WHERE subscription_id = %d",
                        $subscription->get_id()
                    ));
                    
                    // Only show if not linked and in active/pending status
                    if (!$linked && in_array($subscription->get_status(), ['active', 'pending'])) {
                        // Get subscription items
                        $items = $subscription->get_items();
                        $item_names = [];
                        foreach ($items as $item) {
                            $item_names[] = $item->get_name();
                        }
                        
                        $available_subs[] = [
                            'id' => $subscription->get_id(),
                            'name' => !empty($item_names) ? implode(', ', $item_names) : $subscription->get_formatted_order_total(),
                            'status' => $subscription->get_status(),
                            'next_payment' => $subscription->get_date('next_payment')
                        ];
                    }
                }
            }
            
            wp_send_json_success([
                'account' => $account,
                'available_subscriptions' => $available_subs
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Er is een fout opgetreden: ', 'nc-woo-integration') . $e->getMessage());
        }
    }
}