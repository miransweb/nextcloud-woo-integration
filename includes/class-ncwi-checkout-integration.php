<?php
/**
 * WooCommerce Checkout Integration for Nextcloud Data
 */

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Checkout_Integration {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
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
        // Pre-fill checkout fields met Nextcloud data
        add_filter('woocommerce_checkout_fields', [$this, 'prefill_checkout_fields']);
        
        // Voeg Nextcloud info toe aan order meta
        add_action('woocommerce_checkout_create_order', [$this, 'add_nextcloud_data_to_order'], 10, 2);
        
        // Na registratie tijdens checkout, link Nextcloud account
        add_action('woocommerce_created_customer', [$this, 'link_nextcloud_after_registration'], 10, 3);
        
        // Toon melding op productpagina als van Nextcloud
        add_action('woocommerce_before_single_product', [$this, 'show_nextcloud_notice']);
    }
    
    /**
     * Pre-fill checkout fields met Nextcloud data
     */
    public function prefill_checkout_fields($fields) {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if ($nc_data) {
            // Pre-fill email field
            if (isset($fields['billing']['billing_email']) && !is_user_logged_in()) {
                $fields['billing']['billing_email']['default'] = $nc_data['email'];
            }
            
            // Pre-fill name if available
            if (!empty($nc_data['display_name']) && !is_user_logged_in()) {
                $name_parts = explode(' ', $nc_data['display_name'], 2);
                if (isset($fields['billing']['billing_first_name'])) {
                    $fields['billing']['billing_first_name']['default'] = $name_parts[0];
                }
                if (isset($fields['billing']['billing_last_name']) && count($name_parts) > 1) {
                    $fields['billing']['billing_last_name']['default'] = $name_parts[1];
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Voeg Nextcloud data toe aan order
     */
    public function add_nextcloud_data_to_order($order, $data) {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if ($nc_data) {  // Check of data bestaat
        $order->update_meta_data('_nextcloud_server', $nc_data['server']);
        $order->update_meta_data('_nextcloud_user_id', $nc_data['user_id']);
        $order->update_meta_data('_nextcloud_email', $nc_data['email']);
        $order->save();
    }
    }

    
    
    /**
     * Link Nextcloud account na registratie tijdens checkout
     */
    public function link_nextcloud_after_registration($customer_id, $new_customer_data, $password_generated) {
    if (!session_id()) {
        session_start();
    }
    
    $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
    
   if ($nc_data && !empty($nc_data['server']) && !empty($nc_data['user_id'])) {
        global $wpdb;
        
        // Check of dit NC account al bestaat in WordPress database
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ncwi_accounts 
             WHERE nc_user_id = %s AND nc_server = %s",
            $nc_data['user_id'],
            $nc_data['server']
        ));
        
        if ($existing) {
            // Account bestaat al, update de user_id naar de nieuwe WordPress user
            $wpdb->update(
                $wpdb->prefix . 'ncwi_accounts',
                [
                    'user_id' => $customer_id,
                    'status' => 'active' // Zorg dat status active is
                ],
                ['id' => $existing->id],
                ['%d', '%s'],
                ['%d']
            );
            $_SESSION['ncwi_new_account_id'] = $existing->id;
            error_log('NCWI: Updated existing NC account ' . $nc_data['user_id'] . ' to WP user ' . $customer_id);
        } else {
            // NC account bestaat nog niet in WordPress, voeg toe als active
            $wpdb->insert(
                $wpdb->prefix . 'ncwi_accounts',
                [
                    'user_id' => $customer_id,
                    'nc_user_id' => $nc_data['user_id'],
                    'nc_email' => $nc_data['email'],
                    'nc_server' => $nc_data['server'],
                    'nc_username' => $nc_data['user_id'],
                    'status' => 'active', // Direct active - komt van bestaand NC account
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            
            $_SESSION['ncwi_new_account_id'] = $wpdb->insert_id;
            error_log('NCWI: Added existing NC account ' . $nc_data['user_id'] . ' as active for WP user ' . $customer_id);
        }
    }
}
    

    /**
     * Toon melding op productpagina als gebruiker van Nextcloud komt
     */
    public function show_nextcloud_notice() {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if ($nc_data && is_product()) {
            global $product;
            
            // Check of dit het Nextcloud subscription product is
            if ($product && strpos($product->get_slug(), 'personalstorage-subscription') !== false) {
                ?>
                <div class="woocommerce-info">
                    <?php 
                    printf(
                        __('You are about to purchase a subscription for your Nextcloud account: %s', 'nc-woo-integration'),
                        '<strong>' . esc_html($nc_data['email']) . '</strong>'
                    );
                    ?>
                </div>
                <?php
            }
        }
    }
}