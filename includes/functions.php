<?php
/**
 * File: functions.php
 * Location: /wp-content/plugins/guest-management-system/includes/functions.php
 * 
 * Helper Functions for Guest Management System
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get reservation by token
 */
function gms_get_reservation_by_token($token) {
    return GMS_Database::getReservationByToken($token);
}

/**
 * Get reservation by ID
 */
function gms_get_reservation($reservation_id) {
    return GMS_Database::getReservationById($reservation_id);
}

function gms_get_reservation_status_options() {
    return array(
        'pending' => __('Pending Approval', 'guest-management-system'),
        'approved' => __('Approved', 'guest-management-system'),
        'awaiting_signature' => __('Awaiting Signature', 'guest-management-system'),
        'awaiting_id_verification' => __('Awaiting ID Verification', 'guest-management-system'),
        'confirmed' => __('Confirmed', 'guest-management-system'),
        'completed' => __('Completed', 'guest-management-system'),
        'cancelled' => __('Cancelled', 'guest-management-system'),
    );
}

function gms_get_followup_reservation_statuses() {
    return array('approved', 'awaiting_signature', 'awaiting_id_verification');
}

/**
 * Check if reservation is complete
 */
function gms_is_reservation_complete($reservation_id) {
    $agreement = GMS_Database::getAgreementByReservation($reservation_id);
    $verification = GMS_Database::getVerificationByReservation($reservation_id);
    
    return $agreement && 
           $verification && 
           $agreement['status'] === 'signed' && 
           $verification['verification_status'] === 'verified';
}

/**
 * Build the guest portal URL for a token.
 *
 * @param string $token Portal token.
 *
 * @return string|false
 */
function gms_build_portal_url($token) {
    if (!is_scalar($token)) {
        return false;
    }

    $token = trim((string) $token);

    if ($token === '') {
        return false;
    }

    global $wp_rewrite;

    $encoded_token = rawurlencode($token);

    if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_permalinks') && $wp_rewrite->using_permalinks()) {
        $path = 'guest-portal/' . $encoded_token;

        return home_url(user_trailingslashit($path));
    }

    return add_query_arg(
        array(
            'guest_portal' => 1,
            'guest_token'  => $encoded_token,
        ),
        home_url('/')
    );
}

/**
 * Get portal URL for reservation
 */
function gms_get_portal_url($reservation_id) {
    $reservation = GMS_Database::getReservationById($reservation_id);

    if (!$reservation || empty($reservation['portal_token'])) {
        return false;
    }

    return gms_build_portal_url($reservation['portal_token']);
}

function gms_build_guest_profile_url($token) {
    if (!is_scalar($token)) {
        return false;
    }

    $token = trim((string) $token);

    if ($token === '') {
        return false;
    }

    global $wp_rewrite;

    $encoded_token = rawurlencode($token);

    if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_permalinks') && $wp_rewrite->using_permalinks()) {
        $path = 'guest-profile/' . $encoded_token;

        return home_url(user_trailingslashit($path));
    }

    return add_query_arg(
        array(
            'guest_profile' => 1,
            'guest_profile_token' => $encoded_token,
        ),
        home_url('/')
    );
}

function gms_get_guest_profile_url($reservation_id) {
    $access = GMS_Database::getGuestProfileLinkForReservation($reservation_id);

    if (empty($access['url'])) {
        return false;
    }

    return $access['url'];
}

function gms_build_housekeeper_url($token) {
    if (!is_scalar($token)) {
        return false;
    }

    $token = trim((string) $token);

    if ($token === '') {
        return false;
    }

    global $wp_rewrite;

    $encoded_token = rawurlencode($token);

    if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_permalinks') && $wp_rewrite->using_permalinks()) {
        $path = 'housekeeper/' . $encoded_token;

        return home_url(user_trailingslashit($path));
    }

    return add_query_arg(
        array(
            'gms_housekeeper' => 1,
            'gms_housekeeper_token' => $encoded_token,
        ),
        home_url('/')
    );
}

