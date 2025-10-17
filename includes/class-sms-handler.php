<?php
/**
 * File: class-sms-handler.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-sms-handler.php
 * * SMS Handler for Guest Management System
 * Uses VoIP.ms API for SMS delivery
 */

class GMS_SMS_Handler implements GMS_Messaging_Channel_Interface {

    private $api_url = 'https://voip.ms/api/v1/rest.php';
    private const OPTION_LAST_SYNC = 'gms_voipms_last_sms_sync';
    private const CRON_HOOK = 'gms_sync_provider_messages';
    private const CRON_INTERVAL = 'gms_five_minutes';

    private static $bootstrapped = false;

    public function __construct() {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        add_filter('cron_schedules', array($this, 'registerCronSchedules'));
        add_action(self::CRON_HOOK, array($this, 'syncProviderInbox'));
        add_action('init', array($this, 'maybeScheduleInboxSync'));
        add_action('gms_housekeeper_assigned', array($this, 'handleHousekeeperAssignment'), 10, 4);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('gms sync-messages', array($this, 'cliSyncProviderInbox'));
        }
    }

    public function registerCronSchedules($schedules) {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 300,
                'display' => __('Every Five Minutes (GMS Messaging)', 'guest-management-system')
            );
        }

        return $schedules;
    }

    public function maybeScheduleInboxSync() {
        if (!$this->isVoipmsConfigured()) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    private function isVoipmsConfigured() {
        $api_username = get_option('gms_voipms_user');
        $api_password = get_option('gms_voipms_pass');
        $did = $this->getConfiguredDid();

        return !empty($api_username) && !empty($api_password) && !empty($did);
    }

    private function getConfiguredDid() {
        return sanitize_text_field(get_option('gms_voipms_did', ''));
    }

    public function syncProviderInbox($args = array()) {
        if (!$this->isVoipmsConfigured()) {
            return array(
                'success' => false,
                'status' => 'error',
                'message' => 'VoIP.ms credentials are not configured.',
                'processed' => 0,
                'duplicates' => 0,
                'errors' => 0,
                'total' => 0,
            );
        }

        $fetch = $this->fetchVoipmsInbox($args);

        if (!$fetch['success']) {
            return array_merge(
                array(
                    'success' => false,
                    'status' => 'error',
                    'processed' => 0,
                    'duplicates' => 0,
                    'errors' => isset($fetch['errors']) ? intval($fetch['errors']) : 0,
                    'total' => 0,
                ),
                $fetch
            );
        }

        $processed = 0;
        $duplicates = 0;
        $errors = 0;
        $details = array();

        foreach ($fetch['messages'] as $message) {
            $normalized = $this->normalizeVoipmsMessage($message);

            if (trim($normalized['body']) === '') {
                $details[] = array('stored' => false, 'reason' => 'empty_body', 'payload' => $message);
                $errors++;
                continue;
            }

            $result = $this->persistNormalizedMessage($normalized);

            if (!empty($result['stored'])) {
                $processed++;
            } elseif (($result['reason'] ?? '') === 'duplicate') {
                $duplicates++;
            } else {
                $errors++;
            }

            $details[] = $result;
        }

        if ($processed > 0) {
            update_option(self::OPTION_LAST_SYNC, current_time('mysql', true));
        }

        $success = ($errors === 0);
        if ($success) {
            $status = ($processed === 0 && $duplicates === 0) ? 'noop' : 'success';
        } else {
            $status = ($processed > 0 || $duplicates > 0) ? 'partial' : 'error';
        }

        return array(
            'success' => $success,
            'status' => $status,
            'processed' => $processed,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'total' => count($fetch['messages']),
            'details' => $details,
        );
    }

    public function ingestWebhookPayload(array $payload, $request = null) {
        $messages = $this->extractMessagesFromPayload($payload);

        $processed = 0;
        $duplicates = 0;
        $errors = 0;
        $details = array();

        foreach ($messages as $message) {
            $normalized = $this->normalizeVoipmsMessage($message);

            if (trim($normalized['body']) === '') {
                $details[] = array(
                    'stored' => false,
                    'reason' => 'empty_body',
                    'payload' => $message,
                );
                $errors++;
                continue;
            }

            $result = $this->persistNormalizedMessage($normalized);

            if (!empty($result['stored'])) {
                $processed++;
            } elseif (($result['reason'] ?? '') === 'duplicate') {
                $duplicates++;
            } else {
                $errors++;
            }

            $details[] = $result;
        }

        if ($processed > 0) {
            update_option(self::OPTION_LAST_SYNC, current_time('mysql', true));
        }

        $success = ($errors === 0);
        if ($success) {
            $status = ($processed === 0 && $duplicates === 0) ? 'noop' : 'success';
        } else {
            $status = ($processed > 0 || $duplicates > 0) ? 'partial' : 'error';
        }

        return array(
            'success' => $success,
            'status' => $status,
            'processed' => $processed,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'total' => count($messages),
            'details' => $details,
        );
    }

    public function cliSyncProviderInbox($args, $assoc_args) {
        $result = $this->syncProviderInbox($assoc_args);

        if ($result['success']) {
            if (($result['status'] ?? '') === 'noop') {
                \WP_CLI::success('No new messages to process.');
            } else {
                \WP_CLI::success(sprintf(
                    'Processed %d message(s) (%d duplicates, %d errors).',
                    $result['processed'],
                    $result['duplicates'],
                    $result['errors']
                ));
            }
        } else {
            $message = $result['message'] ?? 'No messages processed.';
            \WP_CLI::warning($message);

            if (!empty($result['errors'])) {
                \WP_CLI::warning(sprintf('%d error(s) occurred during processing.', $result['errors']));
            }
        }
    }

    private function fetchVoipmsInbox($args = array()) {
        $api_username = get_option('gms_voipms_user');
        $api_password = get_option('gms_voipms_pass');
        $did = $this->getConfiguredDid();

        if (empty($api_username) || empty($api_password) || empty($did)) {
            return array(
                'success' => false,
                'message' => 'VoIP.ms credentials are not configured.',
            );
        }

        $defaults = array(
            'limit' => 100,
            'type' => 1,
        );

        $args = wp_parse_args($args, $defaults);

        $limit = max(1, min(intval($args['limit']), 1000));

        $params = array(
            'api_username' => $api_username,
            'api_password' => $api_password,
            'method' => 'getSMS',
            'did' => $did,
            'limit' => $limit,
            'type' => intval($args['type']),
        );

        if (!empty($args['contact'])) {
            $params['contact'] = preg_replace('/[^0-9+]/', '', (string) $args['contact']);
        }

        if (!empty($args['from'])) {
            $params['from'] = sanitize_text_field($args['from']);
        } else {
            $last_sync = get_option(self::OPTION_LAST_SYNC, '');
            if (!empty($last_sync)) {
                $params['from'] = gmdate('Y-m-d H:i:s', max(0, strtotime($last_sync) - 300));
            }
        }

        if (!empty($args['to'])) {
            $params['to'] = sanitize_text_field($args['to']);
        }

        $params = apply_filters('gms_voipms_getsms_params', $params, $args);

        $url = $this->api_url . '?' . http_build_query($params);

        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return array(
                'success' => false,
                'message' => 'Unable to parse VoIP.ms response.',
                'raw' => $body,
            );
        }

        if (!isset($data['status']) || $data['status'] !== 'success') {
            $message = isset($data['message']) ? sanitize_text_field($data['message']) : 'Unknown error.';

            return array(
                'success' => false,
                'message' => $message,
                'data' => $data,
            );
        }

        $messages = array();

        if (isset($data['sms']) && is_array($data['sms'])) {
            $messages = $data['sms'];
        }

        return array(
            'success' => true,
            'messages' => $messages,
            'data' => $data,
        );
    }

    private function normalizeVoipmsMessage(array $message) {
        $from_source = $message['from'] ?? $message['src'] ?? $message['contact'] ?? '';
        $to_source = $message['to'] ?? $message['dst'] ?? $message['did'] ?? $this->getConfiguredDid();

        $from = $this->extractPhoneNumberField($from_source);
        if ($from === '' && !is_array($from_source)) {
            $from = trim((string) $from_source);
        }

        $to = $this->extractPhoneNumberField($to_source);
        if ($to === '') {
            if (!is_array($to_source)) {
                $to = trim((string) $to_source);
            }

            if ($to === '') {
                $to = $this->getConfiguredDid();
            }
        }

        $body = $message['message'] ?? $message['body'] ?? $message['text'] ?? '';

        $external_id = (string) ($message['id'] ?? $message['sms_id'] ?? $message['message_id'] ?? '');

        if ($external_id === '') {
            $hash_source = array(
                $from,
                $to,
                $message['date'] ?? $message['timestamp'] ?? $message['received_at'] ?? '',
                $body,
            );

            $external_id = md5(wp_json_encode($hash_source));
        }

        $direction = $this->determineMessageDirection($message, $from, $to);

        return array(
            'external_id' => sanitize_text_field($external_id),
            'provider_reference' => sanitize_text_field($message['provider_reference'] ?? $external_id),
            'from' => sanitize_text_field($from),
            'to' => sanitize_text_field($to),
            'from_e164' => GMS_Database::normalizePhoneNumber($from),
            'to_e164' => GMS_Database::normalizePhoneNumber($to),
            'body' => (string) $body,
            'timestamp' => $this->sanitizeTimestamp($message['date'] ?? $message['timestamp'] ?? $message['created'] ?? $message['received_at'] ?? ''),
            'direction' => $direction,
            'raw' => $message,
        );
    }

    private function determineMessageDirection(array $message, $from, $to) {
        $type = $message['direction'] ?? $message['type'] ?? '';

        if (is_numeric($type)) {
            $type = intval($type) === 1 ? 'inbound' : 'outbound';
        } else {
            $type = strtolower((string) $type);
        }

        $type_map = array(
            'inbound' => 'inbound',
            'incoming' => 'inbound',
            'in' => 'inbound',
            'received' => 'inbound',
            'outbound' => 'outbound',
            'outgoing' => 'outbound',
            'out' => 'outbound',
            'sent' => 'outbound',
        );

        if (isset($type_map[$type])) {
            return $type_map[$type];
        }

        $did = GMS_Database::normalizePhoneNumber($this->getConfiguredDid());
        $from_normalized = GMS_Database::normalizePhoneNumber($from);

        if ($did !== '' && $from_normalized === $did) {
            return 'outbound';
        }

        return 'inbound';
    }

    private function sanitizeTimestamp($value) {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_numeric($value) && $value > 0) {
            return date('Y-m-d H:i:s', intval($value));
        }

        if (!empty($value)) {
            $timestamp = strtotime((string) $value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return current_time('mysql');
    }

    private function persistNormalizedMessage(array $normalized) {
        $channel = 'sms';

        if (!empty($normalized['external_id'])) {
            $existing_id = GMS_Database::communicationExists($normalized['external_id'], $channel);
            if ($existing_id) {
                return array(
                    'stored' => false,
                    'reason' => 'duplicate',
                    'id' => $existing_id,
                );
            }
        }

        $context = GMS_Database::resolveMessageContext($channel, $normalized['from'], $normalized['to'], $normalized['direction']);

        $response_context = array(
            'matched' => $context['matched'],
            'guest_number' => $context['guest_number_e164'],
            'service_number' => $context['service_number_e164'],
        );

        if (!$context['matched']) {
            $response_context['status'] = 'unmatched';
        }

        $log_data = array(
            'reservation_id' => $context['reservation_id'],
            'guest_id' => $context['guest_id'],
            'type' => 'sms',
            'recipient' => $normalized['direction'] === 'outbound' ? $normalized['to'] : $normalized['from'],
            'message' => $normalized['body'],
            'status' => $normalized['direction'] === 'outbound' ? 'sent' : 'received',
            'response_data' => array(
                'provider' => 'voip.ms',
                'payload' => $normalized['raw'],
                'context' => $response_context,
            ),
            'provider_reference' => $normalized['provider_reference'],
            'channel' => $channel,
            'direction' => $normalized['direction'],
            'from_number' => $normalized['from'],
            'to_number' => $normalized['to'],
            'external_id' => $normalized['external_id'],
            'sent_at' => $normalized['timestamp'],
        );

        if (!empty($context['thread_key'])) {
            $log_data['thread_key'] = $context['thread_key'];
        }

        $insert_id = GMS_Database::logCommunication($log_data);

        if ($insert_id <= 0) {
            return array(
                'stored' => false,
                'reason' => 'database_error',
            );
        }

        return array(
            'stored' => true,
            'id' => $insert_id,
            'context' => $context,
        );
    }

    private function extractMessagesFromPayload(array $payload) {
        $candidate = $payload;

        while (is_array($candidate)) {
            if (isset($candidate['messages']) && is_array($candidate['messages'])) {
                return array_values(array_filter($candidate['messages'], 'is_array'));
            }

            if ($this->isSequentialArray($candidate)) {
                $all = array();
                foreach ($candidate as $item) {
                    if (is_array($item)) {
                        $all[] = $item;
                    }
                }

                if (!empty($all)) {
                    return $all;
                }
            }

            if ($this->hasMessageIndicators($candidate)) {
                break;
            }

            $envelope_key = $this->detectPayloadEnvelopeKey($candidate);
            if ($envelope_key === null) {
                break;
            }

            $next_candidate = $candidate[$envelope_key];
            if ($next_candidate === $candidate) {
                break;
            }

            $candidate = $next_candidate;
        }

        if (is_array($candidate)) {
            return array($candidate);
        }

        return array($payload);
    }

    private function detectPayloadEnvelopeKey(array $payload) {
        $preferred_keys = array('body', 'payload', 'sms', 'data', 'result');
        foreach ($preferred_keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $key;
            }
        }

        $message_collection_keys = array('to', 'from', 'media', 'attachments', 'recipients');

        foreach ($payload as $key => $value) {
            if (in_array($key, $message_collection_keys, true)) {
                continue;
            }

            if (is_array($value) && $this->isSequentialArray($value)) {
                $has_arrays = false;
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $has_arrays = true;
                        break;
                    }
                }

                if ($has_arrays) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function isSequentialArray(array $value) {
        if (empty($value)) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function hasMessageIndicators(array $candidate) {
        $indicator_keys = array('text', 'message', 'body', 'from', 'to', 'src', 'dst', 'direction');

        foreach ($indicator_keys as $key) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }

            $value = $candidate[$key];

            if (is_array($value)) {
                if (in_array($key, array('from', 'to'), true) && !empty($value)) {
                    return true;
                }

                continue;
            }

            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function extractPhoneNumberField($value) {
        if (is_string($value)) {
            $value = trim($value);

            if ($value !== '' && preg_match('/[0-9]/', $value)) {
                return $value;
            }

            return '';
        }

        if (!is_array($value) || empty($value)) {
            return '';
        }

        $preferred_keys = array('phone_number', 'number', 'phone', 'value', 'e164');

        foreach ($preferred_keys as $key) {
            if (isset($value[$key])) {
                $candidate = $this->extractPhoneNumberField($value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($value as $item) {
            $candidate = $this->extractPhoneNumberField($item);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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
        $portal_url = gms_build_portal_url($portal_token);
        if ($portal_url === false) {
            $portal_url = '';
        }

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
            'response_data' => array(
                'result' => $result,
                'context' => 'welcome_sequence',
            ),
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
        $portal_url = gms_build_portal_url($portal_token);
        if ($portal_url === false) {
            $portal_url = '';
        }

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
            'response_data' => array(
                'result' => $result,
                'context' => 'portal_link_sequence',
            ),
        ));

        return $result;
    }
    
    public function sendDoorCodeSMS($reservation, $door_code = '') {
        $sanitized_code = GMS_Database::sanitizeDoorCode($door_code !== '' ? $door_code : ($reservation['door_code'] ?? ''));

        if ($sanitized_code === '') {
            return false;
        }

        $guest_phone = $reservation['guest_phone'] ?? '';
        if (empty($guest_phone)) {
            error_log('GMS: No phone number for reservation ' . intval($reservation['id'] ?? 0) . ' when sending door code SMS.');
            return false;
        }

        $phone_validation = $this->validatePhoneNumber($guest_phone);
        if (!$phone_validation['is_valid']) {
            error_log('GMS: Invalid phone number provided for reservation ' . intval($reservation['id'] ?? 0) . ' when sending door code SMS.');
            return false;
        }

        $template = get_option('gms_door_code_sms_template');
        if (empty($template)) {
            $template = 'Hi {guest_name}, your door code for {property_name} is {door_code}. Save this message for your stay. - {company_name}';
        }

        $checkin_date_raw = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';

        $replacements = array(
            '{guest_name}' => sanitize_text_field($reservation['guest_name'] ?? ''),
            '{property_name}' => sanitize_text_field($reservation['property_name'] ?? ''),
            '{door_code}' => $sanitized_code,
            '{checkin_date}' => $checkin_date_raw ? date('M j', strtotime($checkin_date_raw)) : '',
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
            'response_data' => array(
                'result' => $result,
                'context' => 'door_code_sequence',
                'door_code' => $sanitized_code,
            ),
        ));

        return $result;
    }

    public function handleHousekeeperAssignment($reservation_id, $new_token, $previous_token, $reservation = null) {
        if (empty($new_token) || !function_exists('gms_build_housekeeper_url')) {
            return;
        }

        if (!is_array($reservation) || empty($reservation)) {
            $reservation = GMS_Database::getReservationById($reservation_id);
        }

        if (!$reservation || !is_array($reservation)) {
            return;
        }

        $link = gms_build_housekeeper_url($new_token);
        if (!is_string($link) || $link === '') {
            return;
        }

        $short_link = function_exists('gms_shorten_url') ? gms_shorten_url($link) : $link;
        if (!is_string($short_link) || $short_link === '') {
            $short_link = $link;
        }

        $contacts = function_exists('gms_get_housekeeper_contacts') ? gms_get_housekeeper_contacts($reservation) : array();
        $phones = isset($contacts['phones']) ? array_filter((array) $contacts['phones']) : array();

        if (empty($phones)) {
            return;
        }

        $property_name = isset($reservation['property_name']) ? sanitize_text_field($reservation['property_name']) : '';
        $checkin = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkin_display = $checkin ? date_i18n(get_option('date_format', 'M j'), strtotime($checkin)) : '';
        $checkin_time_display = $checkin ? date_i18n(get_option('time_format', 'g:i a'), strtotime($checkin)) : '';
        $company_name = get_option('gms_company_name', get_option('blogname'));

        $parts = array();
        $parts[] = $property_name !== ''
            ? sprintf(__('Checklist ready for %s.', 'guest-management-system'), $property_name)
            : __('Cleaning checklist ready.', 'guest-management-system');

        if ($checkin_display !== '') {
            $parts[] = sprintf(__('Check-in %s', 'guest-management-system'), trim($checkin_display . ' ' . $checkin_time_display));
        }

        $parts[] = $short_link;
        $parts[] = $company_name;

        $message = $this->prepareMessageForSending(implode(' ', $parts));

        foreach ($phones as $phone) {
            $validation = $this->validatePhoneNumber($phone);
            if (!$validation['is_valid']) {
                continue;
            }

            $result = $this->sendSMS($validation['sanitized'], $message);

            GMS_Database::logCommunication(array(
                'reservation_id' => intval($reservation['id'] ?? $reservation_id),
                'guest_id' => intval($reservation['guest_id'] ?? 0),
                'type' => 'sms',
                'recipient' => $validation['sanitized'],
                'message' => $message,
                'status' => $result ? 'sent' : 'failed',
                'response_data' => array(
                    'result' => $result,
                    'context' => 'housekeeper_assignment',
                    'housekeeper_link' => array(
                        'url' => $link,
                        'short' => $short_link,
                    ),
                ),
            ));
        }
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
        $portal_url = gms_build_portal_url($portal_token);
        if ($portal_url === false) {
            $portal_url = '';
        }

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
        $shortened = gms_shorten_url($url);

        if (!is_string($shortened) || $shortened === '') {
            return $url;
        }

        return $shortened;
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
