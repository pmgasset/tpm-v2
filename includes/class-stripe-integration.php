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
    private $files_api_url = 'https://files.stripe.com/v1';
    
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
        
        $metadata = array(
            'reservation_id' => isset($reservation['id']) ? (string) $reservation['id'] : '',
            'guest_id' => isset($reservation['guest_id']) ? (string) $reservation['guest_id'] : '',
            'booking_reference' => isset($reservation['booking_reference']) ? (string) $reservation['booking_reference'] : '',
        );

        $filtered_metadata = array();
        foreach ($metadata as $key => $value) {
            if ($value !== '') {
                $filtered_metadata[$key] = $value;
            }
        }

        $document_options = array(
            'allowed_types' => array('driving_license', 'passport', 'id_card'),
            'require_id_number' => true,
            'require_live_capture' => true,
            'require_matching_selfie' => true
        );

        $body_params = array(
            'type' => 'document'
        );

        $body_params['options'] = array(
            'document' => $document_options
        );

        if (!empty($filtered_metadata)) {
            $body_params['metadata'] = $filtered_metadata;
        }

        $encoded_body = wp_json_encode($body_params);

        if (false === $encoded_body) {
            error_log('GMS Stripe Error: Failed to encode verification session payload.');
            return false;
        }

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/json',
            ),
            'body' => $encoded_body,
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
        $endpoint = add_query_arg(
            array(
                'expand[]' => 'last_verification_report',
            ),
            $endpoint
        );

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

        $session = $result;

        $reservation_id = $this->syncVerificationSession($session, false);

        // Ensure AJAX responses include any enriched report data for the client.
        if ($reservation_id > 0) {
            $session['reservation_id'] = $reservation_id;
        }

        return $session;
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
                $session = $event['data']['object'];
                $this->handleVerificationVerified($session);
                break;
            case 'identity.verification_session.processing':
                $session = $event['data']['object'];
                $this->handleVerificationProcessing($session);
                break;
            case 'identity.verification_session.requires_input':
                $session = $event['data']['object'];
                $this->handleVerificationRequiresInput($session);
                break;
            case 'identity.verification_session.canceled':
                $session = $event['data']['object'];
                $this->handleVerificationCanceled($session);
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
        $session_data = $session;
        $reservation_id = $this->syncVerificationSession($session_data);

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
        $session_data = $session;
        $this->syncVerificationSession($session_data);
    }

    private function handleVerificationRequiresInput($session) {
        $session_data = $session;
        $reservation_id = $this->syncVerificationSession($session_data);

        if (empty($reservation_id)) {
            return;
        }

        $reason = $session['last_error']['reason'] ?? '';
        if (!empty($reason)) {
            error_log('GMS Stripe Notice: Verification requires input for reservation ' . $reservation_id . ' - ' . $reason);
        }
    }

    private function handleVerificationCanceled($session) {
        $session_data = $session;
        $reservation_id = $this->syncVerificationSession($session_data);

        if (empty($reservation_id)) {
            return;
        }

        error_log('GMS Stripe Notice: Verification canceled for reservation ' . $reservation_id . '.');
    }

    private function syncVerificationSession(&$session, $allow_refresh = true) {
        if (!is_array($session) || empty($session['id'])) {
            return 0;
        }

        if ($allow_refresh) {
            $refreshed = $this->retrieveVerificationSessionById($session['id']);
            if (is_array($refreshed)) {
                $session = $refreshed;
            }
        }

        $session = $this->handleSessionSideEffects($session);

        $update = array(
            'status' => $session['status'] ?? 'pending',
            'verification_data' => $session,
        );

        if (!empty($session['client_secret'])) {
            $update['stripe_client_secret'] = $session['client_secret'];
        }

        GMS_Database::updateVerification($session['id'], $update);

        $reservation_id = $this->getReservationIdFromSession($session);

        return $reservation_id;
    }

    private function retrieveVerificationSessionById($session_id) {
        if (empty($session_id) || empty($this->secret_key)) {
            return null;
        }

        $endpoint = $this->api_url . '/identity/verification_sessions/' . rawurlencode($session_id);
        $endpoint = add_query_arg(
            array(
                'expand[]' => 'last_verification_report',
            ),
            $endpoint
        );

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GMS Stripe Error: Unable to decode verification session refresh response.');
            return null;
        }

        if (isset($result['error'])) {
            $message = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown Stripe API error.';
            error_log('GMS Stripe API Error: ' . $message);
            return null;
        }

        return $result;
    }

    private function handleSessionSideEffects($session) {
        if (!is_array($session)) {
            return $session;
        }

        $session = $this->maybeAttachVerificationReport($session);
        $this->persistVerificationDetails($session);

        return $session;
    }

    private function maybeAttachVerificationReport($session) {
        if (!is_array($session)) {
            return $session;
        }

        $report = $session['last_verification_report'] ?? null;

        if (is_array($report) && ($report['object'] ?? '') === 'identity.verification_report') {
            return $session;
        }

        $report_id = '';

        if (is_string($report)) {
            $report_id = $report;
        } elseif (is_array($report) && !empty($report['id'])) {
            $report_id = $report['id'];
        }

        if ($report_id === '') {
            return $session;
        }

        $full_report = $this->retrieveVerificationReport($report_id);

        if (is_array($full_report) && !empty($full_report['id'])) {
            $session['last_verification_report'] = $full_report;
        }

        return $session;
    }

    private function retrieveVerificationReport($report_id) {
        if (empty($report_id) || empty($this->secret_key)) {
            return null;
        }

        $endpoint = $this->api_url . '/identity/verification_reports/' . rawurlencode($report_id);
        $endpoint = add_query_arg(
            array(
                'expand[]' => array(
                    'document.front',
                    'document.back',
                    'selfie.selfie',
                ),
            ),
            $endpoint
        );

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GMS Stripe Error: Unable to decode verification report response.');
            return null;
        }

        if (isset($result['error'])) {
            $message = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown Stripe API error.';
            error_log('GMS Stripe API Error: ' . $message);
            return null;
        }

        return $result;
    }

    private function persistVerificationDetails($session) {
        if (!is_array($session)) {
            return;
        }

        $report = $session['last_verification_report'] ?? null;

        if (!is_array($report) || empty($report['id'])) {
            return;
        }

        $reservation_id = $this->getReservationIdFromSession($session);

        if ($reservation_id <= 0) {
            return;
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (empty($reservation) || !is_array($reservation)) {
            return;
        }

        $user_id = $this->locateOrCreateUser($reservation);

        if ($user_id <= 0) {
            return;
        }

        $document = isset($report['document']) && is_array($report['document']) ? $report['document'] : array();
        $selfie = isset($report['selfie']) && is_array($report['selfie']) ? $report['selfie'] : array();

        $document_type = isset($document['type']) ? sanitize_text_field($document['type']) : '';
        $document_country = isset($document['issued_country']) ? sanitize_text_field($document['issued_country']) : '';
        $document_last4 = isset($document['number_last4']) ? sanitize_text_field((string) $document['number_last4']) : '';
        $document_status = isset($document['status']) ? sanitize_text_field($document['status']) : '';
        $selfie_status = isset($selfie['status']) ? sanitize_text_field($selfie['status']) : '';

        $meta_updates = array(
            'gms_verification_report_id' => isset($report['id']) ? sanitize_text_field($report['id']) : '',
            'gms_verification_session_id' => isset($session['id']) ? sanitize_text_field($session['id']) : '',
            'gms_verification_status' => isset($session['status']) ? sanitize_text_field($session['status']) : '',
            'gms_verification_document_type' => $document_type,
            'gms_verification_document_issuing_country' => $document_country,
            'gms_verification_document_last4' => $document_last4,
            'gms_verification_document_status' => $document_status,
            'gms_verification_selfie_status' => $selfie_status,
            'gms_verification_last_synced' => current_time('mysql'),
        );

        foreach ($meta_updates as $meta_key => $meta_value) {
            if ($meta_value === '') {
                continue;
            }

            update_user_meta($user_id, $meta_key, $meta_value);
        }

        update_user_meta($user_id, 'gms_verification_report', $report);

        $document_front = $this->extractStripeFileId($document['front'] ?? null);
        $document_back = $this->extractStripeFileId($document['back'] ?? null);
        $selfie_file_id = $this->extractStripeFileId($selfie['selfie'] ?? null);

        if ($document_front !== '') {
            update_user_meta($user_id, 'gms_verification_document_front_file_id', sanitize_text_field($document_front));
        }

        if ($document_back !== '') {
            update_user_meta($user_id, 'gms_verification_document_back_file_id', sanitize_text_field($document_back));
        }

        if ($selfie_file_id !== '') {
            $this->maybeDownloadAndAssignSelfie($user_id, $selfie_file_id);
        }
    }

    private function extractStripeFileId($value) {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && !empty($value['id'])) {
            return $value['id'];
        }

        return '';
    }

    private function maybeDownloadAndAssignSelfie($user_id, $file_id) {
        if (empty($file_id)) {
            return;
        }

        $existing_file_id = get_user_meta($user_id, 'gms_verification_selfie_file_id', true);
        $existing_attachment_id = (int) get_user_meta($user_id, 'gms_verification_selfie_attachment_id', true);

        if (
            $existing_file_id === $file_id &&
            $existing_attachment_id > 0 &&
            wp_get_attachment_url($existing_attachment_id)
        ) {
            return;
        }

        $file = $this->retrieveStripeFile($file_id);

        if (!is_array($file) || empty($file['id'])) {
            return;
        }

        $contents = $this->downloadStripeFile($file);

        if (!$contents) {
            return;
        }

        $attachment_id = $this->saveSelfieAttachment($file, $contents);

        if (!$attachment_id || is_wp_error($attachment_id)) {
            return;
        }

        $url = wp_get_attachment_url($attachment_id);

        update_user_meta($user_id, 'gms_verification_selfie_file_id', sanitize_text_field($file_id));
        update_user_meta($user_id, 'gms_verification_selfie_attachment_id', $attachment_id);

        if ($url) {
            update_user_meta($user_id, 'gms_verification_selfie_url', esc_url_raw($url));
            update_user_meta($user_id, 'profile_photo_url', esc_url_raw($url));
        }

        update_user_meta($user_id, 'profile_photo', $attachment_id);
        update_user_meta($user_id, 'profile_photo_id', $attachment_id);
    }

    private function retrieveStripeFile($file_id) {
        if (empty($file_id) || empty($this->secret_key)) {
            return null;
        }

        $endpoint = $this->files_api_url . '/files/' . rawurlencode($file_id);

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GMS Stripe Error: Unable to decode Stripe file metadata response.');
            return null;
        }

        if (isset($result['error'])) {
            $message = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown Stripe API error.';
            error_log('GMS Stripe API Error: ' . $message);
            return null;
        }

        return $result;
    }

    private function downloadStripeFile($file) {
        if (!is_array($file) || empty($file['url'])) {
            return null;
        }

        $response = wp_remote_get($file['url'], array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            error_log('GMS Stripe Error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            error_log('GMS Stripe Error: Unexpected response while downloading Stripe file.');
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    private function saveSelfieAttachment($file, $contents) {
        if (!is_array($file) || !$contents) {
            return null;
        }

        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $filename = !empty($file['filename']) ? sanitize_file_name($file['filename']) : ('stripe-selfie-' . $file['id'] . '.jpg');

        $tmp = wp_tempnam($filename);

        if (!$tmp) {
            return null;
        }

        file_put_contents($tmp, $contents);

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            error_log('GMS Stripe Error: Unable to sideload selfie file - ' . $attachment_id->get_error_message());
            return $attachment_id;
        }

        @unlink($tmp);

        return $attachment_id;
    }

    private function locateOrCreateUser($reservation) {
        $guest_id = isset($reservation['guest_id']) ? intval($reservation['guest_id']) : 0;
        $email = isset($reservation['guest_email']) ? sanitize_email($reservation['guest_email']) : '';

        if ($guest_id > 0) {
            $user = get_user_by('id', $guest_id);
            if ($user) {
                return (int) $user->ID;
            }
        }

        if ($email !== '' && is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                return (int) $user->ID;
            }
        }

        if ($email === '' || !is_email($email)) {
            return 0;
        }

        $guest_name = isset($reservation['guest_name']) ? trim((string) $reservation['guest_name']) : '';
        list($first_name, $last_name) = $this->splitGuestName($guest_name);

        $username_base = sanitize_user(current(explode('@', $email)), true);
        if ($username_base === '') {
            $username_base = 'guest_' . absint($reservation['id'] ?? 0);
        }

        $username = $username_base;
        $attempt = 1;

        while (username_exists($username)) {
            $username = $username_base . '_' . $attempt;
            $attempt++;
        }

        $user_data = array(
            'user_login' => $username,
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $email,
            'display_name' => $guest_name !== '' ? $guest_name : $username,
            'first_name' => $first_name,
            'last_name' => $last_name,
        );

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            error_log('GMS Stripe Error: Unable to create user for verification - ' . $user_id->get_error_message());
            return 0;
        }

        return (int) $user_id;
    }

    private function splitGuestName($name) {
        $name = trim((string) $name);

        if ($name === '') {
            return array('', '');
        }

        $parts = preg_split('/\s+/', $name);

        if (empty($parts)) {
            return array('', '');
        }

        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return array(sanitize_text_field($first), sanitize_text_field($last));
    }

    private function getReservationIdFromSession($session) {
        if (is_array($session) && !empty($session['metadata']['reservation_id'])) {
            return intval($session['metadata']['reservation_id']);
        }

        if (!is_array($session) || empty($session['id'])) {
            return 0;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'gms_identity_verification';
        $reservation_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT reservation_id FROM {$table} WHERE stripe_verification_session_id = %s",
                sanitize_text_field($session['id'])
            )
        );

        return intval($reservation_id);
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
