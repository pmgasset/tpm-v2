<?php
/**
 * Handles communication with the housekeeping calendar integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Housekeeping_Integration {
    const OPTION_ENDPOINT = 'gms_housekeeping_calendar_endpoint';
    const OPTION_TOKEN    = 'gms_housekeeping_calendar_token';

    /**
     * Notify the housekeeping calendar of the cleaning window for a reservation.
     *
     * @param array                 $reservation  Reservation data.
     * @param \DateTimeInterface    $window_start Cleaning window start time.
     * @param \DateTimeInterface    $window_end   Cleaning window end time.
     *
     * @return void
     */
    public static function sendCleaningWindowEvent(array $reservation, \DateTimeInterface $window_start, \DateTimeInterface $window_end)
    {
        $endpoint = apply_filters('gms_housekeeping_calendar_endpoint', get_option(self::OPTION_ENDPOINT, ''));
        if (!is_string($endpoint)) {
            return;
        }

        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }

        $headers = array('Content-Type' => 'application/json');
        $token   = apply_filters('gms_housekeeping_calendar_token', get_option(self::OPTION_TOKEN, ''));
        if (is_string($token)) {
            $token = trim($token);
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        }

        $payload = array(
            'reservationId' => isset($reservation['id']) ? (int) $reservation['id'] : 0,
            'propertyName'  => isset($reservation['property_name']) ? (string) $reservation['property_name'] : '',
            'guestName'     => isset($reservation['guest_name']) ? (string) $reservation['guest_name'] : '',
            'windowStart'   => $window_start->format(DATE_ATOM),
            'windowEnd'     => $window_end->format(DATE_ATOM),
        );

        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            error_log('GMS housekeeping event failed: unable to encode payload for reservation ' . $payload['reservationId']);
            return;
        }

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            error_log(sprintf(
                'GMS housekeeping event failed for reservation %d: %s',
                $payload['reservationId'],
                $response->get_error_message()
            ));
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            error_log(sprintf(
                'GMS housekeeping event failed for reservation %d (HTTP %d): %s',
                $payload['reservationId'],
                $code,
                is_scalar($response_body) ? (string) $response_body : '[non-scalar response]'
            ));
        }
    }
}
