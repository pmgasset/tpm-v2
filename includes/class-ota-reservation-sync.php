<?php
/**
 * OTA reservation synchronization handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_OTA_Reservation_Sync {

    /**
     * Retrieve OTA platform configuration for reservation syncing.
     *
     * @return array
     */
    public function get_platform_config() {
        $defaults = array(
            'airbnb' => array(
                'label' => __('Airbnb', 'guest-management-system'),
                'option' => 'gms_airbnb_access_token',
                'reservation_endpoint' => 'https://api.airbnb.com/v2/reservations/%s',
                'collection_endpoint' => 'https://api.airbnb.com/v2/reservations',
                'reservation_param' => 'confirmation_code',
            ),
            'vrbo' => array(
                'label' => __('VRBO', 'guest-management-system'),
                'option' => 'gms_vrbo_access_token',
                'reservation_endpoint' => 'https://partner.api.expediapartnercentral.com/v1/vrbo/reservations/%s',
                'collection_endpoint' => 'https://partner.api.expediapartnercentral.com/v1/vrbo/reservations',
                'reservation_param' => 'itineraryId',
            ),
            'booking_com' => array(
                'label' => __('Booking.com', 'guest-management-system'),
                'option' => 'gms_booking_access_token',
                'reservation_endpoint' => 'https://distribution-xml.booking.com/json/reservations/%s',
                'collection_endpoint' => 'https://distribution-xml.booking.com/json/reservations',
                'reservation_param' => 'reservation_id',
            ),
        );

        /**
         * Filter the OTA reservation configuration map.
         *
         * @param array $defaults Default configuration values.
         */
        return apply_filters('gms_ota_reservation_config', $defaults);
    }

    /**
     * Synchronize a single reservation with its OTA platform.
     *
     * @param array $reservation Reservation data array.
     * @param array $args        Optional additional arguments for the request.
     *
     * @return array Result information.
     */
    public function sync_reservation($reservation, $args = array()) {
        if (!is_array($reservation) || empty($reservation)) {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => array(__('Reservation data missing. Unable to sync with OTA platform.', 'guest-management-system')),
            );
        }

        $platform_key = $this->normalize_platform($reservation['platform'] ?? '');
        if ($platform_key === '') {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => array(__('Assign a platform before syncing reservation details.', 'guest-management-system')),
            );
        }

        $booking_reference = $this->normalize_booking_reference($reservation['booking_reference'] ?? '');
        if ($booking_reference === '') {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => array(__('Add the platform booking reference to sync reservation details.', 'guest-management-system')),
            );
        }

        $platform_config = $this->resolve_platform($platform_key);
        if (empty($platform_config)) {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => array(__('Unknown OTA platform configuration.', 'guest-management-system')),
            );
        }

        if ($platform_config['token'] === '') {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => array(
                    sprintf(
                        /* translators: %s: OTA platform label */
                        __('Add credentials for %s before syncing reservation details.', 'guest-management-system'),
                        $platform_config['label']
                    )
                ),
            );
        }

        $request = $this->request_single_reservation($platform_key, $platform_config, $booking_reference, $args);
        if (empty($request['success'])) {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => isset($request['errors']) ? (array) $request['errors'] : array(__('Unable to reach the OTA reservation endpoint.', 'guest-management-system')),
            );
        }

        $mapped = $this->map_payload_to_reservation($platform_key, $request['payload'], $booking_reference, $reservation);
        $upsert = $this->upsert_reservation($platform_key, $platform_config['label'], $mapped, $reservation);

        $result = array(
            'success' => !empty($upsert['success']),
            'action' => $upsert['action'] ?? '',
            'message' => $upsert['message'] ?? '',
            'updated_fields' => $upsert['updated_fields'] ?? array(),
            'reservation_id' => $upsert['reservation_id'] ?? ($reservation['id'] ?? 0),
            'booking_reference' => $upsert['booking_reference'] ?? $booking_reference,
            'errors' => isset($upsert['errors']) ? (array) $upsert['errors'] : array(),
        );

        if ($result['success'] && $result['message'] === '') {
            $result['message'] = sprintf(
                /* translators: %s: OTA platform label */
                __('Reservation synced from %s.', 'guest-management-system'),
                $platform_config['label']
            );
        }

        if (!$result['success'] && empty($result['errors'])) {
            $result['errors'][] = sprintf(
                /* translators: %s: OTA platform label */
                __('Unable to sync reservation from %s.', 'guest-management-system'),
                $platform_config['label']
            );
        }

        return $result;
    }

    /**
     * Import reservations from a specific platform.
     *
     * @param string $platform Platform key or label.
     * @param array  $args     Optional request arguments.
     *
     * @return array Summary information.
     */
    public function import_platform_reservations($platform, $args = array()) {
        $platform_key = $this->normalize_platform($platform);

        if ($platform_key === 'all' || $platform_key === '') {
            return $this->import_all_platforms($args);
        }

        $platform_config = $this->resolve_platform($platform_key);
        if (empty($platform_config)) {
            return array(
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'synced' => 0,
                'skipped' => 0,
                'errors' => array(__('Unknown OTA platform configuration.', 'guest-management-system')),
                'messages' => array(),
            );
        }

        if ($platform_config['token'] === '') {
            return array(
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'synced' => 0,
                'skipped' => 0,
                'errors' => array(
                    sprintf(
                        /* translators: %s: OTA platform label */
                        __('Add credentials for %s before importing reservations.', 'guest-management-system'),
                        $platform_config['label']
                    )
                ),
                'messages' => array(),
            );
        }

        $request = $this->request_reservation_collection($platform_key, $platform_config, $args);
        if (empty($request['success'])) {
            return array(
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'synced' => 0,
                'skipped' => 0,
                'errors' => isset($request['errors']) ? (array) $request['errors'] : array(__('Unable to import reservations from the OTA.', 'guest-management-system')),
                'messages' => array(),
            );
        }

        $reservations = isset($request['reservations']) && is_array($request['reservations']) ? $request['reservations'] : array();

        $summary = array(
            'success' => false,
            'created' => 0,
            'updated' => 0,
            'synced' => 0,
            'skipped' => 0,
            'errors' => array(),
            'messages' => array(),
        );

        if (empty($reservations)) {
            $summary['success'] = true;
            $summary['messages'][] = sprintf(
                /* translators: %s: OTA platform label */
                __('No reservations were returned from %s.', 'guest-management-system'),
                $platform_config['label']
            );
            return $summary;
        }

        foreach ($reservations as $reservation_payload) {
            $mapped = $this->map_payload_to_reservation($platform_key, $reservation_payload);
            $booking_reference = $this->normalize_booking_reference($mapped['booking_reference'] ?? '');

            if ($booking_reference === '') {
                $summary['skipped']++;
                $summary['errors'][] = sprintf(
                    /* translators: %s: OTA platform label */
                    __('Skipped a %s reservation without a booking reference.', 'guest-management-system'),
                    $platform_config['label']
                );
                continue;
            }

            $existing = GMS_Database::getReservationByPlatformReference($platform_key, $booking_reference);
            $upsert = $this->upsert_reservation($platform_key, $platform_config['label'], $mapped, $existing);

            if (empty($upsert['success'])) {
                $summary['errors'] = array_merge($summary['errors'], isset($upsert['errors']) ? (array) $upsert['errors'] : array());
                continue;
            }

            $summary['success'] = true;
            $summary['messages'][] = sprintf(
                '%1$s – %2$s (%3$s)',
                $platform_config['label'],
                $upsert['message'] ?? __('Reservation synced.', 'guest-management-system'),
                $upsert['booking_reference'] ?? $booking_reference
            );

            switch ($upsert['action'] ?? '') {
                case 'created':
                    $summary['created']++;
                    break;
                case 'updated':
                    $summary['updated']++;
                    break;
                default:
                    $summary['synced']++;
                    break;
            }
        }

        return $summary;
    }

    /**
     * Import reservations from all configured platforms.
     *
     * @param array $args Optional request arguments.
     *
     * @return array Combined summary information.
     */
    public function import_all_platforms($args = array()) {
        $combined = array(
            'success' => false,
            'created' => 0,
            'updated' => 0,
            'synced' => 0,
            'skipped' => 0,
            'errors' => array(),
            'messages' => array(),
        );

        $config = $this->get_platform_config();

        foreach ($config as $platform_key => $platform_settings) {
            $result = $this->import_platform_reservations($platform_key, $args);

            $combined['created'] += intval($result['created'] ?? 0);
            $combined['updated'] += intval($result['updated'] ?? 0);
            $combined['synced'] += intval($result['synced'] ?? 0);
            $combined['skipped'] += intval($result['skipped'] ?? 0);
            $combined['messages'] = array_merge($combined['messages'], isset($result['messages']) ? (array) $result['messages'] : array());
            $combined['errors'] = array_merge($combined['errors'], isset($result['errors']) ? (array) $result['errors'] : array());
            $combined['success'] = $combined['success'] || !empty($result['success']);
        }

        return $combined;
    }

    /**
     * Normalize a platform identifier.
     *
     * @param string $platform Platform identifier.
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
     * Resolve platform configuration and credentials.
     *
     * @param string $platform_key Normalized platform key.
     *
     * @return array
     */
    private function resolve_platform($platform_key) {
        $platform_key = $this->normalize_platform($platform_key);

        if ($platform_key === '') {
            return array();
        }

        $config = $this->get_platform_config();

        if (!isset($config[$platform_key])) {
            return array();
        }

        $platform = $config[$platform_key];
        $option_key = isset($platform['option']) ? $platform['option'] : '';
        $platform['key'] = $platform_key;
        $platform['token'] = $option_key !== '' ? trim((string) get_option($option_key, '')) : '';

        return $platform;
    }
    /**
     * Perform a single reservation request against the OTA platform.
     *
     * @param string $platform_key      Platform key.
     * @param array  $platform_config   Platform configuration.
     * @param string $booking_reference Booking reference identifier.
     * @param array  $args              Optional request arguments.
     *
     * @return array
     */
    private function request_single_reservation($platform_key, $platform_config, $booking_reference, $args = array()) {
        $endpoint = $this->build_single_endpoint($platform_key, $platform_config, $booking_reference, $args);

        if ($endpoint === '') {
            return array(
                'success' => false,
                'errors' => array(__('OTA reservation endpoint not configured.', 'guest-management-system')),
            );
        }

        $request_args = $this->build_request_args($platform_config['token'], $platform_key, 'single', array(
            'booking_reference' => $booking_reference,
            'config' => $platform_config,
        ));

        $response = wp_remote_get($endpoint, $request_args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'errors' => array($response->get_error_message()),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = $body !== '' ? json_decode($body, true) : array();

        if ($status_code < 200 || $status_code >= 300) {
            return array(
                'success' => false,
                'errors' => array(
                    sprintf(
                        /* translators: 1: HTTP status code, 2: OTA platform label */
                        __('OTA request returned HTTP %1$s for %2$s.', 'guest-management-system'),
                        $status_code,
                        $platform_config['label']
                    )
                ),
            );
        }

        $payload = $this->extract_single_payload($decoded);
        if (empty($payload)) {
            return array(
                'success' => false,
                'errors' => array(__('No reservation data was returned from the OTA.', 'guest-management-system')),
            );
        }

        /**
         * Filter the OTA reservation payload before mapping.
         *
         * @param array  $payload      Reservation payload.
         * @param string $platform_key Normalized platform key.
         * @param string $context      Request context.
         * @param array  $raw_response Raw decoded response.
         */
        $payload = apply_filters('gms_ota_reservation_payload', $payload, $platform_key, 'single', $decoded);

        return array(
            'success' => true,
            'payload' => $payload,
            'status_code' => $status_code,
        );
    }

    /**
     * Perform a reservation collection request.
     *
     * @param string $platform_key    Platform key.
     * @param array  $platform_config Platform configuration.
     * @param array  $args            Request arguments.
     *
     * @return array
     */
    private function request_reservation_collection($platform_key, $platform_config, $args = array()) {
        $endpoint = $this->build_collection_endpoint($platform_key, $platform_config, $args);

        if ($endpoint === '') {
            return array(
                'success' => false,
                'errors' => array(__('OTA collection endpoint not configured.', 'guest-management-system')),
            );
        }

        $request_args = $this->build_request_args($platform_config['token'], $platform_key, 'collection', array(
            'config' => $platform_config,
            'args' => $args,
        ));

        $response = wp_remote_get($endpoint, $request_args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'errors' => array($response->get_error_message()),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = $body !== '' ? json_decode($body, true) : array();

        if ($status_code < 200 || $status_code >= 300) {
            return array(
                'success' => false,
                'errors' => array(
                    sprintf(
                        /* translators: 1: HTTP status code, 2: OTA platform label */
                        __('OTA request returned HTTP %1$s for %2$s.', 'guest-management-system'),
                        $status_code,
                        $platform_config['label']
                    )
                ),
            );
        }

        $payload = $this->extract_collection_payload($decoded);

        /**
         * Filter the OTA reservation collection payload.
         *
         * @param array  $payload      Collection payload.
         * @param string $platform_key Platform key.
         * @param string $context      Request context.
         * @param array  $raw_response Raw decoded response.
         */
        $payload = apply_filters('gms_ota_reservation_payload', $payload, $platform_key, 'collection', $decoded);

        if (!is_array($payload)) {
            $payload = array();
        }

        return array(
            'success' => true,
            'reservations' => $payload,
            'status_code' => $status_code,
        );
    }

    /**
     * Build request arguments for the OTA HTTP requests.
     *
     * @param string $token        Access token.
     * @param string $platform_key Platform key.
     * @param string $context      Request context.
     * @param array  $extra        Extra arguments for filters.
     *
     * @return array
     */
    private function build_request_args($token, $platform_key, $context, $extra = array()) {
        $headers = array(
            'Accept' => 'application/json',
        );

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = array(
            'headers' => $headers,
            'timeout' => 20,
        );

        /**
         * Filter the OTA reservation request arguments.
         *
         * @param array  $args         Request arguments.
         * @param string $platform_key Platform key.
         * @param string $context      Request context.
         * @param array  $extra        Extra data for filters.
         */
        return apply_filters('gms_ota_reservation_request_args', $args, $platform_key, $context, $extra);
    }

    /**
     * Build the endpoint URL for a single reservation request.
     *
     * @param string $platform_key      Platform key.
     * @param array  $platform_config   Platform configuration.
     * @param string $booking_reference Booking reference.
     * @param array  $args              Additional arguments.
     *
     * @return string
     */
    private function build_single_endpoint($platform_key, $platform_config, $booking_reference, $args = array()) {
        $endpoint = isset($platform_config['reservation_endpoint']) ? trim((string) $platform_config['reservation_endpoint']) : '';

        if ($endpoint === '') {
            return '';
        }

        if (strpos($endpoint, '%s') !== false) {
            $endpoint = sprintf($endpoint, rawurlencode($booking_reference));
        } else {
            $param = isset($platform_config['reservation_param']) ? $platform_config['reservation_param'] : 'booking_reference';
            $endpoint = add_query_arg($param, rawurlencode($booking_reference), $endpoint);
        }

        /**
         * Filter the OTA reservation endpoint.
         *
         * @param string $endpoint     Endpoint URL.
         * @param string $platform_key Platform key.
         * @param string $context      Request context.
         * @param array  $args         Additional arguments.
         */
        return apply_filters('gms_ota_reservation_endpoint', $endpoint, $platform_key, 'single', $args);
    }

    /**
     * Build the endpoint URL for reservation collection requests.
     *
     * @param string $platform_key    Platform key.
     * @param array  $platform_config Platform configuration.
     * @param array  $args            Request arguments.
     *
     * @return string
     */
    private function build_collection_endpoint($platform_key, $platform_config, $args = array()) {
        $endpoint = isset($platform_config['collection_endpoint']) && $platform_config['collection_endpoint'] !== ''
            ? trim((string) $platform_config['collection_endpoint'])
            : trim((string) ($platform_config['reservation_endpoint'] ?? ''));

        if ($endpoint === '') {
            return '';
        }

        $query_args = array();

        if (!empty($args['since'])) {
            $since_timestamp = strtotime($args['since'] . ' 00:00:00');
            if ($since_timestamp !== false) {
                $query_args['updated_since'] = gmdate('c', $since_timestamp);
            }
        }

        if (!empty($args['limit'])) {
            $query_args['limit'] = max(1, min(absint($args['limit']), 200));
        }

        if (!empty($args['status'])) {
            $query_args['status'] = sanitize_key($args['status']);
        }

        if (!empty($query_args)) {
            $endpoint = add_query_arg($query_args, $endpoint);
        }

        /**
         * Filter the OTA collection endpoint.
         *
         * @param string $endpoint     Endpoint URL.
         * @param string $platform_key Platform key.
         * @param string $context      Request context.
         * @param array  $args         Additional arguments.
         */
        return apply_filters('gms_ota_reservation_endpoint', $endpoint, $platform_key, 'collection', $args);
    }

    /**
     * Extract a reservation payload from the OTA response.
     *
     * @param mixed $decoded Decoded response.
     *
     * @return array
     */
    private function extract_single_payload($decoded) {
        if (!is_array($decoded)) {
            return array();
        }

        if (isset($decoded['reservation']) && is_array($decoded['reservation'])) {
            return $decoded['reservation'];
        }

        if (isset($decoded['data'])) {
            if (isset($decoded['data']['reservation']) && is_array($decoded['data']['reservation'])) {
                return $decoded['data']['reservation'];
            }

            if (is_array($decoded['data']) && !empty($decoded['data'])) {
                $first = reset($decoded['data']);
                if (is_array($first)) {
                    return $first;
                }
            }
        }

        if (isset($decoded['result']) && is_array($decoded['result'])) {
            if (isset($decoded['result']['reservation']) && is_array($decoded['result']['reservation'])) {
                return $decoded['result']['reservation'];
            }
        }

        if (isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded[0];
        }

        return $decoded;
    }

    /**
     * Extract reservation collection payloads.
     *
     * @param mixed $decoded Decoded response.
     *
     * @return array
     */
    private function extract_collection_payload($decoded) {
        if (!is_array($decoded)) {
            return array();
        }

        if (isset($decoded['reservations']) && is_array($decoded['reservations'])) {
            return $decoded['reservations'];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        if (isset($decoded['result']) && is_array($decoded['result'])) {
            if (isset($decoded['result']['reservations']) && is_array($decoded['result']['reservations'])) {
                return $decoded['result']['reservations'];
            }
        }

        if (isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded;
        }

        return array();
    }
    /**
     * Map OTA payload into reservation fields.
     *
     * @param string $platform_key       Platform key.
     * @param array  $payload            Reservation payload.
     * @param string $fallback_reference Optional fallback booking reference.
     * @param array  $existing           Existing reservation data.
     *
     * @return array
     */
    private function map_payload_to_reservation($platform_key, $payload, $fallback_reference = '', $existing = array()) {
        if (!is_array($payload)) {
            $payload = array();
        }

        $reference = $this->normalize_booking_reference($this->read_value($payload, array(
            array('confirmation_code'),
            array('booking_reference'),
            array('bookingReference'),
            array('reservation_id'),
            array('reservationId'),
            array('code'),
            array('id'),
        ), $fallback_reference));

        $guest_first = $this->sanitize_string($this->read_value($payload, array(
            array('guest', 'first_name'),
            array('guest', 'firstName'),
            array('traveler', 'firstName'),
            array('customer', 'first_name'),
        )));
        $guest_last = $this->sanitize_string($this->read_value($payload, array(
            array('guest', 'last_name'),
            array('guest', 'lastName'),
            array('traveler', 'lastName'),
            array('customer', 'last_name'),
        )));
        $guest_full = $this->sanitize_string($this->read_value($payload, array(
            array('guest', 'full_name'),
            array('guest', 'name'),
            array('traveler', 'name'),
            array('customer', 'full_name'),
        )));

        $guest_name = trim($guest_first . ' ' . $guest_last);
        if ($guest_name === '') {
            $guest_name = $guest_full;
        }

        $guest_email = sanitize_email($this->read_value($payload, array(
            array('guest', 'email'),
            array('traveler', 'email'),
            array('customer', 'email'),
            array('contact', 'email'),
        )));

        $guest_phone_raw = $this->read_value($payload, array(
            array('guest', 'phone'),
            array('guest', 'phone_number'),
            array('traveler', 'phone'),
            array('customer', 'phone'),
            array('contact', 'phone'),
        ));

        if (function_exists('gms_sanitize_phone')) {
            $guest_phone = gms_sanitize_phone($guest_phone_raw);
        } else {
            $guest_phone = preg_replace('/[^0-9+\-()\s]/', '', (string) $guest_phone_raw);
        }

        $property_name = $this->sanitize_string($this->read_value($payload, array(
            array('listing', 'name'),
            array('property', 'name'),
            array('unit', 'name'),
            array('hotel', 'name'),
        )));
        $property_id = $this->sanitize_string($this->read_value($payload, array(
            array('listing', 'id'),
            array('property', 'id'),
            array('unit', 'id'),
            array('hotel', 'id'),
            array('unit_id'),
        )));

        $checkin = $this->maybe_normalize_datetime($this->read_value($payload, array(
            array('check_in'),
            array('check_in_date'),
            array('arrival_date'),
            array('arrival'),
            array('stay', 'check_in'),
            array('stay', 'start'),
        )));
        $checkout = $this->maybe_normalize_datetime($this->read_value($payload, array(
            array('check_out'),
            array('check_out_date'),
            array('departure_date'),
            array('departure'),
            array('stay', 'check_out'),
            array('stay', 'end'),
        )));

        $status_raw = $this->sanitize_string($this->read_value($payload, array(
            array('status'),
            array('reservation_status'),
            array('booking_status'),
        )));
        $status = $this->normalize_status($platform_key, $status_raw);

        $door_code = GMS_Database::sanitizeDoorCode($this->read_value($payload, array(
            array('door_code'),
            array('doorCode'),
            array('access', 'code'),
            array('access', 'pin'),
            array('keyless_entry', 'code'),
        )));

        $fields = array(
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_phone' => $guest_phone,
            'property_name' => $property_name,
            'property_id' => $property_id,
            'door_code' => $door_code,
            'checkin_date' => $checkin,
            'checkout_date' => $checkout,
            'status' => $status,
        );

        $snapshot = array(
            'booking_reference' => $reference,
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_phone' => $guest_phone,
            'property_name' => $property_name,
            'property_id' => $property_id,
            'checkin_date' => $checkin,
            'checkout_date' => $checkout,
            'status' => $status,
            'status_raw' => $status_raw,
        );

        if ($door_code !== '') {
            $snapshot['door_code'] = $door_code;
        }

        $mapped = array(
            'booking_reference' => $reference,
            'fields' => $fields,
            'snapshot' => $snapshot,
        );

        /**
         * Filter the mapped OTA reservation data before persistence.
         *
         * @param array  $mapped       Mapped reservation array.
         * @param string $platform_key Platform key.
         * @param array  $payload      Raw OTA payload.
         * @param array  $existing     Existing reservation data.
         */
        return apply_filters('gms_ota_reservation_mapped_data', $mapped, $platform_key, $payload, $existing);
    }

    /**
     * Persist reservation data to the database.
     *
     * @param string $platform_key        Platform key.
     * @param string $platform_label      Platform label.
     * @param array  $mapped              Mapped reservation data.
     * @param array  $existing_reservation Existing reservation data.
     *
     * @return array
     */
    private function upsert_reservation($platform_key, $platform_label, $mapped, $existing_reservation = null) {
        $platform_label = $this->sanitize_string($platform_label);

        $booking_reference = $this->normalize_booking_reference($mapped['booking_reference'] ?? '');
        $fields = isset($mapped['fields']) && is_array($mapped['fields']) ? $mapped['fields'] : array();
        $snapshot = isset($mapped['snapshot']) && is_array($mapped['snapshot']) ? $mapped['snapshot'] : array();

        if ($booking_reference === '') {
            return array(
                'success' => false,
                'action' => 'skipped',
                'errors' => array(__('Reservation is missing a booking reference and was skipped.', 'guest-management-system')),
            );
        }

        if (empty($existing_reservation)) {
            $existing_reservation = GMS_Database::getReservationByPlatformReference($platform_key, $booking_reference);
        }

        $existing_webhook = isset($existing_reservation['webhook_data']) && is_array($existing_reservation['webhook_data'])
            ? $existing_reservation['webhook_data']
            : array();

        if (empty($existing_reservation)) {
            $create_data = array(
                'guest_name' => $fields['guest_name'] ?? '',
                'guest_email' => $fields['guest_email'] ?? '',
                'guest_phone' => $fields['guest_phone'] ?? '',
                'property_name' => $fields['property_name'] ?? '',
                'property_id' => $fields['property_id'] ?? '',
                'door_code' => $fields['door_code'] ?? '',
                'checkin_date' => $fields['checkin_date'] ?? '',
                'checkout_date' => $fields['checkout_date'] ?? '',
                'status' => $fields['status'] ?? 'pending',
                'booking_reference' => $booking_reference,
                'platform' => $platform_key,
                'webhook_data' => $this->merge_webhook_snapshot($existing_webhook, $platform_key, $snapshot, array()),
            );

            $created_id = GMS_Database::createReservation($create_data);

            if (!$created_id) {
                return array(
                    'success' => false,
                    'action' => 'error',
                    'errors' => array(__('Unable to save the OTA reservation.', 'guest-management-system')),
                );
            }

            $updated_fields = array();
            foreach ($fields as $field_key => $field_value) {
                if ($field_value !== '') {
                    $updated_fields[] = $field_key;
                }
            }

            if (!empty($fields['status'])) {
                $updated_fields[] = 'status';
            }

            return array(
                'success' => true,
                'action' => 'created',
                'reservation_id' => $created_id,
                'booking_reference' => $booking_reference,
                'updated_fields' => array_values(array_unique($updated_fields)),
                'message' => sprintf(
                    /* translators: %s: OTA platform label */
                    __('Reservation imported from %s.', 'guest-management-system'),
                    $platform_label
                ),
            );
        }

        $reservation_id = intval($existing_reservation['id']);

        $update_data = array('platform' => $platform_key);
        $updated_fields = array();

        foreach ($fields as $field_key => $field_value) {
            if ($field_key === 'status') {
                if ($field_value === '') {
                    continue;
                }
                $update_data[$field_key] = $field_value;
                if (isset($existing_reservation[$field_key]) && $existing_reservation[$field_key] !== $field_value) {
                    $updated_fields[] = $field_key;
                }
                continue;
            }

            if ($field_value === '' || $field_value === null) {
                continue;
            }

            $update_data[$field_key] = $field_value;

            if (isset($existing_reservation[$field_key]) && $existing_reservation[$field_key] !== $field_value) {
                $updated_fields[] = $field_key;
            }
        }

        $synced_fields = array();
        foreach ($update_data as $key => $value) {
            if ($key === 'webhook_data' || $key === 'platform') {
                continue;
            }
            $synced_fields[] = $key;
        }

        $update_data['webhook_data'] = $this->merge_webhook_snapshot(
            $existing_webhook,
            $platform_key,
            $snapshot,
            array('synced_fields' => $synced_fields)
        );

        $result = GMS_Database::updateReservation($reservation_id, $update_data);

        if ($result === false) {
            return array(
                'success' => false,
                'action' => 'error',
                'errors' => array(__('Unable to update the reservation with OTA details.', 'guest-management-system')),
            );
        }

        $updated_count = count(array_unique($updated_fields));
        $message = $updated_count > 0
            ? sprintf(
                _n('Updated %1$d field from %2$s.', 'Updated %1$d fields from %2$s.', $updated_count, 'guest-management-system'),
                $updated_count,
                $platform_label
            )
            : sprintf(
                __('%s reservation is already up to date.', 'guest-management-system'),
                $platform_label
            );

        return array(
            'success' => true,
            'action' => $updated_count > 0 ? 'updated' : 'synced',
            'reservation_id' => $reservation_id,
            'booking_reference' => $booking_reference,
            'updated_fields' => array_values(array_unique($updated_fields)),
            'message' => $message,
        );
    }

    /**
     * Merge OTA snapshot data into the webhook payload store.
     *
     * @param array $existing_data Existing webhook data.
     * @param string $platform_key Platform key.
     * @param array $snapshot      Snapshot data.
     * @param array $additional    Additional metadata.
     *
     * @return array
     */
    private function merge_webhook_snapshot($existing_data, $platform_key, $snapshot, $additional = array()) {
        if (!is_array($existing_data)) {
            $existing_data = array();
        }

        if (!isset($existing_data['ota_sync']) || !is_array($existing_data['ota_sync'])) {
            $existing_data['ota_sync'] = array();
        }

        if (isset($additional['synced_fields'])) {
            $additional['synced_fields'] = array_values(array_filter(array_map('sanitize_key', (array) $additional['synced_fields'])));
        }

        $entry = array(
            'last_synced' => current_time('mysql'),
            'snapshot' => $this->sanitize_snapshot($snapshot),
        );

        if (!empty($additional)) {
            $entry = array_merge($entry, $additional);
        }

        $existing_data['ota_sync'][$platform_key] = $entry;

        return $existing_data;
    }

    /**
     * Sanitize nested snapshot data before storage.
     *
     * @param array $snapshot Snapshot data.
     *
     * @return array
     */
    private function sanitize_snapshot($snapshot) {
        if (!is_array($snapshot)) {
            return array();
        }

        $sanitized = array();

        foreach ($snapshot as $key => $value) {
            $clean_key = sanitize_key($key);
            if (is_array($value)) {
                $sanitized[$clean_key] = $this->sanitize_snapshot($value);
                continue;
            }

            if (is_scalar($value)) {
                $sanitized[$clean_key] = wp_strip_all_tags((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Normalize a booking reference value.
     *
     * @param string $reference Booking reference.
     *
     * @return string
     */
    private function normalize_booking_reference($reference) {
        $reference = trim((string) $reference);
        return sanitize_text_field($reference);
    }

    /**
     * Sanitize a string value.
     *
     * @param mixed $value Value to sanitize.
     *
     * @return string
     */
    private function sanitize_string($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim(wp_strip_all_tags((string) $value));
    }

    /**
     * Normalize date values for storage.
     *
     * @param string $value Raw date value.
     *
     * @return string
     */
    private function maybe_normalize_datetime($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        if (function_exists('wp_date') && function_exists('wp_timezone')) {
            return wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Read a value from nested payload paths.
     *
     * @param array $payload Payload array.
     * @param array $paths   Paths to inspect.
     * @param mixed $default Default value when not found.
     *
     * @return mixed
     */
    private function read_value($payload, $paths, $default = '') {
        foreach ($paths as $path) {
            $value = $payload;
            $found = true;

            foreach ((array) $path as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $found = false;
                    break;
                }
            }

            if ($found) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Normalize OTA-specific status values to internal states.
     *
     * @param string $platform_key Platform key.
     * @param string $status       Raw status.
     *
     * @return string
     */
    private function normalize_status($platform_key, $status) {
        $status = strtolower(trim((string) $status));

        if ($status === '') {
            return 'pending';
        }

        $maps = array(
            'airbnb' => array(
                'accepted' => 'confirmed',
                'confirmed' => 'confirmed',
                'canceled' => 'cancelled',
                'cancelled' => 'cancelled',
                'declined' => 'cancelled',
                'pending' => 'pending',
            ),
            'vrbo' => array(
                'booked' => 'confirmed',
                'confirmed' => 'confirmed',
                'cancelled' => 'cancelled',
                'canceled' => 'cancelled',
                'pending' => 'pending',
            ),
            'booking_com' => array(
                'confirmed' => 'confirmed',
                'ok' => 'confirmed',
                'cancelled' => 'cancelled',
                'canceled' => 'cancelled',
                'pending' => 'pending',
            ),
        );

        if (isset($maps[$platform_key][$status])) {
            $normalized = $maps[$platform_key][$status];
        } else {
            $normalized = $status;
        }

        if ($normalized === 'canceled') {
            $normalized = 'cancelled';
        }

        if (!in_array($normalized, array('pending', 'confirmed', 'cancelled', 'completed', 'approved'), true)) {
            $normalized = 'pending';
        }

        /**
         * Filter the normalized OTA status.
         *
         * @param string $normalized   Normalized status.
         * @param string $status       Raw status value.
         * @param string $platform_key Platform key.
         */
        return apply_filters('gms_ota_reservation_status', $normalized, $status, $platform_key);
    }
}