function gms_get_housekeeper_url($reservation_id) {
    $access = GMS_Database::getHousekeeperLinkForReservation($reservation_id);

    if (empty($access['url'])) {
        return false;
    }

    return $access['url'];
}

function gms_build_staff_overview_url($token) {
    if (!is_scalar($token)) {
        return false;
    }

    $token = trim((string) $token);

    if ($token === '') {
        return false;
    }

    global $wp_rewrite;

    $encoded_token = rawurlencode($token);

    if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_permalinks') && $wp_rewrite->using_permalinks()) {
        $path = 'staff-overview/' . $encoded_token;

        return home_url(user_trailingslashit($path));
    }

    return add_query_arg(
        array(
            'gms_staff_overview' => 1,
            'gms_staff_token' => $encoded_token,
        ),
        home_url('/')
    );
}

function gms_get_staff_overview_url() {
    $token = GMS_Database::getStaffOverviewAccessToken();

    if ($token === '') {
        return false;
    }

    return gms_build_staff_overview_url($token);
}

function gms_get_housekeeper_contacts($reservation) {
    $reservation = is_array($reservation) ? $reservation : array();

    $emails = array();
    $phones = array();

    $email_keys = array('housekeeper_email', 'housekeeping_email', 'cleaner_email', 'cleaning_email');
    $phone_keys = array('housekeeper_phone', 'housekeeping_phone', 'cleaner_phone', 'cleaning_phone');

    foreach ($email_keys as $key) {
        if (empty($reservation[$key])) {
            continue;
        }
        $emails = array_merge($emails, (array) $reservation[$key]);
    }

    foreach ($phone_keys as $key) {
        if (empty($reservation[$key])) {
            continue;
        }
        $phones = array_merge($phones, (array) $reservation[$key]);
    }

    if (!empty($reservation['webhook_data']) && is_array($reservation['webhook_data'])) {
        $webhook = $reservation['webhook_data'];

        foreach ($email_keys as $key) {
            if (empty($webhook[$key])) {
                continue;
            }
            $emails = array_merge($emails, (array) $webhook[$key]);
        }

        foreach ($phone_keys as $key) {
            if (empty($webhook[$key])) {
                continue;
            }
            $phones = array_merge($phones, (array) $webhook[$key]);
        }

        $composite_keys = array('housekeeper', 'housekeeping', 'cleaner', 'cleaning_team');

        foreach ($composite_keys as $composite_key) {
            if (empty($webhook[$composite_key])) {
                continue;
            }

            $record = $webhook[$composite_key];

            if (is_array($record)) {
                if (!empty($record['email'])) {
                    $emails = array_merge($emails, (array) $record['email']);
                }
                if (!empty($record['emails'])) {
                    $emails = array_merge($emails, (array) $record['emails']);
                }
                if (!empty($record['phone'])) {
                    $phones = array_merge($phones, (array) $record['phone']);
                }
                if (!empty($record['phones'])) {
                    $phones = array_merge($phones, (array) $record['phones']);
                }
            } elseif (is_string($record)) {
                $segments = preg_split('/[;,]/', $record);
                if ($segments && is_array($segments)) {
                    foreach ($segments as $segment) {
                        $segment = trim($segment);
                        if ($segment === '') {
                            continue;
                        }
                        if (is_email($segment)) {
                            $emails[] = $segment;
                        } elseif (!preg_match('/[^0-9+\s\-().]/', $segment)) {
                            $phones[] = $segment;
                        }
                    }
                }
            }
        }
    }

    $emails = array_filter(array_unique(array_map('sanitize_email', $emails))); 
    $emails = array_values(array_filter($emails, 'is_email'));

    $phones = array_map(function($value) {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('gms_sanitize_phone')) {
            return gms_sanitize_phone($value);
        }

        return preg_replace('/[^0-9+]/', '', $value);
    }, $phones);

    $phones = array_values(array_filter(array_unique($phones)));

    $contacts = array(
        'emails' => $emails,
        'phones' => $phones,
    );

    $filtered = apply_filters('gms_housekeeper_contacts', $contacts, $reservation);
    if (!is_array($filtered)) {
        $filtered = $contacts;
    }

    $merged_emails = array_merge($emails, isset($filtered['emails']) ? (array) $filtered['emails'] : array());
    $merged_phones = array_merge($phones, isset($filtered['phones']) ? (array) $filtered['phones'] : array());

    $merged_emails = array_filter(array_unique(array_map('sanitize_email', $merged_emails)));
    $merged_emails = array_values(array_filter($merged_emails, 'is_email'));

    $merged_phones = array_map(function($value) {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('gms_sanitize_phone')) {
            return gms_sanitize_phone($value);
        }

        return preg_replace('/[^0-9+]/', '', $value);
    }, $merged_phones);

    $merged_phones = array_values(array_filter(array_unique($merged_phones)));

    return array(
        'emails' => $merged_emails,
        'phones' => $merged_phones,
    );
}

