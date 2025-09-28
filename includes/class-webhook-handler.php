<?php
/**
 * File: class-webhook-handler.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-webhook-handler.php
 * 
 * Webhook Handler for Guest Management System
 */

class GMS_Webhook_Handler {
    
    public function __construct() {
        add_action('init', array($this, 'addWebhookEndpoints'));
        add_action('wp_ajax_nopriv_gms_webhook_booking', array($this, 'handleBookingWebhook'));
        add_action('wp_ajax_nopriv_gms_webhook_airbnb', array($this, 'handleAirbnbWebhook'));
        add_action('wp_ajax_nopriv_gms_webhook_vrbo', array($this, 'handleVrboWebhook'));
        add_action('wp_ajax_nopriv_gms_webhook_generic', array($this, 'handleGenericWebhook'));
        
        // Email parsing hook
        add_action('wp_mail_succeeded', array($this, 'parseIncomingEmail'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'registerRestEndpoints'));
    }
    
    public function addWebhookEndpoints() {
        add_rewrite_rule(
            '^webhook/booking/?$',
            'index.php?gms_webhook=booking',
            'top'
        );
        
        add_rewrite_rule(
            '^webhook/airbnb/?$',
            'index.php?gms_webhook=airbnb',
            'top'
        );
        
        add_rewrite_rule(
            '^webhook/vrbo/?$',
            'index.php?gms_webhook=vrbo',
            'top'
        );
        
        add_rewrite_rule(
            '^webhook/generic/?$',
            'index.php?gms_webhook=generic',
            'top'
        );
        
        add_filter('query_vars', array($this, 'addWebhookQueryVars'));
        add_action('template_redirect', array($this, 'handleWebhookRequest'));
    }
    
    public function addWebhookQueryVars($vars) {
        $vars[] = 'gms_webhook';
        return $vars;
    }
    
    public function handleWebhookRequest() {
        $webhook_type = get_query_var('gms_webhook');
        
        if ($webhook_type) {
            switch ($webhook_type) {
                case 'booking':
                    $this->handleBookingWebhook();
                    break;
                case 'airbnb':
                    $this->handleAirbnbWebhook();
                    break;
                case 'vrbo':
                    $this->handleVrboWebhook();
                    break;
                case 'generic':
                    $this->handleGenericWebhook();
                    break;
                default:
                    http_response_code(404);
                    exit('Webhook not found');
            }
        }
    }
    
    public function registerRestEndpoints() {
        register_rest_route('gms/v1', '/webhook/(?P<platform>[a-zA-Z0-9-]+)', array(
            'methods' => array('POST', 'GET'),
            'callback' => array($this, 'handleRestWebhook'),
            'permission_callback' => '__return_true', // Open access - we'll validate in the callback
            'args' => array(
                'platform' => array(
                    'required' => true,
                    'type' => 'string'
                )
            )
        ));
    }
    
