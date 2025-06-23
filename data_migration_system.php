<?php
/**
 * Data Migration System
 * 
 * Handles migration from old Custom Booking Plugin to new Trainer Bootcamp Manager
 * 
 * @package TrainerBootcampManager
 * @subpackage Migration
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Migration {
    
    /**
     * Migration version
     */
    const MIGRATION_VERSION = '2.0.0';
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Migration log
     */
    private $log = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        add_action('admin_init', [$this, 'maybe_run_migration']);
        add_action('wp_ajax_tbm_run_migration', [$this, 'ajax_run_migration']);
        add_action('admin_notices', [$this, 'show_migration_notice']);
    }
    
    /**
     * Check if migration is needed
     */
    public function needs_migration() {
        $migrated_version = get_option('tbm_migration_version', '0.0.0');
        $old_plugin_active = $this->is_old_plugin_data_present();
        
        return $old_plugin_active && version_compare($migrated_version, self::MIGRATION_VERSION, '<');
    }
    
    /**
     * Check if old plugin data is present
     */
    private function is_old_plugin_data_present() {
        // Check for old post types
        $trainer_posts = get_posts([
            'post_type' => 'trainer',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_migrated_to_tbm',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        return !empty($trainer_posts);
    }
    
    /**
     * Maybe run migration automatically
     */
    public function maybe_run_migration() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['tbm_auto_migrate']) && $_GET['tbm_auto_migrate'] === '1') {
            $this->run_migration();
            wp_redirect(admin_url('admin.php?page=tbm-trainers&migration=success'));
            exit;
        }
    }
    
    /**
     * AJAX handler for migration
     */
    public function ajax_run_migration() {
        check_ajax_referer('tbm_migration_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->run_migration();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Show migration notice
     */
    public function show_migration_notice() {
        if (!current_user_can('manage_options') || !$this->needs_migration()) {
            return;
        }
        
        if (isset($_GET['migration']) && $_GET['migration'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Migration réussie !</strong> Toutes vos données ont été migrées vers le nouveau système.</p>';
            echo '</div>';
            return;
        }
        
        ?>
        <div class="notice notice-warning">
            <p><strong>Migration requise :</strong> Des données de l'ancien plugin ont été détectées. 
            <a href="<?php echo admin_url('admin.php?page=tbm-migration'); ?>" class="button button-primary">
                Lancer la migration
            </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Run the complete migration process
     */
    public function run_migration() {
        $this->log('=== DÉBUT DE LA MIGRATION ===');
        $start_time = microtime(true);
        
        try {
            // Step 1: Migrate trainers
            $trainers_result = $this->migrate_trainers();
            
            // Step 2: Migrate applications (if any)
            $applications_result = $this->migrate_applications();
            
            // Step 3: Migrate bootcamps/appointments
            $bootcamps_result = $this->migrate_bootcamps();
            
            // Step 4: Migrate user roles
            $roles_result = $this->migrate_user_roles();
            
            // Step 5: Migrate settings
            $settings_result = $this->migrate_settings();
            
            // Step 6: Clean up old data (optional)
            if (get_option('tbm_migration_cleanup', false)) {
                $cleanup_result = $this->cleanup_old_data();
            }
            
            $end_time = microtime(true);
            $duration = round($end_time - start_time, 2);
            
            // Update migration version
            update_option('tbm_migration_version', self::MIGRATION_VERSION);
            update_option('tbm_migration_date', current_time('mysql'));
            update_option('tbm_migration_log', $this->log);
            
            $this->log("=== MIGRATION TERMINÉE EN {$duration}s ===");
            
            return [
                'success' => true,
                'message' => 'Migration terminée avec succès',
                'duration' => $duration,
                'log' => $this->log,
                'stats' => [
                    'trainers' => $trainers_result,
                    'applications' => $applications_result,
                    'bootcamps' => $bootcamps_result,
                    'roles' => $roles_result,
                    'settings' => $settings_result
                ]
            ];
            
        } catch (Exception $e) {
            $this->log('ERREUR: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Erreur lors de la migration: ' . $e->getMessage(),
                'log' => $this->log
            ];
        }
    }
    
    /**
     * Migrate trainers from old posts to new system
     */
    private function migrate_trainers() {
        $this->log('--- Migration des formateurs ---');
        
        $old_trainers = get_posts([
            'post_type' => 'trainer',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_migrated_to_tbm',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        $migrated_count = 0;
        $errors = [];
        
        foreach ($old_trainers as $old_trainer) {
            try {
                $this->migrate_single_trainer($old_trainer);
                $migrated_count++;
                
                // Mark as migrated
                update_post_meta($old_trainer->ID, '_migrated_to_tbm', true);
                
            } catch (Exception $e) {
                $errors[] = "Formateur ID {$old_trainer->ID}: " . $e->getMessage();
                $this->log("ERREUR formateur {$old_trainer->ID}: " . $e->getMessage());
            }
        }
        
        $this->log("Formateurs migrés: {$migrated_count}/" . count($old_trainers));
        
        return [
            'total' => count($old_trainers),
            'migrated' => $migrated_count,
            'errors' => $errors
        ];
    }
    
    /**
     * Migrate single trainer
     */
    private function migrate_single_trainer($old_trainer) {
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_trainers';
        
        // Get old meta data
        $first_name = get_post_meta($old_trainer->ID, 'first_name', true);
        $last_name = get_post_meta($old_trainer->ID, 'last_name', true);
        $email = get_post_meta($old_trainer->ID, 'email', true);
        $phone = get_post_meta($old_trainer->ID, 'phone_number', true);
        $country = get_post_meta($old_trainer->ID, 'country', true);
        $city = get_post_meta($old_trainer->ID, 'city', true);
        $expertise = get_post_meta($old_trainer->ID, 'expertise', true);
        $skills = get_post_meta($old_trainer->ID, 'skills', true);
        $description = get_post_meta($old_trainer->ID, 'description', true);
        $rating = get_post_meta($old_trainer->ID, 'rating', true);
        $badge = get_post_meta($old_trainer->ID, 'badge', true);
        $status = get_post_meta($old_trainer->ID, 'status', true);
        $linkedin = get_post_meta($old_trainer->ID, 'linkedin_profile', true);
        $calendly = get_post_meta($old_trainer->ID, 'calendly_link', true);
        
        // Map old status to new status
        $new_status = $this->map_trainer_status($status);
        
        // Try to find or create associated user
        $user_id = $this->find_or_create_trainer_user($email, $first_name, $last_name);
        
        // Prepare new data
        $data = [
            'user_id' => $user_id,
            'post_id' => $old_trainer->ID,
            'status' => $new_status,
            'first_name' => sanitize_text_field($first_name),
            'last_name' => sanitize_text_field($last_name),
            'email' => sanitize_email($email),
            'phone' => sanitize_text_field($phone),
            'country' => sanitize_text_field($country),
            'city' => sanitize_text_field($city),
            'expertise_areas' => sanitize_text_field($expertise),
            'skills' => sanitize_textarea_field($skills),
            'bio' => sanitize_textarea_field($description),
            'rating_average' => floatval($rating),
            'rating_count' => 1, // Assume at least one rating
            'linkedin_url' => esc_url_raw($linkedin),
            'calendly_link' => esc_url_raw($calendly),
            'featured' => $badge === 'Top Rated' ? 1 : 0,
            'created_at' => $old_trainer->post_date,
            'updated_at' => current_time('mysql')
        ];
        
        // Insert into new table
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            throw new Exception('Erreur lors de l\'insertion en base de données');
        }
        
        $trainer_id = $wpdb->insert_id;
        
        // Migrate documents
        $this->migrate_trainer_documents($old_trainer->ID, $trainer_id);
        
        $this->log("Formateur migré: {$first_name} {$last_name} (ID: {$trainer_id})");
        
        return $trainer_id;
    }
    
    /**
     * Find or create user for trainer
     */
    private function find_or_create_trainer_user($email, $first_name, $last_name) {
        if (empty($email)) {
            throw new Exception('Email requis pour créer un utilisateur');
        }
        
        // Try to find existing user
        $user = get_user_by('email', $email);
        
        if ($user) {
            // Add trainer role if not present
            if (!in_array('tbm_trainer', $user->roles)) {
                $user->add_role('tbm_trainer');
            }
            return $user->ID;
        }
        
        // Create new user
        $username = sanitize_user(strtolower($first_name . '.' . $last_name));
        $counter = 1;
        $original_username = $username;
        
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }
        
        $user_id = wp_create_user(
            $username,
            wp_generate_password(12),
            $email
        );
        
        if (is_wp_error($user_id)) {
            throw new Exception('Erreur lors de la création de l\'utilisateur: ' . $user_id->get_error_message());
        }
        
        // Set user meta
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'role' => 'tbm_trainer'
        ]);
        
        return $user_id;
    }
    
    /**
     * Map old trainer status to new status
     */
    private function map_trainer_status($old_status) {
        $status_map = [
            'Available' => 'active',
            'Unavailable' => 'active', // Still active but unavailable
            'Pending' => 'pending',
            'Approved' => 'active',
            'Rejected' => 'rejected',
            'Suspended' => 'suspended'
        ];
        
        return isset($status_map[$old_status]) ? $status_map[$old_status] : 'pending';
    }
    
    /**
     * Migrate trainer documents
     */
    private function migrate_trainer_documents($old_trainer_id, $new_trainer_id) {
        global $wpdb;
        $documents_table = $wpdb->prefix . 'tbm_documents';
        
        // Migrate CV
        $cv_path = get_post_meta($old_trainer_id, 'cv', true);
        if (!empty($cv_path)) {
            $this->migrate_document($cv_path, $new_trainer_id, 'cv', 'CV du formateur');
        }
        
        // Migrate photo
        $photo = get_post_meta($old_trainer_id, 'photo', true);
        if (!empty($photo) && is_array($photo) && isset($photo['url'])) {
            $this->migrate_document($photo['url'], $new_trainer_id, 'photo', 'Photo de profil');
        }
    }
    
    /**
     * Migrate single document
     */
    private function migrate_document($file_path, $trainer_id, $type, $title) {
        global $wpdb;
        $documents_table = $wpdb->prefix . 'tbm_documents';
        
        // Extract file info
        $file_name = basename($file_path);
        $file_size = 0;
        $mime_type = '';
        
        // Get file info if it's a local file
        if (strpos($file_path, home_url()) !== false) {
            $local_path = str_replace(home_url(), ABSPATH, $file_path);
            if (file_exists($local_path)) {
                $file_size = filesize($local_path);
                $mime_type = mime_content_type($local_path);
            }
        }
        
        $data = [
            'title' => $title,
            'file_name' => $file_name,
            'file_path' => $file_path,
            'file_type' => pathinfo($file_name, PATHINFO_EXTENSION),
            'file_size' => $file_size,
            'mime_type' => $mime_type,
            'document_type' => $type,
            'owner_id' => $trainer_id,
            'owner_type' => 'trainer',
            'trainer_id' => $trainer_id,
            'is_private' => 1,
            'created_at' => current_time('mysql')
        ];
        
        $wpdb->insert($documents_table, $data);
    }
    
    /**
     * Migrate applications (if any exist in old system)
     */
    private function migrate_applications() {
        $this->log('--- Migration des candidatures ---');
        
        // Check if old applications exist
        $old_applications = get_posts([
            'post_type' => 'application',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_migrated_to_tbm',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        if (empty($old_applications)) {
            $this->log('Aucune candidature à migrer');
            return ['total' => 0, 'migrated' => 0, 'errors' => []];
        }
        
        $migrated_count = 0;
        $errors = [];
        
        foreach ($old_applications as $old_application) {
            try {
                $this->migrate_single_application($old_application);
                $migrated_count++;
                
                // Mark as migrated
                update_post_meta($old_application->ID, '_migrated_to_tbm', true);
                
            } catch (Exception $e) {
                $errors[] = "Candidature ID {$old_application->ID}: " . $e->getMessage();
                $this->log("ERREUR candidature {$old_application->ID}: " . $e->getMessage());
            }
        }
        
        $this->log("Candidatures migrées: {$migrated_count}/" . count($old_applications));
        
        return [
            'total' => count($old_applications),
            'migrated' => $migrated_count,
            'errors' => $errors
        ];
    }
    
    /**
     * Migrate single application
     */
    private function migrate_single_application($old_application) {
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_applications';
        
        // Generate application number
        $application_number = 'MIG-' . date('Y') . '-' . $old_application->ID;
        
        // Get meta data
        $data = [
            'application_number' => $application_number,
            'first_name' => get_post_meta($old_application->ID, 'first_name', true),
            'last_name' => get_post_meta($old_application->ID, 'last_name', true),
            'email' => get_post_meta($old_application->ID, 'email', true),
            'phone' => get_post_meta($old_application->ID, 'phone', true),
            'country' => get_post_meta($old_application->ID, 'country', true),
            'city' => get_post_meta($old_application->ID, 'city', true),
            'expertise_areas' => get_post_meta($old_application->ID, 'expertise', true),
            'skills' => get_post_meta($old_application->ID, 'skills', true),
            'motivation' => $old_application->post_content,
            'status' => 'approved', // Assume old applications were approved
            'submitted_at' => $old_application->post_date,
            'created_at' => $old_application->post_date
        ];
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            throw new Exception('Erreur lors de l\'insertion de la candidature');
        }
        
        $this->log("Candidature migrée: {$data['first_name']} {$data['last_name']}");
        
        return $wpdb->insert_id;
    }
    
    /**
     * Migrate bootcamps from appointments
     */
    private function migrate_bootcamps() {
        $this->log('--- Migration des bootcamps/rendez-vous ---');
        
        // Old system might have had appointments, we'll create bootcamps from them
        $old_appointments = get_posts([
            'post_type' => 'appointment',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_migrated_to_tbm',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        if (empty($old_appointments)) {
            $this->log('Aucun rendez-vous à migrer');
            return ['total' => 0, 'migrated' => 0, 'errors' => []];
        }
        
        // Group appointments by trainer to create bootcamps
        $grouped_appointments = [];
        foreach ($old_appointments as $appointment) {
            $trainer_id = get_post_meta($appointment->ID, 'trainer_id', true);
            if (!isset($grouped_appointments[$trainer_id])) {
                $grouped_appointments[$trainer_id] = [];
            }
            $grouped_appointments[$trainer_id][] = $appointment;
        }
        
        $migrated_count = 0;
        $errors = [];
        
        foreach ($grouped_appointments as $trainer_id => $appointments) {
            try {
                $this->create_bootcamp_from_appointments($trainer_id, $appointments);
                $migrated_count += count($appointments);
                
                // Mark appointments as migrated
                foreach ($appointments as $appointment) {
                    update_post_meta($appointment->ID, '_migrated_to_tbm', true);
                }
                
            } catch (Exception $e) {
                $errors[] = "Bootcamp pour formateur {$trainer_id}: " . $e->getMessage();
                $this->log("ERREUR bootcamp formateur {$trainer_id}: " . $e->getMessage());
            }
        }
        
        $this->log("Rendez-vous migrés: {$migrated_count}/" . count($old_appointments));
        
        return [
            'total' => count($old_appointments),
            'migrated' => $migrated_count,
            'errors' => $errors
        ];
    }
    
    /**
     * Create bootcamp from grouped appointments
     */
    private function create_bootcamp_from_appointments($trainer_id, $appointments) {
        global $wpdb;
        $bootcamps_table = $wpdb->prefix . 'tbm_bootcamps';
        
        // Get trainer info
        $trainer = get_post($trainer_id);
        if (!$trainer) {
            throw new Exception('Formateur introuvable');
        }
        
        $trainer_name = get_post_meta($trainer_id, 'first_name', true) . ' ' . get_post_meta($trainer_id, 'last_name', true);
        
        // Create bootcamp
        $bootcamp_data = [
            'title' => "Sessions de {$trainer_name}",
            'description' => "Sessions migrées depuis l'ancien système",
            'category' => 'Général',
            'status' => 'completed',
            'max_participants' => 20,
            'current_participants' => count($appointments),
            'format' => 'online',
            'created_by' => 1, // Admin user
            'created_at' => current_time('mysql')
        ];
        
        $result = $wpdb->insert($bootcamps_table, $bootcamp_data);
        
        if ($result === false) {
            throw new Exception('Erreur lors de la création du bootcamp');
        }
        
        $bootcamp_id = $wpdb->insert_id;
        
        // Create sessions from appointments
        $this->create_sessions_from_appointments($bootcamp_id, $trainer_id, $appointments);
        
        $this->log("Bootcamp créé: {$bootcamp_data['title']} (ID: {$bootcamp_id})");
        
        return $bootcamp_id;
    }
    
    /**
     * Create sessions from appointments
     */
    private function create_sessions_from_appointments($bootcamp_id, $trainer_id, $appointments) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'tbm_sessions';
        
        // Find new trainer ID in migrated data
        $new_trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tbm_trainers WHERE post_id = %d",
            $trainer_id
        ));
        
        if (!$new_trainer) {
            throw new Exception('Nouveau formateur introuvable');
        }
        
        $session_number = 1;
        
        foreach ($appointments as $appointment) {
            $date = get_post_meta($appointment->ID, 'reservation_date', true);
            $time = get_post_meta($appointment->ID, 'reservation_time', true);
            
            if (empty($date) || empty($time)) {
                continue;
            }
            
            $start_datetime = $date . ' ' . $time;
            $end_datetime = date('Y-m-d H:i:s', strtotime($start_datetime . ' +1 hour'));
            
            $session_data = [
                'bootcamp_id' => $bootcamp_id,
                'trainer_id' => $new_trainer->id,
                'title' => "Session {$session_number}",
                'description' => 'Session migrée depuis l\'ancien système',
                'session_number' => $session_number,
                'status' => 'completed',
                'start_datetime' => $start_datetime,
                'end_datetime' => $end_datetime,
                'duration_minutes' => 60,
                'format' => 'online',
                'attendance_count' => 1,
                'created_at' => $appointment->post_date
            ];
            
            $wpdb->insert($sessions_table, $session_data);
            $session_number++;
        }
        
        $this->log("Sessions créées: " . ($session_number - 1) . " pour le bootcamp {$bootcamp_id}");
    }
    
    /**
     * Migrate user roles
     */
    private function migrate_user_roles() {
        $this->log('--- Migration des rôles utilisateurs ---');
        
        // Find users with old trainer role or capabilities
        $trainers = get_users([
            'role__in' => ['trainer', 'editor', 'administrator'],
            'meta_query' => [
                [
                    'key' => '_tbm_role_migrated',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        $migrated_count = 0;
        
        foreach ($trainers as $user) {
            // Check if user is associated with trainer post
            $trainer_posts = get_posts([
                'post_type' => 'trainer',
                'meta_query' => [
                    [
                        'key' => 'email',
                        'value' => $user->user_email
                    ]
                ]
            ]);
            
            if (!empty($trainer_posts)) {
                // Add new trainer role
                $user->add_role('tbm_trainer');
                update_user_meta($user->ID, '_tbm_role_migrated', true);
                $migrated_count++;
                
                $this->log("Rôle migré pour: {$user->display_name}");
            }
        }
        
        $this->log("Rôles migrés: {$migrated_count}");
        
        return [
            'total' => count($trainers),
            'migrated' => $migrated_count,
            'errors' => []
        ];
    }
    
    /**
     * Migrate plugin settings
     */
    private function migrate_settings() {
        $this->log('--- Migration des paramètres ---');
        
        $old_settings = [
            'cbp_currency' => 'tbm_currency',
            'cbp_email_notifications' => 'tbm_email_notifications',
            'cbp_auto_approve' => 'tbm_auto_approve_trainers',
            'cbp_max_file_size' => 'tbm_max_file_size'
        ];
        
        $migrated_count = 0;
        
        foreach ($old_settings as $old_key => $new_key) {
            $old_value = get_option($old_key);
            if ($old_value !== false) {
                update_option($new_key, $old_value);
                $migrated_count++;
                $this->log("Paramètre migré: {$old_key} -> {$new_key}");
            }
        }
        
        $this->log("Paramètres migrés: {$migrated_count}");
        
        return [
            'total' => count($old_settings),
            'migrated' => $migrated_count,
            'errors' => []
        ];
    }
    
    /**
     * Clean up old data (optional)
     */
    private function cleanup_old_data() {
        $this->log('--- Nettoyage des anciennes données ---');
        
        // Delete old posts (marked as migrated)
        $old_posts = get_posts([
            'post_type' => ['trainer', 'application', 'appointment'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_migrated_to_tbm',
                    'value' => true
                ]
            ]
        ]);
        
        $deleted_count = 0;
        
        foreach ($old_posts as $post) {
            wp_delete_post($post->ID, true);
            $deleted_count++;
        }
        
        // Delete old options
        $old_options = [
            'cbp_version',
            'cbp_db_version',
            'cbp_activated',
            'cbp_settings'
        ];
        
        foreach ($old_options as $option) {
            delete_option($option);
        }
        
        $this->log("Anciennes données supprimées: {$deleted_count} posts");
        
        return [
            'deleted_posts' => $deleted_count,
            'deleted_options' => count($old_options)
        ];
    }
    
    /**
     * Add log entry
     */
    private function log($message) {
        $timestamp = current_time('Y-m-d H:i:s');
        $this->log[] = "[{$timestamp}] {$message}";
        
        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("TBM Migration: {$message}");
        }
    }
    
    /**
     * Get migration log
     */
    public function get_migration_log() {
        return get_option('tbm_migration_log', []);
    }
    
    /**
     * Create migration admin page
     */
    public function create_migration_page() {
        ?>
        <div class="wrap">
            <h1>Migration des données</h1>
            
            <?php if ($this->needs_migration()): ?>
                <div class="notice notice-warning">
                    <p><strong>Migration requise :</strong> Des données de l'ancien plugin ont été détectées.</p>
                </div>
                
                <div class="card">
                    <h2>Données détectées</h2>
                    <p>Les données suivantes seront migrées :</p>
                    <ul>
                        <li>✅ Formateurs et leurs profils</li>
                        <li>✅ Documents (CV, photos)</li>
                        <li>✅ Candidatures (si présentes)</li>
                        <li>✅ Rendez-vous (convertis en bootcamps/sessions)</li>
                        <li>✅ Rôles utilisateurs</li>
                        <li>✅ Paramètres du plugin</li>
                    </ul>
                </div>
                
                <div class="card">
                    <h2>Options de migration</h2>
                    <form id="migration-form">
                        <table class="form-table">
                            <tr>
                                <th>Nettoyer les anciennes données</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cleanup" value="1" checked>
                                        Supprimer les anciennes données après migration
                                    </label>
                                    <p class="description">Recommandé pour éviter les doublons</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Sauvegarde</th>
                                <td>
                                    <p class="description">
                                        <strong>Important :</strong> Assurez-vous d'avoir une sauvegarde complète 
                                        de votre site avant de lancer la migration.
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php wp_nonce_field('tbm_migration_nonce', 'nonce'); ?>
                        
                        <p class="submit">
                            <button type="button" id="start-migration" class="button button-primary button-large">
                                🚀 Lancer la migration
                            </button>
                        </p>
                    </form>
                </div>
                
                <div id="migration-progress" style="display: none;">
                    <div class="card">
                        <h2>Migration en cours...</h2>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%"></div>
                        </div>
                        <div id="migration-log" style="background: #f0f0f0; padding: 1rem; margin-top: 1rem; height: 300px; overflow-y: auto; font-family: monospace;"></div>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong>Migration terminée :</strong> Toutes les données ont été migrées avec succès.</p>
                </div>
                
                <?php $log = $this->get_migration_log(); ?>
                <?php if (!empty($log)): ?>
                    <div class="card">
                        <h2>Journal de migration</h2>
                        <div style="background: #f0f0f0; padding: 1rem; height: 400px; overflow-y: auto; font-family: monospace;">
                            <?php foreach ($log as $entry): ?>
                                <div><?php echo esc_html($entry); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <style>
            .progress-bar {
                width: 100%;
                height: 20px;
                background: #e0e0e0;
                border-radius: 10px;
                overflow: hidden;
            }
            .progress-fill {
                height: 100%;
                background: linear-gradient(135deg, #667eea, #764ba2);
                transition: width 0.3s ease;
            }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const startButton = document.getElementById('start-migration');
            const progressDiv = document.getElementById('migration-progress');
            const logDiv = document.getElementById('migration-log');
            
            if (startButton) {
                startButton.addEventListener('click', function() {
                    if (!confirm('Êtes-vous sûr de vouloir lancer la migration ? Cette opération ne peut pas être annulée.')) {
                        return;
                    }
                    
                    startButton.disabled = true;
                    startButton.textContent = 'Migration en cours...';
                    progressDiv.style.display = 'block';
                    
                    const formData = new FormData();
                    formData.append('action', 'tbm_run_migration');
                    formData.append('nonce', document.querySelector('[name="nonce"]').value);
                    formData.append('cleanup', document.querySelector('[name="cleanup"]').checked ? '1' : '0');
                    
                    fetch(ajaxurl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            logDiv.innerHTML = data.data.log.map(entry => '<div>' + entry + '</div>').join('');
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            alert('Erreur lors de la migration: ' + data.data.message);
                            startButton.disabled = false;
                            startButton.textContent = '🚀 Lancer la migration';
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Erreur réseau lors de la migration');
                        startButton.disabled = false;
                        startButton.textContent = '🚀 Lancer la migration';
                    });
                });
            }
        });
        </script>
        <?php
    }
}