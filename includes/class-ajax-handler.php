<?php
/**
 * File: class-ajax-handler.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-ajax-handler.php
 * * Handles all AJAX requests for the Guest Management System plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_AJAX_Handler {

    public function __construct() {
        // Hook for creating the Stripe ID verification session
        add_action('wp_ajax_gms_create_id_verification_session', array($this, 'create_id_verification_session'));
        add_action('wp_ajax_nopriv_gms_create_id_verification_session', array($this, 'create_id_verification_session'));

        // Hook for saving the signed agreement
        add_action('wp_ajax_gms_save_signed_agreement', array($this, 'save_signed_agreement'));
        add_action('wp_ajax_nopriv_gms_save_signed_agreement', array($this, 'save_signed_agreement'));
    }

    /**
     * Creates a Stripe Identity Verification session for a guest.
     */
    public function create_id_verification_session() {
        // SECURITY: Always check the nonce to protect against CSRF attacks.
        check_ajax_referer('gms_guest_nonce', 'nonce');

        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        
        if (!$reservation_id) {
            wp_send_json_error(array('message' => 'Invalid reservation ID.'));
            return;
        }
        
        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            wp_send_json_error(array('message' => 'Reservation not found.'));
            return;
        }
        
        $stripe_integration = new GMS_Stripe_Integration();
        $session_data = $stripe_integration->createVerificationSession($reservation);
        
        if (!$session_data) {
            wp_send_json_error(array('message' => 'Could not create identity verification session. Please check API keys.'));
            return;
        }
        
        wp_send_json_success(array(
            'client_secret' => $session_data['client_secret']
        ));
    }

    /**
     * Example function for saving a signed agreement.
     */
    public function save_signed_agreement() {
        check_ajax_referer('gms_guest_nonce', 'nonce');
        
        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        $signature_data = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : '';
        
        if (!$reservation_id || empty($signature_data)) {
            wp_send_json_error(array('message' => 'Missing required data.'));
            return;
        }
        
        // This is a placeholder for the actual save logic
        $result = GMS_Database::updateReservation($reservation_id, array(
            'agreement_signed_at' => current_time('mysql', 1),
            'status' => 'agreement_signed'
        ));

        if ($result === false) {
             wp_send_json_error(array('message' => 'Failed to save agreement.'));
        } else {
            wp_send_json_success(array('message' => 'Agreement signed successfully!'));
        }
    }
}
