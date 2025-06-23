<?php
/**
 * Notifications System
 * 
 * Modern notification system with multiple channels and real-time updates
 * 
 * @package TrainerBootcampManager
 * @subpackage Notifications
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Notifications {
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Notifications table
     */
    private $table;
    
    /**
     * Notification types
     */
    const TYPES = [
        'application_submitted' => 'Nouvelle candidature',
        'application_approved' => 'Candidature approuvée',
        'application_rejected' => 'Candidature rejetée',
        'session_scheduled' => 'Session planifiée',
        'session_reminder' => 'Rappel de session',
        'session_cancelled' => 'Session annulée',
        'payment_processed' => 'Paiement traité',
        'contract_signed' => 'Contrat signé',
        'bootcamp_created' => 'Nouveau bootcamp',
        'profile_incomplete' => 'Profil incomplet',
        'rating_received' => 'Nouvelle évaluation',
        'message_received' => 'Nouveau message',
        'system_update' => 'Mise à jour système'
    ];
    
    /**
     * Notification channels
     */
    const CHANNELS = [
        'in_app' => 'Dans l\'application',
        'email' => 'Email',
        'sms' => 'SMS',
        'push' => 'Push notification'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'tbm_notifications';
        
        add_action('init', [$this, 'init_hooks']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_tbm_get_notifications', [$this, 'ajax_get_notifications']);
        add_action('wp_ajax_tbm_mark_notification_read', [$this, 'ajax_mark_notification_read']);
        add_action('wp_ajax_tbm_mark_all_notifications_read', [$this, 'ajax_mark_all_notifications_read']);
        add_action('tbm_send_notifications', [$this, 'process_scheduled_notifications']);
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Application notifications
        add_action('tbm_application_submitted', [$this, 'on_application_submitted'], 10, 1);
        add_action('tbm_application_status_changed', [$this, 'on_application_status_changed'], 10, 3);
        
        // Session notifications
        add_action('tbm_session_created', [$this, 'on_session_created'], 10, 1);
        add_action('tbm_session_updated', [$this, 'on_session_updated'], 10, 2);
        add_action('tbm_session_cancelled', [$this, 'on_session_cancelled'], 10, 1);
        
        // Payment notifications
        add_action('tbm_payment_processed', [$this, 'on_payment_processed'], 10, 1);
        
        // Daily reminders
        add_action('tbm_daily_reminders', [$this, 'send_daily_reminders']);
        
        // Real-time notifications via WebSocket (if available)
        if (class_exists('Ratchet\Server\IoServer')) {
            add_action('init', [$this, 'init_websocket_server']);
        }
    }
    
    /**
     * Enqueue scripts for frontend
     */
    public function enqueue_scripts() {
        if (is_user_logged_in()) {
            wp_enqueue_script(
                'tbm-notifications',
                TBM_ASSETS_URL . 'js/notifications.js',
                ['jquery'],
                TBM_VERSION,
                true
            );
            
            wp_localize_script('tbm-notifications', 'tbmNotifications', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tbm_notifications_nonce'),
                'userId' => get_current_user_id(),
                'refreshInterval' => 30000, // 30 seconds
                'sounds' => [
                    'default' => TBM_ASSETS_URL . 'sounds/notification.mp3',
                    'success' => TBM_ASSETS_URL . 'sounds/success.mp3',
                    'warning' => TBM_ASSETS_URL . 'sounds/warning.mp3'
                ]
            ]);
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'tbm') !== false) {
            $this->enqueue_scripts();
        }
    }
    
    /**
     * Create notification
     */
    public function create_notification($data) {
        $defaults = [
            'user_id' => 0,
            'type' => 'system_update',
            'title' => '',
            'message' => '',
            'action_url' => '',
            'action_text' => '',
            'priority' => 'medium',
            'channel' => 'in_app',
            'status' => 'pending',
            'scheduled_at' => null,
            'data' => null
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['user_id']) || empty($data['title']) || empty($data['message'])) {
            return new WP_Error('invalid_data', 'User ID, title, and message are required');
        }
        
        // Sanitize data
        $data['title'] = sanitize_text_field($data['title']);
        $data['message'] = sanitize_textarea_field($data['message']);
        $data['action_url'] = esc_url_raw($data['action_url']);
        $data['action_text'] = sanitize_text_field($data['action_text']);
        
        // Validate enums
        if (!array_key_exists($data['type'], self::TYPES)) {
            $data['type'] = 'system_update';
        }
        
        if (!in_array($data['priority'], ['low', 'medium', 'high', 'urgent'])) {
            $data['priority'] = 'medium';
        }
        
        if (!array_key_exists($data['channel'], self::CHANNELS)) {
            $data['channel'] = 'in_app';
        }
        
        // Convert data to JSON if it's an array
        if (is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }
        
        // Set created timestamp
        $data['created_at'] = current_time('mysql');
        
        // Insert notification
        $result = $this->wpdb->insert($this->table, $data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create notification');
        }
        
        $notification_id = $this->wpdb->insert_id;
        
        // Send immediately if not scheduled
        if (empty($data['scheduled_at'])) {
            $this->send_notification($notification_id);
        }
        
        do_action('tbm_notification_created', $notification_id, $data);
        
        return $notification_id;
    }
    
    /**
     * Send notification immediately
     */
    public function send_notification($notification_id) {
        $notification = $this->get_notification($notification_id);
        
        if (!$notification) {
            return false;
        }
        
        $sent = false;
        
        switch ($notification->channel) {
            case 'email':
                $sent = $this->send_email_notification($notification);
                break;
                
            case 'sms':
                $sent = $this->send_sms_notification($notification);
                break;
                
            case 'push':
                $sent = $this->send_push_notification($notification);
                break;
                
            case 'in_app':
            default:
                $sent = $this->send_in_app_notification($notification);
                break;
        }
        
        // Update notification status
        $this->wpdb->update(
            $this->table,
            [
                'status' => $sent ? 'sent' : 'failed',
                'sent_at' => current_time('mysql')
            ],
            ['id' => $notification_id]
        );
        
        return $sent;
    }
    
    /**
     * Send email notification
     */
    private function send_email_notification($notification) {
        $user = get_userdata($notification->user_id);
        if (!$user) {
            return false;
        }
        
        $subject = $notification->title;
        $message = $this->format_email_message($notification);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        ];
        
        return wp_mail($user->user_email, $subject, $message, $headers);
    }
    
    /**
     * Format email message
     */
    private function format_email_message($notification) {
        $template = $this->get_email_template();
        
        $placeholders = [
            '{title}' => $notification->title,
            '{message}' => nl2br($notification->message),
            '{action_url}' => $notification->action_url,
            '{action_text}' => $notification->action_text ?: 'Voir détails',
            '{site_name}' => get_option('blogname'),
            '{site_url}' => home_url(),
            '{user_name}' => get_userdata($notification->user_id)->display_name,
            '{priority_class}' => 'priority-' . $notification->priority
        ];
        
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }
    
    /**
     * Get email template
     */
    private function get_email_template() {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{title}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
                .container { max-width: 600px; margin: 0 auto; background: white; }
                .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; text-align: center; }
                .content { padding: 2rem; }
                .priority-high, .priority-urgent { border-left: 4px solid #ef4444; }
                .priority-medium { border-left: 4px solid #f59e0b; }
                .priority-low { border-left: 4px solid #10b981; }
                .action-button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 1rem 0; }
                .footer { background: #f8f9fa; padding: 1rem; text-align: center; color: #6b7280; font-size: 0.9rem; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎓 {site_name}</h1>
                </div>
                <div class="content {priority_class}">
                    <h2>{title}</h2>
                    <p>Bonjour {user_name},</p>
                    <div>{message}</div>
                    {action_url ? <a href="{action_url}" class="action-button">{action_text}</a> : ""}
                </div>
                <div class="footer">
                    <p>© {site_name} - <a href="{site_url}">Visiter le site</a></p>
                </div>
            </div>
        </body>
        </html>';
    }
    
    /**
     * Send SMS notification
     */
    private function send_sms_notification($notification) {
        $user = get_userdata($notification->user_id);
        if (!$user) {
            return false;
        }
        
        $phone = get_user_meta($user->ID, 'phone', true);
        if (empty($phone)) {
            return false;
        }
        
        $message = strip_tags($notification->message);
        if (strlen($message) > 160) {
            $message = substr($message, 0, 157) . '...';
        }
        
        // Integration with SMS provider (Twilio, etc.)
        return $this->send_sms_via_provider($phone, $message);
    }
    
    /**
     * Send SMS via provider
     */
    private function send_sms_via_provider($phone, $message) {
        $provider = get_option('tbm_sms_provider', 'twilio');
        
        switch ($provider) {
            case 'twilio':
                return $this->send_sms_twilio($phone, $message);
                
            case 'nexmo':
                return $this->send_sms_nexmo($phone, $message);
                
            default:
                return false;
        }
    }
    
    /**
     * Send SMS via Twilio
     */
    private function send_sms_twilio($phone, $message) {
        $account_sid = get_option('tbm_twilio_sid');
        $auth_token = get_option('tbm_twilio_token');
        $from_number = get_option('tbm_twilio_from');
        
        if (empty($account_sid) || empty($auth_token) || empty($from_number)) {
            return false;
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($account_sid . ':' . $auth_token)
            ],
            'body' => [
                'From' => $from_number,
                'To' => $phone,
                'Body' => $message
            ]
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201;
    }
    
    /**
     * Send push notification
     */
    private function send_push_notification($notification) {
        // Integration with Firebase FCM or other push service
        $user_tokens = get_user_meta($notification->user_id, 'fcm_tokens', true);
        
        if (empty($user_tokens)) {
            return false;
        }
        
        $payload = [
            'title' => $notification->title,
            'body' => strip_tags($notification->message),
            'icon' => TBM_ASSETS_URL . 'images/icon-192x192.png',
            'badge' => TBM_ASSETS_URL . 'images/badge-72x72.png',
            'click_action' => $notification->action_url ?: home_url(),
            'data' => [
                'type' => $notification->type,
                'priority' => $notification->priority,
                'notification_id' => $notification->id
            ]
        ];
        
        return $this->send_fcm_notification($user_tokens, $payload);
    }
    
    /**
     * Send FCM notification
     */
    private function send_fcm_notification($tokens, $payload) {
        $server_key = get_option('tbm_fcm_server_key');
        if (empty($server_key)) {
            return false;
        }
        
        $headers = [
            'Authorization' => 'key=' . $server_key,
            'Content-Type' => 'application/json'
        ];
        
        $data = [
            'registration_ids' => is_array($tokens) ? $tokens : [$tokens],
            'notification' => $payload,
            'data' => $payload['data']
        ];
        
        $response = wp_remote_post('https://fcm.googleapis.com/fcm/send', [
            'headers' => $headers,
            'body' => json_encode($data)
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Send in-app notification
     */
    private function send_in_app_notification($notification) {
        // For in-app notifications, we just need to mark as sent
        // The frontend will poll for notifications
        
        // Trigger real-time update if WebSocket is available
        if (class_exists('TBM_WebSocket')) {
            TBM_WebSocket::broadcast_to_user($notification->user_id, [
                'type' => 'notification',
                'data' => $this->format_notification_for_frontend($notification)
            ]);
        }
        
        return true;
    }
    
    /**
     * Get notifications for user
     */
    public function get_user_notifications($user_id, $args = []) {
        $defaults = [
            'status' => '',
            'type' => '',
            'limit' => 50,
            'offset' => 0,
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where_conditions = ['user_id = %d'];
        $where_values = [$user_id];
        
        if (!empty($args['status'])) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $args['status'];
        }
        
        if (!empty($args['type'])) {
            $where_conditions[] = 'type = %s';
            $where_values[] = $args['type'];
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        $order_clause = 'ORDER BY created_at ' . $args['order'];
        $limit_clause = 'LIMIT ' . intval($args['limit']);
        
        if ($args['offset'] > 0) {
            $limit_clause .= ' OFFSET ' . intval($args['offset']);
        }
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} {$where_clause} {$order_clause} {$limit_clause}",
            $where_values
        );
        
        return $this->wpdb->get_results($query);
    }
    
    /**
     * Get unread notifications count
     */
    public function get_unread_count($user_id) {
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d AND status = 'sent' AND read_at IS NULL",
            $user_id
        ));
    }
    
    /**
     * Mark notification as read
     */
    public function mark_as_read($notification_id, $user_id = null) {
        $where = ['id' => $notification_id];
        
        if ($user_id) {
            $where['user_id'] = $user_id;
        }
        
        return $this->wpdb->update(
            $this->table,
            ['read_at' => current_time('mysql')],
            $where
        ) !== false;
    }
    
    /**
     * Mark all notifications as read for user
     */
    public function mark_all_as_read($user_id) {
        return $this->wpdb->update(
            $this->table,
            ['read_at' => current_time('mysql')],
            [
                'user_id' => $user_id,
                'read_at' => null
            ]
        ) !== false;
    }
    
    /**
     * AJAX: Get notifications
     */
    public function ajax_get_notifications() {
        check_ajax_referer('tbm_notifications_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }
        
        $args = [
            'limit' => intval($_POST['limit'] ?? 20),
            'offset' => intval($_POST['offset'] ?? 0),
            'status' => sanitize_text_field($_POST['status'] ?? '')
        ];
        
        $notifications = $this->get_user_notifications($user_id, $args);
        $unread_count = $this->get_unread_count($user_id);
        
        $formatted_notifications = array_map([$this, 'format_notification_for_frontend'], $notifications);
        
        wp_send_json_success([
            'notifications' => $formatted_notifications,
            'unread_count' => $unread_count,
            'has_more' => count($notifications) === $args['limit']
        ]);
    }
    
    /**
     * AJAX: Mark notification as read
     */
    public function ajax_mark_notification_read() {
        check_ajax_referer('tbm_notifications_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        if (!$user_id || !$notification_id) {
            wp_send_json_error('Invalid data');
        }
        
        $success = $this->mark_as_read($notification_id, $user_id);
        
        if ($success) {
            $unread_count = $this->get_unread_count($user_id);
            wp_send_json_success(['unread_count' => $unread_count]);
        } else {
            wp_send_json_error('Failed to mark as read');
        }
    }
    
    /**
     * AJAX: Mark all notifications as read
     */
    public function ajax_mark_all_notifications_read() {
        check_ajax_referer('tbm_notifications_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }
        
        $success = $this->mark_all_as_read($user_id);
        
        if ($success) {
            wp_send_json_success(['unread_count' => 0]);
        } else {
            wp_send_json_error('Failed to mark all as read');
        }
    }
    
    /**
     * Format notification for frontend
     */
    private function format_notification_for_frontend($notification) {
        return [
            'id' => (int) $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'action_text' => $notification->action_text,
            'priority' => $notification->priority,
            'is_read' => !empty($notification->read_at),
            'created_at' => $notification->created_at,
            'read_at' => $notification->read_at,
            'data' => json_decode($notification->data, true)
        ];
    }
    
    /**
     * Get single notification
     */
    public function get_notification($notification_id) {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $notification_id
        ));
    }
    
    /**
     * Process scheduled notifications
     */
    public function process_scheduled_notifications() {
        $scheduled_notifications = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id FROM {$this->table} 
             WHERE status = 'pending' 
             AND scheduled_at IS NOT NULL 
             AND scheduled_at <= %s",
            current_time('mysql')
        ));
        
        foreach ($scheduled_notifications as $notification) {
            $this->send_notification($notification->id);
        }
    }
    
    /**
     * Send daily reminders
     */
    public function send_daily_reminders() {
        // Session reminders (24h before)
        $this->send_session_reminders();
        
        // Profile completion reminders
        $this->send_profile_completion_reminders();
        
        // Payment reminders
        $this->send_payment_reminders();
    }
    
    /**
     * Send session reminders
     */
    private function send_session_reminders() {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'tbm_sessions';
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, t.user_id, t.first_name, t.last_name 
             FROM {$sessions_table} s
             JOIN {$trainers_table} t ON s.trainer_id = t.id
             WHERE DATE(s.start_datetime) = %s 
             AND s.status = 'scheduled'",
            $tomorrow
        ));
        
        foreach ($sessions as $session) {
            $this->create_notification([
                'user_id' => $session->user_id,
                'type' => 'session_reminder',
                'title' => 'Rappel de session demain',
                'message' => "N'oubliez pas votre session \"{$session->title}\" prévue demain à " . date('H:i', strtotime($session->start_datetime)),
                'action_url' => admin_url('admin.php?page=tbm-sessions&session_id=' . $session->id),
                'action_text' => 'Voir la session',
                'priority' => 'medium',
                'channel' => 'email'
            ]);
        }
    }
    
    /**
     * Send profile completion reminders
     */
    private function send_profile_completion_reminders() {
        global $wpdb;
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $incomplete_profiles = $wpdb->get_results(
            "SELECT user_id, first_name, last_name, profile_completion 
             FROM {$trainers_table} 
             WHERE profile_completion < 80 
             AND status = 'active'"
        );
        
        foreach ($incomplete_profiles as $trainer) {
            // Check if reminder was sent recently
            $recent_reminder = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->table} 
                 WHERE user_id = %d 
                 AND type = 'profile_incomplete' 
                 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $trainer->user_id
            ));
            
            if (!$recent_reminder) {
                $this->create_notification([
                    'user_id' => $trainer->user_id,
                    'type' => 'profile_incomplete',
                    'title' => 'Complétez votre profil',
                    'message' => "Votre profil est complété à {$trainer->profile_completion}%. Finalisez-le pour recevoir plus d'opportunités.",
                    'action_url' => admin_url('admin.php?page=tbm-trainer-profile'),
                    'action_text' => 'Compléter le profil',
                    'priority' => 'low',
                    'channel' => 'in_app'
                ]);
            }
        }
    }
    
    /**
     * Send payment reminders
     */
    private function send_payment_reminders() {
        global $wpdb;
        $payments_table = $wpdb->prefix . 'tbm_payments';
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $overdue_payments = $wpdb->get_results(
            "SELECT p.*, t.user_id, t.first_name, t.last_name 
             FROM {$payments_table} p
             JOIN {$trainers_table} t ON p.trainer_id = t.id
             WHERE p.status = 'pending' 
             AND p.due_date < CURDATE()"
        );
        
        foreach ($overdue_payments as $payment) {
            $this->create_notification([
                'user_id' => $payment->user_id,
                'type' => 'payment_overdue',
                'title' => 'Paiement en retard',
                'message' => "Votre paiement de {$payment->amount} {$payment->currency} est en retard depuis le " . date('d/m/Y', strtotime($payment->due_date)),
                'action_url' => admin_url('admin.php?page=tbm-payments&payment_id=' . $payment->id),
                'action_text' => 'Voir le paiement',
                'priority' => 'high',
                'channel' => 'email'
            ]);
        }
    }
    
    // Event handlers
    
    /**
     * Handle application submitted
     */
    public function on_application_submitted($application_id) {
        global $wpdb;
        $applications_table = $wpdb->prefix . 'tbm_applications';
        
        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$applications_table} WHERE id = %d",
            $application_id
        ));
        
        if (!$application) {
            return;
        }
        
        // Notify admin
        $admin_users = get_users(['role' => 'administrator']);
        foreach ($admin_users as $admin) {
            $this->create_notification([
                'user_id' => $admin->ID,
                'type' => 'application_submitted',
                'title' => 'Nouvelle candidature',
                'message' => "Nouvelle candidature de {$application->first_name} {$application->last_name} ({$application->expertise_areas})",
                'action_url' => admin_url('admin.php?page=tbm-applications&application_id=' . $application_id),
                'action_text' => 'Examiner la candidature',
                'priority' => 'medium',
                'channel' => 'email'
            ]);
        }
    }
    
    /**
     * Handle application status change
     */
    public function on_application_status_changed($application_id, $old_status, $new_status) {
        global $wpdb;
        $applications_table = $wpdb->prefix . 'tbm_applications';
        
        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$applications_table} WHERE id = %d",
            $application_id
        ));
        
        if (!$application) {
            return;
        }
        
        // Find user by email
        $user = get_user_by('email', $application->email);
        if (!$user) {
            return;
        }
        
        $messages = [
            'approved' => 'Félicitations ! Votre candidature a été approuvée. Bienvenue dans notre équipe !',
            'rejected' => 'Nous vous remercions pour votre candidature. Malheureusement, nous ne pouvons pas donner suite à votre demande pour le moment.',
            'interview_scheduled' => 'Votre candidature a retenu notre attention. Un entretien sera programmé prochainement.'
        ];
        
        if (isset($messages[$new_status])) {
            $this->create_notification([
                'user_id' => $user->ID,
                'type' => 'application_' . $new_status,
                'title' => 'Mise à jour de votre candidature',
                'message' => $messages[$new_status],
                'priority' => $new_status === 'approved' ? 'high' : 'medium',
                'channel' => 'email'
            ]);
        }
    }
    
    /**
     * Handle session created
     */
    public function on_session_created($session_id) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'tbm_sessions';
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, t.user_id, t.first_name, t.last_name 
             FROM {$sessions_table} s
             JOIN {$trainers_table} t ON s.trainer_id = t.id
             WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session) {
            return;
        }
        
        $this->create_notification([
            'user_id' => $session->user_id,
            'type' => 'session_scheduled',
            'title' => 'Nouvelle session planifiée',
            'message' => "Une nouvelle session \"{$session->title}\" a été planifiée pour le " . date('d/m/Y à H:i', strtotime($session->start_datetime)),
            'action_url' => admin_url('admin.php?page=tbm-sessions&session_id=' . $session_id),
            'action_text' => 'Voir la session',
            'priority' => 'medium',
            'channel' => 'in_app'
        ]);
    }
    
    /**
     * Handle payment processed
     */
    public function on_payment_processed($payment_id) {
        global $wpdb;
        $payments_table = $wpdb->prefix . 'tbm_payments';
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.user_id, t.first_name, t.last_name 
             FROM {$payments_table} p
             JOIN {$trainers_table} t ON p.trainer_id = t.id
             WHERE p.id = %d",
            $payment_id
        ));
        
        if (!$payment) {
            return;
        }
        
        $this->create_notification([
            'user_id' => $payment->user_id,
            'type' => 'payment_processed',
            'title' => 'Paiement traité',
            'message' => "Votre paiement de {$payment->amount} {$payment->currency} a été traité avec succès",
            'action_url' => admin_url('admin.php?page=tbm-payments&payment_id=' . $payment_id),
            'action_text' => 'Voir le paiement',
            'priority' => 'medium',
            'channel' => 'email'
        ]);
    }
}