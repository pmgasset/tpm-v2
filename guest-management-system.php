<?php
/**
 * File: guest-management-system.php
 * Location: /wp-content/plugins/guest-management-system/guest-management-system.php
 *
 * Plugin Name: Guest Management System
 * Plugin URI:  https://yoursite.com
 * Description: Complete guest management system for short-term rentals with webhook integration, identity verification, and agreement signing.
 * Version:     1.1.0
 * Author:      Your Company
 * License:     GPL v2 or later
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GMS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('GMS_VERSION', '1.1.0');

class GuestManagementSystem {

    private static $instance = null;
    private $portal_request = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->loadIncludes();
        
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Initialize all plugin components
        new GMS_Database();
        new GMS_Admin();
        new GMS_Webhook_Handler();
        new GMS_Guest_Portal();
        new GMS_Email_Handler();
        new GMS_SMS_Handler();
        new GMS_Stripe_Integration();
        new GMS_Agreement_Handler(); // Initialize the new agreement handler

        GMS_Database::maybeScheduleGuestBackfill();

        // Add custom user role for guests
        $this->addGuestRole();
        
        // Enqueue scripts and styles for the front-end and admin areas
        add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        
        // Add custom rewrite rules for the guest portal
        add_action('init', array($this, 'addRewriteRules'));
        add_filter('query_vars', array($this, 'addQueryVars'));
        add_action('template_redirect', array($this, 'handleGuestPortal'));
    }
    
    private function loadIncludes() {
        $includes = array(
            'class-database.php',
            'class-admin.php',
            'class-webhook-handler.php',
            'class-guest-portal.php',
            'class-email-handler.php',
            'class-sms-handler.php',
            'class-stripe-integration.php',
            'class-agreement-handler.php', // Load the new agreement handler class
            'functions.php'
        );
        
        foreach ($includes as $file) {
            $filepath = GMS_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            } else {
                error_log('GMS Error: Missing required file - ' . $filepath);
            }
        }
    }
    
    public function activate() {
        // Improved: Use an admin notice for a more graceful activation failure
        if (!class_exists('GMS_Database')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Guest Management System Error: GMS_Database class not found. The plugin could not be activated. Please ensure all plugin files are uploaded correctly.</p></div>';
            });
            return;
        }
        
        // Create database tables on activation
        GMS_Database::createTables();
        
        // Add rewrite rules and flush them to avoid 404 errors
        $this->addRewriteRules();
        flush_rewrite_rules();
        
        // Set up default options on first activation
        $default_options = array(
            'gms_stripe_pk' => '',
            'gms_stripe_sk' => '',
            'gms_voipms_user' => '',
            'gms_voipms_pass' => '',
            'gms_voipms_did' => '',
            'gms_email_from' => get_option('admin_email'),
            'gms_email_from_name' => get_option('blogname'),
            'gms_agreement_template' => $this->getDefaultAgreementTemplate(),
            'gms_email_template' => $this->getDefaultEmailTemplate(),
            'gms_sms_template' => $this->getDefaultSMSTemplate(),
            'gms_approved_email_template' => $this->getDefaultApprovedEmailTemplate(),
            'gms_approved_sms_template' => $this->getDefaultApprovedSMSTemplate()
        );
        
        foreach ($default_options as $key => $value) {
            if (!get_option($key)) {
                add_option($key, $value);
            }
        }
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function addGuestRole() {
        if (!get_role('guest')) {
            add_role('guest', 'Guest', array(
                'read' => true,
            ));
        }
    }
    
    public function addRewriteRules() {
        add_rewrite_rule(
            '^guest-portal/([^/]+)/?$',
            'index.php?guest_portal=1&guest_token=$matches[1]',
            'top'
        );
    }
    
    public function addQueryVars($vars) {
        $vars[] = 'guest_portal';
        $vars[] = 'guest_token';
        return $vars;
    }
    
    public function handleGuestPortal() {
        $portal_context = $this->resolvePortalRequest();

        if (!$portal_context['is_portal']) {
            return;
        }

        if (function_exists('status_header')) {
            status_header(200);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        global $wp_query;
        if (is_object($wp_query)) {
            $wp_query->is_404 = false;
            $wp_query->is_home = false;
            $wp_query->is_page = false;
        }

        $token = $portal_context['token'];

        GMS_Guest_Portal::displayPortal($token);
        exit;
    }

    public function enqueueScripts() {
        $portal_context = $this->resolvePortalRequest();

        if (!$portal_context['is_portal']) {
            return;
        }

        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        wp_enqueue_script('gms-guest-portal', GMS_PLUGIN_URL . 'assets/js/guest-portal.js', ['jquery', 'stripe-js'], GMS_VERSION, true);
        wp_enqueue_style('gms-guest-portal', GMS_PLUGIN_URL . 'assets/css/guest-portal.css', [], GMS_VERSION);

        $reservation = GMS_Database::getReservationByToken($portal_context['token']);

        // Pass data from PHP to JavaScript securely
        wp_localize_script('gms-guest-portal', 'gmsConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gms_guest_nonce'),
            'stripeKey' => get_option('gms_stripe_pk'),
            'reservationId' => $reservation ? $reservation['id'] : 0
        ));
    }
    
    public function enqueueAdminScripts($hook) {
        // Only load admin scripts on our plugin's pages
        if (strpos($hook, 'guest-management') === false) {
            return;
        }
        wp_enqueue_script('gms-admin', GMS_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], GMS_VERSION, true);
        wp_enqueue_style('gms-admin', GMS_PLUGIN_URL . 'assets/css/admin.css', [], GMS_VERSION);
        wp_enqueue_media(); // For handling media uploads in settings (e.g., logo)

        $webhook_base = untrailingslashit(home_url('/webhook'));
        $webhook_urls = function_exists('gms_get_webhook_urls') ? gms_get_webhook_urls() : [];

        wp_localize_script(
            'gms-admin',
            'gmsAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'gms_admin_nonce' => wp_create_nonce('gms_admin_nonce'),
                'gms_webhook_url' => $webhook_base,
                'webhookUrls' => $webhook_urls,
                'strings' => [
                    'copySuccess' => __('Portal link copied to clipboard.', 'guest-management-system'),
                    'copyError' => __('Unable to copy the portal link.', 'guest-management-system'),
                    'guestName' => __('Guest Name', 'guest-management-system'),
                    'guestEmail' => __('Guest Email', 'guest-management-system'),
                    'guestPhone' => __('Guest Phone', 'guest-management-system'),
                    'propertyName' => __('Property Name', 'guest-management-system'),
                    'bookingReference' => __('Booking Reference', 'guest-management-system'),
                    'checkinDate' => __('Check-in Date', 'guest-management-system'),
                    'checkoutDate' => __('Check-out Date', 'guest-management-system'),
                    'statusLabel' => __('Status', 'guest-management-system'),
                    'ajaxUnavailable' => __('Unable to communicate with the server.', 'guest-management-system'),


                ],
            ]
        );
    }
    
    private function getDefaultAgreementTemplate() {
        return 'By signing below, I agree to abide by all property rules and regulations, understand that I am responsible for any damages, and confirm that all information provided is accurate.';
    }
    
    private function getDefaultEmailTemplate() {
        return "Hi {guest_name},\n\nWelcome to {property_name}!\n\nTo complete your check-in process, please visit your guest portal: {portal_link}\n\nYou will need to:\n1. Sign the guest agreement\n2. Complete identity verification\n\nCheck-in: {checkin_date}\n\nWe look forward to hosting you!";
    }
    
    private function getDefaultSMSTemplate() {
        return 'Hi {guest_name}! Complete your check-in for {property_name} at {portal_link}. Identity verification and agreement signing are required.';
    }

    private function getDefaultApprovedEmailTemplate() {
        return "Hi {guest_name},\n\nGreat news—your reservation for {property_name} has been approved!\n\nYou can now access your guest portal to complete the remaining steps for your stay:\n• Review and sign your guest agreement\n• Upload your identification for verification\n\nGuest Portal: {portal_link}\nCheck-in: {checkin_date} at {checkin_time}\nCheck-out: {checkout_date} at {checkout_time}\nBooking Reference: {booking_reference}\n\nIf you have any questions, reply to this email and our team will be happy to help.\n\nWe look forward to welcoming you,\n{company_name}";
    }

    private function getDefaultApprovedSMSTemplate() {
        return 'Your stay at {property_name} is approved! Finish your check-in tasks here: {portal_link} - {company_name}';
    }

    private function resolvePortalRequest() {
        if ($this->portal_request !== null) {
            return $this->portal_request;
        }

        $is_portal = false;
        $token = '';

        $query_flag = get_query_var('guest_portal');
        if (!empty($query_flag)) {
            $token = (string) get_query_var('guest_token');
            if ($token !== '') {
                $is_portal = true;
            }
        }

        if (!$is_portal && isset($_GET['guest_portal'])) {
            $raw_flag = strtolower(trim(wp_unslash($_GET['guest_portal'])));
            $truthy_flags = array('1', 'true', 'yes', 'on');

            if (in_array($raw_flag, $truthy_flags, true)) {
                $token = isset($_GET['guest_token']) ? wp_unslash($_GET['guest_token']) : '';
                if ($token !== '') {
                    $is_portal = true;
                }
            }
        }

        if (!$is_portal && isset($_SERVER['REQUEST_URI'])) {
            $extracted = $this->extractPortalTokenFromPath(wp_unslash($_SERVER['REQUEST_URI']));
            if ($extracted !== '') {
                $token = $extracted;
                $is_portal = true;
            }
        }

        if ($is_portal) {
            $token = sanitize_text_field($token);

            if ($token === '') {
                $is_portal = false;
            }
        }

        if ($is_portal && function_exists('set_query_var')) {
            set_query_var('guest_portal', 1);
            set_query_var('guest_token', $token);
        }

        $this->portal_request = array(
            'is_portal' => $is_portal,
            'token' => $is_portal ? $token : ''
        );

        return $this->portal_request;
    }

    private function extractPortalTokenFromPath($request_uri) {
        if ($request_uri === '') {
            return '';
        }

        $parsed_request = wp_parse_url($request_uri);
        $path = isset($parsed_request['path']) ? trim($parsed_request['path'], '/') : '';

        if ($path === '') {
            return '';
        }

        $home_path = '';
        $home_parts = wp_parse_url(home_url('/'));
        if ($home_parts && !empty($home_parts['path'])) {
            $home_path = trim($home_parts['path'], '/');
        }

        if ($home_path !== '' && strpos($path, $home_path) === 0) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        if ($path === '') {
            return '';
        }

        $prefix = 'guest-portal/';

        if (strpos($path, $prefix) !== 0) {
            return '';
        }

        $token = substr($path, strlen($prefix));
        $token = trim($token, '/');

        if ($token === '') {
            return '';
        }

        if (strpos($token, '/') !== false) {
            return '';
        }

        return rawurldecode($token);
    }
}

// Initialize the plugin
GuestManagementSystem::getInstance();
