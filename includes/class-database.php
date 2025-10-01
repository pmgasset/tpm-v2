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
    const DB_VERSION = '1.2.0';
    const OPTION_DB_VERSION = 'gms_db_version';

    public function __construct() {
        self::maybeRunMigrations();
    }
    
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
            door_code varchar(20) NOT NULL DEFAULT '',
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
            channel varchar(50) NOT NULL DEFAULT '',
            direction varchar(50) NOT NULL DEFAULT '',
            from_number varchar(50) NOT NULL DEFAULT '',
            to_number varchar(50) NOT NULL DEFAULT '',
            from_number_e164 varchar(20) NOT NULL DEFAULT '',
            to_number_e164 varchar(20) NOT NULL DEFAULT '',
            thread_key varchar(191) NOT NULL DEFAULT '',
            external_id varchar(191) NOT NULL DEFAULT '',
            read_at datetime NULL DEFAULT NULL,
            sent_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY reservation_id (reservation_id),
            KEY guest_id (guest_id),
            KEY communication_type (communication_type),
            KEY delivery_status (delivery_status),
            KEY channel (channel),
            KEY direction (direction),
            KEY thread_key (thread_key),
            KEY external_id (external_id),
            KEY from_number_e164 (from_number_e164),
            KEY to_number_e164 (to_number_e164)
        ) $charset_collate;";
        dbDelta($sql_communications);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    public static function maybeRunMigrations() {
        $installed = get_option(self::OPTION_DB_VERSION, '');

        if (!empty($installed) && version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        self::createTables();
        self::migrateCommunicationsTable($installed);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    private static function migrateCommunicationsTable($previous_version) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gms_communications';

        if (!self::tableExists($table_name)) {
            return;
        }

        $wpdb->query("UPDATE {$table_name} SET direction = 'outbound' WHERE direction IS NULL OR direction = ''");
        $wpdb->query("UPDATE {$table_name} SET channel = LOWER(communication_type) WHERE (channel = '' OR channel IS NULL) AND communication_type <> ''");
        $wpdb->query("UPDATE {$table_name} SET channel = 'sms' WHERE channel = '' OR channel IS NULL");
        $wpdb->query("UPDATE {$table_name} SET to_number = recipient WHERE channel = 'sms' AND (to_number = '' OR to_number IS NULL) AND recipient <> ''");
        $wpdb->query("UPDATE {$table_name} SET external_id = provider_reference WHERE (external_id = '' OR external_id IS NULL) AND provider_reference <> ''");

        $default_from = sanitize_text_field(get_option('gms_voipms_did', ''));
        if ($default_from !== '') {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_name} SET from_number = %s WHERE (from_number = '' OR from_number IS NULL) AND channel = %s",
                    $default_from,
                    'sms'
                )
            );
        }

        self::backfillCommunicationNumbers($table_name);
        self::backfillCommunicationThreads($table_name);
    }

    private static function backfillCommunicationNumbers($table_name) {
        global $wpdb;

        $batch_size = 200;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, from_number, to_number, from_number_e164, to_number_e164 FROM {$table_name} WHERE channel = %s AND ((from_number <> '' AND (from_number_e164 = '' OR from_number_e164 IS NULL)) OR (to_number <> '' AND (to_number_e164 = '' OR to_number_e164 IS NULL))) LIMIT %d",
                    'sms',
                    $batch_size
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $updates = array();
                $formats = array();

                if ($row['from_number'] !== '' && ($row['from_number_e164'] === '' || $row['from_number_e164'] === null)) {
                    $updates['from_number_e164'] = self::normalizePhoneNumber($row['from_number']);
                    $formats[] = '%s';
                }

                if ($row['to_number'] !== '' && ($row['to_number_e164'] === '' || $row['to_number_e164'] === null)) {
                    $updates['to_number_e164'] = self::normalizePhoneNumber($row['to_number']);
                    $formats[] = '%s';
                }

                if (!empty($updates)) {
                    $wpdb->update(
                        $table_name,
                        $updates,
                        array('id' => intval($row['id'])),
                        $formats,
                        array('%d')
                    );
                }
            }
        } while (!empty($rows));
    }

    private static function backfillCommunicationThreads($table_name) {
        global $wpdb;

        $batch_size = 200;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, reservation_id, guest_id, channel, from_number_e164, to_number_e164, thread_key FROM {$table_name} WHERE thread_key = '' OR thread_key IS NULL LIMIT %d",
                    $batch_size
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $thread_key = self::generateThreadKey(
                    sanitize_key($row['channel']),
                    intval($row['reservation_id']),
                    intval($row['guest_id']),
                    (string) $row['from_number_e164'],
                    (string) $row['to_number_e164']
                );

                if ($thread_key === '') {
                    continue;
                }

                $wpdb->update(
                    $table_name,
                    array('thread_key' => substr($thread_key, 0, 191)),
                    array('id' => intval($row['id'])),
                    array('%s'),
                    array('%d')
                );
            }
        } while (!empty($rows));
    }

    private static function tableExists($table_name) {
        global $wpdb;

        $like = $wpdb->esc_like($table_name);
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $like);
        $result = $wpdb->get_var($sql);

        return $result === $table_name;
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
            'door_code' => '',
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
            'door_code' => self::sanitizeDoorCode($data['door_code']),
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

        $formats = array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

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
            'booking_reference', 'door_code', 'checkin_date', 'checkout_date', 'status',
            'agreement_status', 'verification_status', 'portal_token', 'platform', 'webhook_data'
        );

        $update_data = array();
        $previous_status = null;
        $track_status_change = false;

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
                case 'door_code':
                    $update_data['door_code'] = self::sanitizeDoorCode($data[$field]);
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

            if ($field === 'status') {
                $track_status_change = true;
            }
        }

        if (empty($update_data)) {
            return true;
        }

        if ($track_status_change) {
            $existing = self::getReservationById($reservation_id);
            if ($existing && isset($existing['status'])) {
                $previous_status = sanitize_key($existing['status']);
            }
        }

        $update_data['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => intval($reservation_id)),
            null,
            array('%d')
        );

        if ($result === false) {
            return false;
        }

        if ($track_status_change) {
            $new_status = sanitize_key($update_data['status']);

            if ($previous_status !== $new_status) {
                do_action('gms_reservation_status_updated', $reservation_id, $new_status, $previous_status);
            }
        }

        return true;
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
            'channel' => '',
            'direction' => '',
            'from_number' => '',
            'to_number' => '',
            'thread_key' => '',
            'external_id' => '',
            'read_at' => '',
            'sent_at' => '',
        );

        $data = wp_parse_args($data, $defaults);

        $reservation_id = intval($data['reservation_id']);
        $guest_id = intval($data['guest_id']);
        $communication_type = sanitize_text_field($data['type']);

        $channel = $data['channel'] !== '' ? $data['channel'] : $communication_type;
        $channel = sanitize_key($channel);

        if ($channel === 'text') {
            $channel = 'sms';
        }

        $direction = sanitize_key($data['direction']);
        if ($direction === '') {
            $direction = 'outbound';
        }

        $from_number = sanitize_text_field($data['from_number']);
        $to_number = sanitize_text_field($data['to_number'] !== '' ? $data['to_number'] : $data['recipient']);

        if ($channel === 'sms' && $from_number === '') {
            $from_number = sanitize_text_field(get_option('gms_voipms_did', ''));
        }

        $from_number_e164 = $channel === 'sms' ? self::normalizePhoneNumber($from_number) : '';
        $to_number_e164 = $channel === 'sms' ? self::normalizePhoneNumber($to_number) : '';

        $thread_key = sanitize_text_field($data['thread_key']);
        if ($thread_key === '') {
            $thread_key = self::generateThreadKey($channel, $reservation_id, $guest_id, $from_number_e164, $to_number_e164);
        }

        $external_id = sanitize_text_field($data['external_id'] !== '' ? $data['external_id'] : $data['provider_reference']);

        $read_at = self::sanitizeNullableDateTime($data['read_at']);
        $sent_at = $data['sent_at'] !== '' ? self::sanitizeDateTime($data['sent_at']) : current_time('mysql');
        $created_at = current_time('mysql');

        $insert_data = array(
            'reservation_id' => $reservation_id,
            'guest_id' => $guest_id,
            'communication_type' => $communication_type,
            'recipient' => sanitize_text_field($data['recipient']),
            'subject' => sanitize_text_field($data['subject']),
            'message' => wp_kses_post($data['message']),
            'delivery_status' => sanitize_text_field($data['status']),
            'response_data' => self::maybeEncodeJson($data['response_data']),
            'provider_reference' => sanitize_text_field($data['provider_reference']),
            'channel' => $channel,
            'direction' => $direction,
            'from_number' => $from_number,
            'to_number' => $to_number,
            'from_number_e164' => $from_number_e164,
            'to_number_e164' => $to_number_e164,
            'thread_key' => substr($thread_key, 0, 191),
            'external_id' => $external_id,
            'read_at' => $read_at,
            'sent_at' => $sent_at,
            'created_at' => $created_at,
        );

        $formats = array(
            '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
        );

        $result = $wpdb->insert($table_name, $insert_data, $formats);

        if ($result === false) {
            return 0;
        }

        return intval($wpdb->insert_id);
    }

    public static function getCommunicationsForReservation($reservation_id, $args = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gms_communications';
        $defaults = array(
            'channel' => '',
            'direction' => '',
            'limit' => 50,
            'order' => 'DESC',
            'thread_key' => '',
            'phone' => '',
        );

        $args = wp_parse_args($args, $defaults);

        $sql = "SELECT * FROM {$table_name} WHERE reservation_id = %d";
        $params = array(intval($reservation_id));

        if ($args['channel'] !== '') {
            $sql .= " AND channel = %s";
            $params[] = sanitize_key($args['channel']);
        }

        if ($args['direction'] !== '') {
            $sql .= " AND direction = %s";
            $params[] = sanitize_key($args['direction']);
        }

        if ($args['thread_key'] !== '') {
            $sql .= " AND thread_key = %s";
            $params[] = sanitize_text_field($args['thread_key']);
        }

        if ($args['phone'] !== '') {
            $normalized = self::normalizePhoneNumber($args['phone']);
            if ($normalized !== '') {
                $sql .= " AND (from_number_e164 = %s OR to_number_e164 = %s)";
                $params[] = $normalized;
                $params[] = $normalized;
            }
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, intval($args['limit']));

        $sql .= " ORDER BY sent_at {$order}, id {$order} LIMIT %d";
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return array_map(array(__CLASS__, 'formatCommunicationRow'), $rows);
    }

    public static function communicationExists($external_id, $channel) {
        global $wpdb;

        $external_id = sanitize_text_field($external_id);
        $channel = sanitize_key($channel);

        if ($external_id === '' || $channel === '') {
            return 0;
        }

        $table_name = $wpdb->prefix . 'gms_communications';

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE external_id = %s AND channel = %s LIMIT 1",
                $external_id,
                $channel
            )
        );

        return $id ? intval($id) : 0;
    }

    public static function findReservationByPhone($phone) {
        global $wpdb;

        $normalized = self::normalizePhoneNumber($phone);
        $digits = self::normalizePhoneDigits($phone);

        if ($normalized === '' && $digits === '') {
            return null;
        }

        $table_reservations = $wpdb->prefix . 'gms_reservations';

        $conditions = array();
        $params = array();

        if ($normalized !== '') {
            $conditions[] = 'guest_phone = %s';
            $params[] = $normalized;

            $conditions[] = 'guest_phone = %s';
            $params[] = ltrim($normalized, '+');
        }

        if ($digits !== '') {
            $conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(guest_phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', '') LIKE %s";
            $params[] = '%' . $digits . '%';
        }

        if (empty($conditions)) {
            return null;
        }

        $where = implode(' OR ', array_map(static function ($condition) {
            return '(' . $condition . ')';
        }, $conditions));

        $sql = "SELECT * FROM {$table_reservations} WHERE {$where} ORDER BY updated_at DESC, checkin_date DESC LIMIT 1";

        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

        return $row ? self::formatReservationRow($row) : null;
    }

    public static function findGuestByPhone($phone) {
        global $wpdb;

        $normalized = self::normalizePhoneNumber($phone);
        $digits = self::normalizePhoneDigits($phone);

        if ($normalized === '' && $digits === '') {
            return null;
        }

        $table_guests = $wpdb->prefix . 'gms_guests';

        $conditions = array();
        $params = array();

        if ($normalized !== '') {
            $conditions[] = 'phone = %s';
            $params[] = $normalized;

            $conditions[] = 'phone = %s';
            $params[] = ltrim($normalized, '+');
        }

        if ($digits !== '') {
            $conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', '') LIKE %s";
            $params[] = '%' . $digits . '%';
        }

        if (empty($conditions)) {
            return null;
        }

        $where = implode(' OR ', array_map(static function ($condition) {
            return '(' . $condition . ')';
        }, $conditions));

        $sql = "SELECT * FROM {$table_guests} WHERE {$where} ORDER BY updated_at DESC, created_at DESC LIMIT 1";

        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

        if (!$row) {
            return null;
        }

        if (isset($row['name'])) {
            $row['name'] = trim($row['name']);
        } else {
            $row['name'] = trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''));
        }

        if (!empty($row['email']) && str_ends_with($row['email'], '@' . self::GUEST_PLACEHOLDER_DOMAIN)) {
            $row['email'] = '';
        }

        return $row;
    }

    public static function findRecentCommunicationContext($guest_number_e164, $service_number_e164, $channel = 'sms') {
        global $wpdb;

        $guest_number_e164 = self::normalizePhoneNumber($guest_number_e164);
        $service_number_e164 = self::normalizePhoneNumber($service_number_e164);
        $channel = sanitize_key($channel);

        if ($guest_number_e164 === '' || $service_number_e164 === '' || $channel === '') {
            return null;
        }

        $table = $wpdb->prefix . 'gms_communications';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT reservation_id, guest_id, thread_key FROM {$table} WHERE channel = %s AND ((from_number_e164 = %s AND to_number_e164 = %s) OR (from_number_e164 = %s AND to_number_e164 = %s)) ORDER BY sent_at DESC, id DESC LIMIT 1",
                $channel,
                $guest_number_e164,
                $service_number_e164,
                $service_number_e164,
                $guest_number_e164
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public static function resolveMessageContext($channel, $from_number, $to_number, $direction = 'inbound') {
        $channel = sanitize_key($channel);
        $direction = $direction === 'outbound' ? 'outbound' : 'inbound';

        $from_e164 = in_array($channel, array('sms', 'whatsapp'), true) ? self::normalizePhoneNumber($from_number) : '';
        $to_e164 = in_array($channel, array('sms', 'whatsapp'), true) ? self::normalizePhoneNumber($to_number) : '';

        $guest_number = $direction === 'inbound' ? $from_e164 : $to_e164;
        $service_number = $direction === 'inbound' ? $to_e164 : $from_e164;

        $reservation = null;
        $guest = null;

        if ($guest_number !== '') {
            $reservation = self::findReservationByPhone($guest_number);

            if ($reservation) {
                if (!empty($reservation['guest_record_id'])) {
                    $guest = self::get_guest_by_id($reservation['guest_record_id']);
                } elseif (!empty($reservation['guest_id'])) {
                    $guest = self::get_guest_by_id($reservation['guest_id']);
                }
            }

            if (!$guest) {
                $guest = self::findGuestByPhone($guest_number);
            }
        }

        if ((!$reservation || !$guest) && $guest_number !== '' && $service_number !== '') {
            $context = self::findRecentCommunicationContext($guest_number, $service_number, $channel);

            if ($context) {
                if (!$reservation && !empty($context['reservation_id'])) {
                    $reservation = self::getReservationById($context['reservation_id']);
                }

                if (!$guest && !empty($context['guest_id'])) {
                    $guest = self::get_guest_by_id($context['guest_id']);
                }
            }
        }

        $reservation_id = $reservation['id'] ?? 0;
        $guest_id = 0;

        if (!empty($reservation['guest_record_id'])) {
            $guest_id = intval($reservation['guest_record_id']);
        } elseif (!empty($reservation['guest_id'])) {
            $guest_id = intval($reservation['guest_id']);
        } elseif (!empty($guest['id'])) {
            $guest_id = intval($guest['id']);
        }

        $thread_key = self::generateThreadKey($channel, $reservation_id, $guest_id, $service_number, $guest_number);

        return array(
            'reservation_id' => $reservation_id,
            'guest_id' => $guest_id,
            'reservation' => $reservation,
            'guest' => $guest,
            'guest_number_e164' => $guest_number,
            'service_number_e164' => $service_number,
            'thread_key' => $thread_key,
            'matched' => ($reservation_id > 0 || $guest_id > 0)
        );
    }

    private static function normalizePhoneDigits($number) {
        $number = preg_replace('/[^0-9]/', '', (string) $number);

        if ($number === '') {
            return '';
        }

        return substr($number, -15);
    }

    public static function getCommunicationsForGuest($guest_id, $args = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gms_communications';
        $defaults = array(
            'reservation_id' => 0,
            'channel' => '',
            'direction' => '',
            'limit' => 50,
            'order' => 'DESC',
            'thread_key' => '',
            'phone' => '',
        );

        $args = wp_parse_args($args, $defaults);

        $sql = "SELECT * FROM {$table_name} WHERE guest_id = %d";
        $params = array(intval($guest_id));

        if (!empty($args['reservation_id'])) {
            $sql .= " AND reservation_id = %d";
            $params[] = intval($args['reservation_id']);
        }

        if ($args['channel'] !== '') {
            $sql .= " AND channel = %s";
            $params[] = sanitize_key($args['channel']);
        }

        if ($args['direction'] !== '') {
            $sql .= " AND direction = %s";
            $params[] = sanitize_key($args['direction']);
        }

        if ($args['thread_key'] !== '') {
            $sql .= " AND thread_key = %s";
            $params[] = sanitize_text_field($args['thread_key']);
        }

        if ($args['phone'] !== '') {
            $normalized = self::normalizePhoneNumber($args['phone']);
            if ($normalized !== '') {
                $sql .= " AND (from_number_e164 = %s OR to_number_e164 = %s)";
                $params[] = $normalized;
                $params[] = $normalized;
            }
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, intval($args['limit']));

        $sql .= " ORDER BY sent_at {$order}, id {$order} LIMIT %d";
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return array_map(array(__CLASS__, 'formatCommunicationRow'), $rows);
    }

    private static function formatCommunicationRow($row) {
        if (!is_array($row)) {
            return $row;
        }

        if (isset($row['response_data']) && $row['response_data'] !== '') {
            $decoded = self::maybeDecodeJson($row['response_data']);
            if ($decoded['decoded']) {
                $row['response_data'] = $decoded['value'];
            }
        }

        return $row;
    }

    private static function maybeDecodeJson($value) {
        if ($value === '' || (!is_string($value) && !is_numeric($value))) {
            return array(
                'value' => $value,
                'decoded' => false,
            );
        }

        if (!is_string($value)) {
            return array(
                'value' => $value,
                'decoded' => false,
            );
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return array(
                'value' => $decoded,
                'decoded' => true,
            );
        }

        return array(
            'value' => $value,
            'decoded' => false,
        );
    }

    private static function sanitizeNullableDateTime($value) {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    public static function normalizePhoneNumber($number) {
        $number = trim((string) $number);

        if ($number === '') {
            return '';
        }

        if (function_exists('gms_sanitize_phone')) {
            $number = gms_sanitize_phone($number);
        } else {
            $number = preg_replace('/[^0-9+]/', '', $number);
        }

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        }

        if (!str_starts_with($number, '+')) {
            $number = '+' . ltrim($number, '+');
        }

        $digits = preg_replace('/[^0-9]/', '', substr($number, 1));

        if ($digits === '') {
            return '';
        }

        $digits = substr($digits, 0, 15);

        return '+' . $digits;
    }

    public static function generateThreadKey($channel, $reservation_id, $guest_id, $from_number_e164, $to_number_e164) {
        $parts = array();

        $channel = sanitize_key($channel);
        if ($channel !== '') {
            $parts[] = 'channel:' . $channel;
        }

        $reservation_id = intval($reservation_id);
        if ($reservation_id > 0) {
            $parts[] = 'reservation:' . $reservation_id;
        }

        $guest_id = intval($guest_id);
        if ($guest_id > 0) {
            $parts[] = 'guest:' . $guest_id;
        }

        $numbers = array_filter(array($from_number_e164, $to_number_e164));
        if (!empty($numbers)) {
            sort($numbers);
            $parts[] = 'party:' . implode(',', $numbers);
        }

        if (empty($parts)) {
            return '';
        }

        return implode('|', $parts);
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

    public static function sanitizeDoorCode($value) {
        $value = preg_replace('/[^0-9]/', '', (string) $value);

        if ($value === '') {
            return '';
        }

        return substr($value, 0, 10);
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

        if (isset($row['door_code'])) {
            $row['door_code'] = trim($row['door_code']);
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
