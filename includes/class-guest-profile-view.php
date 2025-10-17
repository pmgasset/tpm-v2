<?php
/**
 * File: class-guest-profile-view.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-guest-profile-view.php
 *
 * Renders a secure guest profile for housekeeper access via hashed links.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Guest_Profile_View {

    public static function displayProfile($token) {
        $reservation = GMS_Database::getReservationByGuestProfileToken($token);

        if (!$reservation) {
            self::renderError(__('Invalid or expired guest profile link.', 'guest-management-system'));
            return;
        }

        $verification = GMS_Database::getVerificationByReservation($reservation['id']);
        $communications = GMS_Database::getCommunicationsForReservation($reservation['id'], array(
            'limit' => 25,
            'order' => 'DESC',
        ));

        $selfie = self::resolveSelfieMedia($reservation, $verification);

        $door_code = isset($reservation['door_code']) ? GMS_Database::sanitizeDoorCode($reservation['door_code']) : '';
        $property_name = isset($reservation['property_name']) ? sanitize_text_field($reservation['property_name']) : '';
        $guest_name = isset($reservation['guest_name']) ? sanitize_text_field($reservation['guest_name']) : '';

        $checkin_date = self::formatDateTime($reservation['checkin_date'] ?? '');
        $checkout_date = self::formatDateTime($reservation['checkout_date'] ?? '');

        $company_name = get_option('gms_company_name', get_option('blogname'));

        $communications = array_map(array(__CLASS__, 'formatCommunicationEntry'), $communications);

        self::renderLayout(array(
            'company_name' => $company_name,
            'reservation' => $reservation,
            'guest_name' => $guest_name,
            'property_name' => $property_name,
            'door_code' => $door_code,
            'checkin' => $checkin_date,
            'checkout' => $checkout_date,
            'selfie' => $selfie,
            'communications' => $communications,
        ));
    }

    private static function renderError($message) {
        $message = esc_html($message);

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html__('Guest Profile', 'guest-management-system') . '</title>';
        echo '<style>';
        echo 'body {font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 0;}';
        echo '.gms-profile-error {max-width: 480px; margin: 15vh auto; background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 18px 60px rgba(15,23,42,0.08);}';
        echo '.gms-profile-error h1 {margin-bottom: 12px; font-size: 1.65rem;}';
        echo '.gms-profile-error p {margin: 0; line-height: 1.6;}';
        echo '</style></head><body>';
        echo '<main class="gms-profile-error">';
        echo '<h1>' . esc_html__('Profile unavailable', 'guest-management-system') . '</h1>';
        echo '<p>' . $message . '</p>';
        echo '</main>';
        echo '</body></html>';
    }

    private static function renderLayout($data) {
        $company = esc_html($data['company_name']);
        $guest_name = esc_html($data['guest_name']);
        $property_name = esc_html($data['property_name']);
        $door_code = esc_html($data['door_code']);

        $checkin_label = self::formatDateLabel($data['checkin']);
        $checkout_label = self::formatDateLabel($data['checkout']);

        $selfie = $data['selfie'];
        $communications = $data['communications'];

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html__('Guest Profile', 'guest-management-system') . ' - ' . $guest_name . '</title>';
        echo '<style>';
        echo 'body {font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(145deg,#f8fafc,#e2e8f0); margin:0; color:#0f172a;}';
        echo '.gms-profile-wrapper {max-width: 960px; margin: 40px auto; padding: 0 20px;}';
        echo '.gms-profile-card {background:#fff; border-radius:24px; box-shadow:0 24px 80px rgba(15,23,42,0.12); overflow:hidden;}';
        echo '.gms-profile-header {padding:36px; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff;}';
        echo '.gms-profile-header h1 {margin:0 0 12px; font-size:2.15rem;}';
        echo '.gms-profile-header p {margin:0; opacity:0.85;}';
        echo '.gms-profile-content {padding:36px; display:flex; flex-wrap:wrap; gap:32px;}';
        echo '.gms-profile-section {flex:1 1 280px;}';
        echo '.gms-profile-section h2 {font-size:1.1rem; letter-spacing:0.02em; text-transform:uppercase; color:#475569; margin-bottom:12px;}';
        echo '.gms-profile-info {background:#f8fafc; border-radius:18px; padding:20px;}';
        echo '.gms-profile-info dl {margin:0; display:grid; grid-template-columns:minmax(120px,160px) 1fr; row-gap:12px; column-gap:18px;}';
        echo '.gms-profile-info dt {font-weight:600; color:#1e293b;}';
        echo '.gms-profile-info dd {margin:0; color:#334155;}';
        echo '.gms-profile-selfie {background:#f8fafc; border-radius:18px; padding:20px; display:flex; flex-direction:column; align-items:flex-start;}';
        echo '.gms-profile-selfie img {max-width:220px; border-radius:18px; box-shadow:0 16px 48px rgba(15,23,42,0.15);}';
        echo '.gms-profile-selfie .placeholder {padding:48px 24px; background:#e2e8f0; border-radius:18px; color:#475569;}';
        echo '.gms-profile-communications {padding:36px; border-top:1px solid rgba(148,163,184,0.25); background:#f8fafc;}';
        echo '.gms-profile-communications h2 {margin:0 0 20px; font-size:1.05rem; color:#1e293b;}';
        echo '.gms-profile-communications ul {list-style:none; margin:0; padding:0; display:grid; gap:16px;}';
        echo '.gms-profile-communications li {background:#fff; border-radius:16px; padding:18px 20px; box-shadow:0 12px 30px rgba(15,23,42,0.08);}';
        echo '.gms-comm-meta {display:flex; flex-wrap:wrap; gap:12px; font-size:0.9rem; color:#475569; margin-bottom:10px;}';
        echo '.gms-comm-message {margin:0; color:#1e293b; line-height:1.55;}';
        echo '@media (max-width:720px) { .gms-profile-content {flex-direction:column;} .gms-profile-info dl {grid-template-columns:1fr;} .gms-profile-info dt {color:#2563eb;} }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="gms-profile-wrapper">';
        echo '<article class="gms-profile-card">';
        echo '<header class="gms-profile-header">';
        echo '<h1>' . $guest_name . '</h1>';
        if ($property_name !== '') {
            echo '<p>' . sprintf(esc_html__('%1$s — %2$s', 'guest-management-system'), $property_name, $company) . '</p>';
        } else {
            echo '<p>' . $company . '</p>';
        }
        echo '</header>';
        echo '<div class="gms-profile-content">';
        echo '<section class="gms-profile-section">';
        echo '<h2>' . esc_html__('Reservation Details', 'guest-management-system') . '</h2>';
        echo '<div class="gms-profile-info"><dl>';
        if ($property_name !== '') {
            echo '<dt>' . esc_html__('Property', 'guest-management-system') . '</dt><dd>' . $property_name . '</dd>';
        }
        if (!empty($checkin_label)) {
            echo '<dt>' . esc_html__('Check-in', 'guest-management-system') . '</dt><dd>' . esc_html($checkin_label) . '</dd>';
        }
        if (!empty($checkout_label)) {
            echo '<dt>' . esc_html__('Check-out', 'guest-management-system') . '</dt><dd>' . esc_html($checkout_label) . '</dd>';
        }
        if ($door_code !== '') {
            echo '<dt>' . esc_html__('Door Code', 'guest-management-system') . '</dt><dd>' . $door_code . '</dd>';
        }
        $booking_reference = isset($data['reservation']['booking_reference']) ? sanitize_text_field($data['reservation']['booking_reference']) : '';
        if ($booking_reference !== '') {
            echo '<dt>' . esc_html__('Reference', 'guest-management-system') . '</dt><dd>' . esc_html($booking_reference) . '</dd>';
        }
        echo '</dl></div>';
        echo '</section>';

        echo '<section class="gms-profile-section">';
        echo '<h2>' . esc_html__('Selfie Verification', 'guest-management-system') . '</h2>';
        echo '<div class="gms-profile-selfie">';
        if (!empty($selfie['src'])) {
            $attrs = 'src="' . esc_url($selfie['src']) . '"';
            if (!empty($selfie['mime'])) {
                $attrs .= ' data-mime="' . esc_attr($selfie['mime']) . '"';
            }
            echo '<img ' . $attrs . ' alt="' . esc_attr(sprintf(__('Selfie for %s', 'guest-management-system'), $guest_name)) . '" />';
            if (!empty($selfie['note'])) {
                echo '<p style="margin-top:12px; font-size:0.9rem; color:#475569;">' . esc_html($selfie['note']) . '</p>';
            }
        } else {
            echo '<div class="placeholder">' . esc_html__('No selfie is available for this guest yet.', 'guest-management-system') . '</div>';
        }
        echo '</div>';
        echo '</section>';

        echo '</div>'; // profile-content

        echo '<section class="gms-profile-communications">';
        echo '<h2>' . esc_html__('Recent Communications', 'guest-management-system') . '</h2>';
        if (!empty($communications)) {
            echo '<ul>';
            foreach ($communications as $entry) {
                echo '<li>';
                echo '<div class="gms-comm-meta">';
                if ($entry['time'] !== '') {
                    echo '<span>' . esc_html($entry['time']) . '</span>';
                }
                if ($entry['channel'] !== '') {
                    echo '<span>' . esc_html($entry['channel']) . '</span>';
                }
                if ($entry['direction'] !== '') {
                    echo '<span>' . esc_html($entry['direction']) . '</span>';
                }
                echo '</div>';
                echo '<p class="gms-comm-message">' . esc_html($entry['message']) . '</p>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="margin:0; color:#475569;">' . esc_html__('No communications logged for this reservation yet.', 'guest-management-system') . '</p>';
        }
        echo '</section>';

        echo '</article>';
        echo '</div>';
        echo '</body></html>';
    }

    private static function resolveSelfieMedia($reservation, $verification) {
        $guest_user_id = isset($reservation['guest_id']) ? intval($reservation['guest_id']) : 0;
        $stored_url = '';

        if ($guest_user_id > 0) {
            $raw_url = get_user_meta($guest_user_id, 'gms_verification_selfie_url', true);
            if (is_string($raw_url)) {
                $raw_url = trim($raw_url);
                if ($raw_url !== '') {
                    $stored_url = esc_url_raw($raw_url);
                }
            }
        }

        if ($stored_url !== '') {
            return array('src' => $stored_url, 'mime' => '', 'note' => __('Stored from Stripe verification', 'guest-management-system'));
        }

        if (!is_array($verification)) {
            return array('src' => '', 'mime' => '', 'note' => '');
        }

        $relative_path = isset($verification['selfie_path']) ? trim((string) $verification['selfie_path']) : '';
        $mime = isset($verification['selfie_mime']) ? sanitize_mime_type($verification['selfie_mime']) : '';

        if ($relative_path !== '' && strpos($relative_path, 'stripe-file:') !== 0) {
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['error'])) {
                $baseurl = trailingslashit($upload_dir['baseurl']);
                $src = esc_url_raw($baseurl . ltrim($relative_path, '/'));
                if ($src !== '') {
                    return array('src' => $src, 'mime' => $mime, 'note' => __('Retrieved from secure uploads', 'guest-management-system'));
                }
            }
        }

        if (!empty($verification['selfie_file_id'])) {
            return array(
                'src' => '',
                'mime' => '',
                'note' => __('Selfie stored securely with Stripe. Request access from the property manager if needed.', 'guest-management-system'),
            );
        }

        return array('src' => '', 'mime' => '', 'note' => '');
    }

    private static function formatDateTime($raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            return array('date' => '', 'time' => '', 'label' => '');
        }

        $timestamp = strtotime($raw);
        if (!$timestamp) {
            return array('date' => '', 'time' => '', 'label' => '');
        }

        $timezone = wp_timezone();
        $formatted_date = wp_date('M j, Y', $timestamp, $timezone);
        $formatted_time = wp_date('g:i A', $timestamp, $timezone);

        return array(
            'date' => $formatted_date,
            'time' => $formatted_time,
            'label' => trim($formatted_date . ' · ' . $formatted_time),
        );
    }

    private static function formatDateLabel($date_info) {
        if (!is_array($date_info)) {
            return '';
        }

        $label = isset($date_info['label']) ? trim((string) $date_info['label']) : '';
        if ($label !== '') {
            return $label;
        }

        $date = isset($date_info['date']) ? trim((string) $date_info['date']) : '';
        $time = isset($date_info['time']) ? trim((string) $date_info['time']) : '';

        return trim($date . ' ' . $time);
    }

    private static function formatCommunicationEntry($entry) {
        $timestamp = isset($entry['sent_at']) ? strtotime($entry['sent_at']) : false;
        $timezone = wp_timezone();
        $time_display = $timestamp ? wp_date('M j, Y g:i A', $timestamp, $timezone) : '';

        $channel = isset($entry['channel']) ? sanitize_key($entry['channel']) : '';
        if ($channel !== '') {
            $channel = ucwords(str_replace('_', ' ', $channel));
        }

        $direction = isset($entry['direction']) ? sanitize_key($entry['direction']) : '';
        if ($direction !== '') {
            $direction = ucfirst($direction);
        }

        $message = isset($entry['message']) ? wp_strip_all_tags($entry['message']) : '';
        $message = $message !== '' ? wp_trim_words($message, 32, '…') : __('No message content recorded.', 'guest-management-system');

        return array(
            'time' => $time_display,
            'channel' => $channel,
            'direction' => $direction,
            'message' => $message,
        );
    }
}
