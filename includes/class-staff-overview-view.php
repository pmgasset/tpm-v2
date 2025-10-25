<?php
/**
 * File: class-staff-overview-view.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-staff-overview-view.php
 *
 * Read-only property staff overview page displaying reservations and guest context.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Staff_Overview_View {

    public static function displayOverview($token) {
        $token = sanitize_text_field((string) $token);

        if ($token === '' || !GMS_Database::isValidStaffOverviewToken($token)) {
            self::renderError(__('Invalid or expired staff overview link.', 'guest-management-system'));
            return;
        }

        $filters = self::resolveFilters();
        $query_args = array(
            'status' => $filters['status'] === 'all' ? '' : $filters['status'],
            'search' => $filters['search'],
            'limit' => $filters['limit'],
            'order' => 'ASC',
            'orderby' => 'checkin_date',
        );

        $reservations = GMS_Database::getStaffOverviewReservations($query_args);

        $company_name = get_option('gms_company_name', get_option('blogname'));
        $status_options = function_exists('gms_get_reservation_status_options')
            ? gms_get_reservation_status_options()
            : array();

        $prepared_reservations = self::prepareReservations($reservations, $status_options);
        $stats = self::buildStats($prepared_reservations, $status_options);

        self::renderLayout(array(
            'company' => $company_name,
            'filters' => $filters,
            'reservations' => $prepared_reservations,
            'stats' => $stats,
            'status_options' => $status_options,
            'token' => $token,
        ));
    }

    private static function resolveFilters() {
        $status_raw = isset($_GET['status']) ? $_GET['status'] : 'all';
        $status_raw = is_scalar($status_raw) ? (string) $status_raw : 'all';
        $status = sanitize_key($status_raw);
        if ($status === '' || $status === 'all') {
            $status = 'all';
        }

        $search_raw = isset($_GET['s']) ? $_GET['s'] : '';
        $search_raw = is_scalar($search_raw) ? (string) $search_raw : '';
        $search = sanitize_text_field($search_raw);

        $limit_raw = isset($_GET['limit']) ? $_GET['limit'] : '';
        $limit_raw = is_scalar($limit_raw) ? (int) $limit_raw : 0;

        $default_limit = 200;
        $max_limit = 500;

        if (function_exists('apply_filters')) {
            $default_limit = (int) apply_filters('gms_staff_overview_default_limit', $default_limit);
            $max_limit = (int) apply_filters('gms_staff_overview_max_limit', $max_limit);
        }

        if ($default_limit <= 0) {
            $default_limit = 200;
        }

        if ($max_limit <= 0) {
            $max_limit = 500;
        }

        if ($limit_raw <= 0) {
            $limit = $default_limit;
        } else {
            $limit = $limit_raw;
        }

        $limit = max(10, min($limit, $max_limit));

        return array(
            'status' => $status,
            'search' => $search,
            'limit' => $limit,
        );
    }

    private static function prepareReservations(array $reservations, array $status_options) {
        $prepared = array();

        foreach ($reservations as $reservation) {
            if (!is_array($reservation)) {
                continue;
            }

            $prepared[] = self::formatReservation($reservation, $status_options);
        }

        return $prepared;
    }

    private static function formatReservation(array $reservation, array $status_options) {
        $guest_name = sanitize_text_field($reservation['guest_name'] ?? '');
        $guest_email = sanitize_email($reservation['guest_email'] ?? '');
        $guest_phone_raw = isset($reservation['guest_phone']) ? (string) $reservation['guest_phone'] : '';
        $guest_phone_link = preg_replace('/[^0-9+]/', '', $guest_phone_raw);
        if ($guest_phone_link !== '' && strpos($guest_phone_link, '+') !== 0 && strlen(preg_replace('/[^0-9]/', '', $guest_phone_link)) === 10) {
            $digits_only = preg_replace('/[^0-9]/', '', $guest_phone_link);
            $guest_phone_link = '+1' . $digits_only;
        }
        $guest_phone_display = self::formatPhone($guest_phone_raw);

        $property_name = sanitize_text_field($reservation['property_name'] ?? '');
        $booking_reference = sanitize_text_field($reservation['booking_reference'] ?? '');

        $platform = sanitize_text_field($reservation['platform'] ?? '');
        $platform_display = $platform;
        if ($platform !== '' && function_exists('gms_get_platform_display')) {
            $platform_data = gms_get_platform_display($platform);
            if (is_array($platform_data)) {
                $platform_display = trim((string) ($platform_data['icon'] ?? '') . ' ' . (string) ($platform_data['name'] ?? ''));
            }
        }

        $checkin = self::formatDateTimeValue($reservation['checkin_timestamp'] ?? ($reservation['checkin_date'] ?? ''));
        $checkout = self::formatDateTimeValue($reservation['checkout_timestamp'] ?? ($reservation['checkout_date'] ?? ''));

        $nights = '';
        if (!empty($checkin['timestamp']) && !empty($checkout['timestamp']) && $checkout['timestamp'] > $checkin['timestamp']) {
            $seconds = $checkout['timestamp'] - $checkin['timestamp'];
            $nights_calc = (int) floor($seconds / (60 * 60 * 24));
            if ($nights_calc <= 0) {
                $nights_calc = 1;
            }
            $nights = sprintf(_n('%d night', '%d nights', $nights_calc, 'guest-management-system'), $nights_calc);
        }

        $status_key = sanitize_key($reservation['status'] ?? '');
        $status_label = $status_options[$status_key] ?? ($status_key !== '' ? ucwords(str_replace('_', ' ', $status_key)) : __('Unknown', 'guest-management-system'));

        $agreement_status = sanitize_text_field($reservation['agreement_status'] ?? '');
        $agreement_label = $agreement_status !== '' ? ucwords(str_replace('_', ' ', $agreement_status)) : __('Pending', 'guest-management-system');

        $verification_status = sanitize_text_field($reservation['verification_status'] ?? '');
        $verification_label = $verification_status !== '' ? ucwords(str_replace('_', ' ', $verification_status)) : __('Pending', 'guest-management-system');

        $door_code = sanitize_text_field($reservation['door_code'] ?? '');
        $portal_url = isset($reservation['portal_url']) ? esc_url_raw($reservation['portal_url']) : '';
        $profile_url = isset($reservation['guest_profile_url']) ? esc_url_raw($reservation['guest_profile_url']) : '';

        $contact_confirmed_at = sanitize_text_field($reservation['contact_info_confirmed_at'] ?? '');
        $contact_confirmed_label = '';
        if ($contact_confirmed_at !== '' && $contact_confirmed_at !== '0000-00-00 00:00:00') {
            $confirmed_timestamp = strtotime($contact_confirmed_at);
            if ($confirmed_timestamp) {
                $contact_confirmed_label = self::formatTimestampLabel($confirmed_timestamp);
            }
        }

        return array(
            'id' => isset($reservation['id']) ? (int) $reservation['id'] : 0,
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_phone_link' => $guest_phone_link,
            'guest_phone_display' => $guest_phone_display,
            'property_name' => $property_name,
            'booking_reference' => $booking_reference,
            'platform_display' => $platform_display,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'nights' => $nights,
            'status_key' => $status_key !== '' ? $status_key : 'unknown',
            'status_label' => $status_label,
            'agreement_status_key' => sanitize_key($agreement_status),
            'agreement_label' => $agreement_label,
            'verification_status_key' => sanitize_key($verification_status),
            'verification_label' => $verification_label,
            'door_code' => $door_code,
            'portal_url' => $portal_url,
            'profile_url' => $profile_url,
            'contact_confirmed_label' => $contact_confirmed_label,
        );
    }

    private static function buildStats(array $reservations, array $status_options) {
        $total = count($reservations);
        $status_counts = array();
        $fully_ready = 0;

        foreach ($reservations as $reservation) {
            $status = $reservation['status_key'] ?? 'unknown';
            if (!isset($status_counts[$status])) {
                $status_counts[$status] = 0;
            }
            $status_counts[$status]++;

            if (($reservation['agreement_status_key'] ?? '') === 'signed' && ($reservation['verification_status_key'] ?? '') === 'verified') {
                $fully_ready++;
            }
        }

        ksort($status_counts);

        $status_breakdown = array();
        foreach ($status_counts as $status => $count) {
            $label = $status_options[$status] ?? ($status !== '' ? ucwords(str_replace('_', ' ', $status)) : __('Unknown', 'guest-management-system'));
            $status_breakdown[] = array(
                'status' => $status,
                'label' => $label,
                'count' => $count,
            );
        }

        return array(
            'total' => $total,
            'fully_ready' => $fully_ready,
            'statuses' => $status_breakdown,
        );
    }

    private static function renderLayout($data) {
        $company = esc_html($data['company']);
        $filters = $data['filters'];
        $reservations = $data['reservations'];
        $stats = $data['stats'];
        $status_options = $data['status_options'];
        $token = $data['token'];

        $status_value = esc_attr($filters['status']);
        $search_value = esc_attr($filters['search']);
        $limit_value = (int) $filters['limit'];

        $self_link = function_exists('gms_build_staff_overview_url') ? gms_build_staff_overview_url($token) : '';
        $self_link_display = $self_link !== '' ? esc_url($self_link) : '';

        $status_choices = array('all' => __('All reservations', 'guest-management-system')) + $status_options;

        $limit_choices = array(50, 100, 200, 300, 500);
        if (!in_array($limit_value, $limit_choices, true)) {
            $limit_choices[] = $limit_value;
        }
        sort($limit_choices);

        $format_number = function ($value) {
            if (function_exists('number_format_i18n')) {
                return number_format_i18n($value);
            }

            return number_format((float) $value);
        };

        $selected_attr = function ($current, $expected) {
            if (function_exists('selected')) {
                return selected($current, $expected, false);
            }

            return (string) $current === (string) $expected ? 'selected="selected"' : '';
        };

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html__('Property Staff Overview', 'guest-management-system') . '</title>';
        echo '<style>';
        echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:0;}';
        echo '.gms-staff-overview{max-width:1200px;margin:40px auto;padding:0 24px 48px;}';
        echo '.gms-staff-header{background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;border-radius:24px;padding:32px 36px;margin-bottom:32px;box-shadow:0 24px 80px rgba(37,99,235,0.25);}';
        echo '.gms-staff-header__badge{display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,0.18);font-size:0.8rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:16px;}';
        echo '.gms-staff-header h1{margin:0;font-size:2.25rem;}';
        echo '.gms-staff-header p{margin:12px 0 0;font-size:1.05rem;opacity:0.92;}';
        echo '.gms-staff-header__link{margin-top:18px;font-size:0.9rem;opacity:0.9;}';
        echo '.gms-staff-header__link a{color:#fff;text-decoration:underline;}';
        echo '.gms-staff-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:0 0 32px;padding:0;list-style:none;}';
        echo '.gms-staff-summary__item{background:#fff;border-radius:20px;padding:18px 20px;box-shadow:0 18px 60px rgba(15,23,42,0.08);}';
        echo '.gms-staff-summary__item strong{display:block;font-size:1.7rem;margin-top:6px;color:#0f172a;}';
        echo '.gms-staff-filters{background:#fff;border-radius:20px;padding:20px 24px;box-shadow:0 18px 60px rgba(15,23,42,0.06);display:flex;flex-wrap:wrap;gap:16px;margin-bottom:28px;}';
        echo '.gms-staff-filters label{font-size:0.85rem;font-weight:600;color:#475569;display:block;margin-bottom:6px;}';
        echo '.gms-staff-filters input[type="search"],.gms-staff-filters select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #cbd5f5;background:#f8fafc;}';
        echo '.gms-staff-filters__group{flex:1 1 220px;}';
        echo '.gms-staff-filters button{align-self:flex-end;padding:12px 24px;border-radius:12px;border:none;background:#2563eb;color:#fff;font-weight:600;cursor:pointer;transition:background 0.2s ease;}';
        echo '.gms-staff-filters button:hover{background:#1d4ed8;}';
        echo '.gms-staff-table{width:100%;border-collapse:separate;border-spacing:0 16px;}';
        echo '.gms-staff-table thead th{font-size:0.9rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.04em;padding:0 16px 8px;text-align:left;}';
        echo '.gms-staff-table tbody tr{background:#fff;box-shadow:0 20px 60px rgba(15,23,42,0.08);border-radius:20px;}';
        echo '.gms-staff-table tbody tr td{padding:20px 16px;vertical-align:top;}';
        echo '.gms-staff-table tbody tr td:first-child{border-top-left-radius:20px;border-bottom-left-radius:20px;}';
        echo '.gms-staff-table tbody tr td:last-child{border-top-right-radius:20px;border-bottom-right-radius:20px;}';
        echo '.gms-staff-guest__name{font-size:1.05rem;font-weight:600;margin:0 0 6px;}';
        echo '.gms-staff-guest__contact{margin:0;font-size:0.92rem;color:#475569;}';
        echo '.gms-staff-guest__contact a{color:#2563eb;text-decoration:none;}';
        echo '.gms-staff-stay__item{margin:0 0 6px;font-size:0.95rem;color:#1e293b;}';
        echo '.gms-staff-status{display:flex;flex-direction:column;gap:8px;}';
        echo '.gms-staff-status__badge{display:inline-flex;align-items:center;font-weight:600;border-radius:999px;padding:6px 12px;font-size:0.85rem;background:#eff6ff;color:#1d4ed8;}';
        echo '.gms-staff-status__details{margin:0;font-size:0.9rem;color:#475569;}';
        echo '.gms-staff-links{display:flex;flex-direction:column;gap:8px;}';
        echo '.gms-staff-links a{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:12px;background:#f1f5f9;color:#1d4ed8;font-weight:600;text-decoration:none;}';
        echo '.gms-staff-links a:hover{background:#e0e7ff;}';
        echo '.gms-staff-links__code{font-size:0.9rem;color:#475569;}';
        echo '.gms-staff-empty{background:#fff;border-radius:20px;padding:40px;text-align:center;box-shadow:0 18px 60px rgba(15,23,42,0.06);font-size:1.05rem;color:#475569;}';
        echo '@media (max-width:900px){.gms-staff-table thead{display:none;}.gms-staff-table tbody tr{display:block;margin-bottom:16px;}.gms-staff-table tbody tr td{display:block;padding:16px;}.gms-staff-table tbody tr td:first-child,.gms-staff-table tbody tr td:last-child{border-radius:0;}.gms-staff-table tbody tr td+td{border-top:1px solid #e2e8f0;}}';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="gms-staff-overview">';
        echo '<header class="gms-staff-header">';
        echo '<span class="gms-staff-header__badge">' . esc_html__('Read-only access', 'guest-management-system') . '</span>';
        echo '<h1>' . esc_html__('Property Staff Overview', 'guest-management-system') . '</h1>';
        echo '<p>' . sprintf(esc_html__('%1$s — %2$s reservations in view', 'guest-management-system'), $company, $format_number($stats['total'])) . '</p>';
        if ($self_link_display !== '') {
            echo '<div class="gms-staff-header__link">';
            echo esc_html__('Direct link:', 'guest-management-system') . ' <a href="' . $self_link_display . '">' . $self_link_display . '</a>';
            echo '</div>';
        }
        echo '</header>';

        echo '<ul class="gms-staff-summary">';
        echo '<li class="gms-staff-summary__item"><span>' . esc_html__('Reservations in view', 'guest-management-system') . '</span><strong>' . $format_number($stats['total']) . '</strong></li>';
        echo '<li class="gms-staff-summary__item"><span>' . esc_html__('Ready for arrival', 'guest-management-system') . '</span><strong>' . $format_number($stats['fully_ready']) . '</strong></li>';
        foreach ($stats['statuses'] as $entry) {
            echo '<li class="gms-staff-summary__item">';
            echo '<span>' . esc_html($entry['label']) . '</span><strong>' . $format_number($entry['count']) . '</strong>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<form class="gms-staff-filters" method="get">';
        echo '<div class="gms-staff-filters__group">';
        echo '<label for="gms-staff-search">' . esc_html__('Search guests, properties, or references', 'guest-management-system') . '</label>';
        echo '<input type="search" id="gms-staff-search" name="s" value="' . $search_value . '" placeholder="' . esc_attr__('Search…', 'guest-management-system') . '">';
        echo '</div>';
        echo '<div class="gms-staff-filters__group">';
        echo '<label for="gms-staff-status">' . esc_html__('Reservation status', 'guest-management-system') . '</label>';
        echo '<select id="gms-staff-status" name="status">';
        foreach ($status_choices as $value => $label) {
            $key_value = sanitize_key($value);
            $selected = $selected_attr($status_value, $key_value);
            echo '<option value="' . esc_attr($key_value) . '" ' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="gms-staff-filters__group">';
        echo '<label for="gms-staff-limit">' . esc_html__('Max reservations', 'guest-management-system') . '</label>';
        echo '<select id="gms-staff-limit" name="limit">';
        foreach ($limit_choices as $choice) {
            $choice = (int) $choice;
            $selected = $selected_attr($limit_value, $choice);
            echo '<option value="' . esc_attr($choice) . '" ' . $selected . '>' . esc_html($format_number($choice)) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<button type="submit">' . esc_html__('Update view', 'guest-management-system') . '</button>';
        echo '<input type="hidden" name="gms_staff_overview" value="1">';
        echo '<input type="hidden" name="gms_staff_token" value="' . esc_attr($token) . '">';
        echo '</form>';

        if (empty($reservations)) {
            echo '<div class="gms-staff-empty">' . esc_html__('No reservations match your current filters. Try expanding your search or status filters.', 'guest-management-system') . '</div>';
        } else {
            echo '<table class="gms-staff-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Guest', 'guest-management-system') . '</th>';
            echo '<th>' . esc_html__('Stay details', 'guest-management-system') . '</th>';
            echo '<th>' . esc_html__('Status', 'guest-management-system') . '</th>';
            echo '<th>' . esc_html__('Access & security', 'guest-management-system') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($reservations as $reservation) {
                echo '<tr>';
                echo '<td>';
                echo '<p class="gms-staff-guest__name">' . esc_html($reservation['guest_name'] !== '' ? $reservation['guest_name'] : __('Guest', 'guest-management-system')) . '</p>';
                if ($reservation['guest_email'] !== '') {
                    $mailto = 'mailto:' . rawurlencode($reservation['guest_email']);
                    echo '<p class="gms-staff-guest__contact"><a href="' . esc_url($mailto) . '">' . esc_html($reservation['guest_email']) . '</a></p>';
                }
                if ($reservation['guest_phone_display'] !== '' && $reservation['guest_phone_link'] !== '') {
                    $tel_link = 'tel:' . esc_attr($reservation['guest_phone_link']);
                    echo '<p class="gms-staff-guest__contact"><a href="' . $tel_link . '">' . esc_html($reservation['guest_phone_display']) . '</a></p>';
                }
                echo '</td>';

                echo '<td>';
                if ($reservation['property_name'] !== '') {
                    echo '<p class="gms-staff-stay__item">' . esc_html($reservation['property_name']);
                    if ($reservation['platform_display'] !== '') {
                        echo ' · ' . esc_html($reservation['platform_display']);
                    }
                    echo '</p>';
                }
                if ($reservation['checkin']['label'] !== '') {
                    echo '<p class="gms-staff-stay__item">' . esc_html__('Check-in:', 'guest-management-system') . ' ' . esc_html($reservation['checkin']['label']) . '</p>';
                }
                if ($reservation['checkout']['label'] !== '') {
                    echo '<p class="gms-staff-stay__item">' . esc_html__('Check-out:', 'guest-management-system') . ' ' . esc_html($reservation['checkout']['label']) . '</p>';
                }
                if ($reservation['nights'] !== '') {
                    echo '<p class="gms-staff-stay__item">' . esc_html($reservation['nights']) . '</p>';
                }
                if ($reservation['booking_reference'] !== '') {
                    echo '<p class="gms-staff-stay__item">' . esc_html__('Reference:', 'guest-management-system') . ' ' . esc_html($reservation['booking_reference']) . '</p>';
                }
                if ($reservation['contact_confirmed_label'] !== '') {
                    echo '<p class="gms-staff-stay__item">' . esc_html__('Contact confirmed:', 'guest-management-system') . ' ' . esc_html($reservation['contact_confirmed_label']) . '</p>';
                }
                echo '</td>';

                echo '<td>';
                echo '<div class="gms-staff-status">';
                echo '<span class="gms-staff-status__badge">' . esc_html($reservation['status_label']) . '</span>';
                echo '<p class="gms-staff-status__details">' . esc_html__('Agreement:', 'guest-management-system') . ' ' . esc_html($reservation['agreement_label']) . '</p>';
                echo '<p class="gms-staff-status__details">' . esc_html__('Verification:', 'guest-management-system') . ' ' . esc_html($reservation['verification_label']) . '</p>';
                echo '</div>';
                echo '</td>';

                echo '<td>';
                echo '<div class="gms-staff-links">';
                if ($reservation['portal_url'] !== '') {
                    echo '<a href="' . esc_url($reservation['portal_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Guest portal', 'guest-management-system') . '</a>';
                }
                if ($reservation['profile_url'] !== '') {
                    echo '<a href="' . esc_url($reservation['profile_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Guest profile', 'guest-management-system') . '</a>';
                }
                if ($reservation['door_code'] !== '') {
                    echo '<span class="gms-staff-links__code">' . esc_html__('Door code:', 'guest-management-system') . ' ' . esc_html($reservation['door_code']) . '</span>';
                }
                echo '</div>';
                echo '</td>';

                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
        echo '</body></html>';
    }

    private static function formatDateTimeValue($raw) {
        if (is_array($raw) && isset($raw['timestamp'])) {
            $timestamp = (int) $raw['timestamp'];
        } elseif (is_numeric($raw)) {
            $timestamp = (int) $raw;
        } else {
            $raw_string = is_array($raw) ? '' : (string) $raw;
            $raw_string = trim($raw_string);
            if ($raw_string === '' || $raw_string === '0000-00-00 00:00:00') {
                return array('label' => '', 'timestamp' => 0);
            }
            $timestamp = strtotime($raw_string);
        }

        if (!$timestamp) {
            return array('label' => '', 'timestamp' => 0);
        }

        return array(
            'label' => self::formatTimestampLabel($timestamp),
            'timestamp' => $timestamp,
        );
    }

    private static function formatTimestampLabel($timestamp) {
        if (!is_int($timestamp)) {
            $timestamp = (int) $timestamp;
        }

        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
        } else {
            $timezone = new DateTimeZone('UTC');
        }

        if (function_exists('wp_date')) {
            return wp_date('M j, Y g:i A', $timestamp, $timezone);
        }

        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone($timezone);

        return $dt->format('M j, Y g:i A');
    }

    private static function formatPhone($phone) {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('+1 (%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
        }

        return $digits;
    }

    private static function renderError($message) {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html__('Property Staff Overview', 'guest-management-system') . '</title>';
        echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:0;}';
        echo '.gms-staff-error{max-width:420px;margin:18vh auto;background:#fff;padding:32px;border-radius:20px;box-shadow:0 18px 60px rgba(15,23,42,0.08);text-align:center;}';
        echo '.gms-staff-error h1{margin:0 0 12px;font-size:1.8rem;}';
        echo '.gms-staff-error p{margin:0;font-size:1rem;color:#475569;}';
        echo '</style></head><body><div class="gms-staff-error">';
        echo '<h1>' . esc_html__('Access unavailable', 'guest-management-system') . '</h1>';
        echo '<p>' . esc_html($message) . '</p>';
        echo '</div></body></html>';
    }
}
