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
        
        // Hook into subscription activation
        add_action('woocommerce_subscription_status_active', [$this, 'handle_subscription_activation']);
        
        // Add custom My Account endpoint
        add_filter('woocommerce_account_menu_items', [$this, 'add_subscription_endpoint']);
        add_action('init', [$this, 'add_subscription_rewrite_endpoint']);
        add_action('woocommerce_account_view-subscription_endpoint', [$this, 'subscription_endpoint_content']);
    }
    
    /**
     * Handle subscription purchase
     */
    public function handle_subscription_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
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
        
        // Get Nextcloud data from order meta (komt van checkout)
        $nc_server = $order->get_meta('_nextcloud_server');
        $nc_user_id = $order->get_meta('_nextcloud_user_id');
        $nc_email = $order->get_meta('_nextcloud_email');
        
        if ($nc_server && $nc_user_id) {
            // Get the subscription from the order
            if (function_exists('wcs_get_subscriptions_for_order')) {
                $subscriptions = wcs_get_subscriptions_for_order($order_id);
                
                foreach ($subscriptions as $subscription) {
                    $subscription_id = $subscription->get_id();
                    
                    // Store Nextcloud info with subscription
                    update_post_meta($subscription_id, '_nextcloud_instance_url', $nc_server);
                    update_post_meta($subscription_id, '_nextcloud_user_id', $nc_user_id);
                    
                    // Link the account via your existing API
                    $nc_data = [
                        'server' => $nc_server,
                        'user_id' => $nc_user_id
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
    
    /**
     * Handle subscription activation
     */
    public function handle_subscription_activation($subscription) {
        $subscription_id = $subscription->get_id();
        
        $nc_server = get_post_meta($subscription_id, '_nextcloud_instance_url', true);
        $nc_user_id = get_post_meta($subscription_id, '_nextcloud_user_id', true);
        
        if ($nc_server && $nc_user_id) {
            $nc_data = [
                'server' => $nc_server,
                'user_id' => $nc_user_id
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
        
        // Get subscription details
        $items = $subscription->get_items();
        $quota = '10GB'; // Default
        
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product) {
                // Get quota from product meta or attributes
                $product_quota = get_post_meta($product->get_id(), '_nextcloud_quota', true);
                if ($product_quota) {
                    $quota = $product_quota;
                }
            }
        }
        
        // Subscribe user via API
        $api->subscribe_user($nc_data['user_id'], $nc_data['server'], $quota);
    }
}