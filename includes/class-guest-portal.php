<?php
/**
 * File: class-guest-portal.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-guest-portal.php
 * 
 * Guest Portal Handler for Guest Management System - WITH PDF DOWNLOAD
 */

class GMS_Guest_Portal {
    
    public function __construct() {
        add_action('wp_ajax_gms_submit_agreement', array($this, 'submitAgreement'));
        add_action('wp_ajax_nopriv_gms_submit_agreement', array($this, 'submitAgreement'));
        add_action('wp_ajax_gms_update_contact_info', array($this, 'updateContactInfo'));
        add_action('wp_ajax_nopriv_gms_update_contact_info', array($this, 'updateContactInfo'));
        add_action('wp_ajax_gms_create_verification_session', array($this, 'createVerificationSession'));
        add_action('wp_ajax_nopriv_gms_create_verification_session', array($this, 'createVerificationSession'));
        add_action('wp_ajax_gms_check_verification_status', array($this, 'checkVerificationStatus'));
        add_action('wp_ajax_nopriv_gms_check_verification_status', array($this, 'checkVerificationStatus'));
    }
    
    public static function displayPortal($token) {
        $reservation = GMS_Database::getReservationByToken($token);
        
        if (!$reservation) {
            self::displayError('Invalid or expired portal link.');
            return;
        }
        
        // Check if already completed
        $agreement = GMS_Database::getAgreementByReservation($reservation['id']);
        $verification = GMS_Database::getVerificationByReservation($reservation['id']);
        
        $is_complete = $agreement && $verification &&
                      $agreement['status'] === 'signed' &&
                      $verification['verification_status'] === 'verified';

        self::displayPortalInterface($reservation, $agreement, $verification, $is_complete);
    }

    private static function displayPortalInterface($reservation, $agreement, $verification, $is_complete = false) {
        $company_name = get_option('gms_company_name', get_option('blogname'));
        $company_logo = get_option('gms_company_logo');
        $primary_color = get_option('gms_portal_primary_color', '#0073aa');

        $door_code = '';
        if (!empty($reservation['door_code'])) {
            $door_code = GMS_Database::sanitizeDoorCode($reservation['door_code']);
        }
        $secondary_color = get_option('gms_portal_secondary_color', '#005a87');

        $contact_first_name = '';
        $contact_last_name = '';

        if (!empty($reservation['guest_name'])) {
            list($contact_first_name, $contact_last_name) = self::splitGuestName($reservation['guest_name']);
        }

        $contact_email = trim((string) ($reservation['guest_email'] ?? ''));
        $contact_phone = trim((string) ($reservation['guest_phone'] ?? ''));

        if ($contact_first_name === '' || $contact_last_name === '') {
            list($split_first, $split_last) = self::splitGuestName($reservation['guest_name'] ?? '');
            if ($contact_first_name === '') {
                $contact_first_name = $split_first;
            }
            if ($contact_last_name === '') {
                $contact_last_name = $split_last;
            }
        }

        $contact_first_name = sanitize_text_field($contact_first_name);
        $contact_last_name = sanitize_text_field($contact_last_name);
        $contact_email = sanitize_email($contact_email);
        $contact_phone = function_exists('gms_sanitize_phone')
            ? gms_sanitize_phone($contact_phone)
            : preg_replace('/[^0-9+]/', '', $contact_phone);

        $contact_full_name = trim($contact_first_name . ' ' . $contact_last_name);

        if ($contact_full_name !== '') {
            $reservation['guest_name'] = $contact_full_name;
        }

        if ($contact_email !== '') {
            $reservation['guest_email'] = $contact_email;
        }

        if ($contact_phone !== '') {
            $reservation['guest_phone'] = $contact_phone;
        }

        $contact_info_complete = $contact_first_name !== '' && $contact_last_name !== '' && $contact_email !== '' && $contact_phone !== '';

        $contact_phone_display = $contact_phone;
        if ($contact_phone_display !== '' && function_exists('gms_format_phone')) {
            $formatted_phone = gms_format_phone($contact_phone_display);
            if (!empty($formatted_phone)) {
                $contact_phone_display = $formatted_phone;
            }
        }

        $display_guest_name = $reservation['guest_name'];

        $agreement_template = get_option('gms_agreement_template', '');

        if (!is_string($agreement_template) || trim($agreement_template) === '') {
            self::displayError(__('The agreement template is not configured. Please contact the property manager.', 'gms'));
            return;
        }

        $agreement_display = self::renderAgreementTemplate($reservation, $company_name, $agreement_template);

        $custom_cards = GMS_Database::get_portal_cards($reservation['id']);

        $host_text_number = sanitize_text_field(get_option('gms_voipms_did', ''));
        $host_text_display = $host_text_number;
        if ($host_text_display !== '' && function_exists('gms_format_phone')) {
            $formatted_host_phone = gms_format_phone($host_text_display);
            if (!empty($formatted_host_phone)) {
                $host_text_display = $formatted_host_phone;
            }
        }
        $host_text_link = '';
        if ($host_text_number !== '') {
            $link_number = preg_replace('/[^0-9+]/', '', $host_text_number);
            if ($link_number !== '') {
                if (strpos($link_number, '+') !== 0) {
                    $link_number = '+' . ltrim($link_number, '+');
                }
                $host_text_link = 'sms:' . $link_number;
            }
        }

        $checkin_timestamp = !empty($reservation['checkin_date']) ? strtotime($reservation['checkin_date']) : false;
        $checkout_timestamp = !empty($reservation['checkout_date']) ? strtotime($reservation['checkout_date']) : false;

        $checkin_date_display = $checkin_timestamp ? date('M j, Y', $checkin_timestamp) : '';
        $checkin_time_display = $checkin_timestamp ? date('g:i A', $checkin_timestamp) : '';
        $checkin_day_long = $checkin_timestamp ? date('l, F j', $checkin_timestamp) : '';

        $checkout_date_display = $checkout_timestamp ? date('M j, Y', $checkout_timestamp) : '';
        $checkout_time_display = $checkout_timestamp ? date('g:i A', $checkout_timestamp) : '';

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Guest Check-in Portal - <?php echo esc_html($company_name); ?></title>
            <style>
                :root {
                    --portal-primary: <?php echo esc_attr($primary_color); ?>;
                    --portal-secondary: <?php echo esc_attr($secondary_color); ?>;
                }

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    line-height: 1.6;
                    color: #0f172a;
                    background: #edf2f7;
                }

                .portal-container {
                    background: linear-gradient(155deg, rgba(255, 255, 255, 0.9), rgba(226, 232, 240, 0.6));
                    min-height: 100vh;
                }

                .portal-header {
                    background: linear-gradient(135deg, var(--portal-primary), var(--portal-secondary));
                    color: #fff;
                    text-align: center;
                    padding: 3.5rem 1.5rem 6rem;
                }

                .company-logo {
                    max-width: 220px;
                    margin: 0 auto 1.5rem;
                    display: block;
                }

                .portal-header h1 {
                    font-size: 2.4rem;
                    margin-bottom: 0.5rem;
                    letter-spacing: -0.5px;
                }

                .portal-header p {
                    font-size: 1.05rem;
                    opacity: 0.85;
                }

                .portal-content {
                    max-width: 960px;
                    margin: -5rem auto 0;
                    padding: 0 1.75rem 4rem;
                    position: relative;
                }

                .portal-complete-banner {
                    display: flex;
                    align-items: flex-start;
                    gap: 1.25rem;
                    background: rgba(34, 197, 94, 0.16);
                    border: 1px solid rgba(34, 197, 94, 0.35);
                    padding: 1.25rem 1.5rem;
                    border-radius: 18px;
                    color: #166534;
                    margin-bottom: 2rem;
                    box-shadow: 0 18px 35px rgba(15, 23, 42, 0.08);
                }

                .portal-complete-banner h3 {
                    font-size: 1.25rem;
                    margin-bottom: 0.35rem;
                }

                .portal-complete-banner p {
                    color: rgba(22, 101, 52, 0.85);
                    font-weight: 500;
                }

                .portal-complete-icon {
                    font-size: 2rem;
                    line-height: 1;
                    background: rgba(34, 197, 94, 0.24);
                    padding: 0.9rem;
                    border-radius: 16px;
                }

