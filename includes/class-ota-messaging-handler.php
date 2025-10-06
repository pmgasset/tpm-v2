<?php
/**
 * OTA messaging handler for posting updates to Airbnb, VRBO, and Booking.com inboxes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_OTA_Messaging_Handler {

    /**
     * Map supported platforms to option keys and default endpoints.
     *
     * @return array
     */
    private function get_platform_config() {
        $defaults = array(
            'airbnb' => array(
                'label' => __('Airbnb', 'guest-management-system'),
                'option' => 'gms_airbnb_access_token',
                'endpoint' => 'https://api.airbnb.com/v2/messages',
            ),
            'vrbo' => array(
                'label' => __('VRBO', 'guest-management-system'),
                'option' => 'gms_vrbo_access_token',
                'endpoint' => 'https://partner.api.expediapartnercentral.com/v1/vrbo/messages',
            ),
            'booking_com' => array(
                'label' => __('Booking.com', 'guest-management-system'),
                'option' => 'gms_booking_access_token',
                'endpoint' => 'https://distribution-xml.booking.com/json/bookings',
            ),
        );

        /**
         * Filter the OTA platform configuration.
         *
         * @param array $defaults Platform configuration array.
         */
        return apply_filters('gms_ota_platform_config', $defaults);
    }

    /**
     * Send the portal invitation message through the OTA inbox when possible.
     *
     * @param array $reservation Reservation data.
     *
     * @return array Result data including success boolean, status keyword, and message string.
     */
    public function sendPortalInvitation($reservation) {
        $portal_url = '';
        $token = isset($reservation['portal_token']) ? sanitize_text_field($reservation['portal_token']) : '';
        $built_url = gms_build_portal_url($token);
        if ($built_url !== false) {
            $portal_url = $built_url;
        }

        $message = sprintf(
            /* translators: 1: guest name, 2: property name, 3: portal url */
            __('Hello %1$s, your guest portal for %2$s is ready: %3$s', 'guest-management-system'),
            sanitize_text_field($reservation['guest_name'] ?? ''),
            sanitize_text_field($reservation['property_name'] ?? ''),
            esc_url_raw($portal_url)
        );

        return $this->dispatch_platform_message($reservation, array(
            'context' => 'portal_link_sequence',
            'subject' => __('Guest Portal Ready', 'guest-management-system'),
            'body' => $message,
        ));
    }

    /**
     * Send the door code details through the OTA inbox.
     *
     * @param array  $reservation Reservation data.
     * @param string $door_code   Door code string.
     *
     * @return array
     */
    public function sendDoorCodeMessage($reservation, $door_code) {
        $sanitized_code = GMS_Database::sanitizeDoorCode($door_code);
        if ($sanitized_code === '') {
            return array(
                'success' => false,
                'status' => 'skipped',
                'message' => __('Door code not provided. OTA message skipped.', 'guest-management-system'),
            );
        }

        $checkin_raw = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkin_time = $checkin_raw ? date_i18n(get_option('time_format', 'g:i a'), strtotime($checkin_raw)) : '';
        $checkin_date = $checkin_raw ? date_i18n(get_option('date_format', 'M j, Y'), strtotime($checkin_raw)) : '';

        $message = sprintf(
            /* translators: 1: guest name, 2: property name, 3: door code, 4: check-in date, 5: check-in time */
            __('Hi %1$s, your access code for %2$s is %3$s. It becomes active on %4$s at %5$s.', 'guest-management-system'),
            sanitize_text_field($reservation['guest_name'] ?? ''),
            sanitize_text_field($reservation['property_name'] ?? ''),
            $sanitized_code,
            esc_html($checkin_date),
            esc_html($checkin_time)
        );

        return $this->dispatch_platform_message($reservation, array(
            'context' => 'door_code_sequence',
            'subject' => __('Door Code Details', 'guest-management-system'),
            'body' => $message,
            'extra' => array(
                'door_code' => $sanitized_code,
            ),
        ));
    }

    /**
     * Send the welcome message through the OTA inbox.
     *
     * @param array $reservation Reservation data.
     *
     * @return array
     */
    public function sendWelcomeMessage($reservation) {
        $checkin_raw = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
        $checkin_time = $checkin_raw ? date_i18n(get_option('time_format', 'g:i a'), strtotime($checkin_raw)) : '';

        $message = sprintf(
            /* translators: 1: guest name, 2: property name, 3: check-in time */
            __('Welcome %1$s! We look forward to hosting you at %2$s. Check-in is at %3$s—message us here if you need anything.', 'guest-management-system'),
            sanitize_text_field($reservation['guest_name'] ?? ''),
            sanitize_text_field($reservation['property_name'] ?? ''),
            esc_html($checkin_time)
        );

        return $this->dispatch_platform_message($reservation, array(
            'context' => 'welcome_sequence',
            'subject' => __('Welcome to Your Stay', 'guest-management-system'),
            'body' => $message,
        ));
    }

    /**
     * Normalize a reservation platform value.
     *
     * @param string $platform Platform string stored on reservation.
     *
     * @return string Normalized key.
     */
    private function normalize_platform($platform) {
        $platform = strtolower(trim((string) $platform));
        if ($platform === '') {
            return '';
        }

        $platform = str_replace(array('.', ' ', '-'), '_', $platform);

        if ($platform === 'bookingcom') {
            $platform = 'booking_com';
        }

        return $platform;
    }

    /**
     * Resolve the platform configuration for a reservation.
     *
     * @param array $reservation Reservation data.
     *
     * @return array Array containing key, label, option, and endpoint. Empty array when unsupported.
     */
    private function resolve_platform($reservation) {
        $platform_key = isset($reservation['platform']) ? $this->normalize_platform($reservation['platform']) : '';

        if ($platform_key === '') {
            return array();
        }

        $config = $this->get_platform_config();

        if (!isset($config[$platform_key])) {
            return array();
        }

        $config[$platform_key]['key'] = $platform_key;

        return $config[$platform_key];
    }

    /**
     * Send a message to the OTA platform inbox.
     *
     * @param array $reservation Reservation data.
     * @param array $payload     Message payload details.
     *
     * @return array Result array.
     */
    private function dispatch_platform_message($reservation, $payload) {
        if (!is_array($reservation) || empty($reservation)) {
            return array(
                'success' => false,
                'status' => 'skipped',
                'message' => __('Reservation data missing. OTA message skipped.', 'guest-management-system'),
            );
        }

        $platform = $this->resolve_platform($reservation);
        if (empty($platform)) {
            return array(
                'success' => false,
                'status' => 'skipped',
                'message' => __('No connected OTA platform for this reservation.', 'guest-management-system'),
            );
        }

        $access_token = isset($platform['option']) ? trim((string) get_option($platform['option'], '')) : '';
        if ($access_token === '') {
            return array(
                'success' => false,
                'status' => 'skipped',
                'message' => sprintf(
                    /* translators: %s: platform label */
                    __('%s messaging credentials are not configured. OTA message skipped.', 'guest-management-system'),
                    esc_html($platform['label'])
                ),
            );
        }

        $endpoint_map = $this->get_platform_config();
        $endpoint = isset($platform['endpoint']) ? $platform['endpoint'] : '';

        if ($endpoint === '' && isset($endpoint_map[$platform['key']]['endpoint'])) {
            $endpoint = $endpoint_map[$platform['key']]['endpoint'];
        }

        /**
         * Filter the endpoint URL used for OTA messaging per platform.
         *
         * @param string $endpoint    Endpoint URL.
         * @param string $platformKey Normalized platform key.
         * @param array  $reservation Reservation payload.
         */
        $endpoint = apply_filters('gms_ota_platform_endpoint', $endpoint, $platform['key'], $reservation);

        if (empty($endpoint)) {
            return array(
                'success' => false,
                'status' => 'skipped',
                'message' => sprintf(
                    /* translators: %s: platform label */
                    __('No endpoint configured for %s messaging.', 'guest-management-system'),
                    esc_html($platform['label'])
                ),
            );
        }

        $body_text = isset($payload['body']) ? wp_strip_all_tags($payload['body']) : '';
        $subject = isset($payload['subject']) ? wp_strip_all_tags($payload['subject']) : '';
        $context = isset($payload['context']) ? sanitize_key($payload['context']) : 'ota_message';
        $reservation_id = intval($reservation['id'] ?? 0);
        $guest_id = intval($reservation['guest_id'] ?? 0);
        $booking_reference = sanitize_text_field($reservation['booking_reference'] ?? '');

        $request_payload = array(
            'reservation_id' => $reservation_id,
            'booking_reference' => $booking_reference,
            'subject' => $subject,
            'message' => $body_text,
        );

        $request_payload = array_merge($request_payload, isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : array());

        $request_args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'body' => wp_json_encode($request_payload),
            'timeout' => 20,
        );

        $response = wp_remote_post($endpoint, $request_args);
        $response_code = 0;
        $response_body = '';
        $provider_reference = '';
        $thread_id = '';

        if (!is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            $decoded = json_decode($response_body, true);
            if (is_array($decoded)) {
                if (isset($decoded['message_id'])) {
                    $provider_reference = sanitize_text_field($decoded['message_id']);
                }
                if (isset($decoded['thread_id'])) {
                    $thread_id = sanitize_text_field($decoded['thread_id']);
                }
            }
        }

        $success = !is_wp_error($response) && $response_code >= 200 && $response_code < 300;
        $status = $success ? 'sent' : 'failed';

        $log_data = array(
            'reservation_id' => $reservation_id,
            'guest_id' => $guest_id,
            'type' => 'platform_message',
            'channel' => $platform['key'],
            'recipient' => sprintf('%s:%s', $platform['key'], $booking_reference !== '' ? $booking_reference : $reservation_id),
            'subject' => $subject,
            'message' => $body_text,
            'status' => $status,
            'provider_reference' => $provider_reference,
            'response_data' => array(
                'context' => $context,
                'platform' => $platform['key'],
                'status_code' => $response_code,
                'thread_id' => $thread_id,
                'raw_response' => $response_body,
            ),
            'sent_at' => current_time('mysql'),
        );

        if (!$success && is_wp_error($response)) {
            $log_data['response_data']['error'] = $response->get_error_message();
        }

        if (isset($payload['extra']['door_code'])) {
            $log_data['response_data']['door_code'] = $payload['extra']['door_code'];
        }

        GMS_Database::logCommunication($log_data);

        $message = $success
            ? sprintf(
                /* translators: %s: platform label */
                __('Message delivered through %s inbox.', 'guest-management-system'),
                esc_html($platform['label'])
            )
            : sprintf(
                /* translators: 1: platform label, 2: status code */
                __('Failed to deliver via %1$s inbox (HTTP %2$s).', 'guest-management-system'),
                esc_html($platform['label']),
                esc_html($response_code ?: 'n/a')
            );

        return array(
            'success' => $success,
            'status' => $status,
            'message' => $message,
        );
    }
}
