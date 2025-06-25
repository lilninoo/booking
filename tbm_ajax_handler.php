<?php
/**
 * AJAX Handler Class
 * 
 * Handles all AJAX requests for the plugin
 * 
 * @package TrainerBootcampManager
 * @subpackage Public
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_AJAX_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->register_ajax_actions();
    }
    
    /**
     * Register AJAX actions
     */
    private function register_ajax_actions() {
        // Public AJAX actions (non-logged users)
        add_action('wp_ajax_nopriv_tbm_search_trainers', [$this, 'search_trainers']);
        add_action('wp_ajax_nopriv_tbm_get_trainer_details', [$this, 'get_trainer_details']);
        add_action('wp_ajax_nopriv_tbm_submit_application', [$this, 'submit_application']);
        add_action('wp_ajax_nopriv_tbm_contact_trainer', [$this, 'contact_trainer']);
        add_action('wp_ajax_nopriv_tbm_get_bootcamps', [$this, 'get_bootcamps']);
        
        // Logged user AJAX actions
        add_action('wp_ajax_tbm_search_trainers', [$this, 'search_trainers']);
        add_action('wp_ajax_tbm_get_trainer_details', [$this, 'get_trainer_details']);
        add_action('wp_ajax_tbm_submit_application', [$this, 'submit_application']);
        add_action('wp_ajax_tbm_contact_trainer', [$this, 'contact_trainer']);
        add_action('wp_ajax_tbm_get_bootcamps', [$this, 'get_bootcamps']);
        
        // Admin/Trainer specific actions
        add_action('wp_ajax_tbm_update_trainer_profile', [$this, 'update_trainer_profile']);
        add_action('wp_ajax_tbm_upload_document', [$this, 'upload_document']);
        add_action('wp_ajax_tbm_get_trainer_stats', [$this, 'get_trainer_stats']);
        add_action('wp_ajax_tbm_update_availability', [$this, 'update_availability']);
        add_action('wp_ajax_tbm_get_notifications', [$this, 'get_notifications']);
        add_action('wp_ajax_tbm_mark_notification_read', [$this, 'mark_notification_read']);
        
        // Admin only actions
        add_action('wp_ajax_tbm_approve_application', [$this, 'approve_application']);
        add_action('wp_ajax_tbm_reject_application', [$this, 'reject_application']);
        add_action('wp_ajax_tbm_get_dashboard_stats', [$this, 'get_dashboard_stats']);
        add_action('wp_ajax_tbm_create_session', [$this, 'create_session']);
        add_action('wp_ajax_tbm_update_session', [$this, 'update_session']);
        add_action('wp_ajax_tbm_delete_session', [$this, 'delete_session']);
    }
    
    /**
     * Search trainers AJAX handler
     */
    public function search_trainers() {
        try {
            // Verify nonce for logged users
            if (is_user_logged_in()) {
                check_ajax_referer('tbm_public_nonce', 'nonce');
            }
            
            // Rate limiting
            $security = new TBM_Security();
            if (!$security->check_rate_limit('api_requests')) {
                wp_send_json_error(['message' => 'Trop de requêtes. Veuillez patienter.'], 429);
            }
            
            // Get parameters
            $search = sanitize_text_field($_POST['search'] ?? '');
            $expertise = sanitize_text_field($_POST['expertise'] ?? '');
            $country = sanitize_text_field($_POST['country'] ?? '');
            $city = sanitize_text_field($_POST['city'] ?? '');
            $page = absint($_POST['page'] ?? 1);
            $per_page = min(absint($_POST['per_page'] ?? 12), 50); // Max 50
            $featured = rest_sanitize_boolean($_POST['featured'] ?? false);
            
            // Use public class method
            $public = new TBM_Public();
            $result = $public->search_trainers([
                'search' => $search,
                'expertise' => $expertise,
                'country' => $country,
                'city' => $city,
                'page' => $page,
                'per_page' => $per_page,
                'featured' => $featured
            ]);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            TBM_Utilities::log_activity('ajax_error', 'search_trainers', 0, $e->getMessage());
            wp_send_json_error(['message' => 'Erreur lors de la recherche.'], 500);
        }
    }
    
    /**
     * Get trainer details AJAX handler
     */
    public function get_trainer_details() {
        try {
            $trainer_id = absint($_POST['trainer_id'] ?? 0);
            
            if (!$trainer_id) {
                wp_send_json_error(['message' => 'ID formateur manquant.'], 400);
            }
            
            $public = new TBM_Public();
            $trainer = $public->get_trainer_data($trainer_id);
            
            if (!$trainer) {
                wp_send_json_error(['message' => 'Formateur non trouvé.'], 404);
            }
            
            wp_send_json_success(['trainer' => $trainer]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération.'], 500);
        }
    }
    
    /**
     * Submit application AJAX handler
     */
    public function submit_application() {
        try {
            // Rate limiting
            $security = new TBM_Security();
            if (!$security->check_rate_limit('application_submit', TBM_Utilities::get_client_ip())) {
                wp_send_json_error(['message' => 'Trop de candidatures. Veuillez patienter 1 heure.'], 429);
            }
            
            // Validate honeypot
            if (apply_filters('tbm_validate_form', true, $_POST) === false) {
                wp_send_json_error(['message' => 'Requête invalide.'], 400);
            }
            
            // Sanitize and validate data
            $data = [
                'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                'country' => sanitize_text_field($_POST['country'] ?? ''),
                'city' => sanitize_text_field($_POST['city'] ?? ''),
                'expertise' => sanitize_text_field($_POST['expertise'] ?? ''),
                'skills' => sanitize_textarea_field($_POST['skills'] ?? ''),
                'experience_years' => absint($_POST['experience_years'] ?? 0),
                'motivation' => sanitize_textarea_field($_POST['motivation'] ?? ''),
                'portfolio_url' => esc_url_raw($_POST['portfolio_url'] ?? ''),
                'linkedin_url' => esc_url_raw($_POST['linkedin_url'] ?? ''),
                'expected_rate' => floatval($_POST['expected_rate'] ?? 0),
                'currency' => sanitize_text_field($_POST['currency'] ?? 'EUR'),
                'availability' => sanitize_textarea_field($_POST['availability'] ?? '')
            ];
            
            // Validation rules
            $errors = [];
            
            if (empty($data['first_name'])) {
                $errors[] = 'Le prénom est requis.';
            }
            
            if (empty($data['last_name'])) {
                $errors[] = 'Le nom est requis.';
            }
            
            if (empty($data['email']) || !is_email($data['email'])) {
                $errors[] = 'Un email valide est requis.';
            }
            
            if (empty($data['country'])) {
                $errors[] = 'Le pays est requis.';
            }
            
            if (empty($data['expertise'])) {
                $errors[] = 'Le domaine d\'expertise est requis.';
            }
            
            if ($data['experience_years'] < 1) {
                $errors[] = 'Au moins 1 année d\'expérience est requise.';
            }
            
            if (strlen($data['motivation']) < 100) {
                $errors[] = 'La motivation doit contenir au moins 100 caractères.';
            }
            
            if (!empty($errors)) {
                wp_send_json_error(['message' => 'Données invalides.', 'errors' => $errors], 400);
            }
            
            // Check for existing application
            global $wpdb;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tbm_applications WHERE email = %s AND status != 'rejected'",
                $data['email']
            ));
            
            if ($existing) {
                wp_send_json_error(['message' => 'Une candidature avec cet email existe déjà.'], 409);
            }
            
            // Submit via REST API
            $response = wp_remote_post(rest_url('tbm/v1/applications'), [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($data),
                'timeout' => 30
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($status_code === 201) {
                wp_send_json_success([
                    'message' => 'Candidature soumise avec succès !',
                    'application_number' => $body['application_number'] ?? ''
                ]);
            } else {
                wp_send_json_error(['message' => $body['message'] ?? 'Erreur lors de la soumission.'], $status_code);
            }
            
        } catch (Exception $e) {
            TBM_Utilities::log_activity('ajax_error', 'submit_application', 0, $e->getMessage());
            wp_send_json_error(['message' => 'Erreur serveur. Veuillez réessayer.'], 500);
        }
    }
    
    /**
     * Contact trainer AJAX handler
     */
    public function contact_trainer() {
        try {
            check_ajax_referer('tbm_public_nonce', 'nonce');
            
            $trainer_id = absint($_POST['trainer_id'] ?? 0);
            $name = sanitize_text_field($_POST['name'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');
            $message = sanitize_textarea_field($_POST['message'] ?? '');
            
            if (!$trainer_id || !$name || !$email || !$message) {
                wp_send_json_error(['message' => 'Tous les champs sont requis.'], 400);
            }
            
            if (!is_email($email)) {
                wp_send_json_error(['message' => 'Email invalide.'], 400);
            }
            
            // Get trainer email
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT email, first_name, last_name FROM {$wpdb->prefix}tbm_trainers WHERE id = %d AND status = 'active'",
                $trainer_id
            ));
            
            if (!$trainer) {
                wp_send_json_error(['message' => 'Formateur non trouvé.'], 404);
            }
            
            // Send email to trainer
            $subject = "Message de {$name} via la plateforme";
            $email_message = "Vous avez reçu un nouveau message :\n\n";
            $email_message .= "De : {$name} ({$email})\n";
            $email_message .= "Message :\n{$message}\n\n";
            $email_message .= "Vous pouvez répondre directement à cette adresse email.";
            
            $headers = [
                'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
                'Reply-To: ' . $email
            ];
            
            if (wp_mail($trainer->email, $subject, $email_message, $headers)) {
                // Log activity
                TBM_Utilities::log_activity('trainer_contacted', 'trainer', $trainer_id, "Par {$name} ({$email})");
                
                wp_send_json_success(['message' => 'Message envoyé avec succès !']);
            } else {
                wp_send_json_error(['message' => 'Erreur lors de l\'envoi.'], 500);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur serveur.'], 500);
        }
    }
    
    /**
     * Get bootcamps AJAX handler
     */
    public function get_bootcamps() {
        try {
            $page = absint($_POST['page'] ?? 1);
            $per_page = min(absint($_POST['per_page'] ?? 12), 50);
            $category = sanitize_text_field($_POST['category'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? 'scheduled,active');
            
            global $wpdb;
            $table = $wpdb->prefix . 'tbm_bootcamps';
            
            $where_conditions = ["status IN ('" . implode("','", explode(',', $status)) . "')"];
            $where_values = [];
            
            if (!empty($category)) {
                $where_conditions[] = 'category = %s';
                $where_values[] = $category;
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            $offset = ($page - 1) * $per_page;
            
            // Count total
            $count_query = "SELECT COUNT(*) FROM {$table} {$where_clause}";
            if (!empty($where_values)) {
                $count_query = $wpdb->prepare($count_query, $where_values);
            }
            $total = $wpdb->get_var($count_query);
            
            // Get bootcamps
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} {$where_clause} ORDER BY start_date ASC LIMIT %d OFFSET %d",
                array_merge($where_values, [$per_page, $offset])
            );
            
            $bootcamps = $wpdb->get_results($query);
            
            wp_send_json_success([
                'bootcamps' => $bootcamps,
                'pagination' => [
                    'total' => (int) $total,
                    'pages' => ceil($total / $per_page),
                    'current_page' => $page,
                    'per_page' => $per_page
                ]
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération.'], 500);
        }
    }
    
    /**
     * Update trainer profile AJAX handler
     */
    public function update_trainer_profile() {
        try {
            check_ajax_referer('tbm_public_nonce', 'nonce');
            
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Connexion requise.'], 401);
            }
            
            $user_management = new TBM_User_Management();
            $trainer = $user_management->get_trainer_by_user_id(get_current_user_id());
            
            if (!$trainer) {
                wp_send_json_error(['message' => 'Profil formateur non trouvé.'], 404);
            }
            
            // Sanitize data
            $data = [
                'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
                'expertise_areas' => sanitize_text_field($_POST['expertise_areas'] ?? ''),
                'skills' => sanitize_textarea_field($_POST['skills'] ?? ''),
                'hourly_rate' => floatval($_POST['hourly_rate'] ?? 0),
                'languages' => sanitize_text_field($_POST['languages'] ?? ''),
                'portfolio_url' => esc_url_raw($_POST['portfolio_url'] ?? ''),
                'linkedin_url' => esc_url_raw($_POST['linkedin_url'] ?? ''),
                'website_url' => esc_url_raw($_POST['website_url'] ?? '')
            ];
            
            // Update via REST API
            $response = wp_remote_request(rest_url("tbm/v1/trainers/{$trainer->id}/profile"), [
                'method' => 'PUT',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WP-Nonce' => wp_create_nonce('wp_rest')
                ],
                'body' => json_encode($data)
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code === 200) {
                // Recalculate profile completion
                $completion = $user_management->calculate_profile_completion($trainer->id);
                
                wp_send_json_success([
                    'message' => 'Profil mis à jour avec succès !',
                    'completion' => $completion
                ]);
            } else {
                wp_send_json_error(['message' => 'Erreur lors de la mise à jour.'], $status_code);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur serveur.'], 500);
        }
    }
    
    /**
     * Upload document AJAX handler
     */
    public function upload_document() {
        try {
            check_ajax_referer('tbm_public_nonce', 'nonce');
            
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Connexion requise.'], 401);
            }
            
            if (empty($_FILES['file'])) {
                wp_send_json_error(['message' => 'Aucun fichier sélectionné.'], 400);
            }
            
            $file = $_FILES['file'];
            $document_type = sanitize_text_field($_POST['document_type'] ?? 'other');
            
            // Validate file
            $result = TBM_Utilities::upload_file($file, 'trainers', true);
            
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            }
            
            // Save document info
            global $wpdb;
            $user_management = new TBM_User_Management();
            $trainer = $user_management->get_trainer_by_user_id(get_current_user_id());
            
            $document_data = [
                'title' => sanitize_text_field($_POST['title'] ?? $result['file_name']),
                'file_name' => $result['file_name'],
                'file_path' => $result['file_path'],
                'file_type' => $result['file_type'],
                'file_size' => $result['file_size'],
                'mime_type' => $result['mime_type'],
                'document_type' => $document_type,
                'owner_id' => get_current_user_id(),
                'owner_type' => 'trainer',
                'trainer_id' => $trainer ? $trainer->id : null,
                'is_private' => 1,
                'created_at' => current_time('mysql')
            ];
            
            $wpdb->insert($wpdb->prefix . 'tbm_documents', $document_data);
            $document_id = $wpdb->insert_id;
            
            wp_send_json_success([
                'message' => 'Document téléchargé avec succès !',
                'document_id' => $document_id,
                'file_name' => $result['file_name'],
                'file_size' => TBM_Utilities::format_file_size($result['file_size'])
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors du téléchargement.'], 500);
        }
    }
    
    /**
     * Get trainer stats AJAX handler
     */
    public function get_trainer_stats() {
        try {
            check_ajax_referer('tbm_public_nonce', 'nonce');
            
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Connexion requise.'], 401);
            }
            
            $user_management = new TBM_User_Management();
            $trainer = $user_management->get_trainer_by_user_id(get_current_user_id());
            
            if (!$trainer) {
                wp_send_json_error(['message' => 'Profil formateur non trouvé.'], 404);
            }
            
            global $wpdb;
            
            // Get sessions count
            $sessions_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tbm_sessions WHERE trainer_id = %d AND status = 'completed'",
                $trainer->id
            ));
            
            // Get total hours
            $total_hours = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(duration_minutes)/60 FROM {$wpdb->prefix}tbm_sessions WHERE trainer_id = %d AND status = 'completed'",
                $trainer->id
            ));
            
            // Get earnings this month
            $current_month_earnings = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(net_amount) FROM {$wpdb->prefix}tbm_payments 
                 WHERE trainer_id = %d AND status = 'completed' AND MONTH(paid_date) = MONTH(NOW()) AND YEAR(paid_date) = YEAR(NOW())",
                $trainer->id
            ));
            
            $stats = [
                'sessions_completed' => (int) $sessions_count,
                'total_hours' => round((float) $total_hours, 1),
                'current_month_earnings' => (float) $current_month_earnings,
                'rating_average' => (float) $trainer->rating_average,
                'rating_count' => (int) $trainer->rating_count,
                'profile_completion' => (int) $trainer->profile_completion
            ];
            
            wp_send_json_success(['stats' => $stats]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération.'], 500);
        }
    }
    
    /**
     * Get notifications AJAX handler
     */
    public function get_notifications() {
        try {
            check_ajax_referer('tbm_public_nonce', 'nonce');
            
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Connexion requise.'], 401);
            }
            
            $notifications = new TBM_Notifications();
            $user_id = get_current_user_id();
            
            $limit = min(absint($_POST['limit'] ?? 20), 50);
            $status = sanitize_text_field($_POST['status'] ?? '');
            
            $user_notifications = $notifications->get_user_notifications($user_id, [
                'limit' => $limit,
                'status' => $status
            ]);
            
            $unread_count = $notifications->get_unread_count($user_id);
            
            wp_send_json_success([
                'notifications' => $user_notifications,
                'unread_count' => $unread_count
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération.'], 500);
        }
    }
    
    /**
     * Mark notification as read AJAX handler
     */
    public function mark_notification_read() {
        try {
            check_ajax_referer('tbm_public_nonce', 'nonce');
            
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Connexion requise.'], 401);
            }
            
            $notification_id = absint($_POST['notification_id'] ?? 0);
            
            if (!$notification_id) {
                wp_send_json_error(['message' => 'ID notification manquant.'], 400);
            }
            
            $notifications = new TBM_Notifications();
            $success = $notifications->mark_as_read($notification_id, get_current_user_id());
            
            if ($success) {
                $unread_count = $notifications->get_unread_count(get_current_user_id());
                wp_send_json_success(['unread_count' => $unread_count]);
            } else {
                wp_send_json_error(['message' => 'Erreur lors de la mise à jour.'], 500);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur serveur.'], 500);
        }
    }
    
    /**
     * Approve application AJAX handler (Admin only)
     */
    public function approve_application() {
        try {
            check_ajax_referer('tbm_admin_nonce', 'nonce');
            
            if (!current_user_can('edit_tbm_applications')) {
                wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
            }
            
            $application_id = absint($_POST['application_id'] ?? 0);
            $notes = sanitize_textarea_field($_POST['notes'] ?? '');
            
            if (!$application_id) {
                wp_send_json_error(['message' => 'ID candidature manquant.'], 400);
            }
            
            // Update via REST API
            $response = wp_remote_request(rest_url("tbm/v1/applications/{$application_id}/status"), [
                'method' => 'PUT',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WP-Nonce' => wp_create_nonce('wp_rest')
                ],
                'body' => json_encode([
                    'status' => 'approved',
                    'notes' => $notes
                ])
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            wp_send_json_success(['message' => 'Candidature approuvée avec succès !']);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de l\'approbation.'], 500);
        }
    }
    
    /**
     * Reject application AJAX handler (Admin only)
     */
    public function reject_application() {
        try {
            check_ajax_referer('tbm_admin_nonce', 'nonce');
            
            if (!current_user_can('edit_tbm_applications')) {
                wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
            }
            
            $application_id = absint($_POST['application_id'] ?? 0);
            $notes = sanitize_textarea_field($_POST['notes'] ?? '');
            
            if (!$application_id) {
                wp_send_json_error(['message' => 'ID candidature manquant.'], 400);
            }
            
            // Update via REST API
            $response = wp_remote_request(rest_url("tbm/v1/applications/{$application_id}/status"), [
                'method' => 'PUT',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WP-Nonce' => wp_create_nonce('wp_rest')
                ],
                'body' => json_encode([
                    'status' => 'rejected',
                    'notes' => $notes
                ])
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            wp_send_json_success(['message' => 'Candidature rejetée.']);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors du rejet.'], 500);
        }
    }
    
    /**
     * Get dashboard stats AJAX handler (Admin only)
     */
    public function get_dashboard_stats() {
        try {
            check_ajax_referer('tbm_admin_nonce', 'nonce');
            
            if (!current_user_can('edit_tbm_trainers')) {
                wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
            }
            
            $analytics = new TBM_Analytics();
            $period = sanitize_text_field($_POST['period'] ?? '30_days');
            
            $dashboard_data = $analytics->get_dashboard_analytics($period);
            
            wp_send_json_success($dashboard_data);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération.'], 500);
        }
    }
    
    /**
     * Create session AJAX handler
     */
    public function create_session() {
        try {
            check_ajax_referer('tbm_admin_nonce', 'nonce');
            
            if (!current_user_can('edit_tbm_sessions')) {
                wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
            }
            
            $calendar = new TBM_Calendar();
            
            $data = [
                'bootcamp_id' => absint($_POST['bootcamp_id'] ?? 0),
                'trainer_id' => absint($_POST['trainer_id'] ?? 0),
                'title' => sanitize_text_field($_POST['title'] ?? ''),
                'description' => sanitize_textarea_field($_POST['description'] ?? ''),
                'start_datetime' => sanitize_text_field($_POST['start_datetime'] ?? ''),
                'end_datetime' => sanitize_text_field($_POST['end_datetime'] ?? ''),
                'location' => sanitize_text_field($_POST['location'] ?? ''),
                'format' => sanitize_text_field($_POST['format'] ?? 'online')
            ];
            
            $result = $calendar->create_calendar_event($data);
            
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            }
            
            wp_send_json_success([
                'message' => 'Session créée avec succès !',
                'session_id' => $result
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la création.'], 500);
        }
    }
    
    /**
     * Update session AJAX handler
     */
    public function update_session() {
        try {
            check_ajax_referer('tbm_admin_nonce', 'nonce');
            
            if (!current_user_can('edit_tbm_sessions')) {
                wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
            }
            
            $session_id = absint($_POST['session_id'] ?? 0);
            
            if (!$session_id) {
                wp_send_json_error(['message' => 'ID session manquant.'], 400);
            }
            
            $calendar = new TBM_Calendar();
            
            $data = [];
            $fields = ['title', 'description', 'start_datetime', 'end_datetime', 'location', 'format', 'status'];
            
            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $data[$field] = sanitize_text_field($_POST[$field]);
                }
            }
            
            $result = $calendar->update_calendar_event($session_id, $data);
            
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            }
            
            wp_send_json_success(['message' => 'Session mise à jour avec succès !']);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la mise à jour.'], 500);
        }
    }
    
    /**
     * Delete session AJAX handler
     */
    public function delete_session() {
        try {
            check_ajax_referer('tbm_admin_nonce', 'nonce');
            
            if (!current_user_can('delete_tbm_sessions')) {
                wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
            }
            
            $session_id = absint($_POST['session_id'] ?? 0);
            
            if (!$session_id) {
                wp_send_json_error(['message' => 'ID session manquant.'], 400);
            }
            
            $calendar = new TBM_Calendar();
            $result = $calendar->delete_calendar_event($session_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            }
            
            wp_send_json_success(['message' => 'Session supprimée avec succès !']);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur lors de la suppression.'], 500);
        }
    }
}