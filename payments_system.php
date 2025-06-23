<?php
/**
 * Payments System
 * 
 * Modern payment management system with multiple gateways and automated processing
 * 
 * @package TrainerBootcampManager
 * @subpackage Payments
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class TBM_Payments {
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Payments table
     */
    private $payments_table;
    
    /**
     * Contracts table
     */
    private $contracts_table;
    
    /**
     * Supported payment gateways
     */
    const GATEWAYS = [
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'bank_transfer' => 'Virement bancaire',
        'check' => 'Chèque',
        'cash' => 'Espèces'
    ];
    
    /**
     * Payment statuses
     */
    const STATUSES = [
        'pending' => 'En attente',
        'processing' => 'En cours',
        'completed' => 'Complété',
        'failed' => 'Échoué',
        'refunded' => 'Remboursé',
        'cancelled' => 'Annulé'
    ];
    
    /**
     * Payment types
     */
    const TYPES = [
        'session' => 'Session',
        'bonus' => 'Prime',
        'penalty' => 'Pénalité',
        'advance' => 'Avance',
        'final' => 'Paiement final',
        'monthly' => 'Mensuel',
        'commission' => 'Commission'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->payments_table = $wpdb->prefix . 'tbm_payments';
        $this->contracts_table = $wpdb->prefix . 'tbm_contracts';
        
        add_action('init', [$this, 'init_hooks']);
        add_action('wp_ajax_tbm_create_payment', [$this, 'ajax_create_payment']);
        add_action('wp_ajax_tbm_process_payment', [$this, 'ajax_process_payment']);
        add_action('wp_ajax_tbm_generate_invoice', [$this, 'ajax_generate_invoice']);
        add_action('tbm_daily_payment_processing', [$this, 'process_scheduled_payments']);
        add_action('tbm_monthly_payment_report', [$this, 'generate_monthly_report']);
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Session completion triggers payment
        add_action('tbm_session_completed', [$this, 'on_session_completed'], 10, 1);
        
        // Bootcamp completion triggers final payment
        add_action('tbm_bootcamp_completed', [$this, 'on_bootcamp_completed'], 10, 1);
        
        // Contract signing triggers advance payment
        add_action('tbm_contract_signed', [$this, 'on_contract_signed'], 10, 1);
        
        // Webhook handlers for payment gateways
        add_action('wp_ajax_nopriv_tbm_stripe_webhook', [$this, 'handle_stripe_webhook']);
        add_action('wp_ajax_nopriv_tbm_paypal_webhook', [$this, 'handle_paypal_webhook']);
    }
    
    /**
     * Create payment
     */
    public function create_payment($data) {
        $defaults = [
            'payment_id' => '',
            'trainer_id' => 0,
            'contract_id' => null,
            'session_id' => null,
            'amount' => 0.00,
            'currency' => 'EUR',
            'type' => 'session',
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'payment_gateway' => null,
            'gateway_payment_id' => '',
            'gateway_fee' => 0.00,
            'description' => '',
            'due_date' => null,
            'invoice_number' => '',
            'notes' => '',
            'processed_by' => get_current_user_id()
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['trainer_id']) || $data['amount'] <= 0) {
            return new WP_Error('invalid_data', 'Trainer ID and amount are required');
        }
        
        // Generate payment ID if not provided
        if (empty($data['payment_id'])) {
            $data['payment_id'] = $this->generate_payment_id();
        }
        
        // Generate invoice number if not provided
        if (empty($data['invoice_number'])) {
            $data['invoice_number'] = $this->generate_invoice_number();
        }
        
        // Calculate net amount (amount - fees)
        $data['net_amount'] = $data['amount'] - $data['gateway_fee'];
        
        // Set creation timestamp
        $data['created_at'] = current_time('mysql');
        
        // Insert payment
        $result = $this->wpdb->insert($this->payments_table, $data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create payment');
        }
        
        $payment_id = $this->wpdb->insert_id;
        
        // Trigger action
        do_action('tbm_payment_created', $payment_id, $data);
        
        // Generate invoice if needed
        if (in_array($data['type'], ['session', 'final', 'monthly'])) {
            $this->generate_invoice($payment_id);
        }
        
        return $payment_id;
    }
    
    /**
     * Process payment
     */
    public function process_payment($payment_id, $gateway_data = []) {
        $payment = $this->get_payment($payment_id);
        
        if (!$payment) {
            return new WP_Error('payment_not_found', 'Payment not found');
        }
        
        if ($payment->status !== 'pending') {
            return new WP_Error('invalid_status', 'Payment is not pending');
        }
        
        $result = false;
        
        switch ($payment->payment_method) {
            case 'stripe':
                $result = $this->process_stripe_payment($payment, $gateway_data);
                break;
                
            case 'paypal':
                $result = $this->process_paypal_payment($payment, $gateway_data);
                break;
                
            case 'bank_transfer':
                $result = $this->process_bank_transfer($payment, $gateway_data);
                break;
                
            case 'check':
            case 'cash':
                $result = $this->process_manual_payment($payment, $gateway_data);
                break;
                
            default:
                return new WP_Error('unsupported_method', 'Unsupported payment method');
        }
        
        if (is_wp_error($result)) {
            $this->update_payment_status($payment_id, 'failed', $result->get_error_message());
            return $result;
        }
        
        // Update payment status
        $this->update_payment_status($payment_id, 'completed');
        
        // Update gateway information
        if (!empty($gateway_data)) {
            $this->update_payment_gateway_data($payment_id, $gateway_data);
        }
        
        // Trigger actions
        do_action('tbm_payment_processed', $payment_id);
        
        // Update trainer earnings
        $this->update_trainer_earnings($payment->trainer_id, $payment->net_amount);
        
        return true;
    }
    
    /**
     * Process Stripe payment
     */
    private function process_stripe_payment($payment, $gateway_data) {
        $stripe_secret = get_option('tbm_stripe_secret_key');
        
        if (empty($stripe_secret)) {
            return new WP_Error('stripe_not_configured', 'Stripe not configured');
        }
        
        try {
            \Stripe\Stripe::setApiKey($stripe_secret);
            
            // Create payment intent
            $intent = \Stripe\PaymentIntent::create([
                'amount' => round($payment->amount * 100), // Convert to cents
                'currency' => strtolower($payment->currency),
                'description' => $payment->description,
                'metadata' => [
                    'payment_id' => $payment->payment_id,
                    'trainer_id' => $payment->trainer_id,
                    'type' => $payment->type
                ]
            ]);
            
            // Store Stripe payment intent ID
            $this->wpdb->update(
                $this->payments_table,
                [
                    'gateway_payment_id' => $intent->id,
                    'status' => 'processing'
                ],
                ['id' => $payment->id]
            );
            
            return [
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return new WP_Error('stripe_error', $e->getMessage());
        }
    }
    
    /**
     * Process PayPal payment
     */
    private function process_paypal_payment($payment, $gateway_data) {
        $paypal_client_id = get_option('tbm_paypal_client_id');
        $paypal_secret = get_option('tbm_paypal_secret');
        $paypal_mode = get_option('tbm_paypal_mode', 'sandbox');
        
        if (empty($paypal_client_id) || empty($paypal_secret)) {
            return new WP_Error('paypal_not_configured', 'PayPal not configured');
        }
        
        $base_url = $paypal_mode === 'live' 
            ? 'https://api.paypal.com' 
            : 'https://api.sandbox.paypal.com';
        
        try {
            // Get access token
            $token_response = wp_remote_post($base_url . '/v1/oauth2/token', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Accept-Language' => 'en_US',
                    'Authorization' => 'Basic ' . base64_encode($paypal_client_id . ':' . $paypal_secret)
                ],
                'body' => 'grant_type=client_credentials'
            ]);
            
            if (is_wp_error($token_response)) {
                return $token_response;
            }
            
            $token_data = json_decode(wp_remote_retrieve_body($token_response), true);
            $access_token = $token_data['access_token'];
            
            // Create payment
            $payment_data = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => $payment->currency,
                        'value' => number_format($payment->amount, 2, '.', '')
                    ],
                    'description' => $payment->description
                ]],
                'application_context' => [
                    'return_url' => home_url('/payment-success'),
                    'cancel_url' => home_url('/payment-cancel')
                ]
            ];
            
            $create_response = wp_remote_post($base_url . '/v2/checkout/orders', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token
                ],
                'body' => json_encode($payment_data)
            ]);
            
            if (is_wp_error($create_response)) {
                return $create_response;
            }
            
            $order_data = json_decode(wp_remote_retrieve_body($create_response), true);
            
            // Store PayPal order ID
            $this->wpdb->update(
                $this->payments_table,
                [
                    'gateway_payment_id' => $order_data['id'],
                    'status' => 'processing'
                ],
                ['id' => $payment->id]
            );
            
            return [
                'order_id' => $order_data['id'],
                'approval_url' => $order_data['links'][1]['href'] // Approval URL
            ];
            
        } catch (Exception $e) {
            return new WP_Error('paypal_error', $e->getMessage());
        }
    }
    
    /**
     * Process bank transfer payment
     */
    private function process_bank_transfer($payment, $gateway_data) {
        // For bank transfers, we just mark as processing and wait for manual confirmation
        $this->wpdb->update(
            $this->payments_table,
            [
                'status' => 'processing',
                'notes' => 'Awaiting bank transfer confirmation'
            ],
            ['id' => $payment->id]
        );
        
        // Send bank details to trainer
        $this->send_bank_transfer_instructions($payment);
        
        return true;
    }
    
    /**
     * Process manual payment (check/cash)
     */
    private function process_manual_payment($payment, $gateway_data) {
        // Mark as completed immediately for manual payments
        $notes = isset($gateway_data['notes']) ? $gateway_data['notes'] : 'Manual payment processed';
        
        $this->wpdb->update(
            $this->payments_table,
            [
                'status' => 'completed',
                'paid_date' => current_time('mysql'),
                'notes' => $notes
            ],
            ['id' => $payment->id]
        );
        
        return true;
    }
    
    /**
     * Update payment status
     */
    public function update_payment_status($payment_id, $status, $notes = '') {
        $update_data = [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ];
        
        if ($status === 'completed') {
            $update_data['paid_date'] = current_time('mysql');
        }
        
        if (!empty($notes)) {
            $update_data['notes'] = $notes;
        }
        
        return $this->wpdb->update(
            $this->payments_table,
            $update_data,
            ['id' => $payment_id]
        ) !== false;
    }
    
    /**
     * Update payment gateway data
     */
    private function update_payment_gateway_data($payment_id, $gateway_data) {
        $update_data = [];
        
        if (isset($gateway_data['gateway_payment_id'])) {
            $update_data['gateway_payment_id'] = $gateway_data['gateway_payment_id'];
        }
        
        if (isset($gateway_data['gateway_fee'])) {
            $update_data['gateway_fee'] = $gateway_data['gateway_fee'];
            $payment = $this->get_payment($payment_id);
            $update_data['net_amount'] = $payment->amount - $gateway_data['gateway_fee'];
        }
        
        if (!empty($update_data)) {
            $this->wpdb->update($this->payments_table, $update_data, ['id' => $payment_id]);
        }
    }
    
    /**
     * Update trainer earnings
     */
    private function update_trainer_earnings($trainer_id, $amount) {
        global $wpdb;
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$trainers_table} 
             SET total_earnings = total_earnings + %f 
             WHERE id = %d",
            $amount,
            $trainer_id
        ));
    }
    
    /**
     * Generate payment ID
     */
    private function generate_payment_id() {
        $prefix = get_option('tbm_payment_prefix', 'PAY');
        $date = date('Ymd');
        $random = wp_generate_password(6, false, false);
        
        return strtoupper($prefix . '-' . $date . '-' . $random);
    }
    
    /**
     * Generate invoice number
     */
    private function generate_invoice_number() {
        $prefix = get_option('tbm_invoice_prefix', 'INV');
        $year = date('Y');
        
        // Get next invoice number for this year
        $last_invoice = $this->wpdb->get_var($wpdb->prepare(
            "SELECT invoice_number FROM {$this->payments_table} 
             WHERE invoice_number LIKE %s 
             ORDER BY created_at DESC LIMIT 1",
            $prefix . '-' . $year . '-%'
        ));
        
        if ($last_invoice) {
            $parts = explode('-', $last_invoice);
            $number = intval(end($parts)) + 1;
        } else {
            $number = 1;
        }
        
        return $prefix . '-' . $year . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate invoice PDF
     */
    public function generate_invoice($payment_id) {
        $payment = $this->get_payment($payment_id);
        
        if (!$payment) {
            return false;
        }
        
        // Get trainer details
        global $wpdb;
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$trainers_table} WHERE id = %d",
            $payment->trainer_id
        ));
        
        if (!$trainer) {
            return false;
        }
        
        // Load invoice template
        $template = $this->get_invoice_template();
        
        // Replace placeholders
        $placeholders = [
            '{invoice_number}' => $payment->invoice_number,
            '{invoice_date}' => date('d/m/Y', strtotime($payment->created_at)),
            '{due_date}' => $payment->due_date ? date('d/m/Y', strtotime($payment->due_date)) : 'À réception',
            '{trainer_name}' => $trainer->first_name . ' ' . $trainer->last_name,
            '{trainer_email}' => $trainer->email,
            '{trainer_address}' => $trainer->city . ', ' . $trainer->country,
            '{amount}' => number_format($payment->amount, 2),
            '{currency}' => $payment->currency,
            '{description}' => $payment->description,
            '{payment_method}' => self::GATEWAYS[$payment->payment_method] ?? $payment->payment_method,
            '{company_name}' => get_option('blogname'),
            '{company_address}' => get_option('tbm_company_address', ''),
            '{company_email}' => get_option('admin_email'),
            '{company_phone}' => get_option('tbm_company_phone', ''),
            '{notes}' => $payment->notes
        ];
        
        $html_content = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        
        // Generate PDF using TCPDF or similar library
        if (class_exists('TCPDF')) {
            return $this->generate_pdf_with_tcpdf($html_content, $payment->invoice_number);
        }
        
        // Fallback: save as HTML
        return $this->save_invoice_html($html_content, $payment->invoice_number);
    }
    
    /**
     * Generate PDF with TCPDF
     */
    private function generate_pdf_with_tcpdf($html_content, $invoice_number) {
        require_once ABSPATH . 'wp-content/plugins/trainer-bootcamp-manager/vendor/tcpdf/tcpdf.php';
        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Trainer Bootcamp Manager');
        $pdf->SetAuthor(get_option('blogname'));
        $pdf->SetTitle('Invoice ' . $invoice_number);
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Add a page
        $pdf->AddPage();
        
        // Write HTML content
        $pdf->writeHTML($html_content, true, false, true, false, '');
        
        // Save to uploads directory
        $upload_dir = wp_upload_dir();
        $invoice_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager/invoices';
        
        if (!file_exists($invoice_dir)) {
            wp_mkdir_p($invoice_dir);
        }
        
        $file_path = $invoice_dir . '/invoice-' . $invoice_number . '.pdf';
        $pdf->Output($file_path, 'F');
        
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
    }
    
    /**
     * Save invoice as HTML (fallback)
     */
    private function save_invoice_html($html_content, $invoice_number) {
        $upload_dir = wp_upload_dir();
        $invoice_dir = $upload_dir['basedir'] . '/trainer-bootcamp-manager/invoices';
        
        if (!file_exists($invoice_dir)) {
            wp_mkdir_p($invoice_dir);
        }
        
        $file_path = $invoice_dir . '/invoice-' . $invoice_number . '.html';
        file_put_contents($file_path, $html_content);
        
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
    }
    
    /**
     * Get invoice template
     */
    private function get_invoice_template() {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Facture {invoice_number}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                .header { display: flex; justify-content: space-between; margin-bottom: 2rem; }
                .company-info { text-align: left; }
                .invoice-info { text-align: right; }
                .trainer-info { margin: 2rem 0; padding: 1rem; background: #f8f9fa; }
                .invoice-details { margin: 2rem 0; }
                .amount-table { width: 100%; border-collapse: collapse; margin: 2rem 0; }
                .amount-table th, .amount-table td { padding: 1rem; border: 1px solid #ddd; text-align: left; }
                .amount-table th { background: #f8f9fa; }
                .total-row { font-weight: bold; background: #e9ecef; }
                .footer { margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #ddd; }
                .payment-info { background: #fff3cd; padding: 1rem; margin: 1rem 0; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company-info">
                    <h1>{company_name}</h1>
                    <p>{company_address}</p>
                    <p>Email: {company_email}</p>
                    <p>Tél: {company_phone}</p>
                </div>
                <div class="invoice-info">
                    <h2>FACTURE</h2>
                    <p><strong>N° {invoice_number}</strong></p>
                    <p>Date: {invoice_date}</p>
                    <p>Échéance: {due_date}</p>
                </div>
            </div>
            
            <div class="trainer-info">
                <h3>Facturé à:</h3>
                <p><strong>{trainer_name}</strong></p>
                <p>{trainer_email}</p>
                <p>{trainer_address}</p>
            </div>
            
            <table class="amount-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{description}</td>
                        <td>{amount} {currency}</td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td><strong>{amount} {currency}</strong></td>
                    </tr>
                </tbody>
            </table>
            
            <div class="payment-info">
                <h4>Informations de paiement</h4>
                <p><strong>Méthode:</strong> {payment_method}</p>
            </div>
            
            <div class="footer">
                <p><strong>Notes:</strong> {notes}</p>
                <p>Merci pour votre collaboration.</p>
            </div>
        </body>
        </html>';
    }
    
    /**
     * Send bank transfer instructions
     */
    private function send_bank_transfer_instructions($payment) {
        global $wpdb;
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$trainers_table} WHERE id = %d",
            $payment->trainer_id
        ));
        
        if (!$trainer) {
            return false;
        }
        
        $bank_details = get_option('tbm_bank_details', []);
        
        $subject = 'Instructions de paiement - Facture ' . $payment->invoice_number;
        
        $message = sprintf(
            "Bonjour %s,\n\n" .
            "Votre paiement de %s %s est prêt.\n\n" .
            "Coordonnées bancaires pour le virement :\n" .
            "IBAN : %s\n" .
            "BIC : %s\n" .
            "Banque : %s\n\n" .
            "Référence à indiquer : %s\n\n" .
            "Merci de nous confirmer le virement une fois effectué.\n\n" .
            "Cordialement,\n" .
            "L'équipe %s",
            $trainer->first_name,
            $payment->amount,
            $payment->currency,
            $bank_details['iban'] ?? '',
            $bank_details['bic'] ?? '',
            $bank_details['bank_name'] ?? '',
            $payment->payment_id,
            get_option('blogname')
        );
        
        return wp_mail($trainer->email, $subject, $message);
    }
    
    /**
     * Get payment
     */
    public function get_payment($payment_id) {
        return $this->wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->payments_table} WHERE id = %d",
            $payment_id
        ));
    }
    
    /**
     * Get payments for trainer
     */
    public function get_trainer_payments($trainer_id, $args = []) {
        $defaults = [
            'status' => '',
            'type' => '',
            'date_from' => '',
            'date_to' => '',
            'limit' => 50,
            'offset' => 0,
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where_conditions = ['trainer_id = %d'];
        $where_values = [$trainer_id];
        
        if (!empty($args['status'])) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $args['status'];
        }
        
        if (!empty($args['type'])) {
            $where_conditions[] = 'type = %s';
            $where_values[] = $args['type'];
        }
        
        if (!empty($args['date_from'])) {
            $where_conditions[] = 'created_at >= %s';
            $where_values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where_conditions[] = 'created_at <= %s';
            $where_values[] = $args['date_to'];
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        $order_clause = 'ORDER BY created_at ' . $args['order'];
        $limit_clause = 'LIMIT ' . intval($args['limit']);
        
        if ($args['offset'] > 0) {
            $limit_clause .= ' OFFSET ' . intval($args['offset']);
        }
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->payments_table} {$where_clause} {$order_clause} {$limit_clause}",
            $where_values
        );
        
        return $this->wpdb->get_results($query);
    }
    
    /**
     * Get payment statistics
     */
    public function get_payment_statistics($trainer_id = null, $date_from = null, $date_to = null) {
        $where_conditions = ['status = %s'];
        $where_values = ['completed'];
        
        if ($trainer_id) {
            $where_conditions[] = 'trainer_id = %d';
            $where_values[] = $trainer_id;
        }
        
        if ($date_from) {
            $where_conditions[] = 'paid_date >= %s';
            $where_values[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = 'paid_date <= %s';
            $where_values[] = $date_to;
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $stats = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                SUM(net_amount) as total_net_amount,
                SUM(gateway_fee) as total_fees,
                AVG(amount) as average_amount
             FROM {$this->payments_table} {$where_clause}",
            $where_values
        ));
        
        return [
            'total_payments' => (int) $stats->total_payments,
            'total_amount' => (float) $stats->total_amount,
            'total_net_amount' => (float) $stats->total_net_amount,
            'total_fees' => (float) $stats->total_fees,
            'average_amount' => (float) $stats->average_amount
        ];
    }
    
    // Event handlers
    
    /**
     * Handle session completed
     */
    public function on_session_completed($session_id) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'tbm_sessions';
        $trainers_table = $wpdb->prefix . 'tbm_trainers';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, t.hourly_rate, t.currency 
             FROM {$sessions_table} s
             JOIN {$trainers_table} t ON s.trainer_id = t.id
             WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session || !$session->hourly_rate) {
            return;
        }
        
        $duration_hours = $session->duration_minutes / 60;
        $amount = $session->hourly_rate * $duration_hours;
        
        $this->create_payment([
            'trainer_id' => $session->trainer_id,
            'session_id' => $session_id,
            'amount' => $amount,
            'currency' => $session->currency,
            'type' => 'session',
            'description' => "Paiement pour la session: {$session->title}",
            'due_date' => date('Y-m-d', strtotime('+30 days'))
        ]);
    }
    
    /**
     * Handle bootcamp completed
     */
    public function on_bootcamp_completed($bootcamp_id) {
        // Create final payment for bootcamp if contract exists
        $contract = $this->wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->contracts_table} 
             WHERE bootcamp_id = %d AND status = 'active'",
            $bootcamp_id
        ));
        
        if ($contract && $contract->total_amount > 0) {
            // Calculate remaining amount (total - advance payments)
            $paid_amount = $this->wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$this->payments_table} 
                 WHERE contract_id = %d AND status = 'completed'",
                $contract->id
            ));
            
            $remaining_amount = $contract->total_amount - $paid_amount;
            
            if ($remaining_amount > 0) {
                $this->create_payment([
                    'trainer_id' => $contract->trainer_id,
                    'contract_id' => $contract->id,
                    'amount' => $remaining_amount,
                    'currency' => $contract->currency,
                    'type' => 'final',
                    'description' => "Paiement final pour le contrat: {$contract->title}",
                    'due_date' => date('Y-m-d', strtotime('+15 days'))
                ]);
            }
        }
    }
    
    /**
     * Handle contract signed
     */
    public function on_contract_signed($contract_id) {
        $contract = $this->wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->contracts_table} WHERE id = %d",
            $contract_id
        ));
        
        if (!$contract) {
            return;
        }
        
        // Create advance payment (30% of total)
        $advance_percentage = get_option('tbm_advance_percentage', 30);
        $advance_amount = $contract->total_amount * ($advance_percentage / 100);
        
        if ($advance_amount > 0) {
            $this->create_payment([
                'trainer_id' => $contract->trainer_id,
                'contract_id' => $contract_id,
                'amount' => $advance_amount,
                'currency' => $contract->currency,
                'type' => 'advance',
                'description' => "Avance sur contrat: {$contract->title} ({$advance_percentage}%)",
                'due_date' => date('Y-m-d', strtotime('+7 days'))
            ]);
        }
    }
    
    /**
     * Handle Stripe webhook
     */
    public function handle_stripe_webhook() {
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $endpoint_secret = get_option('tbm_stripe_webhook_secret');
        
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (\Exception $e) {
            http_response_code(400);
            exit('Webhook signature verification failed');
        }
        
        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $this->handle_stripe_payment_success($event['data']['object']);
                break;
                
            case 'payment_intent.payment_failed':
                $this->handle_stripe_payment_failed($event['data']['object']);
                break;
        }
        
        http_response_code(200);
        exit('OK');
    }
    
    /**
     * Handle Stripe payment success
     */
    private function handle_stripe_payment_success($payment_intent) {
        $payment = $this->wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->payments_table} WHERE gateway_payment_id = %s",
            $payment_intent['id']
        ));
        
        if ($payment) {
            $this->update_payment_status($payment->id, 'completed');
            
            // Update gateway fee
            $fee = $payment_intent['charges']['data'][0]['balance_transaction']['fee'] ?? 0;
            if ($fee > 0) {
                $fee_amount = $fee / 100; // Convert from cents
                $this->update_payment_gateway_data($payment->id, ['gateway_fee' => $fee_amount]);
            }
        }
    }
    
    /**
     * Handle Stripe payment failed
     */
    private function handle_stripe_payment_failed($payment_intent) {
        $payment = $this->wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->payments_table} WHERE gateway_payment_id = %s",
            $payment_intent['id']
        ));
        
        if ($payment) {
            $failure_reason = $payment_intent['last_payment_error']['message'] ?? 'Payment failed';
            $this->update_payment_status($payment->id, 'failed', $failure_reason);
        }
    }
    
    /**
     * AJAX: Create payment
     */
    public function ajax_create_payment() {
        check_ajax_referer('tbm_payments_nonce', 'nonce');
        
        if (!current_user_can('edit_tbm_payments')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $data = [
            'trainer_id' => intval($_POST['trainer_id'] ?? 0),
            'amount' => floatval($_POST['amount'] ?? 0),
            'currency' => sanitize_text_field($_POST['currency'] ?? 'EUR'),
            'type' => sanitize_text_field($_POST['type'] ?? 'session'),
            'payment_method' => sanitize_text_field($_POST['payment_method'] ?? 'bank_transfer'),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'due_date' => sanitize_text_field($_POST['due_date'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? '')
        ];
        
        $payment_id = $this->create_payment($data);
        
        if (is_wp_error($payment_id)) {
            wp_send_json_error($payment_id->get_error_message());
        }
        
        wp_send_json_success([
            'payment_id' => $payment_id,
            'message' => 'Payment created successfully'
        ]);
    }
    
    /**
     * AJAX: Process payment
     */
    public function ajax_process_payment() {
        check_ajax_referer('tbm_payments_nonce', 'nonce');
        
        if (!current_user_can('edit_tbm_payments')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $payment_id = intval($_POST['payment_id'] ?? 0);
        $gateway_data = $_POST['gateway_data'] ?? [];
        
        $result = $this->process_payment($payment_id, $gateway_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'message' => 'Payment processed successfully',
            'data' => $result
        ]);
    }
    
    /**
     * AJAX: Generate invoice
     */
    public function ajax_generate_invoice() {
        check_ajax_referer('tbm_payments_nonce', 'nonce');
        
        if (!current_user_can('edit_tbm_payments')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $payment_id = intval($_POST['payment_id'] ?? 0);
        
        $invoice_url = $this->generate_invoice($payment_id);
        
        if (!$invoice_url) {
            wp_send_json_error('Failed to generate invoice');
        }
        
        wp_send_json_success([
            'invoice_url' => $invoice_url,
            'message' => 'Invoice generated successfully'
        ]);
    }
}