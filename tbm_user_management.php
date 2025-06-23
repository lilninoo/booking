<?php
/**
 * User Management Class
 * 
 * Advanced user management for trainers and administrators
 * 
 * @package TrainerBootcampManager
 * @subpackage UserManagement
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_User_Management {
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Trainers table
     */
    private $trainers_table;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        add_action('init', [$this, 'init_hooks']);
        add_action('user_register', [$this, 'on_user_register']);
        add_action('delete_user', [$this, 'on_user_delete']);
        add_action('wp_login', [$this, 'on_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_user_logout']);
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Profile management
        add_action('show_user_profile', [$this, 'add_trainer_profile_fields']);
        add_action('edit_user_profile', [$this, 'add_trainer_profile_fields']);
        add_action('personal_options_update', [$this, 'save_trainer_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_trainer_profile_fields']);
        
        // Custom user columns
        add_filter('manage_users_columns', [$this, 'add_user_columns']);
        add_filter('manage_users_custom_column', [$this, 'show_user_column_content'], 10, 3);
        
        // User bulk actions
        add_filter('bulk_actions-users', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-users', [$this, 'handle_bulk_actions'], 10, 3);
        
        // AJAX handlers
        add_action('wp_ajax_tbm_update_user_status', [$this, 'ajax_update_user_status']);
        add_action('wp_ajax_tbm_get_user_stats', [$this, 'ajax_get_user_stats']);
        add_action('wp_ajax_tbm_search_users', [$this, 'ajax_search_users']);
    }
    
    /**
     * Create trainer from user
     */
    public function create_trainer_from_user($user_id, $data = []) {
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found');
        }
        
        // Check if trainer already exists
        $existing_trainer = $this->get_trainer_by_user_id($user_id);
        if ($existing_trainer) {
            return new WP_Error('trainer_exists', 'Trainer already exists for this user');
        }
        
        $trainer_data = [
            'user_id' => $user_id,
            'status' => 'pending',
            'first_name' => $user->first_name ?: get_user_meta($user_id, 'first_name', true),
            'last_name' => $user->last_name ?: get_user_meta($user_id, 'last_name', true),
            'email' => $user->user_email,
            'phone' => get_user_meta($user_id, 'phone', true),
            'country' => get_user_meta($user_id, 'country', true),
            'city' => get_user_meta($user_id, 'city', true),
            'timezone' => get_user_meta($user_id, 'timezone', true) ?: get_option('timezone_string'),
            'created_at' => current_time('mysql')
        ];
        
        // Merge with provided data
        $trainer_data = array_merge($trainer_data, $data);
        
        $result = $this->wpdb->insert($this->trainers_table, $trainer_data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create trainer');
        }
        
        $trainer_id = $this->wpdb->insert_id;
        
        // Add trainer role to user
        $user->add_role('tbm_trainer');
        
        // Update user meta
        update_user_meta($user_id, 'tbm_trainer_id', $trainer_id);
        update_user_meta($user_id, 'tbm_trainer_status', 'pending');
        
        do_action('tbm_trainer_created', $trainer_id, $user_id);
        
        return $trainer_id;
    }
    
    /**
     * Get trainer by user ID
     */
    public function get_trainer_by_user_id($user_id) {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->trainers_table} WHERE user_id = %d",
            $user_id
        ));
    }
    
    /**
     * Update trainer status
     */
    public function update_trainer_status($trainer_id, $status, $notes = '') {
        $valid_statuses = ['pending', 'approved', 'active', 'suspended', 'rejected'];
        
        if (!in_array($status, $valid_statuses)) {
            return new WP_Error('invalid_status', 'Invalid trainer status');
        }
        
        $trainer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->trainers_table} WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        $old_status = $trainer->status;
        
        // Update trainer status
        $result = $this->wpdb->update(
            $this->trainers_table,
            [
                'status' => $status,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $trainer_id]
        );
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update trainer status');
        }
        
        // Update user meta
        update_user_meta($trainer->user_id, 'tbm_trainer_status', $status);
        
        // Handle status-specific actions
        $this->handle_status_change($trainer, $old_status, $status, $notes);
        
        do_action('tbm_trainer_status_changed', $trainer_id, $old_status, $status);
        
        return true;
    }
    
    /**
     * Handle trainer status change
     */
    private function handle_status_change($trainer, $old_status, $new_status, $notes) {
        $user = get_userdata($trainer->user_id);
        
        switch ($new_status) {
            case 'approved':
                // Activate trainer
                $this->activate_trainer($trainer->id);
                
                // Send approval notification
                if (class_exists('TBM_Notifications')) {
                    $notifications = new TBM_Notifications();
                    $notifications->create_notification([
                        'user_id' => $trainer->user_id,
                        'type' => 'application_approved',
                        'title' => 'Candidature approuvée',
                        'message' => 'Félicitations ! Votre candidature de formateur a été approuvée.',
                        'priority' => 'high',
                        'channel' => 'email'
                    ]);
                }
                break;
                
            case 'rejected':
                // Remove trainer role
                $user->remove_role('tbm_trainer');
                
                // Send rejection notification
                if (class_exists('TBM_Notifications')) {
                    $notifications = new TBM_Notifications();
                    $notifications->create_notification([
                        'user_id' => $trainer->user_id,
                        'type' => 'application_rejected',
                        'title' => 'Candidature non retenue',
                        'message' => 'Nous vous remercions pour votre candidature. ' . $notes,
                        'priority' => 'medium',
                        'channel' => 'email'
                    ]);
                }
                break;
                
            case 'suspended':
                // Suspend trainer activities
                $this->suspend_trainer_activities($trainer->id);
                
                // Send suspension notification
                if (class_exists('TBM_Notifications')) {
                    $notifications = new TBM_Notifications();
                    $notifications->create_notification([
                        'user_id' => $trainer->user_id,
                        'type' => 'account_suspended',
                        'title' => 'Compte suspendu',
                        'message' => 'Votre compte formateur a été temporairement suspendu. ' . $notes,
                        'priority' => 'high',
                        'channel' => 'email'
                    ]);
                }
                break;
                
            case 'active':
                // Reactivate trainer if coming from suspension
                if ($old_status === 'suspended') {
                    $this->reactivate_trainer($trainer->id);
                }
                break;
        }
    }
    
    /**
     * Activate trainer
     */
    private function activate_trainer($trainer_id) {
        // Update status to active
        $this->wpdb->update(
            $this->trainers_table,
            ['status' => 'active'],
            ['id' => $trainer_id]
        );
        
        // Create welcome message
        $this->create_welcome_resources($trainer_id);
    }
    
    /**
     * Suspend trainer activities
     */
    private function suspend_trainer_activities($trainer_id) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'tbm_sessions';
        
        // Cancel future sessions
        $wpdb->update(
            $sessions_table,
            ['status' => 'cancelled'],
            [
                'trainer_id' => $trainer_id,
                'start_datetime' => ['>', current_time('mysql')]
            ]
        );
    }
    
    /**
     * Reactivate trainer
     */
    private function reactivate_trainer($trainer_id) {
        // Send reactivation notification
        $trainer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->trainers_table} WHERE id = %d",
            $trainer_id
        ));
        
        if ($trainer && class_exists('TBM_Notifications')) {
            $notifications = new TBM_Notifications();
            $notifications->create_notification([
                'user_id' => $trainer->user_id,
                'type' => 'account_reactivated',
                'title' => 'Compte réactivé',
                'message' => 'Votre compte formateur a été réactivé. Vous pouvez reprendre vos activités.',
                'priority' => 'medium',
                'channel' => 'email'
            ]);
        }
    }
    
    /**
     * Create welcome resources for new trainer
     */
    private function create_welcome_resources($trainer_id) {
        $trainer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->trainers_table} WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return;
        }
        
        // Create welcome notification
        if (class_exists('TBM_Notifications')) {
            $notifications = new TBM_Notifications();
            $notifications->create_notification([
                'user_id' => $trainer->user_id,
                'type' => 'welcome',
                'title' => 'Bienvenue chez nous !',
                'message' => 'Votre compte formateur est maintenant actif. Découvrez votre tableau de bord et commencez à planifier vos sessions.',
                'action_url' => admin_url('admin.php?page=tbm-trainer-dashboard'),
                'action_text' => 'Accéder au dashboard',
                'priority' => 'medium',
                'channel' => 'email'
            ]);
        }
        
        // Schedule profile completion reminder
        wp_schedule_single_event(
            strtotime('+3 days'),
            'tbm_profile_completion_reminder',
            [$trainer->user_id]
        );
    }
    
    /**
     * Calculate profile completion percentage
     */
    public function calculate_profile_completion($trainer_id) {
        $trainer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->trainers_table} WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return 0;
        }
        
        $required_fields = [
            'first_name' => 15,
            'last_name' => 15,
            'email' => 10,
            'phone' => 5,
            'country' => 5,
            'city' => 5,
            'expertise_areas' => 15,
            'skills' => 10,
            'bio' => 15,
            'hourly_rate' => 5
        ];
        
        $completion = 0;
        
        foreach ($required_fields as $field => $weight) {
            if (!empty($trainer->$field)) {
                $completion += $weight;
            }
        }
        
        // Check for uploaded documents
        $documents_table = $this->wpdb->prefix . 'tbm_documents';
        $cv_uploaded = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$documents_table} 
             WHERE trainer_id = %d AND document_type = 'cv'",
            $trainer_id
        ));
        
        if ($cv_uploaded > 0) {
            $completion += 10; // CV upload weight
        }
        
        // Update in database
        $this->wpdb->update(
            $this->trainers_table,
            ['profile_completion' => $completion],
            ['id' => $trainer_id]
        );
        
        return $completion;
    }
    
    /**
     * Get user statistics
     */
    public function get_user_statistics($user_id = null) {
        $where_clause = '';
        $where_values = [];
        
        if ($user_id) {
            $where_clause = 'WHERE user_id = %d';
            $where_values[] = $user_id;
        }
        
        $query = "SELECT 
            status,
            COUNT(*) as count,
            AVG(profile_completion) as avg_completion
        FROM {$this->trainers_table} 
        {$where_clause}
        GROUP BY status";
        
        if (!empty($where_values)) {
            $query = $this->wpdb->prepare($query, $where_values);
        }
        
        $results = $this->wpdb->get_results($query);
        
        $stats = [
            'total' => 0,
            'pending' => 0,
            'active' => 0,
            'suspended' => 0,
            'rejected' => 0,
            'avg_completion' => 0
        ];
        
        foreach ($results as $result) {
            $stats['total'] += $result->count;
            $stats[$result->status] = $result->count;
            $stats['avg_completion'] += $result->avg_completion;
        }
        
        $stats['avg_completion'] = $stats['avg_completion'] / max(1, count($results));
        
        return $stats;
    }
    
    /**
     * Add trainer profile fields to user profile
     */
    public function add_trainer_profile_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        
        $trainer = $this->get_trainer_by_user_id($user->ID);
        $trainer_id = get_user_meta($user->ID, 'tbm_trainer_id', true);
        
        ?>
        <h3>Informations Formateur</h3>
        <table class="form-table">
            <?php if ($trainer): ?>
            <tr>
                <th><label>Statut Formateur</label></th>
                <td>
                    <select name="tbm_trainer_status" <?php echo current_user_can('edit_tbm_trainers') ? '' : 'disabled'; ?>>
                        <option value="pending" <?php selected($trainer->status, 'pending'); ?>>En attente</option>
                        <option value="approved" <?php selected($trainer->status, 'approved'); ?>>Approuvé</option>
                        <option value="active" <?php selected($trainer->status, 'active'); ?>>Actif</option>
                        <option value="suspended" <?php selected($trainer->status, 'suspended'); ?>>Suspendu</option>
                        <option value="rejected" <?php selected($trainer->status, 'rejected'); ?>>Rejeté</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label>Complétion du profil</label></th>
                <td>
                    <strong><?php echo $trainer->profile_completion; ?>%</strong>
                    <div style="background: #f0f0f0; height: 10px; border-radius: 5px; margin-top: 5px;">
                        <div style="background: #667eea; height: 100%; width: <?php echo $trainer->profile_completion; ?>%; border-radius: 5px;"></div>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label>Domaines d'expertise</label></th>
                <td>
                    <input type="text" name="tbm_expertise_areas" value="<?php echo esc_attr($trainer->expertise_areas); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label>Tarif horaire</label></th>
                <td>
                    <input type="number" name="tbm_hourly_rate" value="<?php echo esc_attr($trainer->hourly_rate); ?>" step="0.01" /> 
                    <select name="tbm_currency">
                        <option value="EUR" <?php selected($trainer->currency, 'EUR'); ?>>€</option>
                        <option value="USD" <?php selected($trainer->currency, 'USD'); ?>>$</option>
                        <option value="GBP" <?php selected($trainer->currency, 'GBP'); ?>>£</option>
                    </select>
                </td>
            </tr>
            <?php else: ?>
            <tr>
                <th><label>Créer profil formateur</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="tbm_create_trainer" value="1" />
                        Créer un profil formateur pour cet utilisateur
                    </label>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }
    
    /**
     * Save trainer profile fields
     */
    public function save_trainer_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        
        $trainer = $this->get_trainer_by_user_id($user_id);
        
        if ($trainer) {
            // Update existing trainer
            $update_data = [];
            
            if (isset($_POST['tbm_expertise_areas'])) {
                $update_data['expertise_areas'] = sanitize_text_field($_POST['tbm_expertise_areas']);
            }
            
            if (isset($_POST['tbm_hourly_rate'])) {
                $update_data['hourly_rate'] = floatval($_POST['tbm_hourly_rate']);
            }
            
            if (isset($_POST['tbm_currency'])) {
                $update_data['currency'] = sanitize_text_field($_POST['tbm_currency']);
            }
            
            if (!empty($update_data)) {
                $update_data['updated_at'] = current_time('mysql');
                $this->wpdb->update($this->trainers_table, $update_data, ['id' => $trainer->id]);
                
                // Recalculate profile completion
                $this->calculate_profile_completion($trainer->id);
            }
            
            // Update status if admin
            if (current_user_can('edit_tbm_trainers') && isset($_POST['tbm_trainer_status'])) {
                $new_status = sanitize_text_field($_POST['tbm_trainer_status']);
                $this->update_trainer_status($trainer->id, $new_status);
            }
            
        } elseif (isset($_POST['tbm_create_trainer']) && $_POST['tbm_create_trainer'] === '1') {
            // Create new trainer profile
            $this->create_trainer_from_user($user_id);
        }
    }
    
    /**
     * Add custom user columns
     */
    public function add_user_columns($columns) {
        $columns['tbm_trainer_status'] = 'Statut Formateur';
        $columns['tbm_profile_completion'] = 'Profil';
        return $columns;
    }
    
    /**
     * Show custom user column content
     */
    public function show_user_column_content($value, $column_name, $user_id) {
        switch ($column_name) {
            case 'tbm_trainer_status':
                $trainer = $this->get_trainer_by_user_id($user_id);
                if ($trainer) {
                    $status_labels = [
                        'pending' => '<span style="color: #f59e0b;">En attente</span>',
                        'approved' => '<span style="color: #10b981;">Approuvé</span>',
                        'active' => '<span style="color: #059669;"><strong>Actif</strong></span>',
                        'suspended' => '<span style="color: #ef4444;">Suspendu</span>',
                        'rejected' => '<span style="color: #dc2626;">Rejeté</span>'
                    ];
                    return $status_labels[$trainer->status] ?? $trainer->status;
                }
                return '—';
                
            case 'tbm_profile_completion':
                $trainer = $this->get_trainer_by_user_id($user_id);
                if ($trainer) {
                    $completion = $trainer->profile_completion;
                    $color = $completion >= 80 ? '#10b981' : ($completion >= 50 ? '#f59e0b' : '#ef4444');
                    return "<span style='color: {$color}; font-weight: 600;'>{$completion}%</span>";
                }
                return '—';
        }
        
        return $value;
    }
    
    /**
     * Add bulk actions
     */
    public function add_bulk_actions($actions) {
        $actions['tbm_approve_trainers'] = 'Approuver comme formateurs';
        $actions['tbm_suspend_trainers'] = 'Suspendre formateurs';
        $actions['tbm_activate_trainers'] = 'Activer formateurs';
        return $actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $user_ids) {
        if (!current_user_can('edit_tbm_trainers')) {
            return $redirect_to;
        }
        
        $count = 0;
        
        foreach ($user_ids as $user_id) {
            $trainer = $this->get_trainer_by_user_id($user_id);
            
            switch ($action) {
                case 'tbm_approve_trainers':
                    if (!$trainer) {
                        $this->create_trainer_from_user($user_id);
                        $trainer = $this->get_trainer_by_user_id($user_id);
                    }
                    if ($trainer) {
                        $this->update_trainer_status($trainer->id, 'approved');
                        $count++;
                    }
                    break;
                    
                case 'tbm_suspend_trainers':
                    if ($trainer) {
                        $this->update_trainer_status($trainer->id, 'suspended');
                        $count++;
                    }
                    break;
                    
                case 'tbm_activate_trainers':
                    if ($trainer) {
                        $this->update_trainer_status($trainer->id, 'active');
                        $count++;
                    }
                    break;
            }
        }
        
        if ($count > 0) {
            $redirect_to = add_query_arg('tbm_bulk_action_result', $count, $redirect_to);
        }
        
        return $redirect_to;
    }
    
    // Event handlers
    
    /**
     * Handle user registration
     */
    public function on_user_register($user_id) {
        // Auto-create trainer profile if user has trainer role
        $user = get_userdata($user_id);
        if ($user && in_array('tbm_trainer', $user->roles)) {
            $this->create_trainer_from_user($user_id);
        }
    }
    
    /**
     * Handle user deletion
     */
    public function on_user_delete($user_id) {
        $trainer = $this->get_trainer_by_user_id($user_id);
        if ($trainer) {
            // Soft delete trainer record
            $this->wpdb->update(
                $this->trainers_table,
                ['status' => 'deleted', 'updated_at' => current_time('mysql')],
                ['id' => $trainer->id]
            );
        }
    }
    
    /**
     * Handle user login
     */
    public function on_user_login($user_login, $user) {
        if (in_array('tbm_trainer', $user->roles)) {
            // Update last login
            $trainer = $this->get_trainer_by_user_id($user->ID);
            if ($trainer) {
                update_user_meta($user->ID, 'tbm_last_login', current_time('mysql'));
                
                // Check profile completion
                $completion = $this->calculate_profile_completion($trainer->id);
                if ($completion < 80) {
                    // Schedule profile completion reminder
                    wp_schedule_single_event(
                        strtotime('+1 hour'),
                        'tbm_profile_completion_reminder',
                        [$user->ID]
                    );
                }
            }
        }
    }
    
    /**
     * Handle user logout
     */
    public function on_user_logout() {
        $user_id = get_current_user_id();
        if ($user_id) {
            update_user_meta($user_id, 'tbm_last_logout', current_time('mysql'));
        }
    }
    
    // AJAX handlers
    
    /**
     * AJAX: Update user status
     */
    public function ajax_update_user_status() {
        check_ajax_referer('tbm_user_management_nonce', 'nonce');
        
        if (!current_user_can('edit_tbm_trainers')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        $trainer = $this->get_trainer_by_user_id($user_id);
        if (!$trainer) {
            wp_send_json_error('Trainer not found');
        }
        
        $result = $this->update_trainer_status($trainer->id, $status, $notes);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'message' => 'Status updated successfully',
            'new_status' => $status
        ]);
    }
    
    /**
     * AJAX: Get user statistics
     */
    public function ajax_get_user_stats() {
        check_ajax_referer('tbm_user_management_nonce', 'nonce');
        
        if (!current_user_can('view_tbm_stats')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $stats = $this->get_user_statistics($user_id ?: null);
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Search users
     */
    public function ajax_search_users() {
        check_ajax_referer('tbm_user_management_nonce', 'nonce');
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        $role = sanitize_text_field($_POST['role'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? '');
        
        $args = [
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 20
        ];
        
        if ($role) {
            $args['role'] = $role;
        }
        
        if ($role === 'tbm_trainer' && $status) {
            $args['meta_query'] = [
                [
                    'key' => 'tbm_trainer_status',
                    'value' => $status,
                    'compare' => '='
                ]
            ];
        }
        
        $users = get_users($args);
        $results = [];
        
        foreach ($users as $user) {
            $trainer = $this->get_trainer_by_user_id($user->ID);
            
            $results[] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'roles' => $user->roles,
                'trainer_status' => $trainer ? $trainer->status : null,
                'profile_completion' => $trainer ? $trainer->profile_completion : 0
            ];
        }
        
        wp_send_json_success($results);
    }
}