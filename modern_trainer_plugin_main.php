<?php
/**
 * Plugin Name: Trainer Bootcamp Manager Pro
 * Plugin URI: https://yoursite.com/trainer-bootcamp-manager
 * Description: Plateforme moderne de gestion de formateurs et bootcamps avec dashboards avancés, API REST et interfaces utilisateur modernes.
 * Version: 2.0.0
 * Author: NGANDO
 * Author URI: https://yoursite.com
 * Text Domain: trainer-bootcamp-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package TrainerBootcampManager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Define plugin constants
define('TBM_VERSION', '2.0.0');
define('TBM_PLUGIN_FILE', __FILE__);
define('TBM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TBM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TBM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('TBM_ASSETS_URL', TBM_PLUGIN_URL . 'assets/');
define('TBM_INCLUDES_DIR', TBM_PLUGIN_DIR . 'includes/');
define('TBM_ADMIN_DIR', TBM_PLUGIN_DIR . 'admin/');
define('TBM_PUBLIC_DIR', TBM_PLUGIN_DIR . 'public/');
define('TBM_API_DIR', TBM_PLUGIN_DIR . 'api/');
define('TBM_DB_VERSION', '2.0.0');

/**
 * Main Plugin Class
 */
final class TrainerBootcampManager {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Plugin modules
     */
    public $admin;
    public $public;
    public $api;
    public $database;
    public $user_management;
    public $calendar;
    public $notifications;
    public $payments;
    public $analytics;
    
    /**
     * Get single instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        $this->init_modules();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(TBM_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(TBM_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(TBM_PLUGIN_FILE, ['TrainerBootcampManager', 'uninstall']);
        
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core includes
        require_once TBM_INCLUDES_DIR . 'class-tbm-autoloader.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-database.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-user-management.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-calendar.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-notifications.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-payments.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-analytics.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-utilities.php';
        require_once TBM_INCLUDES_DIR . 'class-tbm-security.php';
        
        // Admin includes
        require_once TBM_ADMIN_DIR . 'class-tbm-admin.php';
        require_once TBM_ADMIN_DIR . 'class-tbm-admin-dashboard.php';
        require_once TBM_ADMIN_DIR . 'class-tbm-admin-trainers.php';
        require_once TBM_ADMIN_DIR . 'class-tbm-admin-bootcamps.php';
        require_once TBM_ADMIN_DIR . 'class-tbm-admin-applications.php';
        require_once TBM_ADMIN_DIR . 'class-tbm-admin-settings.php';
        
        // Public includes
        require_once TBM_PUBLIC_DIR . 'class-tbm-public.php';
        require_once TBM_PUBLIC_DIR . 'class-tbm-shortcodes.php';
        require_once TBM_PUBLIC_DIR . 'class-tbm-ajax-handler.php';
        require_once TBM_PUBLIC_DIR . 'class-tbm-trainer-dashboard.php';
        
        // API includes
        require_once TBM_API_DIR . 'class-tbm-api.php';
        require_once TBM_API_DIR . 'class-tbm-rest-controller.php';
        require_once TBM_API_DIR . 'endpoints/class-tbm-trainers-endpoint.php';
        require_once TBM_API_DIR . 'endpoints/class-tbm-bootcamps-endpoint.php';
        require_once TBM_API_DIR . 'endpoints/class-tbm-applications-endpoint.php';
        require_once TBM_API_DIR . 'endpoints/class-tbm-calendar-endpoint.php';
    }
    
    /**
     * Initialize plugin modules
     */
    private function init_modules() {
        $this->database = new TBM_Database();
        $this->user_management = new TBM_User_Management();
        $this->calendar = new TBM_Calendar();
        $this->notifications = new TBM_Notifications();
        $this->payments = new TBM_Payments();
        $this->analytics = new TBM_Analytics();
        
        if (is_admin()) {
            $this->admin = new TBM_Admin();
        }
        
        if (!is_admin()) {
            $this->public = new TBM_Public();
        }
        
        $this->api = new TBM_API();
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Register post types
        $this->register_post_types();
        
        // Register taxonomies
        $this->register_taxonomies();
        
        // Register user roles
        $this->register_user_roles();
        
        // Initialize cron jobs
        $this->init_cron_jobs();
        
        do_action('tbm_init');
    }
    
