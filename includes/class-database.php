<?php
/**
 * File: class-database.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-database.php
 * * Handles all database interactions for the Guest Management System
 */

if (!defined('ABSPATH')) {
    exit;
}

class GMS_Database {
    
    public function __construct() {
        // Constructor
    }
    
    public static function createTables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Table for Reservations
        $table_reservations = $wpdb->prefix . 'gms_reservations';
        $sql_reservations = "CREATE TABLE $table_reservations (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            guest_id mediumint(9) NOT NULL,
            property_id mediumint(9) NOT NULL,
            property_name varchar(255) DEFAULT '' NOT NULL,
            platform varchar(50) DEFAULT '' NOT NULL,
            booking_reference varchar(100) DEFAULT '' NOT NULL,
            checkin_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            checkout_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            num_guests tinyint(4) DEFAULT 1 NOT NULL,
            total_payout decimal(10,2) DEFAULT 0.00 NOT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            portal_token varchar(64) DEFAULT '' NOT NULL,
            verification_status varchar(20) DEFAULT 'not_started' NOT NULL,
            agreement_status varchar(20) DEFAULT 'not_signed' NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            KEY guest_id (guest_id),
            KEY property_id (property_id),
            KEY portal_token (portal_token)
        ) $charset_collate;";
        dbDelta($sql_reservations);

        // Table for Guests
        $table_guests = $wpdb->prefix . 'gms_guests';
        $sql_guests = "CREATE TABLE $table_guests (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            first_name varchar(100) DEFAULT '' NOT NULL,
            last_name varchar(100) DEFAULT '' NOT NULL,
            email varchar(100) DEFAULT '' NOT NULL,
            phone varchar(20) DEFAULT '' NOT NULL,
            address varchar(255) DEFAULT '' NOT NULL,
            city varchar(100) DEFAULT '' NOT NULL,
            state varchar(100) DEFAULT '' NOT NULL,
            zip varchar(20) DEFAULT '' NOT NULL,
            country varchar(100) DEFAULT '' NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        ) $charset_collate;";
        dbDelta($sql_guests);
        
        // Table for Properties
        $table_properties = $wpdb->prefix . 'gms_properties';
        $sql_properties = "CREATE TABLE $table_properties (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) DEFAULT '' NOT NULL,
            address varchar(255) DEFAULT '' NOT NULL,
            platform_id varchar(100) DEFAULT '' NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql_properties);

        // Table for Communications Log
        $table_comms_log = $wpdb->prefix . 'gms_communications_log';
        $sql_comms_log = "CREATE TABLE $table_comms_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            reservation_id mediumint(9) NOT NULL,
            guest_id mediumint(9) NOT NULL,
            type varchar(10) DEFAULT '' NOT NULL, -- 'email' or 'sms'
            recipient varchar(255) DEFAULT '' NOT NULL,
            subject varchar(255) DEFAULT '' NOT NULL,
            message text NOT NULL,
            status varchar(20) DEFAULT 'sent' NOT NULL, -- 'sent', 'failed', 'delivered'
            response_data text,
            sent_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            KEY reservation_id (reservation_id)
        ) $charset_collate;";
        dbDelta($sql_comms_log);

        // Table for ID Verifications
        $table_verifications = $wpdb->prefix . 'gms_verifications';
        $sql_verifications = "CREATE TABLE $table_verifications (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            reservation_id mediumint(9) NOT NULL,
            guest_id mediumint(9) NOT NULL,
            stripe_session_id varchar(255) DEFAULT '' NOT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL, -- 'pending', 'verified', 'failed'
            verification_data text,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            KEY reservation_id (reservation_id),
            UNIQUE KEY stripe_session_id (stripe_session_id)
        ) $charset_collate;";
        dbDelta($sql_verifications);

        // Table for Agreements
        $table_agreements = $wpdb->prefix . 'gms_agreements';
        $sql_agreements = "CREATE TABLE $table_agreements (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            reservation_id mediumint(9) NOT NULL,
            guest_id mediumint(9) NOT NULL,
            agreement_text text NOT NULL,
            signature text NOT NULL, -- Can be base64 encoded image or JSON from a signature pad
            ip_address varchar(45) DEFAULT '' NOT NULL,
            user_agent varchar(255) DEFAULT '' NOT NULL,
            signed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_id (reservation_id)
        ) $charset_collate;";
        dbDelta($sql_agreements);
    }
    
    public static function getReservationByToken($token) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE portal_token = %s", $token), ARRAY_A);
    }

    public static function getReservationById($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id), ARRAY_A);
    }

    public static function updateReservationStatus($id, $status) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        return $wpdb->update(
            $table_name,
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('id' => $id)
        );
    }

    public static function updateVerification($session_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_verifications';
        $data['updated_at'] = current_time('mysql');

        if (isset($data['verification_data']) && is_array($data['verification_data'])) {
            $data['verification_data'] = wp_json_encode($data['verification_data']);
        }

        return $wpdb->update(
            $table_name,
            $data,
            array('stripe_session_id' => $session_id)
        );
    }

    public static function logCommunication($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_communications_log';
        
        $defaults = array(
            'reservation_id' => 0,
            'guest_id' => 0,
            'type' => '',
            'recipient' => '',
            'subject' => '',
            'message' => '',
            'status' => 'sent',
            'response_data' => '',
            'sent_at' => current_time('mysql')
        );
        $data = wp_parse_args($data, $defaults);

        if (is_array($data['response_data'])) {
            $data['response_data'] = wp_json_encode($data['response_data']);
        }

        return $wpdb->insert($table_name, $data);
    }

    public static function updateReservation($id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update($table_name, $data, array('id' => $id));
    }

    /**
     * NEW FUNCTION
     * Fetches all reservations with guest names for the admin list table.
     */
    public static function get_all_reservations_for_admin() {
        global $wpdb;
        $reservations_table = $wpdb->prefix . 'gms_reservations';
        $guests_table = $wpdb->prefix . 'gms_guests';
        
        $query = "
            SELECT r.*, g.first_name, g.last_name, CONCAT(g.first_name, ' ', g.last_name) AS guest_name
            FROM {$reservations_table} r
            LEFT JOIN {$guests_table} g ON r.guest_id = g.id
            ORDER BY r.checkin_date DESC
        ";
        
        $results = $wpdb->get_results($query, ARRAY_A);
        return is_array($results) ? $results : array();
    }

    /**
     * NEW FUNCTION
     * Fetches all guests with their booking counts for the admin list table.
     */
    public static function get_all_guests_for_admin() {
        global $wpdb;
        $guests_table = $wpdb->prefix . 'gms_guests';
        $reservations_table = $wpdb->prefix . 'gms_reservations';
        
        $query = "
            SELECT g.id, g.first_name, g.last_name, g.email, g.phone, COUNT(r.id) as total_bookings
            FROM {$guests_table} g
            LEFT JOIN {$reservations_table} r ON g.id = r.guest_id
            GROUP BY g.id
            ORDER BY g.last_name, g.first_name
        ";
        
        $results = $wpdb->get_results($query, ARRAY_A);
        return is_array($results) ? $results : array();
    }
}
