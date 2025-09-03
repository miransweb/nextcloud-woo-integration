<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_My_Account {
    
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
    // Add endpoint - met hogere prioriteit
    add_action('init', [$this, 'add_endpoints'], 5);
    
    // Add menu item
    add_filter('woocommerce_account_menu_items', [$this, 'add_menu_items']);
    
    // Add content
    add_action('woocommerce_account_nextcloud-accounts_endpoint', [$this, 'nextcloud_accounts_content']);
    
    // Query vars
    add_filter('query_vars', [$this, 'add_query_vars'], 0);
    
    // Title
    add_filter('the_title', [$this, 'endpoint_title'], 10, 2);
    
    // Rewrite rules
    add_filter('woocommerce_get_query_vars', [$this, 'get_query_vars'], 0);
    
    // Force endpoint registration on every init
    add_action('init', function() {
        // Double-check endpoint exists
        global $wp_rewrite;
        $endpoints = $wp_rewrite->endpoints ?? [];
        $found = false;
        
        foreach ($endpoints as $endpoint) {
            if ($endpoint[1] === 'nextcloud-accounts') {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            add_rewrite_endpoint('nextcloud-accounts', EP_ROOT | EP_PAGES);
            
            // Only flush if really needed
            if (get_transient('ncwi_flush_needed') !== 'done') {
                flush_rewrite_rules();
                set_transient('ncwi_flush_needed', 'done', DAY_IN_SECONDS);
            }
        }
    }, 999);
}
    
    /**
     * Register new endpoint
     */
    public function add_endpoints() {
        add_rewrite_endpoint('nextcloud-accounts', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Add menu items
     */
    public function add_menu_items($items) {
        // Insert after orders
        $new_items = [];
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders') {
                $new_items['nextcloud-accounts'] = __('Nextcloud Accounts', 'nc-woo-integration');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'nextcloud-accounts';
        return $vars;
    }
    
    /**
     * Get query vars
     */
    public function get_query_vars($vars) {
        $vars['nextcloud-accounts'] = 'nextcloud-accounts';
        return $vars;
    }
    
    /**
     * Endpoint title
     */
    public function endpoint_title($title, $id) {
        global $wp_query;
        
        if (!is_null($wp_query) && !is_admin() && is_main_query() && in_the_loop() && is_account_page()) {
            if (isset($wp_query->query_vars['nextcloud-accounts'])) {
                $title = __('Nextcloud Accounts', 'nc-woo-integration');
            }
        }
        
        return $title;
    }
    
    /**
     * Render Nextcloud accounts content
     */
    public function nextcloud_accounts_content() {
        $user_id = get_current_user_id();
        $account_manager = NCWI_Account_Manager::get_instance();
        $accounts = $account_manager->get_user_accounts($user_id);
        
        // Check for messages
        if (isset($_GET['verified'])) {
            wc_print_notice(__('Je Nextcloud account is succesvol geverifieerd!', 'nc-woo-integration'), 'success');
        }
        
        // Include template
        $this->include_template('my-account/nextcloud-accounts.php', [
            'accounts' => $accounts,
            'user_id' => $user_id
        ]);
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