function gms_shorten_url($url) {
    $url = is_string($url) ? trim($url) : '';

    if ($url === '') {
        return '';
    }

    $api_token = trim(get_option('gms_shortener_api_token'));
    if ($api_token === '') {
        return $url;
    }

    $base_url = trim(apply_filters('gms_shortener_base_url', 'https://240jv.link'));
    $base_url = esc_url_raw($base_url);
    if ($base_url === '') {
        $base_url = 'https://240jv.link';
    }

    $endpoint = rtrim($base_url, '/') . '/shorten';

    $response = wp_remote_post($endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode(array('url' => $url)),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return $url;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ((int) $status_code !== 200) {
        return $url;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return $url;
    }

    if (isset($data['short_url']) && is_string($data['short_url'])) {
        $short_url = esc_url_raw($data['short_url']);
        if ($short_url !== '') {
            return $short_url;
        }
    }

    return $url;
}

/**
 * Send guest notifications
 */
function gms_send_guest_notifications($reservation_id) {
    $reservation = GMS_Database::getReservationById($reservation_id);
    
    if (!$reservation) {
        return false;
    }
    
    $status = isset($reservation['status']) ? sanitize_key($reservation['status']) : '';
    $approved_statuses = gms_get_followup_reservation_statuses();
    $should_use_approved = in_array($status, $approved_statuses, true);

    // Send email
    $email_handler = new GMS_Email_Handler();
    if ($should_use_approved) {
        $email_result = $email_handler->sendReservationApprovedEmail($reservation);
    } else {
        $email_result = $email_handler->sendWelcomeEmail($reservation);
    }

    // Send SMS if phone available
    $sms_result = false;
    if (!empty($reservation['guest_phone'])) {
        $sms_handler = new GMS_SMS_Handler();
        if ($should_use_approved) {
            $sms_result = $sms_handler->sendReservationApprovedSMS($reservation);
        } else {
            $sms_result = $sms_handler->sendWelcomeSMS($reservation);
        }
    }
    
    return array(
        'email' => $email_result,
        'sms' => $sms_result
    );
}

/**
 * Update reservation status with action hook
 */
function gms_update_reservation_status($reservation_id, $status) {
    $status = sanitize_key($status);

    return GMS_Database::updateReservationStatus($reservation_id, $status);
}

/**
 * Get guest user by reservation
 */
function gms_get_guest_user($reservation_id) {
    $reservation = GMS_Database::getReservationById($reservation_id);

    if (!$reservation) {
        return false;
    }

    if (!empty($reservation['guest_id'])) {
        $user = get_user_by('id', intval($reservation['guest_id']));
        if ($user) {
            return $user;
        }
    }

    if (!empty($reservation['guest_record_id'])) {
        $guest = GMS_Database::get_guest_by_id(intval($reservation['guest_record_id']));
        if ($guest && !empty($guest['wp_user_id'])) {
            $user = get_user_by('id', intval($guest['wp_user_id']));
            if ($user) {
                return $user;
            }
        }
    }

    return false;
}

/**
 * Check if user is a guest
 */
function gms_is_guest($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    if (!$user_id) {
        return false;
    }
    
    $user = get_user_by('id', $user_id);
    
    return $user && in_array('guest', $user->roles);
}

/**
 * Get all reservations for a guest
 */
function gms_get_guest_reservations($guest_id) {
    global $wpdb;

    $table = $wpdb->prefix . 'gms_reservations';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE guest_id = %d ORDER BY checkin_date DESC",
        $guest_id
    ), ARRAY_A);
}

