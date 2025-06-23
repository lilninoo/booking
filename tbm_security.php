<?php
/**
 * Security Class
 * 
 * Handles security, permissions, and attack protection
 * 
 * @package TrainerBootcampManager
 * @subpackage Security
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Security {
    
    /**
     * Rate limit settings
     */
    const RATE_LIMITS = [
        'login_attempts' => ['limit' => 5, 'window' => 300], // 5 attempts per 5 minutes
        'api_requests' => ['limit' => 100, 'window' => 3600], // 100 requests per hour
        'file_uploads' => ['limit' => 10, 'window' => 600], // 10 uploads per 10 minutes
        'password_reset' => ['limit' => 3, 'window' => 1800] // 3 resets per 30 minutes
    ];
    
    /**
     * Suspicious patterns
     */
    const SUSPICIOUS_PATTERNS = [
        '/\b(union\s+select|select\s+.*\s+from|insert\s+into|delete\s+from)\b/i',
        '/<script[^>]*>.*?<\/script>/i',
        '/javascript\s*:/i',
        '/on\w+\s*=/i',
        '/eval\s*\(/i',
        '/base64_decode\s*\(/i'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'init_security_hooks']);
        add_action('wp_login_failed', [$this, 'handle_failed_login']);
        add_action('wp_login', [$this, 'handle_successful_login'], 10, 2);
        add_action('rest_api_init', [$this, 'setup_api_security']);
        add_filter('authenticate', [$this, 'check_login_attempts'], 30, 3);
    }
    
    /**
     * Initialize security hooks
     */
    public function init_security_hooks() {
        // Input sanitization
        add_filter('tbm_sanitize_input', [$this, 'sanitize_input'], 10, 2);
        
        // File upload security
        add_filter('wp_handle_upload_prefilter', [$this, 'secure_file_upload']);
        
        // Content security
        add_filter('tbm_secure_content', [$this, 'secure_content']);
        
        // CSRF protection
        add_action('wp_ajax_nopriv_tbm_*', [$this, 'verify_csrf_token'], 1);
        add_action('wp_ajax_tbm_*', [$this, 'verify_csrf_token'], 1);
        
        // Headers security
        add_action('send_headers', [$this, 'add_security_headers']);
        
        // Blocked IPs check
        add_action('init', [$this, 'check_blocked_ips'], 1);
        
        // Honeypot protection
        add_action('tbm_form_security', [$this, 'add_honeypot_field']);
        add_filter('tbm_validate_form', [$this, 'validate_honeypot'], 10, 2);
    }
    
    /**
     * Sanitize input data
     */
    public function sanitize_input($data, $type = 'text') {
        if (is_array($data)) {
            return array_map(function($item) use ($type) {
                return $this->sanitize_input($item, $type);
            }, $data);
        }
        
        // Check for suspicious patterns
        if ($this->contains_suspicious_patterns($data)) {
            TBM_Utilities::log_activity('security_violation', 'input', 0, 'Suspicious pattern detected: ' . substr($data, 0, 100));
            return '';
        }
        
        switch ($type) {
            case 'email':
                return sanitize_email($data);
                
            case 'url':
                return esc_url_raw($data);
                
            case 'html':
                return wp_kses($data, $this->get_allowed_html_tags());
                
            case 'textarea':
                return sanitize_textarea_field($data);
                
            case 'number':
                return is_numeric($data) ? floatval($data) : 0;
                
            case 'integer':
                return intval($data);
                
            case 'boolean':
                return (bool) $data;
                
            case 'filename':
                return sanitize_file_name($data);
                
            case 'slug':
                return sanitize_title($data);
                
            case 'text':
            default:
                return sanitize_text_field($data);
        }
    }
    
    /**
     * Check for suspicious patterns
     */
    private function contains_suspicious_patterns($data) {
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get allowed HTML tags
     */
    private function get_allowed_html_tags() {
        return [
            'p' => [],
            'br' => [],
            'strong' => [],
            'em' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'a' => ['href' => [], 'title' => [], 'target' => []],
            'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
            'blockquote' => [],
            'code' => [],
            'pre' => []
        ];
    }
    
    /**
     * Secure file upload
     */
    public function secure_file_upload($file) {
        // Check rate limiting
        if (!$this->check_rate_limit('file_uploads')) {
            $file['error'] = 'Rate limit exceeded. Please try again later.';
            return $file;
        }
        
        $filename = $file['name'];
        $tmp_name = $file['tmp_name'];
        
        // Check file extension
        $allowed_extensions = get_option('tbm_allowed_file_types', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_extensions)) {
            $file['error'] = 'File type not allowed.';
            return $file;
        }
        
        // Check file content (magic bytes)
        if (!$this->validate_file_content($tmp_name, $file_ext)) {
            $file['error'] = 'File content does not match extension.';
            return $file;
        }
        
        // Scan for malicious content
        if ($this->contains_malicious_content($tmp_name)) {
            $file['error'] = 'File contains potentially malicious content.';
            TBM_Utilities::log_activity('security_violation', 'file_upload', 0, 'Malicious file upload attempt: ' . $filename);
            return $file;
        }
        
        // Rename file for security
        $file['name'] = TBM_Utilities::generate_unique_id('file_') . '.' . $file_ext;
        
        return $file;
    }
    
    /**
     * Validate file content against extension
     */
    private function validate_file_content($tmp_name, $extension) {
        if (!file_exists($tmp_name)) {
            return false;
        }
        
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $tmp_name);
        finfo_close($file_info);
        
        $allowed_mimes = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'txt' => 'text/plain'
        ];
        
        return isset($allowed_mimes[$extension]) && $mime_type === $allowed_mimes[$extension];
    }
    
    /**
     * Check for malicious content in files
     */
    private function contains_malicious_content($file_path) {
        $content = file_get_contents($file_path, false, null, 0, 8192); // Read first 8KB
        
        $malicious_patterns = [
            '/<\?php/i',
            '/<%/i',
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload=/i',
            '/onerror=/i'
        ];
        
        foreach ($malicious_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Secure content output
     */
    public function secure_content($content) {
        // Remove potentially dangerous content
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/javascript\s*:/i', '', $content);
        $content = preg_replace('/on\w+\s*=/i', '', $content);
        
        return $content;
    }
    
    /**
     * Rate limiting
     */
    public function check_rate_limit($action, $identifier = null) {
        if (!isset(self::RATE_LIMITS[$action])) {
            return true;
        }
        
        $config = self::RATE_LIMITS[$action];
        $identifier = $identifier ?: $this->get_client_identifier();
        
        $transient_key = "tbm_rate_limit_{$action}_{$identifier}";
        $attempts = get_transient($transient_key) ?: 0;
        
        if ($attempts >= $config['limit']) {
            // Log rate limit violation
            TBM_Utilities::log_activity('rate_limit_exceeded', $action, 0, "Identifier: {$identifier}");
            return false;
        }
        
        // Increment counter
        set_transient($transient_key, $attempts + 1, $config['window']);
        
        return true;
    }
    
    /**
     * Get client identifier for rate limiting
     */
    private function get_client_identifier() {
        $ip = TBM_Utilities::get_client_ip();
        $user_id = get_current_user_id();
        
        return $user_id ? "user_{$user_id}" : "ip_{$ip}";
    }
    
    /**
     * Handle failed login
     */
    public function handle_failed_login($username) {
        $ip = TBM_Utilities::get_client_ip();
        
        // Log failed attempt
        TBM_Utilities::log_activity('login_failed', 'user', 0, "Username: {$username}, IP: {$ip}");
        
        // Check if IP should be temporarily blocked
        $failed_attempts = get_transient("tbm_failed_login_{$ip}") ?: 0;
        $failed_attempts++;
        
        set_transient("tbm_failed_login_{$ip}", $failed_attempts, 300); // 5 minutes
        
        // Block IP after 5 failed attempts
        if ($failed_attempts >= 5) {
            $this->temporarily_block_ip($ip, 1800); // 30 minutes
            TBM_Utilities::log_activity('ip_blocked', 'security', 0, "IP blocked for excessive login attempts: {$ip}");
        }
    }
    
    /**
     * Handle successful login
     */
    public function handle_successful_login($user_login, $user) {
        $ip = TBM_Utilities::get_client_ip();
        
        // Clear failed attempts
        delete_transient("tbm_failed_login_{$ip}");
        
        // Log successful login
        TBM_Utilities::log_activity('login_success', 'user', $user->ID, "IP: {$ip}");
        
        // Update last login time
        update_user_meta($user->ID, 'tbm_last_login', current_time('mysql'));
        update_user_meta($user->ID, 'tbm_last_login_ip', $ip);
    }
    
    /**
     * Check login attempts before authentication
     */
    public function check_login_attempts($user, $username, $password) {
        if (!$this->check_rate_limit('login_attempts')) {
            return new WP_Error('rate_limit_exceeded', 'Too many login attempts. Please try again later.');
        }
        
        return $user;
    }
    
    /**
     * Temporarily block IP
     */
    private function temporarily_block_ip($ip, $duration = 1800) {
        set_transient("tbm_blocked_ip_{$ip}", time() + $duration, $duration);
    }
    
    /**
     * Check if IP is blocked
     */
    public function check_blocked_ips() {
        $ip = TBM_Utilities::get_client_ip();
        
        // Check temporary blocks
        if (get_transient("tbm_blocked_ip_{$ip}")) {
            wp_die('Access denied. Your IP has been temporarily blocked due to suspicious activity.');
        }
        
        // Check permanent blocks
        $blocked_ips = get_option('tbm_blocked_ips', []);
        if (in_array($ip, $blocked_ips)) {
            wp_die('Access denied. Your IP address has been blocked.');
        }
    }
    
    /**
     * Add security headers
     */
    public function add_security_headers() {
        if (headers_sent()) {
            return;
        }
        
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // XSS Protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy (basic)
        if (get_option('tbm_enable_csp', false)) {
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self';";
            header("Content-Security-Policy: {$csp}");
        }
        
        // HSTS (only if HTTPS)
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    /**
     * Setup API security
     */
    public function setup_api_security() {
        // Add rate limiting to REST API
        add_filter('rest_pre_dispatch', [$this, 'api_rate_limiting'], 10, 3);
        
        // Add authentication requirements
        add_filter('rest_authentication_errors', [$this, 'api_authentication_check']);
    }
    
    /**
     * API rate limiting
     */
    public function api_rate_limiting($result, $server, $request) {
        $route = $request->get_route();
        
        // Only apply to TBM routes
        if (strpos($route, '/tbm/') === false) {
            return $result;
        }
        
        if (!$this->check_rate_limit('api_requests')) {
            return new WP_Error('rate_limit_exceeded', 'API rate limit exceeded', ['status' => 429]);
        }
        
        return $result;
    }
    
    /**
     * API authentication check
     */
    public function api_authentication_check($result) {
        // Allow if already authenticated
        if (!empty($result)) {
            return $result;
        }
        
        // Check for API key authentication
        $api_key = $this->get_api_key_from_request();
        if ($api_key && $this->validate_api_key($api_key)) {
            return true;
        }
        
        return $result;
    }
    
    /**
     * Get API key from request
     */
    private function get_api_key_from_request() {
        // Check Authorization header
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.*)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        // Check query parameter
        return $_GET['api_key'] ?? null;
    }
    
    /**
     * Validate API key
     */
    private function validate_api_key($api_key) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tbm_api_keys';
        
        $key_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE api_key = %s AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
            hash('sha256', $api_key)
        ));
        
        if ($key_data) {
            // Update last used
            $wpdb->update(
                $table,
                ['last_used_at' => current_time('mysql')],
                ['id' => $key_data->id]
            );
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Generate API key
     */
    public function generate_api_key($user_id, $name, $permissions = [], $expires_at = null) {
        global $wpdb;
        
        $api_key = TBM_Utilities::generate_secure_token(32);
        $api_key_hash = hash('sha256', $api_key);
        
        $table = $wpdb->prefix . 'tbm_api_keys';
        
        $data = [
            'user_id' => $user_id,
            'name' => sanitize_text_field($name),
            'api_key' => $api_key_hash,
            'permissions' => json_encode($permissions),
            'status' => 'active',
            'expires_at' => $expires_at,
            'created_at' => current_time('mysql')
        ];
        
        $result = $wpdb->insert($table, $data);
        
        if ($result) {
            return $api_key; // Return unhashed key only once
        }
        
        return false;
    }
    
    /**
     * CSRF protection
     */
    public function verify_csrf_token() {
        $action = current_action();
        
        // Skip verification for some actions
        $skip_actions = ['wp_ajax_nopriv_heartbeat', 'wp_ajax_heartbeat'];
        if (in_array($action, $skip_actions)) {
            return;
        }
        
        $nonce = $_REQUEST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '';
        $action_name = str_replace(['wp_ajax_', 'wp_ajax_nopriv_'], '', $action);
        
        if (!wp_verify_nonce($nonce, $action_name . '_nonce')) {
            TBM_Utilities::log_activity('csrf_violation', 'security', 0, "Action: {$action_name}");
            wp_die('Security check failed. Please refresh the page and try again.', 'Security Error', ['response' => 403]);
        }
    }
    
    /**
     * Add honeypot field
     */
    public function add_honeypot_field() {
        $field_name = 'tbm_' . TBM_Utilities::generate_unique_id('hp_', 8);
        echo '<input type="text" name="' . $field_name . '" value="" style="display:none !important;" tabindex="-1" autocomplete="off">';
        echo '<input type="hidden" name="tbm_honeypot_field" value="' . $field_name . '">';
    }
    
    /**
     * Validate honeypot
     */
    public function validate_honeypot($is_valid, $form_data) {
        $honeypot_field = $form_data['tbm_honeypot_field'] ?? '';
        
        if ($honeypot_field && !empty($form_data[$honeypot_field])) {
            TBM_Utilities::log_activity('honeypot_triggered', 'security', 0, 'Bot detected via honeypot');
            return false;
        }
        
        return $is_valid;
    }
    
    /**
     * Encrypt sensitive data
     */
    public function encrypt_data($data, $key = null) {
        if (!function_exists('openssl_encrypt')) {
            return base64_encode($data); // Fallback
        }
        
        $key = $key ?: $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes(16);
        
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    public function decrypt_data($encrypted_data, $key = null) {
        if (!function_exists('openssl_decrypt')) {
            return base64_decode($encrypted_data); // Fallback
        }
        
        $key = $key ?: $this->get_encryption_key();
        $data = base64_decode($encrypted_data);
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * Get or generate encryption key
     */
    private function get_encryption_key() {
        $key = get_option('tbm_encryption_key');
        
        if (!$key) {
            $key = TBM_Utilities::generate_secure_token(32);
            update_option('tbm_encryption_key', $key);
        }
        
        return $key;
    }
    
    /**
     * Hash password with salt
     */
    public function hash_password($password, $salt = null) {
        $salt = $salt ?: wp_generate_password(22, false);
        return password_hash($password . $salt, PASSWORD_ARGON2ID) . ':' . $salt;
    }
    
    /**
     * Verify hashed password
     */
    public function verify_password($password, $hash) {
        if (strpos($hash, ':') === false) {
            return password_verify($password, $hash);
        }
        
        list($hash_part, $salt) = explode(':', $hash, 2);
        return password_verify($password . $salt, $hash_part);
    }
    
    /**
     * Generate secure random string
     */
    public function generate_secure_random($length = 32) {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        }
        
        return TBM_Utilities::generate_secure_token($length);
    }
    
    /**
     * Validate and sanitize user permissions
     */
    public function validate_permissions($permissions) {
        $allowed_permissions = [
            'read_tbm_trainers',
            'edit_tbm_trainers',
            'delete_tbm_trainers',
            'read_tbm_bootcamps',
            'edit_tbm_bootcamps',
            'delete_tbm_bootcamps',
            'view_tbm_analytics',
            'manage_tbm_payments'
        ];
        
        return array_intersect($permissions, $allowed_permissions);
    }
    
    /**
     * Check user capabilities with logging
     */
    public function check_capability($capability, $object_id = null) {
        $user_id = get_current_user_id();
        $can_access = current_user_can($capability, $object_id);
        
        if (!$can_access) {
            TBM_Utilities::log_activity('permission_denied', 'security', 0, "Capability: {$capability}, User: {$user_id}");
        }
        
        return $can_access;
    }
    
    /**
     * Clean up security logs
     */
    public function cleanup_security_logs() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tbm_activity_log';
        
        // Delete logs older than 90 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        ));
        
        // Delete rate limit transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tbm_rate_limit_%'"
        );
        
        // Delete expired IP blocks
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tbm_blocked_ip_%'"
        );
    }
    
    /**
     * Get security summary
     */
    public function get_security_summary() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tbm_activity_log';
        $last_24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        return [
            'failed_logins_24h' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE action = 'login_failed' AND created_at > %s",
                $last_24h
            )),
            'security_violations_24h' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE action IN ('security_violation', 'csrf_violation', 'honeypot_triggered') AND created_at > %s",
                $last_24h
            )),
            'blocked_ips_active' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_tbm_blocked_ip_%'"
            ),
            'rate_limits_active' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_tbm_rate_limit_%'"
            )
        ];
    }
}