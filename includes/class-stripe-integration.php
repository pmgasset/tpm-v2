<?php
/**
 * File: class-stripe-integration.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-stripe-integration.php
 * * Stripe Integration for Guest Management System
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
        
        // BUG FIX: Use a robust array for the body instead of a manual string.
        // This prevents errors with special characters in reservation data.
        $body_params = array(
            'type' => 'document',
            'metadata' => array(
                'reservation_id' => $reservation['id'],
                'guest_id' => $reservation['guest_id'],
                'booking_reference' => $reservation['booking_reference']
            ),
            'options' => array(
                'document' => array(
                    'allowed_types' => ['driving_license', 'passport', 'id_card'],
                    'require_id_number' => true,
                    'require_live_capture' => false,
                    'require_matching_selfie' => false
                )
            )
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'body' => $body_params,
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
        $session_id = trim((string) $session_id);

        if (empty($session_id)) {
            error_log('GMS Stripe Error: Missing verification session ID.');
            return false;
        }

        if (empty($this->secret_key)) {
            error_log('GMS Stripe Error: Secret key not configured.');
            return false;
        }

        $endpoint = $this->api_url . '/identity/verification_sessions/' . rawurlencode($session_id);

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GMS Stripe Error: Unable to decode verification status response.');
            return false;
        }

        if (isset($result['error'])) {
            $message = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown Stripe API error.';
            error_log('GMS Stripe API Error: ' . $message);
            return false;
        }

        return $result;
    }

    public function handleStripeWebhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe_signature');
        $webhook_secret = get_option('gms_stripe_webhook_secret');
        
        // SECURITY: Verify the webhook signature to ensure the request is from Stripe.
        if (empty($webhook_secret) || empty($sig_header)) {
            return new WP_REST_Response(array('error' => 'Webhook secret or signature missing.'), 400);
        }
        
        try {
            // Since we cannot use the official Stripe PHP library, we use a manual verification.
            $event = $this->verifyWebhookSignature($payload, $sig_header, $webhook_secret);
        } catch (Exception $e) {
            error_log('GMS Stripe Webhook Error: ' . $e->getMessage());
            return new WP_REST_Response(array('error' => 'Webhook signature verification failed.'), 400);
        }
        
        // Handle different event types
        switch ($event['type']) {
            case 'identity.verification_session.verified':
                $this->handleVerificationVerified($event['data']['object']);
                break;
            case 'identity.verification_session.processing':
                $this->handleVerificationProcessing($event['data']['object']);
                break;
            case 'identity.verification_session.requires_input':
                $this->handleVerificationRequiresInput($event['data']['object']);
                break;
            case 'identity.verification_session.canceled':
                $this->handleVerificationCanceled($event['data']['object']);
                break;
        }

        return new WP_REST_Response(array('success' => true), 200);
    }
    
    /**
     * SECURITY WARNING: Manual Webhook Signature Verification.
     * * This method manually verifies the Stripe webhook signature. This is a fallback for environments
     * where Composer and the official Stripe PHP SDK cannot be used.
     * * The official Stripe library is STRONGLY RECOMMENDED for production environments as it is more
     * robust and maintained by Stripe to handle edge cases like timing attacks.
     *
     * @throws Exception If the signature is invalid or malformed.
     */
    private function verifyWebhookSignature($payload, $sig_header, $secret) {
        $timestamp = -1;
        $signature = '';

        // Extract timestamp and signature from header
        $parts = explode(',', $sig_header);
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (isset($kv[1])) {
                if ($kv[0] === 't') {
                    $timestamp = $kv[1];
                }
                if ($kv[0] === 'v1') {
                    $signature = $kv[1];
                }
            }
        }

        if ($timestamp === -1 || empty($signature)) {
            throw new Exception('Unable to extract timestamp or signature from header.');
        }

        // Protect against replay attacks by checking if the timestamp is recent
        $five_minutes = 5 * 60;
        if (abs(time() - $timestamp) > $five_minutes) {
            throw new Exception('Timestamp is too old. Possible replay attack.');
        }

        // Compute the expected signature
        $signed_payload = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);

        // Compare signatures in a way that is secure against timing attacks
        if (!hash_equals($expected_signature, $signature)) {
            throw new Exception('Webhook signature does not match expected signature.');
        }

        return json_decode($payload, true);
    }

    private function handleVerificationVerified($session) {
        $reservation_id = $this->syncVerificationSession($session);

        if (empty($reservation_id)) {
            return;
        }

        $reservation = GMS_Database::getReservationById($reservation_id);
        if (empty($reservation)) {
            return;
        }

        $email_handler = new GMS_Email_Handler();
        $email_handler->sendCompletionEmail($reservation);

        $guest_phone = $reservation['guest_phone'] ?? '';
        if (!empty($guest_phone) && !empty(get_option('gms_voipms_user'))) {
            $sms_handler = new GMS_SMS_Handler();
            $message = sprintf(
                'Hi %s, your identity verification for %s is complete. Thank you!',
                trim($reservation['guest_name'] ?? ''),
                $reservation['property_name'] ?? __('your stay', 'gms')
            );

            $result = $sms_handler->sendSMS($guest_phone, $message);

            GMS_Database::logCommunication(array(
                'reservation_id' => $reservation_id,
                'guest_id' => intval($reservation['guest_id'] ?? 0),
                'type' => 'sms',
                'recipient' => $guest_phone,
                'message' => $message,
                'status' => $result ? 'sent' : 'failed',
                'response_data' => array('result' => $result),
            ));
        }
    }

    private function handleVerificationProcessing($session) {
        $this->syncVerificationSession($session);
    }

    private function handleVerificationRequiresInput($session) {
        $reservation_id = $this->syncVerificationSession($session);

        if (empty($reservation_id)) {
            return;
        }

        $reason = $session['last_error']['reason'] ?? '';
        if (!empty($reason)) {
            error_log('GMS Stripe Notice: Verification requires input for reservation ' . $reservation_id . ' - ' . $reason);
        }
    }

    private function handleVerificationCanceled($session) {
        $reservation_id = $this->syncVerificationSession($session);

        if (empty($reservation_id)) {
            return;
        }

        error_log('GMS Stripe Notice: Verification canceled for reservation ' . $reservation_id . '.');
    }

    private function syncVerificationSession($session) {
        if (!is_array($session) || empty($session['id'])) {
            return 0;
        }

        $update = array(
            'status' => $session['status'] ?? 'pending',
            'verification_data' => $session,
        );

        if (!empty($session['client_secret'])) {
            $update['stripe_client_secret'] = $session['client_secret'];
        }

        GMS_Database::updateVerification($session['id'], $update);

        if (!empty($session['metadata']['reservation_id'])) {
            return intval($session['metadata']['reservation_id']);
        }

        return 0;
    }

    public function testConnection() {
        if (empty($this->secret_key)) {
            return array(
                'success' => false,
                'message' => 'Secret key not configured',
            );
        }

        $endpoint = $this->api_url . '/account';

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Unable to parse Stripe response',
            );
        }

        if ($code >= 200 && $code < 300 && isset($data['id'])) {
            return array(
                'success' => true,
                'message' => 'Connection successful',
                'account' => $data['id'],
                'details' => array(
                    'email' => $data['email'] ?? '',
                    'business_type' => $data['business_type'] ?? '',
                ),
            );
        }

        $error_message = $data['error']['message'] ?? 'Unexpected Stripe response';

        return array(
            'success' => false,
            'message' => $error_message,
            'code' => $code,
        );
    }
}