    public function handleRestWebhook($request) {
        // Validate webhook authentication first
        if (!$this->verifyWebhookAuth($request)) {
            error_log('GMS: Webhook authentication failed');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Unauthorized - Invalid webhook token'
            ), 401);
        }
        
        $platform = $request->get_param('platform');
        $data = $request->get_json_params();
        
        if (empty($data)) {
            $data = $request->get_params();
        }
        
        $this->logWebhookReceived($platform, $data);
        
        switch ($platform) {
            case 'booking':
                return $this->processBookingData($data);
            case 'airbnb':
                return $this->processAirbnbData($data);
            case 'vrbo':
                return $this->processVrboData($data);
            default:
                return $this->processGenericData($data, $platform);
        }
    }
    
    public function verifyWebhookAuth($request) {
        // Get expected token from WordPress
        $expected_token = defined('GMS_WEBHOOK_TOKEN') 
            ? GMS_WEBHOOK_TOKEN 
            : get_option('gms_webhook_token', '');
        
        // Get token from request (header or query param)
        $auth_token = $request->get_header('x-webhook-token'); // Try lowercase
        
        if (!$auth_token) {
            $auth_token = $request->get_header('X-Webhook-Token'); // Try capitalized
        }
        
        // If not in header, check query parameter
        if (!$auth_token) {
            $auth_token = $request->get_param('webhook_token');
        }
        
        // Debug logging
        error_log('GMS Webhook Auth Check:');
        error_log('- Expected token configured: ' . (!empty($expected_token) ? 'YES' : 'NO'));
        error_log('- Received token: ' . (!empty($auth_token) ? 'YES' : 'NO'));
        error_log('- Tokens match: ' . ($auth_token === $expected_token ? 'YES' : 'NO'));
        
        // If no token is configured in WordPress, allow but warn
        if (empty($expected_token)) {
            error_log('GMS Warning: No webhook token configured!');
            return true; // Allow for initial setup
        }
        
        // Verify token matches
        if ($auth_token === $expected_token) {
            error_log('GMS: Webhook authentication successful');
            return true;
        }
        
        error_log('GMS: Webhook authentication FAILED - token mismatch');
        return false;
    }
    
    public function handleBookingWebhook() {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        
        if (!$data) {
            parse_str($raw_data, $data);
        }
        
        $this->logWebhookReceived('booking', $data);
        
        $result = $this->processBookingData($data);
        
        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);
        exit;
    }
    
    public function handleAirbnbWebhook() {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        
        $this->logWebhookReceived('airbnb', $data);
        
        $result = $this->processAirbnbData($data);
        
        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);
        exit;
    }
    
    public function handleVrboWebhook() {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        
        $this->logWebhookReceived('vrbo', $data);
        
        $result = $this->processVrboData($data);
        
        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);
        exit;
    }
    
    public function handleGenericWebhook() {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        
        if (!$data) {
            parse_str($raw_data, $data);
        }
        
        $this->logWebhookReceived('generic', $data);
        
        $result = $this->processGenericData($data, 'generic');
        
        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);
        exit;
    }
    
    private function processBookingData($data) {
        try {
            // Extract booking data based on Booking.com webhook structure
            $booking_data = $this->parseBookingData($data);
            
            if (!$booking_data) {
                throw new Exception('Invalid booking data received');
            }
            
            // Create or find guest user
            $guest_profile = $this->createOrFindGuest($booking_data);

            if (empty($guest_profile['guest_record_id'])) {
                throw new Exception('Failed to create guest user');
            }

            // Create reservation
            $reservation_data = array_merge($booking_data, array(
                'guest_id' => intval($guest_profile['wp_user_id'] ?? 0),
                'guest_record_id' => intval($guest_profile['guest_record_id']),
                'platform' => 'booking.com',
                'webhook_data' => $data
            ));
            
            $reservation_id = GMS_Database::createReservation($reservation_data);
            
            if (!$reservation_id) {
                throw new Exception('Failed to create reservation');
            }
            
            // Send notifications
            $this->sendGuestNotifications($reservation_id);
            
            return array(
                'success' => true,
                'message' => 'Booking processed successfully',
                'reservation_id' => $reservation_id
            );
            
        } catch (Exception $e) {
            error_log('GMS Booking Webhook Error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    private function processAirbnbData($data) {
        try {
            // Extract booking data based on Airbnb webhook structure
            $booking_data = $this->parseAirbnbData($data);
            
            if (!$booking_data) {
                throw new Exception('Invalid Airbnb data received');
            }
            
            $guest_profile = $this->createOrFindGuest($booking_data);

            if (empty($guest_profile['guest_record_id'])) {
                throw new Exception('Failed to create guest user');
            }

            $reservation_data = array_merge($booking_data, array(
                'guest_id' => intval($guest_profile['wp_user_id'] ?? 0),
                'guest_record_id' => intval($guest_profile['guest_record_id']),
                'platform' => 'airbnb',
                'webhook_data' => $data
            ));
            
            $reservation_id = GMS_Database::createReservation($reservation_data);
            
            if (!$reservation_id) {
                throw new Exception('Failed to create reservation');
            }
            
            $this->sendGuestNotifications($reservation_id);
            
            return array(
                'success' => true,
                'message' => 'Airbnb booking processed successfully',
                'reservation_id' => $reservation_id
            );
            
        } catch (Exception $e) {
            error_log('GMS Airbnb Webhook Error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    private function processVrboData($data) {
        try {
            // Extract booking data based on VRBO webhook structure
            $booking_data = $this->parseVrboData($data);
            
            if (!$booking_data) {
                throw new Exception('Invalid VRBO data received');
            }
            
            $guest_profile = $this->createOrFindGuest($booking_data);

            if (empty($guest_profile['guest_record_id'])) {
                throw new Exception('Failed to create guest user');
            }

            $reservation_data = array_merge($booking_data, array(
                'guest_id' => intval($guest_profile['wp_user_id'] ?? 0),
                'guest_record_id' => intval($guest_profile['guest_record_id']),
                'platform' => 'vrbo',
                'webhook_data' => $data
            ));
            
            $reservation_id = GMS_Database::createReservation($reservation_data);
            
            if (!$reservation_id) {
                throw new Exception('Failed to create reservation');
            }
            
            $this->sendGuestNotifications($reservation_id);
            
            return array(
                'success' => true,
                'message' => 'VRBO booking processed successfully',
                'reservation_id' => $reservation_id
            );
            
        } catch (Exception $e) {
            error_log('GMS VRBO Webhook Error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    private function processGenericData($data, $platform) {
        try {
            // Try to extract booking data from generic format
            $booking_data = $this->parseGenericData($data);
            
            if (!$booking_data) {
                throw new Exception('Invalid generic booking data received');
            }
            
            $guest_profile = $this->createOrFindGuest($booking_data);

            if (empty($guest_profile['guest_record_id'])) {
                throw new Exception('Failed to create guest user');
            }

            $reservation_data = array_merge($booking_data, array(
                'guest_id' => intval($guest_profile['wp_user_id'] ?? 0),
                'guest_record_id' => intval($guest_profile['guest_record_id']),
                'platform' => $platform,
                'webhook_data' => $data
            ));
            
            $reservation_id = GMS_Database::createReservation($reservation_data);
            
            if (!$reservation_id) {
                throw new Exception('Failed to create reservation');
            }
            
            $this->sendGuestNotifications($reservation_id);
            
            return array(
                'success' => true,
                'message' => 'Generic booking processed successfully',
                'reservation_id' => $reservation_id
            );
            
        } catch (Exception $e) {
            error_log('GMS Generic Webhook Error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    private function parseBookingData($data) {
        // Parse Booking.com webhook data
        // This will need to be updated based on actual Booking.com webhook format
        $parsed = array();
        
        // Common fields to look for
        $field_mapping = array(
            'booking_reference' => array('reservation_id', 'booking_id', 'reference', 'confirmation_number'),
            'guest_name' => array('guest_name', 'customer_name', 'first_name', 'name'),
            'guest_email' => array('guest_email', 'customer_email', 'email'),
            'guest_phone' => array('guest_phone', 'customer_phone', 'phone'),
            'property_name' => array('property_name', 'hotel_name', 'accommodation_name'),
            'property_id' => array('property_id', 'hotel_id', 'accommodation_id'),
            'checkin_date' => array('checkin_date', 'arrival_date', 'check_in'),
            'checkout_date' => array('checkout_date', 'departure_date', 'check_out'),
            'guests_count' => array('guests_count', 'number_of_guests', 'adults', 'pax'),
            'total_amount' => array('total_amount', 'total_price', 'amount', 'price'),
            'currency' => array('currency', 'currency_code')
        );
        
        foreach ($field_mapping as $our_field => $possible_fields) {
            foreach ($possible_fields as $field) {
                if (isset($data[$field]) && !empty($data[$field])) {
                    $parsed[$our_field] = $data[$field];
                    break;
                }
            }
        }
        
        // Convert dates to proper format
        if (isset($parsed['checkin_date'])) {
            $parsed['checkin_date'] = date('Y-m-d H:i:s', strtotime($parsed['checkin_date']));
        }
        if (isset($parsed['checkout_date'])) {
            $parsed['checkout_date'] = date('Y-m-d H:i:s', strtotime($parsed['checkout_date']));
        }
        
        // Validate required fields
        $required = array('booking_reference', 'guest_name', 'guest_email', 'checkin_date', 'checkout_date');
        foreach ($required as $field) {
            if (empty($parsed[$field])) {
                error_log("GMS: Missing required field '$field' in booking data");
                return false;
            }
        }
        
        return $parsed;
    }
    
    private function parseAirbnbData($data) {
        // Parse Airbnb webhook data
        // Similar to parseBookingData but with Airbnb-specific field names
        return $this->parseGenericData($data);
    }
    
    private function parseVrboData($data) {
        // Parse VRBO webhook data
        // Similar to parseBookingData but with VRBO-specific field names
        return $this->parseGenericData($data);
    }
    
    private function parseGenericData($data) {
        // Generic parser that tries to find common booking fields
        $parsed = array();
        
        // Flatten nested data
        $flattened = $this->flattenArray($data);
        
        // Map fields using fuzzy matching
        $field_patterns = array(
            'booking_reference' => array('booking', 'reservation', 'confirmation', 'reference', 'id'),
            'guest_name' => array('name', 'guest', 'customer', 'first'),
            'guest_email' => array('email', 'mail'),
            'guest_phone' => array('phone', 'mobile', 'tel'),
            'property_name' => array('property', 'hotel', 'accommodation', 'listing'),
            'checkin_date' => array('checkin', 'check_in', 'arrival', 'start'),
            'checkout_date' => array('checkout', 'check_out', 'departure', 'end'),
            'guests_count' => array('guests', 'adults', 'pax', 'count'),
            'total_amount' => array('total', 'amount', 'price', 'cost'),
            'currency' => array('currency', 'curr')
        );
        
        foreach ($field_patterns as $our_field => $patterns) {
            foreach ($flattened as $key => $value) {
                $key_lower = strtolower($key);
                foreach ($patterns as $pattern) {
                    if (strpos($key_lower, $pattern) !== false && !empty($value)) {
                        $parsed[$our_field] = $value;
                        break 2;
                    }
                }
            }
        }
        
        // Convert dates
        if (isset($parsed['checkin_date'])) {
            $parsed['checkin_date'] = date('Y-m-d H:i:s', strtotime($parsed['checkin_date']));
        }
        if (isset($parsed['checkout_date'])) {
            $parsed['checkout_date'] = date('Y-m-d H:i:s', strtotime($parsed['checkout_date']));
        }
        
        return $parsed;
    }
    
    private function flattenArray($array, $prefix = '') {
        $result = array();
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $prefix . $key . '_'));
            } else {
                $result[$prefix . $key] = $value;
            }
        }
        
        return $result;
    }
    
    private function createOrFindGuest($booking_data) {
        $full_name = isset($booking_data['guest_name']) ? sanitize_text_field($booking_data['guest_name']) : '';
        $name_parts = array_values(array_filter(preg_split('/\s+/', trim($full_name ?? ''))));

        $first_name = $name_parts[0] ?? '';
        $last_name = '';

        if (count($name_parts) > 1) {
            $last_name = implode(' ', array_slice($name_parts, 1));
        }

        $first_name = sanitize_text_field($first_name);
        $last_name = sanitize_text_field($last_name);
        $email = isset($booking_data['guest_email']) ? sanitize_email($booking_data['guest_email']) : '';
        $phone = isset($booking_data['guest_phone']) ? sanitize_text_field($booking_data['guest_phone']) : '';

        $guest_record_id = GMS_Database::upsert_guest(array(
            'name' => $full_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
        ), array(
            'force_user_creation' => !empty($email) && is_email($email),
        ));

        if (!$guest_record_id) {
            error_log('GMS: Failed to upsert guest for reservation payload.');
            return array(
                'guest_record_id' => 0,
                'wp_user_id' => 0,
            );
        }

        $wp_user_id = GMS_Database::ensure_guest_user($guest_record_id, array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => $full_name,
            'email' => $email,
            'phone' => $phone,
        ), !empty($email) && is_email($email));

        return array(
            'guest_record_id' => $guest_record_id,
            'wp_user_id' => $wp_user_id,
        );
    }
    
    private function sendGuestNotifications($reservation_id) {
        $reservation = GMS_Database::getReservationById($reservation_id);
        
        if (!$reservation) {
            return false;
        }
        
        // Send email notification
        $email_handler = new GMS_Email_Handler();
        $email_result = $email_handler->sendWelcomeEmail($reservation);
        
        // Send SMS notification if phone number is available
        if (!empty($reservation['guest_phone'])) {
            $sms_handler = new GMS_SMS_Handler();
            $sms_result = $sms_handler->sendWelcomeSMS($reservation);
        }
        
        return true;
    }
    
    private function logWebhookReceived($platform, $data) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'platform' => $platform,
            'data_keys' => array_keys($data),
            'ip' => GMS_Database::getUserIP(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        
        error_log('GMS Webhook Received: ' . json_encode($log_entry));
        
        // Store in database for debugging
        update_option('gms_last_webhook_' . $platform, $log_entry);
    }
    
    // Email parsing for backup method
    public function parseIncomingEmail($mail) {
        // This would be triggered by a forwarding rule or email plugin
        // Parse emails from booking platforms as a backup method
        $subject = $mail['subject'];
        $body = $mail['body'];
        
        // Detect booking platform by subject or sender
        if (strpos($subject, 'booking') !== false || strpos($subject, 'Booking.com') !== false) {
            $this->parseBookingEmail($body);
        } elseif (strpos($subject, 'airbnb') !== false || strpos($subject, 'Airbnb') !== false) {
            $this->parseAirbnbEmail($body);
        } elseif (strpos($subject, 'vrbo') !== false || strpos($subject, 'VRBO') !== false) {
            $this->parseVrboEmail($body);
        }
    }
    
    private function parseBookingEmail($body) {
        // Extract booking data from email body using regex patterns
        // This is a backup method when webhooks are not available
        $patterns = array(
            'booking_reference' => '/booking\s*(?:reference|id|number)[\s:]+([A-Z0-9-]+)/i',
            'guest_name' => '/guest\s*name[\s:]+([^<\n]+)/i',
            'guest_email' => '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i',
            'checkin_date' => '/check[\s-]*in[\s:]+([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i',
            'checkout_date' => '/check[\s-]*out[\s:]+([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i'
        );
        
        $extracted_data = array();
        
        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $extracted_data[$field] = trim($matches[1]);
            }
        }
        
        if (count($extracted_data) >= 3) { // Minimum required fields
            $extracted_data['platform'] = 'booking.com';
            $this->processGenericData($extracted_data, 'booking.com');
        }
    }
    
    private function parseAirbnbEmail($body) {
        // Similar to parseBookingEmail but with Airbnb-specific patterns
        // Implementation would be similar to parseBookingEmail
    }
    
    private function parseVrboEmail($body) {
        // Similar to parseBookingEmail but with VRBO-specific patterns
        // Implementation would be similar to parseBookingEmail
    }
}