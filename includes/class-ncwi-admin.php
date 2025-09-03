<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Admin {
    
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
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Product options
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_options']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_options']);
        
        // Plugin action links
        add_filter('plugin_action_links_' . plugin_basename(NCWI_PLUGIN_DIR . 'nextcloud-woo-integration.php'), [$this, 'add_action_links']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'admin_notices']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Add main menu item
        add_menu_page(
            __('Nextcloud Integration', 'nc-woo-integration'),
            __('Nextcloud', 'nc-woo-integration'),
            'manage_options',
            'ncwi-settings',
            [$this, 'settings_page'],
            'dashicons-cloud',
            30
        );
        
        // Also add to Settings menu
        add_options_page(
            __('Nextcloud WooCommerce Integration', 'nc-woo-integration'),
            __('Nextcloud Integration', 'nc-woo-integration'),
            'manage_options',
            'ncwi-settings',
            [$this, 'settings_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // API Settings
        register_setting('ncwi_api_settings', 'ncwi_deployer_api_url');
        register_setting('ncwi_api_settings', 'ncwi_deployer_api_key');
        register_setting('ncwi_api_settings', 'ncwi_nextcloud_api_url');
        
        // General Settings
        register_setting('ncwi_general_settings', 'ncwi_enable_email_verification');
        register_setting('ncwi_general_settings', 'ncwi_auto_create_on_register');
        register_setting('ncwi_general_settings', 'ncwi_default_quota');
        register_setting('ncwi_general_settings', 'ncwi_trial_quota');
        register_setting('ncwi_general_settings', 'ncwi_sync_interval');
        
        // API Section
        add_settings_section(
            'ncwi_api_section',
            __('API Configuratie', 'nc-woo-integration'),
            [$this, 'api_section_callback'],
            'ncwi_api_settings'
        );
        
        add_settings_field(
            'ncwi_deployer_api_url',
            __('Deployer API URL', 'nc-woo-integration'),
            [$this, 'text_field_callback'],
            'ncwi_api_settings',
            'ncwi_api_section',
            [
                'label_for' => 'ncwi_deployer_api_url',
                'description' => __('De URL van je Deployer API (bijv. https://deployer.example.com)', 'nc-woo-integration')
            ]
        );
        
        add_settings_field(
            'ncwi_deployer_api_key',
            __('Deployer API Key', 'nc-woo-integration'),
            [$this, 'password_field_callback'],
            'ncwi_api_settings',
            'ncwi_api_section',
            [
                'label_for' => 'ncwi_deployer_api_key',
                'description' => __('Je Deployer API key (wordt veilig opgeslagen)', 'nc-woo-integration')
            ]
        );
        
        add_settings_field(
            'ncwi_nextcloud_api_url',
            __('Nextcloud API URL', 'nc-woo-integration'),
            [$this, 'text_field_callback'],
            'ncwi_api_settings',
            'ncwi_api_section',
            [
                'label_for' => 'ncwi_nextcloud_api_url',
                'description' => __('De URL van je Nextcloud instance (bijv. https://nextcloud.example.com)', 'nc-woo-integration')
            ]
        );
        
        // General Section
        add_settings_section(
            'ncwi_general_section',
            __('Algemene Instellingen', 'nc-woo-integration'),
            [$this, 'general_section_callback'],
            'ncwi_general_settings'
        );
        
        add_settings_field(
            'ncwi_enable_email_verification',
            __('Email Verificatie', 'nc-woo-integration'),
            [$this, 'checkbox_field_callback'],
            'ncwi_general_settings',
            'ncwi_general_section',
            [
                'label_for' => 'ncwi_enable_email_verification',
                'description' => __('Gebruik Nextcloud email verificatie voor nieuwe accounts', 'nc-woo-integration')
            ]
        );
        
        add_settings_field(
            'ncwi_auto_create_on_register',
            __('Automatisch Account Aanmaken', 'nc-woo-integration'),
            [$this, 'checkbox_field_callback'],
            'ncwi_general_settings',
            'ncwi_general_section',
            [
                'label_for' => 'ncwi_auto_create_on_register',
                'description' => __('Maak automatisch een Nextcloud trial account aan bij WordPress registratie', 'nc-woo-integration')
            ]
        );
        
        add_settings_field(
            'ncwi_default_quota',
            __('Standaard Quota', 'nc-woo-integration'),
            [$this, 'text_field_callback'],
            'ncwi_general_settings',
            'ncwi_general_section',
            [
                'label_for' => 'ncwi_default_quota',
                'description' => __('Standaard quota voor nieuwe accounts (bijv. 5GB)', 'nc-woo-integration')
            ]
        );
        
        add_settings_field(
            'ncwi_trial_quota',
            __('Trial Quota', 'nc-woo-integration'),
            [$this, 'text_field_callback'],
            'ncwi_general_settings',
            'ncwi_general_section',
            [
                'label_for' => 'ncwi_trial_quota',
                'description' => __('Quota voor trial accounts (bijv. 1GB)', 'nc-woo-integration')
            ]
        );
        
        add_settings_field(
            'ncwi_sync_interval',
            __('Synchronisatie Interval', 'nc-woo-integration'),
            [$this, 'select_field_callback'],
            'ncwi_general_settings',
            'ncwi_general_section',
            [
                'label_for' => 'ncwi_sync_interval',
                'description' => __('Hoe vaak moeten subscriptions gesynchroniseerd worden?', 'nc-woo-integration'),
                'options' => [
                    'hourly' => __('Elk uur', 'nc-woo-integration'),
                    'twicedaily' => __('Twee keer per dag', 'nc-woo-integration'),
                    'daily' => __('Dagelijks', 'nc-woo-integration')
                ]
            ]
        );
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if (isset($_POST['submit']) && isset($_POST['ncwi_settings_nonce'])) {
            if (wp_verify_nonce($_POST['ncwi_settings_nonce'], 'ncwi_save_settings')) {
                $this->save_settings();
            }
        }
        
        // Test connection if requested
        if (isset($_GET['test_connection'])) {
            $this->test_api_connection();
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('ncwi_messages'); ?>
            
            <div class="ncwi-settings-wrapper">
                <div class="ncwi-settings-main">
                    <form method="post" action="">
                        <?php wp_nonce_field('ncwi_save_settings', 'ncwi_settings_nonce'); ?>
                        
                        <div class="ncwi-settings-section">
                            <h2><?php _e('API Configuratie', 'nc-woo-integration'); ?></h2>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="ncwi_deployer_api_url"><?php _e('Deployer API URL', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="ncwi_deployer_api_url" name="ncwi_deployer_api_url" 
                                               value="<?php echo esc_attr(get_option('ncwi_deployer_api_url')); ?>" 
                                               class="regular-text" />
                                        <p class="description"><?php _e('De URL van je Deployer API (bijv. https://deployer.example.com)', 'nc-woo-integration'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ncwi_deployer_api_key"><?php _e('Deployer API Key', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <input type="password" id="ncwi_deployer_api_key" name="ncwi_deployer_api_key" 
                                               value="<?php echo esc_attr(get_option('ncwi_deployer_api_key')); ?>" 
                                               class="regular-text" />
                                        <p class="description"><?php _e('Je Deployer API key (wordt veilig opgeslagen)', 'nc-woo-integration'); ?></p>
                                    </td>
                                </tr>
                               <!-- <tr>
                                    <th scope="row">
                                        <label for="ncwi_nextcloud_api_url"><?php _e('Nextcloud API URL', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="ncwi_nextcloud_api_url" name="ncwi_nextcloud_api_url" 
                                               value="<?php echo esc_attr(get_option('ncwi_nextcloud_api_url')); ?>" 
                                               class="regular-text" />
                                        <p class="description"><?php _e('De URL van je Nextcloud instance (bijv. https://nextcloud.example.com)', 'nc-woo-integration'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ncwi_skip_email_verification"><?php _e('Skip Email Verificatie (Testing)', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="ncwi_skip_email_verification" name="ncwi_skip_email_verification" 
                                                   value="yes" <?php checked(get_option('ncwi_skip_email_verification', 'no'), 'yes'); ?> />
                                            <?php _e('Skip email verificatie check (alleen voor testing!)', 'nc-woo-integration'); ?>
                                        </label>
                                        <p class="description" style="color: #d63638;">
                                            <?php _e('WAARSCHUWING: Dit schakelt de email verificatie controle uit. Gebruik alleen voor testing!', 'nc-woo-integration'); ?>
                                        </p>
                                    </td>
                                </tr> -->
                            </table>
                        </div>
                        
                        <div class="ncwi-settings-section">
                            <h2><?php _e('Algemene Instellingen', 'nc-woo-integration'); ?></h2>
                            <table class="form-table">
                               <!-- <tr>
                                    <th scope="row">
                                        <label for="ncwi_enable_email_verification"><?php _e('Email Verificatie', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="ncwi_enable_email_verification" name="ncwi_enable_email_verification" 
                                                   value="yes" <?php checked(get_option('ncwi_enable_email_verification', 'yes'), 'yes'); ?> />
                                            <?php _e('Gebruik Nextcloud email verificatie voor nieuwe accounts', 'nc-woo-integration'); ?>
                                        </label>
                                    </td>
                                </tr>-->
                                <tr>
                                    <th scope="row">
                                        <label for="ncwi_default_quota"><?php _e('Standaard Quota', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="ncwi_default_quota" name="ncwi_default_quota" 
                                               value="<?php echo esc_attr(get_option('ncwi_default_quota', '5GB')); ?>" 
                                               class="small-text" />
                                        <p class="description"><?php _e('Standaard quota voor nieuwe accounts (bijv. 5GB)', 'nc-woo-integration'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ncwi_trial_quota"><?php _e('Trial Quota', 'nc-woo-integration'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="ncwi_trial_quota" name="ncwi_trial_quota" 
                                               value="<?php echo esc_attr(get_option('ncwi_trial_quota', '1GB')); ?>" 
                                               class="small-text" />
                                        <p class="description"><?php _e('Quota voor trial accounts (bijv. 1GB)', 'nc-woo-integration'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <?php submit_button(__('Instellingen Opslaan', 'nc-woo-integration')); ?>
                    </form>
                    
                    <p>
                        <a href="<?php echo add_query_arg('test_connection', '1'); ?>" class="button">
                            <?php _e('Test API Verbinding', 'nc-woo-integration'); ?>
                        </a>
                    </p>
                </div>
                
                <div class="ncwi-settings-sidebar">
                    <div class="ncwi-info-box">
                        <h3><?php _e('Hulp nodig?', 'nc-woo-integration'); ?></h3>
                        <p><?php _e('Bekijk de documentatie voor installatie instructies en configuratie hulp.', 'nc-woo-integration'); ?></p>
                        <a href="#" class="button"><?php _e('Documentatie', 'nc-woo-integration'); ?></a>
                    </div>
                    
                    <div class="ncwi-info-box">
                        <h3><?php _e('Status', 'nc-woo-integration'); ?></h3>
                        <?php $this->display_status(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        // API Settings
        if (isset($_POST['ncwi_deployer_api_url'])) {
            update_option('ncwi_deployer_api_url', sanitize_text_field($_POST['ncwi_deployer_api_url']));
        }
        
        if (isset($_POST['ncwi_deployer_api_key'])) {
            update_option('ncwi_deployer_api_key', sanitize_text_field($_POST['ncwi_deployer_api_key']));
        }
        
        if (isset($_POST['ncwi_nextcloud_api_url'])) {
            update_option('ncwi_nextcloud_api_url', sanitize_text_field($_POST['ncwi_nextcloud_api_url']));
        }
        
        // General Settings
        $email_verification = isset($_POST['ncwi_enable_email_verification']) ? 'yes' : 'no';
        update_option('ncwi_enable_email_verification', $email_verification);
        
        if (isset($_POST['ncwi_default_quota'])) {
            update_option('ncwi_default_quota', sanitize_text_field($_POST['ncwi_default_quota']));
        }
        
        if (isset($_POST['ncwi_trial_quota'])) {
            update_option('ncwi_trial_quota', sanitize_text_field($_POST['ncwi_trial_quota']));
        }
        
        // Skip verification option
        $skip_verification = isset($_POST['ncwi_skip_email_verification']) ? 'yes' : 'no';
        update_option('ncwi_skip_email_verification', $skip_verification);
        
        // Reload settings
        $this->api = NCWI_API::get_instance();
        
        // Add success message
        add_settings_error(
            'ncwi_messages',
            'ncwi_message',
            __('Instellingen opgeslagen!', 'nc-woo-integration'),
            'success'
        );
    }
    
    /**
     * Field callbacks
     */
    public function text_field_callback($args) {
        $value = get_option($args['label_for'], '');
        ?>
        <input type="text" 
               id="<?php echo esc_attr($args['label_for']); ?>" 
               name="<?php echo esc_attr($args['label_for']); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function password_field_callback($args) {
        $value = get_option($args['label_for'], '');
        ?>
        <input type="password" 
               id="<?php echo esc_attr($args['label_for']); ?>" 
               name="<?php echo esc_attr($args['label_for']); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    public function checkbox_field_callback($args) {
        $value = get_option($args['label_for'], 'no');
        ?>
        <label for="<?php echo esc_attr($args['label_for']); ?>">
            <input type="checkbox" 
                   id="<?php echo esc_attr($args['label_for']); ?>" 
                   name="<?php echo esc_attr($args['label_for']); ?>" 
                   value="yes" 
                   <?php checked($value, 'yes'); ?> />
            <?php echo esc_html($args['description']); ?>
        </label>
        <?php
    }
    
    public function select_field_callback($args) {
        $value = get_option($args['label_for'], 'hourly');
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>" 
                name="<?php echo esc_attr($args['label_for']); ?>">
            <?php foreach ($args['options'] as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    /**
     * Section callbacks
     */
    public function api_section_callback() {
        echo '<p>' . __('Configureer de verbinding met je Deployer en Nextcloud APIs. Deze gegevens worden veilig opgeslagen.', 'nc-woo-integration') . '</p>';
    }
    
    public function general_section_callback() {
        echo '<p>' . __('Algemene plugin instellingen en standaard waarden.', 'nc-woo-integration') . '</p>';
    }
    
    /**
     * Add product options
     */
    public function add_product_options() {
        global $post;
        
        echo '<div class="options_group">';
        
        woocommerce_wp_text_input([
            'id' => '_ncwi_quota',
            'label' => __('Nextcloud Quota', 'nc-woo-integration'),
            'placeholder' => '10GB',
            'desc_tip' => true,
            'description' => __('Het quota dat bij dit product hoort (bijv. 10GB, 50GB, 100GB)', 'nc-woo-integration')
        ]);
        
        echo '</div>';
    }
    
    /**
     * Save product options
     */
    public function save_product_options($post_id) {
        $quota = isset($_POST['_ncwi_quota']) ? sanitize_text_field($_POST['_ncwi_quota']) : '';
        update_post_meta($post_id, '_ncwi_quota', $quota);
    }
    
    /**
     * Add plugin action links
     */
    public function add_action_links($links) {
        $action_links = [
            'settings' => '<a href="' . admin_url('options-general.php?page=ncwi-settings') . '">' . __('Instellingen', 'nc-woo-integration') . '</a>'
        ];
        
        return array_merge($action_links, $links);
    }
    
    /**
     * Admin notices
     */
    public function admin_notices() {
        // Check if API is configured
        $api = NCWI_API::get_instance();
        $validation = $api->validate_configuration();
        
        if (is_wp_error($validation) && current_user_can('manage_options')) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php _e('Nextcloud WooCommerce Integration:', 'nc-woo-integration'); ?></strong>
                    <?php echo esc_html($validation->get_error_message()); ?>
                    <a href="<?php echo admin_url('options-general.php?page=ncwi-settings'); ?>">
                        <?php _e('Configureer nu', 'nc-woo-integration'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Display status
     */
    private function display_status() {
        $api = NCWI_API::get_instance();
        $validation = $api->validate_configuration();
        
        if (is_wp_error($validation)) {
            echo '<p class="ncwi-status-error">ERROR: ' . __('Niet geconfigureerd', 'nc-woo-integration') . '</p>';
        } else {
            echo '<p class="ncwi-status-success">OK ' . __('Verbonden', 'nc-woo-integration') . '</p>';
        }
        
        // Display some stats
        global $wpdb;
        $total_accounts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_accounts");
        $active_links = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ncwi_account_subscriptions WHERE status = 'active'");
        
        echo '<ul>';
        echo '<li>' . sprintf(__('Totaal accounts: %d', 'nc-woo-integration'), $total_accounts) . '</li>';
        echo '<li>' . sprintf(__('Actieve koppelingen: %d', 'nc-woo-integration'), $active_links) . '</li>';
        echo '</ul>';
    }
    
    /**
     * Test API connection
     */
    private function test_api_connection() {
        $api = NCWI_API::get_instance();
        $validation = $api->validate_configuration();
        
        if (is_wp_error($validation)) {
            add_settings_error(
                'ncwi_messages',
                'ncwi_message',
                __('API verbinding mislukt: ', 'nc-woo-integration') . $validation->get_error_message(),
                'error'
            );
        } else {
            add_settings_error(
                'ncwi_messages',
                'ncwi_message',
                __('API verbinding succesvol!', 'nc-woo-integration'),
                'success'
            );
        }
    }
}