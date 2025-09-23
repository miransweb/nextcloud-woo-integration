<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Subscription_Handler {
    
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
        // Subscription status changes
        add_action('woocommerce_subscription_status_updated', [$this, 'handle_subscription_status_change'], 10, 3);
        
        // Payment complete
        add_action('woocommerce_subscription_payment_complete', [$this, 'handle_payment_complete'], 10, 1);
        
        // Payment failed
        add_action('woocommerce_subscription_payment_failed', [$this, 'handle_payment_failed'], 10, 1);
        
        // Subscription created
        add_action('woocommerce_checkout_subscription_created', [$this, 'handle_subscription_created'], 10, 3);
        
        // Subscription upgraded/downgraded
        add_action('woocommerce_subscriptions_switched_item', [$this, 'handle_subscription_switch'], 10, 3);
        
        // Subscription renewal
        add_action('woocommerce_subscription_renewal_payment_complete', [$this, 'handle_renewal_complete'], 10, 1);
        
        // Scheduled sync
        add_action('ncwi_sync_subscriptions', [$this, 'sync_all_subscriptions']);
        
        // Schedule cron job
        if (!wp_next_scheduled('ncwi_sync_subscriptions')) {
            wp_schedule_event(time(), get_option('ncwi_sync_interval', 'hourly'), 'ncwi_sync_subscriptions');
        }
    }
    
    /**
     * Handle subscription status changes
     */
    public function handle_subscription_status_change($subscription, $new_status, $old_status) {
        // Get linked Nextcloud accounts
        $linked_accounts = $this->get_linked_accounts($subscription->get_id());
        
        if (empty($linked_accounts)) {
            return;
        }
        
        foreach ($linked_accounts as $account) {
            switch ($new_status) {
                case 'active':
                    $this->activate_subscription($account, $subscription);
                    break;
                    
                case 'on-hold':
                case 'cancelled':
                    $this->deactivate_subscription($account, $subscription);
                    break;
                    
                case 'expired':
                    $this->expire_subscription($account, $subscription);
                    break;
            }
        }
    }
    
    /**
     * Handle payment complete
     */ 
    public function handle_payment_complete($subscription) {
    error_log('NCWI: handle_payment_complete called for subscription ID: ' . $subscription->get_id());
    
    // Get only the linked accounts for this subscription
    $linked_accounts = $this->get_linked_accounts($subscription->get_id());
    
    if (empty($linked_accounts)) {
        error_log('NCWI: No linked accounts found for subscription ID: ' . $subscription->get_id());
        return;
    }
    
    error_log('NCWI: Found ' . count($linked_accounts) . ' linked accounts for this subscription');
    
    foreach ($linked_accounts as $account) {
        error_log('NCWI: Processing linked account - NC User: ' . $account['nc_user_id']);
        
        // Move from trial to paid group
        $group_result = $this->api->update_user_group($account['nc_user_id'], 'paid');
        
        if (is_wp_error($group_result)) {
            error_log('NCWI ERROR: Failed to update user group: ' . $group_result->get_error_message());
            // Continue with other updates even if group update fails
        } else {
            error_log('NCWI: Successfully updated user group to paid');
        }
        
        // Update quota based on subscription
        $quota = $this->get_subscription_quota($subscription);
        error_log('NCWI: Setting quota to: ' . $quota);
        
        $quota_result = $this->api->update_user_quota($account['nc_user_id'], $quota);
        
        if (is_wp_error($quota_result)) {
            error_log('NCWI ERROR: Failed to update quota: ' . $quota_result->get_error_message());
        } else {
            error_log('NCWI: Successfully updated quota');
        }
        
        // Enable user if disabled
        $status_result = $this->api->update_user_status($account['nc_user_id'], true);
        
        if (is_wp_error($status_result)) {
            error_log('NCWI ERROR: Failed to update user status: ' . $status_result->get_error_message());
        } else {
            error_log('NCWI: Successfully enabled user');
        }
        
        // Update local database to track the active subscription
        $this->update_account_subscription_status($account['id'], $subscription->get_id(), 'active');
        
        error_log('NCWI: Completed processing for linked account ' . $account['nc_user_id']);
    }
}
    
    /**
     * Handle payment failed
     */
    public function handle_payment_failed($subscription) {
        $linked_accounts = $this->get_linked_accounts($subscription->get_id());
        
        foreach ($linked_accounts as $account) {
            // Disable user access
            $this->api->update_user_status($account['nc_user_id'], false);
            
            // Update local status
            $this->update_account_subscription_status($account['id'], $subscription->get_id(), 'suspended');
            
            // Send notification email
            $this->send_payment_failed_notification($account, $subscription);
        }
    }
    
    /**
     * Handle new subscription created
     */
    public function handle_subscription_created($subscription, $order, $recurring_cart) {
        $user_id = $subscription->get_user_id();
        
        // Check if user has NC accounts
        $account_manager = NCWI_Account_Manager::get_instance();
        $accounts = $account_manager->get_user_accounts($user_id);
        
        if (empty($accounts)) {
            // Create new account if none exists
            $account_id = $account_manager->create_account($user_id, [
                'quota' => $this->get_subscription_quota($subscription)
            ]);
            
            if (!is_wp_error($account_id)) {
                $this->link_subscription_to_account($subscription->get_id(), $account_id);
            }
        }
    }
    
    /**
     * Handle subscription switch (upgrade/downgrade)
     */
    public function handle_subscription_switch($subscription, $new_item, $old_item) {
        $linked_accounts = $this->get_linked_accounts($subscription->get_id());
        
        $old_quota = $this->get_item_quota($old_item);
        $new_quota = $this->get_item_quota($new_item);
        
        foreach ($linked_accounts as $account) {
            // Update quota
            $result = $this->api->update_user_quota($account['nc_user_id'], $new_quota);
            
            if (!is_wp_error($result)) {
                // Update local database
                $this->update_account_subscription_quota($account['id'], $subscription->get_id(), $new_quota);
                
                // Log the change
                $this->log_quota_change($account, $old_quota, $new_quota);
            }
        }
    }
    
   /**
     * Link subscription to Nextcloud account
     */
    public function link_subscription_to_account($subscription_id, $account_id) {
        global $wpdb;
        
        // Check if WooCommerce Subscriptions is active
        if (!function_exists('wcs_get_subscription')) {
            return new WP_Error('no_subscriptions_plugin', __('WooCommerce Subscriptions is niet actief', 'nc-woo-integration'));
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return false;
        }
        
        // Check if already linked
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_account_subscriptions 
             WHERE account_id = %d AND subscription_id = %d",
            $account_id,
            $subscription_id
        ));
        
        if ($exists) {
            return true; // Already linked
        }
        
        $quota = $this->get_subscription_quota($subscription);
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ncwi_account_subscriptions',
            [
                'account_id' => $account_id,
                'subscription_id' => $subscription_id,
                'quota' => $quota,
                'status' => $subscription->get_status(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            error_log('NCWI DB Error: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Unlink subscription from account
     */
    public function unlink_subscription_from_account($subscription_id, $account_id) {
        global $wpdb;
        
        return $wpdb->delete(
            $wpdb->prefix . 'ncwi_account_subscriptions',
            [
                'subscription_id' => $subscription_id,
                'account_id' => $account_id
            ],
            ['%d', '%d']
        );
    }
    
    /**
     * Get linked Nextcloud accounts for a subscription
     */
    private function get_linked_accounts($subscription_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, s.quota, s.status as link_status
             FROM {$wpdb->prefix}ncwi_accounts a
             INNER JOIN {$wpdb->prefix}ncwi_account_subscriptions s ON a.id = s.account_id
             WHERE s.subscription_id = %d",
            $subscription_id
        ), ARRAY_A);
    }
    
    /**
     * Get subscription quota
     */
    public function get_subscription_quota($subscription) {
        // Check product meta for quota
        foreach ($subscription->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $quota = $product->get_meta('_ncwi_quota');
                if ($quota) {
                    return $quota;
                }
            }
        }
        
        // Default quota based on subscription total
        $total = $subscription->get_total();
        if ($total >= 20) {
            return '100GB';
        } elseif ($total >= 10) {
            return '50GB';
        } else {
            return '10GB';
        }
    }
    
    /**
     * Get item quota
     */
    private function get_item_quota($item) {
        $product = wc_get_product($item['product_id']);
        if ($product) {
            return $product->get_meta('_ncwi_quota') ?: '10GB';
        }
        return '10GB';
    }
    
    /**
     * Activate subscription
     */
    private function activate_subscription($account, $subscription) {
        // Enable user
        $this->api->update_user_status($account['nc_user_id'], true);
        
        // Update quota
        $quota = $this->get_subscription_quota($subscription);
        $this->api->update_user_quota($account['nc_user_id'], $quota);
        
        // Move to paid group
        $this->api->update_user_group($account['nc_user_id'], 'paid');
        
        // Update local status
        $this->update_account_subscription_status($account['id'], $subscription->get_id(), 'active');
    }
    
    /**
     * Deactivate subscription
     */
    private function deactivate_subscription($account, $subscription) {
        // Disable user
        $this->api->update_user_status($account['nc_user_id'], false);
        
        // Update local status
        $this->update_account_subscription_status($account['id'], $subscription->get_id(), 'inactive');
    }
    
    /**
     * Handle expired subscription
     */
    private function expire_subscription($account, $subscription) {
        // Move to trial group with reduced quota
        $this->api->update_user_group($account['nc_user_id'], 'trial');
        $this->api->update_user_quota($account['nc_user_id'], get_option('ncwi_trial_quota', '1GB'));
        
        // Update local status
        $this->update_account_subscription_status($account['id'], $subscription->get_id(), 'expired');
    }
    
    /**
     * Update account subscription status
     */
    private function update_account_subscription_status($account_id, $subscription_id, $status) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ncwi_account_subscriptions',
            ['status' => $status, 'updated_at' => current_time('mysql')],
            ['account_id' => $account_id, 'subscription_id' => $subscription_id],
            ['%s', '%s'],
            ['%d', '%d']
        );
    }
    
    /**
     * Update account subscription quota
     */
    private function update_account_subscription_quota($account_id, $subscription_id, $quota) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ncwi_account_subscriptions',
            ['quota' => $quota, 'updated_at' => current_time('mysql')],
            ['account_id' => $account_id, 'subscription_id' => $subscription_id],
            ['%s', '%s'],
            ['%d', '%d']
        );
    }
    
    /**
     * Sync all subscriptions
     */
    public function sync_all_subscriptions() {
        global $wpdb;
        
        // Get all active account-subscription links
        $links = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ncwi_account_subscriptions WHERE status = 'active'",
            ARRAY_A
        );
        
        foreach ($links as $link) {
            $subscription = wcs_get_subscription($link['subscription_id']);
            if ($subscription) {
                // Sync status
                if ($subscription->get_status() !== 'active') {
                    $this->handle_subscription_status_change($subscription, $subscription->get_status(), 'active');
                }
                
                // Sync quota
                $current_quota = $this->get_subscription_quota($subscription);
                if ($current_quota !== $link['quota']) {
                    $account = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE id = %d",
                        $link['account_id']
                    ), ARRAY_A);
                    
                    if ($account) {
                        $this->api->update_user_quota($account['nc_user_id'], $current_quota);
                        $this->update_account_subscription_quota($link['account_id'], $link['subscription_id'], $current_quota);
                    }
                }
            }
        }
    }
    
    /**
     * Send payment failed notification
     */
    private function send_payment_failed_notification($account, $subscription) {
        $user = get_user_by('id', $account['user_id']);
        if (!$user) {
            return;
        }
        
        $subject = sprintf(__('Payment failed - %s', 'nc-woo-integration'), get_bloginfo('name'));
        
        $message = sprintf(
            __("Hallo %s,\n\nThe payment for your Nextcloud subscription failed.\n\nYour Nextcloud account '%s' is temporarily disabled.\n\nLog in to your account to complete payment:\n%s\n\nKind regards,\n%s", 'nc-woo-integration'),
            $user->display_name,
            $account['nc_username'],
            wc_get_account_endpoint_url('subscriptions'),
            get_bloginfo('name')
        );
        
        wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Log quota changes
     */
    private function log_quota_change($account, $old_quota, $new_quota) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'NCWI Quota Change: Account %s changed from %s to %s',
                $account['nc_username'],
                $old_quota,
                $new_quota
            ));
        }
    }
}