                .portal-hero {
                    background: #fff;
                    border-radius: 24px;
                    padding: 2.75rem 2.5rem;
                    text-align: center;
                    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
                    border: 1px solid rgba(148, 163, 184, 0.2);
                    margin-bottom: 2rem;
                }

                .portal-hero__eyebrow {
                    text-transform: uppercase;
                    letter-spacing: 0.18em;
                    font-size: 0.75rem;
                    color: rgba(15, 23, 42, 0.55);
                    margin-bottom: 1rem;
                    font-weight: 600;
                }

                .portal-hero h2 {
                    font-size: 2.15rem;
                    margin-bottom: 0.75rem;
                    color: #0f172a;
                }

                .portal-hero p {
                    font-size: 1.05rem;
                    color: #475569;
                }

                .portal-cards {
                    display: flex;
                    flex-direction: column;
                    gap: 1.75rem;
                }

                .portal-card {
                    background: #fff;
                    border-radius: 20px;
                    border: 1px solid rgba(203, 213, 225, 0.7);
                    box-shadow: 0 22px 55px rgba(15, 23, 42, 0.12);
                    overflow: hidden;
                }

                .portal-card__header {
                    display: flex;
                    align-items: center;
                    gap: 1.1rem;
                    margin-bottom: 1.25rem;
                }

                .portal-card__icon {
                    font-size: 2.2rem;
                    line-height: 1;
                }

                .portal-card__title {
                    font-size: 1.35rem;
                    font-weight: 700;
                    color: #0f172a;
                }

                .portal-card__subtitle {
                    font-size: 0.95rem;
                    color: #64748b;
                    margin-top: 0.15rem;
                }

                .portal-card__body {
                    padding: 0 2rem 2rem;
                }

                .portal-card--door {
                    background: linear-gradient(140deg, var(--portal-primary), var(--portal-secondary));
                    color: #fff;
                    border: none;
                    padding: 2.25rem 2.5rem;
                    box-shadow: 0 26px 65px rgba(15, 23, 42, 0.25);
                }

                .portal-card--door .portal-card__title {
                    color: #fff;
                }

                .portal-card--door .portal-card__subtitle {
                    color: rgba(255, 255, 255, 0.75);
                }

                .portal-card--door .portal-card__body {
                    padding: 0;
                }

                .door-code-display {
                    font-size: 3rem;
                    font-weight: 800;
                    text-align: center;
                    letter-spacing: 0.35rem;
                    margin-bottom: 0.75rem;
                    text-transform: uppercase;
                }

                .door-code-note {
                    text-align: center;
                    font-size: 0.95rem;
                    color: rgba(255, 255, 255, 0.85);
                }

                .door-code-note--muted {
                    color: rgba(255, 255, 255, 0.85);
                }

                .portal-accordion {
                    padding: 0;
                }

                .portal-accordion summary {
                    list-style: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    gap: 1.1rem;
                    padding: 1.6rem 2rem;
                    font-weight: 600;
                    color: #0f172a;
                }

                .portal-accordion summary::-webkit-details-marker {
                    display: none;
                }

                .portal-card__chevron {
                    margin-left: auto;
                    font-size: 1.2rem;
                    color: #94a3b8;
                    transition: transform 0.25s ease;
                }

                .portal-accordion[open] .portal-card__chevron {
                    transform: rotate(180deg);
                }

                .booking-details {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 1.25rem;
                }

                .detail-item {
                    padding: 1.25rem 1.5rem;
                    border-radius: 14px;
                    background: #f8fafc;
                    border: 1px solid rgba(226, 232, 240, 0.8);
                }

                .detail-label {
                    font-size: 0.8rem;
                    text-transform: uppercase;
                    letter-spacing: 0.14em;
                    color: #64748b;
                    margin-bottom: 0.4rem;
                    font-weight: 700;
                }

                .detail-value {
                    font-size: 1.15rem;
                    font-weight: 600;
                    color: #0f172a;
                }

                .progress-bar {
                    width: 100%;
                    background: rgba(148, 163, 184, 0.25);
                    border-radius: 999px;
                    height: 10px;
                    margin-bottom: 1.25rem;
                    overflow: hidden;
                }

                .progress-fill {
                    height: 100%;
                    width: 0;
                    background: var(--portal-primary);
                    border-radius: 999px;
                    transition: width 0.4s ease;
                }

                .checklist {
                    display: flex;
                    flex-direction: column;
                    gap: 1rem;
                }

                .checklist-item {
                    display: flex;
                    align-items: flex-start;
                    gap: 1.1rem;
                    border-radius: 16px;
                    padding: 1.2rem 1.4rem;
                    background: #f8fafc;
                    border: 1px solid rgba(226, 232, 240, 0.9);
                    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
                }

                .checklist-item.completed {
                    border-color: var(--portal-primary);
                    background: rgba(59, 130, 246, 0.08);
                    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
                }

                .checklist-icon {
                    width: 44px;
                    height: 44px;
                    border-radius: 50%;
                    background: rgba(148, 163, 184, 0.25);
                    color: #475569;
                    font-weight: 700;
                    font-size: 1.1rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                }

                .checklist-item.completed .checklist-icon {
                    background: var(--portal-primary);
                    color: #fff;
                }

                .checklist-title {
                    font-weight: 700;
                    font-size: 1.05rem;
                    color: #0f172a;
                }

                .checklist-description {
                    color: #64748b;
                    font-size: 0.95rem;
                    margin-top: 0.25rem;
                }

                .contact-summary {
                    background: #f8fafc;
                    border: 1px solid rgba(226, 232, 240, 0.9);
                    border-radius: 14px;
                    padding: 1.2rem 1.5rem;
                    margin-bottom: 1rem;
                }

                .contact-summary ul {
                    list-style: none;
                }

                .contact-summary li {
                    margin-bottom: 0.5rem;
                    color: #475569;
                }

                .text-muted {
                    color: #64748b;
                }

