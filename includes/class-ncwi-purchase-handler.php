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
         // Get the API instance
        $api = NCWI_API::get_instance();
        $account_manager = NCWI_Account_Manager::get_instance();
    $subscription_handler = NCWI_Subscription_Handler::get_instance();

    $user_id = $subscription->get_user_id();
        
        // Get subscription details
        $items = $subscription->get_items();
        $quota = $nc_data['quota'] ?? '10GB';
        
        $user_accounts = $account_manager->get_user_accounts($user_id);
    
    // Check of er al een account is met deze NC user/server
    $existing_account = null;
    foreach ($user_accounts as $account) {
        if ($account['nc_user_id'] === $nc_data['user_id'] && 
            $account['nc_server'] === $nc_data['server']) {
            $existing_account = $account;
            break;
        }
    }
 
   if ($existing_account) {
        // Link subscription
        $link_result = $subscription_handler->link_subscription_to_account($subscription->get_id(), $existing_account['id']);
        $api->subscribe_user($nc_data['user_id'], $nc_data['server'], $quota);
        $api->update_user_group($nc_data['user_id'], 'paid', $nc_data['server']);
    } else {
         // Maak nieuw account
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
}


}