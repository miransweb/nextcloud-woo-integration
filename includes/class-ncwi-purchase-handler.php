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
     
       // NIEUWE hook voor Elementor
    add_action('woocommerce_checkout_order_processed', [$this, 'save_nc_data_and_schedule_link'], 99, 3);
    
    // NIEUWE scheduled action
    add_action('ncwi_process_subscription_link', [$this, 'process_subscription_link'], 10, 1);
    
    // deze als backup
    add_action('woocommerce_subscription_status_active', [$this, 'handle_subscription_activation']);
    
    // deze voor de notice
    add_action('woocommerce_thankyou', [$this, 'add_nextcloud_notice_after_purchase'], 20, 1);
    }
    
    /**
 * Save NC data en schedule linking
 */
public function save_nc_data_and_schedule_link($order_id, $posted_data, $order) {
    if (!session_id()) {
        session_start();
    }
    
    $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
    
    if ($nc_data) {
        // Sla NC data op in order meta
        $order->update_meta_data('_nextcloud_server', $nc_data['server']);
        $order->update_meta_data('_nextcloud_user_id', $nc_data['user_id']);
        $order->update_meta_data('_nextcloud_email', $nc_data['email']);
        $order->save();
        
        // Schedule linking voor over 5 seconden (geeft WC tijd om subscription aan te maken)
        as_schedule_single_action(time() + 5, 'ncwi_process_subscription_link', [$order_id]);
    }
}

/**
 * Process subscription linking (scheduled)
 */
public function process_subscription_link($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // Check of al geprocessed
    if (get_post_meta($order_id, '_ncwi_linking_processed', true)) {
        return;
    }
    
    // Haal subscriptions op
    if (function_exists('wcs_get_subscriptions_for_order')) {
        $subscriptions = wcs_get_subscriptions_for_order($order_id);
        
        if (empty($subscriptions)) {
            // Nog geen subscriptions, probeer later nog een keer
            as_schedule_single_action(time() + 10, 'ncwi_process_subscription_link', [$order_id]);
            return;
        }
        
        // Process subscriptions
        $nc_server = $order->get_meta('_nextcloud_server');
        $nc_user_id = $order->get_meta('_nextcloud_user_id');
        $nc_email = $order->get_meta('_nextcloud_email');
        
        if ($nc_server && $nc_user_id) {
            foreach ($subscriptions as $subscription) {
                // Store NC info in subscription
                update_post_meta($subscription->get_id(), '_nextcloud_instance_url', $nc_server);
                update_post_meta($subscription->get_id(), '_nextcloud_user_id', $nc_user_id);
                update_post_meta($subscription->get_id(), '_nextcloud_email', $nc_email);
                
                // Get quota
                $subscription_handler = NCWI_Subscription_Handler::get_instance();
                $quota = $subscription_handler->get_subscription_quota($subscription);
                
                // Link account
                $nc_data = [
                    'server' => $nc_server,
                    'user_id' => $nc_user_id,
                    'email' => $nc_email,
                    'quota' => $quota
                ];
                
                $this->activate_nextcloud_subscription($nc_data, $subscription);
            }
            
            // Mark as processed
            update_post_meta($order_id, '_ncwi_linking_processed', 'yes');
        }
    }
}

    /**
     * Handle subscription purchase
     */
    public function handle_subscription_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $valid_statuses = ['completed', 'processing'];
    if (!in_array($order->get_status(), $valid_statuses)) {
        error_log('NCWI: Order status is ' . $order->get_status() . ', not in valid statuses');
        return;
    }
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;

        // Check voor duplicate linking
    $already_processed = get_post_meta($order_id, '_ncwi_linking_processed', true);
    if ($already_processed) {
        error_log('NCWI: Order already processed for linking, skipping');
        return;
    }
        
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
                
                $attempts = 0;
            $max_attempts = 3;
            $subscriptions = array();
            
            while ($attempts < $max_attempts && empty($subscriptions)) {
                $subscriptions = wcs_get_subscriptions_for_order($order_id);
                
                if (empty($subscriptions) && $attempts < $max_attempts - 1) {
                    // Wacht 2 seconden en probeer opnieuw
                    sleep(2);
                }
                $attempts++;
            }
            
            if (empty($subscriptions)) {
                // Geen subscriptions gevonden na meerdere pogingen
                // Laat handle_subscription_activation het later oppakken
                return;
            }
                
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
                update_post_meta($order_id, '_ncwi_linking_processed', 'yes');
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

