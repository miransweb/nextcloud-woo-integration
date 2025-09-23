<?php

class NCWI_Purchase_Handler {


    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook into WooCommerce order completion
        add_action('woocommerce_thankyou', [$this, 'handle_subscription_purchase'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'handle_subscription_purchase']);
        
        // Hook into subscription activation >> !!
        //add_action('woocommerce_subscription_status_active', [$this, 'handle_subscription_activation']);

        add_action('woocommerce_thankyou', [$this, 'add_nextcloud_notice_after_purchase'], 20, 1);
        
        // Add custom My Account endpoint
       // add_filter('woocommerce_account_menu_items', [$this, 'add_subscription_endpoint']);
        //add_action('init', [$this, 'add_subscription_rewrite_endpoint']);
        //add_action('woocommerce_account_view-subscription_endpoint', [$this, 'subscription_endpoint_content']);
    }
    
    /**
     * Handle subscription purchase
     */
    public function handle_subscription_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if (!$order->is_paid()) {
        error_log('NCWI: Order not paid yet, skipping linking');
        return;
    }
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        // Check if this order contains a Nextcloud subscription
        $has_nextcloud_subscription = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && strpos($product->get_slug(), 'personalstorage-subscription') !== false) {
                $has_nextcloud_subscription = true;
                break;
            }
        }
        
        if (!$has_nextcloud_subscription) return;
        
        // Get Nextcloud data from order meta (from checkout)
        $nc_server = $order->get_meta('_nextcloud_server');
        $nc_user_id = $order->get_meta('_nextcloud_user_id');
        $nc_email = $order->get_meta('_nextcloud_email');
        
        if ($nc_server && $nc_user_id) {
            // Get the subscription from the order
            if (function_exists('wcs_get_subscriptions_for_order')) {
                $subscriptions = wcs_get_subscriptions_for_order($order_id);
                
                foreach ($subscriptions as $subscription) {

                    if (!in_array($subscription->get_status(), ['active', 'pending-cancel'])) {
                    error_log('NCWI: Subscription status is ' . $subscription->get_status() . ', skipping linking');
                    continue;
                }
                    $subscription_id = $subscription->get_id();
                    
                    // Store Nextcloud info with subscription
                    update_post_meta($subscription_id, '_nextcloud_instance_url', $nc_server);
                    update_post_meta($subscription_id, '_nextcloud_user_id', $nc_user_id);
                    update_post_meta($subscription_id, '_nextcloud_email', $nc_email);

                    // Get quota voor deze subscription
                $subscription_handler = NCWI_Subscription_Handler::get_instance();
                $quota = $subscription_handler->get_subscription_quota($subscription);
                    
                    // Link the account via your existing API
                    $nc_data = [
                        'server' => $nc_server,
                        'user_id' => $nc_user_id,
                        'email' => $nc_email,
                    'quota' => $quota
                    ];
                    $this->activate_nextcloud_subscription($nc_data, $subscription);
                }
            }
            
            // Clear session data
            if (!session_id()) {
                session_start();
            }
            unset($_SESSION['ncwi_nextcloud_data']);
        }
    }

    // melding als direct in de shop een subscription gekocht wordt zonder dat er een NC account is
public function add_nextcloud_notice_after_purchase($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // Check of er een Nextcloud subscription is gekocht
    $has_nextcloud_subscription = false;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && strpos($product->get_slug(), 'personalstorage-subscription') !== false) {
            $has_nextcloud_subscription = true;
            break;
        }
    }
    
    if ($has_nextcloud_subscription) {
        // Check of gebruiker al een Nextcloud account heeft
        $user_id = $order->get_user_id();
        $account_manager = NCWI_Account_Manager::get_instance();
        $accounts = $account_manager->get_user_accounts($user_id);
        
        if (empty($accounts)) {
            // Voeg notice toe
            wc_add_notice(
                __('To use your Nextcloud subscription, you first need to create or link a Nextcloud account. Go to Nextcloud accounts to do this.', 'nc-woo-integration'),
                'notice'
            );
        }
    }
}
    
    /**
     * Handle subscription activation
     */
    public function handle_subscription_activation($subscription) {
        $subscription_id = $subscription->get_id();
        
        $nc_server = get_post_meta($subscription_id, '_nextcloud_instance_url', true);
        $nc_user_id = get_post_meta($subscription_id, '_nextcloud_user_id', true);
         $nc_email = get_post_meta($subscription_id, '_nextcloud_email', true);
        
        if ($nc_server && $nc_user_id) {
            $subscription_handler = NCWI_Subscription_Handler::get_instance();
        $quota = $subscription_handler->get_subscription_quota($subscription);
        
        $nc_data = [
            'server' => $nc_server,
            'user_id' => $nc_user_id,
            'email' => $nc_email,
            'quota' => $quota
        ];
            
            $this->activate_nextcloud_subscription($nc_data, $subscription);
        }
    }
    
    /**
     * Activate Nextcloud subscription via API
     */
    private function activate_nextcloud_subscription($nc_data, $subscription) {
        // Get the API instance
        $api = NCWI_API::get_instance();
        $account_manager = NCWI_Account_Manager::get_instance();
    $subscription_handler = NCWI_Subscription_Handler::get_instance();

    $user_id = $subscription->get_user_id();
        
        // Get subscription details
        $items = $subscription->get_items();
        $quota = $nc_data['quota'] ?? '10GB';
        
        $existing_account = $account_manager->get_account_by_nc_user($nc_data['user_id'], $nc_data['server']);
    
    if ($existing_account) {
        // Account exists, just link the subscription
        $subscription_handler->link_subscription_to_account($subscription->get_id(), $existing_account['id']);
        
        // Update user via API
        $api->subscribe_user($nc_data['user_id'], $nc_data['server'], $quota);
        $api->update_user_group($nc_data['user_id'], 'paid', $nc_data['server']);
    } else {
        // Create new account 
        $account_data = [
            'nc_server' => $nc_data['server'],
            'nc_user_id' => $nc_data['user_id'],
            'nc_username' => $nc_data['user_id'], 
            'nc_email' => $nc_data['email'] ?? '', 
            'quota' => $quota
        ];
        
        $account_id = $account_manager->create_account($user_id, $account_data);
        
        if (!is_wp_error($account_id)) {
            // Link subscription to the new account
            $subscription_handler->link_subscription_to_account($subscription->get_id(), $account_id);
            
            // Update user via API
            $api->subscribe_user($nc_data['user_id'], $nc_data['server'], $quota);
            $api->update_user_group($nc_data['user_id'], 'paid', $nc_data['server']);
        } else {
            error_log('NCWI: Failed to create account: ' . $account_id->get_error_message());
        }
    }
    
    // Log voor debugging
    error_log('NCWI: Subscription ' . $subscription->get_id() . ' linked to Nextcloud account ' . $nc_data['user_id'] . ' with quota ' . $quota);
}
}