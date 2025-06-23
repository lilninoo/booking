<?php
/**
 * Database Management Class
 * 
 * Handles all database operations for the Trainer Bootcamp Manager plugin
 * 
 * @package TrainerBootcampManager
 * @subpackage Database
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Database {
    
    /**
     * Database version
     */
    const DB_VERSION = '2.0.0';
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Table names
     */
    private $tables = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->init_table_names();
    }
    
    /**
     * Initialize table names
     */
    private function init_table_names() {
        $this->tables = [
            'trainers' => $this->wpdb->prefix . 'tbm_trainers',
            'applications' => $this->wpdb->prefix . 'tbm_applications',
            'bootcamps' => $this->wpdb->prefix . 'tbm_bootcamps',
            'sessions' => $this->wpdb->prefix . 'tbm_sessions',
            'availabilities' => $this->wpdb->prefix . 'tbm_availabilities',
            'contracts' => $this->wpdb->prefix . 'tbm_contracts',
            'payments' => $this->wpdb->prefix . 'tbm_payments',
            'notifications' => $this->wpdb->prefix . 'tbm_notifications',
            'messages' => $this->wpdb->prefix . 'tbm_messages',
            'analytics' => $this->wpdb->prefix . 'tbm_analytics',
            'documents' => $this->wpdb->prefix . 'tbm_documents',
            'ratings' => $this->wpdb->prefix . 'tbm_ratings',
            'activity_log' => $this->wpdb->prefix . 'tbm_activity_log'
        ];
    }
    
    /**
     * Create all database tables
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // Create trainers table
        $this->create_trainers_table($charset_collate);
        
        // Create applications table
        $this->create_applications_table($charset_collate);
        
        // Create bootcamps table
        $this->create_bootcamps_table($charset_collate);
        
        // Create sessions table
        $this->create_sessions_table($charset_collate);
        
        // Create availabilities table
        $this->create_availabilities_table($charset_collate);
        
        // Create contracts table
        $this->create_contracts_table($charset_collate);
        
        // Create payments table
        $this->create_payments_table($charset_collate);
        
        // Create notifications table
        $this->create_notifications_table($charset_collate);
        
        // Create messages table
        $this->create_messages_table($charset_collate);
        
        // Create analytics table
        $this->create_analytics_table($charset_collate);
        
        // Create documents table
        $this->create_documents_table($charset_collate);
        
        // Create ratings table
        $this->create_ratings_table($charset_collate);
        
        // Create activity log table
        $this->create_activity_log_table($charset_collate);
        
        // Update database version
        update_option('tbm_db_version', self::DB_VERSION);
        
        do_action('tbm_database_created');
    }
    
    /**
     * Create trainers table
     */
    private function create_trainers_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['trainers']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            status enum('pending', 'approved', 'active', 'suspended', 'rejected') DEFAULT 'pending',
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            expertise_areas text DEFAULT NULL,
            skills text DEFAULT NULL,
            bio text DEFAULT NULL,
            experience_years int(11) DEFAULT 0,
            hourly_rate decimal(10,2) DEFAULT NULL,
            currency varchar(3) DEFAULT 'EUR',
            languages text DEFAULT NULL,
            certifications text DEFAULT NULL,
            portfolio_url varchar(255) DEFAULT NULL,
            linkedin_url varchar(255) DEFAULT NULL,
            website_url varchar(255) DEFAULT NULL,
            availability_status enum('available', 'busy', 'unavailable') DEFAULT 'available',
            rating_average decimal(3,2) DEFAULT 0.00,
            rating_count int(11) DEFAULT 0,
            total_sessions int(11) DEFAULT 0,
            total_earnings decimal(10,2) DEFAULT 0.00,
            profile_completion int(3) DEFAULT 0,
            verification_status enum('unverified', 'pending', 'verified') DEFAULT 'unverified',
            featured tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY email (email),
            KEY status (status),
            KEY country_city (country, city),
            KEY availability_status (availability_status),
            KEY rating_average (rating_average),
            KEY featured (featured),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create applications table
     */
    private function create_applications_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['applications']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            application_number varchar(50) NOT NULL,
            status enum('submitted', 'reviewing', 'interview_scheduled', 'approved', 'rejected', 'withdrawn') DEFAULT 'submitted',
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            expertise_areas text DEFAULT NULL,
            skills text DEFAULT NULL,
            experience_years int(11) DEFAULT 0,
            motivation text DEFAULT NULL,
            portfolio_url varchar(255) DEFAULT NULL,
            linkedin_url varchar(255) DEFAULT NULL,
            cv_file_path varchar(255) DEFAULT NULL,
            cover_letter_file_path varchar(255) DEFAULT NULL,
            references text DEFAULT NULL,
            availability text DEFAULT NULL,
            expected_rate decimal(10,2) DEFAULT NULL,
            currency varchar(3) DEFAULT 'EUR',
            reviewer_id bigint(20) unsigned DEFAULT NULL,
            reviewer_notes text DEFAULT NULL,
            interview_date datetime DEFAULT NULL,
            interview_notes text DEFAULT NULL,
            rejection_reason text DEFAULT NULL,
            submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at datetime DEFAULT NULL,
            decision_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY application_number (application_number),
            KEY user_id (user_id),
            KEY status (status),
            KEY email (email),
            KEY country_city (country, city),
            KEY reviewer_id (reviewer_id),
            KEY submitted_at (submitted_at),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create bootcamps table
     */
    private function create_bootcamps_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['bootcamps']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            category varchar(100) DEFAULT NULL,
            skill_level enum('beginner', 'intermediate', 'advanced', 'expert') DEFAULT 'beginner',
            duration_weeks int(11) DEFAULT NULL,
            max_participants int(11) DEFAULT NULL,
            current_participants int(11) DEFAULT 0,
            status enum('draft', 'scheduled', 'active', 'completed', 'cancelled') DEFAULT 'draft',
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            format enum('in-person', 'online', 'hybrid') DEFAULT 'online',
            price decimal(10,2) DEFAULT NULL,
            currency varchar(3) DEFAULT 'EUR',
            requirements text DEFAULT NULL,
            objectives text DEFAULT NULL,
            syllabus text DEFAULT NULL,
            materials text DEFAULT NULL,
            certification_provided tinyint(1) DEFAULT 0,
            featured tinyint(1) DEFAULT 0,
            manager_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY title (title),
            KEY category (category),
            KEY status (status),
            KEY start_date (start_date),
            KEY end_date (end_date),
            KEY skill_level (skill_level),
            KEY format (format),
            KEY featured (featured),
            KEY manager_id (manager_id),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create sessions table
     */
    private function create_sessions_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['sessions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bootcamp_id bigint(20) unsigned NOT NULL,
            trainer_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            session_number int(11) DEFAULT NULL,
            status enum('scheduled', 'in_progress', 'completed', 'cancelled', 'rescheduled') DEFAULT 'scheduled',
            start_datetime datetime NOT NULL,
            end_datetime datetime NOT NULL,
            duration_minutes int(11) DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            format enum('in-person', 'online', 'hybrid') DEFAULT 'online',
            meeting_url varchar(255) DEFAULT NULL,
            meeting_password varchar(100) DEFAULT NULL,
            materials text DEFAULT NULL,
            homework text DEFAULT NULL,
            notes text DEFAULT NULL,
            attendance_count int(11) DEFAULT 0,
            rating_average decimal(3,2) DEFAULT 0.00,
            rating_count int(11) DEFAULT 0,
            recorded tinyint(1) DEFAULT 0,
            recording_url varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY bootcamp_id (bootcamp_id),
            KEY trainer_id (trainer_id),
            KEY status (status),
            KEY start_datetime (start_datetime),
            KEY end_datetime (end_datetime),
            KEY session_number (session_number),
            FOREIGN KEY (bootcamp_id) REFERENCES {$this->tables['bootcamps']} (id) ON DELETE CASCADE,
            FOREIGN KEY (trainer_id) REFERENCES {$this->tables['trainers']} (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create availabilities table
     */
    private function create_availabilities_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['availabilities']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) unsigned NOT NULL,
            type enum('regular', 'exception', 'vacation', 'blocked') DEFAULT 'regular',
            day_of_week tinyint(1) DEFAULT NULL,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            is_available tinyint(1) DEFAULT 1,
            reason varchar(255) DEFAULT NULL,
            recurring tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY type (type),
            KEY day_of_week (day_of_week),
            KEY start_date (start_date),
            KEY end_date (end_date),
            KEY is_available (is_available),
            FOREIGN KEY (trainer_id) REFERENCES {$this->tables['trainers']} (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create contracts table
     */
    private function create_contracts_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['contracts']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            contract_number varchar(50) NOT NULL,
            trainer_id bigint(20) unsigned NOT NULL,
            bootcamp_id bigint(20) unsigned DEFAULT NULL,
            type enum('bootcamp', 'hourly', 'project', 'retainer') DEFAULT 'bootcamp',
            status enum('draft', 'sent', 'signed', 'active', 'completed', 'terminated') DEFAULT 'draft',
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            total_amount decimal(10,2) DEFAULT NULL,
            hourly_rate decimal(10,2) DEFAULT NULL,
            currency varchar(3) DEFAULT 'EUR',
            payment_terms text DEFAULT NULL,
            terms_conditions text DEFAULT NULL,
            file_path varchar(255) DEFAULT NULL,
            signed_date datetime DEFAULT NULL,
            signed_by_trainer tinyint(1) DEFAULT 0,
            signed_by_company tinyint(1) DEFAULT 0,
            trainer_signature_path varchar(255) DEFAULT NULL,
            company_signature_path varchar(255) DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY contract_number (contract_number),
            KEY trainer_id (trainer_id),
            KEY bootcamp_id (bootcamp_id),
            KEY type (type),
            KEY status (status),
            KEY start_date (start_date),
            KEY created_by (created_by),
            FOREIGN KEY (trainer_id) REFERENCES {$this->tables['trainers']} (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create payments table
     */
    private function create_payments_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['payments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id varchar(100) NOT NULL,
            trainer_id bigint(20) unsigned NOT NULL,
            contract_id bigint(20) unsigned DEFAULT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) DEFAULT 'EUR',
            type enum('session', 'bonus', 'penalty', 'advance', 'final') DEFAULT 'session',
            status enum('pending', 'processing', 'completed', 'failed', 'refunded') DEFAULT 'pending',
            payment_method enum('bank_transfer', 'paypal', 'stripe', 'check', 'cash') DEFAULT 'bank_transfer',
            payment_gateway varchar(50) DEFAULT NULL,
            gateway_payment_id varchar(100) DEFAULT NULL,
            gateway_fee decimal(10,2) DEFAULT 0.00,
            net_amount decimal(10,2) DEFAULT NULL,
            description text DEFAULT NULL,
            due_date date DEFAULT NULL,
            paid_date datetime DEFAULT NULL,
            invoice_number varchar(50) DEFAULT NULL,
            invoice_path varchar(255) DEFAULT NULL,
            receipt_path varchar(255) DEFAULT NULL,
            notes text DEFAULT NULL,
            processed_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY payment_id (payment_id),
            KEY trainer_id (trainer_id),
            KEY contract_id (contract_id),
            KEY session_id (session_id),
            KEY status (status),
            KEY type (type),
            KEY due_date (due_date),
            KEY paid_date (paid_date),
            KEY invoice_number (invoice_number),
            FOREIGN KEY (trainer_id) REFERENCES {$this->tables['trainers']} (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create notifications table
     */
    private function create_notifications_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['notifications']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            action_url varchar(255) DEFAULT NULL,
            action_text varchar(100) DEFAULT NULL,
            priority enum('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            channel enum('in_app', 'email', 'sms', 'push') DEFAULT 'in_app',
            status enum('pending', 'sent', 'read', 'failed') DEFAULT 'pending',
            scheduled_at datetime DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            read_at datetime DEFAULT NULL,
            data json DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY status (status),
            KEY priority (priority),
            KEY channel (channel),
            KEY scheduled_at (scheduled_at),
            KEY sent_at (sent_at),
            KEY read_at (read_at),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create messages table
     */
    private function create_messages_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['messages']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id varchar(100) NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            subject varchar(255) DEFAULT NULL,
            message text NOT NULL,
            message_type enum('text', 'file', 'image', 'system') DEFAULT 'text',
            file_path varchar(255) DEFAULT NULL,
            file_name varchar(255) DEFAULT NULL,
            file_size int(11) DEFAULT NULL,
            status enum('sent', 'delivered', 'read', 'failed') DEFAULT 'sent',
            read_at datetime DEFAULT NULL,
            reply_to bigint(20) unsigned DEFAULT NULL,
            priority enum('low', 'normal', 'high') DEFAULT 'normal',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY sender_id (sender_id),
            KEY recipient_id (recipient_id),
            KEY status (status),
            KEY read_at (read_at),
            KEY reply_to (reply_to),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create analytics table
     */
    private function create_analytics_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['analytics']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            metric_name varchar(100) NOT NULL,
            metric_value decimal(15,4) NOT NULL,
            metric_date date NOT NULL,
            dimension_1 varchar(100) DEFAULT NULL,
            dimension_2 varchar(100) DEFAULT NULL,
            dimension_3 varchar(100) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            trainer_id bigint(20) unsigned DEFAULT NULL,
            bootcamp_id bigint(20) unsigned DEFAULT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            metadata json DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY metric_name (metric_name),
            KEY metric_date (metric_date),
            KEY dimension_1 (dimension_1),
            KEY dimension_2 (dimension_2),
            KEY user_id (user_id),
            KEY trainer_id (trainer_id),
            KEY bootcamp_id (bootcamp_id),
            KEY session_id (session_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create documents table
     */
    private function create_documents_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['documents']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(255) NOT NULL,
            file_type varchar(50) NOT NULL,
            file_size int(11) NOT NULL,
            mime_type varchar(100) NOT NULL,
            document_type enum('cv', 'certificate', 'portfolio', 'contract', 'invoice', 'other') DEFAULT 'other',
            owner_id bigint(20) unsigned NOT NULL,
            owner_type enum('trainer', 'admin', 'system') DEFAULT 'trainer',
            trainer_id bigint(20) unsigned DEFAULT NULL,
            bootcamp_id bigint(20) unsigned DEFAULT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            contract_id bigint(20) unsigned DEFAULT NULL,
            is_private tinyint(1) DEFAULT 1,
            is_verified tinyint(1) DEFAULT 0,
            verified_by bigint(20) unsigned DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            download_count int(11) DEFAULT 0,
            description text DEFAULT NULL,
            tags text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY title (title),
            KEY file_name (file_name),
            KEY document_type (document_type),
            KEY owner_id (owner_id),
            KEY owner_type (owner_type),
            KEY trainer_id (trainer_id),
            KEY bootcamp_id (bootcamp_id),
            KEY is_private (is_private),
            KEY is_verified (is_verified),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create ratings table
     */
    private function create_ratings_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['ratings']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) unsigned NOT NULL,
            rater_id bigint(20) unsigned NOT NULL,
            rater_type enum('student', 'admin', 'peer') DEFAULT 'student',
            session_id bigint(20) unsigned DEFAULT NULL,
            bootcamp_id bigint(20) unsigned DEFAULT NULL,
            rating decimal(3,2) NOT NULL,
            max_rating decimal(3,2) DEFAULT 5.00,
            review text DEFAULT NULL,
            criteria_ratings json DEFAULT NULL,
            is_public tinyint(1) DEFAULT 1,
            is_verified tinyint(1) DEFAULT 0,
            helpful_count int(11) DEFAULT 0,
            reported_count int(11) DEFAULT 0,
            status enum('active', 'hidden', 'removed') DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY rater_id (rater_id),
            KEY session_id (session_id),
            KEY bootcamp_id (bootcamp_id),
            KEY rating (rating),
            KEY is_public (is_public),
            KEY status (status),
            KEY created_at (created_at),
            FOREIGN KEY (trainer_id) REFERENCES {$this->tables['trainers']} (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Create activity log table
     */
    private function create_activity_log_table($charset_collate) {
        $sql = "CREATE TABLE {$this->tables['activity_log']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            description text DEFAULT NULL,
            old_values json DEFAULT NULL,
            new_values json DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            severity enum('info', 'warning', 'error', 'critical') DEFAULT 'info',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY object_id (object_id),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Get table name
     */
    public function get_table_name($table) {
        return isset($this->tables[$table]) ? $this->tables[$table] : null;
    }
    
    /**
     * Clean expired sessions
     */
    public function clean_expired_sessions() {
        $table = $this->tables['sessions'];
        $expired_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $this->wpdb->delete(
            $table,
            [
                'status' => 'completed',
                'end_datetime <' => $expired_date
            ]
        );
    }
    
    /**
     * Clean old notifications
     */
    public function clean_old_notifications() {
        $table = $this->tables['notifications'];
        $old_date = date('Y-m-d H:i:s', strtotime('-90 days'));
        
        $this->wpdb->delete(
            $table,
            [
                'status' => 'read',
                'read_at <' => $old_date
            ]
        );
    }
    
    /**
     * Get database version
     */
    public function get_version() {
        return get_option('tbm_db_version', '0.0.0');
    }
    
    /**
     * Check if database needs update
     */
    public function needs_update() {
        return version_compare($this->get_version(), self::DB_VERSION, '<');
    }
    
    /**
     * Update database if needed
     */
    public function maybe_update() {
        if ($this->needs_update()) {
            $this->create_tables();
            return true;
        }
        return false;
    }
}