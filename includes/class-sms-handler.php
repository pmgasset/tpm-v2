<?php
/**
 * File: class-sms-handler.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-sms-handler.php
 * * SMS Handler for Guest Management System
 * Uses VoIP.ms API for SMS delivery
 */

class GMS_SMS_Handler {
    
    private $api_url = 'https://voip.ms/api/v1/rest.php';
    
    public function __construct() {
        // Constructor
    }
    
    public function sendWelcomeSMS($reservation) {
        if (empty($reservation['guest_phone'])) {
            error_log('GMS: No phone number for reservation ' . intval($reservation['id'] ?? 0));
            return false;
        }

        $phone_validation = $this->validatePhoneNumber($reservation['guest_phone']);
        if (!$phone_validation['is_valid']) {
            error_log('GMS: Invalid phone number provided for reservation ' . intval($reservation['id'] ?? 0));
            return false;
        }

        $template = get_option('gms_sms_template');
        $portal_token = isset($reservation['portal_token']) ? sanitize_text_field($reservation['portal_token']) : '';
        $portal_url = home_url('/guest-portal/' . $portal_token);

        $checkin_date_raw = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkout_date_raw = isset($reservation['checkout_date']) ? $reservation['checkout_date'] : '';

        $replacements = array(
            '{guest_name}' => sanitize_text_field($reservation['guest_name'] ?? ''),
            '{property_name}' => sanitize_text_field($reservation['property_name'] ?? ''),
            '{booking_reference}' => sanitize_text_field($reservation['booking_reference'] ?? ''),
            '{checkin_date}' => $checkin_date_raw ? date('M j', strtotime($checkin_date_raw)) : '',
            '{checkout_date}' => $checkout_date_raw ? date('M j', strtotime($checkout_date_raw)) : '',
            '{checkin_time}' => $checkin_date_raw ? date('g:i A', strtotime($checkin_date_raw)) : '',
            '{checkout_time}' => $checkout_date_raw ? date('g:i A', strtotime($checkout_date_raw)) : '',
            '{portal_link}' => $this->shortenUrl($portal_url),
            '{company_name}' => get_option('gms_company_name', get_option('blogname'))
        );

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $message = $this->prepareMessageForSending($message);

        $result = $this->sendSMS($phone_validation['sanitized'], $message);

        // Log communication
        GMS_Database::logCommunication(array(
            'reservation_id' => intval($reservation['id'] ?? 0),
            'guest_id' => intval($reservation['guest_id'] ?? 0),
            'type' => 'sms',
            'recipient' => $phone_validation['sanitized'],
            'message' => $message,
            'status' => $result ? 'sent' : 'failed',
            'response_data' => array('result' => $result)
        ));

        return $result;
    }

    public function sendReservationApprovedSMS($reservation) {
        if (empty($reservation['guest_phone'])) {
            error_log('GMS: No phone number for reservation ' . intval($reservation['id'] ?? 0));
            return false;
        }

        $phone_validation = $this->validatePhoneNumber($reservation['guest_phone']);
        if (!$phone_validation['is_valid']) {
            error_log('GMS: Invalid phone number provided for reservation ' . intval($reservation['id'] ?? 0));
            return false;
        }

        $template = get_option('gms_approved_sms_template');
        if (empty($template)) {
            $template = 'Reservation approved! Finish your tasks for {property_name}: {portal_link} - {company_name}';
        }

        $portal_token = isset($reservation['portal_token']) ? sanitize_text_field($reservation['portal_token']) : '';
        $portal_url = home_url('/guest-portal/' . $portal_token);

        $checkin_date_raw = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkout_date_raw = isset($reservation['checkout_date']) ? $reservation['checkout_date'] : '';

        $replacements = array(
            '{guest_name}' => sanitize_text_field($reservation['guest_name'] ?? ''),
            '{property_name}' => sanitize_text_field($reservation['property_name'] ?? ''),
            '{booking_reference}' => sanitize_text_field($reservation['booking_reference'] ?? ''),
            '{checkin_date}' => $checkin_date_raw ? date('M j', strtotime($checkin_date_raw)) : '',
            '{checkout_date}' => $checkout_date_raw ? date('M j', strtotime($checkout_date_raw)) : '',
            '{checkin_time}' => $checkin_date_raw ? date('g:i A', strtotime($checkin_date_raw)) : '',
            '{checkout_time}' => $checkout_date_raw ? date('g:i A', strtotime($checkout_date_raw)) : '',
            '{portal_link}' => $this->shortenUrl($portal_url),
            '{company_name}' => get_option('gms_company_name', get_option('blogname'))
        );

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $message = $this->prepareMessageForSending($message);

        $result = $this->sendSMS($phone_validation['sanitized'], $message);

        GMS_Database::logCommunication(array(
            'reservation_id' => intval($reservation['id'] ?? 0),
            'guest_id' => intval($reservation['guest_id'] ?? 0),
            'type' => 'sms',
            'recipient' => $phone_validation['sanitized'],
            'message' => $message,
            'status' => $result ? 'sent' : 'failed',
            'response_data' => array('result' => $result)
        ));

        return $result;
    }
    
