<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Signup {
    
    private static $instance = null;
    
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
        // Shortcode
        add_shortcode('nextcloud_signup', [$this, 'render_signup_form']);
        
        // AJAX handlers
        add_action('wp_ajax_ncwi_direct_signup', [$this, 'ajax_direct_signup']);
        add_action('wp_ajax_nopriv_ncwi_direct_signup', [$this, 'ajax_direct_signup']);
        
        // Create signup page on init if it doesn't exist
        add_action('init', [$this, 'maybe_create_signup_page']);
        
        // Add signup link to login form
        add_action('woocommerce_login_form_end', [$this, 'add_signup_link']);
        
        // Redirect old endpoint to new page
        add_action('template_redirect', [$this, 'redirect_old_endpoint']);
        
        // Add admin notice if page is missing
        add_action('admin_notices', [$this, 'admin_notice_missing_page']);
    }
    
    /**
     * Maybe create signup page
     */
    public function maybe_create_signup_page() {
        // Only run in admin and if user can manage options
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        
        // Check if we need to create the page
        $page_id = get_option('ncwi_signup_page_id');
        
        if (!$page_id || !get_post($page_id)) {
            $this->create_signup_page();
        }
    }
    
    /**
     * Create signup page
     */
    public function create_signup_page() {
        // First check if a page with our slug already exists
        $existing_page = get_page_by_path('nextcloud-signup');
        
        if ($existing_page) {
            update_option('ncwi_signup_page_id', $existing_page->ID);
            return $existing_page->ID;
        }
        
        // Create the page
        $page_data = [
            'post_title'    => __('Nextcloud Signup', 'nc-woo-integration'),
            'post_name'     => 'nextcloud-signup',
            'post_content'  => '[nextcloud_signup]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => 1,
            'comment_status' => 'closed',
            'ping_status'   => 'closed'
        ];
        
        $page_id = wp_insert_post($page_data);
        
        if (!is_wp_error($page_id)) {
            update_option('ncwi_signup_page_id', $page_id);
            return $page_id;
        }
        
        return false;
    }
    
    /**
     * Admin notice for missing signup page
     */
    public function admin_notice_missing_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $page_id = get_option('ncwi_signup_page_id');
        
        if (!$page_id || !get_post($page_id)) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php _e('Nextcloud Integration:', 'nc-woo-integration'); ?></strong>
                    <?php _e('The signup page is missing. Please go to Pages and create a new page with the shortcode [nextcloud_signup] or', 'nc-woo-integration'); ?>
                    <a href="<?php echo admin_url('admin-post.php?action=ncwi_create_signup_page'); ?>"><?php _e('click here to create it automatically', 'nc-woo-integration'); ?></a>.
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Add signup link to login form
     */
    /*public function add_signup_link() {
        $signup_page_id = get_option('ncwi_signup_page_id');
        if ($signup_page_id) {
            $signup_url = get_permalink($signup_page_id);
            ?>
            <p class="ncwi-signup-link">
                <?php _e("Don't have an account?", 'nc-woo-integration'); ?>
                <a href="<?php echo esc_url($signup_url); ?>">
                    <?php _e('Create your Nextcloud account', 'nc-woo-integration'); ?>
                </a>
            </p>
            <?php
        }
    }*/
    
    /**
     * Redirect old endpoint to new page
     */
    public function redirect_old_endpoint() {
        global $wp_query;
        
        if (is_account_page() && isset($wp_query->query_vars['nextcloud-signup'])) {
            $signup_page_id = get_option('ncwi_signup_page_id');
            if ($signup_page_id) {
                wp_redirect(get_permalink($signup_page_id));
                exit;
            }
        }
        
       
    }
    
    /**
     * Get signup page URL
     */
    public static function get_signup_url() {
        $signup_page_id = get_option('ncwi_signup_page_id');
        if ($signup_page_id) {
            return get_permalink($signup_page_id);
        }
        return wc_get_page_permalink('myaccount');
    }
    
    /**
     * Render signup form shortcode
     */
    public function render_signup_form($atts = []) {
        // If already logged in, return a message instead of redirecting
        if (is_user_logged_in()) {
            return '<div class="ncwi-signup-wrapper"><p>' . 
                   sprintf(__('You are already logged in. <a href="%s">Go to your account</a>.', 'nc-woo-integration'), 
                   wc_get_account_endpoint_url('dashboard')) . 
                   '</p></div>';
        }
        
        // Start output buffering
        ob_start();
        
        // Check if template exists
        $template_path = NCWI_PLUGIN_DIR . 'templates/signup/form.php';
        
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            // Fallback if template not found
            ?>
            <div class="ncwi-signup-wrapper">
                <p style="color: red;">
                    <?php _e('Template file not found at: ', 'nc-woo-integration'); ?>
                    <?php echo esc_html($template_path); ?>
                </p>
            </div>
            <?php
        }
        
        return ob_get_clean();
    }
    
    /**
     * AJAX handler for direct signup
     */
    public function ajax_direct_signup() {
        check_ajax_referer('ncwi_direct_signup', 'signup_nonce');
        
        $email = sanitize_email($_POST['email'] ?? '');
        $username = sanitize_user($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validate input
        if (empty($email) || empty($username) || empty($password)) {
            wp_send_json_error(['message' => __('Please fill in all required fields.', 'nc-woo-integration')]);
        }
        
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'nc-woo-integration')]);
        }
        
        // Check if shop account exists
        $user_id = email_exists($email);
        
        if (!$user_id) {
            // Create WordPress/Shop account
            $user_id = wp_create_user($username, $password, $email);
            
            if (is_wp_error($user_id)) {
                // Try with email as username if username is taken
                if ($user_id->get_error_code() === 'existing_user_login') {
                    $username = $email;
                    $user_id = wp_create_user($username, $password, $email);
                }
                
                if (is_wp_error($user_id)) {
                    wp_send_json_error(['message' => $user_id->get_error_message()]);
                }
            }
            
            // Set user meta
            update_user_meta($user_id, 'first_name', '');
            update_user_meta($user_id, 'last_name', '');
            
            // Log the user in
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            
            // Trigger WooCommerce customer creation
            do_action('woocommerce_created_customer', $user_id);
            
            $new_account = true;
        } else {
            // User exists but not logged in - log them in
            $user = get_user_by('id', $user_id);
            if (!wp_check_password($password, $user->user_pass, $user_id)) {
                wp_send_json_error(['message' => __('An account with this email already exists. Please use the login form or use a different email.', 'nc-woo-integration')]);
            }
            
            // Log them in
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            $new_account = false;
        }
        
        // Now create/link Nextcloud account
        try {
            $account_manager = NCWI_Account_Manager::get_instance();
            
            // Check if user already has NC accounts
            $existing_accounts = $account_manager->get_user_accounts($user_id);
            
            if (empty($existing_accounts)) {
                // Create new Nextcloud account
                $nc_username = $username;
                $counter = 1;
                
                // Ensure unique NC username
                while ($this->nc_username_exists($nc_username)) {
                    $nc_username = $username . $counter;
                    $counter++;
                }
                
                $account_id = $account_manager->create_account($user_id, [
                    'username' => $nc_username,
                    'email' => $email,
                    'quota' => get_option('ncwi_trial_quota', '1GB')
                ]);
                
                if (is_wp_error($account_id)) {
                    // Account creation failed, but user is logged in
                    wp_send_json_success([
                        'message' => __('Shop account created! However, Nextcloud account creation failed. You can try again from your account page.', 'nc-woo-integration'),
                        'redirect' => wc_get_account_endpoint_url('dashboard')
                    ]);
                }
                
                // Check if it's pending verification
                if (is_array($account_id) && isset($account_id['status']) && $account_id['status'] === 'pending_verification') {
                    $message = __('Success! Your account has been created. Please check your email to verify your Nextcloud account.', 'nc-woo-integration');
                } else {
                    $message = $new_account 
                        ? __('Success! Your account has been created and your Nextcloud storage is being set up. Check your email for login details.', 'nc-woo-integration')
                        : __('Logged in successfully! Your Nextcloud storage is being set up.', 'nc-woo-integration');
                }
            } else {
                $message = __('Welcome back! You already have a Nextcloud account linked.', 'nc-woo-integration');
            }
            
            wp_send_json_success([
                'message' => $message,
                'redirect' => wc_get_account_endpoint_url('dashboard')
            ]);
            
        } catch (Exception $e) {
            // User is logged in but NC creation failed
            wp_send_json_success([
                'message' => __('Account created! Redirecting to complete Nextcloud setup...', 'nc-woo-integration'),
                'redirect' => wc_get_account_endpoint_url('dashboard')
            ]);
        }
    }
    
    /**
     * Helper: Check if NC username exists
     */
    private function nc_username_exists($username) {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_accounts WHERE nc_username = %s",
            $username
        ));
        return $count > 0;
    }
    
    /**
     * Include template with fallback
     */
    private function include_template($template, $args = []) {
        // Extract args to variables
        extract($args);
        
        // Check theme override
        $theme_file = get_stylesheet_directory() . '/ncwi/' . $template;
        if (file_exists($theme_file)) {
            include $theme_file;
            return;
        }
        
        // Use plugin template
        $plugin_file = NCWI_PLUGIN_DIR . 'templates/' . $template;
        if (file_exists($plugin_file)) {
            include $plugin_file;
            return;
        }
        
        // Fallback content
        echo '<p>' . __('Template niet gevonden', 'nc-woo-integration') . '</p>';
    }
}