add_action('profile_update', 'gms_sync_guest_profile_from_user', 10, 1);
add_action('user_register', 'gms_sync_guest_profile_from_user', 10, 1);
add_action('added_user_meta', 'gms_maybe_sync_guest_phone_meta', 10, 4);
add_action('updated_user_meta', 'gms_maybe_sync_guest_phone_meta', 10, 4);
add_filter('pre_get_avatar_data', 'gms_use_stripe_selfie_avatar', 10, 2);

function gms_sync_guest_profile_from_user($user_id) {
    $user_id = intval($user_id);

    if ($user_id <= 0 || !class_exists('GMS_Database')) {
        return;
    }

    GMS_Database::syncUserToGuest($user_id);
}

function gms_maybe_sync_guest_phone_meta($meta_id, $user_id, $meta_key, $meta_value) {
    if ($meta_key !== 'gms_guest_phone' || !class_exists('GMS_Database')) {
        return;
    }

    gms_sync_guest_profile_from_user($user_id);
}

function gms_use_stripe_selfie_avatar($args, $id_or_email) {
    $user = null;

    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', intval($id_or_email));
    } elseif (is_object($id_or_email) && isset($id_or_email->user_id)) {
        $user = get_user_by('id', intval($id_or_email->user_id));
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
    }

    if (!$user) {
        return $args;
    }

    $attachment_id = (int) get_user_meta($user->ID, 'profile_photo_id', true);
    $avatar_url = '';

    if ($attachment_id > 0) {
        $size = isset($args['size']) ? intval($args['size']) : 96;
        $avatar_url = wp_get_attachment_image_url($attachment_id, array($size, $size));

        $srcset = wp_get_attachment_image_srcset($attachment_id, array($size, $size));
        if ($srcset) {
            $args['srcset'] = $srcset;
        }

        $sizes = wp_get_attachment_image_sizes($attachment_id, array($size, $size));
        if ($sizes) {
            $args['sizes'] = $sizes;
        }
    }

    if (!$avatar_url) {
        $avatar_url = esc_url_raw(get_user_meta($user->ID, 'profile_photo_url', true));
    }

    if (!$avatar_url) {
        return $args;
    }

    $args['url'] = $avatar_url;
    $args['found_avatar'] = true;

    return $args;
}

/**
 * Get upcoming check-ins
 */
function gms_get_upcoming_checkins($days = 7) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'gms_reservations';
    $start_date = current_time('mysql');
    $end_date = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE checkin_date BETWEEN %s AND %s 
         ORDER BY checkin_date ASC",
        $start_date,
        $end_date
    ), ARRAY_A);
}

/**
 * Get pending check-ins (not completed)
 */
function gms_get_pending_checkins() {
    global $wpdb;

    $table = $wpdb->prefix . 'gms_reservations';

    $statuses = array_merge(array('pending'), gms_get_followup_reservation_statuses());
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table
             WHERE status IN ($placeholders)
             AND checkin_date >= NOW()
             ORDER BY checkin_date ASC",
            $statuses
        ),
        ARRAY_A
    );
}

/**
 * Format date for display
 */
