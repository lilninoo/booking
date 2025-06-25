<?php
/**
 * Admin Class
 * 
 * Handles WordPress admin interface and functionality
 * 
 * @package TrainerBootcampManager
 * @subpackage Admin
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('plugin_action_links_' . TBM_PLUGIN_BASENAME, [$this, 'add_action_links']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'Trainer Bootcamp Manager',
            'TBM Pro',
            'edit_tbm_trainers',
            'tbm-dashboard',
            [$this, 'dashboard_page'],
            'dashicons-graduation-cap',
            30
        );
        
        // Dashboard submenu
        add_submenu_page(
            'tbm-dashboard',
            'Tableau de bord',
            'Tableau de bord',
            'edit_tbm_trainers',
            'tbm-dashboard',
            [$this, 'dashboard_page']
        );
        
        // Applications submenu
        add_submenu_page(
            'tbm-dashboard',
            'Candidatures',
            'Candidatures',
            'edit_tbm_applications',
            'tbm-applications',
            [$this, 'applications_page']
        );
        
        // Trainers submenu
        add_submenu_page(
            'tbm-dashboard',
            'Formateurs',
            'Formateurs',
            'edit_tbm_trainers',
            'tbm-trainers',
            [$this, 'trainers_page']
        );
        
        // Bootcamps submenu
        add_submenu_page(
            'tbm-dashboard',
            'Bootcamps',
            'Bootcamps',
            'edit_tbm_bootcamps',
            'tbm-bootcamps',
            [$this, 'bootcamps_page']
        );
        
        // Calendar submenu
        add_submenu_page(
            'tbm-dashboard',
            'Calendrier',
            'Calendrier',
            'edit_tbm_sessions',
            'tbm-calendar',
            [$this, 'calendar_page']
        );
        
        // Payments submenu
        add_submenu_page(
            'tbm-dashboard',
            'Paiements',
            'Paiements',
            'manage_tbm_payments',
            'tbm-payments',
            [$this, 'payments_page']
        );
        
        // Analytics submenu
        add_submenu_page(
            'tbm-dashboard',
            'Analytics',
            'Analytics',
            'view_tbm_analytics',
            'tbm-analytics',
            [$this, 'analytics_page']
        );
        
        // Settings submenu
        add_submenu_page(
            'tbm-dashboard',
            'Paramètres',
            'Paramètres',
            'manage_options',
            'tbm-settings',
            [$this, 'settings_page']
        );
    }
    
    /**
     * Admin init
     */
    public function admin_init() {
        // Register settings
        register_setting('tbm_settings', 'tbm_general_settings');
        register_setting('tbm_settings', 'tbm_email_settings');
        register_setting('tbm_settings', 'tbm_payment_settings');
        
        // Add settings sections
        add_settings_section(
            'tbm_general_section',
            'Paramètres généraux',
            [$this, 'general_section_callback'],
            'tbm_settings'
        );
        
        // Add settings fields
        add_settings_field(
            'tbm_currency',
            'Devise par défaut',
            [$this, 'currency_field_callback'],
            'tbm_settings',
            'tbm_general_section'
        );
        
        add_settings_field(
            'tbm_auto_approve',
            'Approbation automatique',
            [$this, 'auto_approve_field_callback'],
            'tbm_settings',
            'tbm_general_section'
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'tbm') === false) {
            return;
        }
        
        // Enqueue admin styles
        wp_enqueue_style(
            'tbm-admin-style',
            TBM_ASSETS_URL . 'css/admin.css',
            [],
            TBM_VERSION
        );
        
        // Enqueue React app for modern pages
        if (in_array($hook, ['toplevel_page_tbm-dashboard', 'tbm-pro_page_tbm-applications'])) {
            wp_enqueue_script('react', 'https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js', [], '18.2.0');
            wp_enqueue_script('react-dom', 'https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js', ['react'], '18.2.0');
            wp_enqueue_script('recharts', 'https://cdnjs.cloudflare.com/ajax/libs/recharts/2.8.0/Recharts.js', ['react'], '2.8.0');
        }
        
        // Enqueue admin scripts
        wp_enqueue_script(
            'tbm-admin-script',
            TBM_ASSETS_URL . 'js/admin.js',
            ['jquery', 'wp-util'],
            TBM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('tbm-admin-script', 'tbmAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resturl' => rest_url('tbm/v1/'),
            'nonce' => wp_create_nonce('tbm_admin_nonce'),
            'strings' => [
                'confirm_delete' => __('Êtes-vous sûr de vouloir supprimer cet élément ?', 'trainer-bootcamp-manager'),
                'loading' => __('Chargement...', 'trainer-bootcamp-manager'),
                'error' => __('Une erreur est survenue.', 'trainer-bootcamp-manager'),
                'success' => __('Opération réussie.', 'trainer-bootcamp-manager')
            ]
        ]);
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        if (!current_user_can('edit_tbm_trainers')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        echo '<div class="wrap">';
        echo '<div id="admin-dashboard"></div>';
        echo '</div>';
        
        // Inline the HTML dashboard
        $this->render_react_dashboard();
    }
    
    /**
     * Applications page
     */
    public function applications_page() {
        if (!current_user_can('edit_tbm_applications')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_applications';
        
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $this->handle_application_bulk_action();
        }
        
        // Get applications
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $where_clause = '';
        $where_values = [];
        
        if (!empty($status)) {
            $where_clause = 'WHERE status = %s';
            $where_values[] = $status;
        }
        
        $total_query = "SELECT COUNT(*) FROM {$table} {$where_clause}";
        if (!empty($where_values)) {
            $total_query = $wpdb->prepare($total_query, $where_values);
        }
        $total = $wpdb->get_var($total_query);
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} {$where_clause} ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            array_merge($where_values, [$per_page, $offset])
        );
        
        $applications = $wpdb->get_results($query);
        
        // Render page
        include TBM_ADMIN_DIR . 'views/applications.php';
    }
    
    /**
     * Trainers page
     */
    public function trainers_page() {
        if (!current_user_can('edit_tbm_trainers')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_trainers';
        
        // Get trainers
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'active';
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $trainers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $status, $per_page, $offset
        ));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            $status
        ));
        
        // Render page
        include TBM_ADMIN_DIR . 'views/trainers.php';
    }
    
    /**
     * Bootcamps page
     */
    public function bootcamps_page() {
        if (!current_user_can('edit_tbm_bootcamps')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        // Render page
        include TBM_ADMIN_DIR . 'views/bootcamps.php';
    }
    
    /**
     * Calendar page
     */
    public function calendar_page() {
        if (!current_user_can('edit_tbm_sessions')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        echo '<div class="wrap">';
        echo '<h1>Calendrier de planification</h1>';
        echo '<div id="tbm-calendar-container"></div>';
        echo '</div>';
        
        // Inline calendar HTML
        $this->render_calendar_interface();
    }
    
    /**
     * Payments page
     */
    public function payments_page() {
        if (!current_user_can('manage_tbm_payments')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        // Render page
        include TBM_ADMIN_DIR . 'views/payments.php';
    }
    
    /**
     * Analytics page
     */
    public function analytics_page() {
        if (!current_user_can('view_tbm_analytics')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        // Render page
        include TBM_ADMIN_DIR . 'views/analytics.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'trainer-bootcamp-manager'));
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        echo '<div class="wrap">';
        echo '<h1>Paramètres TBM Pro</h1>';
        
        // Tabs
        echo '<nav class="nav-tab-wrapper">';
        $tabs = [
            'general' => 'Général',
            'email' => 'Email',
            'payments' => 'Paiements',
            'integrations' => 'Intégrations'
        ];
        
        foreach ($tabs as $tab_key => $tab_name) {
            $active_class = $active_tab === $tab_key ? 'nav-tab-active' : '';
            echo '<a href="?page=tbm-settings&tab=' . $tab_key . '" class="nav-tab ' . $active_class . '">' . $tab_name . '</a>';
        }
        echo '</nav>';
        
        // Tab content
        echo '<form method="post" action="options.php">';
        settings_fields('tbm_settings');
        
        switch ($active_tab) {
            case 'general':
                do_settings_sections('tbm_settings');
                break;
            case 'email':
                $this->render_email_settings();
                break;
            case 'payments':
                $this->render_payment_settings();
                break;
            case 'integrations':
                $this->render_integration_settings();
                break;
        }
        
        submit_button();
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Handle application bulk actions
     */
    private function handle_application_bulk_action() {
        if (!isset($_POST['applications']) || !is_array($_POST['applications'])) {
            return;
        }
        
        $action = sanitize_text_field($_POST['action']);
        $application_ids = array_map('absint', $_POST['applications']);
        
        global $wpdb;
        $table = $wpdb->prefix . 'tbm_applications';
        
        switch ($action) {
            case 'approve':
                foreach ($application_ids as $id) {
                    $wpdb->update(
                        $table,
                        ['status' => 'approved', 'decision_at' => current_time('mysql')],
                        ['id' => $id]
                    );
                }
                add_settings_error('tbm_admin', 'applications_approved', 'Candidatures approuvées.', 'success');
                break;
                
            case 'reject':
                foreach ($application_ids as $id) {
                    $wpdb->update(
                        $table,
                        ['status' => 'rejected', 'decision_at' => current_time('mysql')],
                        ['id' => $id]
                    );
                }
                add_settings_error('tbm_admin', 'applications_rejected', 'Candidatures rejetées.', 'success');
                break;
        }
    }
    
    /**
     * Admin notices
     */
    public function admin_notices() {
        settings_errors('tbm_admin');
        
        // Show setup notices
        if (!get_option('tbm_setup_completed')) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>Trainer Bootcamp Manager:</strong> Merci d\'avoir installé le plugin ! ';
            echo '<a href="' . admin_url('admin.php?page=tbm-settings') . '">Configurez les paramètres</a> pour commencer.</p>';
            echo '</div>';
        }
    }
    
    /**
     * Add action links
     */
    public function add_action_links($links) {
        $action_links = [
            'settings' => '<a href="' . admin_url('admin.php?page=tbm-settings') . '">Paramètres</a>',
            'dashboard' => '<a href="' . admin_url('admin.php?page=tbm-dashboard') . '">Dashboard</a>'
        ];
        
        return array_merge($action_links, $links);
    }
    
    /**
     * Settings callbacks
     */
    public function general_section_callback() {
        echo '<p>Configurez les paramètres généraux du plugin.</p>';
    }
    
    public function currency_field_callback() {
        $value = get_option('tbm_currency', 'EUR');
        $currencies = TBM_Utilities::get_currencies();
        
        echo '<select name="tbm_currency">';
        foreach ($currencies as $code => $currency) {
            $selected = selected($value, $code, false);
            echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($currency['name']) . ' (' . esc_html($currency['symbol']) . ')</option>';
        }
        echo '</select>';
    }
    
    public function auto_approve_field_callback() {
        $value = get_option('tbm_auto_approve_trainers', false);
        echo '<input type="checkbox" name="tbm_auto_approve_trainers" value="1" ' . checked($value, true, false) . '>';
        echo '<label for="tbm_auto_approve_trainers">Approuver automatiquement les nouveaux formateurs</label>';
    }
    
    /**
     * Render React dashboard
     */
    private function render_react_dashboard() {
        // Include the HTML content from modern_admin_dashboard.html
        $dashboard_content = file_get_contents(TBM_PLUGIN_DIR . 'assets/templates/modern_admin_dashboard.html');
        
        // Extract just the body content and script
        if (preg_match('/<body[^>]*>(.*?)<\/body>/s', $dashboard_content, $matches)) {
            echo $matches[1];
        }
    }
    
    /**
     * Render calendar interface
     */
    private function render_calendar_interface() {
        // Include the HTML content from interactive_calendar.html
        $calendar_content = file_get_contents(TBM_PLUGIN_DIR . 'assets/templates/interactive_calendar.html');
        
        // Extract just the body content and script
        if (preg_match('/<body[^>]*>(.*?)<\/body>/s', $calendar_content, $matches)) {
            echo $matches[1];
        }
    }
    
    /**
     * Render email settings
     */
    private function render_email_settings() {
        $settings = get_option('tbm_email_settings', []);
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Notifications email</th>';
        echo '<td>';
        echo '<input type="checkbox" name="tbm_email_settings[enabled]" value="1" ' . checked(!empty($settings['enabled']), true, false) . '>';
        echo '<label>Activer les notifications par email</label>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
    }
    
    /**
     * Render payment settings
     */
    private function render_payment_settings() {
        $settings = get_option('tbm_payment_settings', []);
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Stripe Secret Key</th>';
        echo '<td><input type="text" name="tbm_payment_settings[stripe_secret]" value="' . esc_attr($settings['stripe_secret'] ?? '') . '" class="regular-text"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">PayPal Client ID</th>';
        echo '<td><input type="text" name="tbm_payment_settings[paypal_client_id]" value="' . esc_attr($settings['paypal_client_id'] ?? '') . '" class="regular-text"></td>';
        echo '</tr>';
        echo '</table>';
    }
    
    /**
     * Render integration settings
     */
    private function render_integration_settings() {
        $settings = get_option('tbm_integration_settings', []);
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Google Calendar</th>';
        echo '<td>';
        echo '<input type="checkbox" name="tbm_integration_settings[google_calendar]" value="1" ' . checked(!empty($settings['google_calendar']), true, false) . '>';
        echo '<label>Activer la synchronisation Google Calendar</label>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
    }
}