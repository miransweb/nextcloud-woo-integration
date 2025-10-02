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
        
        // NEW: Enable registration for NC users
        add_filter('woocommerce_checkout_registration_enabled', [$this, 'enable_checkout_registration']);
        add_filter('woocommerce_checkout_registration_required', [$this, 'maybe_require_registration']);
        
        // NEW: Setup checkout for NC users
        add_action('woocommerce_before_checkout_form', [$this, 'setup_checkout_for_nc_users']);
        
        // NEW: Handle registration errors
        add_filter('woocommerce_registration_errors', [$this, 'handle_registration_errors'], 10, 3);
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
            
            // NEW: Set account fields defaults
            if (!is_user_logged_in()) {
                if (isset($fields['account'])) {
                    // Pre-fill username with email
                    if (isset($fields['account']['account_username'])) {
                        $fields['account']['account_username']['default'] = $nc_data['email'];
                    }
                    
                    // Generate a secure password
                    if (isset($fields['account']['account_password'])) {
                        $fields['account']['account_password']['default'] = wp_generate_password(12, true, false);
                    }
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Enable checkout registration for NC users
     */
    public function enable_checkout_registration($enabled) {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        // If user comes from NC and is not logged in, enable registration
        if ($nc_data && !is_user_logged_in()) {
            return true;
        }
        
        return $enabled;
    }
    
    /**
     * Maybe require registration for NC users
     */
    public function maybe_require_registration($required) {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        // If user comes from NC and is not logged in, don't require registration
        // This allows guest checkout if needed
        if ($nc_data && !is_user_logged_in()) {
            return false;
        }
        
        return $required;
    }
    
    /**
     * Setup checkout form for NC users
     */
    public function setup_checkout_for_nc_users() {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if ($nc_data && !is_user_logged_in()) {
            // Check if user already exists with this email
            $existing_user = get_user_by('email', $nc_data['email']);
            
            if ($existing_user) {
                // User exists - show login prompt
                ?>
                <div class="woocommerce-info">
                    <?php 
                    printf(
                        __('An account already exists with email %s. Please <a href="#" class="showlogin">click here to login</a>.', 'nc-woo-integration'),
                        '<strong>' . esc_html($nc_data['email']) . '</strong>'
                    );
                    ?>
                </div>
                <script>
                jQuery(document).ready(function($) {
                    // Auto-fill login form
                    $('#username').val('<?php echo esc_js($nc_data['email']); ?>');
                });
                </script>
                <?php
            } else {
                // New user - setup auto-registration
                ?>
                <div class="woocommerce-info ncwi-auto-account-info">
                    <?php _e('An account will be automatically created for your Nextcloud access.', 'nc-woo-integration'); ?>
                </div>
                <script>
                jQuery(document).ready(function($) {
                    // Enable create account checkbox
                    $('#createaccount').prop('checked', true).trigger('change');
                    
                    // Generate secure password if not already set
                    if ($('#account_password').val() === '') {
                        $('#account_password').val('<?php echo esc_js(wp_generate_password(12, true, false)); ?>');
                    }
                    
                    // Use email as username if username field is empty
                    if ($('#account_username').val() === '') {
                        $('#account_username').val('<?php echo esc_js($nc_data['email']); ?>');
                    }
                });
                </script>
                <?php
            }
        }
    }
    
    /**
     * Handle registration errors for NC users
     */
    public function handle_registration_errors($errors, $username, $email) {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if ($nc_data && $email === $nc_data['email']) {
            // For NC users, handle username exists error
            if ($errors->get_error_code() === 'registration-error-username-exists') {
                $errors->remove('registration-error-username-exists');
                
                // Generate alternative username
                $base = explode('@', $email)[0];
                $counter = 1;
                $new_username = $base;
                
                while (username_exists($new_username)) {
                    $new_username = $base . $counter;
                    $counter++;
                }
                
                // Update the username in POST
                $_POST['account_username'] = $new_username;
                
                // Add info message
                wc_add_notice(
                    sprintf(__('Username %s was already taken, using %s instead.', 'nc-woo-integration'), $username, $new_username),
                    'notice'
                );
            }
            
            // For NC users, also handle email exists error differently
            if ($errors->get_error_code() === 'registration-error-email-exists') {
                $errors->remove('registration-error-email-exists');
                
                // Add more helpful error
                $errors->add('registration-error-email-exists-nc',
                    sprintf(__('An account with email %s already exists. Please login first or use the login form above.', 'nc-woo-integration'), $email)
                );
            }
        }
        
        return $errors;
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
                
                // For trial users upgrading, we KNOW they exist in NC, so just try to link
                if ($nc_data['action'] === 'subscribe') {
                    $api = NCWI_API::get_instance();
                    
                    // Skip the check, just link
                    $link_result = $api->link_existing_account([
                        'nc_username' => $nc_data['email'],
                        'nc_email' => $nc_data['email'],
                        'wp_user_id' => $customer_id,
                        'server_url' => $nc_data['server']
                    ]);
                    
                    if (is_wp_error($link_result)) {
                        error_log('NCWI: Failed to link NC user to shop_user: ' . $link_result->get_error_message());
                        // Don't fail the checkout
                    } else {
                        error_log('NCWI: Successfully linked existing NC user to shop_user on server ' . $nc_data['server']);
                    }
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
                
                // For trial users upgrading, just link without checking
                if ($nc_data['action'] === 'subscribe') {
                    $api = NCWI_API::get_instance();
                    
                    $link_result = $api->link_existing_account([
                        'nc_username' => $nc_data['email'],
                        'nc_email' => $nc_data['email'],
                        'wp_user_id' => $customer_id,
                        'server_url' => $nc_data['server']
                    ]);
                    
                    if (is_wp_error($link_result)) {
                        error_log('NCWI: Failed to link NC user to shop_user: ' . $link_result->get_error_message());
                    } else {
                        error_log('NCWI: Successfully linked NC user to shop_user');
                    }
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