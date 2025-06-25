<?php
/**
 * Public Class
 * 
 * Handles public-facing functionality
 * 
 * @package TrainerBootcampManager
 * @subpackage Public
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Public {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('template_redirect', [$this, 'template_redirect']);
        add_action('wp_head', [$this, 'add_meta_tags']);
        add_filter('the_content', [$this, 'filter_content']);
        add_filter('body_class', [$this, 'add_body_classes']);
        
        // Initialize shortcodes
        $this->init_shortcodes();
        
        // Handle form submissions
        add_action('init', [$this, 'handle_form_submissions']);
    }
    
    /**
     * Enqueue public scripts and styles
     */
    public function enqueue_scripts() {
        // Main public stylesheet
        wp_enqueue_style(
            'tbm-public-style',
            TBM_ASSETS_URL . 'css/public.css',
            [],
            TBM_VERSION
        );
        
        // Main public script
        wp_enqueue_script(
            'tbm-public-script',
            TBM_ASSETS_URL . 'js/public.js',
            ['jquery'],
            TBM_VERSION,
            true
        );
        
        // React for interactive components
        if ($this->needs_react()) {
            wp_enqueue_script('react', 'https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js', [], '18.2.0');
            wp_enqueue_script('react-dom', 'https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js', ['react'], '18.2.0');
        }
        
        // Localize script
        wp_localize_script('tbm-public-script', 'tbmPublic', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resturl' => rest_url('tbm/v1/'),
            'nonce' => wp_create_nonce('tbm_public_nonce'),
            'strings' => [
                'loading' => __('Chargement...', 'trainer-bootcamp-manager'),
                'error' => __('Une erreur est survenue.', 'trainer-bootcamp-manager'),
                'success' => __('Opération réussie.', 'trainer-bootcamp-manager'),
                'search_placeholder' => __('Rechercher un formateur...', 'trainer-bootcamp-manager'),
                'no_results' => __('Aucun résultat trouvé.', 'trainer-bootcamp-manager')
            ],
            'settings' => [
                'currency' => get_option('tbm_currency', 'EUR'),
                'date_format' => get_option('date_format'),
                'per_page' => 12
            ]
        ]);
    }
    
    /**
     * Check if React is needed for current page
     */
    private function needs_react() {
        global $post;
        
        if (!$post) {
            return false;
        }
        
        // Check if page contains TBM shortcodes that need React
        $react_shortcodes = ['tbm_trainer_listing', 'tbm_trainer_application', 'tbm_bootcamp_listing'];
        
        foreach ($react_shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Initialize shortcodes
     */
    private function init_shortcodes() {
        $shortcodes = new TBM_Shortcodes();
    }
    
    /**
     * Handle template redirects
     */
    public function template_redirect() {
        // Handle custom post type templates
        if (is_singular('tbm_trainer')) {
            $this->load_trainer_template();
        } elseif (is_singular('tbm_bootcamp')) {
            $this->load_bootcamp_template();
        } elseif (is_post_type_archive('tbm_trainer')) {
            $this->load_trainer_archive_template();
        } elseif (is_post_type_archive('tbm_bootcamp')) {
            $this->load_bootcamp_archive_template();
        }
    }
    
    /**
     * Load trainer single template
     */
    private function load_trainer_template() {
        $template = locate_template('single-tbm_trainer.php');
        
        if (!$template) {
            $template = TBM_PUBLIC_DIR . 'templates/single-trainer.php';
        }
        
        if (file_exists($template)) {
            include $template;
            exit;
        }
    }
    
    /**
     * Load bootcamp single template
     */
    private function load_bootcamp_template() {
        $template = locate_template('single-tbm_bootcamp.php');
        
        if (!$template) {
            $template = TBM_PUBLIC_DIR . 'templates/single-bootcamp.php';
        }
        
        if (file_exists($template)) {
            include $template;
            exit;
        }
    }
    
    /**
     * Load trainer archive template
     */
    private function load_trainer_archive_template() {
        $template = locate_template('archive-tbm_trainer.php');
        
        if (!$template) {
            $template = TBM_PUBLIC_DIR . 'templates/archive-trainer.php';
        }
        
        if (file_exists($template)) {
            include $template;
            exit;
        }
    }
    
    /**
     * Load bootcamp archive template
     */
    private function load_bootcamp_archive_template() {
        $template = locate_template('archive-tbm_bootcamp.php');
        
        if (!$template) {
            $template = TBM_PUBLIC_DIR . 'templates/archive-bootcamp.php';
        }
        
        if (file_exists($template)) {
            include $template;
            exit;
        }
    }
    
    /**
     * Add meta tags
     */
    public function add_meta_tags() {
        if (is_singular('tbm_trainer')) {
            $this->add_trainer_meta_tags();
        } elseif (is_singular('tbm_bootcamp')) {
            $this->add_bootcamp_meta_tags();
        }
    }
    
    /**
     * Add trainer meta tags
     */
    private function add_trainer_meta_tags() {
        global $post;
        
        $trainer_id = get_post_meta($post->ID, 'trainer_id', true);
        if (!$trainer_id) {
            return;
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbm_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return;
        }
        
        echo '<meta name="description" content="' . esc_attr($trainer->bio) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($trainer->first_name . ' ' . $trainer->last_name) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($trainer->bio) . '">' . "\n";
        echo '<meta property="og:type" content="profile">' . "\n";
    }
    
    /**
     * Add bootcamp meta tags
     */
    private function add_bootcamp_meta_tags() {
        global $post;
        
        $bootcamp_id = get_post_meta($post->ID, 'bootcamp_id', true);
        if (!$bootcamp_id) {
            return;
        }
        
        global $wpdb;
        $bootcamp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbm_bootcamps WHERE id = %d",
            $bootcamp_id
        ));
        
        if (!$bootcamp) {
            return;
        }
        
        echo '<meta name="description" content="' . esc_attr($bootcamp->description) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($bootcamp->title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($bootcamp->description) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
    }
    
    /**
     * Filter content
     */
    public function filter_content($content) {
        if (is_singular(['tbm_trainer', 'tbm_bootcamp'])) {
            $content = $this->add_structured_data($content);
        }
        
        return $content;
    }
    
    /**
     * Add structured data
     */
    private function add_structured_data($content) {
        global $post;
        
        if (is_singular('tbm_trainer')) {
            $structured_data = $this->get_trainer_structured_data($post->ID);
        } elseif (is_singular('tbm_bootcamp')) {
            $structured_data = $this->get_bootcamp_structured_data($post->ID);
        } else {
            return $content;
        }
        
        if ($structured_data) {
            $content .= '<script type="application/ld+json">' . json_encode($structured_data) . '</script>';
        }
        
        return $content;
    }
    
    /**
     * Get trainer structured data
     */
    private function get_trainer_structured_data($post_id) {
        $trainer_id = get_post_meta($post_id, 'trainer_id', true);
        if (!$trainer_id) {
            return null;
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbm_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return null;
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $trainer->first_name . ' ' . $trainer->last_name,
            'description' => $trainer->bio,
            'jobTitle' => 'Formateur',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $trainer->city,
                'addressCountry' => $trainer->country
            ],
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => $trainer->rating_average,
                'reviewCount' => $trainer->rating_count
            ]
        ];
    }
    
    /**
     * Get bootcamp structured data
     */
    private function get_bootcamp_structured_data($post_id) {
        $bootcamp_id = get_post_meta($post_id, 'bootcamp_id', true);
        if (!$bootcamp_id) {
            return null;
        }
        
        global $wpdb;
        $bootcamp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbm_bootcamps WHERE id = %d",
            $bootcamp_id
        ));
        
        if (!$bootcamp) {
            return null;
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $bootcamp->title,
            'description' => $bootcamp->description,
            'provider' => [
                '@type' => 'Organization',
                'name' => get_bloginfo('name')
            ],
            'courseMode' => $bootcamp->format === 'online' ? 'online' : 'onsite',
            'educationalLevel' => $bootcamp->skill_level,
            'offers' => [
                '@type' => 'Offer',
                'price' => $bootcamp->price,
                'priceCurrency' => $bootcamp->currency
            ]
        ];
    }
    
    /**
     * Add body classes
     */
    public function add_body_classes($classes) {
        if (is_singular('tbm_trainer')) {
            $classes[] = 'tbm-trainer-single';
        } elseif (is_singular('tbm_bootcamp')) {
            $classes[] = 'tbm-bootcamp-single';
        } elseif (is_post_type_archive('tbm_trainer')) {
            $classes[] = 'tbm-trainer-archive';
        } elseif (is_post_type_archive('tbm_bootcamp')) {
            $classes[] = 'tbm-bootcamp-archive';
        }
        
        return $classes;
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        // Handle trainer application form
        if (isset($_POST['tbm_trainer_application'])) {
            $this->handle_trainer_application();
        }
        
        // Handle contact form
        if (isset($_POST['tbm_contact_form'])) {
            $this->handle_contact_form();
        }
    }
    
    /**
     * Handle trainer application form submission
     */
    private function handle_trainer_application() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'tbm_trainer_application')) {
            wp_die('Security check failed');
        }
        
        // Validate and sanitize data
        $data = [
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'country' => sanitize_text_field($_POST['country']),
            'city' => sanitize_text_field($_POST['city']),
            'expertise' => sanitize_text_field($_POST['expertise']),
            'skills' => sanitize_textarea_field($_POST['skills']),
            'experience_years' => absint($_POST['experience_years']),
            'motivation' => sanitize_textarea_field($_POST['motivation']),
            'portfolio_url' => esc_url_raw($_POST['portfolio_url']),
            'linkedin_url' => esc_url_raw($_POST['linkedin_url']),
            'expected_rate' => floatval($_POST['expected_rate']),
            'currency' => sanitize_text_field($_POST['currency']),
            'availability' => sanitize_textarea_field($_POST['availability'])
        ];
        
        // Validate required fields
        $errors = [];
        
        if (empty($data['first_name'])) {
            $errors[] = 'Le prénom est requis';
        }
        
        if (empty($data['last_name'])) {
            $errors[] = 'Le nom est requis';
        }
        
        if (empty($data['email']) || !is_email($data['email'])) {
            $errors[] = 'Un email valide est requis';
        }
        
        if (empty($data['expertise'])) {
            $errors[] = 'Le domaine d\'expertise est requis';
        }
        
        if ($data['experience_years'] < 1) {
            $errors[] = 'L\'expérience doit être d\'au moins 1 an';
        }
        
        if (strlen($data['motivation']) < 100) {
            $errors[] = 'La motivation doit faire au moins 100 caractères';
        }
        
        if (!empty($errors)) {
            wp_redirect(add_query_arg('tbm_errors', urlencode(implode('|', $errors)), wp_get_referer()));
            exit;
        }
        
        // Submit via REST API
        $response = wp_remote_post(rest_url('tbm/v1/applications'), [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data)
        ]);
        
        if (is_wp_error($response)) {
            wp_redirect(add_query_arg('tbm_error', 'submission_failed', wp_get_referer()));
            exit;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (wp_remote_retrieve_response_code($response) === 201) {
            wp_redirect(add_query_arg('tbm_success', 'application_submitted', wp_get_referer()));
        } else {
            wp_redirect(add_query_arg('tbm_error', 'submission_failed', wp_get_referer()));
        }
        
        exit;
    }
    
    /**
     * Handle contact form submission
     */
    private function handle_contact_form() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'tbm_contact_form')) {
            wp_die('Security check failed');
        }
        
        // Sanitize data
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $subject = sanitize_text_field($_POST['subject']);
        $message = sanitize_textarea_field($_POST['message']);
        
        // Validate
        if (empty($name) || empty($email) || empty($message)) {
            wp_redirect(add_query_arg('tbm_error', 'missing_fields', wp_get_referer()));
            exit;
        }
        
        if (!is_email($email)) {
            wp_redirect(add_query_arg('tbm_error', 'invalid_email', wp_get_referer()));
            exit;
        }
        
        // Send email
        $to = get_option('admin_email');
        $email_subject = 'Contact TBM: ' . $subject;
        $email_message = "Nom: {$name}\n";
        $email_message .= "Email: {$email}\n\n";
        $email_message .= "Message:\n{$message}";
        
        $headers = ['Reply-To: ' . $email];
        
        if (wp_mail($to, $email_subject, $email_message, $headers)) {
            wp_redirect(add_query_arg('tbm_success', 'message_sent', wp_get_referer()));
        } else {
            wp_redirect(add_query_arg('tbm_error', 'send_failed', wp_get_referer()));
        }
        
        exit;
    }
    
    /**
     * Get trainer data for public display
     */
    public function get_trainer_data($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, first_name, last_name, city, country, expertise_areas, bio, rating_average, rating_count, featured 
             FROM {$wpdb->prefix}tbm_trainers 
             WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return null;
        }
        
        // Format for public display (limited info)
        return [
            'id' => (int) $trainer->id,
            'name' => $trainer->first_name . ' ' . substr($trainer->last_name, 0, 1) . '.',
            'location' => $trainer->city . ', ' . $trainer->country,
            'expertise' => $trainer->expertise_areas,
            'bio' => wp_trim_words($trainer->bio, 50),
            'rating' => [
                'average' => (float) $trainer->rating_average,
                'count' => (int) $trainer->rating_count
            ],
            'featured' => (bool) $trainer->featured
        ];
    }
    
    /**
     * Get bootcamp data for public display
     */
    public function get_bootcamp_data($bootcamp_id) {
        global $wpdb;
        
        $bootcamp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbm_bootcamps WHERE id = %d AND status IN ('scheduled', 'active')",
            $bootcamp_id
        ));
        
        return $bootcamp;
    }
    
    /**
     * Search trainers
     */
    public function search_trainers($args = []) {
        $defaults = [
            'search' => '',
            'expertise' => '',
            'country' => '',
            'city' => '',
            'page' => 1,
            'per_page' => 12
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Use REST API
        $url = rest_url('tbm/v1/trainers') . '?' . http_build_query($args);
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return ['trainers' => [], 'pagination' => []];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body ?: ['trainers' => [], 'pagination' => []];
    }
}