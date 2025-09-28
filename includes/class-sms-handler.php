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
            error_log('GMS: No phone number for reservation ' . $reservation['id']);
            return false;
        }
        
        $template = get_option('gms_sms_template');
        $portal_url = home_url('/guest-portal/' . $reservation['portal_token']);
        
        $replacements = array(
            '{guest_name}' => $reservation['guest_name'],
            '{property_name}' => $reservation['property_name'],
            '{booking_reference}' => $reservation['booking_reference'],
            '{checkin_date}' => date('M j', strtotime($reservation['checkin_date'])),
            '{checkout_date}' => date('M j', strtotime($reservation['checkout_date'])),
            '{checkin_time}' => date('g:i A', strtotime($reservation['checkin_date'])),
            '{checkout_time}' => date('g:i A', strtotime($reservation['checkout_date'])),
            '{portal_link}' => $this->shortenUrl($portal_url),
            '{company_name}' => get_option('gms_company_name', get_option('blogname'))
        );
        
        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Ensure message is under 160 characters
        if (strlen($message) > 160) {
            $message = substr($message, 0, 157) . '...';
        }
        
        $result = $this->sendSMS($reservation['guest_phone'], $message);
        
        // Log communication
        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'sms',
            'recipient' => $reservation['guest_phone'],
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
        
        // BUG FIX: Improved phone number handling for international use.
        // It's recommended to use a library like libphonenumber-for-php for robust validation if possible.
        $to = preg_replace('/[^0-9]/', '', $to); // Clean number
        if (strlen($to) < 10) {
            error_log('GMS: Invalid phone number provided: ' . $to);
            return false;
        }
        
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
        } else {
            error_log('GMS SMS Error: ' . print_r($data, true));
            return false;
        }
    }

    // ... (other functions like sendReminderSMS, getSMSBalance, etc. are unchanged)

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

    // ... (other functions like validatePhoneNumber, formatPhoneNumber are unchanged)
}
