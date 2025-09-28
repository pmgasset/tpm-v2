<?php
/**
 * File: guest-management-system.php
 * Location: /wp-content/plugins/guest-management-system/guest-management-system.php
 * * Plugin Name: Guest Management System
 * Plugin URI: https://yoursite.com
 * Description: Complete guest management system for short-term rentals with webhook integration, identity verification, and agreement signing.
 * Version: 1.1.2
 * Author: Your Company
 * License: GPL v2 or later
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
define('GMS_VERSION', '1.1.2');

class GuestManagementSystem {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // FIX: Load includes immediately in the constructor to ensure all classes are available early.
        $this->loadIncludes();

        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Initialize plugin components
        new GMS_Database();
        new GMS_Admin();
        new GMS_Webhook_Handler();
        new GMS_Guest_Portal();
        new GMS_Email_Handler();
        new GMS_SMS_Handler();
        new GMS_Stripe_Integration();
        new GMS_Agreement_Handler();
        new GMS_AJAX_Handler();
        
        // Add custom user role
        $this->addGuestRole();
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        
        // Add rewrite rules for guest portal
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
            'class-agreement-handler.php',
            'class-ajax-handler.php', // Load the new class file
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
        // We need to load includes during activation to access the Database class
        $this->loadIncludes();

        if (!class_exists('GMS_Database')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Guest Management System Error: The GMS_Database class was not found. The plugin could not be activated. Please ensure all plugin files are uploaded correctly.</p></div>';
            });
            return;
        }
        
        // Create database tables
        GMS_Database::createTables();
        
        // Add rewrite rules and flush them
        $this->addRewriteRules();
        flush_rewrite_rules();
        
        // Add default options
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
            'gms_sms_template' => $this->getDefaultSMSTemplate()
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
                'guest_portal_access' => true
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
        if (get_query_var('guest_portal')) {
            $token = get_query_var('guest_token');
            if ($token) {
                GMS_Guest_Portal::displayPortal($token);
                exit;
            }
        }
    }
    
    public function enqueueScripts() {
        if (get_query_var('guest_portal')) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
            
            wp_enqueue_script('gms-guest-portal', GMS_PLUGIN_URL . 'assets/js/guest-portal.js', array('stripe-js', 'jquery'), GMS_VERSION, true);
            wp_enqueue_style('gms-guest-portal', GMS_PLUGIN_URL . 'assets/css/guest-portal.css', array(), GMS_VERSION);
            
            $token = get_query_var('guest_token');
            $reservation = GMS_Database::getReservationByToken($token);
            
            wp_localize_script('gms-guest-portal', 'gmsConfig', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('gms_guest_nonce'),
                'stripeKey' => get_option('gms_stripe_pk'),
                'reservationId' => $reservation ? $reservation['id'] : 0,
                'generic_error' => __('An unexpected error occurred. Please try again.', 'guest-management-system')
            ));
        }
    }
    
    public function enqueueAdminScripts($hook) {
        if (strpos($hook, 'guest-management') !== false) {
            wp_enqueue_script('gms-admin', GMS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), GMS_VERSION, true);
            wp_enqueue_style('gms-admin', GMS_PLUGIN_URL . 'assets/css/admin.css', array(), GMS_VERSION);
            
            wp_localize_script('gms-admin', 'gmsAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('gms_admin_nonce'),
                'webhookUrl' => home_url('/webhook')
            ));

            wp_enqueue_media();
        }
    }
    
    private function getDefaultAgreementTemplate() {
        return 'By signing below, I agree to abide by all property rules and regulations, understand that I am responsible for any damages, and confirm that all information provided is accurate.';
    }
    
    private function getDefaultEmailTemplate() {
        return 'Hi {guest_name},

Welcome to {property_name}! 

To complete your check-in process, please visit your guest portal: {portal_link}

You will need to:
1. Sign the guest agreement
2. Complete identity verification

Check-in: {checkin_date} at {checkin_time}
Check-out: {checkout_date} at {checkout_time}

We look forward to hosting you!

Best regards,
{company_name}';
    }
    
    private function getDefaultSMSTemplate() {
        return 'Hi {guest_name}! Complete your check-in at {portal_link} - Identity verification and agreement required. Check-in: {checkin_date} {checkin_time}';
    }
}

// Initialize the plugin
GuestManagementSystem::getInstance();
