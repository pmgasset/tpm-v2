<?php
/**
 * File: class-sms-handler.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-sms-handler.php
 * 
 * SMS Handler for Guest Management System
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
        $api_username = get_option('gms_voipms_user'); // This is your API username, NOT your account username
        $api_password = get_option('gms_voipms_pass'); // This is your API password, NOT your account password
        $did = get_option('gms_voipms_did');
        
        if (empty($api_username) || empty($api_password) || empty($did)) {
            error_log('GMS: VoIP.ms API credentials not configured');
            return false;
        }
        
        // Clean phone number - remove all non-numeric characters
        $to = preg_replace('/[^0-9]/', '', $to);
        
        // Ensure number has country code
        if (strlen($to) === 10) {
            $to = '1' . $to; // Add US/Canada country code
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
    
    public function sendReminderSMS($reservation) {
        if (empty($reservation['guest_phone'])) {
            return false;
        }
        
        $portal_url = home_url('/guest-portal/' . $reservation['portal_token']);
        $short_url = $this->shortenUrl($portal_url);
        
        $message = "Reminder: Complete check-in for {$reservation['property_name']}. ";
        $message .= "Visit: {$short_url}";
        
        $result = $this->sendSMS($reservation['guest_phone'], $message);
        
        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'sms',
            'recipient' => $reservation['guest_phone'],
            'message' => $message,
            'status' => $result ? 'sent' : 'failed'
        ));
        
        return $result;
    }
    
    public function sendCompletionSMS($reservation) {
        if (empty($reservation['guest_phone'])) {
            return false;
        }
        
        $message = "Check-in complete! Welcome to {$reservation['property_name']}. ";
        $message .= "Access info will be sent 24hrs before check-in. ";
        $message .= "Questions? Reply to this message.";
        
        $result = $this->sendSMS($reservation['guest_phone'], $message);
        
        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'sms',
            'recipient' => $reservation['guest_phone'],
            'message' => $message,
            'status' => $result ? 'sent' : 'failed'
        ));
        
        return $result;
    }
    
    public function getSMSBalance() {
        $api_username = get_option('gms_voipms_user');
        $api_password = get_option('gms_voipms_pass');
        
        if (empty($api_username) || empty($api_password)) {
            return null;
        }
        
        $params = array(
            'api_username' => $api_username,
            'api_password' => $api_password,
            'method' => 'getBalance'
        );
        
        $url = $this->api_url . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['balance'])) {
            return $data['balance'];
        }
        
        return null;
    }
    
    public function getSMSHistory($limit = 50) {
        $api_username = get_option('gms_voipms_user');
        $api_password = get_option('gms_voipms_pass');
        
        if (empty($api_username) || empty($api_password)) {
            return array();
        }
        
        $params = array(
            'api_username' => $api_username,
            'api_password' => $api_password,
            'method' => 'getSMS',
            'type' => 1, // Sent messages
            'limit' => $limit
        );
        
        $url = $this->api_url . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['sms']) && is_array($data['sms'])) {
            return $data['sms'];
        }
        
        return array();
    }
    
    private function shortenUrl($url) {
        // For now, return the original URL
        // In production, you might want to use a URL shortening service
        // like bit.ly, tinyurl, or your own custom shortener
        
        // Example with bit.ly (requires API key):
        // $bitly_token = get_option('gms_bitly_token');
        // if ($bitly_token) {
        //     return $this->shortenWithBitly($url, $bitly_token);
        // }
        
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
            return $url;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['link'])) {
            return $data['link'];
        }
        
        return $url;
    }
    
    public function validatePhoneNumber($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a valid North American number
        if (strlen($phone) === 10) {
            return '1' . $phone;
        } elseif (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            return $phone;
        }
        
        // For international numbers, just return if at least 10 digits
        if (strlen($phone) >= 10) {
            return $phone;
        }
        
        return false;
    }
    
    public function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 10) {
            return sprintf('(%s) %s-%s', 
                substr($phone, 0, 3), 
                substr($phone, 3, 3), 
                substr($phone, 6, 4)
            );
        } elseif (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            return sprintf('+1 (%s) %s-%s', 
                substr($phone, 1, 3), 
                substr($phone, 4, 3), 
                substr($phone, 7, 4)
            );
        }
        
        return $phone;
    }
}