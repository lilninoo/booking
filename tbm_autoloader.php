<?php
/**
 * Autoloader Class
 * 
 * Handles automatic loading of TBM classes
 * 
 * @package TrainerBootcampManager
 * @subpackage Core
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Autoloader {
    
    /**
     * Class map for faster loading
     */
    private static $class_map = [];
    
    /**
     * Directories to search for classes
     */
    private static $directories = [];
    
    /**
     * Initialize autoloader
     */
    public static function init() {
        // Register autoloader
        spl_autoload_register([__CLASS__, 'load_class']);
        
        // Set up directories
        self::setup_directories();
        
        // Build class map
        self::build_class_map();
    }
    
    /**
     * Setup search directories
     */
    private static function setup_directories() {
        self::$directories = [
            TBM_INCLUDES_DIR,
            TBM_ADMIN_DIR,
            TBM_PUBLIC_DIR,
            TBM_API_DIR,
            TBM_API_DIR . 'endpoints/',
            TBM_INCLUDES_DIR . 'integrations/',
            TBM_INCLUDES_DIR . 'widgets/',
            TBM_INCLUDES_DIR . 'blocks/'
        ];
    }
    
    /**
     * Build class map for performance
     */
    private static function build_class_map() {
        $cache_key = 'tbm_class_map_' . md5(TBM_VERSION);
        $cached_map = wp_cache_get($cache_key, 'tbm_core');
        
        if ($cached_map !== false) {
            self::$class_map = $cached_map;
            return;
        }
        
        foreach (self::$directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            
            $files = glob($directory . '*.php');
            foreach ($files as $file) {
                $class_name = self::get_class_name_from_file($file);
                if ($class_name) {
                    self::$class_map[$class_name] = $file;
                }
            }
        }
        
        // Cache for 1 hour
        wp_cache_set($cache_key, self::$class_map, 'tbm_core', 3600);
    }
    
    /**
     * Extract class name from file path
     */
    private static function get_class_name_from_file($file) {
        $filename = basename($file, '.php');
        
        // Convert filename to class name
        if (strpos($filename, 'class-') === 0) {
            // Remove 'class-' prefix and convert hyphens to underscores
            $class_name = str_replace('-', '_', substr($filename, 6));
            return strtoupper($class_name);
        }
        
        return null;
    }
    
    /**
     * Load class file
     */
    public static function load_class($class_name) {
        // Only handle TBM classes
        if (strpos($class_name, 'TBM_') !== 0) {
            return false;
        }
        
        // Check class map first
        if (isset(self::$class_map[$class_name])) {
            require_once self::$class_map[$class_name];
            return true;
        }
        
        // Convert class name to filename
        $filename = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';
        
        // Search in directories
        foreach (self::$directories as $directory) {
            $file_path = $directory . $filename;
            if (file_exists($file_path)) {
                require_once $file_path;
                
                // Add to class map for next time
                self::$class_map[$class_name] = $file_path;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Register additional directory
     */
    public static function add_directory($directory) {
        if (is_dir($directory) && !in_array($directory, self::$directories)) {
            self::$directories[] = trailingslashit($directory);
            
            // Rebuild class map
            self::build_class_map();
        }
    }
    
    /**
     * Get all registered classes
     */
    public static function get_registered_classes() {
        return array_keys(self::$class_map);
    }
    
    /**
     * Clear class map cache
     */
    public static function clear_cache() {
        wp_cache_delete('tbm_class_map_' . md5(TBM_VERSION), 'tbm_core');
        self::$class_map = [];
        self::build_class_map();
    }
}