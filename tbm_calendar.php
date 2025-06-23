<?php
/**
 * Calendar Management Class
 * 
 * Handles calendar integration and event management
 * 
 * @package TrainerBootcampManager
 * @subpackage Calendar
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Calendar {
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Sessions table
     */
    private $sessions_table;
    
    /**
     * Availabilities table
     */
    private $availabilities_table;
    
    /**
     * Google Calendar service
     */
    private $google_service;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->sessions_table = $wpdb->prefix . 'tbm_sessions';
        $this->availabilities_table = $wpdb->prefix . 'tbm_availabilities';
        
        add_action('init', [$this, 'init_hooks']);
        add_action('admin_init', [$this, 'init_google_calendar']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_calendar_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_calendar_scripts']);
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_tbm_get_calendar_events', [$this, 'ajax_get_calendar_events']);
        add_action('wp_ajax_tbm_create_calendar_event', [$this, 'ajax_create_calendar_event']);
        add_action('wp_ajax_tbm_update_calendar_event', [$this, 'ajax_update_calendar_event']);
        add_action('wp_ajax_tbm_delete_calendar_event', [$this, 'ajax_delete_calendar_event']);
        add_action('wp_ajax_tbm_get_trainer_availability', [$this, 'ajax_get_trainer_availability']);
        add_action('wp_ajax_tbm_update_trainer_availability', [$this, 'ajax_update_trainer_availability']);
        add_action('wp_ajax_tbm_sync_google_calendar', [$this, 'ajax_sync_google_calendar']);
        
        // Session events
        add_action('tbm_session_created', [$this, 'on_session_created']);
        add_action('tbm_session_updated', [$this, 'on_session_updated']);
        add_action('tbm_session_deleted', [$this, 'on_session_deleted']);
        
        // Scheduled sync
        add_action('tbm_sync_google_calendars', [$this, 'sync_all_google_calendars']);
        
        // OAuth callback
        add_action('init', [$this, 'handle_google_oauth_callback']);
    }
    
    /**
     * Initialize Google Calendar API
     */
    public function init_google_calendar() {
        $credentials_file = TBM_PLUGIN_DIR . 'credentials.json';
        
        if (!file_exists($credentials_file)) {
            return;
        }
        
        require_once TBM_PLUGIN_DIR . 'vendor/autoload.php';
        
        try {
            $client = new Google_Client();
            $client->setApplicationName('Trainer Bootcamp Manager');
            $client->setScopes(Google_Service_Calendar::CALENDAR);
            $client->setAuthConfig($credentials_file);
            $client->setAccessType('offline');
            $client->setPrompt('select_account consent');
            
            $this->google_service = new Google_Service_Calendar($client);
            
        } catch (Exception $e) {
            error_log('Google Calendar API initialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Enqueue calendar scripts for frontend
     */
    public function enqueue_calendar_scripts() {
        if (is_user_logged_in()) {
            wp_enqueue_script(
                'tbm-calendar',
                TBM_ASSETS_URL . 'js/calendar.js',
                ['jquery', 'moment'],
                TBM_VERSION,
                true
            );
            
            wp_enqueue_style(
                'tbm-calendar',
                TBM_ASSETS_URL . 'css/calendar.css',
                [],
                TBM_VERSION
            );
            
            wp_localize_script('tbm-calendar', 'tbmCalendar', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tbm_calendar_nonce'),
                'userId' => get_current_user_id(),
                'isTrainer' => current_user_can('tbm_trainer'),
                'locale' => get_locale(),
                'dateFormat' => get_option('date_format'),
                'timeFormat' => get_option('time_format'),
                'timezone' => wp_timezone_string(),
                'strings' => [
                    'loading' => __('Loading...', 'trainer-bootcamp-manager'),
                    'noEvents' => __('No events found', 'trainer-bootcamp-manager'),
                    'createEvent' => __('Create Event', 'trainer-bootcamp-manager'),
                    'editEvent' => __('Edit Event', 'trainer-bootcamp-manager'),
                    'deleteEvent' => __('Delete Event', 'trainer-bootcamp-manager'),
                    'confirmDelete' => __('Are you sure you want to delete this event?', 'trainer-bootcamp-manager')
                ]
            ]);
        }
    }
    
    /**
     * Enqueue admin calendar scripts
     */
    public function enqueue_admin_calendar_scripts($hook) {
        if (strpos($hook, 'tbm') !== false) {
            $this->enqueue_calendar_scripts();
            
            // Additional admin calendar scripts
            wp_enqueue_script(
                'tbm-admin-calendar',
                TBM_ASSETS_URL . 'js/admin-calendar.js',
                ['tbm-calendar'],
                TBM_VERSION,
                true
            );
        }
    }
    
    /**
     * Get calendar events
     */
    public function get_calendar_events($args = []) {
        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-1 month')),
            'end_date' => date('Y-m-d', strtotime('+2 months')),
            'trainer_id' => null,
            'bootcamp_id' => null,
            'status' => null,
            'include_availability' => false
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $events = [];
        
        // Get sessions
        $sessions = $this->get_sessions_for_calendar($args);
        foreach ($sessions as $session) {
            $events[] = $this->format_session_for_calendar($session);
        }
        
        // Get availability slots if requested
        if ($args['include_availability'] && $args['trainer_id']) {
            $availability = $this->get_trainer_availability($args['trainer_id'], $args['start_date'], $args['end_date']);
            foreach ($availability as $slot) {
                $events[] = $this->format_availability_for_calendar($slot);
            }
        }
        
        return $events;
    }
    
    /**
     * Get sessions for calendar
     */
    private function get_sessions_for_calendar($args) {
        $where_conditions = [
            'DATE(start_datetime) >= %s',
            'DATE(start_datetime) <= %s'
        ];
        $where_values = [$args['start_date'], $args['end_date']];
        
        if ($args['trainer_id']) {
            $where_conditions[] = 'trainer_id = %d';
            $where_values[] = $args['trainer_id'];
        }
        
        if ($args['bootcamp_id']) {
            $where_conditions[] = 'bootcamp_id = %d';
            $where_values[] = $args['bootcamp_id'];
        }
        
        if ($args['status']) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $args['status'];
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = $this->wpdb->prepare(
            "SELECT s.*, b.title as bootcamp_title, t.first_name, t.last_name
             FROM {$this->sessions_table} s
             LEFT JOIN {$this->wpdb->prefix}tbm_bootcamps b ON s.bootcamp_id = b.id
             LEFT JOIN {$this->wpdb->prefix}tbm_trainers t ON s.trainer_id = t.id
             {$where_clause}
             ORDER BY start_datetime",
            $where_values
        );
        
        return $this->wpdb->get_results($query);
    }
    
    /**
     * Format session for calendar display
     */
    private function format_session_for_calendar($session) {
        $trainer_name = $session->first_name . ' ' . $session->last_name;
        
        return [
            'id' => 'session_' . $session->id,
            'type' => 'session',
            'title' => $session->title,
            'start' => $session->start_datetime,
            'end' => $session->end_datetime,
            'description' => $session->description,
            'trainer' => $trainer_name,
            'bootcamp' => $session->bootcamp_title,
            'status' => $session->status,
            'attendees' => $session->attendance_count,
            'location' => $session->location,
            'format' => $session->format,
            'meeting_url' => $session->meeting_url,
            'backgroundColor' => $this->get_status_color($session->status),
            'borderColor' => $this->get_status_color($session->status),
            'textColor' => '#ffffff',
            'editable' => current_user_can('edit_tbm_sessions'),
            'extendedProps' => [
                'sessionId' => $session->id,
                'trainerId' => $session->trainer_id,
                'bootcampId' => $session->bootcamp_id,
                'duration' => $session->duration_minutes,
                'notes' => $session->notes
            ]
        ];
    }
    
    /**
     * Format availability for calendar display
     */
    private function format_availability_for_calendar($availability) {
        return [
            'id' => 'availability_' . $availability->id,
            'type' => 'availability',
            'title' => $availability->is_available ? 'Disponible' : 'Indisponible',
            'start' => $availability->start_date . 'T' . $availability->start_time,
            'end' => $availability->end_date . 'T' . $availability->end_time,
            'backgroundColor' => $availability->is_available ? '#10b981' : '#ef4444',
            'borderColor' => $availability->is_available ? '#10b981' : '#ef4444',
            'textColor' => '#ffffff',
            'display' => 'background',
            'editable' => current_user_can('edit_own_tbm_trainer'),
            'extendedProps' => [
                'availabilityId' => $availability->id,
                'trainerId' => $availability->trainer_id,
                'type' => $availability->type,
                'reason' => $availability->reason,
                'recurring' => $availability->recurring
            ]
        ];
    }
    
    /**
     * Get status color for calendar events
     */
    private function get_status_color($status) {
        $colors = [
            'scheduled' => '#3b82f6',
            'in_progress' => '#f59e0b',
            'completed' => '#10b981',
            'cancelled' => '#ef4444',
            'rescheduled' => '#8b5cf6'
        ];
        
        return $colors[$status] ?? '#6b7280';
    }
    
    /**
     * Create calendar event
     */
    public function create_calendar_event($data) {
        // Validate required fields
        if (empty($data['title']) || empty($data['start_datetime']) || empty($data['end_datetime'])) {
            return new WP_Error('missing_fields', 'Title, start and end datetime are required');
        }
        
        // Check for conflicts
        $conflicts = $this->check_trainer_conflicts($data['trainer_id'], $data['start_datetime'], $data['end_datetime']);
        if (!empty($conflicts)) {
            return new WP_Error('schedule_conflict', 'Trainer has conflicting sessions at this time');
        }
        
        // Create session in database
        $session_data = [
            'bootcamp_id' => intval($data['bootcamp_id'] ?? 0),
            'trainer_id' => intval($data['trainer_id']),
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'start_datetime' => $data['start_datetime'],
            'end_datetime' => $data['end_datetime'],
            'duration_minutes' => $this->calculate_duration($data['start_datetime'], $data['end_datetime']),
            'location' => sanitize_text_field($data['location'] ?? ''),
            'format' => sanitize_text_field($data['format'] ?? 'online'),
            'status' => 'scheduled',
            'created_at' => current_time('mysql')
        ];
        
        $result = $this->wpdb->insert($this->sessions_table, $session_data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create session');
        }
        
        $session_id = $this->wpdb->insert_id;
        
        // Sync with Google Calendar if enabled
        if (get_option('tbm_google_calendar_sync', false)) {
            $this->sync_session_to_google_calendar($session_id, 'create');
        }
        
        do_action('tbm_session_created', $session_id);
        
        return $session_id;
    }
    
    /**
     * Update calendar event
     */
    public function update_calendar_event($session_id, $data) {
        $session = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->sessions_table} WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }
        
        // Check for conflicts if datetime changed
        if (isset($data['start_datetime']) || isset($data['end_datetime'])) {
            $start = $data['start_datetime'] ?? $session->start_datetime;
            $end = $data['end_datetime'] ?? $session->end_datetime;
            
            $conflicts = $this->check_trainer_conflicts($session->trainer_id, $start, $end, $session_id);
            if (!empty($conflicts)) {
                return new WP_Error('schedule_conflict', 'Trainer has conflicting sessions at this time');
            }
        }
        
        $update_data = [];
        
        $allowed_fields = [
            'title', 'description', 'start_datetime', 'end_datetime',
            'location', 'format', 'status', 'notes'
        ];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        // Recalculate duration if times changed
        if (isset($data['start_datetime']) || isset($data['end_datetime'])) {
            $start = $data['start_datetime'] ?? $session->start_datetime;
            $end = $data['end_datetime'] ?? $session->end_datetime;
            $update_data['duration_minutes'] = $this->calculate_duration($start, $end);
        }
        
        $update_data['updated_at'] = current_time('mysql');
        
        $result = $this->wpdb->update(
            $this->sessions_table,
            $update_data,
            ['id' => $session_id]
        );
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update session');
        }
        
        // Sync with Google Calendar if enabled
        if (get_option('tbm_google_calendar_sync', false)) {
            $this->sync_session_to_google_calendar($session_id, 'update');
        }
        
        do_action('tbm_session_updated', $session_id, $session);
        
        return true;
    }
    
    /**
     * Delete calendar event
     */
    public function delete_calendar_event($session_id) {
        $session = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->sessions_table} WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }
        
        // Soft delete
        $result = $this->wpdb->update(
            $this->sessions_table,
            [
                'status' => 'cancelled',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $session_id]
        );
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete session');
        }
        
        // Sync with Google Calendar if enabled
        if (get_option('tbm_google_calendar_sync', false)) {
            $this->sync_session_to_google_calendar($session_id, 'delete');
        }
        
        do_action('tbm_session_deleted', $session_id);
        
        return true;
    }
    
    /**
     * Check trainer schedule conflicts
     */
    public function check_trainer_conflicts($trainer_id, $start_datetime, $end_datetime, $exclude_session_id = null) {
        $where_conditions = [
            'trainer_id = %d',
            'status != %s',
            '(start_datetime < %s AND end_datetime > %s)'
        ];
        $where_values = [$trainer_id, 'cancelled', $end_datetime, $start_datetime];
        
        if ($exclude_session_id) {
            $where_conditions[] = 'id != %d';
            $where_values[] = $exclude_session_id;
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->sessions_table} {$where_clause}",
            $where_values
        );
        
        return $this->wpdb->get_results($query);
    }
    
    /**
     * Calculate duration in minutes
     */
    private function calculate_duration($start_datetime, $end_datetime) {
        $start = new DateTime($start_datetime);
        $end = new DateTime($end_datetime);
        $interval = $start->diff($end);
        
        return ($interval->h * 60) + $interval->i;
    }
    
    /**
     * Get trainer availability
     */
    public function get_trainer_availability($trainer_id, $start_date = null, $end_date = null) {
        $where_conditions = ['trainer_id = %d'];
        $where_values = [$trainer_id];
        
        if ($start_date) {
            $where_conditions[] = '(end_date IS NULL OR end_date >= %s)';
            $where_values[] = $start_date;
        }
        
        if ($end_date) {
            $where_conditions[] = '(start_date IS NULL OR start_date <= %s)';
            $where_values[] = $end_date;
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->availabilities_table} {$where_clause} ORDER BY start_date, start_time",
            $where_values
        );
        
        return $this->wpdb->get_results($query);
    }
    
    /**
     * Update trainer availability
     */
    public function update_trainer_availability($trainer_id, $availability_data) {
        // Delete existing availability for this trainer
        $this->wpdb->delete(
            $this->availabilities_table,
            ['trainer_id' => $trainer_id]
        );
        
        // Insert new availability slots
        foreach ($availability_data as $slot) {
            $data = [
                'trainer_id' => $trainer_id,
                'type' => sanitize_text_field($slot['type'] ?? 'regular'),
                'day_of_week' => isset($slot['day_of_week']) ? intval($slot['day_of_week']) : null,
                'start_date' => !empty($slot['start_date']) ? $slot['start_date'] : null,
                'end_date' => !empty($slot['end_date']) ? $slot['end_date'] : null,
                'start_time' => sanitize_text_field($slot['start_time']),
                'end_time' => sanitize_text_field($slot['end_time']),
                'timezone' => sanitize_text_field($slot['timezone'] ?? wp_timezone_string()),
                'is_available' => (bool) ($slot['is_available'] ?? true),
                'reason' => sanitize_text_field($slot['reason'] ?? ''),
                'recurring' => (bool) ($slot['recurring'] ?? false),
                'created_at' => current_time('mysql')
            ];
            
            $this->wpdb->insert($this->availabilities_table, $data);
        }
        
        do_action('tbm_trainer_availability_updated', $trainer_id);
        
        return true;
    }
    
    /**
     * Sync session to Google Calendar
     */
    private function sync_session_to_google_calendar($session_id, $action = 'create') {
        if (!$this->google_service) {
            return false;
        }
        
        $session = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT s.*, t.email as trainer_email, b.title as bootcamp_title
             FROM {$this->sessions_table} s
             LEFT JOIN {$this->wpdb->prefix}tbm_trainers t ON s.trainer_id = t.id
             LEFT JOIN {$this->wpdb->prefix}tbm_bootcamps b ON s.bootcamp_id = b.id
             WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session) {
            return false;
        }
        
        try {
            $calendar_id = get_option('tbm_google_calendar_id', 'primary');
            
            switch ($action) {
                case 'create':
                    $event = new Google_Service_Calendar_Event([
                        'summary' => $session->title,
                        'description' => $session->description . "\n\nBootcamp: " . $session->bootcamp_title,
                        'start' => [
                            'dateTime' => date('c', strtotime($session->start_datetime)),
                            'timeZone' => wp_timezone_string()
                        ],
                        'end' => [
                            'dateTime' => date('c', strtotime($session->end_datetime)),
                            'timeZone' => wp_timezone_string()
                        ],
                        'location' => $session->location,
                        'attendees' => [
                            ['email' => $session->trainer_email]
                        ]
                    ]);
                    
                    $created_event = $this->google_service->events->insert($calendar_id, $event);
                    
                    // Store Google event ID
                    update_post_meta($session_id, '_google_event_id', $created_event->getId());
                    break;
                    
                case 'update':
                    $google_event_id = get_post_meta($session_id, '_google_event_id', true);
                    if ($google_event_id) {
                        $event = $this->google_service->events->get($calendar_id, $google_event_id);
                        $event->setSummary($session->title);
                        $event->setDescription($session->description . "\n\nBootcamp: " . $session->bootcamp_title);
                        $event->setLocation($session->location);
                        
                        $start = new Google_Service_Calendar_EventDateTime();
                        $start->setDateTime(date('c', strtotime($session->start_datetime)));
                        $start->setTimeZone(wp_timezone_string());
                        $event->setStart($start);
                        
                        $end = new Google_Service_Calendar_EventDateTime();
                        $end->setDateTime(date('c', strtotime($session->end_datetime)));
                        $end->setTimeZone(wp_timezone_string());
                        $event->setEnd($end);
                        
                        $this->google_service->events->update($calendar_id, $google_event_id, $event);
                    }
                    break;
                    
                case 'delete':
                    $google_event_id = get_post_meta($session_id, '_google_event_id', true);
                    if ($google_event_id) {
                        $this->google_service->events->delete($calendar_id, $google_event_id);
                        delete_post_meta($session_id, '_google_event_id');
                    }
                    break;
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Google Calendar sync failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle Google OAuth callback
     */
    public function handle_google_oauth_callback() {
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            return;
        }
        
        if ($_GET['state'] !== get_option('tbm_google_oauth_state')) {
            wp_die('Invalid OAuth state');
        }
        
        try {
            $client = new Google_Client();
            $client->setAuthConfig(TBM_PLUGIN_DIR . 'credentials.json');
            $client->setRedirectUri(home_url('/oauth2callback'));
            
            $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
            
            if (isset($token['error'])) {
                wp_die('OAuth error: ' . $token['error_description']);
            }
            
            // Store the token
            update_option('tbm_google_access_token', $token);
            update_option('tbm_google_calendar_sync', true);
            
            // Redirect to calendar settings
            wp_redirect(admin_url('admin.php?page=tbm-settings&tab=calendar&oauth=success'));
            exit;
            
        } catch (Exception $e) {
            wp_die('OAuth callback error: ' . $e->getMessage());
        }
    }
    
    // Event handlers
    
    /**
     * Handle session created
     */
    public function on_session_created($session_id) {
        // Auto-sync to Google Calendar if enabled
        if (get_option('tbm_google_calendar_sync', false)) {
            $this->sync_session_to_google_calendar($session_id, 'create');
        }
        
        // Send notifications to participants
        $this->send_session_notifications($session_id, 'created');
    }
    
    /**
     * Handle session updated
     */
    public function on_session_updated($session_id, $old_session) {
        // Auto-sync to Google Calendar if enabled
        if (get_option('tbm_google_calendar_sync', false)) {
            $this->sync_session_to_google_calendar($session_id, 'update');
        }
        
        // Send update notifications
        $this->send_session_notifications($session_id, 'updated');
    }
    
    /**
     * Handle session deleted
     */
    public function on_session_deleted($session_id) {
        // Auto-sync to Google Calendar if enabled
        if (get_option('tbm_google_calendar_sync', false)) {
            $this->sync_session_to_google_calendar($session_id, 'delete');
        }
        
        // Send cancellation notifications
        $this->send_session_notifications($session_id, 'cancelled');
    }
    
    /**
     * Send session notifications
     */
    private function send_session_notifications($session_id, $type) {
        if (!class_exists('TBM_Notifications')) {
            return;
        }
        
        $session = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT s.*, t.user_id as trainer_user_id, t.first_name, t.last_name
             FROM {$this->sessions_table} s
             JOIN {$this->wpdb->prefix}tbm_trainers t ON s.trainer_id = t.id
             WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session) {
            return;
        }
        
        $notifications = new TBM_Notifications();
        
        $messages = [
            'created' => 'Une nouvelle session a été planifiée',
            'updated' => 'Votre session a été mise à jour',
            'cancelled' => 'Votre session a été annulée'
        ];
        
        $notifications->create_notification([
            'user_id' => $session->trainer_user_id,
            'type' => 'session_' . $type,
            'title' => $messages[$type] ?? 'Mise à jour de session',
            'message' => "{$session->title} - " . date('d/m/Y à H:i', strtotime($session->start_datetime)),
            'action_url' => admin_url('admin.php?page=tbm-sessions&session_id=' . $session_id),
            'action_text' => 'Voir la session',
            'priority' => $type === 'cancelled' ? 'high' : 'medium',
            'channel' => 'email'
        ]);
    }
    
    // AJAX handlers
    
    /**
     * AJAX: Get calendar events
     */
    public function ajax_get_calendar_events() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        $start_date = sanitize_text_field($_POST['start'] ?? '');
        $end_date = sanitize_text_field($_POST['end'] ?? '');
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $include_availability = (bool) ($_POST['include_availability'] ?? false);
        
        // Check permissions
        if ($trainer_id && !current_user_can('view_tbm_sessions')) {
            // Allow trainers to view their own calendar
            $current_trainer = (new TBM_User_Management())->get_trainer_by_user_id(get_current_user_id());
            if (!$current_trainer || $current_trainer->id != $trainer_id) {
                wp_send_json_error('Insufficient permissions');
            }
        }
        
        $events = $this->get_calendar_events([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'trainer_id' => $trainer_id ?: null,
            'include_availability' => $include_availability
        ]);
        
        wp_send_json_success($events);
    }
    
    /**
     * AJAX: Create calendar event
     */
    public function ajax_create_calendar_event() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        if (!current_user_can('edit_tbm_sessions')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $data = [
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'start_datetime' => sanitize_text_field($_POST['start_datetime'] ?? ''),
            'end_datetime' => sanitize_text_field($_POST['end_datetime'] ?? ''),
            'trainer_id' => intval($_POST['trainer_id'] ?? 0),
            'bootcamp_id' => intval($_POST['bootcamp_id'] ?? 0),
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'format' => sanitize_text_field($_POST['format'] ?? 'online')
        ];
        
        $result = $this->create_calendar_event($data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'session_id' => $result,
            'message' => 'Event created successfully'
        ]);
    }
    
    /**
     * AJAX: Update calendar event
     */
    public function ajax_update_calendar_event() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        if (!current_user_can('edit_tbm_sessions')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $data = [];
        
        $allowed_fields = ['title', 'description', 'start_datetime', 'end_datetime', 'location', 'format', 'status'];
        
        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        $result = $this->update_calendar_event($session_id, $data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(['message' => 'Event updated successfully']);
    }
    
    /**
     * AJAX: Delete calendar event
     */
    public function ajax_delete_calendar_event() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        if (!current_user_can('delete_tbm_sessions')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        
        $result = $this->delete_calendar_event($session_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(['message' => 'Event deleted successfully']);
    }
    
    /**
     * AJAX: Get trainer availability
     */
    public function ajax_get_trainer_availability() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');
        
        // Check permissions
        $current_trainer = (new TBM_User_Management())->get_trainer_by_user_id(get_current_user_id());
        if (!current_user_can('view_tbm_sessions') && (!$current_trainer || $current_trainer->id != $trainer_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $availability = $this->get_trainer_availability($trainer_id, $start_date, $end_date);
        
        wp_send_json_success($availability);
    }
    
    /**
     * AJAX: Update trainer availability
     */
    public function ajax_update_trainer_availability() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $availability_data = $_POST['availability'] ?? [];
        
        // Check permissions
        $current_trainer = (new TBM_User_Management())->get_trainer_by_user_id(get_current_user_id());
        if (!current_user_can('edit_tbm_sessions') && (!$current_trainer || $current_trainer->id != $trainer_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $result = $this->update_trainer_availability($trainer_id, $availability_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(['message' => 'Availability updated successfully']);
    }
    
    /**
     * AJAX: Sync Google Calendar
     */
    public function ajax_sync_google_calendar() {
        check_ajax_referer('tbm_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $synced = $this->sync_all_google_calendars();
        
        wp_send_json_success([
            'message' => 'Google Calendar sync completed',
            'synced_events' => $synced
        ]);
    }
    
    /**
     * Sync all Google Calendars
     */
    public function sync_all_google_calendars() {
        if (!$this->google_service) {
            return 0;
        }
        
        $synced_count = 0;
        
        // Get all future sessions
        $sessions = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id FROM {$this->sessions_table} 
             WHERE start_datetime > %s AND status != 'cancelled'",
            current_time('mysql')
        ));
        
        foreach ($sessions as $session) {
            if ($this->sync_session_to_google_calendar($session->id, 'update')) {
                $synced_count++;
            }
        }
        
        return $synced_count;
    }
}