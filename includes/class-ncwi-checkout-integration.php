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
        
        if ($nc_data) {
            // BELANGRIJK: Sla de specifieke server URL op!
            $order->update_meta_data('_nextcloud_server', $nc_data['server']);
            $order->update_meta_data('_nextcloud_user_id', $nc_data['user_id']);
            $order->update_meta_data('_nextcloud_email', $nc_data['email']);
            $order->save();
            
            error_log('NCWI: Saved NC data to order - Server: ' . $nc_data['server'] . ', User: ' . $nc_data['user_id']);
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
            
            error_log('NCWI: Processing NC account after registration - Server: ' . $nc_data['server'] . ', Email: ' . $nc_data['email']);
            
            // IMPROVED: Better check for existing accounts
            // First check by nc_user_id and server (most specific)
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ncwi_accounts 
                 WHERE nc_user_id = %s AND nc_server = %s
                 ORDER BY created_at DESC
                 LIMIT 1",
                $nc_data['user_id'],
                $nc_data['server']
            ));
            
            if (!$existing) {
                // Fallback: check by email and server
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ncwi_accounts 
                     WHERE nc_email = %s AND nc_server = %s
                     ORDER BY created_at DESC
                     LIMIT 1",
                    $nc_data['email'],
                    $nc_data['server']
                ));
            }
            
            if ($existing) {
                // Account exists - UPDATE it instead of creating new
                $update_data = [
                    'user_id' => $customer_id,  // Update to current WP user
                    'status' => 'active',       // Ensure status is active
                    'updated_at' => current_time('mysql')
                ];
                
                // Only update email if it has changed
                if ($existing->nc_email !== $nc_data['email']) {
                    $update_data['nc_email'] = $nc_data['email'];
                }
                
                // BELANGRIJK: Keep the original server URL!
                // Do NOT update nc_server - user stays on same server
                
                $wpdb->update(
                    $wpdb->prefix . 'ncwi_accounts',
                    $update_data,
                    ['id' => $existing->id],
                    ['%d', '%s', '%s', '%s'],
                    ['%d']
                );
                
                $_SESSION['ncwi_new_account_id'] = $existing->id;
                error_log('NCWI: Updated existing NC account ' . $nc_data['user_id'] . ' on server ' . $nc_data['server'] . ' for WP user ' . $customer_id);
                
                // CRITICAL: Check if NC user exists in Deployer before linking
                $api = NCWI_API::get_instance();
                $nc_user_exists = $api->check_nc_user_exists($nc_data['email'], $nc_data['server']);
                
                if ($nc_user_exists) {
                    // Link the existing NC user to shop_user in Deployer
                    $link_result = $api->link_existing_account([
                        'nc_username' => $nc_data['email'],
                        'nc_email' => $nc_data['email'],
                        'wp_user_id' => $customer_id,
                        'server_url' => $nc_data['server']  // Use specific server!
                    ]);
                    
                    if (is_wp_error($link_result)) {
                        error_log('NCWI ERROR: Failed to link NC user to shop_user: ' . $link_result->get_error_message());
                    } else {
                        error_log('NCWI: Successfully linked existing NC user to shop_user on server ' . $nc_data['server']);
                    }
                } else {
                    error_log('NCWI WARNING: NC user does not exist in Deployer for server ' . $nc_data['server']);
                }
                
            } else {
                // Only create new record if really doesn't exist
                $wpdb->insert(
                    $wpdb->prefix . 'ncwi_accounts',
                    [
                        'user_id' => $customer_id,
                        'nc_user_id' => $nc_data['user_id'],
                        'nc_email' => $nc_data['email'],
                        'nc_server' => $nc_data['server'],  // Store specific server!
                        'nc_username' => $nc_data['user_id'],
                        'status' => 'active',  // Direct active - comes from existing NC account
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
                );
                
                $_SESSION['ncwi_new_account_id'] = $wpdb->insert_id;
                error_log('NCWI: Added existing NC account ' . $nc_data['user_id'] . ' on server ' . $nc_data['server'] . ' as active for WP user ' . $customer_id);
                
                // Check if NC user exists and link in Deployer
                $api = NCWI_API::get_instance();
                $nc_user_exists = $api->check_nc_user_exists($nc_data['email'], $nc_data['server']);
                
                if ($nc_user_exists) {
                    // Link the NC user to shop_user in Deployer
                    $link_result = $api->link_existing_account([
                        'nc_username' => $nc_data['email'],
                        'nc_email' => $nc_data['email'],
                        'wp_user_id' => $customer_id,
                        'server_url' => $nc_data['server']  // Use specific server!
                    ]);
                    
                    if (is_wp_error($link_result)) {
                        error_log('NCWI ERROR: Failed to link NC user to shop_user: ' . $link_result->get_error_message());
                    } else {
                        error_log('NCWI: Successfully linked NC user to shop_user on server ' . $nc_data['server']);
                    }
                } else {
                    error_log('NCWI WARNING: NC user does not exist in Deployer for server ' . $nc_data['server']);
                }
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
                        __('You are about to purchase a subscription for your Nextcloud account: %s on server %s', 'nc-woo-integration'),
                        '<strong>' . esc_html($nc_data['email']) . '</strong>',
                        '<strong>' . esc_html(parse_url($nc_data['server'], PHP_URL_HOST)) . '</strong>'
                    );
                    ?>
                </div>
                <?php
            }
        }
    }
}