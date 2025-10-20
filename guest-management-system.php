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
    private $guest_profile_request = null;
    private $housekeeper_request = null;
    
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
        new GMS_Roku_Integration();
        new GMS_Agreement_Handler(); // Initialize the new agreement handler
        new GMS_AJAX_Handler();
        GMS_Housekeeper_Checklist_View::init();

        GMS_Database::maybeScheduleGuestBackfill();

        // Add custom user role for guests
        $this->addGuestRole();
        
        // Enqueue scripts and styles for the front-end and admin areas
        add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        
        // Add custom rewrite rules for the guest portal
        add_action('init', array($this, 'addRewriteRules'));
        add_filter('query_vars', array($this, 'addQueryVars'));
        add_action('template_redirect', array($this, 'handleGuestPortal'), 0);
        add_filter('pre_handle_404', array($this, 'preHandlePortal404'), 0, 2);
    }
    
    private function loadIncludes() {
        $includes = array(
            'class-database.php',
            'class-admin.php',
            'class-messaging-channel-interface.php',
            'class-webhook-handler.php',
            'class-guest-portal.php',
            'class-guest-profile-view.php',
            'class-email-handler.php',
            'class-sms-handler.php',
            'class-ota-reservation-sync.php',
            'class-ota-messaging-handler.php',
            'class-roku-integration.php',
            'class-stripe-integration.php',
            'class-ajax-handler.php',
            'class-agreement-handler.php', // Load the new agreement handler class
            'class-housekeeper-checklist-view.php',
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
            'gms_airbnb_access_token' => '',
            'gms_vrbo_access_token' => '',
            'gms_booking_access_token' => '',
            'gms_email_from' => get_option('admin_email'),
            'gms_email_from_name' => get_option('blogname'),
            'gms_agreement_template' => $this->getDefaultAgreementTemplate(),
            'gms_email_template' => $this->getDefaultEmailTemplate(),
            'gms_sms_template' => $this->getDefaultSMSTemplate(),
            'gms_approved_email_template' => $this->getDefaultApprovedEmailTemplate(),
            'gms_approved_sms_template' => $this->getDefaultApprovedSMSTemplate(),
            'gms_roku_api_token' => wp_generate_password(32, false, false),
            'gms_roku_media_tag_prefix' => 'roku'
        );
        
        foreach ($default_options as $key => $value) {
            if (!get_option($key)) {
                add_option($key, $value);
            }
        }

        GMS_Database::recalculateAllCommunicationContexts();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('gms_sync_provider_messages');
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

        add_rewrite_rule(
            '^guest-profile/([^/]+)/?$',
            'index.php?guest_profile=1&guest_profile_token=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^housekeeper/([^/]+)/?$',
            'index.php?gms_housekeeper=1&gms_housekeeper_token=$matches[1]',
            'top'
        );
    }

    public function addQueryVars($vars) {
        $vars[] = 'guest_portal';
        $vars[] = 'guest_token';
        $vars[] = 'guest_profile';
        $vars[] = 'guest_profile_token';
        $vars[] = 'gms_housekeeper';
        $vars[] = 'gms_housekeeper_token';
        return $vars;
    }
    
    public function handleGuestPortal() {
        $portal_context = $this->resolvePortalRequest();

        if ($portal_context['is_portal']) {
            $this->synchronizePortalQueryState($portal_context['token']);

            if (function_exists('status_header')) {
                status_header(200);
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            GMS_Guest_Portal::displayPortal($portal_context['token']);
            exit;
        }

        $profile_context = $this->resolveGuestProfileRequest();

        if ($profile_context['is_profile']) {
            $this->synchronizeGuestProfileQueryState($profile_context['token']);

            if (function_exists('status_header')) {
                status_header(200);
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            GMS_Guest_Profile_View::displayProfile($profile_context['token']);
            exit;
        }

        $housekeeper_context = $this->resolveHousekeeperRequest();

        if (!$housekeeper_context['is_housekeeper']) {
            return;
        }

        $this->synchronizeHousekeeperQueryState($housekeeper_context['token']);

        if (function_exists('status_header')) {
            status_header(200);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        GMS_Housekeeper_Checklist_View::displayChecklist($housekeeper_context['token']);
        exit;
    }

    public function preHandlePortal404($preempt, $wp_query) {
        $portal_context = $this->resolvePortalRequest();

        if ($portal_context['is_portal']) {
            $this->synchronizePortalQueryState($portal_context['token'], $wp_query);

            add_filter('redirect_canonical', '__return_false', 10, 2);

            if (function_exists('status_header')) {
                status_header(200);
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            return false;
        }

        $profile_context = $this->resolveGuestProfileRequest();

        if ($profile_context['is_profile']) {
            $this->synchronizeGuestProfileQueryState($profile_context['token'], $wp_query);

            add_filter('redirect_canonical', '__return_false', 10, 2);

            if (function_exists('status_header')) {
                status_header(200);
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            return false;
        }

        $housekeeper_context = $this->resolveHousekeeperRequest();

        if (!$housekeeper_context['is_housekeeper']) {
            return $preempt;
        }

        $this->synchronizeHousekeeperQueryState($housekeeper_context['token'], $wp_query);

        add_filter('redirect_canonical', '__return_false', 10, 2);

        if (function_exists('status_header')) {
            status_header(200);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        return false;
    }

    public function enqueueScripts() {
        $portal_context = $this->resolvePortalRequest();

        if ($portal_context['is_portal']) {
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

            return;
        }

        $housekeeper_context = $this->resolveHousekeeperRequest();

        if ($housekeeper_context['is_housekeeper']) {
            GMS_Housekeeper_Checklist_View::enqueueAssets($housekeeper_context['token']);
        }
    }
    
    public function enqueueAdminScripts($hook) {
        // Only load admin scripts on our plugin's pages
        if (strpos($hook, 'guest-management') === false) {
            return;
        }

        $asset_base_path = plugin_dir_path(__FILE__);

        $admin_script_path = $asset_base_path . 'assets/js/admin.js';
        $admin_style_path  = $asset_base_path . 'assets/css/admin.css';

        $admin_script_version = file_exists($admin_script_path) ? filemtime($admin_script_path) : GMS_VERSION;
        $admin_style_version  = file_exists($admin_style_path) ? filemtime($admin_style_path) : GMS_VERSION;

        wp_enqueue_script('gms-admin', GMS_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], $admin_script_version, true);
        wp_enqueue_style('gms-admin', GMS_PLUGIN_URL . 'assets/css/admin.css', [], $admin_style_version);
        wp_enqueue_media(); // For handling media uploads in settings (e.g., logo)

        $is_messaging_screen = $hook === 'guest-management_page_guest-management-messaging';
        $is_logs_screen = $hook === 'guest-management_page_guest-management-logs';
        $is_housekeeper_screen = $hook === 'guest-management_page_guest-management-housekeeper';

        if ($is_housekeeper_screen) {
            if (function_exists('add_thickbox')) {
                add_thickbox();
            } else {
                wp_enqueue_style('thickbox');
                wp_enqueue_script('thickbox');
            }
        }

        if ($is_messaging_screen) {
            $messaging_style_path = $asset_base_path . 'assets/css/messaging.css';
            $messaging_script_path = $asset_base_path . 'assets/js/messaging.js';
            $messaging_style_version = file_exists($messaging_style_path) ? filemtime($messaging_style_path) : GMS_VERSION;
            $messaging_script_version = file_exists($messaging_script_path) ? filemtime($messaging_script_path) : GMS_VERSION;

            $channels = array('sms', 'email');
            $channel_labels = array(
                'sms'   => __('SMS Chat', 'guest-management-system'),
                'email' => __('Email History', 'guest-management-system'),
            );

            wp_enqueue_style('gms-messaging', GMS_PLUGIN_URL . 'assets/css/messaging.css', [], $messaging_style_version);
            wp_enqueue_script('gms-messaging', GMS_PLUGIN_URL . 'assets/js/messaging.js', [], $messaging_script_version, true);

            wp_localize_script(
                'gms-messaging',
                'gmsMessaging',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('gms_messaging_nonce'),
                    'refreshInterval' => apply_filters('gms_messaging_refresh_interval', 20000),
                    'dateFormat' => get_option('date_format', 'Y-m-d'),
                    'timeFormat' => get_option('time_format', 'H:i'),
                    'locale' => get_locale(),
                    'channels' => $channels,
                    'channelLabels' => $channel_labels,
                    'strings' => array(
                        'searchPlaceholder' => __('Search guests, properties, or numbers…', 'guest-management-system'),
                        'searchAction' => __('Search', 'guest-management-system'),
                        'loading' => __('Loading…', 'guest-management-system'),
                        'noConversations' => __('No conversations found. Try adjusting your filters.', 'guest-management-system'),
                        'loadError' => __('Unable to load conversations. Please try again.', 'guest-management-system'),
                        'messageLoadError' => __('Unable to load messages for this thread.', 'guest-management-system'),
                        'sendPlaceholder' => __('Type your reply…', 'guest-management-system'),
                        'sendDisabled' => __('Replying is only available for SMS conversations.', 'guest-management-system'),
                        'sendLabel' => __('Send Reply', 'guest-management-system'),
                        'markRead' => __('Mark all as read', 'guest-management-system'),
                        'previousPage' => __('Previous conversations', 'guest-management-system'),
                        'nextPage' => __('Next conversations', 'guest-management-system'),
                        'templatePlaceholder' => __('Insert a template…', 'guest-management-system'),
                        'templateSearchPlaceholder' => __('Search templates…', 'guest-management-system'),
                        'templateLoading' => __('Loading templates…', 'guest-management-system'),
                        'templateEmpty' => __('No templates available for this channel yet.', 'guest-management-system'),
                        'templateEmptySearch' => __('No templates match your search.', 'guest-management-system'),
                        'templateLoadError' => __('Unable to load templates. Please try again.', 'guest-management-system'),
                        'templateUnavailable' => __('Select a conversation to browse templates.', 'guest-management-system'),
                        'sending' => __('Sending…', 'guest-management-system'),
                        'sendFailed' => __('Message failed to send. Please try again.', 'guest-management-system'),
                        'sendSuccess' => __('Message sent successfully.', 'guest-management-system'),
                        'optimisticFailed' => __('We were unable to deliver your last message.', 'guest-management-system'),
                        'unknownGuest' => __('Unknown Guest', 'guest-management-system'),
                        'guestEmail' => __('Email', 'guest-management-system'),
                        'guestPhone' => __('Phone', 'guest-management-system'),
                        'bookingReference' => __('Booking Reference', 'guest-management-system'),
                        'pagination' => __('Page %1$d of %2$d', 'guest-management-system'),
                        'conversationHeading' => __('Select a conversation to view message history.', 'guest-management-system'),
                        'emptyThread' => __('No messages yet. Start the conversation below.', 'guest-management-system'),
                    ),
                )
            );
        }

        if ($is_logs_screen) {
            $logs_style_path = $asset_base_path . 'assets/css/logs.css';
            $logs_script_path = $asset_base_path . 'assets/js/logs.js';
            $logs_style_version = file_exists($logs_style_path) ? filemtime($logs_style_path) : GMS_VERSION;
            $logs_script_version = file_exists($logs_script_path) ? filemtime($logs_script_path) : GMS_VERSION;

            wp_enqueue_style('gms-logs', GMS_PLUGIN_URL . 'assets/css/logs.css', [], $logs_style_version);
            wp_enqueue_script('gms-logs', GMS_PLUGIN_URL . 'assets/js/logs.js', [], $logs_script_version, true);

            wp_localize_script(
                'gms-logs',
                'gmsLogs',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('gms_logs_nonce'),
                    'locale' => get_locale(),
                    'strings' => array(
                        'searchPlaceholder' => __('Search logs…', 'guest-management-system'),
                        'searchAction' => __('Search Logs', 'guest-management-system'),
                        'loading' => __('Loading…', 'guest-management-system'),
                        'loadError' => __('Unable to load operational logs. Please try again.', 'guest-management-system'),
                        'empty' => __('No operational activity recorded yet.', 'guest-management-system'),
                        'pagination' => __('Page %1$d of %2$d', 'guest-management-system'),
                        'detailPlaceholder' => __('Select an operational log entry to view details.', 'guest-management-system'),
                        'channelLabel' => __('Channel', 'guest-management-system'),
                        'statusLabel' => __('Status', 'guest-management-system'),
                        'propertyLabel' => __('Property', 'guest-management-system'),
                        'guestLabel' => __('Guest', 'guest-management-system'),
                        'phoneLabel' => __('Phone', 'guest-management-system'),
                        'emailLabel' => __('Email', 'guest-management-system'),
                        'referenceLabel' => __('Booking Reference', 'guest-management-system'),
                        'subjectFallback' => __('Log entry', 'guest-management-system'),
                    ),
                )
            );
        }

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

    private function resolveGuestProfileRequest() {
        if ($this->guest_profile_request !== null) {
            return $this->guest_profile_request;
        }

        $is_profile = false;
        $token = '';

        $query_flag = get_query_var('guest_profile');
        if (!empty($query_flag)) {
            $token = (string) get_query_var('guest_profile_token');
            if ($token !== '') {
                $is_profile = true;
            }
        }

        if (!$is_profile && isset($_GET['guest_profile'])) {
            $raw_flag = strtolower(trim(wp_unslash($_GET['guest_profile'])));
            $truthy_flags = array('1', 'true', 'yes', 'on');

            if (in_array($raw_flag, $truthy_flags, true)) {
                $token = isset($_GET['guest_profile_token']) ? wp_unslash($_GET['guest_profile_token']) : '';
                if ($token !== '') {
                    $is_profile = true;
                }
            }
        }

        if (!$is_profile && isset($_SERVER['REQUEST_URI'])) {
            $extracted = $this->extractGuestProfileTokenFromPath(wp_unslash($_SERVER['REQUEST_URI']));
            if ($extracted !== '') {
                $token = $extracted;
                $is_profile = true;
            }
        }

        if ($is_profile) {
            $token = sanitize_text_field($token);

            if ($token === '') {
                $is_profile = false;
            }
        }

        if ($is_profile && function_exists('set_query_var')) {
            set_query_var('guest_profile', 1);
            set_query_var('guest_profile_token', $token);
        }

        $this->guest_profile_request = array(
            'is_profile' => $is_profile,
            'token' => $is_profile ? $token : ''
        );

        return $this->guest_profile_request;
    }

    private function resolveHousekeeperRequest() {
        if ($this->housekeeper_request !== null) {
            return $this->housekeeper_request;
        }

        $is_housekeeper = false;
        $token = '';

        $query_flag = get_query_var('gms_housekeeper');

        if (!empty($query_flag)) {
            $token = (string) get_query_var('gms_housekeeper_token');
            if ($token !== '') {
                $is_housekeeper = true;
            }
        }

        if (!$is_housekeeper && isset($_GET['gms_housekeeper'])) {
            $raw_flag = strtolower(trim(wp_unslash($_GET['gms_housekeeper'])));
            $truthy_flags = array('1', 'true', 'yes', 'on');

            if (in_array($raw_flag, $truthy_flags, true)) {
                $token = isset($_GET['gms_housekeeper_token']) ? wp_unslash($_GET['gms_housekeeper_token']) : '';
                if ($token !== '') {
                    $is_housekeeper = true;
                }
            }
        }

        if (!$is_housekeeper && isset($_SERVER['REQUEST_URI'])) {
            $extracted = $this->extractHousekeeperTokenFromPath(wp_unslash($_SERVER['REQUEST_URI']));
            if ($extracted !== '') {
                $token = $extracted;
                $is_housekeeper = true;
            }
        }

        if ($is_housekeeper) {
            $token = sanitize_text_field($token);

            if ($token === '') {
                $is_housekeeper = false;
            }
        }

        if ($is_housekeeper && function_exists('set_query_var')) {
            set_query_var('gms_housekeeper', 1);
            set_query_var('gms_housekeeper_token', $token);
        }

        $this->housekeeper_request = array(
            'is_housekeeper' => $is_housekeeper,
            'token' => $is_housekeeper ? $token : ''
        );

        return $this->housekeeper_request;
    }

    private function synchronizePortalQueryState($token, $primary_query = null) {
        $queries = array();

        if ($primary_query instanceof WP_Query) {
            $queries[] = $primary_query;
        }

        global $wp_query, $wp_the_query;

        if ($wp_query instanceof WP_Query) {
            $queries[] = $wp_query;
        }

        if ($wp_the_query instanceof WP_Query) {
            $queries[] = $wp_the_query;
        }

        foreach ($queries as $query_obj) {
            if (!$query_obj instanceof WP_Query) {
                continue;
            }

            $query_obj->is_404 = false;
            $query_obj->is_home = false;
            $query_obj->is_page = false;
            $query_obj->is_archive = false;
            $query_obj->is_singular = false;
            $query_obj->query['error'] = '';
            $query_obj->query_vars['error'] = '';
            $query_obj->set('guest_portal', 1);
            $query_obj->set('guest_token', $token);
        }

        if (function_exists('set_query_var')) {
            set_query_var('guest_portal', 1);
            set_query_var('guest_token', $token);
        }

        global $wp;

        if ($wp instanceof WP) {
            $wp->query_vars['guest_portal'] = 1;
            $wp->query_vars['guest_token'] = $token;

            if (isset($wp->query_vars['error'])) {
                unset($wp->query_vars['error']);
            }

            if (isset($wp->query) && is_array($wp->query) && isset($wp->query['error'])) {
                unset($wp->query['error']);
            }
        }
    }

    private function synchronizeGuestProfileQueryState($token, $primary_query = null) {
        $queries = array();

        if ($primary_query instanceof WP_Query) {
            $queries[] = $primary_query;
        }

        global $wp_query, $wp_the_query;

        if ($wp_query instanceof WP_Query) {
            $queries[] = $wp_query;
        }

        if ($wp_the_query instanceof WP_Query) {
            $queries[] = $wp_the_query;
        }

        foreach ($queries as $query_obj) {
            if (!$query_obj instanceof WP_Query) {
                continue;
            }

            $query_obj->is_404 = false;
            $query_obj->is_home = false;
            $query_obj->is_page = false;
            $query_obj->is_archive = false;
            $query_obj->is_singular = false;
            $query_obj->query['error'] = '';
            $query_obj->query_vars['error'] = '';
            $query_obj->set('guest_profile', 1);
            $query_obj->set('guest_profile_token', $token);
        }

        if (function_exists('set_query_var')) {
            set_query_var('guest_profile', 1);
            set_query_var('guest_profile_token', $token);
        }

        global $wp;

        if ($wp instanceof WP) {
            $wp->query_vars['guest_profile'] = 1;
            $wp->query_vars['guest_profile_token'] = $token;

            if (isset($wp->query_vars['error'])) {
                unset($wp->query_vars['error']);
            }

            if (isset($wp->query) && is_array($wp->query) && isset($wp->query['error'])) {
                unset($wp->query['error']);
            }
        }
    }

    private function synchronizeHousekeeperQueryState($token, $primary_query = null) {
        $queries = array();

        if ($primary_query instanceof WP_Query) {
            $queries[] = $primary_query;
        }

        global $wp_query, $wp_the_query;

        if ($wp_query instanceof WP_Query) {
            $queries[] = $wp_query;
        }

        if ($wp_the_query instanceof WP_Query) {
            $queries[] = $wp_the_query;
        }

        foreach ($queries as $query_obj) {
            if (!$query_obj instanceof WP_Query) {
                continue;
            }

            $query_obj->is_404 = false;
            $query_obj->is_home = false;
            $query_obj->is_page = false;
            $query_obj->is_archive = false;
            $query_obj->is_singular = false;
            $query_obj->query['error'] = '';
            $query_obj->query_vars['error'] = '';
            $query_obj->set('gms_housekeeper', 1);
            $query_obj->set('gms_housekeeper_token', $token);
        }

        if (function_exists('set_query_var')) {
            set_query_var('gms_housekeeper', 1);
            set_query_var('gms_housekeeper_token', $token);
        }

        global $wp;

        if ($wp instanceof WP) {
            $wp->query_vars['gms_housekeeper'] = 1;
            $wp->query_vars['gms_housekeeper_token'] = $token;

            if (isset($wp->query_vars['error'])) {
                unset($wp->query_vars['error']);
            }

            if (isset($wp->query) && is_array($wp->query) && isset($wp->query['error'])) {
                unset($wp->query['error']);
            }
        }
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

    private function extractGuestProfileTokenFromPath($request_uri) {
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

        $prefix = 'guest-profile/';

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

    private function extractHousekeeperTokenFromPath($request_uri) {
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

        $prefix = 'housekeeper/';

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
