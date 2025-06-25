<?php
/**
 * Shortcodes Class
 * 
 * Handles all plugin shortcodes
 * 
 * @package TrainerBootcampManager
 * @subpackage Public
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Shortcodes {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->register_shortcodes();
    }
    
    /**
     * Register all shortcodes
     */
    private function register_shortcodes() {
        add_shortcode('tbm_trainer_listing', [$this, 'trainer_listing_shortcode']);
        add_shortcode('tbm_trainer_search', [$this, 'trainer_search_shortcode']);
        add_shortcode('tbm_trainer_application', [$this, 'trainer_application_shortcode']);
        add_shortcode('tbm_bootcamp_listing', [$this, 'bootcamp_listing_shortcode']);
        add_shortcode('tbm_stats', [$this, 'stats_shortcode']);
        add_shortcode('tbm_featured_trainers', [$this, 'featured_trainers_shortcode']);
        add_shortcode('tbm_contact_form', [$this, 'contact_form_shortcode']);
        add_shortcode('tbm_trainer_profile', [$this, 'trainer_profile_shortcode']);
    }
    
    /**
     * Trainer listing shortcode
     * [tbm_trainer_listing limit="12" expertise="web-development" country="France"]
     */
    public function trainer_listing_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 12,
            'expertise' => '',
            'country' => '',
            'city' => '',
            'featured' => false,
            'layout' => 'grid', // grid, list, carousel
            'show_search' => true,
            'show_filters' => true
        ], $atts);
        
        // Enqueue React if not already done
        wp_enqueue_script('react');
        wp_enqueue_script('react-dom');
        
        $unique_id = 'tbm-trainer-listing-' . uniqid();
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($unique_id); ?>" class="tbm-trainer-listing" data-config="<?php echo esc_attr(json_encode($atts)); ?>"></div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof React !== 'undefined' && typeof ReactDOM !== 'undefined') {
                const config = JSON.parse(document.getElementById('<?php echo $unique_id; ?>').dataset.config);
                ReactDOM.render(
                    React.createElement(TBMTrainerListing, config),
                    document.getElementById('<?php echo $unique_id; ?>')
                );
            }
        });
        
        // Trainer Listing React Component
        function TBMTrainerListing(props) {
            const [trainers, setTrainers] = React.useState([]);
            const [loading, setLoading] = React.useState(true);
            const [filters, setFilters] = React.useState({
                search: '',
                expertise: props.expertise || '',
                country: props.country || '',
                city: props.city || ''
            });
            const [pagination, setPagination] = React.useState({});
            
            React.useEffect(() => {
                fetchTrainers();
            }, [filters]);
            
            const fetchTrainers = async () => {
                setLoading(true);
                try {
                    const params = new URLSearchParams({
                        per_page: props.limit,
                        status: 'active',
                        featured: props.featured,
                        ...filters
                    });
                    
                    const response = await fetch(`${tbmPublic.resturl}trainers?${params}`);
                    const data = await response.json();
                    
                    setTrainers(data.trainers || []);
                    setPagination(data.pagination || {});
                } catch (error) {
                    console.error('Error fetching trainers:', error);
                } finally {
                    setLoading(false);
                }
            };
            
            const handleFilterChange = (key, value) => {
                setFilters(prev => ({ ...prev, [key]: value }));
            };
            
            if (loading) {
                return React.createElement('div', { className: 'tbm-loading' }, 'Chargement...');
            }
            
            return React.createElement('div', { className: 'tbm-trainer-listing-container' }, [
                // Search and filters
                props.show_search && React.createElement(TrainerSearchFilters, {
                    key: 'filters',
                    filters: filters,
                    onFilterChange: handleFilterChange,
                    showFilters: props.show_filters
                }),
                
                // Results
                React.createElement('div', {
                    key: 'results',
                    className: `tbm-trainers-grid tbm-layout-${props.layout}`
                }, 
                    trainers.length > 0 
                        ? trainers.map(trainer => React.createElement(TrainerCard, { 
                            key: trainer.id, 
                            trainer: trainer 
                        }))
                        : React.createElement('div', { className: 'tbm-no-results' }, 'Aucun formateur trouvé.')
                )
            ]);
        }
        
        function TrainerSearchFilters({ filters, onFilterChange, showFilters }) {
            return React.createElement('div', { className: 'tbm-search-filters' }, [
                React.createElement('input', {
                    key: 'search',
                    type: 'text',
                    placeholder: 'Rechercher un formateur...',
                    value: filters.search,
                    onChange: (e) => onFilterChange('search', e.target.value),
                    className: 'tbm-search-input'
                }),
                
                showFilters && React.createElement('div', { key: 'filters', className: 'tbm-filters' }, [
                    React.createElement('select', {
                        key: 'expertise',
                        value: filters.expertise,
                        onChange: (e) => onFilterChange('expertise', e.target.value),
                        className: 'tbm-filter-select'
                    }, [
                        React.createElement('option', { key: '', value: '' }, 'Tous les domaines'),
                        React.createElement('option', { key: 'web', value: 'Développement Web' }, 'Développement Web'),
                        React.createElement('option', { key: 'mobile', value: 'Développement Mobile' }, 'Développement Mobile'),
                        React.createElement('option', { key: 'data', value: 'Data Science' }, 'Data Science'),
                        React.createElement('option', { key: 'design', value: 'UX/UI Design' }, 'UX/UI Design')
                    ]),
                    
                    React.createElement('select', {
                        key: 'country',
                        value: filters.country,
                        onChange: (e) => onFilterChange('country', e.target.value),
                        className: 'tbm-filter-select'
                    }, [
                        React.createElement('option', { key: '', value: '' }, 'Tous les pays'),
                        React.createElement('option', { key: 'FR', value: 'France' }, 'France'),
                        React.createElement('option', { key: 'BE', value: 'Belgique' }, 'Belgique'),
                        React.createElement('option', { key: 'CH', value: 'Suisse' }, 'Suisse'),
                        React.createElement('option', { key: 'CA', value: 'Canada' }, 'Canada')
                    ])
                ])
            ]);
        }
        
        function TrainerCard({ trainer }) {
            return React.createElement('div', { className: 'tbm-trainer-card' }, [
                React.createElement('div', { key: 'header', className: 'tbm-trainer-header' }, [
                    React.createElement('div', { 
                        key: 'avatar',
                        className: 'tbm-trainer-avatar' 
                    }, trainer.name.split(' ').map(n => n[0]).join('')),
                    
                    React.createElement('div', { key: 'info', className: 'tbm-trainer-info' }, [
                        React.createElement('h3', { key: 'name' }, trainer.name),
                        React.createElement('p', { key: 'location' }, trainer.location),
                        trainer.rating.count > 0 && React.createElement('div', { 
                            key: 'rating', 
                            className: 'tbm-trainer-rating' 
                        }, `⭐ ${trainer.rating.average}/5 (${trainer.rating.count} avis)`)
                    ])
                ]),
                
                React.createElement('div', { key: 'body', className: 'tbm-trainer-body' }, [
                    React.createElement('p', { key: 'expertise', className: 'tbm-trainer-expertise' }, trainer.expertise),
                    trainer.bio && React.createElement('p', { key: 'bio', className: 'tbm-trainer-bio' }, trainer.bio)
                ]),
                
                React.createElement('div', { key: 'footer', className: 'tbm-trainer-footer' }, [
                    React.createElement('button', {
                        key: 'contact',
                        className: 'tbm-btn tbm-btn-primary',
                        onClick: () => alert('Fonctionnalité de contact à implémenter')
                    }, 'Contacter'),
                    
                    React.createElement('a', {
                        key: 'profile',
                        href: `#trainer-${trainer.id}`,
                        className: 'tbm-btn tbm-btn-secondary'
                    }, 'Voir profil')
                ])
            ]);
        }
        </script>
        
        <style>
        .tbm-trainer-listing-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .tbm-search-filters {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .tbm-search-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .tbm-filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .tbm-filter-select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            background: white;
        }
        
        .tbm-trainers-grid {
            display: grid;
            gap: 20px;
        }
        
        .tbm-layout-grid {
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        }
        
        .tbm-layout-list .tbm-trainer-card {
            display: flex;
            align-items: center;
            padding: 20px;
        }
        
        .tbm-trainer-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .tbm-trainer-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .tbm-trainer-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .tbm-trainer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .tbm-trainer-info h3 {
            margin: 0 0 5px 0;
            font-size: 18px;
            color: #1f2937;
        }
        
        .tbm-trainer-info p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }
        
        .tbm-trainer-rating {
            color: #f59e0b;
            font-size: 14px;
            font-weight: 500;
        }
        
        .tbm-trainer-expertise {
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .tbm-trainer-bio {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .tbm-trainer-footer {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .tbm-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.2s;
        }
        
        .tbm-btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .tbm-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .tbm-btn-secondary {
            background: #f9fafb;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .tbm-btn-secondary:hover {
            background: #f3f4f6;
        }
        
        .tbm-loading, .tbm-no-results {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Trainer search shortcode
     * [tbm_trainer_search]
     */
    public function trainer_search_shortcode($atts) {
        $atts = shortcode_atts([
            'placeholder' => 'Rechercher un formateur...',
            'redirect_to' => ''
        ], $atts);
        
        ob_start();
        ?>
        <form class="tbm-search-form" method="get" action="<?php echo esc_url($atts['redirect_to'] ?: get_permalink()); ?>">
            <div class="tbm-search-wrapper">
                <input type="text" 
                       name="trainer_search" 
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                       value="<?php echo esc_attr($_GET['trainer_search'] ?? ''); ?>"
                       class="tbm-search-field">
                <button type="submit" class="tbm-search-button">🔍</button>
            </div>
        </form>
        
        <style>
        .tbm-search-form {
            margin: 20px 0;
        }
        
        .tbm-search-wrapper {
            display: flex;
            max-width: 500px;
            margin: 0 auto;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .tbm-search-field {
            flex: 1;
            padding: 12px 16px;
            border: none;
            font-size: 16px;
            outline: none;
        }
        
        .tbm-search-button {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .tbm-search-button:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Trainer application form shortcode
     * [tbm_trainer_application]
     */
    public function trainer_application_shortcode($atts) {
        $atts = shortcode_atts([
            'success_message' => 'Votre candidature a été soumise avec succès !',
            'redirect_after' => true
        ], $atts);
        
        // Handle success/error messages
        $message = '';
        if (isset($_GET['tbm_success']) && $_GET['tbm_success'] === 'application_submitted') {
            $message = '<div class="tbm-message tbm-success">' . esc_html($atts['success_message']) . '</div>';
        } elseif (isset($_GET['tbm_error'])) {
            $message = '<div class="tbm-message tbm-error">Une erreur est survenue. Veuillez réessayer.</div>';
        } elseif (isset($_GET['tbm_errors'])) {
            $errors = explode('|', urldecode($_GET['tbm_errors']));
            $message = '<div class="tbm-message tbm-error">' . implode('<br>', array_map('esc_html', $errors)) . '</div>';
        }
        
        // Render React application form
        $unique_id = 'tbm-application-form-' . uniqid();
        
        ob_start();
        ?>
        <?php echo $message; ?>
        <div id="<?php echo esc_attr($unique_id); ?>" class="tbm-application-form-container"></div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof React !== 'undefined' && typeof ReactDOM !== 'undefined') {
                // Load the trainer application form HTML content
                fetch('<?php echo TBM_ASSETS_URL; ?>templates/modern_trainer_application_form.html')
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const bodyContent = doc.body.innerHTML;
                        document.getElementById('<?php echo $unique_id; ?>').innerHTML = bodyContent;
                        
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
                    });
            } else {
                // Fallback basic form
                document.getElementById('<?php echo $unique_id; ?>').innerHTML = `
                    <form method="post" class="tbm-basic-application-form">
                        <?php wp_nonce_field('tbm_trainer_application'); ?>
                        <input type="hidden" name="tbm_trainer_application" value="1">
                        
                        <div class="tbm-form-row">
                            <div class="tbm-form-group">
                                <label>Prénom *</label>
                                <input type="text" name="first_name" required>
                            </div>
                            <div class="tbm-form-group">
                                <label>Nom *</label>
                                <input type="text" name="last_name" required>
                            </div>
                        </div>
                        
                        <div class="tbm-form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required>
                        </div>
                        
                        <div class="tbm-form-group">
                            <label>Domaine d'expertise *</label>
                            <select name="expertise" required>
                                <option value="">Sélectionnez...</option>
                                <option value="Développement Web">Développement Web</option>
                                <option value="Développement Mobile">Développement Mobile</option>
                                <option value="Data Science">Data Science</option>
                                <option value="UX/UI Design">UX/UI Design</option>
                            </select>
                        </div>
                        
                        <div class="tbm-form-group">
                            <label>Années d'expérience *</label>
                            <input type="number" name="experience_years" min="1" required>
                        </div>
                        
                        <div class="tbm-form-group">
                            <label>Motivation *</label>
                            <textarea name="motivation" rows="5" required placeholder="Minimum 100 caractères..."></textarea>
                        </div>
                        
                        <button type="submit" class="tbm-submit-btn">Soumettre ma candidature</button>
                    </form>
                `;
            }
        });
        </script>
        
        <style>
        .tbm-application-form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .tbm-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .tbm-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .tbm-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .tbm-basic-application-form {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .tbm-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .tbm-form-group {
            margin-bottom: 20px;
        }
        
        .tbm-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .tbm-form-group input,
        .tbm-form-group select,
        .tbm-form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .tbm-form-group input:focus,
        .tbm-form-group select:focus,
        .tbm-form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .tbm-submit-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .tbm-submit-btn:hover {
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .tbm-form-row {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Featured trainers shortcode
     * [tbm_featured_trainers limit="6"]
     */
    public function featured_trainers_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 6,
            'layout' => 'carousel'
        ], $atts);
        
        return $this->trainer_listing_shortcode([
            'limit' => $atts['limit'],
            'featured' => true,
            'layout' => $atts['layout'],
            'show_search' => false,
            'show_filters' => false
        ]);
    }
    
    /**
     * Stats shortcode
     * [tbm_stats]
     */
    public function stats_shortcode($atts) {
        $atts = shortcode_atts([
            'show' => 'trainers,bootcamps,students,hours' // comma-separated
        ], $atts);
        
        $show_stats = explode(',', $atts['show']);
        
        global $wpdb;
        
        $stats = [];
        
        if (in_array('trainers', $show_stats)) {
            $trainers_count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tbm_trainers WHERE status = 'active'"
            );
            $stats['trainers'] = [
                'label' => 'Formateurs actifs',
                'value' => number_format($trainers_count),
                'icon' => '👨‍🏫'
            ];
        }
        
        if (in_array('bootcamps', $show_stats)) {
            $bootcamps_count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tbm_bootcamps WHERE status IN ('active', 'scheduled')"
            );
            $stats['bootcamps'] = [
                'label' => 'Bootcamps disponibles',
                'value' => number_format($bootcamps_count),
                'icon' => '🎓'
            ];
        }
        
        if (in_array('students', $show_stats)) {
            $students_count = $wpdb->get_var(
                "SELECT SUM(current_participants) FROM {$wpdb->prefix}tbm_bootcamps WHERE status = 'active'"
            );
            $stats['students'] = [
                'label' => 'Étudiants formés',
                'value' => number_format($students_count ?: 0),
                'icon' => '👥'
            ];
        }
        
        if (in_array('hours', $show_stats)) {
            $total_hours = $wpdb->get_var(
                "SELECT SUM(duration_minutes)/60 FROM {$wpdb->prefix}tbm_sessions WHERE status = 'completed'"
            );
            $stats['hours'] = [
                'label' => 'Heures de formation',
                'value' => number_format($total_hours ?: 0),
                'icon' => '⏰'
            ];
        }
        
        ob_start();
        ?>
        <div class="tbm-stats-container">
            <?php foreach ($stats as $stat): ?>
            <div class="tbm-stat-item">
                <div class="tbm-stat-icon"><?php echo $stat['icon']; ?></div>
                <div class="tbm-stat-content">
                    <div class="tbm-stat-value"><?php echo $stat['value']; ?></div>
                    <div class="tbm-stat-label"><?php echo $stat['label']; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <style>
        .tbm-stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .tbm-stat-item {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        
        .tbm-stat-item:hover {
            transform: translateY(-3px);
        }
        
        .tbm-stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .tbm-stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .tbm-stat-label {
            color: #6b7280;
            font-weight: 500;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Contact form shortcode
     * [tbm_contact_form]
     */
    public function contact_form_shortcode($atts) {
        $atts = shortcode_atts([
            'title' => 'Contactez-nous',
            'success_message' => 'Votre message a été envoyé avec succès !'
        ], $atts);
        
        // Handle messages
        $message = '';
        if (isset($_GET['tbm_success']) && $_GET['tbm_success'] === 'message_sent') {
            $message = '<div class="tbm-message tbm-success">' . esc_html($atts['success_message']) . '</div>';
        } elseif (isset($_GET['tbm_error'])) {
            $message = '<div class="tbm-message tbm-error">Erreur lors de l\'envoi. Veuillez réessayer.</div>';
        }
        
        ob_start();
        ?>
        <div class="tbm-contact-form-container">
            <?php if ($atts['title']): ?>
            <h3 class="tbm-contact-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>
            
            <?php echo $message; ?>
            
            <form method="post" class="tbm-contact-form">
                <?php wp_nonce_field('tbm_contact_form'); ?>
                <input type="hidden" name="tbm_contact_form" value="1">
                
                <div class="tbm-form-row">
                    <div class="tbm-form-group">
                        <label for="contact_name">Nom *</label>
                        <input type="text" id="contact_name" name="name" required>
                    </div>
                    <div class="tbm-form-group">
                        <label for="contact_email">Email *</label>
                        <input type="email" id="contact_email" name="email" required>
                    </div>
                </div>
                
                <div class="tbm-form-group">
                    <label for="contact_subject">Sujet</label>
                    <input type="text" id="contact_subject" name="subject">
                </div>
                
                <div class="tbm-form-group">
                    <label for="contact_message">Message *</label>
                    <textarea id="contact_message" name="message" rows="6" required></textarea>
                </div>
                
                <button type="submit" class="tbm-submit-btn">Envoyer le message</button>
            </form>
        </div>
        
        <style>
        .tbm-contact-form-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .tbm-contact-title {
            text-align: center;
            margin-bottom: 30px;
            color: #1f2937;
        }
        
        .tbm-contact-form {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Trainer profile shortcode
     * [tbm_trainer_profile id="123"]
     */
    public function trainer_profile_shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0
        ], $atts);
        
        if (!$atts['id']) {
            return '<p>ID du formateur requis.</p>';
        }
        
        $public = new TBM_Public();
        $trainer = $public->get_trainer_data($atts['id']);
        
        if (!$trainer) {
            return '<p>Formateur non trouvé.</p>';
        }
        
        ob_start();
        ?>
        <div class="tbm-trainer-profile">
            <div class="tbm-trainer-header">
                <div class="tbm-trainer-avatar">
                    <?php echo esc_html($trainer['name'][0]); ?>
                </div>
                <div class="tbm-trainer-info">
                    <h2><?php echo esc_html($trainer['name']); ?></h2>
                    <p class="tbm-trainer-location"><?php echo esc_html($trainer['location']); ?></p>
                    <p class="tbm-trainer-expertise"><?php echo esc_html($trainer['expertise']); ?></p>
                    <?php if ($trainer['rating']['count'] > 0): ?>
                    <div class="tbm-trainer-rating">
                        ⭐ <?php echo esc_html($trainer['rating']['average']); ?>/5 
                        (<?php echo esc_html($trainer['rating']['count']); ?> avis)
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($trainer['bio']): ?>
            <div class="tbm-trainer-bio">
                <h3>À propos</h3>
                <p><?php echo esc_html($trainer['bio']); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="tbm-trainer-actions">
                <button class="tbm-btn tbm-btn-primary" onclick="alert('Fonctionnalité de contact à implémenter')">
                    Contacter ce formateur
                </button>
            </div>
        </div>
        
        <style>
        .tbm-trainer-profile {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .tbm-trainer-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .tbm-trainer-profile .tbm-trainer-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 600;
        }
        
        .tbm-trainer-info h2 {
            margin: 0 0 10px 0;
            color: #1f2937;
        }
        
        .tbm-trainer-location {
            color: #6b7280;
            margin: 5px 0;
        }
        
        .tbm-trainer-expertise {
            background: #dbeafe;
            color: #1e40af;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            display: inline-block;
            margin: 10px 0;
        }
        
        .tbm-trainer-rating {
            color: #f59e0b;
            font-weight: 500;
        }
        
        .tbm-trainer-bio h3 {
            margin-bottom: 15px;
            color: #1f2937;
        }
        
        .tbm-trainer-bio p {
            line-height: 1.6;
            color: #4b5563;
        }
        
        .tbm-trainer-actions {
            margin-top: 30px;
            text-align: center;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Bootcamp listing shortcode
     * [tbm_bootcamp_listing]
     */
    public function bootcamp_listing_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 12,
            'category' => '',
            'status' => 'scheduled,active',
            'layout' => 'grid'
        ], $atts);
        
        // Implementation similar to trainer listing
        return '<div class="tbm-bootcamp-listing">Bootcamp listing à implémenter</div>';
    }
}