                .form-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 1.25rem;
                    margin-bottom: 1.5rem;
                }

                .form-group label {
                    font-weight: 600;
                    margin-bottom: 0.4rem;
                }

                .form-group input {
                    padding: 0.85rem 1rem;
                    border-radius: 10px;
                    border: 1px solid rgba(148, 163, 184, 0.6);
                    font-size: 1rem;
                    transition: border-color 0.2s ease, box-shadow 0.2s ease;
                }

                .form-group input:focus {
                    border-color: var(--portal-primary);
                    outline: none;
                    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
                }

                .checkbox-group {
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    margin: 1.25rem 0;
                }

                .checkbox-group input[type="checkbox"] {
                    transform: scale(1.2);
                }

                .agreement-text {
                    background: #f8fafc;
                    padding: 1.25rem;
                    border-radius: 14px;
                    border: 1px solid rgba(226, 232, 240, 0.9);
                    max-height: 280px;
                    overflow-y: auto;
                    font-size: 0.95rem;
                }

                .signature-section {
                    margin: 1.5rem 0;
                }

                .signature-canvas {
                    border: 2px dashed rgba(148, 163, 184, 0.7);
                    border-radius: 12px;
                    width: 100%;
                    max-width: 640px;
                    height: 200px;
                    background: #fff;
                }

                .signature-controls {
                    margin-top: 0.75rem;
                }

                .btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 0.35rem;
                    padding: 0.9rem 1.6rem;
                    border-radius: 12px;
                    font-weight: 600;
                    font-size: 1rem;
                    text-decoration: none;
                    border: none;
                    cursor: pointer;
                    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
                }

                .btn-primary {
                    background: var(--portal-primary);
                    color: #fff;
                    box-shadow: 0 10px 22px rgba(59, 130, 246, 0.3);
                }

                .btn-primary:hover {
                    transform: translateY(-2px);
                    background: var(--portal-secondary);
                }

                .btn-secondary {
                    background: #0f172a;
                    color: #fff;
                }

                .btn-outline {
                    background: transparent;
                    border: 2px solid var(--portal-primary);
                    color: var(--portal-primary);
                }

                .btn-success {
                    background: #16a34a;
                    color: #fff;
                }

                .btn-light {
                    background: #fff;
                    color: var(--portal-primary);
                    border: 2px solid rgba(148, 163, 184, 0.4);
                    box-shadow: 0 6px 16px rgba(148, 163, 184, 0.25);
                }

                .btn:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                    box-shadow: none;
                    transform: none;
                }

                .portal-card--success {
                    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(56, 189, 248, 0.12));
                    border: 1px solid rgba(45, 212, 191, 0.35);
                }

                .portal-card--success h3 {
                    color: #0f766e;
                }

                .portal-card--link {
                    display: flex;
                    align-items: center;
                    gap: 1.4rem;
                    padding: 1.8rem 2rem;
                    text-decoration: none;
                    color: #0f172a;
                    transition: transform 0.2s ease, box-shadow 0.2s ease;
                }

                .portal-card--link:hover {
                    transform: translateY(-4px);
                    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.16);
                }

                .portal-card--link .portal-card__icon {
                    background: rgba(255, 255, 255, 0.85);
                    color: var(--portal-primary);
                    padding: 0.85rem;
                    border-radius: 16px;
                    font-size: 1.8rem;
                }

                .portal-card__cta {
                    margin-left: auto;
                    font-weight: 600;
                    color: var(--portal-primary);
                    display: flex;
                    align-items: center;
                    gap: 0.35rem;
                }

                .portal-card--contact .portal-card__body {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    justify-content: space-between;
                    gap: 1rem;
                }

                .portal-card__copy {
                    color: #475569;
                    font-size: 1rem;
                }

                .portal-card__copy p {
                    margin-bottom: 0.75rem;
                }

                .portal-card--promo .portal-card__icon {
                    background: rgba(59, 130, 246, 0.12);
                    padding: 0.75rem;
                    border-radius: 16px;
                    font-size: 1.6rem;
                }

                .portal-card__cta-button {
                    margin-top: 1rem;
                }

                .loading {
                    text-align: center;
                    padding: 2rem;
                }

                .spinner {
                    border: 4px solid rgba(148, 163, 184, 0.35);
                    border-top: 4px solid var(--portal-primary);
                    border-radius: 50%;
                    width: 42px;
                    height: 42px;
                    animation: spin 1.2s linear infinite;
                    margin: 0 auto 1rem;
                }

                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }

                .success-message {
                    background: rgba(34, 197, 94, 0.12);
                    border: 1px solid rgba(34, 197, 94, 0.35);
                    padding: 1rem 1.25rem;
                    border-radius: 12px;
                    color: #166534;
                    font-weight: 600;
                }

                .error-message {
                    background: rgba(248, 113, 113, 0.12);
                    border: 1px solid rgba(248, 113, 113, 0.4);
                    padding: 1rem 1.25rem;
                    border-radius: 12px;
                    color: #b91c1c;
                    font-weight: 600;
                }

                .hidden {
                    display: none !important;
                }

                @media (max-width: 768px) {
                    .portal-content {
                        margin-top: -4rem;
                        padding: 0 1.25rem 3rem;
                    }

                    .portal-complete-banner {
                        flex-direction: column;
                        align-items: flex-start;
                    }

                    .portal-hero {
                        padding: 2rem 1.75rem;
                    }

                    .portal-card__body {
                        padding: 0 1.5rem 1.5rem;
                    }

                    .portal-accordion summary {
                        padding: 1.35rem 1.5rem;
                    }

                    .booking-details {
                        grid-template-columns: 1fr;
                    }

                    .form-grid {
                        grid-template-columns: 1fr;
                    }

                    .door-code-display {
                        font-size: 2.4rem;
                        letter-spacing: 0.2rem;
                    }
                }
            </style>
        </head>
        <body>
            <div class="portal-container">
                <header class="portal-header">
                    <?php if ($company_logo): ?>
                        <img src="<?php echo esc_url($company_logo); ?>" alt="<?php echo esc_attr($company_name); ?>" class="company-logo">
                    <?php endif; ?>
                    <h1>Welcome to <?php echo esc_html($company_name); ?></h1>
                    <p>Complete your check-in process below</p>
                </header>

                <main class="portal-content">
                    <section class="portal-hero">
                        <?php if (!empty($reservation['property_name'])): ?>
                            <p class="portal-hero__eyebrow"><?php echo esc_html($reservation['property_name']); ?></p>
                        <?php endif; ?>
                        <h2>Hello, <span id="guest-name-display"><?php echo esc_html($display_guest_name); ?></span>!</h2>
                        <p><?php esc_html_e('We\'re excited to host you. Complete the steps below so everything is ready for your arrival.', 'gms'); ?></p>
                    </section>

                    <div class="portal-cards">
                        <div class="portal-complete-banner <?php echo $is_complete ? '' : 'hidden'; ?>" id="portal-complete-banner">
                            <div class="portal-complete-icon" aria-hidden="true">🎉</div>
                            <div>
                                <h3><?php esc_html_e('Check-in steps completed', 'gms'); ?></h3>
                                <p><?php esc_html_e('You can return here anytime for your agreement, door code, and stay details.', 'gms'); ?></p>
                            </div>
                        </div>

                        <section class="portal-card portal-card--door">
                            <div class="portal-card__header">
                                <span class="portal-card__icon" aria-hidden="true">🗝️</span>
                                <div>
                                    <h2 class="portal-card__title"><?php esc_html_e('Door Access', 'gms'); ?></h2>
                                    <p class="portal-card__subtitle"><?php echo $checkin_day_long !== '' ? esc_html(sprintf(__('Activates %s', 'gms'), $checkin_day_long)) : esc_html__('Your personal entry code', 'gms'); ?></p>
                                </div>
                            </div>
                            <div class="portal-card__body">
                                <?php if ($door_code !== ''): ?>
                                    <div class="door-code-display"><?php echo esc_html($door_code); ?></div>
                                    <?php if ($checkin_time_display !== '' || $checkin_day_long !== ''): ?>
                                        <p class="door-code-note"><?php
                                            if ($checkin_day_long !== '' && $checkin_time_display !== '') {
                                                printf(esc_html__('Active %1$s at %2$s', 'gms'), esc_html($checkin_day_long), esc_html($checkin_time_display));
                                            } elseif ($checkin_day_long !== '') {
                                                printf(esc_html__('Active %s', 'gms'), esc_html($checkin_day_long));
                                            } else {
                                                esc_html_e('Use this code at the smart lock when you arrive.', 'gms');
                                            }
                                        ?></p>
                                    <?php else: ?>
                                        <p class="door-code-note"><?php esc_html_e('Use this code at the smart lock when you arrive.', 'gms'); ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="door-code-note door-code-note--muted"><?php esc_html_e('We\'ll send your unique access code closer to arrival.', 'gms'); ?></p>
                                <?php endif; ?>
                            </div>
                        </section>

                        <details class="portal-card portal-accordion" open>
                            <summary>
                                <span class="portal-card__icon" aria-hidden="true">🧭</span>
                                <span class="portal-card__title"><?php esc_html_e('Your check-in checklist', 'gms'); ?></span>
                                <span class="portal-card__chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="portal-card__body">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="progress-fill"></div>
                                </div>
                                <div class="checklist">
                                    <div class="checklist-item <?php echo $contact_info_complete ? 'completed' : ''; ?>" id="contact-checklist">
                                        <div class="checklist-icon"><?php echo $contact_info_complete ? '✓' : '1'; ?></div>
                                        <div class="checklist-content">
                                            <div class="checklist-title"><?php esc_html_e('Confirm guest details', 'gms'); ?></div>
                                            <div class="checklist-description"><?php esc_html_e('Share your contact information so we can keep you updated about your stay.', 'gms'); ?></div>
                                        </div>
                                    </div>
                                    <div class="checklist-item <?php echo ($agreement && $agreement['status'] === 'signed') ? 'completed' : ''; ?>" id="agreement-checklist">
                                        <div class="checklist-icon"><?php echo ($agreement && $agreement['status'] === 'signed') ? '✓' : '2'; ?></div>
                                        <div class="checklist-content">
                                            <div class="checklist-title"><?php esc_html_e('Sign the guest agreement', 'gms'); ?></div>
                                            <div class="checklist-description"><?php esc_html_e('Review the house rules and sign to confirm your reservation.', 'gms'); ?></div>
                                        </div>
                                    </div>
                                    <div class="checklist-item <?php echo ($verification && $verification['verification_status'] === 'verified') ? 'completed' : ''; ?>" id="verification-checklist">
                                        <div class="checklist-icon"><?php echo ($verification && $verification['verification_status'] === 'verified') ? '✓' : '3'; ?></div>
                                        <div class="checklist-content">
                                            <div class="checklist-title"><?php esc_html_e('Identity verification', 'gms'); ?></div>
                                            <div class="checklist-description"><?php esc_html_e('Verify your identity with a government ID and quick selfie for security.', 'gms'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </details>

                        <details class="portal-card portal-accordion" open>
                            <summary>
                                <span class="portal-card__icon" aria-hidden="true">🏡</span>
                                <span class="portal-card__title"><?php esc_html_e('Your stay details', 'gms'); ?></span>
                                <span class="portal-card__chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="portal-card__body">
                                <div class="booking-details">
                                    <div class="detail-item">
                                        <div class="detail-label"><?php esc_html_e('Property', 'gms'); ?></div>
                                        <div class="detail-value"><?php echo esc_html($reservation['property_name']); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label"><?php esc_html_e('Check-in', 'gms'); ?></div>
                                        <?php if ($checkin_date_display !== ''): ?>
                                            <div class="detail-value"><?php echo esc_html($checkin_date_display); ?></div>
                                        <?php endif; ?>
                                        <?php if ($checkin_time_display !== ''): ?>
                                            <div class="detail-value"><?php echo esc_html($checkin_time_display); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label"><?php esc_html_e('Check-out', 'gms'); ?></div>
                                        <?php if ($checkout_date_display !== ''): ?>
                                            <div class="detail-value"><?php echo esc_html($checkout_date_display); ?></div>
                                        <?php endif; ?>
                                        <?php if ($checkout_time_display !== ''): ?>
                                            <div class="detail-value"><?php echo esc_html($checkout_time_display); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label"><?php esc_html_e('Booking reference', 'gms'); ?></div>
                                        <div class="detail-value"><?php echo esc_html($reservation['booking_reference']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </details>

                        <details class="portal-card portal-accordion" open>
                            <summary>
                                <span class="portal-card__icon" aria-hidden="true">👤</span>
                                <span class="portal-card__title"><?php esc_html_e('Guest contact information', 'gms'); ?></span>
                                <span class="portal-card__chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="portal-card__body" id="contact-section">
                                <div class="contact-summary <?php echo $contact_info_complete ? '' : 'hidden'; ?>" id="contact-info-summary">
                                    <p class="text-muted" style="margin-bottom: 0.75rem;"><?php esc_html_e('We\'ll use these details to send arrival information and timely updates about your stay.', 'gms'); ?></p>
                                    <ul>
                                        <li><strong><?php esc_html_e('Name:', 'gms'); ?></strong> <span id="contact-summary-name"><?php echo esc_html($reservation['guest_name']); ?></span></li>
                                        <li><strong><?php esc_html_e('Email:', 'gms'); ?></strong> <span id="contact-summary-email"><?php echo esc_html($reservation['guest_email']); ?></span></li>
                                        <li><strong><?php esc_html_e('Mobile:', 'gms'); ?></strong> <span id="contact-summary-phone"><?php echo esc_html($contact_phone_display); ?></span></li>
                                    </ul>
                                </div>

                                <p class="text-muted" id="contact-section-helper" style="margin-bottom: 1rem;"><?php
                                    if ($contact_info_complete) {
                                        esc_html_e('Need to make a change? Update your contact details below.', 'gms');
                                    } else {
                                        esc_html_e('We need your legal name and contact information before we can confirm the reservation. The remaining steps will unlock once this is saved.', 'gms');
                                    }
                                ?></p>

                                <form id="contact-info-form" novalidate>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="guest-first-name"><?php esc_html_e('First name', 'gms'); ?></label>
                                            <input type="text" id="guest-first-name" name="first_name" value="<?php echo esc_attr($contact_first_name); ?>" required autocomplete="given-name">
                                        </div>
                                        <div class="form-group">
                                            <label for="guest-last-name"><?php esc_html_e('Last name', 'gms'); ?></label>
                                            <input type="text" id="guest-last-name" name="last_name" value="<?php echo esc_attr($contact_last_name); ?>" required autocomplete="family-name">
                                        </div>
                                        <div class="form-group">
                                            <label for="guest-email"><?php esc_html_e('Email', 'gms'); ?></label>
                                            <input type="email" id="guest-email" name="email" value="<?php echo esc_attr($reservation['guest_email']); ?>" required autocomplete="email">
                                        </div>
                                        <div class="form-group">
                                            <label for="guest-phone"><?php esc_html_e('Mobile phone', 'gms'); ?></label>
                                            <input type="tel" id="guest-phone" name="phone" value="<?php echo esc_attr($reservation['guest_phone']); ?>" required autocomplete="tel">
                                        </div>
                                    </div>
                                    <button id="save-contact-info" class="btn btn-primary" type="submit"><?php echo esc_html($contact_info_complete ? __('Update details', 'gms') : __('Save & continue', 'gms')); ?></button>
                                    <div id="contact-info-message"></div>
                                </form>
                            </div>
                        </details>

                        <details class="portal-card portal-accordion requires-contact-info <?php echo $contact_info_complete ? '' : 'hidden'; ?>" <?php echo ($agreement && $agreement['status'] === 'signed') ? '' : 'open'; ?>>
                            <summary>
                                <span class="portal-card__icon" aria-hidden="true">📋</span>
                                <span class="portal-card__title"><?php esc_html_e('Guest agreement', 'gms'); ?></span>
                                <span class="portal-card__chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="portal-card__body" id="agreement-section" <?php echo ($agreement && $agreement['status'] === 'signed') ? 'style="display: none;"' : ''; ?>>
                                <div class="agreement-text" id="agreement-text"><?php echo wp_kses_post($agreement_display); ?></div>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="agreement-checkbox" required>
                                    <label for="agreement-checkbox"><?php esc_html_e('I have read and agree to the terms above.', 'gms'); ?></label>
                                </div>
                                <div class="signature-section">
                                    <label for="signature-canvas"><?php esc_html_e('Your signature', 'gms'); ?>:</label>
                                    <canvas id="signature-canvas" class="signature-canvas"></canvas>
                                    <div class="signature-controls">
                                        <button type="button" id="clear-signature" class="btn btn-outline"><?php esc_html_e('Clear signature', 'gms'); ?></button>
                                    </div>
                                </div>
                                <button id="submit-agreement" class="btn btn-primary" disabled><?php esc_html_e('Submit agreement', 'gms'); ?></button>
                                <div id="agreement-message"></div>
                            </div>
                        </details>

                        <?php if ($agreement && $agreement['status'] === 'signed' && !empty($agreement['pdf_url'])): ?>
                            <section class="portal-card portal-card--success pdf-download-section requires-contact-info <?php echo $contact_info_complete ? '' : 'hidden'; ?>">
                                <div class="portal-card__body">
                                    <h3><?php esc_html_e('Agreement signed successfully', 'gms'); ?></h3>
                                    <p class="portal-card__copy"><?php
                                        printf(esc_html__('Signed on %s. A copy is on the way to your phone.', 'gms'), esc_html(date('F j, Y 	 g:i A', strtotime($agreement['signed_at']))));
                                    ?></p>
                                    <a href="<?php echo esc_url($agreement['pdf_url']); ?>" class="btn btn-success" download="<?php echo esc_attr($reservation['booking_reference']); ?>.pdf"><?php esc_html_e('Download signed agreement (PDF)', 'gms'); ?></a>
                                </div>
                            </section>
                        <?php endif; ?>

                        <details class="portal-card portal-accordion requires-contact-info <?php echo $contact_info_complete ? '' : 'hidden'; ?>" <?php echo ($verification && $verification['verification_status'] === 'verified') ? '' : 'open'; ?>>
                            <summary>
                                <span class="portal-card__icon" aria-hidden="true">🆔</span>
                                <span class="portal-card__title"><?php esc_html_e('Identity verification', 'gms'); ?></span>
                                <span class="portal-card__chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="portal-card__body" id="verification-section" <?php echo (!$agreement || $agreement['status'] !== 'signed') ? 'style="display: none;"' : ''; ?>>
                                <p class="text-muted"><?php esc_html_e('Please verify your identity by uploading a photo of your government-issued ID and a matching selfie. This keeps the property secure for everyone.', 'gms'); ?></p>
                                <div id="verification-content">
                                    <?php if ($verification && $verification['verification_status'] === 'verified'): ?>
                                        <div class="success-message">✅ <?php esc_html_e('Identity verification completed successfully!', 'gms'); ?></div>
                                    <?php elseif ($verification && $verification['verification_status'] === 'processing'): ?>
                                        <div class="loading">
                                            <div class="spinner"></div>
                                            <p><?php esc_html_e('Verifying your identity...', 'gms'); ?></p>
                                            <button id="check-verification" class="btn btn-secondary"><?php esc_html_e('Check status', 'gms'); ?></button>
                                        </div>
                                    <?php else: ?>
                                        <div class="verification-help">
                                            <p class="text-muted" style="margin-bottom: 0.75rem;"><?php esc_html_e('When you start, Stripe will guide you through capturing your ID and a quick selfie. Please make sure you\'re in a well-lit area.', 'gms'); ?></p>
                                        </div>
                                        <button id="start-verification" class="btn btn-primary"><?php esc_html_e('Start identity verification', 'gms'); ?></button>
                                    <?php endif; ?>
                                </div>
                                <div id="verification-message"></div>
                            </div>
                        </details>

                        <a class="portal-card portal-card--link" href="https://240jordanview.com/handbook" target="_blank" rel="noopener noreferrer">
                            <span class="portal-card__icon" aria-hidden="true">📖</span>
                            <div>
                                <h3 class="portal-card__title"><?php esc_html_e('Guest handbook', 'gms'); ?></h3>
                                <p class="portal-card__subtitle"><?php esc_html_e('Wi-Fi info, appliance tips, and favorite local spots—everything you need in one place.', 'gms'); ?></p>
                            </div>
                            <span class="portal-card__cta"><?php esc_html_e('Open', 'gms'); ?> &rarr;</span>
                        </a>

                        <?php if ($host_text_number !== ''): ?>
                            <section class="portal-card portal-card--contact">
                                <div class="portal-card__header">
                                    <span class="portal-card__icon" aria-hidden="true">💬</span>
                                    <div>
                                        <h3 class="portal-card__title"><?php esc_html_e('Text the host', 'gms'); ?></h3>
                                        <p class="portal-card__subtitle"><?php esc_html_e('We\'re just a message away if you need anything.', 'gms'); ?></p>
                                    </div>
                                </div>
                                <div class="portal-card__body">
                                    <div class="portal-card__copy"><?php printf(esc_html__('Send a text to %s and we\'ll respond quickly.', 'gms'), '<strong>' . esc_html($host_text_display) . '</strong>'); ?></div>
                                    <?php if ($host_text_link !== ''): ?>
                                        <a class="btn btn-light" href="<?php echo esc_url($host_text_link); ?>"><?php esc_html_e('Start a text', 'gms'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if (!empty($custom_cards)) : ?>
                            <?php foreach ($custom_cards as $card) : ?>
                                <section class="portal-card portal-card--promo">
                                    <div class="portal-card__header">
                                        <span class="portal-card__icon" aria-hidden="true"><?php echo esc_html($card['icon'] !== '' ? $card['icon'] : '⭐'); ?></span>
                                        <div>
                                            <h3 class="portal-card__title"><?php echo esc_html($card['title']); ?></h3>
                                        </div>
                                    </div>
                                    <div class="portal-card__body">
                                        <?php if (!empty($card['body'])) : ?>
                                            <div class="portal-card__copy"><?php echo wp_kses_post(wpautop($card['body'])); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($card['cta_label']) && !empty($card['cta_url'])) : ?>
                                            <a class="btn btn-primary portal-card__cta-button" href="<?php echo esc_url($card['cta_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($card['cta_label']); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </main>
            </div>
            <script src="https://js.stripe.com/v3/"></script>
            <script>
                // Initialize variables
                let signaturePad = null;
                let stripe = null;
                let contactInfoComplete = <?php echo $contact_info_complete ? 'true' : 'false'; ?>;
                let portalComplete = <?php echo $is_complete ? 'true' : 'false'; ?>;
                let completionStatusReported = portalComplete;
                const reservationId = <?php echo intval($reservation['id']); ?>;
                const portalToken = '<?php echo esc_js($reservation['portal_token']); ?>';
                const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                const guestNonce = '<?php echo wp_create_nonce('gms_guest_nonce'); ?>';
                const contactHelperMessages = {
                    complete: '<?php echo esc_js(__('Need to make a change? Update your contact details below.', 'gms')); ?>',
                    incomplete: '<?php echo esc_js(__('We need your legal name and contact information before we can confirm the reservation. The remaining steps will unlock once this is saved.', 'gms')); ?>'
                };
                const contactSavingMessage = '<?php echo esc_js(__('Saving your details…', 'gms')); ?>';
                const contactButtonLabels = {
                    save: '<?php echo esc_js(__('Save & Continue', 'gms')); ?>',
                    update: '<?php echo esc_js(__('Update Details', 'gms')); ?>',
                    saving: contactSavingMessage
                };
                const contactSuccessMessage = '<?php echo esc_js(__('Contact information saved. You can move on to the next step.', 'gms')); ?>';
                const contactFailureMessage = '<?php echo esc_js(__('Unable to save contact information. Please try again.', 'gms')); ?>';
                const contactNetworkErrorMessage = '<?php echo esc_js(__('We could not save your details due to a network error. Please try again.', 'gms')); ?>';
                const contactMissingConfigMessage = '<?php echo esc_js(__('We could not save your details because the portal is missing required configuration.', 'gms')); ?>';

                window.gmsReservationId = reservationId;
                window.gmsAjaxUrl = ajaxUrl;
                window.gmsNonce = guestNonce;
                window.gmsContactInfoComplete = contactInfoComplete;
                window.gmsContactStrings = {
                    helperComplete: contactHelperMessages.complete,
                    helperIncomplete: contactHelperMessages.incomplete,
                    saving: contactButtonLabels.saving,
                    success: contactSuccessMessage,
                    failure: contactFailureMessage,
                    networkError: contactNetworkErrorMessage,
                    saveLabel: contactButtonLabels.save,
                    updateLabel: contactButtonLabels.update,
                    missingConfig: contactMissingConfigMessage
                };
                
                // Initialize Stripe
                <?php if (get_option('gms_stripe_pk')): ?>
                stripe = Stripe('<?php echo esc_js(get_option('gms_stripe_pk')); ?>');
                <?php endif; ?>
                
                document.addEventListener('DOMContentLoaded', function() {
                    initializeSignaturePad();
                    setupContactForm();
                    toggleContactDependentSections();
                    updateProgress();
                    setupEventListeners();
                });
                
                function initializeSignaturePad() {
                    const canvas = document.getElementById('signature-canvas');
                    if (!canvas) return;
                    
                    // Set canvas size properly
                    const rect = canvas.parentElement.getBoundingClientRect();
                    canvas.width = Math.min(600, rect.width - 40);
                    canvas.height = 200;
                    
                    const ctx = canvas.getContext('2d');
                    let isDrawing = false;
                    let lastX = 0;
                    let lastY = 0;
                    
                    // Set up canvas
                    ctx.strokeStyle = '#000';
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    
                    function draw(e) {
                        if (!isDrawing) return;
                        
                        const rect = canvas.getBoundingClientRect();
                        const x = (e.clientX || e.touches[0].clientX) - rect.left;
                        const y = (e.clientY || e.touches[0].clientY) - rect.top;
                        
                        ctx.beginPath();
                        ctx.moveTo(lastX, lastY);
                        ctx.lineTo(x, y);
                        ctx.stroke();
                        
                        lastX = x;
                        lastY = y;
                    }
                    
                    function startDrawing(e) {
                        isDrawing = true;
                        const rect = canvas.getBoundingClientRect();
                        lastX = (e.clientX || e.touches[0].clientX) - rect.left;
                        lastY = (e.clientY || e.touches[0].clientY) - rect.top;
                    }
                    
                    function stopDrawing() {
                        isDrawing = false;
                        checkFormValidity();
                    }
                    
                    // Mouse events
                    canvas.addEventListener('mousedown', startDrawing);
                    canvas.addEventListener('mousemove', draw);
                    canvas.addEventListener('mouseup', stopDrawing);
                    canvas.addEventListener('mouseout', stopDrawing);
                    
                    // Touch events
                    canvas.addEventListener('touchstart', function(e) {
                        e.preventDefault();
                        startDrawing(e);
                    });
                    canvas.addEventListener('touchmove', function(e) {
                        e.preventDefault();
                        draw(e);
                    });
                    canvas.addEventListener('touchend', function(e) {
                        e.preventDefault();
                        stopDrawing();
                    });
                    
                    signaturePad = {
                        clear: function() {
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            checkFormValidity();
                        },
                        isEmpty: function() {
                            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                            return !imageData.data.some(channel => channel !== 0);
                        },
                        toDataURL: function() {
                            return canvas.toDataURL();
                        }
                    };
                }

                function setupContactForm() {
                    const contactForm = document.getElementById('contact-info-form');
                    if (!contactForm) {
                        return;
                    }

                    const submitBtn = document.getElementById('save-contact-info');
                    const messageDiv = document.getElementById('contact-info-message');
                    const helper = document.getElementById('contact-section-helper');

                    if (helper) {
                        helper.textContent = contactInfoComplete ? contactHelperMessages.complete : contactHelperMessages.incomplete;
                    }

                    const inputs = contactForm.querySelectorAll('input');

                    function updateSubmitState() {
                        if (!submitBtn) {
                            return;
                        }
                        submitBtn.disabled = !contactForm.checkValidity();
                    }

                    inputs.forEach((input) => {
                        input.addEventListener('input', updateSubmitState);
                        input.addEventListener('blur', function() {
                            this.value = this.value.trim();
                            updateSubmitState();
                        });
                    });

                    updateSubmitState();

                    contactForm.addEventListener('submit', function(event) {
                        event.preventDefault();

                        if (!contactForm.checkValidity()) {
                            contactForm.reportValidity();
                            return;
                        }

                        submitContactInfo({
                            contactForm: contactForm,
                            submitBtn: submitBtn,
                            messageDiv: messageDiv,
                            helper: helper,
                            updateSubmitState: updateSubmitState
                        });
                    });
                }

                function toggleContactDependentSections() {
                    const gatedSections = document.querySelectorAll('.requires-contact-info');
                    gatedSections.forEach((section) => {
                        if (contactInfoComplete) {
                            section.classList.remove('hidden');
                        } else {
                            section.classList.add('hidden');
                        }
                    });
                }

                function updateContactSummary(payload) {
                    const summary = document.getElementById('contact-info-summary');
                    if (summary) {
                        if (payload.guest_name) {
                            const nameEl = document.getElementById('contact-summary-name');
                            if (nameEl) {
                                nameEl.textContent = payload.guest_name;
                            }
                        }

                        if (payload.guest_email) {
                            const emailEl = document.getElementById('contact-summary-email');
                            if (emailEl) {
                                emailEl.textContent = payload.guest_email;
                            }
                        }

                        if (payload.display_phone || payload.guest_phone) {
                            const phoneEl = document.getElementById('contact-summary-phone');
                            if (phoneEl) {
                                phoneEl.textContent = payload.display_phone || payload.guest_phone;
                            }
                        }

                        summary.classList.remove('hidden');
                    }

                    const helper = document.getElementById('contact-section-helper');
                    if (helper) {
                        helper.textContent = contactHelperMessages.complete;
                    }
                }

                function submitContactInfo(context) {
                    const { contactForm, submitBtn, messageDiv, helper, updateSubmitState } = context;
                    const wasComplete = contactInfoComplete;

                    const firstNameInput = document.getElementById('guest-first-name');
                    const lastNameInput = document.getElementById('guest-last-name');
                    const emailInput = document.getElementById('guest-email');
                    const phoneInput = document.getElementById('guest-phone');

                    const firstName = firstNameInput ? firstNameInput.value.trim() : '';
                    const lastName = lastNameInput ? lastNameInput.value.trim() : '';
                    const email = emailInput ? emailInput.value.trim() : '';
                    const phone = phoneInput ? phoneInput.value.trim() : '';

                    if (firstNameInput) {
                        firstNameInput.value = firstName;
                    }
                    if (lastNameInput) {
                        lastNameInput.value = lastName;
                    }
                    if (emailInput) {
                        emailInput.value = email;
                    }
                    if (phoneInput) {
                        phoneInput.value = phone;
                    }

                    const canSubmit = reservationId > 0 && ajaxUrl !== '' && guestNonce !== '';
                    const originalLabel = contactInfoComplete ? contactButtonLabels.update : contactButtonLabels.save;

                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = contactButtonLabels.saving;
                    }

                    if (messageDiv) {
                        messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>' + contactSavingMessage + '</p></div>';
                    }

                    if (!canSubmit) {
                        if (messageDiv) {
                            messageDiv.innerHTML = '<div class="error-message">❌ ' + contactMissingConfigMessage + '</div>';
                        }

                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalLabel;
                        }

                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'gms_update_contact_info');
                    formData.append('reservation_id', reservationId);
                    formData.append('nonce', guestNonce);
                    formData.append('first_name', firstName);
                    formData.append('last_name', lastName);
                    formData.append('email', email);
                    formData.append('phone', phone);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            if (data.success) {
                                const payload = data.data || {};
                                contactInfoComplete = true;
                                window.gmsContactInfoComplete = true;
                                updateContactSummary(payload);
                                toggleContactDependentSections();

                                const contactChecklist = document.getElementById('contact-checklist');
                                if (contactChecklist) {
                                    contactChecklist.classList.add('completed');
                                    const icon = contactChecklist.querySelector('.checklist-icon');
                                    if (icon) {
                                        icon.textContent = '✓';
                                    }
                                }

                                if (payload.first_name && firstNameInput) {
                                    firstNameInput.value = payload.first_name;
                                }
                                if (payload.last_name && lastNameInput) {
                                    lastNameInput.value = payload.last_name;
                                }
                                if (payload.guest_email && emailInput) {
                                    emailInput.value = payload.guest_email;
                                }
                                if ((payload.guest_phone || payload.display_phone) && phoneInput) {
                                    phoneInput.value = payload.guest_phone || payload.display_phone;
                                }

                                if (helper) {
                                    helper.textContent = contactHelperMessages.complete;
                                }

                                if (messageDiv) {
                                    messageDiv.innerHTML = '<div class="success-message">✅ ' + contactSuccessMessage + '</div>';
                                }

                                const guestNameDisplay = document.getElementById('guest-name-display');
                                if (guestNameDisplay && payload.guest_name) {
                                    guestNameDisplay.textContent = payload.guest_name;
                                }

                                if (payload.agreement_html) {
                                    const agreementText = document.getElementById('agreement-text');
                                    if (agreementText) {
                                        agreementText.innerHTML = payload.agreement_html;
                                    }
                                }

                                updateProgress();

                                if (!wasComplete) {
                                    const agreementSection = document.getElementById('agreement-section');
                                    if (agreementSection) {
                                        setTimeout(() => {
                                            agreementSection.scrollIntoView({ behavior: 'smooth' });
                                        }, 400);
                                    }
                                }
                            } else {
                                if (messageDiv) {
                                    messageDiv.innerHTML = '<div class="error-message">❌ ' + (data.data || contactFailureMessage) + '</div>';
                                }
                            }
                        })
                        .catch(() => {
                            if (messageDiv) {
                                messageDiv.innerHTML = '<div class="error-message">❌ ' + contactNetworkErrorMessage + '</div>';
                            }
                        })
                        .finally(() => {
                            if (submitBtn) {
                                submitBtn.textContent = contactInfoComplete ? contactButtonLabels.update : contactButtonLabels.save;
                                submitBtn.disabled = false;
                                if (typeof updateSubmitState === 'function') {
                                    updateSubmitState();
                                }
                            }
                        });
                }

                function setupEventListeners() {
                    // Agreement checkbox
                    const checkbox = document.getElementById('agreement-checkbox');
                    if (checkbox) {
                        checkbox.addEventListener('change', checkFormValidity);
                    }
                    
                    // Clear signature button
                    const clearBtn = document.getElementById('clear-signature');
                    if (clearBtn) {
                        clearBtn.addEventListener('click', function() {
                            signaturePad.clear();
                        });
                    }
                    
                    // Submit agreement button
                    const submitBtn = document.getElementById('submit-agreement');
                    if (submitBtn) {
                        submitBtn.addEventListener('click', submitAgreement);
                    }
                    
                    // Start verification button
                    const startVerificationBtn = document.getElementById('start-verification');
                    if (startVerificationBtn) {
                        startVerificationBtn.addEventListener('click', startIdentityVerification);
                    }
                    
                    // Check verification button
                    const checkVerificationBtn = document.getElementById('check-verification');
                    if (checkVerificationBtn) {
                        checkVerificationBtn.addEventListener('click', checkVerificationStatus);
                    }
                }
                
                function checkFormValidity() {
                    const checkbox = document.getElementById('agreement-checkbox');
                    const submitBtn = document.getElementById('submit-agreement');
                    
                    if (checkbox && submitBtn && signaturePad) {
                        const isValid = checkbox.checked && !signaturePad.isEmpty();
                        submitBtn.disabled = !isValid;
                    }
                }
                
                function submitAgreement() {
                    const messageDiv = document.getElementById('agreement-message');
                    const submitBtn = document.getElementById('submit-agreement');
                    
                    submitBtn.disabled = true;
                    messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Submitting agreement and generating PDF...</p></div>';
                    
                    const formData = new FormData();
                    formData.append('action', 'gms_submit_agreement');
                    formData.append('reservation_id', reservationId);
                    formData.append('signature_data', signaturePad.toDataURL());
                    formData.append('nonce', guestNonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            messageDiv.innerHTML = '<div class="success-message">✅ Agreement signed successfully! Refreshing page...</div>';
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            messageDiv.innerHTML = '<div class="error-message">❌ Error: ' + (data.data || 'Failed to submit agreement') + '</div>';
                            submitBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        messageDiv.innerHTML = '<div class="error-message">❌ Network error occurred</div>';
                        submitBtn.disabled = false;
                    });
                }
                
                function startIdentityVerification() {
                    if (!stripe) {
                        document.getElementById('verification-message').innerHTML = 
                            '<div class="error-message">❌ Identity verification is not configured</div>';
                        return;
                    }
                    
                    const messageDiv = document.getElementById('verification-message');
                    const startBtn = document.getElementById('start-verification');
                    
                    startBtn.disabled = true;
                    messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Setting up identity verification...</p></div>';
                    
                    const formData = new FormData();
                    formData.append('action', 'gms_create_verification_session');
                    formData.append('reservation_id', reservationId);
                    formData.append('nonce', guestNonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            return stripe.verifyIdentity(data.data.client_secret);
                        } else {
                            throw new Error(data.data || 'Failed to create verification session');
                        }
                    })
                    .then(result => {
                        if (result.error) {
                            throw new Error(result.error.message);
                        } else {
                            // Verification completed, check status
                            checkVerificationStatus();
                        }
                    })
                    .catch(error => {
                        messageDiv.innerHTML = '<div class="error-message">❌ Error: ' + error.message + '</div>';
                        startBtn.disabled = false;
                    });
                }
                
                function checkVerificationStatus() {
                    const messageDiv = document.getElementById('verification-message');
                    
                    const formData = new FormData();
                    formData.append('action', 'gms_check_verification_status');
                    formData.append('reservation_id', reservationId);
                    formData.append('nonce', guestNonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const status = data.data.status;
                            if (status === 'verified') {
                                document.getElementById('verification-content').innerHTML = 
                                    '<div class="success-message">✅ Identity verification completed successfully!</div>';
                                document.getElementById('verification-checklist').classList.add('completed');
                                document.getElementById('verification-checklist').querySelector('.checklist-icon').textContent = '✓';
                                updateProgress();
                            } else if (status === 'requires_input') {
                                messageDiv.innerHTML = '<div class="error-message">❌ Additional information required. Please try again.</div>';
                                document.getElementById('verification-content').innerHTML = 
                                    '<button id="start-verification" class="btn btn-primary">Retry Identity Verification</button>';
                                setupEventListeners();
                            } else {
                                messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Still processing...</p><button id="check-verification" class="btn btn-secondary">Check Again</button></div>';
                                setupEventListeners();
                            }
                        } else {
                            messageDiv.innerHTML = '<div class="error-message">❌ Error checking status: ' + (data.data || 'Unknown error') + '</div>';
                        }
                    })
                    .catch(error => {
                        messageDiv.innerHTML = '<div class="error-message">❌ Network error occurred</div>';
                    });
                }
                
                function updateProgress() {
                    const completedItems = document.querySelectorAll('.checklist-item.completed').length;
                    const totalItems = document.querySelectorAll('.checklist-item').length;
                    const percentage = (completedItems / totalItems) * 100;

                    document.getElementById('progress-fill').style.width = percentage + '%';
                    toggleCompletionState(completedItems === totalItems && totalItems > 0);
                }

                function toggleCompletionState(isComplete) {
                    const banner = document.getElementById('portal-complete-banner');

                    if (isComplete) {
                        if (banner) {
                            banner.classList.remove('hidden');
                        }

                        if (!portalComplete) {
                            portalComplete = true;
                            markReservationCompleted();
                        }
                    } else {
                        if (banner) {
                            banner.classList.add('hidden');
                        }
                        portalComplete = false;
                    }
                }

                function markReservationCompleted() {
                    if (completionStatusReported) {
                        return;
                    }

                    completionStatusReported = true;

                    if (!reservationId || !ajaxUrl || !guestNonce) {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'gms_update_reservation_status');
                    formData.append('reservation_id', reservationId);
                    formData.append('status', 'completed');
                    formData.append('nonce', guestNonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    }).catch(() => {
                        completionStatusReported = false;
                    });
                }
            </script>
        </body>
        </html>
        <?php
    }
    
    private static function splitGuestName($name) {
        $name = trim((string) $name);

        if ($name === '') {
            return array('', '');
        }

        $parts = preg_split('/\s+/', $name);

        if (empty($parts)) {
            return array('', '');
        }

        $first = array_shift($parts);
        $last = trim(implode(' ', $parts));

        return array($first ?? '', $last);
    }

    private static function renderAgreementTemplate($reservation, $company_name, $agreement_template) {
        if (!is_array($reservation)) {
            return $agreement_template;
        }

        $checkin_timestamp = !empty($reservation['checkin_date']) ? strtotime($reservation['checkin_date']) : false;
        $checkout_timestamp = !empty($reservation['checkout_date']) ? strtotime($reservation['checkout_date']) : false;

        $replacements = array(
            '{guest_name}' => isset($reservation['guest_name']) ? $reservation['guest_name'] : '',
            '{guest_email}' => isset($reservation['guest_email']) ? $reservation['guest_email'] : '',
            '{guest_phone}' => isset($reservation['guest_phone']) ? $reservation['guest_phone'] : '',
            '{property_name}' => isset($reservation['property_name']) ? $reservation['property_name'] : '',
            '{booking_reference}' => isset($reservation['booking_reference']) ? $reservation['booking_reference'] : '',
            '{checkin_date}' => $checkin_timestamp ? date('F j, Y', $checkin_timestamp) : '',
            '{checkout_date}' => $checkout_timestamp ? date('F j, Y', $checkout_timestamp) : '',
            '{checkin_time}' => isset($reservation['checkin_time']) ? $reservation['checkin_time'] : '3:00 PM',
            '{checkout_time}' => isset($reservation['checkout_time']) ? $reservation['checkout_time'] : '11:00 AM',
            '{company_name}' => $company_name,
        );

        return str_replace(array_keys($replacements), array_values($replacements), $agreement_template);
    }

    private static function displayError($message) {
        $company_name = get_option('gms_company_name', get_option('blogname'));
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Error - <?php echo esc_html($company_name); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    text-align: center;
                    padding: 2rem;
                    color: #333;
                    background: #f8f9fa;
                }
                .error-container {
                    max-width: 600px;
                    margin: 2rem auto;
                    background: #fff;
                    padding: 3rem 2rem;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .error-icon {
                    font-size: 4rem;
                    color: #e74c3c;
                    margin-bottom: 1rem;
                }
                .error-title {
                    font-size: 2rem;
                    margin-bottom: 1rem;
                    color: #e74c3c;
                }
                .error-message {
                    font-size: 1.1rem;
                    color: #666;
                    margin-bottom: 2rem;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-icon">⚠️</div>
                <h1 class="error-title">Access Error</h1>
                <p class="error-message"><?php echo esc_html($message); ?></p>
                <p>Please contact us if you believe this is an error.</p>
            </div>
        </body>
        </html>
        <?php
    }
    
    public function submitAgreement() {
        if (!wp_verify_nonce($_POST['nonce'], 'gms_guest_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $reservation_id = intval($_POST['reservation_id']);
        $signature_data = sanitize_textarea_field($_POST['signature_data']);
        
        if (empty($signature_data) || $signature_data === 'data:image/png;base64,') {
            wp_send_json_error('Signature is required');
        }
        
        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            wp_send_json_error('Invalid reservation');
        }
        
        // Check if agreement already signed
        $existing_agreement = GMS_Database::getAgreementByReservation($reservation_id);
        if ($existing_agreement && $existing_agreement['status'] === 'signed') {
            wp_send_json_error('Agreement already signed');
        }
        
        $agreement_text = get_option('gms_agreement_template', '');

        if (!is_string($agreement_text) || trim($agreement_text) === '') {
            wp_send_json_error(__('The agreement template is not configured. Please contact the property manager.', 'gms'));
        }

        $agreement_data = array(
            'reservation_id' => $reservation_id,
            'guest_id' => $reservation['guest_id'],
            'agreement_text' => $agreement_text,
            'signature_data' => $signature_data
        );
        
        $agreement_id = GMS_Database::createAgreement($agreement_data);
        
        if ($agreement_id) {
            // Generate PDF if Agreement Handler class exists
            if (class_exists('GMS_Agreement_Handler')) {
                $agreement_handler = new GMS_Agreement_Handler();
                $pdf_result = $agreement_handler->generatePDFForAgreement($agreement_id);
                
                if (!is_wp_error($pdf_result)) {
                    wp_send_json_success(array(
                        'message' => 'Agreement signed successfully',
                        'pdf_url' => $pdf_result['url'],
                        'reload' => true
                    ));
                }
            }
            
            // Success even if PDF generation fails
            wp_send_json_success(array(
                'message' => 'Agreement signed successfully',
                'reload' => true
            ));
        } else {
            wp_send_json_error('Failed to save agreement');
        }
    }

    public function updateContactInfo() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'gms_guest_nonce')) {
            wp_send_json_error(__('Security check failed.', 'gms'));
        }

        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        if ($reservation_id <= 0) {
            wp_send_json_error(__('Invalid reservation.', 'gms'));
        }

        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            wp_send_json_error(__('Invalid reservation.', 'gms'));
        }

        $first_name = sanitize_text_field(trim(wp_unslash($_POST['first_name'] ?? '')));
        $last_name = sanitize_text_field(trim(wp_unslash($_POST['last_name'] ?? '')));
        $email_raw = trim(wp_unslash($_POST['email'] ?? ''));
        $email = sanitize_email($email_raw);
        $phone_raw = trim(wp_unslash($_POST['phone'] ?? ''));
        $phone = function_exists('gms_sanitize_phone')
            ? gms_sanitize_phone($phone_raw)
            : preg_replace('/[^0-9+]/', '', $phone_raw);

        $errors = array();

        if ($first_name === '') {
            $errors[] = __('First name is required.', 'gms');
        }

        if ($last_name === '') {
            $errors[] = __('Last name is required.', 'gms');
        }

        if ($email === '' || !is_email($email)) {
            $errors[] = __('A valid email address is required.', 'gms');
        }

        $numeric_phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone === '' || strlen($numeric_phone) < 7) {
            $errors[] = __('Please enter a valid mobile phone number.', 'gms');
        }

        if (!empty($errors)) {
            wp_send_json_error(implode(' ', array_unique($errors)));
        }

        $full_name = trim($first_name . ' ' . $last_name);

        $guest_data = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'name' => $full_name,
            'email' => $email,
            'phone' => $phone,
        );

        $guest_id = 0;
        $upserted_guest_id = GMS_Database::upsert_guest($guest_data, array('suppress_user_sync' => true));
        if ($upserted_guest_id > 0) {
            $guest_id = $upserted_guest_id;
        } elseif (!empty($reservation['guest_id'])) {
            $guest_id = intval($reservation['guest_id']);
        }

        $update_data = array(
            'guest_name' => $full_name,
            'guest_email' => $email,
            'guest_phone' => $phone,
        );

        if ($guest_id > 0) {
            $update_data['guest_id'] = $guest_id;
        }

        $updated = GMS_Database::updateReservation($reservation_id, $update_data);
        if ($updated === false) {
            wp_send_json_error(__('Unable to update reservation details.', 'gms'));
        }

        $updated_reservation = GMS_Database::getReservationById($reservation_id);
        if (!$updated_reservation) {
            $updated_reservation = array_merge($reservation, $update_data);
            if ($guest_id > 0) {
                $updated_reservation['guest_id'] = $guest_id;
            }
        }

        $company_name = get_option('gms_company_name', get_option('blogname'));
        $agreement_template = get_option('gms_agreement_template', '');
        $agreement_html = '';

        if (is_string($agreement_template) && trim($agreement_template) !== '') {
            $agreement_html = self::renderAgreementTemplate($updated_reservation, $company_name, $agreement_template);
        }

        $display_phone = $phone;
        if ($display_phone !== '' && function_exists('gms_format_phone')) {
            $formatted_phone = gms_format_phone($display_phone);
            if (!empty($formatted_phone)) {
                $display_phone = $formatted_phone;
            }
        }

        wp_send_json_success(array(
            'guest_name' => $full_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'guest_email' => $email,
            'guest_phone' => $phone,
            'display_phone' => $display_phone,
            'agreement_html' => $agreement_html,
        ));
    }

    public function createVerificationSession() {
        if (!wp_verify_nonce($_POST['nonce'], 'gms_guest_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $reservation_id = intval($_POST['reservation_id']);
        
        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            wp_send_json_error('Invalid reservation');
        }
        
        $stripe_integration = new GMS_Stripe_Integration();
        $session = $stripe_integration->createVerificationSession($reservation);
        
        if ($session) {
            // Save verification record
            GMS_Database::createVerification(array(
                'reservation_id' => $reservation_id,
                'guest_id' => $reservation['guest_id'],
                'stripe_session_id' => $session['id'],
                'status' => 'processing'
            ));
            
            wp_send_json_success(array(
                'client_secret' => $session['client_secret']
            ));
        } else {
            wp_send_json_error('Failed to create verification session');
        }
    }
    
    public function checkVerificationStatus() {
        if (!wp_verify_nonce($_POST['nonce'], 'gms_guest_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $reservation_id = intval($_POST['reservation_id']);
        
        $verification = GMS_Database::getVerificationByReservation($reservation_id);
        if (!$verification) {
            wp_send_json_error('No verification found');
        }
        
        // Check status with Stripe
        $stripe_integration = new GMS_Stripe_Integration();
        $status = $stripe_integration->checkVerificationStatus($verification['stripe_verification_session_id']);
        
        if ($status) {
            // Update database
            GMS_Database::updateVerification($verification['stripe_verification_session_id'], array(
                'status' => $status['status'],
                'verification_data' => $status
            ));
            
            wp_send_json_success(array(
                'status' => $status['status'],
                'last_error' => isset($status['last_error']) ? $status['last_error'] : null
            ));
        } else {
            wp_send_json_error('Failed to check verification status');
        }
    }
}