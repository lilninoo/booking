<?php
/**
 * Trainer Dashboard Class
 * 
 * Handles trainer-specific dashboard functionality
 * 
 * @package TrainerBootcampManager
 * @subpackage Public
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Trainer_Dashboard {
    
    /**
     * Current trainer data
     */
    private $trainer;
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('tbm_trainer_dashboard', [$this, 'render_dashboard']);
        add_action('template_redirect', [$this, 'handle_dashboard_access']);
    }
    
    /**
     * Initialize
     */
    public function init() {
        // Check if current user is a trainer
        if (is_user_logged_in() && in_array('tbm_trainer', wp_get_current_user()->roles)) {
            $user_management = new TBM_User_Management();
            $this->trainer = $user_management->get_trainer_by_user_id(get_current_user_id());
        }
    }
    
    /**
     * Enqueue dashboard scripts
     */
    public function enqueue_scripts() {
        if (!$this->is_trainer_dashboard_page()) {
            return;
        }
        
        // React and dependencies
        wp_enqueue_script('react', 'https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js', [], '18.2.0');
        wp_enqueue_script('react-dom', 'https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js', ['react'], '18.2.0');
        wp_enqueue_script('recharts', 'https://cdnjs.cloudflare.com/ajax/libs/recharts/2.8.0/Recharts.js', ['react'], '2.8.0');
        
        // Dashboard styles
        wp_enqueue_style(
            'tbm-trainer-dashboard',
            TBM_ASSETS_URL . 'css/trainer-dashboard.css',
            [],
            TBM_VERSION
        );
        
        // Dashboard script
        wp_enqueue_script(
            'tbm-trainer-dashboard',
            TBM_ASSETS_URL . 'js/trainer-dashboard.js',
            ['react', 'react-dom'],
            TBM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('tbm-trainer-dashboard', 'tbmTrainerDashboard', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resturl' => rest_url('tbm/v1/'),
            'nonce' => wp_create_nonce('tbm_public_nonce'),
            'trainer' => $this->get_trainer_data(),
            'strings' => [
                'loading' => __('Chargement...', 'trainer-bootcamp-manager'),
                'error' => __('Une erreur est survenue.', 'trainer-bootcamp-manager'),
                'success' => __('Opération réussie.', 'trainer-bootcamp-manager'),
                'confirm_delete' => __('Êtes-vous sûr de vouloir supprimer ?', 'trainer-bootcamp-manager')
            ]
        ]);
    }
    
    /**
     * Check if current page is trainer dashboard
     */
    private function is_trainer_dashboard_page() {
        global $post;
        
        if (!$post) {
            return false;
        }
        
        return has_shortcode($post->post_content, 'tbm_trainer_dashboard') || 
               (isset($_GET['tbm_trainer_dashboard']) && $_GET['tbm_trainer_dashboard'] === '1');
    }
    
    /**
     * Handle dashboard access
     */
    public function handle_dashboard_access() {
        if (!$this->is_trainer_dashboard_page()) {
            return;
        }
        
        // Redirect if not logged in
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url(get_permalink()));
            exit;
        }
        
        // Redirect if not a trainer
        if (!$this->trainer) {
            wp_redirect(home_url());
            exit;
        }
    }
    
    /**
     * Render trainer dashboard
     */
    public function render_dashboard($atts) {
        $atts = shortcode_atts([
            'layout' => 'full' // full, compact
        ], $atts);
        
        if (!is_user_logged_in()) {
            return '<p>Vous devez être connecté pour accéder au dashboard.</p>';
        }
        
        if (!$this->trainer) {
            return '<p>Profil formateur non trouvé.</p>';
        }
        
        ob_start();
        ?>
        <div id="tbm-trainer-dashboard" class="tbm-trainer-dashboard-container" data-layout="<?php echo esc_attr($atts['layout']); ?>">
            <div class="tbm-loading">
                <div class="tbm-spinner"></div>
                <p>Chargement du dashboard...</p>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof React !== 'undefined' && typeof ReactDOM !== 'undefined') {
                // Load the trainer dashboard HTML content
                fetch('<?php echo TBM_ASSETS_URL; ?>templates/trainer_dashboard_interface.html')
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const bodyContent = doc.body.innerHTML;
                        document.getElementById('tbm-trainer-dashboard').innerHTML = bodyContent;
                        
                        // Execute the scripts
                        const scripts = doc.querySelectorAll('script');
                        scripts.forEach(script => {
                            if (script.src) {
                                const newScript = document.createElement('script');
                                newScript.src = script.src;
                                document.head.appendChild(newScript);
                            } else {
                                eval(script.textContent);
                            }
                        });
                    })
                    .catch(error => {
                        console.error('Error loading dashboard:', error);
                        document.getElementById('tbm-trainer-dashboard').innerHTML = `
                            <div class="tbm-dashboard-fallback">
                                <h2>Dashboard Formateur</h2>
                                <div class="tbm-dashboard-grid">
                                    <div class="tbm-card">
                                        <h3>Mes Statistiques</h3>
                                        <div class="tbm-stats">
                                            <div class="tbm-stat">
                                                <span class="tbm-stat-value"><?php echo esc_html($this->get_sessions_count()); ?></span>
                                                <span class="tbm-stat-label">Sessions</span>
                                            </div>
                                            <div class="tbm-stat">
                                                <span class="tbm-stat-value"><?php echo esc_html($this->get_rating_average()); ?></span>
                                                <span class="tbm-stat-label">Note moyenne</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="tbm-card">
                                        <h3>Prochaines Sessions</h3>
                                        <div class="tbm-sessions-list">
                                            <?php echo $this->get_upcoming_sessions_html(); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="tbm-card">
                                        <h3>Actions Rapides</h3>
                                        <div class="tbm-actions">
                                            <a href="<?php echo $this->get_profile_url(); ?>" class="tbm-btn">Modifier mon profil</a>
                                            <a href="<?php echo $this->get_calendar_url(); ?>" class="tbm-btn">Mon planning</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
            } else {
                // Fallback if React is not available
                document.getElementById('tbm-trainer-dashboard').innerHTML = `
                    <div class="tbm-dashboard-fallback">
                        <h2>Dashboard Formateur</h2>
                        <p>Dashboard de base - React non disponible</p>
                    </div>
                `;
            }
        });
        </script>
        
        <style>
        .tbm-trainer-dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .tbm-loading {
            text-align: center;
            padding: 60px 20px;
        }
        
        .tbm-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: tbm-spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes tbm-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .tbm-dashboard-fallback {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .tbm-dashboard-fallback h2 {
            margin-bottom: 30px;
            color: #1f2937;
            text-align: center;
        }
        
        .tbm-dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .tbm-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e9ecef;
        }
        
        .tbm-card h3 {
            margin-bottom: 15px;
            color: #495057;
        }
        
        .tbm-stats {
            display: flex;
            gap: 20px;
        }
        
        .tbm-stat {
            text-align: center;
        }
        
        .tbm-stat-value {
            display: block;
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .tbm-stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .tbm-sessions-list {
            space-y: 10px;
        }
        
        .tbm-session-item {
            padding: 10px;
            background: white;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .tbm-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .tbm-btn {
            display: inline-block;
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            text-align: center;
            transition: transform 0.2s;
        }
        
        .tbm-btn:hover {
            transform: translateY(-1px);
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Get trainer data for JavaScript
     */
    private function get_trainer_data() {
        if (!$this->trainer) {
            return null;
        }
        
        return [
            'id' => (int) $this->trainer->id,
            'name' => $this->trainer->first_name . ' ' . $this->trainer->last_name,
            'email' => $this->trainer->email,
            'expertise' => $this->trainer->expertise_areas,
            'rating_average' => (float) $this->trainer->rating_average,
            'rating_count' => (int) $this->trainer->rating_count,
            'total_sessions' => (int) $this->trainer->total_sessions,
            'total_earnings' => (float) $this->trainer->total_earnings,
            'profile_completion' => (int) $this->trainer->profile_completion,
            'status' => $this->trainer->status,
            'created_at' => $this->trainer->created_at
        ];
    }
    
    /**
     * Get sessions count
     */
    private function get_sessions_count() {
        if (!$this->trainer) {
            return 0;
        }
        
        return $this->trainer->total_sessions;
    }
    
    /**
     * Get rating average
     */
    private function get_rating_average() {
        if (!$this->trainer || $this->trainer->rating_count == 0) {
            return 'N/A';
        }
        
        return number_format($this->trainer->rating_average, 1) . '/5';
    }
    
    /**
     * Get upcoming sessions HTML
     */
    private function get_upcoming_sessions_html() {
        if (!$this->trainer) {
            return '<p>Aucune session à venir.</p>';
        }
        
        global $wpdb;
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, b.title as bootcamp_title 
             FROM {$wpdb->prefix}tbm_sessions s
             LEFT JOIN {$wpdb->prefix}tbm_bootcamps b ON s.bootcamp_id = b.id
             WHERE s.trainer_id = %d 
             AND s.start_datetime > NOW() 
             AND s.status = 'scheduled'
             ORDER BY s.start_datetime ASC 
             LIMIT 5",
            $this->trainer->id
        ));
        
        if (empty($sessions)) {
            return '<p>Aucune session programmée.</p>';
        }
        
        $html = '';
        foreach ($sessions as $session) {
            $date = date('d/m/Y', strtotime($session->start_datetime));
            $time = date('H:i', strtotime($session->start_datetime));
            
            $html .= '<div class="tbm-session-item">';
            $html .= '<h4>' . esc_html($session->title) . '</h4>';
            $html .= '<p>' . esc_html($session->bootcamp_title) . '</p>';
            $html .= '<p>' . $date . ' à ' . $time . '</p>';
            $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * Get profile URL
     */
    private function get_profile_url() {
        return add_query_arg('tbm_action', 'edit_profile', get_permalink());
    }
    
    /**
     * Get calendar URL
     */
    private function get_calendar_url() {
        return add_query_arg('tbm_action', 'calendar', get_permalink());
    }
    
    /**
     * Get trainer statistics
     */
    public function get_trainer_statistics($trainer_id = null) {
        $trainer_id = $trainer_id ?: ($this->trainer ? $this->trainer->id : 0);
        
        if (!$trainer_id) {
            return [];
        }
        
        global $wpdb;
        
        // Sessions stats
        $sessions_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_sessions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_sessions,
                COUNT(CASE WHEN status = 'scheduled' THEN 1 END) as upcoming_sessions,
                SUM(CASE WHEN status = 'completed' THEN duration_minutes ELSE 0 END) as total_minutes
             FROM {$wpdb->prefix}tbm_sessions 
             WHERE trainer_id = %d",
            $trainer_id
        ));
        
        // Earnings this month
        $current_month_earnings = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(net_amount) 
             FROM {$wpdb->prefix}tbm_payments 
             WHERE trainer_id = %d 
             AND status = 'completed' 
             AND MONTH(paid_date) = MONTH(NOW()) 
             AND YEAR(paid_date) = YEAR(NOW())",
            $trainer_id
        ));
        
        // Earnings last month
        $last_month_earnings = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(net_amount) 
             FROM {$wpdb->prefix}tbm_payments 
             WHERE trainer_id = %d 
             AND status = 'completed' 
             AND MONTH(paid_date) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) 
             AND YEAR(paid_date) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))",
            $trainer_id
        ));
        
        // Recent ratings
        $recent_ratings = $wpdb->get_results($wpdb->prepare(
            "SELECT rating, review, created_at 
             FROM {$wpdb->prefix}tbm_ratings 
             WHERE trainer_id = %d 
             AND status = 'active'
             ORDER BY created_at DESC 
             LIMIT 5",
            $trainer_id
        ));
        
        return [
            'sessions' => [
                'total' => (int) $sessions_stats->total_sessions,
                'completed' => (int) $sessions_stats->completed_sessions,
                'upcoming' => (int) $sessions_stats->upcoming_sessions,
                'total_hours' => round((int) $sessions_stats->total_minutes / 60, 1)
            ],
            'earnings' => [
                'current_month' => (float) $current_month_earnings,
                'last_month' => (float) $last_month_earnings,
                'change_percentage' => $last_month_earnings > 0 ? 
                    round((($current_month_earnings - $last_month_earnings) / $last_month_earnings) * 100, 1) : 0
            ],
            'ratings' => [
                'average' => (float) $this->trainer->rating_average,
                'count' => (int) $this->trainer->rating_count,
                'recent' => $recent_ratings
            ]
        ];
    }
    
    /**
     * Get upcoming sessions
     */
    public function get_upcoming_sessions($trainer_id = null, $limit = 10) {
        $trainer_id = $trainer_id ?: ($this->trainer ? $this->trainer->id : 0);
        
        if (!$trainer_id) {
            return [];
        }
        
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, b.title as bootcamp_title 
             FROM {$wpdb->prefix}tbm_sessions s
             LEFT JOIN {$wpdb->prefix}tbm_bootcamps b ON s.bootcamp_id = b.id
             WHERE s.trainer_id = %d 
             AND s.start_datetime > NOW() 
             AND s.status = 'scheduled'
             ORDER BY s.start_datetime ASC 
             LIMIT %d",
            $trainer_id, $limit
        ));
    }
    
    /**
     * Get trainer notifications
     */
    public function get_trainer_notifications($limit = 20) {
        if (!$this->trainer) {
            return [];
        }
        
        $user_id = get_current_user_id();
        $notifications = new TBM_Notifications();
        
        return $notifications->get_user_notifications($user_id, [
            'limit' => $limit,
            'status' => 'sent'
        ]);
    }
    
    /**
     * Check if trainer profile is complete
     */
    public function is_profile_complete() {
        if (!$this->trainer) {
            return false;
        }
        
        return $this->trainer->profile_completion >= 80;
    }
    
    /**
     * Get profile completion items
     */
    public function get_profile_completion_items() {
        if (!$this->trainer) {
            return [];
        }
        
        $items = [
            [
                'label' => 'Informations personnelles',
                'completed' => !empty($this->trainer->first_name) && !empty($this->trainer->last_name) && !empty($this->trainer->email),
                'weight' => 15
            ],
            [
                'label' => 'Localisation',
                'completed' => !empty($this->trainer->country) && !empty($this->trainer->city),
                'weight' => 10
            ],
            [
                'label' => 'Expertise et compétences',
                'completed' => !empty($this->trainer->expertise_areas) && !empty($this->trainer->skills),
                'weight' => 20
            ],
            [
                'label' => 'Biographie',
                'completed' => !empty($this->trainer->bio) && strlen($this->trainer->bio) >= 100,
                'weight' => 15
            ],
            [
                'label' => 'Tarif horaire',
                'completed' => $this->trainer->hourly_rate > 0,
                'weight' => 10
            ],
            [
                'label' => 'Photo de profil',
                'completed' => !empty(get_user_meta(get_current_user_id(), 'profile_picture', true)),
                'weight' => 10
            ],
            [
                'label' => 'Documents (CV)',
                'completed' => $this->has_uploaded_cv(),
                'weight' => 20
            ]
        ];
        
        return $items;
    }
    
    /**
     * Check if trainer has uploaded CV
     */
    private function has_uploaded_cv() {
        if (!$this->trainer) {
            return false;
        }
        
        global $wpdb;
        
        $cv_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tbm_documents 
             WHERE trainer_id = %d AND document_type = 'cv'",
            $this->trainer->id
        ));
        
        return $cv_count > 0;
    }
    
    /**
     * Get trainer's recent activity
     */
    public function get_recent_activity($limit = 10) {
        if (!$this->trainer) {
            return [];
        }
        
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT action, object_type, description, created_at 
             FROM {$wpdb->prefix}tbm_activity_log 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            get_current_user_id(), $limit
        ));
    }
}