function gms_format_date($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function gms_format_datetime($datetime, $format = 'F j, Y \a\t g:i A') {
    return date($format, strtotime($datetime));
}

/**
 * Get reservation completion percentage
 */
function gms_get_completion_percentage($reservation_id) {
    $agreement = GMS_Database::getAgreementByReservation($reservation_id);
    $verification = GMS_Database::getVerificationByReservation($reservation_id);
    
    $completed = 0;
    $total = 2;
    
    if ($agreement && $agreement['status'] === 'signed') {
        $completed++;
    }
    
    if ($verification && $verification['verification_status'] === 'verified') {
        $completed++;
    }
    
    return ($completed / $total) * 100;
}

/**
 * Get reservation status badge HTML
 */
function gms_get_status_badge($status) {
    $badges = array(
        'pending' => '<span class="gms-badge gms-badge-warning">' . esc_html__('Pending Approval', 'guest-management-system') . '</span>',
        'approved' => '<span class="gms-badge gms-badge-success">' . esc_html__('Approved', 'guest-management-system') . '</span>',
        'awaiting_signature' => '<span class="gms-badge gms-badge-info">' . esc_html__('Awaiting Signature', 'guest-management-system') . '</span>',
        'awaiting_id_verification' => '<span class="gms-badge gms-badge-warning">' . esc_html__('Awaiting ID Verification', 'guest-management-system') . '</span>',
        'confirmed' => '<span class="gms-badge gms-badge-success">' . esc_html__('Confirmed', 'guest-management-system') . '</span>',
        'completed' => '<span class="gms-badge gms-badge-success">' . esc_html__('Completed', 'guest-management-system') . '</span>',
        'cancelled' => '<span class="gms-badge gms-badge-danger">' . esc_html__('Cancelled', 'guest-management-system') . '</span>',
        'in-progress' => '<span class="gms-badge gms-badge-info">' . esc_html__('In Progress', 'guest-management-system') . '</span>',
    );

    return $badges[$status] ?? '<span class="gms-badge gms-badge-default">' . esc_html(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

/**
 * Log custom event
 */
function gms_log_event($event_type, $reservation_id, $data = array()) {
    $log_data = array(
        'event_type' => sanitize_text_field($event_type),
        'reservation_id' => intval($reservation_id),
        'data' => maybe_serialize($data),
        'timestamp' => current_time('mysql'),
        'user_id' => get_current_user_id(),
        'ip_address' => GMS_Database::getUserIP()
    );
    
    do_action('gms_event_logged', $log_data);
    
    // Store in custom log table if needed
    // For now, just use WordPress error_log
    error_log('GMS Event: ' . $event_type . ' - Reservation: ' . $reservation_id);
}

/**
 * Get company branding settings
 */
function gms_get_branding() {
    return array(
        'company_name' => get_option('gms_company_name', get_option('blogname')),
        'company_logo' => get_option('gms_company_logo', ''),
        'primary_color' => get_option('gms_portal_primary_color', '#0073aa'),
        'secondary_color' => get_option('gms_portal_secondary_color', '#005a87')
    );
}

/**
 * Sanitize phone number
 */
function gms_sanitize_phone($phone) {
    return preg_replace('/[^0-9+]/', '', $phone);
}

/**
 * Format phone number for display
 */
function gms_format_phone($phone) {
    $sms_handler = new GMS_SMS_Handler();
    return $sms_handler->formatPhoneNumber($phone);
}

/**
 * Get platform icon/name
 */
function gms_get_platform_display($platform) {
    $platforms = array(
        'booking.com' => array('name' => 'Booking.com', 'icon' => '🏨'),
        'airbnb' => array('name' => 'Airbnb', 'icon' => '🏠'),
        'vrbo' => array('name' => 'VRBO', 'icon' => '🏖️'),
        'generic' => array('name' => 'Direct Booking', 'icon' => '📅')
    );
    
    return $platforms[$platform] ?? array('name' => ucfirst($platform), 'icon' => '📝');
}

/**
 * Check if webhook endpoints are configured
 */
function gms_webhooks_configured() {
    return true; // Webhooks are always available via REST API
}

/**
 * Get webhook URLs
 */
function gms_get_webhook_urls() {
    return array(
        'booking' => home_url('/webhook/booking'),
        'airbnb' => home_url('/webhook/airbnb'),
        'vrbo' => home_url('/webhook/vrbo'),
        'generic' => home_url('/webhook/generic'),
        'rest_api' => rest_url('gms/v1/webhook/{platform}'),
        'stripe' => rest_url('gms/v1/stripe-webhook')
    );
}

/**
 * Test notification services
 */
function gms_test_notifications() {
    $results = array();
    
    // Test email
    $email_handler = new GMS_Email_Handler();
    $test_email = get_option('admin_email');
    $results['email'] = $email_handler->sendEmail(
        $test_email,
        'Test Email from Guest Management System',
        'This is a test email to verify your email configuration.'
    );
    
    // Test SMS (only if configured)
    $voipms_user = get_option('gms_voipms_user');
    if (!empty($voipms_user)) {
        $sms_handler = new GMS_SMS_Handler();
        $results['sms_configured'] = true;
        $results['sms_balance'] = $sms_handler->getSMSBalance();
    } else {
        $results['sms_configured'] = false;
    }
    
    // Test Stripe
    $stripe_sk = get_option('gms_stripe_sk');
    if (!empty($stripe_sk)) {
        $stripe = new GMS_Stripe_Integration();
        $results['stripe'] = $stripe->testConnection();
    } else {
        $results['stripe'] = array('success' => false, 'message' => 'Not configured');
    }
    
    return $results;
}

/**
 * Get plugin version
 */
function gms_get_version() {
    return GMS_VERSION;
}

/**
 * Check if plugin requirements are met
 */
function gms_check_requirements() {
    $requirements = array(
        'php_version' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'wp_version' => version_compare(get_bloginfo('version'), '6.0', '>='),
        'curl' => function_exists('curl_init'),
        'json' => function_exists('json_encode')
    );
    
    return array_filter($requirements);
}

/**
 * AJAX handler for updating reservation status
 */
add_action('wp_ajax_gms_update_reservation_status', 'gms_ajax_update_reservation_status');
add_action('wp_ajax_nopriv_gms_update_reservation_status', 'gms_ajax_update_reservation_status');

function gms_ajax_update_reservation_status() {
    if (!wp_verify_nonce($_POST['nonce'], 'gms_guest_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    $reservation_id = intval($_POST['reservation_id']);
    $status = sanitize_text_field($_POST['status']);
    
    $result = gms_update_reservation_status($reservation_id, $status);
    
    if ($result) {
        wp_send_json_success('Status updated');
    } else {
        wp_send_json_error('Failed to update status');
    }
}

/**
 * Shortcode for displaying guest portal link
 */
add_shortcode('gms_portal_link', 'gms_portal_link_shortcode');

function gms_portal_link_shortcode($atts) {
    $atts = shortcode_atts(array(
        'reservation_id' => 0,
        'text' => 'Access Guest Portal'
    ), $atts);
    
    if (!$atts['reservation_id']) {
        return '';
    }
    
    $url = gms_get_portal_url($atts['reservation_id']);
    
    if (!$url) {
        return '';
    }
    
    return '<a href="' . esc_url($url) . '" class="gms-portal-link" target="_blank">' . 
           esc_html($atts['text']) . '</a>';
}

/**
 * Cron job to send reminder notifications
 */
add_action('gms_send_reminder_notifications', 'gms_cron_send_reminders');

function gms_cron_send_reminders() {
    // Get reservations that are pending and check-in is within 48 hours
    global $wpdb;

    $table = $wpdb->prefix . 'gms_reservations';
    $start_date = current_time('mysql');
    $end_date = date('Y-m-d H:i:s', strtotime('+48 hours'));

    $statuses = gms_get_followup_reservation_statuses();

    if (empty($statuses)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $query_params = array_merge($statuses, array($start_date, $end_date));

    $reservations = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table
         WHERE status IN ($placeholders)
         AND checkin_date BETWEEN %s AND %s",
        $query_params
    ), ARRAY_A);

    foreach ($reservations as $reservation) {
        $email_handler = new GMS_Email_Handler();
        $email_handler->sendReminderEmail($reservation);
        
        if (!empty($reservation['guest_phone'])) {
            $sms_handler = new GMS_SMS_Handler();
            $sms_handler->sendReminderSMS($reservation);
        }
    }
}

// Schedule cron job on plugin activation
register_activation_hook(__FILE__, 'gms_schedule_cron');

function gms_schedule_cron() {
    if (!wp_next_scheduled('gms_send_reminder_notifications')) {
        wp_schedule_event(time(), 'daily', 'gms_send_reminder_notifications');
    }
}

// Clear cron job on plugin deactivation
register_deactivation_hook(__FILE__, 'gms_clear_cron');

function gms_clear_cron() {
    wp_clear_scheduled_hook('gms_send_reminder_notifications');
    wp_clear_scheduled_hook('gms_sync_provider_messages');
}

add_action('gms_reservation_status_updated', 'gms_handle_reservation_status_transition', 10, 3);

function gms_handle_reservation_status_transition($reservation_id, $new_status, $previous_status = null) {
    $new_status = sanitize_key($new_status);
    $previous_status = $previous_status !== null ? sanitize_key($previous_status) : null;

    $should_handle_approved = $new_status === 'approved' && $previous_status !== 'approved';

    $completed_predecessors = array(
        'pending',
        'approved',
        'confirmed',
        'awaiting_signature',
        'awaiting_id_verification',
    );

    if (function_exists('apply_filters')) {
        $completed_predecessors = apply_filters(
            'gms_cleaning_ready_previous_statuses',
            $completed_predecessors,
            $reservation_id,
            $new_status,
            $previous_status
        );
    }

    if (!is_array($completed_predecessors)) {
        $completed_predecessors = array();
    }

    $completed_predecessors = array_values(array_unique(array_filter(array_map(
        function ($status) {
            if (!is_scalar($status)) {
                return '';
            }

            $sanitized = sanitize_key($status);

            return $sanitized !== '' ? $sanitized : '';
        },
        $completed_predecessors
    ))));

    $should_handle_completed = $new_status === 'completed'
        && $previous_status !== 'completed'
        && in_array($previous_status, $completed_predecessors, true);

    if (!$should_handle_approved && !$should_handle_completed) {
        return;
    }

    $reservation = GMS_Database::getReservationById($reservation_id);

    if (!$reservation) {
        return;
    }

    if ($should_handle_approved) {
        if (!empty($reservation['guest_email']) && is_email($reservation['guest_email'])) {
            static $email_handler = null;

            if ($email_handler === null) {
                $email_handler = new GMS_Email_Handler();
            }

            $email_handler->sendReservationApprovedEmail($reservation);
        }

        if (!empty($reservation['guest_phone'])) {
            static $sms_handler = null;

            if ($sms_handler === null) {
                $sms_handler = new GMS_SMS_Handler();
            }

            $sms_handler->sendReservationApprovedSMS($reservation);
        }
    }

    if ($should_handle_completed) {
        $checkout_raw = isset($reservation['checkout_date']) ? trim((string) $reservation['checkout_date']) : '';

        if ($checkout_raw === '' || $checkout_raw === '0000-00-00 00:00:00') {
            return;
        }

        try {
            $timezone = wp_timezone();
            $checkout = new \DateTimeImmutable($checkout_raw, $timezone);
        } catch (\Exception $exception) {
            error_log('GMS housekeeping event skipped: invalid checkout for reservation ' . $reservation_id);
            return;
        }

        $window_start = $checkout->modify('+30 minutes');
        $window_end   = $checkout->modify('+120 minutes');

        if (!$window_start || !$window_end) {
            return;
        }

        if (class_exists('GMS_Housekeeping_Integration')) {
            GMS_Housekeeping_Integration::sendCleaningWindowEvent($reservation, $window_start, $window_end);
        }

        $housekeeper_link = GMS_Database::getHousekeeperLinkForReservation($reservation_id);
        $housekeeper_token = is_array($housekeeper_link) ? trim((string) ($housekeeper_link['token'] ?? '')) : '';

        if ($housekeeper_token === '') {
            return;
        }

        $contacts = gms_get_housekeeper_contacts($reservation);
        $phones = isset($contacts['phones']) ? array_filter((array) $contacts['phones']) : array();

        if (empty($phones)) {
            return;
        }

        static $housekeeper_sms_handler = null;

        if ($housekeeper_sms_handler === null) {
            $housekeeper_sms_handler = new GMS_SMS_Handler();
        }

        $housekeeper_sms_handler->handleHousekeeperAssignment(
            $reservation_id,
            $housekeeper_token,
            '',
            $reservation
        );
    }
}
