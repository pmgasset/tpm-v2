<?php
/**
 * File: class-roku-integration.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-roku-integration.php
 *
 * Roku integration for the Jordan View television experience.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Roku_Integration {
    private const API_NAMESPACE = 'gms/v1';
    private const APP_TITLE = 'Jordan View';
    private const MEDIA_LIMIT = 24;

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_init', array($this, 'maybe_seed_roku_token'));
    }

    /**
     * Ensure a Roku API token exists for authenticated requests.
     */
    public function maybe_seed_roku_token() {
        if (defined('GMS_ROKU_API_TOKEN')) {
            return;
        }

        $token = get_option('gms_roku_api_token', '');
        if ($token === '') {
            $token = wp_generate_password(32, false, false);
            update_option('gms_roku_api_token', $token);
        }
    }

    /**
     * Register Roku-specific REST endpoints.
     */
    public function register_rest_routes() {
        register_rest_route(
            self::API_NAMESPACE,
            '/roku/dashboard',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'handle_dashboard_request'),
                'permission_callback' => array($this, 'verify_request_permission'),
                'args'                => array(
                    'property_id'   => array(
                        'required' => false,
                        'type'     => 'string',
                    ),
                    'property_name' => array(
                        'required' => false,
                        'type'     => 'string',
                    ),
                    'media_tag'     => array(
                        'required' => false,
                        'type'     => 'string',
                    ),
                ),
            )
        );

        register_rest_route(
            self::API_NAMESPACE,
            '/roku/reservations/(?P<reservation_id>\d+)/checkout',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'handle_checkout_request'),
                'permission_callback' => array($this, 'verify_request_permission'),
                'args'                => array(
                    'reservation_id' => array(
                        'required' => true,
                        'type'     => 'integer',
                    ),
                ),
            )
        );
    }

    /**
     * Validate Roku token prior to executing handlers.
     *
     * @param WP_REST_Request $request Current request.
     *
     * @return bool|WP_Error
     */
    public function verify_request_permission($request) {
        $configured = $this->get_configured_token();
        if ($configured === '') {
            return new WP_Error(
                'gms_roku_token_unavailable',
                __('Roku API token is not configured. Please set gms_roku_api_token in WordPress.', 'guest-management-system'),
                array('status' => 500)
            );
        }

        $provided = $this->extract_token_from_request($request);

        if ($provided === '') {
            return new WP_Error(
                'gms_roku_missing_token',
                __('Missing Roku authentication token.', 'guest-management-system'),
                array('status' => 401)
            );
        }

        if (!hash_equals($configured, $provided)) {
            return new WP_Error(
                'gms_roku_invalid_token',
                __('Invalid Roku authentication token.', 'guest-management-system'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Provide dashboard payload for the Roku experience.
     */
    public function handle_dashboard_request($request) {
        $filters = array(
            'property_id'   => sanitize_text_field((string) $request->get_param('property_id')),
            'property_name' => sanitize_text_field((string) $request->get_param('property_name')),
        );

        $current = GMS_Database::getActiveReservationForProperty($filters);
        $upcoming = GMS_Database::getUpcomingReservationForProperty($filters);

        if ($current && $upcoming && intval($current['id']) === intval($upcoming['id'])) {
            $upcoming = null;
        }

        $media_tag = sanitize_text_field((string) $request->get_param('media_tag'));

        $payload = array(
            'success'             => true,
            'title'               => self::APP_TITLE,
            'branding'            => $this->build_branding_block(),
            'property'            => $this->build_property_block($filters, $current, $upcoming),
            'currentReservation'  => $current ? $this->transform_reservation($current, true) : null,
            'upcomingReservation' => $upcoming ? $this->transform_reservation($upcoming, false) : null,
            'media'               => $this->build_media_collection($media_tag, $current, $upcoming),
            'meta'                => $this->build_meta_block($filters),
        );

        if ($payload['currentReservation']) {
            $payload['currentReservation']['actions']['checkoutEndpoint'] = $this->build_checkout_endpoint($payload['currentReservation']['id']);
        }

        return new WP_REST_Response($payload, 200);
    }

    /**
     * Handle checkout action from Roku.
     */
    public function handle_checkout_request($request) {
        $reservation_id = intval($request->get_param('reservation_id'));

        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Reservation not found.', 'guest-management-system'),
                ),
                404
            );
        }

        if (in_array($reservation['status'], array('cancelled'), true)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Cannot check out a cancelled reservation.', 'guest-management-system'),
                ),
                409
            );
        }

        $now_gmt = current_time('mysql', true);
        $update = array('status' => 'completed');

        $existing_checkout = isset($reservation['checkout_date']) ? $reservation['checkout_date'] : '';
        if ($existing_checkout === '' || $existing_checkout === '0000-00-00 00:00:00') {
            $update['checkout_date'] = get_date_from_gmt($now_gmt);
        }

        $updated = GMS_Database::updateReservation($reservation_id, $update);

        if (!$updated) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __('Unable to update reservation status. Please retry.', 'guest-management-system'),
                ),
                500
            );
        }

        $fresh = GMS_Database::getReservationById($reservation_id);
        $response = $this->transform_reservation($fresh ?: $reservation, true);
        $response['actions']['checkoutEndpoint'] = $this->build_checkout_endpoint($reservation_id);

        do_action('gms_roku_reservation_checked_out', $reservation_id, $fresh);

        return new WP_REST_Response(
            array(
                'success'     => true,
                'message'     => __('Guest successfully checked out.', 'guest-management-system'),
                'reservation' => $response,
            ),
            200
        );
    }

    private function get_configured_token() {
        if (defined('GMS_ROKU_API_TOKEN') && GMS_ROKU_API_TOKEN !== '') {
            return trim((string) GMS_ROKU_API_TOKEN);
        }

        $stored = get_option('gms_roku_api_token', '');
        return trim((string) $stored);
    }

    private function extract_token_from_request($request) {
        $header_keys = array(
            'x-roku-token',
            'X-Roku-Token',
            'x-webhook-token',
            'X-Webhook-Token',
            'authorization',
            'Authorization',
        );

        foreach ($header_keys as $header) {
            $value = (string) $request->get_header($header);
            if ($value !== '') {
                if (stripos($header, 'authorization') === 0 && stripos($value, 'bearer ') === 0) {
                    return trim(substr($value, 7));
                }
                return trim($value);
            }
        }

        $param = (string) $request->get_param('token');
        if ($param !== '') {
            return trim($param);
        }

        return '';
    }

    private function build_branding_block() {
        $company_name = get_option('gms_company_name', get_bloginfo('name'));
        $logo_url = get_option('gms_company_logo', '');
        $primary = get_option('gms_portal_primary_color', '#0073aa');
        $secondary = get_option('gms_portal_secondary_color', '#005a87');

        return array(
            'title'         => self::APP_TITLE,
            'companyName'   => $company_name,
            'logoUrl'       => $logo_url,
            'primaryColor'  => $primary ?: '#0073aa',
            'secondaryColor'=> $secondary ?: '#005a87',
        );
    }

    private function build_property_block($filters, $current, $upcoming) {
        $property_id = $filters['property_id'];
        $property_name = $filters['property_name'];

        if ($current) {
            $property_id = $current['property_id'] ?? $property_id;
            $property_name = $current['property_name'] ?? $property_name;
        } elseif ($upcoming) {
            $property_id = $upcoming['property_id'] ?? $property_id;
            $property_name = $upcoming['property_name'] ?? $property_name;
        }

        return array(
            'id'   => $property_id,
            'name' => $property_name,
        );
    }

    private function build_meta_block($filters) {
        $timezone = wp_timezone_string();
        $timestamp = time();

        return array(
            'generatedAt'     => wp_date('c', $timestamp),
            'generatedAtUnix' => $timestamp,
            'timezone'        => $timezone,
            'pluginVersion'   => defined('GMS_VERSION') ? GMS_VERSION : '1.0.0',
            'siteUrl'         => home_url('/'),
            'request'         => array(
                'property_id'   => $filters['property_id'],
                'property_name' => $filters['property_name'],
            ),
        );
    }

    private function transform_reservation($reservation, $include_actions = false) {
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        $checkin = $this->format_datetime($reservation['checkin_date'] ?? '', $date_format, $time_format);
        $checkout = $this->format_datetime($reservation['checkout_date'] ?? '', $date_format, $time_format);

        $nights = $this->calculate_nights($reservation['checkin_date'] ?? '', $reservation['checkout_date'] ?? '');

        $status = sanitize_key($reservation['status'] ?? '');
        $status_label = $this->get_status_label($status);

        $portal_url = function_exists('gms_get_portal_url') ? gms_get_portal_url(intval($reservation['id'])) : '';

        $payload = array(
            'id'              => intval($reservation['id']),
            'guestName'       => $reservation['guest_name'] ?? '',
            'guestEmail'      => $reservation['guest_email'] ?? '',
            'guestPhone'      => $reservation['guest_phone'] ?? '',
            'propertyId'      => $reservation['property_id'] ?? '',
            'propertyName'    => $reservation['property_name'] ?? '',
            'bookingReference'=> $reservation['booking_reference'] ?? '',
            'doorCode'        => $reservation['door_code'] ?? '',
            'status'          => $status,
            'statusLabel'     => $status_label,
            'checkin'         => $checkin,
            'checkout'        => $checkout,
            'nights'          => $nights,
            'staySummary'     => $this->build_stay_summary($checkin, $checkout, $nights),
            'portalUrl'       => $portal_url,
            'actions'         => array(
                'canCheckout' => false,
            ),
        );

        if ($include_actions) {
            $payload['actions']['canCheckout'] = $this->can_checkout($reservation);
        }

        return $payload;
    }

    private function build_stay_summary($checkin, $checkout, $nights) {
        $parts = array();

        if (!empty($checkin['dateLabel']) && !empty($checkout['dateLabel'])) {
            $parts[] = sprintf(
                /* translators: 1: check-in date, 2: check-out date */
                __('%1$s – %2$s', 'guest-management-system'),
                $checkin['dateLabel'],
                $checkout['dateLabel']
            );
        }

        if ($nights > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of nights */
                _n('%d night', '%d nights', $nights, 'guest-management-system'),
                $nights
            );
        }

        return implode(' ', $parts);
    }

    private function format_datetime($value, $date_format, $time_format) {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return array(
                'iso'       => '',
                'dateLabel' => '',
                'timeLabel' => '',
            );
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return array(
                'iso'       => '',
                'dateLabel' => '',
                'timeLabel' => '',
            );
        }

        return array(
            'iso'       => wp_date('c', $timestamp),
            'dateLabel' => wp_date($date_format, $timestamp),
            'timeLabel' => wp_date($time_format, $timestamp),
        );
    }

    private function calculate_nights($checkin, $checkout) {
        if (empty($checkin) || empty($checkout) || $checkin === '0000-00-00 00:00:00' || $checkout === '0000-00-00 00:00:00') {
            return 0;
        }

        $checkin_ts = strtotime($checkin);
        $checkout_ts = strtotime($checkout);

        if (!$checkin_ts || !$checkout_ts || $checkout_ts <= $checkin_ts) {
            return 0;
        }

        $diff = $checkout_ts - $checkin_ts;
        return (int) floor($diff / DAY_IN_SECONDS);
    }

    private function get_status_label($status) {
        if (!function_exists('gms_get_reservation_status_options')) {
            return ucfirst($status);
        }

        $statuses = gms_get_reservation_status_options();
        if (isset($statuses[$status])) {
            return $statuses[$status];
        }

        return ucfirst($status);
    }

    private function can_checkout($reservation) {
        $status = sanitize_key($reservation['status'] ?? '');
        if (in_array($status, array('cancelled', 'completed'), true)) {
            return false;
        }

        $checkin = $reservation['checkin_date'] ?? '';
        $now = current_time('timestamp');

        if (empty($checkin) || $checkin === '0000-00-00 00:00:00') {
            return true;
        }

        $checkin_ts = strtotime($checkin);
        if ($checkin_ts === false) {
            return true;
        }

        return $now >= $checkin_ts;
    }

    private function build_media_collection($media_tag, $current, $upcoming) {
        $tags = array();
        $prefix = sanitize_title(get_option('gms_roku_media_tag_prefix', 'roku'));

        if ($prefix !== '') {
            $tags[] = $prefix;
        }

        if ($media_tag !== '') {
            $tags[] = sanitize_title($media_tag);
        }

        $property_identifiers = array();
        foreach (array($current, $upcoming) as $reservation) {
            if (!$reservation) {
                continue;
            }

            if (!empty($reservation['property_id'])) {
                $property_identifiers[] = sanitize_title($reservation['property_id']);
            }

            if (!empty($reservation['property_name'])) {
                $property_identifiers[] = sanitize_title($reservation['property_name']);
            }
        }

        foreach ($property_identifiers as $identifier) {
            $tags[] = $identifier;
            if ($prefix !== '' && strpos($identifier, $prefix) !== 0) {
                $tags[] = sanitize_title($prefix . '-' . $identifier);
            }
        }

        $tags = array_values(array_unique(array_filter($tags)));

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => self::MEDIA_LIMIT,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        );

        if (!empty($tags)) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'post_tag',
                    'field'    => 'slug',
                    'terms'    => $tags,
                ),
            );
        }

        $attachments = array();
        if (!empty($tags)) {
            $attachments = get_posts($query_args);
        }
        $media = array();

        foreach ($attachments as $attachment) {
            $id = $attachment->ID;
            $url = wp_get_attachment_url($id);
            if (!$url) {
                continue;
            }

            $type = strpos($attachment->post_mime_type, 'video/') === 0 ? 'video' : 'image';
            $thumbnail = '';

            if ($type === 'image') {
                $thumb = wp_get_attachment_image_src($id, 'large');
                if ($thumb) {
                    $thumbnail = $thumb[0];
                }
            }

            $media[] = array(
                'id'          => $id,
                'type'        => $type,
                'url'         => $url,
                'thumbnail'   => $thumbnail ?: $url,
                'title'       => get_the_title($id),
                'description' => wp_strip_all_tags(get_post_field('post_content', $id)),
                'caption'     => wp_get_attachment_caption($id),
                'altText'     => get_post_meta($id, '_wp_attachment_image_alt', true),
            );
        }

        if (empty($media)) {
            $logo_url = get_option('gms_company_logo', '');
            if ($logo_url !== '') {
                $media[] = array(
                    'id'          => 0,
                    'type'        => 'image',
                    'url'         => $logo_url,
                    'thumbnail'   => $logo_url,
                    'title'       => self::APP_TITLE,
                    'description' => '',
                    'caption'     => '',
                    'altText'     => self::APP_TITLE,
                );
            }
        }

        /**
         * Filter the curated Roku media collection before it is returned.
         *
         * @since 1.1.0
         *
         * @param array $media      Curated media items.
         * @param array $tags       Tag slugs used for the lookup.
         * @param array $current    Current reservation data or null.
         * @param array $upcoming   Upcoming reservation data or null.
         */
        return apply_filters('gms_roku_media_items', $media, $tags, $current, $upcoming);
    }

    private function build_checkout_endpoint($reservation_id) {
        return rest_url(
            trailingslashit(self::API_NAMESPACE . '/roku/reservations/' . intval($reservation_id)) . 'checkout'
        );
    }
}
