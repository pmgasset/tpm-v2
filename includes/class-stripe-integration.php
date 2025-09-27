<?php
/**
 * File: class-stripe-integration.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-stripe-integration.php
 * 
 * Stripe Integration for Guest Management System
 * Handles identity verification using Stripe Identity API
 */

class GMS_Stripe_Integration {
    
    private $api_url = 'https://api.stripe.com/v1';
    private $secret_key;
    
    public function __construct() {
        $this->secret_key = get_option('gms_stripe_sk');
        
        // Add webhook handler for Stripe events
        add_action('rest_api_init', array($this, 'registerWebhookEndpoint'));
    }
    
    public function registerWebhookEndpoint() {
        register_rest_route('gms/v1', '/stripe-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handleStripeWebhook'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public function createVerificationSession($reservation) {
        if (empty($this->secret_key)) {
            error_log('GMS: Stripe secret key not configured');
            return false;
        }
        
        $endpoint = $this->api_url . '/identity/verification_sessions';
        
        // Build the form-encoded body manually for Stripe's format
        $body_parts = array(
            'type=document',
            'metadata[reservation_id]=' . urlencode($reservation['id']),
            'metadata[guest_id]=' . urlencode($reservation['guest_id']),
            'metadata[booking_reference]=' . urlencode($reservation['booking_reference']),
            'options[document][allowed_types][]=driving_license',
            'options[document][allowed_types][]=passport',
            'options[document][allowed_types][]=id_card',
            'options[document][require_id_number]=true',
            'options[document][require_live_capture]=false',
            'options[document][require_matching_selfie]=false'
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => implode('&', $body_parts),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['error'])) {
            error_log('GMS Stripe API Error: ' . print_r($result['error'], true));
            return false;
        }
        
        if (isset($result['id']) && isset($result['client_secret'])) {
            return array(
                'id' => $result['id'],
                'client_secret' => $result['client_secret'],
                'status' => $result['status']
            );
        }
        
        return false;
    }
    
    public function checkVerificationStatus($session_id) {
        if (empty($this->secret_key)) {
            return false;
        }
        
        $endpoint = $this->api_url . '/identity/verification_sessions/' . $session_id;
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['error'])) {
            error_log('GMS Stripe API Error: ' . $result['error']['message']);
            return false;
        }
        
        if (isset($result['status'])) {
            return array(
                'status' => $result['status'],
                'verified' => $result['status'] === 'verified',
                'last_error' => isset($result['last_error']) ? $result['last_error'] : null,
                'verified_data' => isset($result['verified_outputs']) ? $result['verified_outputs'] : null
            );
        }
        
        return false;
    }
    
    public function getVerificationDetails($session_id) {
        if (empty($this->secret_key)) {
            return false;
        }
        
        $endpoint = $this->api_url . '/identity/verification_sessions/' . $session_id;
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        return $result;
    }
    
    public function handleStripeWebhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe_signature');
        
        // Verify webhook signature (requires webhook secret)
        $webhook_secret = get_option('gms_stripe_webhook_secret');
        
        if ($webhook_secret) {
            try {
                $event = $this->verifyWebhookSignature($payload, $sig_header, $webhook_secret);
            } catch (Exception $e) {
                error_log('GMS Stripe Webhook Error: ' . $e->getMessage());
                return new WP_REST_Response(array('error' => 'Invalid signature'), 400);
            }
        } else {
            $event = json_decode($payload, true);
        }
        
        // Handle different event types
        switch ($event['type']) {
            case 'identity.verification_session.verified':
                $this->handleVerificationVerified($event['data']['object']);
                break;
                
            case 'identity.verification_session.requires_input':
                $this->handleVerificationRequiresInput($event['data']['object']);
                break;
                
            case 'identity.verification_session.processing':
                $this->handleVerificationProcessing($event['data']['object']);
                break;
                
            case 'identity.verification_session.canceled':
                $this->handleVerificationCanceled($event['data']['object']);
                break;
                
            default:
                error_log('GMS: Unhandled Stripe webhook event: ' . $event['type']);
        }
        
        return new WP_REST_Response(array('success' => true), 200);
    }
    
    private function verifyWebhookSignature($payload, $sig_header, $secret) {
        // Simplified signature verification
        // In production, use Stripe's library for proper verification
        $elements = explode(',', $sig_header);
        $timestamp = null;
        $signature = null;
        
        foreach ($elements as $element) {
            $parts = explode('=', $element, 2);
            if ($parts[0] === 't') {
                $timestamp = $parts[1];
            } elseif ($parts[0] === 'v1') {
                $signature = $parts[1];
            }
        }
        
        if (!$timestamp || !$signature) {
            throw new Exception('Invalid signature format');
        }
        
        // Verify timestamp is recent (within 5 minutes)
        if (abs(time() - $timestamp) > 300) {
            throw new Exception('Timestamp too old');
        }
        
        // Compute expected signature
        $signed_payload = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);
        
        if (!hash_equals($expected_signature, $signature)) {
            throw new Exception('Invalid signature');
        }
        
        return json_decode($payload, true);
    }
    
    private function handleVerificationVerified($session) {
        $reservation_id = $session['metadata']['reservation_id'] ?? null;
        
        if (!$reservation_id) {
            return;
        }
        
        // Update verification status
        GMS_Database::updateVerification($session['id'], array(
            'status' => 'verified',
            'verification_data' => $session
        ));
        
        // Update reservation status
        GMS_Database::updateReservationStatus($reservation_id, 'completed');
        
        // Send completion notifications
        $reservation = GMS_Database::getReservationById($reservation_id);
        if ($reservation) {
            $email_handler = new GMS_Email_Handler();
            $email_handler->sendCompletionEmail($reservation);
            
            if (!empty($reservation['guest_phone'])) {
                $sms_handler = new GMS_SMS_Handler();
                $sms_handler->sendCompletionSMS($reservation);
            }
            
            // Send admin notification
            $email_handler->sendAdminNotification($reservation, 'check_in_complete');
        }
    }
    
    private function handleVerificationRequiresInput($session) {
        $reservation_id = $session['metadata']['reservation_id'] ?? null;
        
        if (!$reservation_id) {
            return;
        }
        
        GMS_Database::updateVerification($session['id'], array(
            'status' => 'requires_input',
            'verification_data' => $session
        ));
    }
    
    private function handleVerificationProcessing($session) {
        $reservation_id = $session['metadata']['reservation_id'] ?? null;
        
        if (!$reservation_id) {
            return;
        }
        
        GMS_Database::updateVerification($session['id'], array(
            'status' => 'processing',
            'verification_data' => $session
        ));
    }
    
    private function handleVerificationCanceled($session) {
        $reservation_id = $session['metadata']['reservation_id'] ?? null;
        
        if (!$reservation_id) {
            return;
        }
        
        GMS_Database::updateVerification($session['id'], array(
            'status' => 'canceled',
            'verification_data' => $session
        ));
    }
    
    public function testConnection() {
        if (empty($this->secret_key)) {
            return array(
                'success' => false,
                'message' => 'Secret key not configured'
            );
        }
        
        // Test the connection by retrieving account info
        $endpoint = $this->api_url . '/account';
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['error'])) {
            return array(
                'success' => false,
                'message' => $result['error']['message']
            );
        }
        
        if (isset($result['id'])) {
            return array(
                'success' => true,
                'message' => 'Connection successful',
                'account' => $result['business_profile']['name'] ?? $result['email'] ?? 'Connected'
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Unknown error occurred'
        );
    }
}