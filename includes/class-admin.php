<?php
/**
 * File: class-admin.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-admin.php
 * Handles all admin-facing functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class GMS_Reservations_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct(['singular' => 'Reservation', 'plural' => 'Reservations', 'ajax' => false]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'guest_name' => 'Guest',
            'property_name' => 'Property',
            'checkin_date' => 'Check-in',
            'status' => 'Status',
            'booking_reference' => 'Booking Ref',
            'portal_link' => 'Guest Portal',
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'guest_name':
                return '<strong>' . esc_html($item[$column_name]) . '</strong>';
            case 'property_name':
            case 'status':
                return esc_html($item[$column_name]);
            case 'checkin_date':
                if (empty($item[$column_name]) || $item[$column_name] === '0000-00-00 00:00:00') {
                    return '&mdash;';
                }

                return date('M j, Y, g:i a', strtotime($item[$column_name]));
            default:
                return '';
        }
    }

    public function column_booking_reference($item) {
        $reservation_id = isset($item['id']) ? absint($item['id']) : 0;
        $reference = isset($item['booking_reference']) ? (string) $item['booking_reference'] : '';

        if (!$reservation_id) {
            return esc_html($reference);
        }

        $label = $reference !== '' ? $reference : __('Edit Reservation', 'guest-management-system');

        $edit_url = add_query_arg(
            array(
                'page' => 'guest-management-reservations',
                'action' => 'edit',
                'reservation_id' => $reservation_id,
            ),
            admin_url('admin.php')
        );

        $aria_label = sprintf(
            /* translators: %s: reservation identifier */
            __('Edit reservation %s', 'guest-management-system'),
            $label
        );

        return sprintf(
            '<a class="gms-reservation-edit-link" href="%1$s" aria-label="%2$s">%3$s</a>',
            esc_url($edit_url),
            esc_attr($aria_label),
            esc_html($label)
        );
    }

    public function column_portal_link($item) {
        $reservation_id = isset($item['id']) ? absint($item['id']) : 0;

        if (!$reservation_id) {
            return '&mdash;';
        }

        $portal_url = gms_get_portal_url($reservation_id);

        if (empty($portal_url)) {
            return '&mdash;';
        }

        $copy_label = __('Copy portal link', 'guest-management-system');
        $button_label = __('Open Portal', 'guest-management-system');
        $aria_label = __('Open the guest portal in a new tab. The link will be copied to your clipboard.', 'guest-management-system');

        return sprintf(
            '<a class="button button-small gms-open-portal" href="%1$s" target="_blank" rel="noopener noreferrer" data-copy-url="%1$s" aria-label="%2$s" data-copy-label="%3$s">%4$s</a>',
            esc_url($portal_url),
            esc_attr($aria_label),
            esc_attr($copy_label),
            esc_html($button_label)
        );
    }
    
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="reservation[]" value="%s" />', $item['id']);
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = GMS_Database::get_record_count('reservations');

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $this->items = GMS_Database::get_reservations($per_page, $current_page);
    }
}


class GMS_Guests_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'Guest',
            'plural' => 'Guests',
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'name' => __('Name', 'guest-management-system'),
            'email' => __('Email', 'guest-management-system'),
            'phone' => __('Phone', 'guest-management-system'),
            'created_at' => __('Created', 'guest-management-system'),
            'status' => __('Status', 'guest-management-system'),
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'name':
                return '<strong>' . esc_html($item[$column_name]) . '</strong>';
            case 'email':
                return $item[$column_name] !== '' ? esc_html($item[$column_name]) : '&mdash;';
            case 'phone':
                return $item[$column_name] !== '' ? esc_html($item[$column_name]) : '&mdash;';
            case 'created_at':
                if (empty($item[$column_name]) || $item[$column_name] === '0000-00-00 00:00:00') {
                    return '&mdash;';
                }

                $timestamp = strtotime($item[$column_name]);
                if (!$timestamp) {
                    return '&mdash;';
                }

                $format = trim(get_option('date_format', 'M j, Y') . ' ' . get_option('time_format', 'g:i a'));
                return esc_html(date_i18n($format, $timestamp));
            case 'status':
                $has_name = !empty($item['name']);
                $has_contact = !empty($item['email']) || !empty($item['phone']);
                $status = ($has_name && $has_contact) ? __('Complete', 'guest-management-system') : __('Incomplete', 'guest-management-system');
                return esc_html($status);
            default:
                return '';
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="guest[]" value="%s" />', $item['id']);
    }

    public function no_items() {
        esc_html_e('No guests found.', 'guest-management-system');
    }

    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = [];
        $this->_column_headers = [$columns, $hidden, $sortable];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        $total_items = GMS_Database::get_guest_count($search);
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
        ]);

        $this->items = GMS_Database::get_guests($per_page, $current_page, $search);
    }
}