    /**
     * Register custom post types
     */
    private function register_post_types() {
        // Trainers post type
        register_post_type('tbm_trainer', [
            'labels' => [
                'name' => __('Trainers', 'trainer-bootcamp-manager'),
                'singular_name' => __('Trainer', 'trainer-bootcamp-manager'),
                'add_new' => __('Add New Trainer', 'trainer-bootcamp-manager'),
                'add_new_item' => __('Add New Trainer', 'trainer-bootcamp-manager'),
                'edit_item' => __('Edit Trainer', 'trainer-bootcamp-manager'),
                'new_item' => __('New Trainer', 'trainer-bootcamp-manager'),
                'view_item' => __('View Trainer', 'trainer-bootcamp-manager'),
                'search_items' => __('Search Trainers', 'trainer-bootcamp-manager'),
                'not_found' => __('No trainers found', 'trainer-bootcamp-manager'),
                'not_found_in_trash' => __('No trainers found in trash', 'trainer-bootcamp-manager')
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'capability_type' => 'tbm_trainer',
            'map_meta_cap' => true,
            'rewrite' => ['slug' => 'trainer', 'with_front' => false],
            'has_archive' => true
        ]);
        
        // Bootcamps post type
        register_post_type('tbm_bootcamp', [
            'labels' => [
                'name' => __('Bootcamps', 'trainer-bootcamp-manager'),
                'singular_name' => __('Bootcamp', 'trainer-bootcamp-manager'),
                'add_new' => __('Add New Bootcamp', 'trainer-bootcamp-manager'),
                'add_new_item' => __('Add New Bootcamp', 'trainer-bootcamp-manager'),
                'edit_item' => __('Edit Bootcamp', 'trainer-bootcamp-manager'),
                'new_item' => __('New Bootcamp', 'trainer-bootcamp-manager'),
                'view_item' => __('View Bootcamp', 'trainer-bootcamp-manager'),
                'search_items' => __('Search Bootcamps', 'trainer-bootcamp-manager'),
                'not_found' => __('No bootcamps found', 'trainer-bootcamp-manager'),
                'not_found_in_trash' => __('No bootcamps found in trash', 'trainer-bootcamp-manager')
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'capability_type' => 'tbm_bootcamp',
            'map_meta_cap' => true,
            'rewrite' => ['slug' => 'bootcamp', 'with_front' => false],
            'has_archive' => true
        ]);
        
        // Applications post type
        register_post_type('tbm_application', [
            'labels' => [
                'name' => __('Applications', 'trainer-bootcamp-manager'),
                'singular_name' => __('Application', 'trainer-bootcamp-manager'),
                'add_new' => __('Add New Application', 'trainer-bootcamp-manager'),
                'add_new_item' => __('Add New Application', 'trainer-bootcamp-manager'),
                'edit_item' => __('Edit Application', 'trainer-bootcamp-manager'),
                'new_item' => __('New Application', 'trainer-bootcamp-manager'),
                'view_item' => __('View Application', 'trainer-bootcamp-manager'),
                'search_items' => __('Search Applications', 'trainer-bootcamp-manager'),
                'not_found' => __('No applications found', 'trainer-bootcamp-manager'),
                'not_found_in_trash' => __('No applications found in trash', 'trainer-bootcamp-manager')
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'custom-fields'],
            'capability_type' => 'tbm_application',
            'map_meta_cap' => true,
            'rewrite' => false,
            'has_archive' => false
        ]);
    }
    
    /**
     * Register taxonomies
     */
    private function register_taxonomies() {
        // Expertise taxonomy
        register_taxonomy('tbm_expertise', ['tbm_trainer'], [
            'labels' => [
                'name' => __('Expertise Areas', 'trainer-bootcamp-manager'),
                'singular_name' => __('Expertise Area', 'trainer-bootcamp-manager'),
                'search_items' => __('Search Expertise Areas', 'trainer-bootcamp-manager'),
                'all_items' => __('All Expertise Areas', 'trainer-bootcamp-manager'),
                'edit_item' => __('Edit Expertise Area', 'trainer-bootcamp-manager'),
                'update_item' => __('Update Expertise Area', 'trainer-bootcamp-manager'),
                'add_new_item' => __('Add New Expertise Area', 'trainer-bootcamp-manager'),
                'new_item_name' => __('New Expertise Area Name', 'trainer-bootcamp-manager'),
                'menu_name' => __('Expertise Areas', 'trainer-bootcamp-manager')
            ],
            'hierarchical' => true,
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'expertise']
        ]);
        
        // Skills taxonomy
        register_taxonomy('tbm_skills', ['tbm_trainer'], [
            'labels' => [
                'name' => __('Skills', 'trainer-bootcamp-manager'),
                'singular_name' => __('Skill', 'trainer-bootcamp-manager'),
                'search_items' => __('Search Skills', 'trainer-bootcamp-manager'),
                'all_items' => __('All Skills', 'trainer-bootcamp-manager'),
                'edit_item' => __('Edit Skill', 'trainer-bootcamp-manager'),
                'update_item' => __('Update Skill', 'trainer-bootcamp-manager'),
                'add_new_item' => __('Add New Skill', 'trainer-bootcamp-manager'),
                'new_item_name' => __('New Skill Name', 'trainer-bootcamp-manager'),
                'menu_name' => __('Skills', 'trainer-bootcamp-manager')
            ],
            'hierarchical' => false,
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'skill']
        ]);
    }
    
