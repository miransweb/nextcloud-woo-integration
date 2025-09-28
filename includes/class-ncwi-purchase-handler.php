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
   
        add_action('woocommerce_thankyou', [$this, 'add_nextcloud_notice_after_purchase'], 20, 1);
       
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
     * Activate Nextcloud subscription via API
     */
    public function activate_nextcloud_subscription($nc_data, $subscription) {
    $api = NCWI_API::get_instance();
    $subscription_handler = NCWI_Subscription_Handler::get_instance();
    
    $user_id = $subscription->get_user_id();
    
    global $wpdb;
    
    // Check of er al een NC account bestaat voor deze gebruiker

    $existing_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ncwi_accounts 
         WHERE user_id = %d 
         AND (nc_email = %s OR nc_user_id = %s)
         ORDER BY id DESC LIMIT 1",
        $user_id,
        $nc_data['email'],
        $nc_data['user_id']
    ), ARRAY_A);
    
    if ($existing_account) {
        error_log('NCWI: Found existing account ID: ' . $existing_account['id']);
        
        // Update het account 
        $wpdb->update(
            $wpdb->prefix . 'ncwi_accounts',
            [
                'nc_server' => $nc_data['server'],
                'nc_user_id' => $nc_data['user_id'],
                'status' => 'active'
            ],
            ['id' => $existing_account['id']],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        // Link de subscription
        $link_result = $subscription_handler->link_subscription_to_account($subscription->get_id(), $existing_account['id']);
        
        if ($link_result) {
            error_log('NCWI: Successfully linked subscription to existing account');
            
            // Update user group en quota via API
            $quota = $subscription_handler->get_subscription_quota($subscription);
            $api->update_user_group($nc_data['user_id'], 'paid', $nc_data['server']);
            $api->update_user_quota($nc_data['user_id'], $quota, $nc_data['server']);
        } else {
            error_log('NCWI: Failed to link subscription to existing account');
        }
    } else {
        error_log('NCWI ERROR: No existing account found - this should not happen for NC users!');
        error_log('NCWI: User ID: ' . $user_id . ', Email: ' . $nc_data['email'] . ', NC User: ' . $nc_data['user_id']);
    }
}


}