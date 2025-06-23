<?php
/**
 * REST API Controller
 * 
 * Handles all REST API endpoints for the Trainer Bootcamp Manager plugin
 * 
 * @package TrainerBootcampManager
 * @subpackage API
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_API {
    
    /**
     * API namespace
     */
    const NAMESPACE = 'tbm/v1';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_pre_dispatch', [$this, 'add_cors_headers'], 10, 3);
    }
    
    /**
     * Register all REST API routes
     */
    public function register_routes() {
        // Trainers endpoints
        $this->register_trainers_routes();
        
        // Applications endpoints
        $this->register_applications_routes();
        
        // Bootcamps endpoints
        $this->register_bootcamps_routes();
        
        // Sessions endpoints
        $this->register_sessions_routes();
        
        // Calendar endpoints
        $this->register_calendar_routes();
        
        // Notifications endpoints
        $this->register_notifications_routes();
        
        // Analytics endpoints
        $this->register_analytics_routes();
        
        // Documents endpoints
        $this->register_documents_routes();
        
        // Payments endpoints
        $this->register_payments_routes();
    }
    
    /**
     * Register trainers routes
     */
    private function register_trainers_routes() {
        // Get all trainers (public)
        register_rest_route(self::NAMESPACE, '/trainers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trainers'],
            'permission_callback' => '__return_true',
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint'
                ],
                'per_page' => [
                    'default' => 12,
                    'sanitize_callback' => 'absint'
                ],
                'search' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'expertise' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'country' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'city' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'status' => [
                    'default' => 'active',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'featured' => [
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean'
                ]
            ]
        ]);
        
        // Get single trainer (public, limited info)
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trainer'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);
        
        // Get trainer full profile (authenticated)
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/profile', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trainer_profile'],
            'permission_callback' => [$this, 'check_trainer_access'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);
        
        // Update trainer profile
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/profile', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_trainer_profile'],
            'permission_callback' => [$this, 'check_trainer_edit_access'],
            'args' => $this->get_trainer_profile_schema()
        ]);
        
        // Get trainer statistics
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trainer_stats'],
            'permission_callback' => [$this, 'check_trainer_access']
        ]);
        
        // Get trainer availability
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/availability', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trainer_availability'],
            'permission_callback' => [$this, 'check_trainer_access']
        ]);
        
        // Update trainer availability
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/availability', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_trainer_availability'],
            'permission_callback' => [$this, 'check_trainer_edit_access']
        ]);
    }
    
    /**
     * Register applications routes
     */
    private function register_applications_routes() {
        // Submit new application
        register_rest_route(self::NAMESPACE, '/applications', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit_application'],
            'permission_callback' => '__return_true',
            'args' => $this->get_application_schema()
        ]);
        
        // Get applications (admin only)
        register_rest_route(self::NAMESPACE, '/applications', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_applications'],
            'permission_callback' => [$this, 'check_admin_access'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint'
                ],
                'per_page' => [
                    'default' => 20,
                    'sanitize_callback' => 'absint'
                ],
                'status' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // Get single application
        register_rest_route(self::NAMESPACE, '/applications/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_application'],
            'permission_callback' => [$this, 'check_application_access']
        ]);
        
        // Update application status
        register_rest_route(self::NAMESPACE, '/applications/(?P<id>\d+)/status', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_application_status'],
            'permission_callback' => [$this, 'check_admin_access'],
            'args' => [
                'status' => [
                    'required' => true,
                    'enum' => ['reviewing', 'interview_scheduled', 'approved', 'rejected']
                ],
                'notes' => [
                    'sanitize_callback' => 'sanitize_textarea_field'
                ]
            ]
        ]);
    }
    
    /**
     * Register bootcamps routes
     */
    private function register_bootcamps_routes() {
        // Get bootcamps
        register_rest_route(self::NAMESPACE, '/bootcamps', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_bootcamps'],
            'permission_callback' => [$this, 'check_bootcamp_access'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint'
                ],
                'per_page' => [
                    'default' => 20,
                    'sanitize_callback' => 'absint'
                ],
                'status' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // Create bootcamp
        register_rest_route(self::NAMESPACE, '/bootcamps', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_bootcamp'],
            'permission_callback' => [$this, 'check_bootcamp_create_access'],
            'args' => $this->get_bootcamp_schema()
        ]);
        
        // Get single bootcamp
        register_rest_route(self::NAMESPACE, '/bootcamps/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_bootcamp'],
            'permission_callback' => [$this, 'check_bootcamp_access']
        ]);
        
        // Update bootcamp
        register_rest_route(self::NAMESPACE, '/bootcamps/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_bootcamp'],
            'permission_callback' => [$this, 'check_bootcamp_edit_access'],
            'args' => $this->get_bootcamp_schema()
        ]);
        
        // Delete bootcamp
        register_rest_route(self::NAMESPACE, '/bootcamps/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_bootcamp'],
            'permission_callback' => [$this, 'check_bootcamp_delete_access']
        ]);
    }
    
    /**
     * Register sessions routes
     */
    private function register_sessions_routes() {
        // Get sessions
        register_rest_route(self::NAMESPACE, '/sessions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_sessions'],
            'permission_callback' => [$this, 'check_session_access'],
            'args' => [
                'bootcamp_id' => [
                    'sanitize_callback' => 'absint'
                ],
                'trainer_id' => [
                    'sanitize_callback' => 'absint'
                ],
                'date_from' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'date_to' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // Create session
        register_rest_route(self::NAMESPACE, '/sessions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_session'],
            'permission_callback' => [$this, 'check_session_create_access'],
            'args' => $this->get_session_schema()
        ]);
        
        // Update session
        register_rest_route(self::NAMESPACE, '/sessions/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_session'],
            'permission_callback' => [$this, 'check_session_edit_access'],
            'args' => $this->get_session_schema()
        ]);
    }
    
    /**
     * Register calendar routes
     */
    private function register_calendar_routes() {
        // Get calendar events
        register_rest_route(self::NAMESPACE, '/calendar/events', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_calendar_events'],
            'permission_callback' => [$this, 'check_calendar_access'],
            'args' => [
                'start' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'end' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'trainer_id' => [
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
        
        // Create calendar event
        register_rest_route(self::NAMESPACE, '/calendar/events', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_calendar_event'],
            'permission_callback' => [$this, 'check_calendar_edit_access']
        ]);
    }
    
    /**
     * Register notifications routes
     */
    private function register_notifications_routes() {
        // Get user notifications
        register_rest_route(self::NAMESPACE, '/notifications', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_notifications'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'status' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'limit' => [
                    'default' => 20,
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
        
        // Mark notification as read
        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)/read', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'mark_notification_read'],
            'permission_callback' => 'is_user_logged_in'
        ]);
        
        // Mark all notifications as read
        register_rest_route(self::NAMESPACE, '/notifications/read-all', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'mark_all_notifications_read'],
            'permission_callback' => 'is_user_logged_in'
        ]);
    }
    
    /**
     * Register analytics routes
     */
    private function register_analytics_routes() {
        // Get dashboard stats
        register_rest_route(self::NAMESPACE, '/analytics/dashboard', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_dashboard_analytics'],
            'permission_callback' => [$this, 'check_analytics_access']
        ]);
        
        // Get trainer performance
        register_rest_route(self::NAMESPACE, '/analytics/trainer/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trainer_analytics'],
            'permission_callback' => [$this, 'check_trainer_analytics_access']
        ]);
    }
    
    /**
     * Get trainers
     */
    public function get_trainers($request) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'tbm_trainers';
            
            $page = $request->get_param('page');
            $per_page = min($request->get_param('per_page'), 50); // Max 50 per page
            $search = $request->get_param('search');
            $expertise = $request->get_param('expertise');
            $country = $request->get_param('country');
            $city = $request->get_param('city');
            $status = $request->get_param('status');
            $featured = $request->get_param('featured');
            
            $offset = ($page - 1) * $per_page;
            
            // Build WHERE clause
            $where_conditions = ['status = %s'];
            $where_values = [$status];
            
            if (!empty($search)) {
                $where_conditions[] = '(first_name LIKE %s OR last_name LIKE %s OR expertise_areas LIKE %s)';
                $search_term = '%' . $wpdb->esc_like($search) . '%';
                $where_values[] = $search_term;
                $where_values[] = $search_term;
                $where_values[] = $search_term;
            }
            
            if (!empty($expertise)) {
                $where_conditions[] = 'expertise_areas LIKE %s';
                $where_values[] = '%' . $wpdb->esc_like($expertise) . '%';
            }
            
            if (!empty($country)) {
                $where_conditions[] = 'country = %s';
                $where_values[] = $country;
            }
            
            if (!empty($city)) {
                $where_conditions[] = 'city = %s';
                $where_values[] = $city;
            }
            
            if ($featured) {
                $where_conditions[] = 'featured = 1';
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            
            // Count total results
            $count_query = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} {$where_clause}",
                $where_values
            );
            $total = $wpdb->get_var($count_query);
            
            // Get results
            $query = $wpdb->prepare(
                "SELECT id, first_name, last_name, city, country, expertise_areas, rating_average, rating_count, featured, created_at 
                 FROM {$table} {$where_clause} 
                 ORDER BY featured DESC, rating_average DESC, created_at DESC 
                 LIMIT %d OFFSET %d",
                array_merge($where_values, [$per_page, $offset])
            );
            
            $trainers = $wpdb->get_results($query);
            
            // Format results (limited public info only)
            $formatted_trainers = array_map(function($trainer) {
                return [
                    'id' => (int) $trainer->id,
                    'name' => $trainer->first_name . ' ' . substr($trainer->last_name, 0, 1) . '.',
                    'location' => $trainer->city . ', ' . $trainer->country,
                    'expertise' => $trainer->expertise_areas,
                    'rating' => [
                        'average' => (float) $trainer->rating_average,
                        'count' => (int) $trainer->rating_count
                    ],
                    'featured' => (bool) $trainer->featured
                ];
            }, $trainers);
            
            return new WP_REST_Response([
                'trainers' => $formatted_trainers,
                'pagination' => [
                    'total' => (int) $total,
                    'pages' => ceil($total / $per_page),
                    'current_page' => $page,
                    'per_page' => $per_page
                ]
            ], 200);
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Get single trainer (public info only)
     */
    public function get_trainer($request) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'tbm_trainers';
            
            $trainer_id = $request->get_param('id');
            
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id, first_name, last_name, city, country, expertise_areas, rating_average, rating_count, featured, bio 
                 FROM {$table} 
                 WHERE id = %d AND status = 'active'",
                $trainer_id
            ));
            
            if (!$trainer) {
                return new WP_Error('trainer_not_found', 'Trainer not found', ['status' => 404]);
            }
            
            return new WP_REST_Response([
                'id' => (int) $trainer->id,
                'name' => $trainer->first_name . ' ' . substr($trainer->last_name, 0, 1) . '.',
                'location' => $trainer->city . ', ' . $trainer->country,
                'expertise' => $trainer->expertise_areas,
                'bio' => wp_trim_words($trainer->bio, 50), // Truncated bio
                'rating' => [
                    'average' => (float) $trainer->rating_average,
                    'count' => (int) $trainer->rating_count
                ],
                'featured' => (bool) $trainer->featured
            ], 200);
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Submit trainer application
     */
    public function submit_application($request) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'tbm_applications';
            
            // Generate unique application number
            $application_number = 'APP-' . date('Y') . '-' . wp_generate_password(8, false);
            
            // Prepare data
            $data = [
                'application_number' => $application_number,
                'first_name' => sanitize_text_field($request->get_param('first_name')),
                'last_name' => sanitize_text_field($request->get_param('last_name')),
                'email' => sanitize_email($request->get_param('email')),
                'phone' => sanitize_text_field($request->get_param('phone')),
                'country' => sanitize_text_field($request->get_param('country')),
                'city' => sanitize_text_field($request->get_param('city')),
                'expertise_areas' => sanitize_text_field($request->get_param('expertise')),
                'skills' => sanitize_textarea_field($request->get_param('skills')),
                'experience_years' => absint($request->get_param('experience_years')),
                'motivation' => sanitize_textarea_field($request->get_param('motivation')),
                'portfolio_url' => esc_url_raw($request->get_param('portfolio_url')),
                'linkedin_url' => esc_url_raw($request->get_param('linkedin_url')),
                'expected_rate' => floatval($request->get_param('expected_rate')),
                'currency' => sanitize_text_field($request->get_param('currency')),
                'availability' => sanitize_textarea_field($request->get_param('availability')),
                'status' => 'submitted',
                'submitted_at' => current_time('mysql'),
                'created_at' => current_time('mysql')
            ];
            
            // Check if email already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE email = %s AND status != 'rejected'",
                $data['email']
            ));
            
            if ($existing) {
                return new WP_Error('email_exists', 'Une candidature avec cet email existe déjà', ['status' => 409]);
            }
            
            // Insert application
            $result = $wpdb->insert($table, $data);
            
            if ($result === false) {
                return new WP_Error('insert_failed', 'Erreur lors de la soumission', ['status' => 500]);
            }
            
            $application_id = $wpdb->insert_id;
            
            // Send notification email to admin
            $this->send_new_application_notification($application_id, $data);
            
            // Send confirmation email to applicant
            $this->send_application_confirmation($data);
            
            return new WP_REST_Response([
                'message' => 'Candidature soumise avec succès',
                'application_number' => $application_number,
                'application_id' => $application_id
            ], 201);
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Get applications (admin only)
     */
    public function get_applications($request) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'tbm_applications';
            
            $page = $request->get_param('page');
            $per_page = $request->get_param('per_page');
            $status = $request->get_param('status');
            
            $offset = ($page - 1) * $per_page;
            
            $where_clause = '';
            $where_values = [];
            
            if (!empty($status)) {
                $where_clause = 'WHERE status = %s';
                $where_values[] = $status;
            }
            
            // Count total
            $count_query = "SELECT COUNT(*) FROM {$table} {$where_clause}";
            if (!empty($where_values)) {
                $count_query = $wpdb->prepare($count_query, $where_values);
            }
            $total = $wpdb->get_var($count_query);
            
            // Get results
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} {$where_clause} 
                 ORDER BY submitted_at DESC 
                 LIMIT %d OFFSET %d",
                array_merge($where_values, [$per_page, $offset])
            );
            
            $applications = $wpdb->get_results($query);
            
            return new WP_REST_Response([
                'applications' => $applications,
                'pagination' => [
                    'total' => (int) $total,
                    'pages' => ceil($total / $per_page),
                    'current_page' => $page,
                    'per_page' => $per_page
                ]
            ], 200);
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Permission callbacks
     */
    public function check_trainer_access($request) {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user_id = get_current_user_id();
        $trainer_id = $request->get_param('id');
        
        // Admin can access all
        if (current_user_can('manage_options')) {
            return true;
        }
        
        // Trainers can access their own profile
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_trainers';
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d",
            $trainer_id
        ));
        
        return $trainer && $trainer->user_id == $user_id;
    }
    
    public function check_trainer_edit_access($request) {
        return $this->check_trainer_access($request);
    }
    
    public function check_admin_access() {
        return current_user_can('edit_tbm_trainers');
    }
    
    public function check_application_access($request) {
        if (!is_user_logged_in()) {
            return false;
        }
        
        return current_user_can('edit_tbm_applications');
    }
    
    public function check_bootcamp_access() {
        return current_user_can('read_tbm_bootcamps');
    }
    
    public function check_bootcamp_create_access() {
        return current_user_can('edit_tbm_bootcamps');
    }
    
    public function check_bootcamp_edit_access() {
        return current_user_can('edit_tbm_bootcamps');
    }
    
    public function check_bootcamp_delete_access() {
        return current_user_can('delete_tbm_bootcamps');
    }
    
    public function check_session_access() {
        return is_user_logged_in();
    }
    
    public function check_session_create_access() {
        return current_user_can('edit_tbm_bootcamps');
    }
    
    public function check_session_edit_access() {
        return current_user_can('edit_tbm_bootcamps');
    }
    
    public function check_calendar_access() {
        return is_user_logged_in();
    }
    
    public function check_calendar_edit_access() {
        return current_user_can('edit_tbm_bootcamps');
    }
    
    public function check_analytics_access() {
        return current_user_can('edit_tbm_trainers');
    }
    
    public function check_trainer_analytics_access($request) {
        return $this->check_trainer_access($request) || $this->check_analytics_access();
    }
    
    /**
     * Add CORS headers
     */
    public function add_cors_headers($result, $server, $request) {
        $server->send_header('Access-Control-Allow-Origin', '*');
        $server->send_header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $server->send_header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce');
        
        return $result;
    }
    
    /**
     * Schema definitions
     */
    private function get_trainer_profile_schema() {
        return [
            'first_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'last_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'bio' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'expertise_areas' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'skills' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'hourly_rate' => [
                'sanitize_callback' => 'floatval'
            ],
            'languages' => [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ];
    }
    
    private function get_application_schema() {
        return [
            'first_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'last_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'email' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_email'
            ],
            'phone' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'country' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'city' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'expertise' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'skills' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'experience_years' => [
                'required' => true,
                'sanitize_callback' => 'absint'
            ],
            'motivation' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'portfolio_url' => [
                'sanitize_callback' => 'esc_url_raw'
            ],
            'linkedin_url' => [
                'sanitize_callback' => 'esc_url_raw'
            ],
            'expected_rate' => [
                'sanitize_callback' => 'floatval'
            ],
            'currency' => [
                'default' => 'EUR',
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'availability' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ]
        ];
    }
    
    private function get_bootcamp_schema() {
        return [
            'title' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'description' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'category' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'skill_level' => [
                'enum' => ['beginner', 'intermediate', 'advanced', 'expert']
            ],
            'duration_weeks' => [
                'sanitize_callback' => 'absint'
            ],
            'max_participants' => [
                'sanitize_callback' => 'absint'
            ],
            'start_date' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'end_date' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'location' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'format' => [
                'enum' => ['in-person', 'online', 'hybrid']
            ],
            'price' => [
                'sanitize_callback' => 'floatval'
            ]
        ];
    }
    
    private function get_session_schema() {
        return [
            'bootcamp_id' => [
                'required' => true,
                'sanitize_callback' => 'absint'
            ],
            'trainer_id' => [
                'required' => true,
                'sanitize_callback' => 'absint'
            ],
            'title' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'description' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'start_datetime' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'end_datetime' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'location' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'format' => [
                'enum' => ['in-person', 'online', 'hybrid']
            ]
        ];
    }
    
    /**
     * Notification methods
     */
    private function send_new_application_notification($application_id, $data) {
        $admin_email = get_option('admin_email');
        $subject = 'Nouvelle candidature formateur - ' . $data['first_name'] . ' ' . $data['last_name'];
        
        $message = sprintf(
            "Une nouvelle candidature de formateur a été soumise.\n\n" .
            "Nom: %s %s\n" .
            "Email: %s\n" .
            "Expertise: %s\n" .
            "Expérience: %d ans\n\n" .
            "Voir la candidature dans l'administration.",
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['expertise_areas'],
            $data['experience_years']
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    private function send_application_confirmation($data) {
        $subject = 'Confirmation de votre candidature - Trainer Bootcamp Manager';
        
        $message = sprintf(
            "Bonjour %s,\n\n" .
            "Nous avons bien reçu votre candidature pour devenir formateur.\n" .
            "Numéro de candidature: %s\n\n" .
            "Notre équipe va examiner votre profil et vous recontactera sous 48-72h.\n\n" .
            "Cordialement,\n" .
            "L'équipe TBM",
            $data['first_name'],
            $data['application_number']
        );
        
        wp_mail($data['email'], $subject, $message);
    }
}