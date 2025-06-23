<?php
/**
 * Analytics and Reporting System
 * 
 * Advanced analytics system with comprehensive reporting capabilities
 * 
 * @package TrainerBootcampManager
 * @subpackage Analytics
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Analytics {
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Analytics table
     */
    private $analytics_table;
    
    /**
     * Cache group
     */
    const CACHE_GROUP = 'tbm_analytics';
    
    /**
     * Metric types
     */
    const METRICS = [
        'trainer_applications' => 'Candidatures formateurs',
        'trainer_approvals' => 'Approbations formateurs',
        'trainer_sessions' => 'Sessions dispensées',
        'trainer_earnings' => 'Revenus formateurs',
        'trainer_ratings' => 'Évaluations formateurs',
        'bootcamp_enrollments' => 'Inscriptions bootcamps',
        'bootcamp_completions' => 'Complétions bootcamps',
        'session_attendance' => 'Présence sessions',
        'platform_revenue' => 'Revenus plateforme',
        'user_engagement' => 'Engagement utilisateurs',
        'conversion_rates' => 'Taux de conversion',
        'retention_rates' => 'Taux de rétention'
    ];
    
    /**
     * Report types
     */
    const REPORT_TYPES = [
        'performance' => 'Performance des formateurs',
        'financial' => 'Rapport financier',
        'engagement' => 'Engagement utilisateurs',
        'operational' => 'Rapport opérationnel',
        'custom' => 'Rapport personnalisé'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->analytics_table = $wpdb->prefix . 'tbm_analytics';
        
        add_action('init', [$this, 'init_hooks']);
        add_action('wp_ajax_tbm_get_analytics_data', [$this, 'ajax_get_analytics_data']);
        add_action('wp_ajax_tbm_generate_report', [$this, 'ajax_generate_report']);
        add_action('wp_ajax_tbm_export_report', [$this, 'ajax_export_report']);
        add_action('tbm_daily_analytics', [$this, 'collect_daily_metrics']);
        add_action('tbm_weekly_analytics', [$this, 'collect_weekly_metrics']);
        add_action('tbm_monthly_analytics', [$this, 'collect_monthly_metrics']);
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Track events
        add_action('tbm_application_submitted', [$this, 'track_application_submitted'], 10, 1);
        add_action('tbm_application_approved', [$this, 'track_application_approved'], 10, 1);
        add_action('tbm_session_completed', [$this, 'track_session_completed'], 10, 1);
        add_action('tbm_payment_processed', [$this, 'track_payment_processed'], 10, 1);
        add_action('tbm_rating_submitted', [$this, 'track_rating_submitted'], 10, 2);
        add_action('wp_login', [$this, 'track_user_login'], 10, 2);
        
        // Schedule automated reports
        if (!wp_next_scheduled('tbm_weekly_report')) {
            wp_schedule_event(time(), 'weekly', 'tbm_weekly_report');
        }
        
        if (!wp_next_scheduled('tbm_monthly_report')) {
            wp_schedule_event(time(), 'monthly', 'tbm_monthly_report');
        }
        
        add_action('tbm_weekly_report', [$this, 'generate_weekly_report']);
        add_action('tbm_monthly_report', [$this, 'generate_monthly_report']);
    }
    
    /**
     * Record metric
     */
    public function record_metric($metric_name, $value, $date = null, $dimensions = [], $metadata = []) {
        if (!array_key_exists($metric_name, self::METRICS)) {
            return new WP_Error('invalid_metric', 'Invalid metric name');
        }
        
        $data = [
            'metric_name' => $metric_name,
            'metric_value' => floatval($value),
            'metric_date' => $date ?: current_time('Y-m-d'),
            'dimension_1' => isset($dimensions['dimension_1']) ? sanitize_text_field($dimensions['dimension_1']) : null,
            'dimension_2' => isset($dimensions['dimension_2']) ? sanitize_text_field($dimensions['dimension_2']) : null,
            'dimension_3' => isset($dimensions['dimension_3']) ? sanitize_text_field($dimensions['dimension_3']) : null,
            'user_id' => isset($dimensions['user_id']) ? intval($dimensions['user_id']) : null,
            'trainer_id' => isset($dimensions['trainer_id']) ? intval($dimensions['trainer_id']) : null,
            'bootcamp_id' => isset($dimensions['bootcamp_id']) ? intval($dimensions['bootcamp_id']) : null,
            'session_id' => isset($dimensions['session_id']) ? intval($dimensions['session_id']) : null,
            'metadata' => !empty($metadata) ? json_encode($metadata) : null,
            'created_at' => current_time('mysql')
        ];
        
        $result = $this->wpdb->insert($this->analytics_table, $data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to record metric');
        }
        
        // Clear relevant caches
        $this->clear_metric_cache($metric_name);
        
        return $this->wpdb->insert_id;
    }
    
    /**
     * Get metrics data
     */
    public function get_metrics($metric_name, $args = []) {
        $defaults = [
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
            'dimension_1' => null,
            'dimension_2' => null,
            'dimension_3' => null,
            'trainer_id' => null,
            'bootcamp_id' => null,
            'session_id' => null,
            'aggregate' => 'sum', // sum, avg, count, min, max
            'group_by' => 'date', // date, dimension_1, dimension_2, etc.
            'limit' => 1000
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Check cache first
        $cache_key = 'metrics_' . md5(serialize([$metric_name, $args]));
        $cached_result = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if ($cached_result !== false) {
            return $cached_result;
        }
        
        // Build query
        $where_conditions = ['metric_name = %s'];
        $where_values = [$metric_name];
        
        if ($args['date_from']) {
            $where_conditions[] = 'metric_date >= %s';
            $where_values[] = $args['date_from'];
        }
        
        if ($args['date_to']) {
            $where_conditions[] = 'metric_date <= %s';
            $where_values[] = $args['date_to'];
        }
        
        foreach (['dimension_1', 'dimension_2', 'dimension_3', 'trainer_id', 'bootcamp_id', 'session_id'] as $field) {
            if (!is_null($args[$field])) {
                $where_conditions[] = "{$field} = %s";
                $where_values[] = $args[$field];
            }
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Select and group by clauses
        $select_fields = [$args['group_by']];
        $aggregate_function = strtoupper($args['aggregate']);
        $select_fields[] = "{$aggregate_function}(metric_value) as value";
        
        if ($args['group_by'] !== 'metric_date') {
            $select_fields[] = 'COUNT(*) as count';
        }
        
        $select_clause = 'SELECT ' . implode(', ', $select_fields);
        $group_clause = 'GROUP BY ' . $args['group_by'];
        $order_clause = 'ORDER BY ' . $args['group_by'];
        $limit_clause = 'LIMIT ' . intval($args['limit']);
        
        $query = $this->wpdb->prepare(
            "{$select_clause} FROM {$this->analytics_table} {$where_clause} {$group_clause} {$order_clause} {$limit_clause}",
            $where_values
        );
        
        $results = $this->wpdb->get_results($query, ARRAY_A);
        
        // Cache results for 1 hour
        wp_cache_set($cache_key, $results, self::CACHE_GROUP, 3600);
        
        return $results;
    }
    
    /**
     * Get dashboard analytics
     */
    public function get_dashboard_analytics($period = '30_days') {
        $cache_key = 'dashboard_analytics_' . $period;
        $cached_result = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if ($cached_result !== false) {
            return $cached_result;
        }
        
        switch ($period) {
            case '7_days':
                $date_from = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30_days':
                $date_from = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90_days':
                $date_from = date('Y-m-d', strtotime('-90 days'));
                break;
            case '1_year':
                $date_from = date('Y-m-d', strtotime('-1 year'));
                break;
            default:
                $date_from = date('Y-m-d', strtotime('-30 days'));
        }
        
        $date_to = date('Y-m-d');
        
        $analytics = [
            'overview' => $this->get_overview_metrics($date_from, $date_to),
            'trainers' => $this->get_trainer_metrics($date_from, $date_to),
            'bootcamps' => $this->get_bootcamp_metrics($date_from, $date_to),
            'financial' => $this->get_financial_metrics($date_from, $date_to),
            'trends' => $this->get_trend_data($date_from, $date_to),
            'top_performers' => $this->get_top_performers($date_from, $date_to)
        ];
        
        // Cache for 30 minutes
        wp_cache_set($cache_key, $analytics, self::CACHE_GROUP, 1800);
        
        return $analytics;
    }
    
    /**
     * Get overview metrics
     */
    private function get_overview_metrics($date_from, $date_to) {
        $trainers_table = $this->wpdb->prefix . 'tbm_trainers';
        $applications_table = $this->wpdb->prefix . 'tbm_applications';
        $sessions_table = $this->wpdb->prefix . 'tbm_sessions';
        $bootcamps_table = $this->wpdb->prefix . 'tbm_bootcamps';
        
        // Total trainers
        $total_trainers = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$trainers_table} WHERE status = 'active'"
        );
        
        // New applications in period
        $new_applications = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$applications_table} 
             WHERE submitted_at BETWEEN %s AND %s",
            $date_from, $date_to . ' 23:59:59'
        ));
        
        // Total sessions in period
        $total_sessions = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessions_table} 
             WHERE DATE(start_datetime) BETWEEN %s AND %s",
            $date_from, $date_to
        ));
        
        // Active bootcamps
        $active_bootcamps = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$bootcamps_table} WHERE status = 'active'"
        );
        
        // Average rating
        $avg_rating = $this->wpdb->get_var(
            "SELECT AVG(rating_average) FROM {$trainers_table} WHERE rating_count > 0"
        );
        
        return [
            'total_trainers' => intval($total_trainers),
            'new_applications' => intval($new_applications),
            'total_sessions' => intval($total_sessions),
            'active_bootcamps' => intval($active_bootcamps),
            'average_rating' => round(floatval($avg_rating), 2)
        ];
    }
    
    /**
     * Get trainer metrics
     */
    private function get_trainer_metrics($date_from, $date_to) {
        $trainers_table = $this->wpdb->prefix . 'tbm_trainers';
        $sessions_table = $this->wpdb->prefix . 'tbm_sessions';
        
        // Trainer performance data
        $trainer_performance = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                t.id,
                t.first_name,
                t.last_name,
                t.rating_average,
                t.total_sessions,
                t.total_earnings,
                COUNT(s.id) as sessions_in_period,
                AVG(s.rating_average) as period_rating
             FROM {$trainers_table} t
             LEFT JOIN {$sessions_table} s ON t.id = s.trainer_id 
                AND DATE(s.start_datetime) BETWEEN %s AND %s
             WHERE t.status = 'active'
             GROUP BY t.id
             ORDER BY sessions_in_period DESC, t.rating_average DESC
             LIMIT 10",
            $date_from, $date_to
        ), ARRAY_A);
        
        // Trainer distribution by expertise
        $expertise_distribution = $this->wpdb->get_results(
            "SELECT expertise_areas, COUNT(*) as count 
             FROM {$trainers_table} 
             WHERE status = 'active' AND expertise_areas IS NOT NULL
             GROUP BY expertise_areas
             ORDER BY count DESC
             LIMIT 10",
            ARRAY_A
        );
        
        return [
            'performance' => $trainer_performance,
            'expertise_distribution' => $expertise_distribution
        ];
    }
    
    /**
     * Get bootcamp metrics
     */
    private function get_bootcamp_metrics($date_from, $date_to) {
        $bootcamps_table = $this->wpdb->prefix . 'tbm_bootcamps';
        $sessions_table = $this->wpdb->prefix . 'tbm_sessions';
        
        // Bootcamp completion rates
        $bootcamp_stats = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                b.id,
                b.title,
                b.max_participants,
                b.current_participants,
                COUNT(s.id) as total_sessions,
                SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
                AVG(s.attendance_count) as avg_attendance
             FROM {$bootcamps_table} b
             LEFT JOIN {$sessions_table} s ON b.id = s.bootcamp_id
             WHERE b.start_date BETWEEN %s AND %s
             GROUP BY b.id
             ORDER BY b.current_participants DESC
             LIMIT 10",
            $date_from, $date_to
        ), ARRAY_A);
        
        // Category distribution
        $category_distribution = $this->wpdb->get_results(
            "SELECT category, COUNT(*) as count, SUM(current_participants) as total_participants
             FROM {$bootcamps_table}
             WHERE status IN ('active', 'completed')
             GROUP BY category
             ORDER BY count DESC",
            ARRAY_A
        );
        
        return [
            'bootcamp_stats' => $bootcamp_stats,
            'category_distribution' => $category_distribution
        ];
    }
    
    /**
     * Get financial metrics
     */
    private function get_financial_metrics($date_from, $date_to) {
        $payments_table = $this->wpdb->prefix . 'tbm_payments';
        
        // Revenue by period
        $revenue_data = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                DATE(paid_date) as date,
                SUM(amount) as total_revenue,
                SUM(net_amount) as net_revenue,
                SUM(gateway_fee) as total_fees,
                COUNT(*) as payment_count
             FROM {$payments_table}
             WHERE status = 'completed' 
             AND paid_date BETWEEN %s AND %s
             GROUP BY DATE(paid_date)
             ORDER BY date",
            $date_from, $date_to . ' 23:59:59'
        ), ARRAY_A);
        
        // Payment method distribution
        $payment_methods = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                payment_method,
                COUNT(*) as count,
                SUM(amount) as total_amount
             FROM {$payments_table}
             WHERE status = 'completed' 
             AND paid_date BETWEEN %s AND %s
             GROUP BY payment_method
             ORDER BY total_amount DESC",
            $date_from, $date_to . ' 23:59:59'
        ), ARRAY_A);
        
        // Top earning trainers
        $top_earners = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                t.id,
                t.first_name,
                t.last_name,
                SUM(p.net_amount) as period_earnings,
                COUNT(p.id) as payment_count
             FROM {$payments_table} p
             JOIN {$this->wpdb->prefix}tbm_trainers t ON p.trainer_id = t.id
             WHERE p.status = 'completed' 
             AND p.paid_date BETWEEN %s AND %s
             GROUP BY p.trainer_id
             ORDER BY period_earnings DESC
             LIMIT 10",
            $date_from, $date_to . ' 23:59:59'
        ), ARRAY_A);
        
        return [
            'revenue_data' => $revenue_data,
            'payment_methods' => $payment_methods,
            'top_earners' => $top_earners
        ];
    }
    
    /**
     * Get trend data
     */
    private function get_trend_data($date_from, $date_to) {
        // Daily metrics for the period
        $daily_trends = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                metric_date as date,
                metric_name,
                SUM(metric_value) as value
             FROM {$this->analytics_table}
             WHERE metric_date BETWEEN %s AND %s
             AND metric_name IN ('trainer_sessions', 'bootcamp_enrollments', 'platform_revenue')
             GROUP BY metric_date, metric_name
             ORDER BY metric_date",
            $date_from, $date_to
        ), ARRAY_A);
        
        // Format data for charts
        $formatted_trends = [];
        foreach ($daily_trends as $trend) {
            $formatted_trends[$trend['metric_name']][] = [
                'date' => $trend['date'],
                'value' => floatval($trend['value'])
            ];
        }
        
        return $formatted_trends;
    }
    
    /**
     * Get top performers
     */
    private function get_top_performers($date_from, $date_to) {
        $trainers_table = $this->wpdb->prefix . 'tbm_trainers';
        $sessions_table = $this->wpdb->prefix . 'tbm_sessions';
        
        // Top rated trainers
        $top_rated = $this->wpdb->get_results(
            "SELECT id, first_name, last_name, rating_average, rating_count
             FROM {$trainers_table}
             WHERE status = 'active' AND rating_count >= 3
             ORDER BY rating_average DESC, rating_count DESC
             LIMIT 5",
            ARRAY_A
        );
        
        // Most active trainers
        $most_active = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                t.id, t.first_name, t.last_name,
                COUNT(s.id) as sessions_count,
                AVG(s.attendance_count) as avg_attendance
             FROM {$trainers_table} t
             JOIN {$sessions_table} s ON t.id = s.trainer_id
             WHERE s.start_datetime BETWEEN %s AND %s
             AND s.status = 'completed'
             GROUP BY t.id
             ORDER BY sessions_count DESC
             LIMIT 5",
            $date_from, $date_to . ' 23:59:59'
        ), ARRAY_A);
        
        return [
            'top_rated' => $top_rated,
            'most_active' => $most_active
        ];
    }
    
    /**
     * Generate report
     */
    public function generate_report($type, $args = []) {
        $defaults = [
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
            'trainer_ids' => [],
            'bootcamp_ids' => [],
            'format' => 'array', // array, json, csv, pdf
            'include_charts' => false
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        switch ($type) {
            case 'performance':
                $report_data = $this->generate_performance_report($args);
                break;
                
            case 'financial':
                $report_data = $this->generate_financial_report($args);
                break;
                
            case 'engagement':
                $report_data = $this->generate_engagement_report($args);
                break;
                
            case 'operational':
                $report_data = $this->generate_operational_report($args);
                break;
                
            default:
                return new WP_Error('invalid_report_type', 'Invalid report type');
        }
        
        // Format output
        switch ($args['format']) {
            case 'json':
                return json_encode($report_data);
                
            case 'csv':
                return $this->format_report_as_csv($report_data);
                
            case 'pdf':
                return $this->format_report_as_pdf($report_data, $type, $args);
                
            default:
                return $report_data;
        }
    }
    
    /**
     * Generate performance report
     */
    private function generate_performance_report($args) {
        $trainers_table = $this->wpdb->prefix . 'tbm_trainers';
        $sessions_table = $this->wpdb->prefix . 'tbm_sessions';
        $ratings_table = $this->wpdb->prefix . 'tbm_ratings';
        
        // Trainer performance metrics
        $where_trainer = '';
        $where_values = [$args['date_from'], $args['date_to']];
        
        if (!empty($args['trainer_ids'])) {
            $placeholders = implode(',', array_fill(0, count($args['trainer_ids']), '%d'));
            $where_trainer = "AND t.id IN ({$placeholders})";
            $where_values = array_merge($where_values, $args['trainer_ids']);
        }
        
        $performance_data = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                t.id,
                t.first_name,
                t.last_name,
                t.expertise_areas,
                t.rating_average,
                t.rating_count,
                COUNT(DISTINCT s.id) as sessions_completed,
                SUM(s.duration_minutes) as total_minutes_taught,
                AVG(s.attendance_count) as avg_attendance,
                COUNT(DISTINCT r.id) as reviews_received,
                AVG(r.rating) as period_rating
             FROM {$trainers_table} t
             LEFT JOIN {$sessions_table} s ON t.id = s.trainer_id 
                AND s.start_datetime BETWEEN %s AND %s
                AND s.status = 'completed'
             LEFT JOIN {$ratings_table} r ON t.id = r.trainer_id
                AND r.created_at BETWEEN %s AND %s
             WHERE t.status = 'active' {$where_trainer}
             GROUP BY t.id
             ORDER BY sessions_completed DESC, t.rating_average DESC",
            array_merge($where_values, [$args['date_from'], $args['date_to']])
        ), ARRAY_A);
        
        return [
            'report_type' => 'performance',
            'period' => [
                'from' => $args['date_from'],
                'to' => $args['date_to']
            ],
            'summary' => [
                'total_trainers' => count($performance_data),
                'total_sessions' => array_sum(array_column($performance_data, 'sessions_completed')),
                'total_hours' => round(array_sum(array_column($performance_data, 'total_minutes_taught')) / 60, 2),
                'average_rating' => round(array_sum(array_column($performance_data, 'period_rating')) / count($performance_data), 2)
            ],
            'data' => $performance_data,
            'generated_at' => current_time('mysql')
        ];
    }
    
    /**
     * Generate financial report
     */
    private function generate_financial_report($args) {
        $payments_table = $this->wpdb->prefix . 'tbm_payments';
        $trainers_table = $this->wpdb->prefix . 'tbm_trainers';
        
        // Financial metrics
        $financial_data = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                DATE(p.paid_date) as date,
                SUM(p.amount) as gross_revenue,
                SUM(p.net_amount) as net_revenue,
                SUM(p.gateway_fee) as total_fees,
                COUNT(p.id) as payment_count,
                COUNT(DISTINCT p.trainer_id) as unique_trainers
             FROM {$payments_table} p
             WHERE p.status = 'completed'
             AND p.paid_date BETWEEN %s AND %s
             GROUP BY DATE(p.paid_date)
             ORDER BY date",
            $args['date_from'], $args['date_to'] . ' 23:59:59'
        ), ARRAY_A);
        
        // Trainer earnings
        $trainer_earnings = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT 
                t.id,
                t.first_name,
                t.last_name,
                SUM(p.amount) as gross_earnings,
                SUM(p.net_amount) as net_earnings,
                COUNT(p.id) as payment_count
             FROM {$payments_table} p
             JOIN {$trainers_table} t ON p.trainer_id = t.id
             WHERE p.status = 'completed'
             AND p.paid_date BETWEEN %s AND %s
             GROUP BY p.trainer_id
             ORDER BY net_earnings DESC",
            $args['date_from'], $args['date_to'] . ' 23:59:59'
        ), ARRAY_A);
        
        $total_gross = array_sum(array_column($financial_data, 'gross_revenue'));
        $total_net = array_sum(array_column($financial_data, 'net_revenue'));
        $total_fees = array_sum(array_column($financial_data, 'total_fees'));
        
        return [
            'report_type' => 'financial',
            'period' => [
                'from' => $args['date_from'],
                'to' => $args['date_to']
            ],
            'summary' => [
                'total_gross_revenue' => $total_gross,
                'total_net_revenue' => $total_net,
                'total_fees' => $total_fees,
                'fee_percentage' => $total_gross > 0 ? round(($total_fees / $total_gross) * 100, 2) : 0,
                'average_daily_revenue' => round($total_gross / max(1, count($financial_data)), 2)
            ],
            'daily_data' => $financial_data,
            'trainer_earnings' => $trainer_earnings,
            'generated_at' => current_time('mysql')
        ];
    }
    
    /**
     * Format report as CSV
     */
    private function format_report_as_csv($report_data) {
        if (empty($report_data['data'])) {
            return '';
        }
        
        $csv_output = '';
        $headers = array_keys($report_data['data'][0]);
        $csv_output .= implode(',', $headers) . "\n";
        
        foreach ($report_data['data'] as $row) {
            $csv_output .= implode(',', array_map(function($value) {
                return '"' . str_replace('"', '""', $value) . '"';
            }, $row)) . "\n";
        }
        
        return $csv_output;
    }
    
    /**
     * Format report as PDF
     */
    private function format_report_as_pdf($report_data, $type, $args) {
        if (!class_exists('TCPDF')) {
            return new WP_Error('tcpdf_not_available', 'TCPDF library not available');
        }
        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Trainer Bootcamp Manager');
        $pdf->SetAuthor(get_option('blogname'));
        $pdf->SetTitle('Rapport ' . ucfirst($type));
        
        // Add a page
        $pdf->AddPage();
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Rapport ' . ucfirst($type), 0, 1, 'C');
        
        // Period
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Période: ' . $args['date_from'] . ' au ' . $args['date_to'], 0, 1, 'C');
        $pdf->Ln(10);
        
        // Summary
        if (isset($report_data['summary'])) {
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 10, 'Résumé', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            
            foreach ($report_data['summary'] as $key => $value) {
                $pdf->Cell(0, 6, ucfirst(str_replace('_', ' ', $key)) . ': ' . $value, 0, 1);
            }
            $pdf->Ln(10);
        }
        
        // Data table
        if (isset($report_data['data']) && !empty($report_data['data'])) {
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 10, 'Données détaillées', 0, 1);
            
            // Table headers
            $headers = array_keys($report_data['data'][0]);
            $col_width = 180 / count($headers);
            
            foreach ($headers as $header) {
                $pdf->Cell($col_width, 8, ucfirst(str_replace('_', ' ', $header)), 1, 0, 'C');
            }
            $pdf->Ln();
            
            // Table data
            $pdf->SetFont('helvetica', '', 8);
            foreach ($report_data['data'] as $row) {
                foreach ($row as $cell) {
                    $pdf->Cell($col_width, 6, $cell, 1, 0, 'C');
                }
                $pdf->Ln();
            }
        }
        
        // Save to uploads directory
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager/reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        $filename = 'report-' . $type . '-' . date('Y-m-d-H-i-s') . '.pdf';
        $file_path = $reports_dir . '/' . $filename;
        
        $pdf->Output($file_path, 'F');
        
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
    }
    
    /**
     * Clear metric cache
     */
    private function clear_metric_cache($metric_name) {
        wp_cache_delete('dashboard_analytics_7_days', self::CACHE_GROUP);
        wp_cache_delete('dashboard_analytics_30_days', self::CACHE_GROUP);
        wp_cache_delete('dashboard_analytics_90_days', self::CACHE_GROUP);
        wp_cache_delete('dashboard_analytics_1_year', self::CACHE_GROUP);
        
        // Clear specific metric caches
        wp_cache_flush_group(self::CACHE_GROUP);
    }
    
    // Event tracking methods
    
    /**
     * Track application submitted
     */
    public function track_application_submitted($application_id) {
        $this->record_metric('trainer_applications', 1, null, [
            'dimension_1' => 'submitted'
        ]);
    }
    
    /**
     * Track application approved
     */
    public function track_application_approved($application_id) {
        $this->record_metric('trainer_approvals', 1, null, [
            'dimension_1' => 'approved'
        ]);
    }
    
    /**
     * Track session completed
     */
    public function track_session_completed($session_id) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'tbm_sessions';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table} WHERE id = %d",
            $session_id
        ));
        
        if ($session) {
            $this->record_metric('trainer_sessions', 1, null, [
                'trainer_id' => $session->trainer_id,
                'bootcamp_id' => $session->bootcamp_id,
                'session_id' => $session_id
            ]);
            
            $this->record_metric('session_attendance', $session->attendance_count, null, [
                'trainer_id' => $session->trainer_id,
                'session_id' => $session_id
            ]);
        }
    }
    
    /**
     * Track payment processed
     */
    public function track_payment_processed($payment_id) {
        global $wpdb;
        $payments_table = $wpdb->prefix . 'tbm_payments';
        
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$payments_table} WHERE id = %d",
            $payment_id
        ));
        
        if ($payment) {
            $this->record_metric('trainer_earnings', $payment->net_amount, null, [
                'trainer_id' => $payment->trainer_id,
                'dimension_1' => $payment->type
            ]);
            
            $this->record_metric('platform_revenue', $payment->gateway_fee, null, [
                'dimension_1' => $payment->payment_method
            ]);
        }
    }
    
    /**
     * Track rating submitted
     */
    public function track_rating_submitted($trainer_id, $rating) {
        $this->record_metric('trainer_ratings', $rating, null, [
            'trainer_id' => $trainer_id
        ]);
    }
    
    /**
     * Track user login
     */
    public function track_user_login($user_login, $user) {
        if (in_array('tbm_trainer', $user->roles)) {
            $this->record_metric('user_engagement', 1, null, [
                'user_id' => $user->ID,
                'dimension_1' => 'trainer_login'
            ]);
        }
    }
    
    /**
     * Collect daily metrics
     */
    public function collect_daily_metrics() {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // Collect various metrics for yesterday
        $this->collect_trainer_metrics($yesterday);
        $this->collect_bootcamp_metrics($yesterday);
        $this->collect_financial_metrics($yesterday);
        $this->collect_engagement_metrics($yesterday);
    }
    
    /**
     * AJAX: Get analytics data
     */
    public function ajax_get_analytics_data() {
        check_ajax_referer('tbm_analytics_nonce', 'nonce');
        
        if (!current_user_can('view_tbm_analytics')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $period = sanitize_text_field($_POST['period'] ?? '30_days');
        $analytics = $this->get_dashboard_analytics($period);
        
        wp_send_json_success($analytics);
    }
    
    /**
     * AJAX: Generate report
     */
    public function ajax_generate_report() {
        check_ajax_referer('tbm_analytics_nonce', 'nonce');
        
        if (!current_user_can('generate_tbm_reports')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $type = sanitize_text_field($_POST['type'] ?? 'performance');
        $args = [
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
            'format' => sanitize_text_field($_POST['format'] ?? 'array'),
            'trainer_ids' => array_map('intval', $_POST['trainer_ids'] ?? []),
            'bootcamp_ids' => array_map('intval', $_POST['bootcamp_ids'] ?? [])
        ];
        
        $report = $this->generate_report($type, $args);
        
        if (is_wp_error($report)) {
            wp_send_json_error($report->get_error_message());
        }
        
        wp_send_json_success([
            'report' => $report,
            'type' => $type,
            'args' => $args
        ]);
    }
    
    /**
     * Generate weekly automated report
     */
    public function generate_weekly_report() {
        $date_from = date('Y-m-d', strtotime('-7 days'));
        $date_to = date('Y-m-d', strtotime('-1 day'));
        
        $report = $this->generate_report('performance', [
            'date_from' => $date_from,
            'date_to' => $date_to,
            'format' => 'pdf'
        ]);
        
        if (!is_wp_error($report)) {
            // Send report to administrators
            $this->send_report_email('weekly', $report);
        }
    }
    
    /**
     * Generate monthly automated report
     */
    public function generate_monthly_report() {
        $date_from = date('Y-m-01', strtotime('-1 month'));
        $date_to = date('Y-m-t', strtotime('-1 month'));
        
        $reports = [
            'performance' => $this->generate_report('performance', [
                'date_from' => $date_from,
                'date_to' => $date_to,
                'format' => 'pdf'
            ]),
            'financial' => $this->generate_report('financial', [
                'date_from' => $date_from,
                'date_to' => $date_to,
                'format' => 'pdf'
            ])
        ];
        
        // Send reports to administrators
        foreach ($reports as $type => $report) {
            if (!is_wp_error($report)) {
                $this->send_report_email('monthly_' . $type, $report);
            }
        }
    }
    
    /**
     * Send report email
     */
    private function send_report_email($type, $report_url) {
        $admin_users = get_users(['role' => 'administrator']);
        
        foreach ($admin_users as $admin) {
            $subject = sprintf(
                'Rapport %s - %s',
                ucfirst($type),
                get_option('blogname')
            );
            
            $message = sprintf(
                "Bonjour %s,\n\n" .
                "Votre rapport %s est disponible en téléchargement :\n" .
                "%s\n\n" .
                "Cordialement,\n" .
                "Trainer Bootcamp Manager",
                $admin->display_name,
                $type,
                $report_url
            );
            
            wp_mail($admin->user_email, $subject, $message);
        }
    }
}