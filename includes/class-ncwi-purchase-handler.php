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
   
    add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
        add_action('init', [$this, 'ensure_webhook_exists']);
        add_action('woocommerce_thankyou', [$this, 'add_nextcloud_notice_after_purchase'], 20, 1);
        add_action('wp_footer', [$this, 'inject_debug_on_order_received']);
    }
    

public function register_webhook_endpoint() {
    register_rest_route('ncwi/v1', '/subscription-active', [
        'methods' => 'POST',
        'callback' => [$this, 'handle_subscription_webhook'],
        'permission_callback' => '__return_true'
    ]);
}

public function handle_subscription_webhook($request) {
    $subscription_id = $request->get_param('subscription_id');
    if (!$subscription_id) return;
    
    $subscription = wcs_get_subscription($subscription_id);
    if (!$subscription) return;
    
    // Process de subscription
    $parent_order = $subscription->get_parent();
    if (!$parent_order) return;
    
    $nc_server = $parent_order->get_meta('_nextcloud_server');
    $nc_user_id = $parent_order->get_meta('_nextcloud_user_id');
    $nc_email = $parent_order->get_meta('_nextcloud_email');
    
    if ($nc_server && $nc_user_id) {
        // Store en link
        update_post_meta($subscription_id, '_nextcloud_instance_url', $nc_server);
        update_post_meta($subscription_id, '_nextcloud_user_id', $nc_user_id);
        update_post_meta($subscription_id, '_nextcloud_email', $nc_email);
        
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
    
    return ['success' => true];
}

public function ensure_webhook_exists() {
    if (get_option('ncwi_webhook_created')) return;
    
    // Maak webhook aan via WooCommerce
    $webhook = new WC_Webhook();
    $webhook->set_name('NCWI Subscription Active');
    $webhook->set_topic('subscription.updated');
    $webhook->set_delivery_url(rest_url('ncwi/v1/subscription-active'));
    $webhook->set_status('active');
    $webhook->save();
    
    update_option('ncwi_webhook_created', 'yes');
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
    private function activate_nextcloud_subscription($nc_data, $subscription) {

        //debug
        update_post_meta($subscription->get_id(), '_ncwi_activate_called', 'YES - ' . current_time('Y-m-d H:i:s'));
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
        update_post_meta($subscription->get_id(), '_ncwi_account_found', 'YES - ID: ' . $existing_account['id']);
        
        // Link subscription
        $link_result = $subscription_handler->link_subscription_to_account($subscription->get_id(), $existing_account['id']);
        update_post_meta($subscription->get_id(), '_ncwi_link_result', $link_result ? 'SUCCESS' : 'FAILED');
    } else {
        update_post_meta($subscription->get_id(), '_ncwi_account_found', 'NO - Creating new');
        
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
            update_post_meta($subscription->get_id(), '_ncwi_account_created', 'YES - ID: ' . $account_id);
            $link_result = $subscription_handler->link_subscription_to_account($subscription->get_id(), $account_id);
            update_post_meta($subscription->get_id(), '_ncwi_link_result', $link_result ? 'SUCCESS' : 'FAILED');
        } else {
            update_post_meta($subscription->get_id(), '_ncwi_account_created', 'ERROR: ' . $account_id->get_error_message());
        }
    }
}



/**
 * Injecteer debug info via JavaScript
 */
public function inject_debug_on_order_received() {
    if (!is_wc_endpoint_url('order-received')) {
        return;
    }
    
    // Haal order ID uit de URL
    global $wp;
    $order_id = absint($wp->query_vars['order-received']);
    if (!$order_id) return;
    
    $order = wc_get_order($order_id);
    if (!$order) return;

    $hook_triggered = get_post_meta($order_id, '_ncwi_hook_triggered', true);
$schedule_attempted = get_post_meta($order_id, '_ncwi_schedule_attempted', true);
$process_called = get_post_meta($order_id, '_ncwi_process_link_called', true);
$process_error = get_post_meta($order_id, '_ncwi_process_link_error', true);
$activate_called = get_post_meta($sub_id, '_ncwi_activation_triggered', true);
$account_found = get_post_meta($sub_id, '_ncwi_account_found', true);
$account_created = get_post_meta($sub_id, '_ncwi_account_created', true);
$link_result = get_post_meta($sub_id, '_ncwi_link_result', true);

    
    // debug data
    $nc_server = $order->get_meta('_nextcloud_server');
    $nc_user = $order->get_meta('_nextcloud_user_id');
    $nc_email = $order->get_meta('_nextcloud_email');
    $processed = get_post_meta($order_id, '_ncwi_linking_processed', true);
    
    $subscriptions = function_exists('wcs_get_subscriptions_for_order') ? wcs_get_subscriptions_for_order($order_id) : [];
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var debugHtml = `
        <div style="background: #fff3cd; border: 3px solid #ff6b6b; padding: 20px; margin: 20px 0;">
            <h2 style="color: #ff6b6b;">NCWI Debug</h2>
            <p><strong>NC Server:</strong> <?php echo $nc_server ?: 'NIET GEVONDEN'; ?></p>
            <p><strong>NC User:</strong> <?php echo $nc_user ?: 'NIET GEVONDEN'; ?></p>
            <p><strong>NC Email:</strong> <?php echo $nc_email ?: 'NIET GEVONDEN'; ?></p>
            <p><strong>Processed:</strong> <?php echo $processed ? 'JA' : 'NEE'; ?></p>
            <p><strong>Subscriptions:</strong> <?php echo count($subscriptions); ?> gevonden</p>
            
            <p><strong>Activate Called:</strong> <?php echo $activate_called ?: 'NO'; ?></p>
            <p><strong>Account Found:</strong> <?php echo $account_found ?: 'NO'; ?></p>
            <p><strong>Account Created:</strong> <?php echo $account_created ?: '-'; ?></p>
            <p><strong>Link Result:</strong> <?php echo $link_result ?: '-'; ?></p>
          
            <?php 
            foreach ($subscriptions as $sub) {
                $sub_id = $sub->get_id();
                $sub_nc = get_post_meta($sub_id, '_nextcloud_instance_url', true);
                echo '<p>Sub #' . $sub_id . ' - NC: ' . ($sub_nc ?: 'NIET GELINKT') . '</p>';
            }
            ?>
        </div>
        `;
        
        // Voeg debug info toe na de order details
        $('.woocommerce-order').prepend(debugHtml);
    });
    </script>
    <?php
}



}