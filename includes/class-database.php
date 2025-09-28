<?php
/**
 * File: class-database.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-database.php
 * Handles all database interactions for the Guest Management System
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Database {

    const GUEST_PLACEHOLDER_DOMAIN = 'guest.invalid';
    
    public static function createTables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_reservations = $wpdb->prefix . 'gms_reservations';
        $sql_reservations = "CREATE TABLE $table_reservations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            guest_id bigint(20) unsigned DEFAULT 0,
            guest_record_id bigint(20) unsigned DEFAULT 0,
            guest_name varchar(255) NOT NULL DEFAULT '',
            guest_email varchar(255) NOT NULL DEFAULT '',
            guest_phone varchar(50) NOT NULL DEFAULT '',
            property_id varchar(100) NOT NULL DEFAULT '',
            property_name varchar(255) NOT NULL DEFAULT '',
            booking_reference varchar(191) NOT NULL DEFAULT '',
            checkin_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            checkout_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            status varchar(50) NOT NULL DEFAULT 'pending',
            agreement_status varchar(50) NOT NULL DEFAULT 'pending',
            verification_status varchar(50) NOT NULL DEFAULT 'pending',
            portal_token varchar(100) NOT NULL DEFAULT '',
            platform varchar(100) NOT NULL DEFAULT '',
            webhook_payload longtext NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY guest_id (guest_id),
            KEY guest_record_id (guest_record_id),
            KEY booking_reference (booking_reference),
            KEY portal_token (portal_token),
            KEY platform (platform),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_reservations);

        $table_guests = $wpdb->prefix . 'gms_guests';
        $sql_guests = "CREATE TABLE $table_guests (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            email varchar(255) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY wp_user_id (wp_user_id)
        ) $charset_collate;";
        dbDelta($sql_guests);

        $table_agreements = $wpdb->prefix . 'gms_guest_agreements';
        $sql_agreements = "CREATE TABLE $table_agreements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_id bigint(20) unsigned NOT NULL,
            guest_id bigint(20) unsigned DEFAULT 0,
            status varchar(50) NOT NULL DEFAULT 'pending',
            agreement_text longtext NULL,
            signature_data longtext NULL,
            signature_hash varchar(64) NOT NULL DEFAULT '',
            pdf_path varchar(255) NOT NULL DEFAULT '',
            pdf_url varchar(255) NOT NULL DEFAULT '',
            signed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_id (reservation_id),
            KEY guest_id (guest_id)
        ) $charset_collate;";
        dbDelta($sql_agreements);

        $table_verification = $wpdb->prefix . 'gms_identity_verification';
        $sql_verification = "CREATE TABLE $table_verification (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_id bigint(20) unsigned NOT NULL,
            guest_id bigint(20) unsigned DEFAULT 0,
            stripe_verification_session_id varchar(191) NOT NULL DEFAULT '',
            stripe_client_secret varchar(191) NOT NULL DEFAULT '',
            verification_status varchar(50) NOT NULL DEFAULT 'pending',
            verification_data longtext NULL,
            verified_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY stripe_verification_session_id (stripe_verification_session_id),
            KEY reservation_id (reservation_id)
        ) $charset_collate;";
        dbDelta($sql_verification);

        $table_communications = $wpdb->prefix . 'gms_communications';
        $sql_communications = "CREATE TABLE $table_communications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_id bigint(20) unsigned NOT NULL,
            guest_id bigint(20) unsigned DEFAULT 0,
            communication_type varchar(50) NOT NULL DEFAULT '',
            recipient varchar(255) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            message longtext NULL,
            delivery_status varchar(50) NOT NULL DEFAULT '',
            response_data longtext NULL,
            provider_reference varchar(191) NOT NULL DEFAULT '',
            sent_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY reservation_id (reservation_id),
            KEY guest_id (guest_id),
            KEY communication_type (communication_type),
            KEY delivery_status (delivery_status)
        ) $charset_collate;";
        dbDelta($sql_communications);
    }

    public static function upsert_guest($guest_data, $options = array()) {
        global $wpdb;

        $defaults = array(
            'first_name' => '',
            'last_name' => '',
            'name' => '',
            'email' => '',
            'phone' => '',
            'wp_user_id' => 0,
        );

        $guest_data = wp_parse_args($guest_data, $defaults);
        $options = wp_parse_args($options, array(
            'suppress_user_sync' => false,
            'force_user_creation' => false,
        ));

        $full_name = trim((string) $guest_data['name']);
        $first_name = trim((string) $guest_data['first_name']);
        $last_name = trim((string) $guest_data['last_name']);

        if ($first_name === '' && $last_name === '' && $full_name !== '') {
            $name_parts = preg_split('/\s+/', $full_name);
            if (!empty($name_parts)) {
                $first_name = array_shift($name_parts);
                $last_name = implode(' ', $name_parts);
            }
        }

        $first_name = sanitize_text_field($first_name);
        $last_name = sanitize_text_field($last_name);

        if ($full_name === '' && ($first_name !== '' || $last_name !== '')) {
            $full_name = trim($first_name . ' ' . $last_name);
        }

        $email = sanitize_email($guest_data['email']);
        $phone_raw = isset($guest_data['phone']) ? $guest_data['phone'] : '';
        $phone = function_exists('gms_sanitize_phone')
            ? gms_sanitize_phone($phone_raw)
            : sanitize_text_field($phone_raw);

        $identity_seed = trim(strtolower($full_name . '|' . $phone));
        if ($identity_seed === '') {
            $identity_seed = sanitize_text_field($email);
        }

        $placeholder_email = '';
        if ($email === '') {
            $placeholder_email = 'guest-' . md5($identity_seed ?: wp_generate_password(12, false, false)) . '@' . self::GUEST_PLACEHOLDER_DOMAIN;
        }

        $table_guests = $wpdb->prefix . 'gms_guests';

        $existing_row = null;

        if ($email !== '') {
            $existing_row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, email FROM {$table_guests} WHERE email = %s", $email),
                ARRAY_A
            );
        }

        if (!$existing_row && $phone !== '') {
            $existing_row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, email FROM {$table_guests} WHERE phone = %s", $phone),
                ARRAY_A
            );
        }

        if (!$existing_row && ($first_name !== '' || $last_name !== '')) {
            $existing_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, email FROM {$table_guests} WHERE first_name = %s AND last_name = %s",
                    $first_name,
                    $last_name
                ),
                ARRAY_A
            );
        }

        if (!$existing_row && $placeholder_email !== '') {
            $existing_row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, email FROM {$table_guests} WHERE email = %s", $placeholder_email),
                ARRAY_A
            );
        }

        $guest_id = 0;

        if ($existing_row) {
            $update_data = array();

            if ($first_name !== '') {
                $update_data['first_name'] = $first_name;
            }

            if ($last_name !== '') {
                $update_data['last_name'] = $last_name;
            }

            if ($phone !== '') {
                $update_data['phone'] = $phone;
            }

            if ($email !== '') {
                $update_data['email'] = $email;
            } elseif ($placeholder_email !== '' && empty($existing_row['email'])) {
                $update_data['email'] = $placeholder_email;
            }

            if (!empty($guest_data['wp_user_id'])) {
                $update_data['wp_user_id'] = intval($guest_data['wp_user_id']);
            }

            if (!empty($update_data)) {
                $update_data['updated_at'] = current_time('mysql');
                $formats = array();
                foreach ($update_data as $key => $value) {
                    $formats[] = $key === 'wp_user_id' ? '%d' : '%s';
                }
                $wpdb->update(
                    $table_guests,
                    $update_data,
                    array('id' => intval($existing_row['id'])),
                    $formats,
                    array('%d')
                );
            }

            $guest_id = (int) $existing_row['id'];
        } else {
            $insert_data = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email !== '' ? $email : $placeholder_email,
                'phone' => $phone,
                'wp_user_id' => intval($guest_data['wp_user_id']),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );

            $formats = array('%s', '%s', '%s', '%s', '%d', '%s', '%s');

            $result = $wpdb->insert($table_guests, $insert_data, $formats);

            if ($result === false) {
                error_log('GMS: Failed to upsert guest record: ' . $wpdb->last_error);
                return 0;
            }

            $guest_id = (int) $wpdb->insert_id;
        }

        if ($guest_id > 0 && !$options['suppress_user_sync']) {
            self::syncGuestToUser($guest_id, array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'full_name' => $full_name,
                'email' => $email !== '' ? $email : $placeholder_email,
                'phone' => $phone,
            ), $options['force_user_creation']);
        }

        return $guest_id;
    }

    public static function getReservationByToken($token) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE portal_token = %s", sanitize_text_field($token)), ARRAY_A);
        return self::formatReservationRow($row);
    }

    public static function ensure_guest_user($guest_id, $guest_profile = array(), $force_create = false) {
        return self::syncGuestToUser($guest_id, $guest_profile, $force_create);
    }

    private static function syncGuestToUser($guest_id, $guest_profile, $force_create = false) {
        $guest_id = intval($guest_id);

        if ($guest_id <= 0) {
            return 0;
        }

        $guest_row = self::get_guest_by_id($guest_id);

        if (!$guest_row) {
            return 0;
        }

        $email = sanitize_email($guest_profile['email'] ?? $guest_row['email'] ?? '');
        $first_name = sanitize_text_field($guest_profile['first_name'] ?? $guest_row['first_name'] ?? '');
        $last_name = sanitize_text_field($guest_profile['last_name'] ?? $guest_row['last_name'] ?? '');
        $full_name = sanitize_text_field($guest_profile['full_name'] ?? trim($first_name . ' ' . $last_name));
        $phone = sanitize_text_field($guest_profile['phone'] ?? $guest_row['phone'] ?? '');

        $user_id = intval($guest_row['wp_user_id'] ?? 0);

        if ($user_id > 0) {
            $user = get_user_by('id', $user_id);
            if (!$user) {
                self::updateGuestWpUserId($guest_id, 0);
                $user_id = 0;
            }
        }

        if ($user_id === 0 && $email !== '' && is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $user_id = (int) $user->ID;
            }
        }

        if ($user_id === 0 && $force_create && $email !== '' && is_email($email)) {
            $username_base = sanitize_user(current(explode('@', $email)), true);
            if ($username_base === '') {
                $username_base = 'guest_' . $guest_id;
            }

            $username = $username_base;
            $attempt = 1;

            while (username_exists($username)) {
                $username = $username_base . '_' . $attempt;
                $attempt++;
            }

            $user_id = wp_insert_user(array(
                'user_login' => $username,
                'user_pass' => wp_generate_password(32, true, true),
                'user_email' => $email,
                'display_name' => $full_name !== '' ? $full_name : $username,
                'first_name' => $first_name,
                'last_name' => $last_name,
            ));

            if (is_wp_error($user_id)) {
                error_log('GMS: Failed to create guest user - ' . $user_id->get_error_message());
                $user_id = 0;
            } else {
                $user_id = (int) $user_id;
            }
        }

        if ($user_id <= 0) {
            return 0;
        }

        $update_user = array('ID' => $user_id);

        if ($first_name !== '') {
            $update_user['first_name'] = $first_name;
        }

        if ($last_name !== '') {
            $update_user['last_name'] = $last_name;
        }

        if ($email !== '' && is_email($email)) {
            $update_user['user_email'] = $email;
        }

        if (!empty($update_user['first_name']) || !empty($update_user['last_name'])) {
            $display_name = trim(($update_user['first_name'] ?? $first_name) . ' ' . ($update_user['last_name'] ?? $last_name));
            if ($display_name !== '') {
                $update_user['display_name'] = $display_name;
            }
        } elseif ($full_name !== '') {
            $update_user['display_name'] = $full_name;
        }

        $result = wp_update_user($update_user);

        if (is_wp_error($result)) {
            error_log('GMS: Failed to update guest user #' . $user_id . ' - ' . $result->get_error_message());
        }

        $user = get_user_by('id', $user_id);
        if ($user && !in_array('guest', (array) $user->roles, true)) {
            $user->add_role('guest');
        }

        if ($phone !== '') {
            update_user_meta($user_id, 'gms_guest_phone', $phone);
        }

        self::updateGuestWpUserId($guest_id, $user_id);

        global $wpdb;
        $table_reservations = $wpdb->prefix . 'gms_reservations';
        $wpdb->update(
            $table_reservations,
            array('guest_id' => $user_id),
            array('guest_record_id' => $guest_id),
            array('%d'),
            array('%d')
        );

        return $user_id;
    }

    private static function updateGuestWpUserId($guest_id, $user_id) {
        global $wpdb;

        $table_guests = $wpdb->prefix . 'gms_guests';
        $wpdb->update(
            $table_guests,
            array(
                'wp_user_id' => intval($user_id),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => intval($guest_id)),
            array('%d', '%s'),
            array('%d')
        );
    }

    public static function get_guest_wp_user_id($guest_id) {
        global $wpdb;

        $table_guests = $wpdb->prefix . 'gms_guests';
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wp_user_id FROM {$table_guests} WHERE id = %d",
                intval($guest_id)
            )
        );

        return intval($value);
    }

    public static function get_guest_by_wp_user_id($user_id) {
        global $wpdb;

        $table_guests = $wpdb->prefix . 'gms_guests';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT g.*, TRIM(CONCAT(g.first_name, ' ', g.last_name)) AS name FROM {$table_guests} g WHERE wp_user_id = %d",
                intval($user_id)
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        if (empty($row['name'])) {
            $row['name'] = trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''));
        }

        if (!empty($row['email']) && str_ends_with($row['email'], '@' . self::GUEST_PLACEHOLDER_DOMAIN)) {
            $row['email'] = '';
        }

        return $row;
    }

    public static function syncUserToGuest($user_id) {
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return false;
        }

        $first_name = $user->first_name;
        $last_name = $user->last_name;
        $email = $user->user_email;
        $phone = get_user_meta($user_id, 'gms_guest_phone', true);

        global $wpdb;
        $table_guests = $wpdb->prefix . 'gms_guests';

        $guest_row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_guests} WHERE wp_user_id = %d", intval($user_id)),
            ARRAY_A
        );

        $guest_data = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'wp_user_id' => $user_id,
        );

        if ($guest_row) {
            $sanitized_email = sanitize_email($email);
            if ($sanitized_email === '' && !empty($guest_row['email'])) {
                $sanitized_email = $guest_row['email'];
            }

            $sanitized_phone = sanitize_text_field($phone);
            if ($sanitized_phone === '' && !empty($guest_row['phone'])) {
                $sanitized_phone = $guest_row['phone'];
            }

            $update = array(
                'first_name' => sanitize_text_field($first_name),
                'last_name' => sanitize_text_field($last_name),
                'email' => $sanitized_email,
                'phone' => $sanitized_phone,
                'updated_at' => current_time('mysql'),
            );

            $wpdb->update(
                $table_guests,
                $update,
                array('id' => intval($guest_row['id'])),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );

            return true;
        }

        self::upsert_guest($guest_data, array(
            'suppress_user_sync' => true,
        ));

        return true;
    }

    public static function createReservation($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';

        $defaults = array(
            'guest_id' => 0,
            'guest_record_id' => 0,
            'guest_name' => '',
            'guest_email' => '',
            'guest_phone' => '',
            'property_id' => '',
            'property_name' => '',
            'booking_reference' => '',
            'checkin_date' => '',
            'checkout_date' => '',
            'status' => 'pending',
            'agreement_status' => 'pending',
            'verification_status' => 'pending',
            'portal_token' => '',
            'platform' => '',
            'webhook_data' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        $portal_token = $data['portal_token'];
        if (empty($portal_token)) {
            $portal_token = self::generatePortalToken();
        }

        $guest_phone = $data['guest_phone'];
        if (function_exists('gms_sanitize_phone')) {
            $guest_phone = gms_sanitize_phone($guest_phone);
        } else {
            $guest_phone = sanitize_text_field($guest_phone);
        }

        $insert_data = array(
            'guest_id' => intval($data['guest_id']),
            'guest_record_id' => intval($data['guest_record_id']),
            'guest_name' => sanitize_text_field($data['guest_name']),
            'guest_email' => sanitize_email($data['guest_email']),
            'guest_phone' => $guest_phone,
            'property_id' => sanitize_text_field($data['property_id']),
            'property_name' => sanitize_text_field($data['property_name']),
            'booking_reference' => sanitize_text_field($data['booking_reference']),
            'checkin_date' => self::sanitizeDateTime($data['checkin_date']),
            'checkout_date' => self::sanitizeDateTime($data['checkout_date']),
            'status' => sanitize_text_field($data['status']),
            'agreement_status' => sanitize_text_field($data['agreement_status']),
            'verification_status' => sanitize_text_field($data['verification_status']),
            'portal_token' => sanitize_text_field($portal_token),
            'platform' => sanitize_text_field($data['platform']),
            'webhook_payload' => self::maybeEncodeJson($data['webhook_data']),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $formats = array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        $result = $wpdb->insert($table_name, $insert_data, $formats);

        if ($result === false) {
            return false;
        }

        $reservation_id = (int) $wpdb->insert_id;

        return $reservation_id;
    }

    public static function getReservationById($reservation_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($reservation_id)), ARRAY_A);
        return self::formatReservationRow($row);
    }

    public static function updateReservation($reservation_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';

        $allowed = array(
            'guest_id', 'guest_record_id', 'guest_name', 'guest_email', 'guest_phone', 'property_id', 'property_name',
            'booking_reference', 'checkin_date', 'checkout_date', 'status',
            'agreement_status', 'verification_status', 'portal_token', 'platform', 'webhook_data'
        );

        $update_data = array();
        foreach ($allowed as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            switch ($field) {
                case 'guest_id':
                    $update_data['guest_id'] = intval($data[$field]);
                    break;
                case 'guest_record_id':
                    $update_data['guest_record_id'] = intval($data[$field]);
                    break;
                case 'guest_email':
                    $update_data['guest_email'] = sanitize_email($data[$field]);
                    break;
                case 'guest_phone':
                    $update_data['guest_phone'] = function_exists('gms_sanitize_phone')
                        ? gms_sanitize_phone($data[$field])
                        : sanitize_text_field($data[$field]);
                    break;
                case 'checkin_date':
                case 'checkout_date':
                    $update_data[$field] = self::sanitizeDateTime($data[$field]);
                    break;
                case 'webhook_data':
                    $update_data['webhook_payload'] = self::maybeEncodeJson($data[$field]);
                    break;
                default:
                    $update_data[$field] = sanitize_text_field($data[$field]);
                    break;
            }
        }

        if (empty($update_data)) {
            return true;
        }

        $update_data['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => intval($reservation_id)),
            null,
            array('%d')
        );

        return $result !== false;
    }

    public static function updateReservationStatus($reservation_id, $status) {
        $status = sanitize_text_field($status);

        $update = array('status' => $status);

        if ($status === 'agreement_signed') {
            $update['agreement_status'] = 'signed';
        }

        if ($status === 'verification_completed' || $status === 'verified') {
            $update['verification_status'] = 'verified';
        }

        return self::updateReservation($reservation_id, $update);
    }

    public static function createAgreement($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_guest_agreements';

        $defaults = array(
            'reservation_id' => 0,
            'guest_id' => 0,
            'status' => 'signed',
            'agreement_text' => '',
            'signature_data' => '',
        );

        $data = wp_parse_args($data, $defaults);

        $reservation_id = intval($data['reservation_id']);

        $insert_data = array(
            'reservation_id' => $reservation_id,
            'guest_id' => intval($data['guest_id']),
            'status' => sanitize_text_field($data['status']),
            'agreement_text' => wp_kses_post($data['agreement_text']),
            'signature_data' => self::sanitizeSignatureData($data['signature_data']),
            'signature_hash' => md5(self::sanitizeSignatureData($data['signature_data'])),
            'signed_at' => current_time('mysql'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $formats = array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s');

        $result = $wpdb->insert($table_name, $insert_data, $formats);

        if ($result === false) {
            return false;
        }

        $agreement_id = (int) $wpdb->insert_id;

        self::updateReservationStatus($reservation_id, 'agreement_signed');

        return $agreement_id;
    }

    public static function updateAgreement($agreement_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_guest_agreements';

        $allowed = array('status', 'agreement_text', 'signature_data', 'pdf_path', 'pdf_url', 'signed_at');

        $update_data = array();
        foreach ($allowed as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            switch ($field) {
                case 'agreement_text':
                    $update_data[$field] = wp_kses_post($data[$field]);
                    break;
                case 'signature_data':
                    $update_data[$field] = self::sanitizeSignatureData($data[$field]);
                    $update_data['signature_hash'] = md5($update_data[$field]);
                    break;
                case 'pdf_url':
                    $update_data[$field] = esc_url_raw($data[$field]);
                    break;
                case 'signed_at':
                    $update_data[$field] = self::sanitizeDateTime($data[$field]);
                    break;
                default:
                    $update_data[$field] = sanitize_text_field($data[$field]);
                    break;
            }
        }

        if (empty($update_data)) {
            return true;
        }

        $update_data['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => intval($agreement_id)),
            null,
            array('%d')
        );

        if ($result === false) {
            return false;
        }

        if (isset($update_data['status'])) {
            $reservation_id = $wpdb->get_var($wpdb->prepare("SELECT reservation_id FROM $table_name WHERE id = %d", intval($agreement_id)));
            if ($reservation_id) {
                self::updateReservation($reservation_id, array('agreement_status' => $update_data['status']));
            }
        }

        return true;
    }

    public static function getAgreementById($agreement_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_guest_agreements';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($agreement_id)), ARRAY_A);
        return self::formatAgreementRow($row);
    }

    public static function getAgreementByReservation($reservation_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_guest_agreements';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE reservation_id = %d", intval($reservation_id)), ARRAY_A);
        return self::formatAgreementRow($row);
    }

    public static function createVerification($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_identity_verification';

        $defaults = array(
            'reservation_id' => 0,
            'guest_id' => 0,
            'stripe_session_id' => '',
            'stripe_client_secret' => '',
            'status' => 'pending',
            'verification_data' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        $insert_data = array(
            'reservation_id' => intval($data['reservation_id']),
            'guest_id' => intval($data['guest_id']),
            'stripe_verification_session_id' => sanitize_text_field($data['stripe_session_id']),
            'stripe_client_secret' => sanitize_text_field($data['stripe_client_secret']),
            'verification_status' => sanitize_text_field($data['status']),
            'verification_data' => self::maybeEncodeJson($data['verification_data']),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $formats = array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s');

        $result = $wpdb->insert($table_name, $insert_data, $formats);

        if ($result === false) {
            return false;
        }

        self::updateReservation($data['reservation_id'], array('verification_status' => $data['status']));

        return (int) $wpdb->insert_id;
    }

    public static function getVerificationByReservation($reservation_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_identity_verification';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE reservation_id = %d ORDER BY id DESC LIMIT 1", intval($reservation_id)), ARRAY_A);
        return self::formatVerificationRow($row);
    }

    public static function updateVerification($stripe_session_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_identity_verification';

        $allowed = array('status', 'verification_data', 'stripe_client_secret', 'verified_at');
        $update_data = array();

        foreach ($allowed as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            switch ($field) {
                case 'verification_data':
                    $update_data['verification_data'] = self::maybeEncodeJson($data[$field]);
                    break;
                case 'verified_at':
                    $update_data['verified_at'] = self::sanitizeDateTime($data[$field]);
                    break;
                case 'status':
                    $update_data['verification_status'] = sanitize_text_field($data[$field]);
                    break;
                default:
                    $update_data[$field] = sanitize_text_field($data[$field]);
                    break;
            }
        }

        if (empty($update_data)) {
            return true;
        }

        if (isset($update_data['verification_status']) && $update_data['verification_status'] === 'verified' && empty($update_data['verified_at'])) {
            $update_data['verified_at'] = current_time('mysql');
        }

        $update_data['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('stripe_verification_session_id' => sanitize_text_field($stripe_session_id)),
            null,
            array('%s')
        );

        if ($result === false) {
            return false;
        }

        $record = $wpdb->get_row($wpdb->prepare("SELECT reservation_id FROM $table_name WHERE stripe_verification_session_id = %s", sanitize_text_field($stripe_session_id)), ARRAY_A);
        if ($record && isset($update_data['verification_status'])) {
            if ($update_data['verification_status'] === 'verified') {
                self::updateReservationStatus($record['reservation_id'], 'verification_completed');
            } else {
                self::updateReservation($record['reservation_id'], array('verification_status' => $update_data['verification_status']));
            }
        }

        return true;
    }

    public static function logCommunication($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_communications';

        $defaults = array(
            'reservation_id' => 0,
            'guest_id' => 0,
            'type' => '',
            'recipient' => '',
            'subject' => '',
            'message' => '',
            'status' => '',
            'response_data' => array(),
            'provider_reference' => '',
        );

        $data = wp_parse_args($data, $defaults);

        $insert_data = array(
            'reservation_id' => intval($data['reservation_id']),
            'guest_id' => intval($data['guest_id']),
            'communication_type' => sanitize_text_field($data['type']),
            'recipient' => sanitize_text_field($data['recipient']),
            'subject' => sanitize_text_field($data['subject']),
            'message' => wp_kses_post($data['message']),
            'delivery_status' => sanitize_text_field($data['status']),
            'response_data' => self::maybeEncodeJson($data['response_data']),
            'provider_reference' => sanitize_text_field($data['provider_reference']),
            'sent_at' => current_time('mysql'),
            'created_at' => current_time('mysql'),
        );

        $formats = array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        $wpdb->insert($table_name, $insert_data, $formats);
    }

    public static function getUserIP() {
        $keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');

        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $ip_list = explode(',', $_SERVER[$key]);
            $ip = trim($ip_list[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * NEW: Fetches reservations with guest names for the admin list table.
     */
    public static function get_reservations($per_page = 20, $current_page = 1) {
        global $wpdb;
        $table_reservations = $wpdb->prefix . 'gms_reservations';
        $table_guests = $wpdb->prefix . 'gms_guests';
        $offset = ($current_page - 1) * $per_page;
        
        $query = $wpdb->prepare(
            "SELECT r.*, r.guest_name AS reservation_guest_name,
                COALESCE(NULLIF(TRIM(CONCAT(g.first_name, ' ', g.last_name)), ''), r.guest_name) AS guest_name
            FROM {$table_reservations} r
            LEFT JOIN {$table_guests} g ON r.guest_record_id = g.id
            ORDER BY r.checkin_date DESC
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);

        return array_map(array(__CLASS__, 'formatReservationRow'), $results);
    }

    public static function get_guests($per_page = 20, $current_page = 1, $search = '') {
        global $wpdb;

        $table_guests = $wpdb->prefix . 'gms_guests';
        $offset = max(0, ($current_page - 1) * $per_page);

        $sql = "SELECT g.*, TRIM(CONCAT(g.first_name, ' ', g.last_name)) AS name
            FROM {$table_guests} g";
        $params = array();

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= " WHERE (g.first_name LIKE %s OR g.last_name LIKE %s OR g.email LIKE %s OR g.phone LIKE %s)";
            $params = array($like, $like, $like, $like);
        }

        $sql .= " ORDER BY g.created_at DESC LIMIT %d OFFSET %d";
        $params[] = (int) $per_page;
        $params[] = (int) $offset;

        $query = $wpdb->prepare($sql, $params);
        $results = $wpdb->get_results($query, ARRAY_A);

        return array_map(static function ($row) {
            if (isset($row['name'])) {
                $row['name'] = trim($row['name']);
            }

            if (empty($row['name'])) {
                $row['name'] = trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''));
            }

            if (!empty($row['email']) && str_ends_with($row['email'], '@' . self::GUEST_PLACEHOLDER_DOMAIN)) {
                $row['email'] = '';
            }

            return $row;
        }, $results);
    }

    public static function get_guest_by_id($guest_id) {
        global $wpdb;

        $table_guests = $wpdb->prefix . 'gms_guests';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT g.*, TRIM(CONCAT(g.first_name, ' ', g.last_name)) AS name FROM {$table_guests} g WHERE id = %d",
                intval($guest_id)
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        if (empty($row['name'])) {
            $row['name'] = trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''));
        }

        if (!empty($row['email']) && str_ends_with($row['email'], '@' . self::GUEST_PLACEHOLDER_DOMAIN)) {
            $row['email'] = '';
        }

        return $row;
    }

    public static function get_guest_count($search = '') {
        global $wpdb;

        $table_guests = $wpdb->prefix . 'gms_guests';
        $sql = "SELECT COUNT(g.id) FROM {$table_guests} g";
        $params = array();

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= " WHERE (g.first_name LIKE %s OR g.last_name LIKE %s OR g.email LIKE %s OR g.phone LIKE %s)";
            $params = array($like, $like, $like, $like);
        }

        $query = $params ? $wpdb->prepare($sql, $params) : $sql;

        return (int) $wpdb->get_var($query);
    }

    /**
     * NEW: Gets the total count of records for pagination.
     */
    public static function get_record_count($type = 'reservations') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        if ($type === 'guests') {
            $table_name = $wpdb->prefix . 'gms_guests';
        }
        return (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}");
    }

    public static function maybeScheduleGuestBackfill() {
        if (get_option('gms_guest_backfill_complete')) {
            return;
        }

        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        if (!self::hasPendingGuestBackfill()) {
            update_option('gms_guest_backfill_complete', 1, false);
            return;
        }

        if (!wp_next_scheduled('gms_guest_backfill_event')) {
            $delay = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
            wp_schedule_single_event(time() + $delay, 'gms_guest_backfill_event', array(50));
        }
    }

    public static function runGuestBackfill($batch_size = 50) {
        $batch_size = max(1, (int) $batch_size);

        if (!self::hasPendingGuestBackfill()) {
            update_option('gms_guest_backfill_complete', 1, false);
            return;
        }

        $processed = self::processGuestBackfillBatch($batch_size);

        if ($processed === 0) {
            update_option('gms_guest_backfill_complete', 1, false);
            return;
        }

        if (self::hasPendingGuestBackfill() && function_exists('wp_schedule_single_event')) {
            $delay = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
            wp_schedule_single_event(time() + $delay, 'gms_guest_backfill_event', array($batch_size));
        } else {
            update_option('gms_guest_backfill_complete', 1, false);
        }
    }

    private static function processGuestBackfillBatch($batch_size = 50) {
        global $wpdb;

        $table_reservations = $wpdb->prefix . 'gms_reservations';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, guest_name, guest_email, guest_phone FROM {$table_reservations}
                 WHERE (guest_record_id IS NULL OR guest_record_id = 0)
                   AND (guest_name <> '' OR guest_email <> '' OR guest_phone <> '')
                 ORDER BY id ASC
                 LIMIT %d",
                max(1, (int) $batch_size)
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return 0;
        }

        $processed = 0;

        foreach ($rows as $row) {
            $guest_id = self::upsert_guest(array(
                'name' => $row['guest_name'],
                'email' => $row['guest_email'],
                'phone' => $row['guest_phone'],
            ));

            if ($guest_id) {
                $guest_profile = self::get_guest_by_id($guest_id);
                $wp_user_id = 0;

                if ($guest_profile) {
                    $wp_user_id = self::ensure_guest_user($guest_id, array(
                        'first_name' => $guest_profile['first_name'] ?? '',
                        'last_name' => $guest_profile['last_name'] ?? '',
                        'email' => $guest_profile['email'] ?? '',
                        'phone' => $guest_profile['phone'] ?? '',
                    ), !empty($guest_profile['email']) && is_email($guest_profile['email']));
                }

                self::updateReservation($row['id'], array(
                    'guest_record_id' => $guest_id,
                    'guest_id' => $wp_user_id,
                ));
                $processed++;
            }
        }

        return $processed;
    }

    private static function hasPendingGuestBackfill() {
        global $wpdb;

        $table_reservations = $wpdb->prefix . 'gms_reservations';

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(id) FROM {$table_reservations} WHERE (guest_record_id IS NULL OR guest_record_id = 0) AND (guest_name <> '' OR guest_email <> '' OR guest_phone <> '')"
        );

        return $count > 0;
    }

    private static function sanitizeDateTime($value) {
        if (empty($value)) {
            return '0000-00-00 00:00:00';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '0000-00-00 00:00:00';
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function sanitizeSignatureData($value) {
        if (empty($value)) {
            return '';
        }

        $value = trim($value);
        return preg_replace('/[^A-Za-z0-9\/=+,:;._-]/', '', $value);
    }

    private static function maybeEncodeJson($value) {
        if (empty($value)) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value);
        }

        return wp_strip_all_tags((string) $value);
    }

    private static function generatePortalToken() {
        return strtolower(wp_generate_password(32, false, false));
    }

    private static function formatReservationRow($row) {
        if (!$row) {
            return null;
        }

        if (isset($row['webhook_payload']) && !empty($row['webhook_payload'])) {
            $decoded = json_decode($row['webhook_payload'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['webhook_data'] = $decoded;
            }
        }

        if (isset($row['guest_name'])) {
            $row['guest_name'] = trim($row['guest_name']);
        }

        if (isset($row['guest_id'])) {
            $row['guest_id'] = intval($row['guest_id']);
        }

        if (isset($row['guest_record_id'])) {
            $row['guest_record_id'] = intval($row['guest_record_id']);
        }

        if (empty($row['guest_name']) && isset($row['reservation_guest_name'])) {
            $row['guest_name'] = trim($row['reservation_guest_name']);
        }

        return $row;
    }

    private static function formatAgreementRow($row) {
        if (!$row) {
            return null;
        }

        if (!empty($row['pdf_path']) && empty($row['pdf_url'])) {
            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['basedir']) && !empty($upload_dir['baseurl'])) {
                $relative = str_replace($upload_dir['basedir'], '', $row['pdf_path']);
                $row['pdf_url'] = trailingslashit($upload_dir['baseurl']) . ltrim($relative, '/');
            }
        }

        if (isset($row['signed_at']) && $row['signed_at'] === '0000-00-00 00:00:00') {
            $row['signed_at'] = '';
        }

        return $row;
    }

    private static function formatVerificationRow($row) {
        if (!$row) {
            return null;
        }

        if (isset($row['verification_data']) && !empty($row['verification_data'])) {
            $decoded = json_decode($row['verification_data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['verification_data'] = $decoded;
            }
        }

        if (isset($row['verified_at']) && $row['verified_at'] === '0000-00-00 00:00:00') {
            $row['verified_at'] = '';
        }

        $row['stripe_session_id'] = $row['stripe_verification_session_id'];
        $row['status'] = $row['verification_status'];

        return $row;
    }
}

if (function_exists('add_action')) {
    add_action('admin_init', array('GMS_Database', 'maybeScheduleGuestBackfill'));
    add_action('gms_guest_backfill_event', array('GMS_Database', 'runGuestBackfill'));
}