public function debug_order_received_page($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // Check of dit een Nextcloud subscription order is
    $has_nc_sub = false;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && strpos($product->get_slug(), 'personalstorage-subscription') !== false) {
            $has_nc_sub = true;
            break;
        }
    }
    
    if (!$has_nc_sub) return;
    
    // Debug informatie
    ?>
    <div style="background: #f0f0f0; padding: 20px; margin: 20px 0; border: 2px solid #0073aa;">
        <h3>NCWI Debug Info</h3>
        
        <h4>Order Status:</h4>
        <ul>
            <li>Order ID: <?php echo $order_id; ?></li>
            <li>Status: <?php echo $order->get_status(); ?></li>
            <li>Is Paid: <?php echo $order->is_paid() ? 'YES' : 'NO'; ?></li>
            <li>Already Processed: <?php echo get_post_meta($order_id, '_ncwi_linking_processed', true) ? 'YES' : 'NO'; ?></li>
        </ul>
        
        <h4>Nextcloud Data in Order:</h4>
        <ul>
            <li>Server: <?php echo $order->get_meta('_nextcloud_server') ?: 'NOT FOUND'; ?></li>
            <li>User ID: <?php echo $order->get_meta('_nextcloud_user_id') ?: 'NOT FOUND'; ?></li>
            <li>Email: <?php echo $order->get_meta('_nextcloud_email') ?: 'NOT FOUND'; ?></li>
        </ul>
        
        <h4>Subscriptions:</h4>
        <?php
        if (function_exists('wcs_get_subscriptions_for_order')) {
            $subscriptions = wcs_get_subscriptions_for_order($order_id);
            if (empty($subscriptions)) {
                echo '<p style="color: red;">NO SUBSCRIPTIONS FOUND YET!</p>';
            } else {
                foreach ($subscriptions as $subscription) {
                    ?>
                    <ul>
                        <li>Subscription ID: <?php echo $subscription->get_id(); ?></li>
                        <li>Status: <?php echo $subscription->get_status(); ?></li>
                        <li>NC Server in Sub: <?php echo get_post_meta($subscription->get_id(), '_nextcloud_instance_url', true) ?: 'NOT SET'; ?></li>
                        <li>NC User in Sub: <?php echo get_post_meta($subscription->get_id(), '_nextcloud_user_id', true) ?: 'NOT SET'; ?></li>
                    </ul>
                    <?php
                }
            }
        }
        ?>
        
        <h4>Session Data:</h4>
        <?php
        if (!session_id()) {
            session_start();
        }
        if (isset($_SESSION['ncwi_nextcloud_data'])) {
            echo '<pre>' . print_r($_SESSION['ncwi_nextcloud_data'], true) . '</pre>';
        } else {
            echo '<p style="color: orange;">No Nextcloud session data found</p>';
        }
        ?>
        
        <h4>Linked Accounts:</h4>
        <?php
        global $wpdb;
        $user_id = $order->get_user_id();
        $accounts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts WHERE user_id = %d",
            $user_id
        ));
        
        if (empty($accounts)) {
            echo '<p style="color: red;">NO NEXTCLOUD ACCOUNTS LINKED TO THIS USER!</p>';
        } else {
            foreach ($accounts as $account) {
                echo '<ul>';
                echo '<li>Account ID: ' . $account->id . '</li>';
                echo '<li>NC Server: ' . $account->nc_server . '</li>';
                echo '<li>NC User: ' . $account->nc_user_id . '</li>';
                echo '<li>Status: ' . $account->status . '</li>';
                echo '</ul>';
            }
        }
        ?>
    </div>
    <?php
}


}