    /**
     * Register user roles and capabilities
     */
    private function register_user_roles() {
        // Trainer role
        add_role('tbm_trainer', __('Trainer', 'trainer-bootcamp-manager'), [
            'read' => true,
            'edit_tbm_trainer' => true,
            'edit_own_tbm_trainer' => true,
            'read_tbm_trainer' => true,
            'upload_files' => true
        ]);
        
        // Bootcamp Manager role
        add_role('tbm_bootcamp_manager', __('Bootcamp Manager', 'trainer-bootcamp-manager'), [
            'read' => true,
            'edit_tbm_trainer' => true,
            'edit_tbm_trainers' => true,
            'edit_others_tbm_trainers' => true,
            'edit_published_tbm_trainers' => true,
            'read_tbm_trainer' => true,
            'read_private_tbm_trainers' => true,
            'delete_tbm_trainer' => true,
            'delete_tbm_trainers' => true,
            'delete_others_tbm_trainers' => true,
            'delete_published_tbm_trainers' => true,
            'delete_private_tbm_trainers' => true,
            'publish_tbm_trainers' => true,
            'edit_tbm_bootcamp' => true,
            'edit_tbm_bootcamps' => true,
            'edit_others_tbm_bootcamps' => true,
            'edit_published_tbm_bootcamps' => true,
            'read_tbm_bootcamp' => true,
            'read_private_tbm_bootcamps' => true,
            'delete_tbm_bootcamp' => true,
            'delete_tbm_bootcamps' => true,
            'delete_others_tbm_bootcamps' => true,
            'delete_published_tbm_bootcamps' => true,
            'delete_private_tbm_bootcamps' => true,
            'publish_tbm_bootcamps' => true,
            'upload_files' => true
        ]);
        
        // Add capabilities to administrator
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $capabilities = [
                'edit_tbm_trainer', 'edit_tbm_trainers', 'edit_others_tbm_trainers',
                'edit_published_tbm_trainers', 'read_tbm_trainer', 'read_private_tbm_trainers',
                'delete_tbm_trainer', 'delete_tbm_trainers', 'delete_others_tbm_trainers',
                'delete_published_tbm_trainers', 'delete_private_tbm_trainers', 'publish_tbm_trainers',
                'edit_tbm_bootcamp', 'edit_tbm_bootcamps', 'edit_others_tbm_bootcamps',
                'edit_published_tbm_bootcamps', 'read_tbm_bootcamp', 'read_private_tbm_bootcamps',
                'delete_tbm_bootcamp', 'delete_tbm_bootcamps', 'delete_others_tbm_bootcamps',
                'delete_published_tbm_bootcamps', 'delete_private_tbm_bootcamps', 'publish_tbm_bootcamps',
                'edit_tbm_application', 'edit_tbm_applications', 'edit_others_tbm_applications',
                'read_tbm_application', 'read_private_tbm_applications', 'delete_tbm_application',
                'delete_tbm_applications', 'delete_others_tbm_applications', 'publish_tbm_applications'
            ];
            
            foreach ($capabilities as $cap) {
                $admin_role->add_cap($cap);
            }
        }
    }
    
    /**
     * Initialize cron jobs
     */
    private function init_cron_jobs() {
        if (!wp_next_scheduled('tbm_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tbm_daily_cleanup');
        }
        
        if (!wp_next_scheduled('tbm_send_notifications')) {
            wp_schedule_event(time(), 'hourly', 'tbm_send_notifications');
        }
        
        add_action('tbm_daily_cleanup', [$this, 'daily_cleanup']);
        add_action('tbm_send_notifications', [$this, 'send_scheduled_notifications']);
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'trainer-bootcamp-manager',
            false,
            dirname(TBM_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    /**
     * Enqueue public assets
     */
    public function enqueue_public_assets() {
        // Styles
        wp_enqueue_style(
            'tbm-public-style',
            TBM_ASSETS_URL . 'css/public.css',
            [],
            TBM_VERSION
        );
        
        // Scripts
        wp_enqueue_script(
            'tbm-public-script',
            TBM_ASSETS_URL . 'js/public.js',
            ['jquery'],
            TBM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('tbm-public-script', 'tbmAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resturl' => rest_url('tbm/v1/'),
            'nonce' => wp_create_nonce('tbm_nonce'),
            'strings' => [
                'loading' => __('Loading...', 'trainer-bootcamp-manager'),
                'error' => __('An error occurred. Please try again.', 'trainer-bootcamp-manager'),
                'success' => __('Operation completed successfully.', 'trainer-bootcamp-manager')
            ]
        ]);
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'tbm') === false) {
            return;
        }
        
        // Admin styles
        wp_enqueue_style(
            'tbm-admin-style',
            TBM_ASSETS_URL . 'css/admin.css',
            [],
            TBM_VERSION
        );
        
        // React/Vue build for modern dashboard
        wp_enqueue_script(
            'tbm-admin-app',
            TBM_ASSETS_URL . 'js/admin-app.js',
            ['wp-element', 'wp-components', 'wp-i18n'],
            TBM_VERSION,
            true
        );
        
        // Localize admin script
        wp_localize_script('tbm-admin-app', 'tbmAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resturl' => rest_url('tbm/v1/'),
            'nonce' => wp_create_nonce('tbm_admin_nonce'),
            'currentUser' => wp_get_current_user()->ID,
            'capabilities' => $this->get_current_user_capabilities(),
            'settings' => $this->get_plugin_settings()
        ]);
    }
    
    /**
     * Get current user capabilities for the plugin
     */
    private function get_current_user_capabilities() {
        $user = wp_get_current_user();
        $capabilities = [];
        
        $plugin_caps = [
            'edit_tbm_trainers', 'edit_tbm_bootcamps', 'edit_tbm_applications',
            'delete_tbm_trainers', 'delete_tbm_bootcamps', 'delete_tbm_applications',
            'publish_tbm_trainers', 'publish_tbm_bootcamps', 'publish_tbm_applications'
        ];
        
        foreach ($plugin_caps as $cap) {
            $capabilities[$cap] = user_can($user, $cap);
        }
        
        return $capabilities;
    }
    
    /**
     * Get plugin settings
     */
    private function get_plugin_settings() {
        return [
            'dateFormat' => get_option('date_format'),
            'timeFormat' => get_option('time_format'),
            'currency' => get_option('tbm_currency', 'EUR'),
            'timezone' => get_option('timezone_string'),
            'language' => get_locale(),
            'features' => get_option('tbm_enabled_features', [])
        ];
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->database->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Create upload directories
        $this->create_upload_directories();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set activation flag
        update_option('tbm_activated', true);
        update_option('tbm_activation_time', current_time('timestamp'));
        
        do_action('tbm_activated');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('tbm_daily_cleanup');
        wp_clear_scheduled_hook('tbm_send_notifications');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        do_action('tbm_deactivated');
    }
    
    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        // Delete options
        delete_option('tbm_version');
        delete_option('tbm_db_version');
        delete_option('tbm_settings');
        delete_option('tbm_activated');
        delete_option('tbm_activation_time');
        
        // Drop database tables
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'tbm_trainers',
            $wpdb->prefix . 'tbm_applications',
            $wpdb->prefix . 'tbm_bootcamps',
            $wpdb->prefix . 'tbm_sessions',
            $wpdb->prefix . 'tbm_availabilities',
            $wpdb->prefix . 'tbm_contracts',
            $wpdb->prefix . 'tbm_payments',
            $wpdb->prefix . 'tbm_notifications',
            $wpdb->prefix . 'tbm_messages',
            $wpdb->prefix . 'tbm_analytics'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        // Remove user roles
        remove_role('tbm_trainer');
        remove_role('tbm_bootcamp_manager');
        
        // Remove capabilities from administrator
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $capabilities = [
                'edit_tbm_trainer', 'edit_tbm_trainers', 'edit_others_tbm_trainers',
                'edit_published_tbm_trainers', 'read_tbm_trainer', 'read_private_tbm_trainers',
                'delete_tbm_trainer', 'delete_tbm_trainers', 'delete_others_tbm_trainers',
                'delete_published_tbm_trainers', 'delete_private_tbm_trainers', 'publish_tbm_trainers'
            ];
            
            foreach ($capabilities as $cap) {
                $admin_role->remove_cap($cap);
            }
        }
        
        do_action('tbm_uninstalled');
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = [
            'tbm_version' => TBM_VERSION,
            'tbm_db_version' => TBM_DB_VERSION,
            'tbm_currency' => 'EUR',
            'tbm_date_format' => 'Y-m-d',
            'tbm_time_format' => 'H:i',
            'tbm_email_notifications' => true,
            'tbm_sms_notifications' => false,
            'tbm_auto_approve_trainers' => false,
            'tbm_require_trainer_approval' => true,
            'tbm_max_file_size' => 10485760, // 10MB
            'tbm_allowed_file_types' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
            'tbm_enabled_features' => [
                'trainer_applications',
                'bootcamp_management',
                'calendar_integration',
                'payment_processing',
                'email_notifications',
                'analytics_reporting'
            ]
        ];
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                update_option($option, $value);
            }
        }
    }
    
    /**
     * Create upload directories
     */
    private function create_upload_directories() {
        $upload_dir = wp_upload_dir();
        $tbm_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager';
        
        $dirs = [
            $tbm_dir,
            $tbm_dir . '/trainers',
            $tbm_dir . '/applications',
            $tbm_dir . '/contracts',
            $tbm_dir . '/certificates'
        ];
        
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
                
                // Create .htaccess for security
                $htaccess_content = "Options -Indexes\n";
                $htaccess_content .= "deny from all\n";
                file_put_contents($dir . '/.htaccess', $htaccess_content);
            }
        }
    }
    
    /**
     * Daily cleanup task
     */
    public function daily_cleanup() {
        // Clean expired sessions
        $this->database->clean_expired_sessions();
        
        // Clean old notifications
        $this->database->clean_old_notifications();
        
        // Clean temporary files
        $this->clean_temporary_files();
        
        do_action('tbm_daily_cleanup');
    }
    
    /**
     * Send scheduled notifications
     */
    public function send_scheduled_notifications() {
        $this->notifications->send_scheduled_notifications();
        do_action('tbm_notifications_sent');
    }
    
    /**
     * Clean temporary files
     */
    private function clean_temporary_files() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager/temp';
        
        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '/*');
            $now = time();
            
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 86400) { // 24 hours
                    unlink($file);
                }
            }
        }
    }
    
    /**
     * Get plugin version
     */
    public function get_version() {
        return TBM_VERSION;
    }
    
    /**
     * Check if feature is enabled
     */
    public function is_feature_enabled($feature) {
        $enabled_features = get_option('tbm_enabled_features', []);
        return in_array($feature, $enabled_features);
    }
}

/**
 * Initialize the plugin
 */
function tbm() {
    return TrainerBootcampManager::instance();
}

// Start the plugin
tbm();