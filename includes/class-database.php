<?php
/**
 * File: class-database.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-database.php
 * 
 * Database Handler for Guest Management System - WITH PDF SUPPORT
 */

class GMS_Database {
    
    public function __construct() {
        // Database initialization happens during plugin activation
    }
    
    public static function createTables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Reservations table
        $reservations_table = $wpdb->prefix . 'gms_reservations';
        $reservations_sql = "CREATE TABLE $reservations_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            booking_reference varchar(100) NOT NULL,
            platform varchar(50) NOT NULL,
            guest_id int(11) NOT NULL,
            property_id varchar(100),
            property_name varchar(255),
            guest_name varchar(255) NOT NULL,
            guest_email varchar(255) NOT NULL,
            guest_phone varchar(50),
            checkin_date datetime NOT NULL,
            checkout_date datetime NOT NULL,
            guests_count int(5) DEFAULT 1,
            total_amount decimal(10,2),
            currency varchar(10) DEFAULT 'USD',
            status varchar(50) DEFAULT 'pending',
            portal_token varchar(100) UNIQUE,
            webhook_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_reference (booking_reference),
            KEY guest_id (guest_id),
            KEY portal_token (portal_token),
            KEY status (status)
        ) $charset_collate;";
        
        // Guest agreements table - WITH PDF FIELDS
        $agreements_table = $wpdb->prefix . 'gms_guest_agreements';
        $agreements_sql = "CREATE TABLE $agreements_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            reservation_id int(11) NOT NULL,
            guest_id int(11) NOT NULL,
            agreement_text longtext,
            signature_data longtext,
            ip_address varchar(45),
            user_agent text,
            signed_at datetime,
            status varchar(20) DEFAULT 'pending',
            pdf_url varchar(500) DEFAULT NULL,
            pdf_attachment_id int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY reservation_id (reservation_id),
            KEY guest_id (guest_id),
            KEY status (status),
            KEY pdf_attachment_id (pdf_attachment_id)
        ) $charset_collate;";
        
        // Identity verification table
        $verification_table = $wpdb->prefix . 'gms_identity_verification';
        $verification_sql = "CREATE TABLE $verification_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            reservation_id int(11) NOT NULL,
            guest_id int(11) NOT NULL,
            stripe_verification_session_id varchar(255),
            verification_status varchar(50) DEFAULT 'pending',
            verification_data longtext,
            verified_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY reservation_id (reservation_id),
            KEY guest_id (guest_id),
            KEY verification_status (verification_status),
            KEY stripe_verification_session_id (stripe_verification_session_id)
        ) $charset_collate;";
        
        // Communication log table
        $communications_table = $wpdb->prefix . 'gms_communications';
        $communications_sql = "CREATE TABLE $communications_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            reservation_id int(11) NOT NULL,
            guest_id int(11) NOT NULL,
            type varchar(20) NOT NULL,
            recipient varchar(255) NOT NULL,
            subject varchar(255),
            message longtext,
            status varchar(20) DEFAULT 'pending',
            response_data text,
            sent_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY reservation_id (reservation_id),
            KEY guest_id (guest_id),
            KEY type (type),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($reservations_sql);
        dbDelta($agreements_sql);
        dbDelta($verification_sql);
        dbDelta($communications_sql);
        
        // Update database version
        update_option('gms_db_version', '1.1.0');
    }
    
    /**
     * Update database schema for existing installations
     * Adds PDF fields to agreements table if they don't exist
     */
    public static function updateSchema() {
        global $wpdb;
        
        $agreements_table = $wpdb->prefix . 'gms_guest_agreements';
        
        // Check if PDF fields exist
        $pdf_url_exists = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM `%s` LIKE 'pdf_url'", $agreements_table)
        );
        
        $pdf_attachment_exists = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM `%s` LIKE 'pdf_attachment_id'", $agreements_table)
        );
        
        // Add pdf_url if it doesn't exist
        if (empty($pdf_url_exists)) {
            $wpdb->query(
                "ALTER TABLE `{$agreements_table}` 
                ADD COLUMN `pdf_url` varchar(500) DEFAULT NULL AFTER `status`"
            );
        }
        
        // Add pdf_attachment_id if it doesn't exist
        if (empty($pdf_attachment_exists)) {
            $wpdb->query(
                "ALTER TABLE `{$agreements_table}` 
                ADD COLUMN `pdf_attachment_id` int(11) DEFAULT NULL AFTER `pdf_url`,
                ADD KEY `pdf_attachment_id` (`pdf_attachment_id`)"
            );
        }
        
        // Update database version
        update_option('gms_db_version', '1.1.0');
        
        return true;
    }
    
    // Reservation methods
    public static function createReservation($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_reservations';
        
        $reservation_data = array(
            'booking_reference' => sanitize_text_field($data['booking_reference']),
            'platform' => sanitize_text_field($data['platform']),
            'guest_id' => intval($data['guest_id']),
            'property_id' => sanitize_text_field($data['property_id'] ?? ''),
            'property_name' => sanitize_text_field($data['property_name'] ?? ''),
            'guest_name' => sanitize_text_field($data['guest_name']),
            'guest_email' => sanitize_email($data['guest_email']),
            'guest_phone' => sanitize_text_field($data['guest_phone'] ?? ''),
            'checkin_date' => $data['checkin_date'],
            'checkout_date' => $data['checkout_date'],
            'guests_count' => intval($data['guests_count'] ?? 1),
            'total_amount' => floatval($data['total_amount'] ?? 0),
            'currency' => sanitize_text_field($data['currency'] ?? 'USD'),
            'status' => 'pending',
            'portal_token' => self::generatePortalToken(),
            'webhook_data' => maybe_serialize($data['webhook_data'] ?? array())
        );
        
        $inserted = $wpdb->insert($table, $reservation_data);
        
        if ($inserted) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    public static function getReservationByToken($token) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_reservations';
        
        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE portal_token = %s",
            $token
        ), ARRAY_A);
        
        if ($reservation) {
            $reservation['webhook_data'] = maybe_unserialize($reservation['webhook_data']);
            return $reservation;
        }
        
        return null;
    }
    
    public static function getReservationById($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_reservations';
        
        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);
        
        if ($reservation) {
            $reservation['webhook_data'] = maybe_unserialize($reservation['webhook_data']);
            return $reservation;
        }
        
        return null;
    }
    
    public static function updateReservationStatus($id, $status) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_reservations';
        
        return $wpdb->update(
            $table,
            array('status' => sanitize_text_field($status)),
            array('id' => intval($id))
        );
    }
    
    // Agreement methods
    public static function createAgreement($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_guest_agreements';
        
        $agreement_data = array(
            'reservation_id' => intval($data['reservation_id']),
            'guest_id' => intval($data['guest_id']),
            'agreement_text' => wp_kses_post($data['agreement_text']),
            'signature_data' => sanitize_textarea_field($data['signature_data']),
            'ip_address' => self::getUserIP(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'signed_at' => current_time('mysql'),
            'status' => 'signed'
        );
        
        $inserted = $wpdb->insert($table, $agreement_data);
        
        if ($inserted) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    public static function getAgreementByReservation($reservation_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_guest_agreements';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE reservation_id = %d ORDER BY id DESC LIMIT 1",
            $reservation_id
        ), ARRAY_A);
    }
    
    public static function getAgreementById($agreement_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_guest_agreements';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $agreement_id
        ), ARRAY_A);
    }
    
    /**
     * Update agreement with PDF information
     * Called after PDF is generated
     */
    public static function updateAgreementPDF($agreement_id, $pdf_url, $attachment_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_guest_agreements';
        
        return $wpdb->update(
            $table,
            array(
                'pdf_url' => esc_url_raw($pdf_url),
                'pdf_attachment_id' => intval($attachment_id)
            ),
            array('id' => intval($agreement_id)),
            array('%s', '%d'),
            array('%d')
        );
    }
    
    // Identity verification methods
    public static function createVerification($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_identity_verification';
        
        $verification_data = array(
            'reservation_id' => intval($data['reservation_id']),
            'guest_id' => intval($data['guest_id']),
            'stripe_verification_session_id' => sanitize_text_field($data['stripe_session_id']),
            'verification_status' => sanitize_text_field($data['status'] ?? 'pending')
        );
        
        $inserted = $wpdb->insert($table, $verification_data);
        
        if ($inserted) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    public static function updateVerification($session_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_identity_verification';
        
        $update_data = array(
            'verification_status' => sanitize_text_field($data['status']),
            'verification_data' => maybe_serialize($data['verification_data'] ?? array())
        );
        
        if ($data['status'] === 'verified') {
            $update_data['verified_at'] = current_time('mysql');
        }
        
        return $wpdb->update(
            $table,
            $update_data,
            array('stripe_verification_session_id' => sanitize_text_field($session_id))
        );
    }
    
    public static function getVerificationByReservation($reservation_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_identity_verification';
        
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE reservation_id = %d ORDER BY id DESC LIMIT 1",
            $reservation_id
        ), ARRAY_A);
        
        if ($verification && $verification['verification_data']) {
            $verification['verification_data'] = maybe_unserialize($verification['verification_data']);
        }
        
        return $verification;
    }
    
    // Communication methods
    public static function logCommunication($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_communications';
        
        $comm_data = array(
            'reservation_id' => intval($data['reservation_id']),
            'guest_id' => intval($data['guest_id']),
            'type' => sanitize_text_field($data['type']),
            'recipient' => sanitize_text_field($data['recipient']),
            'subject' => sanitize_text_field($data['subject'] ?? ''),
            'message' => sanitize_textarea_field($data['message']),
            'status' => sanitize_text_field($data['status'] ?? 'sent'),
            'response_data' => maybe_serialize($data['response_data'] ?? array()),
            'sent_at' => current_time('mysql')
        );
        
        $inserted = $wpdb->insert($table, $comm_data);
        
        if ($inserted) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    // Utility methods
    public static function generatePortalToken() {
        return bin2hex(random_bytes(16)) . '_' . time();
    }
    
    public static function getUserIP() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    // Admin dashboard methods
    public static function getReservationStats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gms_reservations';
        
        $stats = array();
        
        // Total reservations
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        // Pending check-ins
        $stats['pending'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = %s",
            'pending'
        ));
        
        // Completed check-ins
        $stats['completed'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = %s",
            'completed'
        ));
        
        // Recent reservations (last 30 days)
        $stats['recent'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        return $stats;
    }
}