    public function sendSMS($to, $message) {
        $api_username = get_option('gms_voipms_user');
        $api_password = get_option('gms_voipms_pass');
        $did = get_option('gms_voipms_did');
        
        if (empty($api_username) || empty($api_password) || empty($did)) {
            error_log('GMS: VoIP.ms API credentials not configured');
            return false;
        }
        
        $validation = $this->validatePhoneNumber($to);
        if (!$validation['is_valid']) {
            error_log('GMS: Invalid phone number provided: ' . sanitize_text_field($validation['original']));
            return false;
        }

        $to = $validation['sanitized'];

        $message = $this->prepareMessageForSending($message);

        // Prepare API request
        $params = array(
            'api_username' => $api_username,
            'api_password' => $api_password,
            'method' => 'sendSMS',
            'did' => $did,
            'dst' => $to,
            'message' => $message
        );
        
        $url = $this->api_url . '?' . http_build_query($params);
        
        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            error_log('GMS SMS Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['status']) && $data['status'] === 'success') {
            return true;
        }

        error_log('GMS SMS Error: ' . print_r($data, true));
        return false;
    }

    public function sendReminderSMS($reservation) {
        if (empty($reservation['guest_phone'])) {
            error_log('GMS: No phone number for reservation ' . intval($reservation['id'] ?? 0));
            return false;
        }

        $phone_validation = $this->validatePhoneNumber($reservation['guest_phone']);
        if (!$phone_validation['is_valid']) {
            error_log('GMS: Invalid phone number provided for reservation ' . intval($reservation['id'] ?? 0));
            return false;
        }

        $template = get_option('gms_sms_reminder_template');
        $portal_token = isset($reservation['portal_token']) ? sanitize_text_field($reservation['portal_token']) : '';
        $portal_url = home_url('/guest-portal/' . $portal_token);

        $checkin_date_raw = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkout_date_raw = isset($reservation['checkout_date']) ? $reservation['checkout_date'] : '';

        if (empty($template)) {
            $template = 'Reminder: Complete your check-in for {property_name}. Check-in {checkin_date}. Portal: {portal_link} - {company_name}';
        }

        $replacements = array(
            '{guest_name}' => sanitize_text_field($reservation['guest_name'] ?? ''),
            '{property_name}' => sanitize_text_field($reservation['property_name'] ?? ''),
            '{booking_reference}' => sanitize_text_field($reservation['booking_reference'] ?? ''),
            '{checkin_date}' => $checkin_date_raw ? date('M j', strtotime($checkin_date_raw)) : '',
            '{checkout_date}' => $checkout_date_raw ? date('M j', strtotime($checkout_date_raw)) : '',
            '{portal_link}' => $this->shortenUrl($portal_url),
            '{company_name}' => get_option('gms_company_name', get_option('blogname'))
        );

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $message = $this->prepareMessageForSending($message);

        $result = $this->sendSMS($phone_validation['sanitized'], $message);

        GMS_Database::logCommunication(array(
            'reservation_id' => intval($reservation['id'] ?? 0),
            'guest_id' => intval($reservation['guest_id'] ?? 0),
            'type' => 'sms',
            'recipient' => $phone_validation['sanitized'],
            'message' => $message,
            'status' => $result ? 'sent' : 'failed',
            'response_data' => array('result' => $result)
        ));

        return $result;
    }

