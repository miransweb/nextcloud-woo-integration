<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Nextcloud_Handler {
    
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
        // Handle incoming Nextcloud data
        add_action('init', [$this, 'handle_nextcloud_redirect']);
        
        // Add query vars
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // Shortcode for signup with NC data
        add_shortcode('nextcloud_signup_handler', [$this, 'render_signup_handler']);
        // Hook voor redirect na login
    add_action('wp_login', [$this, 'check_nextcloud_redirect_after_login'], 10, 2);
    }
    
    /**
     * Add custom query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'nc_server';
        $vars[] = 'nc_user_id';
        $vars[] = 'nc_email';
        $vars[] = 'nc_display_name';
        $vars[] = 'action';
        $vars[] = 'signature';
        return $vars;
    }
    
    /**
     * Handle incoming redirect from Nextcloud
     */
    public function handle_nextcloud_redirect() {
        if (!isset($_GET['nc_server']) || !isset($_GET['signature'])) {
            return;
        }
        
        // Verify signature
        if (!$this->verify_signature($_GET)) {
            wp_die(__('Invalid signature', 'nc-woo-integration'), 403);
        }
        
        // Store NC data in session
        if (!session_id()) {
            session_start();
        }
        
        $_SESSION['ncwi_nextcloud_data'] = [
            'server' => sanitize_text_field($_GET['nc_server']),
            'user_id' => sanitize_text_field($_GET['nc_user_id']),
            'email' => sanitize_email($_GET['nc_email']),
            'display_name' => sanitize_text_field($_GET['nc_display_name'] ?? ''),
            'action' => sanitize_text_field($_GET['action'] ?? 'subscribe')
        ];
        
        // Redirect based on action
        if ($_GET['action'] === 'manage' && is_user_logged_in()) {
            // Go to subscription management
            wp_redirect(wc_get_account_endpoint_url('subscriptions'));
            exit;
        } elseif ($_GET['action'] === 'subscribe') {
        // Trial gebruiker - ga direct naar productpagina
        // WooCommerce handelt login/registratie af tijdens checkout
        wp_redirect(home_url('/product/personalstorage-subscription/'));
        exit;
    } else {
        // Als manage maar niet ingelogd, stuur naar login
        wp_redirect(wc_get_page_permalink('myaccount'));
        exit;
    }
    }
    
  public function check_nextcloud_redirect_after_login($user_login, $user) {
    if (!session_id()) {
        session_start();
    }
    
    $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
    
    if ($nc_data && $nc_data['action'] === 'manage') {
        // Na login voor manage actie, redirect naar subscriptions
        wp_redirect(wc_get_account_endpoint_url('subscriptions'));
        exit;
    }
}

    /**
     * Process Nextcloud user
     */
    private function process_nextcloud_user() {
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if (!$nc_data) {
            return;
        }
        
        // Check if user exists
        $user = get_user_by('email', $nc_data['email']);
        
        if ($user) {
            // User exists, log them in
            if (!is_user_logged_in()) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                
                // Link NC account if not already linked
                $this->link_nextcloud_account($user->ID, $nc_data);
            }
            
            // Redirect to account page
            wp_redirect(wc_get_account_endpoint_url('nextcloud-accounts'));
            exit;
        } else {
            // New user - redirect to signup page with pre-filled data
            $signup_url = NCWI_Signup::get_signup_url();
            wp_redirect($signup_url);
            exit;
        }
    }
    
    /**
     * Link Nextcloud account to WordPress user
     */
    private function link_nextcloud_account($user_id, $nc_data) {
        global $wpdb;
        
        // Check if already linked
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ncwi_accounts 
             WHERE user_id = %d AND nc_user_id = %s AND nc_server = %s",
            $user_id,
            $nc_data['user_id'],
            $nc_data['server']
        ));
        
        if (!$existing) {
            // Link the account
            $wpdb->insert(
                $wpdb->prefix . 'ncwi_accounts',
                [
                    'user_id' => $user_id,
                    'nc_username' => $nc_data['user_id'], // NC uses user_id as username
                    'nc_email' => $nc_data['email'],
                    'nc_server' => $nc_data['server'],
                    'nc_user_id' => $nc_data['user_id'],
                    'status' => 'active' // Already verified in NC
                ]
            );
        }
    }
    
    /**
     * Verify signature from Nextcloud
     */
    private function verify_signature($params) {
        $shared_secret = get_option('ncwi_shared_secret', '');
        
        if (empty($shared_secret)) {
            return false;
        }
        
        $signature = $params['signature'];
        unset($params['signature']);
        
        // Sort params
        ksort($params);
        
        // Generate signature
        $data_string = http_build_query($params);
        $expected_signature = hash_hmac('sha256', $data_string, $shared_secret);
        
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Render signup handler
     */
    public function render_signup_handler() {
        if (!session_id()) {
            session_start();
        }
        
        $nc_data = $_SESSION['ncwi_nextcloud_data'] ?? null;
        
        if (!$nc_data) {
            return '<p>' . __('No Nextcloud data found. Please try again.', 'nc-woo-integration') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="ncwi-signup-handler">
            <h2><?php _e('Complete Your Registration', 'nc-woo-integration'); ?></h2>
            
            <div class="ncwi-nc-info">
                <p><?php _e('You are signing up with your Nextcloud account:', 'nc-woo-integration'); ?></p>
                <ul>
                    <li><strong><?php _e('Server:', 'nc-woo-integration'); ?></strong> <?php echo esc_html($nc_data['server']); ?></li>
                    <li><strong><?php _e('Email:', 'nc-woo-integration'); ?></strong> <?php echo esc_html($nc_data['email']); ?></li>
                    <li><strong><?php _e('User ID:', 'nc-woo-integration'); ?></strong> <?php echo esc_html($nc_data['user_id']); ?></li>
                </ul>
            </div>
            
            <form id="ncwi-complete-signup" method="post">
                <?php wp_nonce_field('ncwi_complete_signup', 'signup_nonce'); ?>
                <input type="hidden" name="action" value="ncwi_complete_signup">
                
                <p>
                    <label for="username"><?php _e('Choose a username', 'nc-woo-integration'); ?></label>
                    <input type="text" name="username" id="username" value="<?php echo esc_attr($nc_data['user_id']); ?>" required>
                </p>
                
                <p>
                    <label for="password"><?php _e('Choose a password', 'nc-woo-integration'); ?></label>
                    <input type="password" name="password" id="password" required>
                </p>
                
                <p>
                    <label for="password2"><?php _e('Confirm password', 'nc-woo-integration'); ?></label>
                    <input type="password" name="password2" id="password2" required>
                </p>
                
                <p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Create Account & Link Nextcloud', 'nc-woo-integration'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}