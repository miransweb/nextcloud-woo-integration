<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCWI_Security {
    
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
        // Encrypt/decrypt filters
        add_filter('ncwi_encrypt_data', [$this, 'encrypt_data'], 10, 1);
        add_filter('ncwi_decrypt_data', [$this, 'decrypt_data'], 10, 1);
        
        // Rate limiting
        add_action('init', [$this, 'init_rate_limiting']);
        
        // Security headers
        add_action('send_headers', [$this, 'add_security_headers']);
        
        // Validate requests
        add_action('wp_ajax_nopriv_ncwi_create_account', [$this, 'block_unauthorized']);
        add_action('wp_ajax_nopriv_ncwi_link_account', [$this, 'block_unauthorized']);
    }
    
    /**
     * Encrypt sensitive data
     */
    public function encrypt_data($data) {
        $key = $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes(16);
        
        $encrypted = openssl_encrypt(
            serialize($data),
            'AES-256-CBC',
            $key,
            0,
            $iv
        );
        
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Decrypt sensitive data
     */
    public function decrypt_data($data) {
        $key = $this->get_encryption_key();
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        
        $decrypted = openssl_decrypt(
            $encrypted_data,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );
        
        return unserialize($decrypted);
    }
    
    /**
     * Get encryption key
     */
    private function get_encryption_key() {
        $key = get_option('ncwi_encryption_key');
        
        if (!$key) {
            $key = wp_generate_password(32, true, true);
            update_option('ncwi_encryption_key', $key);
        }
        
        return $key;
    }
    
    /**
     * Initialize rate limiting
     */
    public function init_rate_limiting() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $this->check_rate_limit();
        }
    }
    
    /**
     * Check rate limit
     */
    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $transient_key = 'ncwi_rate_' . md5($ip);
        
        $attempts = get_transient($transient_key);
        
        if ($attempts === false) {
            set_transient($transient_key, 1, MINUTE_IN_SECONDS * 5);
        } else {
            $attempts++;
            
            if ($attempts > 30) {
                wp_die(__('Te veel verzoeken. Probeer het later opnieuw.', 'nc-woo-integration'), 429);
            }
            
            set_transient($transient_key, $attempts, MINUTE_IN_SECONDS * 5);
        }
    }
    
    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Add security headers
     */
    public function add_security_headers() {
        if (!is_admin()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    /**
     * Block unauthorized access
     */
    public function block_unauthorized() {
        wp_die(__('Geen toegang', 'nc-woo-integration'), 403);
    }
    
    /**
     * Validate AJAX nonce
     */
    public static function validate_ajax_nonce() {
        if (!check_ajax_referer('ncwi_ajax_nonce', 'nonce', false)) {
            wp_die(__('Beveiligingscontrole mislukt', 'nc-woo-integration'), 403);
        }
    }
    
    /**
     * Sanitize API response
     */
    public static function sanitize_api_response($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize_api_response'], $data);
        } elseif (is_string($data)) {
            return sanitize_text_field($data);
        }
        
        return $data;
    }
    
    /**
     * Log security events
     */
    public static function log_security_event($event_type, $details = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'NCWI Security Event [%s]: %s',
                $event_type,
                json_encode($details)
            ));
        }
    }
}