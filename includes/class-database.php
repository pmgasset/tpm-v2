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
        
        // (Other table creation code remains the same...)
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

    // (Other existing functions remain the same...)

    /**
     * FIX: Add missing functions to fetch data for admin tables.
     */
    public static function get_reservations($per_page = 20, $current_page = 1) {
        global $wpdb;
        $table_reservations = $wpdb->prefix . 'gms_reservations';
        $table_guests = $wpdb->prefix . 'gms_guests';
        $offset = ($current_page - 1) * $per_page;
        
        $query = $wpdb->prepare(
            "SELECT r.*, CONCAT(g.first_name, ' ', g.last_name) AS guest_name 
            FROM {$table_reservations} r
            LEFT JOIN {$table_guests} g ON r.guest_id = g.id
            ORDER BY r.checkin_date DESC 
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        return is_array($results) ? $results : array();
    }

    public static function get_all_guests() {
        global $wpdb;
        $guests_table = $wpdb->prefix . 'gms_guests';
        $reservations_table = $wpdb->prefix . 'gms_reservations';
        
        $query = "
            SELECT g.*, COUNT(r.id) as total_bookings
            FROM {$guests_table} g
            LEFT JOIN {$reservations_table} r ON g.id = r.guest_id
            GROUP BY g.id
            ORDER BY g.last_name, g.first_name
        ";
        $results = $wpdb->get_results($query, ARRAY_A);
        return is_array($results) ? $results : array();
    }
    
    public static function get_record_count($type = 'reservations') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gms_reservations';
        if ($type === 'guests') {
            $table_name = $wpdb->prefix . 'gms_guests';
        }
        return (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}");
    }
}
