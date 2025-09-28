<?php
/**
 * File: ajax-functions.php
 * Location: /wp-content/plugins/guest-management-system/includes/ajax-functions.php
 * * Handles all AJAX requests for the Guest Management System plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates a Stripe Identity Verification session for a guest.
 * This is called from the guest portal.
 */
function gms_ajax_create_id_verification_session() {
    // SECURITY: Always check the nonce to protect against CSRF attacks.
    check_ajax_referer('gms_guest_nonce', 'nonce');

    // Get reservation ID from the AJAX request
    $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
    
    if (!$reservation_id) {
        wp_send_json_error(array('message' => 'Invalid reservation ID.'));
        return;
    }
    
    // Get reservation data from the database
    $reservation = GMS_Database::getReservationById($reservation_id);
    if (!$reservation) {
        wp_send_json_error(array('message' => 'Reservation not found.'));
        return;
    }
    
    // Create the Stripe session
    $stripe_integration = new GMS_Stripe_Integration();
    $session_data = $stripe_integration->createVerificationSession($reservation);
    
    if (!$session_data) {
        wp_send_json_error(array('message' => 'Could not create identity verification session. Please check API keys.'));
        return;
    }
    
    // On success, send the client secret back to the JavaScript
    wp_send_json_success(array(
        'client_secret' => $session_data['client_secret']
    ));
}
add_action('wp_ajax_gms_create_id_verification_session', 'gms_ajax_create_id_verification_session');
// Note: Use wp_ajax_nopriv_ for actions accessible to non-logged-in users (like guests in the portal).
add_action('wp_ajax_nopriv_gms_create_id_verification_session', 'gms_ajax_create_id_verification_session');


/**
 * Example function for saving a signed agreement.
 */
function gms_ajax_save_signed_agreement() {
    check_ajax_referer('gms_guest_nonce', 'nonce');
    
    $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
    $signature_data = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : '';
    
    if (!$reservation_id || empty($signature_data)) {
        wp_send_json_error(array('message' => 'Missing required data.'));
        return;
    }
    
    // Here, you would call your GMS_Agreement_Handler class to save the signature
    // For example:
    // $agreement_handler = new GMS_Agreement_Handler();
    // $result = $agreement_handler->saveSignature($reservation_id, $signature_data);
    
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
add_action('wp_ajax_gms_save_signed_agreement', 'gms_ajax_save_signed_agreement');
add_action('wp_ajax_nopriv_gms_save_signed_agreement', 'gms_ajax_save_signed_agreement');
