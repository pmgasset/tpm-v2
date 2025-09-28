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
    
    // ... (other functions like checkVerificationStatus are unchanged)

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
            // ... (other cases remain the same)
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

    // ... (other handler functions like handleVerificationVerified are unchanged)
}
