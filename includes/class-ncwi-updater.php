<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Self-Updater
 */
class NCWI_Updater {
    
    private $plugin_slug;
    private $version;
    private $github_url;
    private $plugin_file;
    
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        
        // Haal plugin data op
        $plugin_data = get_file_data($plugin_file, [
            'Version' => 'Version',
            'PluginName' => 'Plugin Name'
        ]);
        
        $this->version = $plugin_data['Version'];
        $this->github_url = 'https://api.github.com/repos/miransweb/nextcloud-woo-integration/releases/latest';
        
        // Hooks
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_folder_name'], 10, 3);
        
        // Add update check button in admin
        add_action('admin_menu', [$this, 'add_update_menu']);
    }
    
    /**
     * Check for updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Check GitHub for latest release
        $remote_data = $this->get_remote_data();
        
        if ($remote_data && version_compare($this->version, $remote_data->tag_name, '<')) {
            $plugin_data = [
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_data->tag_name,
                'url' => 'https://github.com/miransweb/nextcloud-woo-integration',
                'package' => $remote_data->zipball_url,
                'icons' => [],
                'banners' => [],
                'banners_rtl' => [],
                'tested' => '6.4',
                'requires_php' => '7.4',
                'compatibility' => new stdClass()
            ];
            
            $transient->response[$this->plugin_slug] = (object) $plugin_data;
        }
        
        return $transient;
    }
    
    /**
     * Get plugin info for update details
     */
    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return $res;
        }
        
        if ($args->slug !== dirname($this->plugin_slug)) {
            return $res;
        }
        
        $remote_data = $this->get_remote_data();
        
        if (!$remote_data) {
            return $res;
        }
        
        $plugin_info = [
            'name' => 'Nextcloud WooCommerce Integration',
            'slug' => dirname($this->plugin_slug),
            'version' => $remote_data->tag_name,
            'author' => '<a href="https://github.com/miransweb">Mirans Web</a>',
            'homepage' => 'https://github.com/miransweb/nextcloud-woo-integration',
            'short_description' => 'Integreert Nextcloud accounts met WooCommerce subscriptions',
            'sections' => [
                'description' => 'Deze plugin koppelt WooCommerce subscriptions aan Nextcloud accounts.',
                'changelog' => $this->parse_changelog($remote_data->body)
            ],
            'download_link' => $remote_data->zipball_url
        ];
        
        return (object) $plugin_info;
    }
    
    /**
     * Fix folder name after update
     */
    public function fix_folder_name($source, $remote_source, $upgrader) {
        global $wp_filesystem;
        
        $plugin = isset($upgrader->skin->plugin) ? $upgrader->skin->plugin : '';
        if ($plugin !== $this->plugin_slug) {
            return $source;
        }
        
        $corrected_source = trailingslashit($remote_source) . dirname($this->plugin_slug) . '/';
        
        if ($wp_filesystem->move($source, $corrected_source)) {
            return $corrected_source;
        }
        
        return $source;
    }
    
    /**
     * Get remote data from GitHub
     */
    private function get_remote_data() {
        $transient_key = 'ncwi_update_data';
        $cached_data = get_transient($transient_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $response = wp_remote_get($this->github_url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response));
        
        if (!empty($data->tag_name)) {
            set_transient($transient_key, $data, HOUR_IN_SECONDS * 6);
            return $data;
        }
        
        return false;
    }
    
    /**
     * Parse changelog from release body
     */
    private function parse_changelog($body) {
        $changelog = '<h4>Changelog</h4>';
        $changelog .= nl2br(esc_html($body));
        return $changelog;
    }
    
    /**
     * Add manual update check to admin menu
     */
    public function add_update_menu() {
        add_submenu_page(
            'ncwi-settings',
            'Check for Updates',
            'Check Updates',
            'manage_options',
            'ncwi-check-update',
            [$this, 'update_check_page']
        );
    }
    
    /**
     * Manual update check page
     */
    public function update_check_page() {
        if (isset($_POST['check_update'])) {
            delete_transient('ncwi_update_data');
            wp_update_plugins();
            echo '<div class="notice notice-success"><p>Update check complete!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Check for Updates</h1>
            <p>Current version: <strong><?php echo esc_html($this->version); ?></strong></p>
            
            <form method="post">
                <?php wp_nonce_field('ncwi_check_update'); ?>
                <p>
                    <input type="submit" name="check_update" class="button button-primary" value="Check for Updates">
                </p>
            </form>
            
            <?php
            $remote_data = $this->get_remote_data();
            if ($remote_data) {
                echo '<h3>Latest Release</h3>';
                echo '<p>Version: <strong>' . esc_html($remote_data->tag_name) . '</strong></p>';
                echo '<p>Released: ' . date('Y-m-d', strtotime($remote_data->published_at)) . '</p>';
                
                if (version_compare($this->version, $remote_data->tag_name, '<')) {
                    echo '<p class="notice notice-warning">An update is available! Go to Plugins page to update.</p>';
                } else {
                    echo '<p class="notice notice-success">You have the latest version!</p>';
                }
            }
            ?>
        </div>
        <?php
    }
}

