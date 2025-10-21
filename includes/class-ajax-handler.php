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

        // Messaging inbox endpoints
        add_action('wp_ajax_gms_list_message_threads', array($this, 'list_message_threads'));
        add_action('wp_ajax_gms_fetch_thread_messages', array($this, 'fetch_thread_messages'));
        add_action('wp_ajax_gms_send_message_reply', array($this, 'send_message_reply'));
        add_action('wp_ajax_gms_mark_thread_read', array($this, 'mark_thread_read'));
        add_action('wp_ajax_gms_list_message_templates', array($this, 'list_message_templates'));
        add_action('wp_ajax_gms_list_operational_logs', array($this, 'list_operational_logs'));
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

    private function verify_messaging_permissions($nonce_action = 'gms_messaging_nonce') {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to manage messages.', 'guest-management-system')));
        }

        check_ajax_referer($nonce_action, 'nonce');
    }

    public function list_message_threads() {
        $this->verify_messaging_permissions();

        $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
        $per_page = isset($_REQUEST['per_page']) ? max(1, min(50, intval($_REQUEST['per_page']))) : 20;
        $search = isset($_REQUEST['search']) ? sanitize_text_field(wp_unslash($_REQUEST['search'])) : '';
        $channel = isset($_REQUEST['channel']) ? sanitize_key(wp_unslash($_REQUEST['channel'])) : '';

        $channels = array();
        if ($channel !== '') {
            if ($channel === 'all') {
                $channels = GMS_Database::getConversationalChannels();
            } else {
                $channels[] = $channel;
            }
        }

        $threads = GMS_Database::getCommunicationThreads(array(
            'page' => $page,
            'per_page' => $per_page,
            'search' => $search,
            'channels' => $channels,
        ));

        wp_send_json_success($threads);
    }

    public function list_operational_logs() {
        $this->verify_messaging_permissions('gms_logs_nonce');

        $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
        $per_page = isset($_REQUEST['per_page']) ? max(1, min(100, intval($_REQUEST['per_page']))) : 50;
        $search = isset($_REQUEST['search']) ? sanitize_text_field(wp_unslash($_REQUEST['search'])) : '';
        $requested = array();

        if (isset($_REQUEST['channels'])) {
            $channels = is_array($_REQUEST['channels']) ? $_REQUEST['channels'] : explode(',', wp_unslash($_REQUEST['channels']));
            foreach ($channels as $channel) {
                $key = sanitize_key($channel);
                if ($key === '') {
                    continue;
                }
                $requested[] = $key;
            }
        }

        $logs = GMS_Database::getOperationalLogs(array(
            'page' => $page,
            'per_page' => $per_page,
            'search' => $search,
            'channels' => $requested,
        ));

        wp_send_json_success($logs);
    }

    public function fetch_thread_messages() {
        $this->verify_messaging_permissions();

        $thread_key = isset($_REQUEST['thread_key']) ? sanitize_text_field(wp_unslash($_REQUEST['thread_key'])) : '';
        if ($thread_key === '') {
            wp_send_json_error(array('message' => __('Invalid conversation selected.', 'guest-management-system')));
        }

        $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
        $per_page = isset($_REQUEST['per_page']) ? max(1, min(100, intval($_REQUEST['per_page']))) : 50;
        $order = isset($_REQUEST['order']) ? sanitize_text_field(wp_unslash($_REQUEST['order'])) : 'ASC';

        $messages = GMS_Database::getThreadMessages($thread_key, array(
            'page' => $page,
            'per_page' => $per_page,
            'order' => $order,
        ));

        $context = GMS_Database::getCommunicationThreadContext($thread_key);

        if (!$context || !GMS_Database::isConversationalChannel($context['channel'] ?? '')) {
            wp_send_json_error(array('message' => __('Unable to resolve the selected conversation.', 'guest-management-system')));
        }

        wp_send_json_success(array(
            'messages' => $messages,
            'thread' => $context,
        ));
    }

    public function send_message_reply() {
        $this->verify_messaging_permissions();

        $thread_key = isset($_POST['thread_key']) ? sanitize_text_field(wp_unslash($_POST['thread_key'])) : '';
        $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : 'sms';
        $message = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';

        if ($thread_key === '' || $message === '') {
            wp_send_json_error(array('message' => __('Message content is required.', 'guest-management-system')));
        }

        $context = GMS_Database::getCommunicationThreadContext($thread_key);
        if (!$context) {
            wp_send_json_error(array('message' => __('Unable to resolve the selected conversation.', 'guest-management-system')));
        }

        if ($channel !== 'sms' || sanitize_key($context['channel'] ?? '') !== 'sms') {
            wp_send_json_error(array('message' => __('Only SMS replies are supported at this time.', 'guest-management-system')));
        }

        $outbound_number = isset($context['service_number']) ? sanitize_text_field($context['service_number']) : '';
        if ($outbound_number === '') {
            $outbound_number = sanitize_text_field(get_option('gms_voipms_did', ''));
        }

        $guest_number = isset($context['guest_number']) ? sanitize_text_field($context['guest_number']) : '';
        if ($guest_number === '') {
            $guest_number = isset($context['guest_phone']) ? sanitize_text_field($context['guest_phone']) : '';
        }

        if ($guest_number === '') {
            wp_send_json_error(array('message' => __('Guest phone number is missing for this conversation.', 'guest-management-system')));
        }

        $clean_message = trim(wp_strip_all_tags($message));
        if ($clean_message === '') {
            wp_send_json_error(array('message' => __('Message content is required.', 'guest-management-system')));
        }

        $sms_handler = new GMS_SMS_Handler();
        $sent = $sms_handler->sendSMS($guest_number, $clean_message);

        if (!$sent) {
            wp_send_json_error(array('message' => __('Unable to send SMS reply. Please check your messaging configuration.', 'guest-management-system')));
        }

        $log_id = GMS_Database::logCommunication(array(
            'reservation_id' => intval($context['reservation_id'] ?? 0),
            'guest_id' => intval($context['guest_id'] ?? 0),
            'type' => $channel,
            'recipient' => $guest_number,
            'message' => $clean_message,
            'status' => 'sent',
            'channel' => $channel,
            'direction' => 'outbound',
            'from_number' => $outbound_number,
            'to_number' => $guest_number,
            'thread_key' => $thread_key,
        ));

        $message_row = $log_id ? GMS_Database::getCommunicationById($log_id) : null;

        wp_send_json_success(array(
            'message' => $message_row,
        ));
    }

    public function mark_thread_read() {
        $this->verify_messaging_permissions();

        $thread_key = isset($_POST['thread_key']) ? sanitize_text_field(wp_unslash($_POST['thread_key'])) : '';
        if ($thread_key === '') {
            wp_send_json_error(array('message' => __('Invalid conversation selected.', 'guest-management-system')));
        }

        $updated = GMS_Database::markThreadAsRead($thread_key);
        $context = GMS_Database::getCommunicationThreadContext($thread_key);

        wp_send_json_success(array(
            'updated' => $updated,
            'thread' => $context,
        ));
    }

    public function list_message_templates() {
        $this->verify_messaging_permissions();

        $channel = isset($_REQUEST['channel']) ? sanitize_key(wp_unslash($_REQUEST['channel'])) : '';
        $search = isset($_REQUEST['search']) ? sanitize_text_field(wp_unslash($_REQUEST['search'])) : '';
        $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
        $per_page = isset($_REQUEST['per_page']) ? max(1, min(100, intval($_REQUEST['per_page']))) : 25;

        $templates = GMS_Database::getMessageTemplates(array(
            'channel' => $channel,
            'search' => $search,
            'page' => $page,
            'per_page' => $per_page,
        ));

        wp_send_json_success($templates);
    }
}
