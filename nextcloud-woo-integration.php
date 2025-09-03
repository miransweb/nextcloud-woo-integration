<?php
/**
 * Plugin Name: Nextcloud WooCommerce Integration
 * Plugin URI: https://github.com/miransweb/nextcloud-woo-integration/
 * Description: Integreert Nextcloud accounts met WooCommerce subscriptions
 * Version: 2.1.3
 * Author: Miran
 * Text Domain: nc-woo-integration
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NCWI_VERSION', '2.1.3');
define('NCWI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NCWI_PLUGIN_URL', plugin_dir_url(__FILE__));

// Main plugin class
class Nextcloud_Woo_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->define_hooks();
    }
    
    private function load_dependencies() {
        // Core classes
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-api.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-account-manager.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-subscription-handler.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-my-account.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-admin.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-security.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-ajax.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-signup.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-updater.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-checkout-integration.php';
        require_once NCWI_PLUGIN_DIR . 'includes/class-ncwi-purchase-handler.php';
    }
    
    private function define_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize components
        add_action('plugins_loaded', [$this, 'init']);
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
    }
    
    public function init() {
        // Load text domain
        load_plugin_textdomain('nc-woo-integration', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize components
        NCWI_API::get_instance();
        NCWI_Account_Manager::get_instance();
        NCWI_Subscription_Handler::get_instance();
        NCWI_My_Account::get_instance();
        NCWI_Admin::get_instance();
        NCWI_Security::get_instance();
        NCWI_Ajax::get_instance();
        NCWI_Signup::get_instance();
        NCWI_Checkout_Integration::get_instance();
        NCWI_Purchase_Handler::get_instance();
    if (is_admin()) {
        new NCWI_Updater(__FILE__); 
    }
}
    
    public function enqueue_scripts() {
        // Existing account page scripts
        if (is_account_page()) {
            wp_enqueue_script(
                'ncwi-frontend',
                NCWI_PLUGIN_URL . 'assets/js/frontend.js',
                ['jquery'],
                NCWI_VERSION,
                true
            );
            
            wp_localize_script('ncwi-frontend', 'ncwi_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ncwi_ajax_nonce'),
                'i18n' => [
                    'confirm_unlink' => __('Weet je zeker dat je dit account wilt ontkoppelen?', 'nc-woo-integration'),
                    'confirm_delete' => __('Weet je zeker dat je dit account wilt verwijderen?', 'nc-woo-integration'),
                    'processing' => __('Bezig met verwerken...', 'nc-woo-integration'),
                    'error' => __('Er is een fout opgetreden. Probeer het opnieuw.', 'nc-woo-integration')
                ]
            ]);
            
            wp_enqueue_style(
                'ncwi-frontend',
                NCWI_PLUGIN_URL . 'assets/css/frontend.css',
                [],
                NCWI_VERSION
            );
        }
        
        // Check if we're on a page with the signup shortcode
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'nextcloud_signup')) {
            wp_enqueue_script(
                'ncwi-signup',
                NCWI_PLUGIN_URL . 'assets/js/signup.js',
                ['jquery'],
                NCWI_VERSION,
                true
            );
            
            wp_localize_script('ncwi-signup', 'ncwi_signup', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'i18n' => [
                    'processing' => __('Creating your account...', 'nc-woo-integration'),
                    'create_account' => __('Create My Nextcloud Account', 'nc-woo-integration'),
                    'error' => __('An error occurred. Please try again.', 'nc-woo-integration'),
                    'password_mismatch' => __('Passwords do not match!', 'nc-woo-integration'),
                    'nextcloud_setup' => __('Setting up your Nextcloud storage space. This may take a moment...', 'nc-woo-integration')
                ]
            ]);
            
            wp_enqueue_style(
                'ncwi-signup',
                NCWI_PLUGIN_URL . 'assets/css/signup.css',
                [],
                NCWI_VERSION
            );
        }
    }
    
    public function admin_enqueue_scripts($hook) {
        if ('settings_page_ncwi-settings' === $hook || 'toplevel_page_ncwi-settings' === $hook) {
            wp_enqueue_script(
                'ncwi-admin',
                NCWI_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                NCWI_VERSION,
                true
            );
            
            wp_enqueue_style(
                'ncwi-admin',
                NCWI_PLUGIN_URL . 'assets/css/admin.css',
                [],
                NCWI_VERSION
            );
        }
    }
    
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();

        // Create signup page
        do_action('ncwi_plugin_activated');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clean up scheduled tasks
        wp_clear_scheduled_hook('ncwi_sync_subscriptions');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for Nextcloud accounts
        $table_name = $wpdb->prefix . 'ncwi_accounts';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            nc_username varchar(255) NOT NULL,
            nc_email varchar(255) NOT NULL,
            nc_server varchar(255) NOT NULL,
            nc_user_id varchar(255) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY nc_username (nc_username),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Table for account-subscription relationships
        $table_name = $wpdb->prefix . 'ncwi_account_subscriptions';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            account_id bigint(20) NOT NULL,
            subscription_id bigint(20) NOT NULL,
            quota varchar(50) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY subscription_id (subscription_id),
            KEY status (status),
            UNIQUE KEY account_subscription (account_id, subscription_id)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    private function set_default_options() {
        add_option('ncwi_deployer_api_url', '');
        add_option('ncwi_deployer_api_key', '');
        add_option('ncwi_nextcloud_api_url', '');
        add_option('ncwi_enable_email_verification', 'yes');
        add_option('ncwi_default_quota', '5GB');
        add_option('ncwi_trial_quota', '1GB');
        add_option('ncwi_sync_interval', 'hourly');
    }
}

// Enable auto-updates for this plugin
add_filter('auto_update_plugin', function($update, $item) {
    if (isset($item->slug) && $item->slug === 'nextcloud-woo-integration') {
        return true;
    }
    return $update;
}, 10, 2);

// Initialize the plugin
function ncwi_init() {
    return Nextcloud_Woo_Integration::get_instance();
}

// Start the plugin
ncwi_init();