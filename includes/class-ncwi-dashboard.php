<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Dashboard {
    
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
        // Override at multiple points with highest priority
        add_action('init', [$this, 'force_dashboard_override'], 1);
        add_action('wp', [$this, 'check_and_override_dashboard'], 1);
        
        // Override the_content with multiple methods
        add_filter('the_content', [$this, 'override_elementor_content'], 1);
        add_filter('woocommerce_account_content', [$this, 'force_custom_dashboard'], 1);
        
        // Override shortcode output
        add_filter('do_shortcode_tag', [$this, 'override_myaccount_shortcode'], 1, 4);
        
        // JavaScript override as last resort
        add_action('wp_footer', [$this, 'add_dashboard_override_script']);
        
        // Override WooCommerce template
        add_filter('wc_get_template', [$this, 'override_dashboard_template'], 1, 5);
        
        // Hook into Elementor rendering
        add_action('elementor/widget/before_render', [$this, 'before_elementor_render']);
        add_filter('elementor/widget/render_content', [$this, 'filter_elementor_content'], 1, 2);
    }
    
    /**
     * Force dashboard override early
     */
    public function force_dashboard_override() {
        // Remove all dashboard actions
        remove_all_actions('woocommerce_account_dashboard');
        remove_all_actions('woocommerce_before_account_dashboard'); 
        remove_all_actions('woocommerce_after_account_dashboard');
        
        // Add our content
        add_action('woocommerce_account_dashboard', [$this, 'render_custom_dashboard'], 1);
    }
    
    /**
     * Check if we're on account page and override
     */
    public function check_and_override_dashboard() {
        if (is_account_page() && !is_wc_endpoint_url()) {
            // Remove any potential Elementor content
            remove_all_filters('the_content');
            add_filter('the_content', [$this, 'override_elementor_content'], 1);
        }
    }
    
    /**
     * Override Elementor content completely
     */
    public function override_elementor_content($content) {
        if (!is_account_page() || is_wc_endpoint_url()) {
            return $content;
        }
        
        // Check if content contains Elementor or old dashboard markers
        $elementor_markers = [
            'elementor-',
            'Product Management',
            'How this works',
            'click this link',
            'data-elementor-type',
            'elementor-element'
        ];
        
        $contains_elementor = false;
        foreach ($elementor_markers as $marker) {
            if (stripos($content, $marker) !== false) {
                $contains_elementor = true;
                break;
            }
        }
        
        if ($contains_elementor || has_shortcode($content, 'woocommerce_my_account')) {
            // Replace entire content
            ob_start();
            ?>
            <div class="woocommerce">
                <nav class="woocommerce-MyAccount-navigation">
                    <?php do_action('woocommerce_account_navigation'); ?>
                </nav>
                
                <div class="woocommerce-MyAccount-content">
                    <?php $this->render_custom_dashboard(); ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        return $content;
    }
    
    /**
     * Override my account shortcode output
     */
    public function override_myaccount_shortcode($output, $tag, $attr, $m) {
        if ($tag === 'woocommerce_my_account' && is_account_page() && !is_wc_endpoint_url()) {
            ob_start();
            ?>
            <div class="woocommerce">
                <nav class="woocommerce-MyAccount-navigation">
                    <?php do_action('woocommerce_account_navigation'); ?>
                </nav>
                
                <div class="woocommerce-MyAccount-content">
                    <?php $this->render_custom_dashboard(); ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        return $output;
    }
    
    /**
     * Force custom dashboard content
     */
    public function force_custom_dashboard($content) {
        if (is_account_page() && !is_wc_endpoint_url()) {
            ob_start();
            $this->render_custom_dashboard();
            return ob_get_clean();
        }
        return $content;
    }
    
    /**
     * Override WooCommerce dashboard template
     */
    public function override_dashboard_template($template, $template_name, $args, $template_path, $default_path) {
        if ($template_name === 'myaccount/dashboard.php' || $template_name === 'my-account/dashboard.php') {
            return NCWI_PLUGIN_DIR . 'templates/my-account/dashboard.php';
        }
        return $template;
    }
    
    /**
     * Hook into Elementor before render
     */
    public function before_elementor_render($widget) {
        if (is_account_page() && !is_wc_endpoint_url()) {
            // Check if it's a text or HTML widget that might contain our content
            $widget_type = $widget->get_name();
            if (in_array($widget_type, ['text-editor', 'html', 'shortcode'])) {
                // Force our content
                add_filter('elementor/widget/render_content', [$this, 'filter_elementor_content'], 1, 2);
            }
        }
    }
    
    /**
     * Filter Elementor widget content
     */
    public function filter_elementor_content($content, $widget) {
        if (!is_account_page() || is_wc_endpoint_url()) {
            return $content;
        }
        
        // Check if content contains old dashboard
        if (stripos($content, 'Product Management') !== false || 
            stripos($content, 'How this works') !== false) {
            
            ob_start();
            $this->render_custom_dashboard();
            return ob_get_clean();
        }
        
        return $content;
    }
    
    /**
     * Render custom dashboard
     */
    public function render_custom_dashboard() {
        $current_user = wp_get_current_user();
        ?>
        <div class="ncwi-custom-dashboard-wrapper">
            <p>
                <?php 
                printf(
                    __('Hello %s,', 'woocommerce'), 
                    '<strong>' . esc_html($current_user->display_name) . '</strong>'
                ); 
                ?>
            </p>
            
            <p><?php _e('From your account dashboard you can:', 'woocommerce'); ?></p>
            
            <ul>
                <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('nextcloud-accounts')); ?>">
                    <?php _e('Manage your Nextcloud accounts', 'nc-woo-integration'); ?>
                </a></li>
                <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>">
                    <?php _e('View your recent orders', 'woocommerce'); ?>
                </a></li>
                <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('subscriptions')); ?>">
                    <?php _e('Manage your subscriptions', 'woocommerce'); ?>
                </a></li>
                <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address')); ?>">
                    <?php _e('Edit your shipping and billing addresses', 'woocommerce'); ?>
                </a></li>
                <li><a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>">
                    <?php _e('Change your account details and password', 'woocommerce'); ?>
                </a></li>
            </ul>
            
            <div class="ncwi-dashboard-info" style="margin-top: 30px; padding: 20px; background: #f0f4f8; border-left: 4px solid #0073aa; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #0073aa;"><?php _e('🔗 Link Nextcloud Account', 'nc-woo-integration'); ?></h3>
                <p><?php _e('To use your Nextcloud licenses:', 'nc-woo-integration'); ?></p>
                <ol style="margin-left: 20px;">
                    <li><?php _e('Go to', 'nc-woo-integration'); ?> 
                        <a href="<?php echo wc_get_account_endpoint_url('nextcloud-accounts'); ?>" style="font-weight: bold;">
                            <?php _e('Nextcloud Accounts', 'nc-woo-integration'); ?>
                        </a>
                    </li>
                    <li><?php _e('Create a new account or link an existing account', 'nc-woo-integration'); ?></li>
                    <li><?php _e('Link your active subscriptions to the account', 'nc-woo-integration'); ?></li>
                </ol>
                
                <?php
                // Quick stats
                $account_manager = NCWI_Account_Manager::get_instance();
                $accounts = $account_manager->get_user_accounts(get_current_user_id());
                $active_accounts = array_filter($accounts, function($acc) { return $acc['status'] === 'active'; });
                ?>
                
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <strong><?php _e('Quick Status:', 'nc-woo-integration'); ?></strong>
                    <?php if (count($active_accounts) > 0): ?>
                        <span style="color: #46b450;">✓ <?php printf(_n('%d active Nextcloud account', '%d active Nextcloud accounts', count($active_accounts), 'nc-woo-integration'), count($active_accounts)); ?></span>
                    <?php else: ?>
                        <span style="color: #ff9800;">⚠ <?php _e('No active Nextcloud accounts yet', 'nc-woo-integration'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add JavaScript override as last resort
     */
    public function add_dashboard_override_script() {
        if (!is_account_page() || is_wc_endpoint_url()) {
            return;
        }
        ?>
        <script>
        (function() {
            // Function to replace old content
            function replaceOldDashboard() {
                var contentArea = document.querySelector('.woocommerce-MyAccount-content');
                if (!contentArea) return;
                
                // Check if old content exists
                var hasOldContent = false;
                var oldPhrases = ['Product Management', 'How this works', 'click this link'];
                
                oldPhrases.forEach(function(phrase) {
                    if (contentArea.textContent.includes(phrase)) {
                        hasOldContent = true;
                    }
                });
                
                // Also check for Elementor containers
                var elementorElements = contentArea.querySelectorAll('[class*="elementor-"]');
                if (elementorElements.length > 0) {
                    hasOldContent = true;
                }
                
                // If old content found but no new content, force reload
                if (hasOldContent && !contentArea.querySelector('.ncwi-custom-dashboard-wrapper')) {
                    // Create a marker to prevent infinite reloads
                    if (!sessionStorage.getItem('ncwi_dashboard_reloaded')) {
                        sessionStorage.setItem('ncwi_dashboard_reloaded', 'true');
                        window.location.reload();
                    } else {
                        // If already reloaded, hide old content
                        contentArea.querySelectorAll('details, [class*="elementor-"]').forEach(function(el) {
                            el.style.display = 'none';
                        });
                    }
                }
            }
            
            // Run on load
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', replaceOldDashboard);
            } else {
                replaceOldDashboard();
            }
            
            // Clear reload marker on navigation
            window.addEventListener('beforeunload', function() {
                var isStayingOnAccount = window.location.href.includes('/my-account') && 
                                       !window.location.href.includes('/my-account/');
                if (!isStayingOnAccount) {
                    sessionStorage.removeItem('ncwi_dashboard_reloaded');
                }
            });
        })();
        </script>
        
        <style>
        /* Force hide Elementor content on dashboard */
        .woocommerce-MyAccount-content [class*="elementor-"],
        .woocommerce-MyAccount-content details:has(summary:contains("Product Management")) {
            display: none !important;
        }
        
        /* Ensure our content is visible */
        .ncwi-custom-dashboard-wrapper {
            display: block !important;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        </style>
        <?php
    }
}