class GMS_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_gms_test_sms', array($this, 'ajax_test_sms'));
        add_action('wp_ajax_gms_test_email', array($this, 'ajax_test_email'));
        add_action('wp_ajax_gms_resend_notification', array($this, 'ajax_resend_notification'));
        add_action('wp_ajax_gms_bulk_action', array($this, 'ajax_bulk_action'));
        add_action('wp_ajax_gms_autosave_template', array($this, 'ajax_autosave_template'));
        add_action('wp_ajax_gms_refresh_stats', array($this, 'ajax_refresh_stats'));
    }

    public function ajax_test_sms() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $number = isset($_POST['number']) ? sanitize_text_field(wp_unslash($_POST['number'])) : '';

        if (empty($number)) {
            wp_send_json_error(__('Please provide a phone number.', 'guest-management-system'));
        }

        $company_name = get_option('gms_company_name', get_option('blogname'));
        $message = sprintf(__('This is a test SMS from %s.', 'guest-management-system'), $company_name);

        $sms_handler = new GMS_SMS_Handler();
        $sent = $sms_handler->sendSMS($number, $message);

        if (!$sent) {
            wp_send_json_error(__('Failed to send test SMS. Please verify your VoIP.ms configuration.', 'guest-management-system'));
        }

        wp_send_json_success(array(
            'message' => __('Test SMS sent successfully.', 'guest-management-system'),
        ));
    }

    public function ajax_test_email() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(__('Please enter a valid email address.', 'guest-management-system'));
        }

        $email_handler = new GMS_Email_Handler();
        $subject = __('Test Email from Guest Management System', 'guest-management-system');
        $message = __('This is a test email to confirm your notification settings are working.', 'guest-management-system');

        $sent = $email_handler->sendEmail($email, $subject, $message);

        if (!$sent) {
            wp_send_json_error(__('Failed to send test email. Please verify your email configuration.', 'guest-management-system'));
        }

        wp_send_json_success(array(
            'message' => __('Test email sent successfully.', 'guest-management-system'),
        ));
    }

    public function ajax_resend_notification() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $reservation_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

        if (!$reservation_id) {
            wp_send_json_error(__('Invalid reservation selected.', 'guest-management-system'));
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            wp_send_json_error(__('The reservation could not be found.', 'guest-management-system'));
        }

        $results = $this->send_reservation_notifications($reservation);

        if (!$results['email_sent'] && !$results['sms_sent']) {
            wp_send_json_error(__('Notifications could not be sent for this reservation.', 'guest-management-system'));
        }

        wp_send_json_success(array(
            'results' => $results,
        ));
    }

    public function ajax_bulk_action() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
        $ids_raw = isset($_POST['reservation_ids']) ? (array) wp_unslash($_POST['reservation_ids']) : array();
        $reservation_ids = array_filter(array_map('absint', $ids_raw));

        if (empty($action) || empty($reservation_ids)) {
            wp_send_json_error(__('Please select at least one reservation and a valid action.', 'guest-management-system'));
        }

        $processed = 0;
        $errors = array();

        if (strpos($action, 'mark_') === 0) {
            $status = str_replace('mark_', '', $action);
            $status = str_replace('-', '_', $status);

            foreach ($reservation_ids as $reservation_id) {
                $updated = GMS_Database::updateReservation($reservation_id, array('status' => $status));
                if ($updated) {
                    $processed++;
                } else {
                    $errors[] = sprintf(__('Failed to update reservation #%d.', 'guest-management-system'), $reservation_id);
                }
            }
        } elseif ($action === 'resend_notifications' || $action === 'resend_notification') {
            foreach ($reservation_ids as $reservation_id) {
                $reservation = GMS_Database::getReservationById($reservation_id);

                if (!$reservation) {
                    $errors[] = sprintf(__('Reservation #%d could not be found.', 'guest-management-system'), $reservation_id);
                    continue;
                }

                $results = $this->send_reservation_notifications($reservation);
                if ($results['email_sent'] || $results['sms_sent']) {
                    $processed++;
                } else {
                    $errors[] = sprintf(__('Notifications failed for reservation #%d.', 'guest-management-system'), $reservation_id);
                }
            }
        } else {
            wp_send_json_error(__('Unsupported bulk action.', 'guest-management-system'));
        }

        if ($processed === 0) {
            $message = !empty($errors)
                ? implode(' ', $errors)
                : __('No reservations were processed.', 'guest-management-system');

            wp_send_json_error($message);
        }

        $response = array(
            'processed' => $processed,
        );

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        wp_send_json_success($response);
    }

    public function ajax_autosave_template() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

        $allowed = array(
            'gms_agreement_template' => 'sanitize_template',
            'gms_email_template' => 'sanitize_template',
            'gms_sms_template' => 'sanitize_plain_textarea',
            'gms_sms_reminder_template' => 'sanitize_plain_textarea',
        );

        if (!array_key_exists($field, $allowed)) {
            wp_send_json_error(__('This template cannot be auto-saved.', 'guest-management-system'));
        }

        $callback = $allowed[$field];
        $sanitized_value = call_user_func(array($this, $callback), $value);

        update_option($field, $sanitized_value);

        wp_send_json_success(array(
            'message' => __('Template saved.', 'guest-management-system'),
        ));
    }

    public function ajax_refresh_stats() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $upcoming_checkins = gms_get_upcoming_checkins(7);
        $pending_checkins = gms_get_pending_checkins();

        $stats = array(
            'total_reservations' => GMS_Database::get_record_count('reservations'),
            'upcoming_checkins' => is_array($upcoming_checkins) ? count($upcoming_checkins) : 0,
            'pending_checkins' => is_array($pending_checkins) ? count($pending_checkins) : 0,
            'total_guests' => GMS_Database::get_record_count('guests'),
            'refreshed_at' => current_time('mysql'),
        );

        wp_send_json_success(array(
            'stats' => $stats,
        ));
    }

    public function ajax_get_reservation() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $reservation_id = isset($_REQUEST['reservation_id']) ? absint(wp_unslash($_REQUEST['reservation_id'])) : 0;

        if (!$reservation_id) {
            wp_send_json_error(__('Invalid reservation ID supplied.', 'guest-management-system'));
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            wp_send_json_error(__('Reservation not found.', 'guest-management-system'));
        }

        $response = $this->prepare_reservation_response($reservation);

        wp_send_json_success($response);
    }

    public function ajax_update_reservation() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $reservation_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

        if (!$reservation_id) {
            wp_send_json_error(__('Invalid reservation ID supplied.', 'guest-management-system'));
        }

        $payload = isset($_POST['reservation']) ? wp_unslash($_POST['reservation']) : array();

        if (!is_array($payload)) {
            $payload = array();
        }

        $allowed = array(
            'guest_name',
            'guest_email',
            'guest_phone',
            'property_name',
            'booking_reference',
            'checkin_date',
            'checkout_date',
            'status',
        );

        $update_data = array();
        $sanitizers = array(
            'guest_name' => 'sanitize_text_field',
            'guest_email' => 'sanitize_email',
            'guest_phone' => 'sanitize_text_field',
            'property_name' => 'sanitize_text_field',
            'booking_reference' => 'sanitize_text_field',
            'checkin_date' => 'sanitize_text_field',
            'checkout_date' => 'sanitize_text_field',
            'status' => 'sanitize_key',
        );

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if (is_array($value)) {
                continue;
            }

            if (isset($sanitizers[$field]) && is_callable($sanitizers[$field])) {
                $value = call_user_func($sanitizers[$field], $value);
            }

            $update_data[$field] = $value;
        }

        if (!empty($update_data)) {
            $updated = GMS_Database::updateReservation($reservation_id, $update_data);

            if (!$updated) {
                wp_send_json_error(__('Unable to save the reservation. Please try again.', 'guest-management-system'));
            }
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            wp_send_json_error(__('Unable to load the updated reservation.', 'guest-management-system'));
        }

        $response = $this->prepare_reservation_response($reservation);

        wp_send_json_success($response);
    }


    /**
     * Return available settings tabs
     */
    private function get_settings_tabs() {
        return array(
            'general' => __('General', 'guest-management-system'),
            'integrations' => __('Integrations', 'guest-management-system'),
            'branding' => __('Branding', 'guest-management-system'),
            'templates' => __('Templates', 'guest-management-system')
        );
    }

    /**
     * Register plugin settings and sections
     */
    public function register_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // General tab - contact defaults
        register_setting('gms_settings_general', 'gms_email_from_name', array(
            'sanitize_callback' => array($this, 'sanitize_text')
        ));
        register_setting('gms_settings_general', 'gms_email_from', array(
            'sanitize_callback' => array($this, 'sanitize_email')
        ));

        add_settings_section(
            'gms_general_contact',
            __('Contact Defaults', 'guest-management-system'),
            function() {
                echo '<p>' . esc_html__('Configure the default sender details used for guest communications.', 'guest-management-system') . '</p>';
            },
            'gms_settings_general'
        );

        add_settings_field(
            'gms_email_from_name',
            __('From Name', 'guest-management-system'),
            array($this, 'render_text_field'),
            'gms_settings_general',
            'gms_general_contact',
            array(
                'option_name' => 'gms_email_from_name',
                'placeholder' => get_option('blogname'),
                'description' => __('Displayed as the sender name in outgoing emails.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_email_from',
            __('From Email', 'guest-management-system'),
            array($this, 'render_email_field'),
            'gms_settings_general',
            'gms_general_contact',
            array(
                'option_name' => 'gms_email_from',
                'placeholder' => get_option('admin_email'),
                'description' => __('Used as the reply-to address for guest notifications.', 'guest-management-system')
            )
        );

        // Integrations tab - API credentials
        $integration_options = array(
            'gms_stripe_pk' => array('label' => __('Stripe Publishable Key', 'guest-management-system')),
            'gms_stripe_sk' => array('label' => __('Stripe Secret Key', 'guest-management-system')),
            'gms_stripe_webhook_secret' => array('label' => __('Stripe Webhook Secret', 'guest-management-system')),
            'gms_voipms_user' => array('label' => __('VoIP.ms Username', 'guest-management-system')),
            'gms_voipms_pass' => array('label' => __('VoIP.ms API Password', 'guest-management-system')),
            'gms_voipms_did' => array('label' => __('VoIP.ms SMS DID', 'guest-management-system')),
            'gms_bitly_token' => array('label' => __('Bitly Access Token', 'guest-management-system')),
            'gms_webhook_token' => array('label' => __('Webhook Shared Secret', 'guest-management-system'))
        );

        foreach ($integration_options as $option => $meta) {
            register_setting('gms_settings_integrations', $option, array(
                'sanitize_callback' => array($this, 'sanitize_api_credential')
            ));
        }

        add_settings_section(
            'gms_integrations_section',
            __('API Integrations', 'guest-management-system'),
            array($this, 'render_integrations_help'),
            'gms_settings_integrations'
        );

        foreach ($integration_options as $option => $meta) {
            add_settings_field(
                $option,
                $meta['label'],
                array($this, 'render_api_field'),
                'gms_settings_integrations',
                'gms_integrations_section',
                array(
                    'option_name' => $option,
                    'description' => isset($meta['description']) ? $meta['description'] : ''
                )
            );
        }

        // Branding tab - appearance
        register_setting('gms_settings_branding', 'gms_company_name', array(
            'sanitize_callback' => array($this, 'sanitize_text')
        ));
        register_setting('gms_settings_branding', 'gms_company_logo', array(
            'sanitize_callback' => array($this, 'sanitize_url')
        ));
        register_setting('gms_settings_branding', 'gms_portal_primary_color', array(
            'sanitize_callback' => array($this, 'sanitize_color')
        ));
        register_setting('gms_settings_branding', 'gms_portal_secondary_color', array(
            'sanitize_callback' => array($this, 'sanitize_color')
        ));

        add_settings_section(
            'gms_branding_section',
            __('Branding', 'guest-management-system'),
            function() {
                echo '<p>' . esc_html__('Customize the branding shown in guest emails and the portal experience.', 'guest-management-system') . '</p>';
            },
            'gms_settings_branding'
        );

        add_settings_field(
            'gms_company_name',
            __('Company Name', 'guest-management-system'),
            array($this, 'render_text_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_company_name',
                'placeholder' => get_option('blogname'),
                'description' => __('Displayed across the guest portal and in notification templates.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_company_logo',
            __('Company Logo', 'guest-management-system'),
            array($this, 'render_logo_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_company_logo',
                'description' => __('Upload a logo to display on guest emails and portal headers.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_portal_primary_color',
            __('Primary Color', 'guest-management-system'),
            array($this, 'render_color_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_portal_primary_color',
                'default' => '#0073aa',
                'description' => __('Used for primary buttons and accents.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_portal_secondary_color',
            __('Secondary Color', 'guest-management-system'),
            array($this, 'render_color_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_portal_secondary_color',
                'default' => '#005a87',
                'description' => __('Used for backgrounds and supporting UI elements.', 'guest-management-system')
            )
        );

        // Templates tab - communication content
        $template_options = array(
            'gms_agreement_template' => array(
                'label' => __('Guest Agreement Template', 'guest-management-system'),
                'description' => __('Displayed to guests prior to signing their agreement.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_template'),
                'rows' => 8
            ),
            'gms_email_template' => array(
                'label' => __('Welcome Email Template', 'guest-management-system'),
                'description' => __('Sent when a reservation is created. Supports HTML.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_template'),
                'rows' => 8
            ),
            'gms_sms_template' => array(
                'label' => __('Welcome SMS Template', 'guest-management-system'),
                'description' => __('Used for initial SMS notifications to guests.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_plain_textarea'),
                'rows' => 4
            ),
            'gms_sms_reminder_template' => array(
                'label' => __('Reminder SMS Template', 'guest-management-system'),
                'description' => __('Used for reminder SMS follow-ups.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_plain_textarea'),
                'rows' => 4
            )
        );

        foreach ($template_options as $option => $meta) {
            register_setting('gms_settings_templates', $option, array(
                'sanitize_callback' => $meta['sanitize_callback']
            ));
        }

        add_settings_section(
            'gms_templates_section',
            __('Message Templates', 'guest-management-system'),
            array($this, 'render_template_tokens_help'),
            'gms_settings_templates'
        );

        foreach ($template_options as $option => $meta) {
            add_settings_field(
                $option,
                $meta['label'],
                array($this, 'render_textarea_field'),
                'gms_settings_templates',
                'gms_templates_section',
                array(
                    'option_name' => $option,
                    'description' => $meta['description'],
                    'rows' => $meta['rows']
                )
            );
        }
    }

    /**
     * Sanitize helper for generic text fields
     */
    public function sanitize_text($value) {
        return sanitize_text_field($value);
    }

    /**
     * Sanitize helper for emails
     */
    public function sanitize_email($value) {
        return sanitize_email($value);
    }

    /**
     * Sanitize helper for API credentials
     */
    public function sanitize_api_credential($value) {
        return trim(wp_strip_all_tags($value));
    }

    /**
     * Sanitize helper for URLs
     */
    public function sanitize_url($value) {
        return esc_url_raw($value);
    }

    /**
     * Sanitize helper for color values
     */
    public function sanitize_color($value) {
        $color = sanitize_hex_color($value);
        return $color ? $color : '';
    }

    /**
     * Sanitize template content allowing limited HTML
     */
    public function sanitize_template($value) {
        $allowed_tags = array(
            'a' => array('href' => array(), 'title' => array(), 'target' => array()),
            'br' => array(),
            'em' => array(),
            'strong' => array(),
            'p' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
        );

        return wp_kses($value, $allowed_tags);
    }

    /**
     * Sanitize plain text area fields
     */
    public function sanitize_plain_textarea($value) {
        return sanitize_textarea_field($value);
    }

    private function ensure_ajax_permissions() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'guest-management-system'));
        }
    }

    private function send_reservation_notifications($reservation) {
        $results = array(
            'email_sent' => false,
            'sms_sent' => false,
        );

        if (empty($reservation) || !is_array($reservation)) {
            return $results;
        }

        if (!empty($reservation['guest_email']) && is_email($reservation['guest_email'])) {
            static $email_handler = null;

            if ($email_handler === null) {
                $email_handler = new GMS_Email_Handler();
            }

            $results['email_sent'] = $email_handler->sendWelcomeEmail($reservation);
        }

        if (!empty($reservation['guest_phone'])) {
            static $sms_handler = null;

            if ($sms_handler === null) {
                $sms_handler = new GMS_SMS_Handler();
            }

            $results['sms_sent'] = $sms_handler->sendWelcomeSMS($reservation);
        }

        return $results;
    }

    /**
     * Render a generic text input field
     */
    public function render_text_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr($placeholder)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render an email input field
     */
    public function render_email_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="email" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr($placeholder)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render API credential fields with monospace styling
     */
    public function render_api_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text api-key-field" autocomplete="off" />',
            esc_attr($option_name),
            esc_attr($value)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render color picker fields
     */
    public function render_color_field($args) {
        $option_name = $args['option_name'];
        $default = isset($args['default']) ? $args['default'] : '#0073aa';
        $value = get_option($option_name, $default);
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="color" name="%1$s" id="%1$s" value="%2$s" />',
            esc_attr($option_name),
            esc_attr($value)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render logo upload field with preview
     */
    public function render_logo_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $description = isset($args['description']) ? $args['description'] : '';
        $preview_id = $option_name . '_preview';

        echo '<div class="gms-logo-field">';
        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr__('https://example.com/logo.png', 'guest-management-system')
        );
        echo '<br />';
        printf(
            '<button type="button" class="button gms-upload-logo" data-target="%1$s" data-preview="%2$s">%3$s</button> ',
            esc_attr($option_name),
            esc_attr($preview_id),
            esc_html__('Upload Logo', 'guest-management-system')
        );
        printf(
            '<button type="button" class="button-secondary gms-remove-logo" data-target="%1$s" data-preview="%2$s">%3$s</button>',
            esc_attr($option_name),
            esc_attr($preview_id),
            esc_html__('Remove', 'guest-management-system')
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }

        $preview_style = empty($value) ? 'style="display:none;"' : '';
        printf(
            '<div id="%1$s" class="gms-logo-preview" %3$s>%2$s</div>',
            esc_attr($preview_id),
            $value ? '<img src="' . esc_url($value) . '" alt="" style="max-width:200px;height:auto;margin-top:10px;" />' : '',
            $preview_style
        );
        echo '</div>';
    }

    /**
     * Render textarea fields used for templates
     */
    public function render_textarea_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $description = isset($args['description']) ? $args['description'] : '';
        $rows = isset($args['rows']) ? intval($args['rows']) : 6;

        printf(
            '<textarea name="%1$s" id="%1$s" rows="%3$d" class="large-text code">%2$s</textarea>',
            esc_attr($option_name),
            esc_textarea($value),
            $rows
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render contextual help for template tokens
     */
    public function render_template_tokens_help() {
        $tokens = array(
            '{guest_name}' => __('Guest name', 'guest-management-system'),
            '{property_name}' => __('Property name', 'guest-management-system'),
            '{booking_reference}' => __('Reservation confirmation or reference number', 'guest-management-system'),
            '{checkin_date}' => __('Formatted check-in date', 'guest-management-system'),
            '{checkout_date}' => __('Formatted check-out date', 'guest-management-system'),
            '{checkin_time}' => __('Formatted check-in time', 'guest-management-system'),
            '{checkout_time}' => __('Formatted check-out time', 'guest-management-system'),
            '{portal_link}' => __('Secure guest portal link', 'guest-management-system'),
            '{company_name}' => __('Configured company name', 'guest-management-system')
        );

        echo '<p>' . esc_html__('Use the tokens below to personalize email, SMS, and agreement templates. Tokens will be replaced with reservation data when messages are sent.', 'guest-management-system') . '</p>';

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html__('Available Tokens:', 'guest-management-system') . '</strong></p><ul>';
        foreach ($tokens as $token => $description) {
            printf('<li><code>%1$s</code> — %2$s</li>', esc_html($token), esc_html($description));
        }
        echo '</ul></div>';
    }

    /**
     * Render contextual guidance for integrations, including webhook endpoints
     */
    public function render_integrations_help() {
        echo '<p>' . esc_html__('Provide credentials for payment processing, SMS delivery, URL shortening, and webhook security.', 'guest-management-system') . '</p>';

        $webhook_urls = function_exists('gms_get_webhook_urls') ? gms_get_webhook_urls() : array();

        if (empty($webhook_urls) || !is_array($webhook_urls)) {
            return;
        }

        echo '<div class="notice notice-info inline webhook-url-box">';
        echo '<p><strong>' . esc_html__('Webhook Endpoints', 'guest-management-system') . '</strong></p>';
        echo '<ul>';

        foreach ($webhook_urls as $platform => $url) {
            if (empty($url)) {
                continue;
            }

            $label = ucwords(str_replace(array('-', '_'), ' ', $platform));
            printf(
                '<li><span class="webhook-label">%1$s:</span> <code>%2$s</code></li>',
                esc_html($label),
                esc_html($url)
            );
        }

        echo '</ul>';
        echo '<p>' . esc_html__('Authenticate webhook requests by sending the shared secret saved below as the X-Webhook-Token header or as a webhook_token query parameter.', 'guest-management-system') . '</p>';
        echo '</div>';
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Guest Management',
            'Guest Management',
            'manage_options',
            'guest-management-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-businessperson',
            25
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'guest-management-dashboard',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Reservations',
            'Reservations',
            'manage_options',
            'guest-management-reservations',
            [$this, 'render_reservations_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Guests',
            'Guests',
            'manage_options',
            'guest-management-guests',
            [$this, 'render_guests_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Communications & Logs',
            'Communications/Logs',
            'manage_options',
            'guest-management-communications',
            [$this, 'render_communications_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Templates',
            'Templates',
            'manage_options',
            'guest-management-templates',
            [$this, 'render_templates_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'guest-management-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_dashboard_page() {
        $total_reservations = GMS_Database::get_record_count('reservations');
        $total_guests = GMS_Database::get_record_count('guests');

        $upcoming_checkins = gms_get_upcoming_checkins(7);
        $pending_checkins = gms_get_pending_checkins();
        $recent_reservations = GMS_Database::get_reservations(5, 1);

        $upcoming_count = is_array($upcoming_checkins) ? count($upcoming_checkins) : 0;
        $pending_count = is_array($pending_checkins) ? count($pending_checkins) : 0;

        ?>
        <div class="wrap gms-dashboard">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guest Management Dashboard', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">

            <div class="gms-dashboard-stats">
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($total_reservations)); ?></h3>
                    <p><?php esc_html_e('Total Reservations', 'guest-management-system'); ?></p>
                </div>
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($upcoming_count)); ?></h3>
                    <p><?php esc_html_e('Upcoming Check-ins (7 Days)', 'guest-management-system'); ?></p>
                </div>
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($pending_count)); ?></h3>
                    <p><?php esc_html_e('Pending Check-ins', 'guest-management-system'); ?></p>
                </div>
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($total_guests)); ?></h3>
                    <p><?php esc_html_e('Total Guests', 'guest-management-system'); ?></p>
                </div>
            </div>

            <div class="gms-recent-reservations">
                <h2><?php esc_html_e('Recent Reservations', 'guest-management-system'); ?></h2>
                <?php if (!empty($recent_reservations)) : ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Guest', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Property', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Check-in', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Status', 'guest-management-system'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_reservations as $reservation) :
                                $status = isset($reservation['status']) ? $reservation['status'] : '';
                                $status_slug = $status ? strtolower(str_replace([' ', '_'], '-', $status)) : 'default';
                                $checkin_display = !empty($reservation['checkin_date'])
                                    ? gms_format_datetime($reservation['checkin_date'])
                                    : __('—', 'guest-management-system');
                                ?>
                                <tr>
                                    <td><?php echo esc_html($reservation['guest_name'] ?? __('Unknown Guest', 'guest-management-system')); ?></td>
                                    <td><?php echo esc_html($reservation['property_name'] ?? __('N/A', 'guest-management-system')); ?></td>
                                    <td><?php echo esc_html($checkin_display); ?></td>
                                    <td>
                                        <?php if ($status) : ?>
                                            <span class="status-badge status-<?php echo esc_attr($status_slug); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></span>
                                        <?php else : ?>
                                            <span class="status-badge status-default"><?php esc_html_e('Unknown', 'guest-management-system'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e('No recent reservations found.', 'guest-management-system'); ?></p>
                <?php endif; ?>
            </div>

            <div class="gms-quick-actions">
                <h2><?php esc_html_e('Quick Actions', 'guest-management-system'); ?></h2>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-reservations')); ?>"><?php esc_html_e('Manage Reservations', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-guests')); ?>"><?php esc_html_e('View Guests', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-communications')); ?>"><?php esc_html_e('Communications & Logs', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-templates')); ?>"><?php esc_html_e('Edit Templates', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-settings')); ?>"><?php esc_html_e('Open Settings', 'guest-management-system'); ?></a>
            </div>
        </div>
        <?php
    }
    
    public function render_reservations_page() {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'new') {
            $this->render_reservation_creation_page();
            return;
        }

        if ($action === 'edit') {
            $reservation_id = isset($_GET['reservation_id']) ? absint(wp_unslash($_GET['reservation_id'])) : 0;
            $this->render_reservation_edit_page($reservation_id);
            return;
        }

        $reservations_table = new GMS_Reservations_List_Table();
        $reservations_table->prepare_items();

        $add_new_url = add_query_arg(
            array(
                'page' => 'guest-management-reservations',
                'action' => 'new',
            ),
            admin_url('admin.php')
        );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Reservations</h1>
            <a href="<?php echo esc_url($add_new_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['gms_reservation_created'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Reservation created successfully.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php $reservations_table->display(); ?>
            </form>
        </div>
        <?php
    }

    protected function render_reservation_creation_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $form_values = array(
            'guest_name' => '',
            'guest_email' => '',
            'guest_phone' => '',
            'property_name' => '',
            'property_id' => '',
            'booking_reference' => '',
            'checkin_date' => '',
            'checkout_date' => '',
            'status' => 'pending',
        );

        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('gms_create_reservation');

            foreach ($form_values as $key => $default) {
                if (isset($_POST[$key])) {
                    $value = wp_unslash($_POST[$key]);
                    switch ($key) {
                        case 'guest_email':
                            $form_values[$key] = sanitize_email($value);
                            break;
                        case 'checkin_date':
                        case 'checkout_date':
                            $form_values[$key] = $this->format_datetime_for_input($value);
                            break;
                        default:
                            $form_values[$key] = sanitize_text_field($value);
                            break;
                    }
                }
            }

            $allowed_statuses = array('pending', 'confirmed', 'cancelled');
            if (!in_array($form_values['status'], $allowed_statuses, true)) {
                $form_values['status'] = 'pending';
            }

            if (empty($form_values['guest_name'])) {
                $errors[] = __('Guest name is required.', 'guest-management-system');
            }

            if (!empty($form_values['guest_email']) && !is_email($form_values['guest_email'])) {
                $errors[] = __('Please enter a valid email address.', 'guest-management-system');
            }

            $guest_id = 0;

            if (empty($errors)) {
                $guest_id = GMS_Database::upsert_guest(array(
                    'name' => $form_values['guest_name'],
                    'email' => $form_values['guest_email'],
                    'phone' => $form_values['guest_phone'],
                ));

                if (!$guest_id) {
                    $errors[] = __('Unable to save guest details. Please try again.', 'guest-management-system');
                }
            }

            if (empty($errors)) {
                $reservation_data = array(
                    'guest_id' => $guest_id,
                    'guest_name' => $form_values['guest_name'],
                    'guest_email' => $form_values['guest_email'],
                    'guest_phone' => $form_values['guest_phone'],
                    'property_name' => $form_values['property_name'],
                    'property_id' => $form_values['property_id'],
                    'booking_reference' => $form_values['booking_reference'],
                    'checkin_date' => $this->format_datetime_for_database($form_values['checkin_date']),
                    'checkout_date' => $this->format_datetime_for_database($form_values['checkout_date']),
                    'status' => $form_values['status'],
                );

                $result = GMS_Database::createReservation($reservation_data);

                if ($result) {
                    $redirect_url = add_query_arg(
                        array(
                            'page' => 'guest-management-reservations',
                            'gms_reservation_created' => 1,
                        ),
                        admin_url('admin.php')
                    );

                    wp_safe_redirect($redirect_url);
                    exit;
                }

                $errors[] = __('Unable to create reservation. Please try again.', 'guest-management-system');
            }
        }

        $cancel_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Add New Reservation', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($cancel_url); ?>" class="page-title-action"><?php esc_html_e('Back to Reservations', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if (!empty($errors)) : ?>
                <div class="notice notice-error">
                    <ul>
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('gms_create_reservation'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="gms_guest_name"><?php esc_html_e('Guest Name', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_name" type="text" id="gms_guest_name" value="<?php echo esc_attr($form_values['guest_name']); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_email"><?php esc_html_e('Guest Email', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_email" type="email" id="gms_guest_email" value="<?php echo esc_attr($form_values['guest_email']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_phone"><?php esc_html_e('Guest Phone', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_phone" type="text" id="gms_guest_phone" value="<?php echo esc_attr($form_values['guest_phone']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_property_name"><?php esc_html_e('Property Name', 'guest-management-system'); ?></label></th>
                            <td><input name="property_name" type="text" id="gms_property_name" value="<?php echo esc_attr($form_values['property_name']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_property_id"><?php esc_html_e('Property ID', 'guest-management-system'); ?></label></th>
                            <td><input name="property_id" type="text" id="gms_property_id" value="<?php echo esc_attr($form_values['property_id']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_booking_reference"><?php esc_html_e('Booking Reference', 'guest-management-system'); ?></label></th>
                            <td><input name="booking_reference" type="text" id="gms_booking_reference" value="<?php echo esc_attr($form_values['booking_reference']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_checkin_date"><?php esc_html_e('Check-in Date', 'guest-management-system'); ?></label></th>
                            <td><input name="checkin_date" type="datetime-local" id="gms_checkin_date" value="<?php echo esc_attr($form_values['checkin_date']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_checkout_date"><?php esc_html_e('Check-out Date', 'guest-management-system'); ?></label></th>
                            <td><input name="checkout_date" type="datetime-local" id="gms_checkout_date" value="<?php echo esc_attr($form_values['checkout_date']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_status"><?php esc_html_e('Status', 'guest-management-system'); ?></label></th>
                            <td>
                                <select name="status" id="gms_status">
                                    <?php
                                    $statuses = array(
                                        'pending' => __('Pending', 'guest-management-system'),
                                        'confirmed' => __('Confirmed', 'guest-management-system'),
                                        'cancelled' => __('Cancelled', 'guest-management-system'),
                                    );
                                    foreach ($statuses as $status_key => $status_label) :
                                        ?>
                                        <option value="<?php echo esc_attr($status_key); ?>"<?php selected($form_values['status'], $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Create Reservation', 'guest-management-system')); ?>
            </form>
        </div>
        <?php
    }

    protected function render_reservation_edit_page($reservation_id) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $reservation_id = absint($reservation_id);

        if (!$reservation_id) {
            $this->render_missing_reservation_notice();
            return;
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            $this->render_missing_reservation_notice();
            return;
        }

        $form_values = array(
            'guest_name' => isset($reservation['guest_name']) ? $reservation['guest_name'] : '',
            'guest_email' => isset($reservation['guest_email']) ? $reservation['guest_email'] : '',
            'guest_phone' => isset($reservation['guest_phone']) ? $reservation['guest_phone'] : '',
            'property_name' => isset($reservation['property_name']) ? $reservation['property_name'] : '',
            'property_id' => isset($reservation['property_id']) ? $reservation['property_id'] : '',
            'booking_reference' => isset($reservation['booking_reference']) ? $reservation['booking_reference'] : '',
            'checkin_date' => $this->format_datetime_for_input(isset($reservation['checkin_date']) ? $reservation['checkin_date'] : ''),
            'checkout_date' => $this->format_datetime_for_input(isset($reservation['checkout_date']) ? $reservation['checkout_date'] : ''),
            'status' => isset($reservation['status']) ? $reservation['status'] : 'pending',
        );

        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('gms_edit_reservation_' . $reservation_id);

            $posted_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

            if ($posted_id !== $reservation_id) {
                $errors[] = __('Invalid reservation request. Please try again.', 'guest-management-system');
            }

            $fields = array_keys($form_values);

            foreach ($fields as $field) {
                if (!isset($_POST[$field])) {
                    $form_values[$field] = '';
                    continue;
                }

                $value = wp_unslash($_POST[$field]);

                switch ($field) {
                    case 'guest_email':
                        $form_values[$field] = sanitize_email($value);
                        break;
                    case 'checkin_date':
                    case 'checkout_date':
                        $form_values[$field] = $this->format_datetime_for_input($value);
                        break;
                    default:
                        $form_values[$field] = sanitize_text_field($value);
                        break;
                }
            }

            if (empty($form_values['guest_name'])) {
                $errors[] = __('Guest name is required.', 'guest-management-system');
            }

            if ($form_values['guest_email'] !== '' && !is_email($form_values['guest_email'])) {
                $errors[] = __('Please enter a valid email address.', 'guest-management-system');
            }

            $allowed_statuses = array('pending', 'confirmed', 'cancelled');
            if (!in_array($form_values['status'], $allowed_statuses, true)) {
                $form_values['status'] = 'pending';
            }

            if (empty($errors)) {
                $update_data = array(
                    'guest_name' => $form_values['guest_name'],
                    'guest_email' => $form_values['guest_email'],
                    'guest_phone' => $form_values['guest_phone'],
                    'property_name' => $form_values['property_name'],
                    'property_id' => $form_values['property_id'],
                    'booking_reference' => $form_values['booking_reference'],
                    'checkin_date' => $this->format_datetime_for_database($form_values['checkin_date']),
                    'checkout_date' => $this->format_datetime_for_database($form_values['checkout_date']),
                    'status' => $form_values['status'],
                );

                $updated = GMS_Database::updateReservation($reservation_id, $update_data);

                if ($updated) {
                    $redirect_url = add_query_arg(
                        array(
                            'page' => 'guest-management-reservations',
                            'action' => 'edit',
                            'reservation_id' => $reservation_id,
                            'gms_reservation_updated' => 1,
                        ),
                        admin_url('admin.php')
                    );

                    wp_safe_redirect($redirect_url);
                    exit;
                }

                $errors[] = __('Unable to update reservation. Please try again.', 'guest-management-system');
            }
        }

        $cancel_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Edit Reservation', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($cancel_url); ?>" class="page-title-action"><?php esc_html_e('Back to Reservations', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['gms_reservation_updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Reservation updated successfully.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)) : ?>
                <div class="notice notice-error">
                    <ul>
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('gms_edit_reservation_' . $reservation_id); ?>
                <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="gms_guest_name_edit"><?php esc_html_e('Guest Name', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_name" type="text" id="gms_guest_name_edit" value="<?php echo esc_attr($form_values['guest_name']); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_email_edit"><?php esc_html_e('Guest Email', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_email" type="email" id="gms_guest_email_edit" value="<?php echo esc_attr($form_values['guest_email']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_phone_edit"><?php esc_html_e('Guest Phone', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_phone" type="text" id="gms_guest_phone_edit" value="<?php echo esc_attr($form_values['guest_phone']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_property_name_edit"><?php esc_html_e('Property Name', 'guest-management-system'); ?></label></th>
                            <td><input name="property_name" type="text" id="gms_property_name_edit" value="<?php echo esc_attr($form_values['property_name']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_property_id_edit"><?php esc_html_e('Property ID', 'guest-management-system'); ?></label></th>
                            <td><input name="property_id" type="text" id="gms_property_id_edit" value="<?php echo esc_attr($form_values['property_id']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_booking_reference_edit"><?php esc_html_e('Booking Reference', 'guest-management-system'); ?></label></th>
                            <td><input name="booking_reference" type="text" id="gms_booking_reference_edit" value="<?php echo esc_attr($form_values['booking_reference']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_checkin_date_edit"><?php esc_html_e('Check-in Date', 'guest-management-system'); ?></label></th>
                            <td><input name="checkin_date" type="datetime-local" id="gms_checkin_date_edit" value="<?php echo esc_attr($form_values['checkin_date']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_checkout_date_edit"><?php esc_html_e('Check-out Date', 'guest-management-system'); ?></label></th>
                            <td><input name="checkout_date" type="datetime-local" id="gms_checkout_date_edit" value="<?php echo esc_attr($form_values['checkout_date']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_status_edit"><?php esc_html_e('Status', 'guest-management-system'); ?></label></th>
                            <td>
                                <select name="status" id="gms_status_edit">
                                    <?php
                                    $statuses = array(
                                        'pending' => __('Pending', 'guest-management-system'),
                                        'confirmed' => __('Confirmed', 'guest-management-system'),
                                        'cancelled' => __('Cancelled', 'guest-management-system'),
                                    );
                                    foreach ($statuses as $status_key => $status_label) :
                                        ?>
                                        <option value="<?php echo esc_attr($status_key); ?>"<?php selected($form_values['status'], $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Update Reservation', 'guest-management-system')); ?>
            </form>
        </div>
        <?php
    }

    protected function render_missing_reservation_notice() {
        $cancel_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Edit Reservation', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($cancel_url); ?>" class="page-title-action"><?php esc_html_e('Back to Reservations', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <div class="notice notice-error">
                <p><?php esc_html_e('The requested reservation could not be found.', 'guest-management-system'); ?></p>
            </div>
        </div>
        <?php
    }

    protected function format_datetime_for_input($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d\TH:i', $timestamp);
    }

    protected function format_datetime_for_database($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    public function render_guests_page() {
        $guests_table = new GMS_Guests_List_Table();
        $guests_table->prepare_items();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guests', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Manage guest records, contact information, and stay history from this section.', 'guest-management-system'); ?></p>

            <form method="get">
                <input type="hidden" name="page" value="guest-management-guests" />
                <?php $guests_table->search_box(__('Search Guests', 'guest-management-system'), 'gms-guests'); ?>
                <?php $guests_table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function render_communications_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Communications & Logs', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Review automated emails, SMS activity, and other communication logs here.', 'guest-management-system'); ?></p>
        </div>
        <?php
    }

    public function render_templates_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('gms_settings_messages', 'gms_settings_updated', __('Settings saved.', 'guest-management-system'), 'updated');
        }

        ?>
        <div class="wrap gms-settings">
            <h1 class="wp-heading-inline"><?php esc_html_e('Templates', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Customize notification and agreement templates used throughout the guest journey.', 'guest-management-system'); ?></p>

            <?php settings_errors('gms_settings_messages'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('gms_settings_templates');
                do_settings_sections('gms_settings_templates');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tabs = $this->get_settings_tabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        if (!array_key_exists($current_tab, $tabs)) {
            $current_tab = 'general';
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('gms_settings_messages', 'gms_settings_updated', __('Settings saved.', 'guest-management-system'), 'updated');
        }

        ?>
        <div class="wrap gms-settings">
            <h1><?php esc_html_e('Guest Management Settings', 'guest-management-system'); ?></h1>
            <?php settings_errors('gms_settings_messages'); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab => $label) :
                    $tab_url = add_query_arg(array('tab' => $tab));
                    $active_class = $tab === $current_tab ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>" class="nav-tab<?php echo esc_attr($active_class); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </h2>

            <form action="options.php" method="post">
                <?php
                switch ($current_tab) {
                    case 'integrations':
                        settings_fields('gms_settings_integrations');
                        do_settings_sections('gms_settings_integrations');
                        break;

                    case 'branding':
                        settings_fields('gms_settings_branding');
                        do_settings_sections('gms_settings_branding');
                        break;

                    case 'templates':
                        settings_fields('gms_settings_templates');
                        do_settings_sections('gms_settings_templates');
                        break;

                    case 'general':
                    default:
                        settings_fields('gms_settings_general');
                        do_settings_sections('gms_settings_general');
                        break;
                }

                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