    public function getSMSBalance() {
        $api_username = get_option('gms_voipms_user');
        $api_password = get_option('gms_voipms_pass');

        if (empty($api_username) || empty($api_password)) {
            return array(
                'success' => false,
                'balance' => null,
                'currency' => null,
                'message' => 'VoIP.ms credentials are not configured.'
            );
        }

        $params = array(
            'api_username' => $api_username,
            'api_password' => $api_password,
            'method' => 'getSMSBalance'
        );

        $url = $this->api_url . '?' . http_build_query($params);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'balance' => null,
                'currency' => null,
                'message' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return array(
                'success' => false,
                'balance' => null,
                'currency' => null,
                'message' => 'Unable to parse SMS balance response.'
            );
        }

        if (isset($data['status']) && $data['status'] === 'success') {
            $balance = isset($data['balance']) ? floatval($data['balance']) : null;
            $currency = isset($data['currency']) ? sanitize_text_field($data['currency']) : null;

            return array(
                'success' => true,
                'balance' => $balance,
                'currency' => $currency,
                'message' => ''
            );
        }

        $message = isset($data['status']) ? sanitize_text_field($data['status']) : 'Unknown error';
        if (isset($data['message'])) {
            $message = sanitize_text_field($data['message']);
        }

        return array(
            'success' => false,
            'balance' => null,
            'currency' => null,
            'message' => $message
        );
    }

    private function shortenUrl($url) {
        // IMPROVEMENT: Implement a URL shortening service for better SMS messages.
        // Example using Bitly API (requires a free account and access token).
        $bitly_token = get_option('gms_bitly_token'); // Add this to your settings page
        if ($bitly_token) {
            return $this->shortenWithBitly($url, $bitly_token);
        }
        
        return $url;
    }
    
    private function shortenWithBitly($url, $token) {
        $api_url = 'https://api-ssl.bitly.com/v4/shorten';
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'long_url' => $url
            )),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return $url; // Return original URL on failure
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['link'])) {
            return $data['link'];
        }
        
        return $url; // Return original URL if response is not as expected
    }

    public function formatPhoneNumber($phone) {
        $validation = $this->validatePhoneNumber($phone);

        if (empty($validation['sanitized'])) {
            $fallback = $validation['digits'] !== '' ? $validation['digits'] : $validation['original'];
            return sanitize_text_field($fallback);
        }

        $digits = $validation['sanitized'];
        $length = strlen($digits);

        if ($length === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6)
            );
        }

        if ($length === 11 && substr($digits, 0, 1) === '1') {
            return sprintf('+1 (%s) %s-%s',
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7)
            );
        }

        return '+' . ltrim($digits, '+');
    }

    public function validatePhoneNumber($phone) {
        $original = (string) $phone;
        $digits_only = preg_replace('/[^0-9]/', '', $original);
        $length = strlen($digits_only);

        $is_valid = $length >= 10 && $length <= 15;

        return array(
            'is_valid' => $is_valid,
            'sanitized' => $is_valid ? $digits_only : '',
            'digits' => $digits_only,
            'original' => $original
        );
    }

    private function prepareMessageForSending($message) {
        $message = wp_strip_all_tags((string) $message);
        $message = preg_replace('/\s+/u', ' ', $message);
        $message = trim($message);

        if (strlen($message) > 160) {
            $message = substr($message, 0, 157) . '...';
        }

        return $message;
    }
}
