<?php
/**
 * Utilities Class
 * 
 * Common utility functions used throughout the plugin
 * 
 * @package TrainerBootcampManager
 * @subpackage Utilities
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Utilities {
    
    /**
     * Supported file types for uploads
     */
    const ALLOWED_FILE_TYPES = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'document' => ['pdf', 'doc', 'docx', 'txt', 'rtf'],
        'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv'],
        'audio' => ['mp3', 'wav', 'ogg', 'aac']
    ];
    
    /**
     * Common date formats
     */
    const DATE_FORMATS = [
        'Y-m-d' => 'YYYY-MM-DD',
        'd/m/Y' => 'DD/MM/YYYY',
        'm/d/Y' => 'MM/DD/YYYY',
        'F j, Y' => 'Month D, YYYY',
        'j F Y' => 'D Month YYYY'
    ];
    
    /**
     * Generate unique ID
     */
    public static function generate_unique_id($prefix = '', $length = 8) {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $id = '';
        
        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $prefix . $id;
    }
    
    /**
     * Generate secure token
     */
    public static function generate_secure_token($length = 32) {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        }
        
        return wp_generate_password($length, false);
    }
    
    /**
     * Sanitize file name
     */
    public static function sanitize_file_name($filename) {
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Remove multiple underscores
        $filename = preg_replace('/_+/', '_', $filename);
        
        // Remove leading/trailing underscores
        $filename = trim($filename, '_');
        
        return $filename;
    }
    
    /**
     * Validate file upload
     */
    public static function validate_file_upload($file, $allowed_types = null, $max_size = null) {
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return new WP_Error('invalid_file', 'Invalid file upload');
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'File upload error: ' . $file['error']);
        }
        
        // Get file extension
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Validate file type
        if ($allowed_types) {
            $allowed_extensions = [];
            
            if (is_string($allowed_types)) {
                $allowed_extensions = self::ALLOWED_FILE_TYPES[$allowed_types] ?? [$allowed_types];
            } elseif (is_array($allowed_types)) {
                $allowed_extensions = $allowed_types;
            }
            
            if (!in_array($file_ext, $allowed_extensions)) {
                return new WP_Error('invalid_file_type', 'File type not allowed');
            }
        }
        
        // Validate file size
        $max_size = $max_size ?: get_option('tbm_max_file_size', 10485760); // 10MB default
        
        if ($file['size'] > $max_size) {
            $max_size_mb = round($max_size / 1024 / 1024, 1);
            return new WP_Error('file_too_large', "File size exceeds {$max_size_mb}MB limit");
        }
        
        // Validate MIME type
        $file_type = wp_check_filetype($file['name']);
        if (!$file_type['type']) {
            return new WP_Error('invalid_mime_type', 'Invalid file MIME type');
        }
        
        return true;
    }
    
    /**
     * Upload file to TBM directory
     */
    public static function upload_file($file, $subfolder = '', $rename = true) {
        $validation = self::validate_file_upload($file);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        $upload_dir = wp_upload_dir();
        $tbm_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager';
        
        if ($subfolder) {
            $tbm_dir .= '/' . trim($subfolder, '/');
        }
        
        // Create directory if it doesn't exist
        if (!file_exists($tbm_dir)) {
            wp_mkdir_p($tbm_dir);
            
            // Create .htaccess for security
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<Files \"*.php\">\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</Files>\n";
            file_put_contents($tbm_dir . '/.htaccess', $htaccess_content);
        }
        
        // Generate filename
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($rename) {
            $filename = self::generate_unique_id('file_') . '.' . $file_ext;
        } else {
            $filename = self::sanitize_file_name($file['name']);
        }
        
        $file_path = $tbm_dir . '/' . $filename;
        
        // Ensure unique filename
        $counter = 1;
        $original_filename = $filename;
        while (file_exists($file_path)) {
            $name_parts = pathinfo($original_filename);
            $filename = $name_parts['filename'] . '_' . $counter . '.' . $name_parts['extension'];
            $file_path = $tbm_dir . '/' . $filename;
            $counter++;
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return new WP_Error('upload_failed', 'Failed to move uploaded file');
        }
        
        // Set proper permissions
        chmod($file_path, 0644);
        
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
        
        return [
            'file_path' => $file_path,
            'file_url' => $file_url,
            'file_name' => $filename,
            'file_size' => filesize($file_path),
            'mime_type' => mime_content_type($file_path),
            'file_type' => $file_ext
        ];
    }
    
    /**
     * Delete file safely
     */
    public static function delete_file($file_path) {
        // Security check - only allow deletion within TBM directory
        $upload_dir = wp_upload_dir();
        $tbm_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager';
        
        if (strpos(realpath($file_path), realpath($tbm_dir)) !== 0) {
            return new WP_Error('security_violation', 'File deletion not allowed outside TBM directory');
        }
        
        if (file_exists($file_path) && is_file($file_path)) {
            return unlink($file_path);
        }
        
        return false;
    }
    
    /**
     * Format file size for display
     */
    public static function format_file_size($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Format date for display
     */
    public static function format_date($date, $format = null, $timezone = null) {
        if (empty($date)) {
            return '';
        }
        
        $format = $format ?: get_option('date_format');
        $timezone = $timezone ?: wp_timezone();
        
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }
        
        $date_obj = new DateTime($date);
        $date_obj->setTimezone($timezone);
        
        return $date_obj->format($format);
    }
    
    /**
     * Format time for display
     */
    public static function format_time($time, $format = null, $timezone = null) {
        if (empty($time)) {
            return '';
        }
        
        $format = $format ?: get_option('time_format');
        $timezone = $timezone ?: wp_timezone();
        
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }
        
        $time_obj = new DateTime($time);
        $time_obj->setTimezone($timezone);
        
        return $time_obj->format($format);
    }
    
    /**
     * Format datetime for display
     */
    public static function format_datetime($datetime, $date_format = null, $time_format = null, $timezone = null) {
        if (empty($datetime)) {
            return '';
        }
        
        $date_part = self::format_date($datetime, $date_format, $timezone);
        $time_part = self::format_time($datetime, $time_format, $timezone);
        
        return $date_part . ' ' . $time_part;
    }
    
    /**
     * Get relative time (e.g., "2 hours ago")
     */
    public static function get_relative_time($datetime) {
        if (empty($datetime)) {
            return '';
        }
        
        $time = strtotime($datetime);
        $current_time = current_time('timestamp');
        $difference = $current_time - $time;
        
        if ($difference < 60) {
            return 'il y a quelques secondes';
        } elseif ($difference < 3600) {
            $minutes = floor($difference / 60);
            return "il y a {$minutes} minute" . ($minutes > 1 ? 's' : '');
        } elseif ($difference < 86400) {
            $hours = floor($difference / 3600);
            return "il y a {$hours} heure" . ($hours > 1 ? 's' : '');
        } elseif ($difference < 2592000) {
            $days = floor($difference / 86400);
            return "il y a {$days} jour" . ($days > 1 ? 's' : '');
        } else {
            return self::format_date($datetime);
        }
    }
    
    /**
     * Generate color from string (for avatars, etc.)
     */
    public static function generate_color_from_string($string) {
        $hash = md5($string);
        $hue = hexdec(substr($hash, 0, 2)) / 255 * 360;
        
        return "hsl({$hue}, 70%, 50%)";
    }
    
    /**
     * Get initials from name
     */
    public static function get_initials($name) {
        $words = explode(' ', trim($name));
        $initials = '';
        
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper(substr($word, 0, 1));
            }
        }
        
        return substr($initials, 0, 2);
    }
    
    /**
     * Truncate text with ellipsis
     */
    public static function truncate_text($text, $length = 100, $ellipsis = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        
        $truncated = substr($text, 0, $length);
        $last_space = strrpos($truncated, ' ');
        
        if ($last_space !== false) {
            $truncated = substr($truncated, 0, $last_space);
        }
        
        return $truncated . $ellipsis;
    }
    
    /**
     * Extract excerpt from content
     */
    public static function get_excerpt($content, $length = 160) {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        
        return self::truncate_text(trim($content), $length);
    }
    
    /**
     * Validate email address
     */
    public static function validate_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number
     */
    public static function validate_phone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Check minimum length
        if (strlen($phone) < 8) {
            return false;
        }
        
        // Basic international format validation
        if (substr($phone, 0, 1) === '+') {
            return strlen($phone) >= 10 && strlen($phone) <= 15;
        }
        
        return strlen($phone) >= 8 && strlen($phone) <= 12;
    }
    
    /**
     * Validate URL
     */
    public static function validate_url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Generate slug from title
     */
    public static function generate_slug($title) {
        $slug = sanitize_title($title);
        
        if (empty($slug)) {
            $slug = self::generate_unique_id('item_');
        }
        
        return $slug;
    }
    
    /**
     * Convert timezone
     */
    public static function convert_timezone($datetime, $from_timezone, $to_timezone) {
        $from_tz = new DateTimeZone($from_timezone);
        $to_tz = new DateTimeZone($to_timezone);
        
        $date_obj = new DateTime($datetime, $from_tz);
        $date_obj->setTimezone($to_tz);
        
        return $date_obj->format('Y-m-d H:i:s');
    }
    
    /**
     * Get country list
     */
    public static function get_countries() {
        return [
            'FR' => 'France',
            'BE' => 'Belgique',
            'CH' => 'Suisse',
            'CA' => 'Canada',
            'MA' => 'Maroc',
            'TN' => 'Tunisie',
            'DZ' => 'Algérie',
            'SN' => 'Sénégal',
            'CI' => "Côte d'Ivoire",
            'MG' => 'Madagascar',
            'LU' => 'Luxembourg',
            'MC' => 'Monaco',
            'US' => 'États-Unis',
            'GB' => 'Royaume-Uni',
            'DE' => 'Allemagne',
            'ES' => 'Espagne',
            'IT' => 'Italie',
            'PT' => 'Portugal',
            'NL' => 'Pays-Bas'
        ];
    }
    
    /**
     * Get language list
     */
    public static function get_languages() {
        return [
            'fr' => 'Français',
            'en' => 'English',
            'es' => 'Español',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'pt' => 'Português',
            'nl' => 'Nederlands',
            'ar' => 'العربية'
        ];
    }
    
    /**
     * Get currency list
     */
    public static function get_currencies() {
        return [
            'EUR' => ['symbol' => '€', 'name' => 'Euro'],
            'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
            'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
            'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
            'CHF' => ['symbol' => 'Fr', 'name' => 'Swiss Franc'],
            'MAD' => ['symbol' => 'DH', 'name' => 'Moroccan Dirham'],
            'TND' => ['symbol' => 'TND', 'name' => 'Tunisian Dinar']
        ];
    }
    
    /**
     * Format currency amount
     */
    public static function format_currency($amount, $currency = 'EUR', $show_symbol = true) {
        $currencies = self::get_currencies();
        $currency_data = $currencies[$currency] ?? $currencies['EUR'];
        
        $formatted = number_format($amount, 2, ',', ' ');
        
        if ($show_symbol) {
            $formatted = $currency_data['symbol'] . $formatted;
        }
        
        return $formatted;
    }
    
    /**
     * Calculate percentage
     */
    public static function calculate_percentage($value, $total, $precision = 2) {
        if ($total == 0) {
            return 0;
        }
        
        return round(($value / $total) * 100, $precision);
    }
    
    /**
     * Calculate average
     */
    public static function calculate_average($values) {
        if (empty($values)) {
            return 0;
        }
        
        return array_sum($values) / count($values);
    }
    
    /**
     * Generate random color palette
     */
    public static function generate_color_palette($count = 5) {
        $colors = [];
        $hue_step = 360 / $count;
        
        for ($i = 0; $i < $count; $i++) {
            $hue = $i * $hue_step;
            $colors[] = "hsl({$hue}, 70%, 50%)";
        }
        
        return $colors;
    }
    
    /**
     * Log activity
     */
    public static function log_activity($action, $object_type, $object_id, $description = '', $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_activity_log';
        
        $user_id = $user_id ?: get_current_user_id();
        
        $data = [
            'user_id' => $user_id,
            'action' => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id' => intval($object_id),
            'description' => sanitize_textarea_field($description),
            'ip_address' => self::get_client_ip(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'created_at' => current_time('mysql')
        ];
        
        return $wpdb->insert($table, $data);
    }
    
    /**
     * Get client IP address
     */
    public static function get_client_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                
                // Handle multiple IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    /**
     * Check if current request is AJAX
     */
    public static function is_ajax_request() {
        return defined('DOING_AJAX') && DOING_AJAX;
    }
    
    /**
     * Check if current request is REST API
     */
    public static function is_rest_request() {
        return defined('REST_REQUEST') && REST_REQUEST;
    }
    
    /**
     * Get current page URL
     */
    public static function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Send JSON response with proper headers
     */
    public static function send_json_response($data, $status_code = 200) {
        status_header($status_code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Create nonce with expiration
     */
    public static function create_nonce_with_expiration($action, $expiry_hours = 24) {
        $nonce = wp_create_nonce($action);
        $expiry = time() + ($expiry_hours * 3600);
        
        set_transient('tbm_nonce_' . $nonce, $expiry, $expiry_hours * 3600);
        
        return $nonce;
    }
    
    /**
     * Verify nonce with expiration
     */
    public static function verify_nonce_with_expiration($nonce, $action) {
        // First check standard nonce
        if (!wp_verify_nonce($nonce, $action)) {
            return false;
        }
        
        // Check if nonce has expired
        $expiry = get_transient('tbm_nonce_' . $nonce);
        if ($expiry === false) {
            return false;
        }
        
        return time() <= $expiry;
    }
    
    /**
     * Cleanup expired nonces
     */
    public static function cleanup_expired_nonces() {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_value < %d",
            '_transient_tbm_nonce_%',
            time()
        ));
    }
    
    /**
     * Get plugin information
     */
    public static function get_plugin_info() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        return get_plugin_data(TBM_PLUGIN_FILE);
    }
    
    /**
     * Check if development mode
     */
    public static function is_development_mode() {
        return defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Debug log
     */
    public static function debug_log($message, $data = null) {
        if (!self::is_development_mode()) {
            return;
        }
        
        $log_message = '[TBM Debug] ' . $message;
        
        if ($data !== null) {
            $log_message .= ' | Data: ' . print_r($data, true);
        }
        
        error_log($log_message);
    }
    
    /**
     * Get memory usage
     */
    public static function get_memory_usage() {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }
    
    /**
     * Get system info for debugging
     */
    public static function get_system_info() {
        global $wpdb;
        
        return [
            'plugin_version' => TBM_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $wpdb->db_version(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_vars' => ini_get('max_input_vars'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'timezone' => wp_timezone_string(),
            'locale' => get_locale()
        ];
    }
}