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
        
        if ($is_complete) {
            self::displayCompletionPage($reservation, $agreement, $verification);
            return;
        }
        
        self::displayPortalInterface($reservation, $agreement, $verification);
    }
    
    private static function displayPortalInterface($reservation, $agreement, $verification) {
        $company_name = get_option('gms_company_name', get_option('blogname'));
        $company_logo = get_option('gms_company_logo');
        $primary_color = get_option('gms_portal_primary_color', '#0073aa');

        $door_code = '';
        if (!empty($reservation['door_code'])) {
            $door_code = GMS_Database::sanitizeDoorCode($reservation['door_code']);
        }
        $secondary_color = get_option('gms_portal_secondary_color', '#005a87');

        $guest_profile = null;
        if (!empty($reservation['guest_id'])) {
            $guest_profile = GMS_Database::get_guest_by_id($reservation['guest_id']);
        }

        $contact_first_name = trim((string) ($guest_profile['first_name'] ?? ''));
        $contact_last_name = trim((string) ($guest_profile['last_name'] ?? ''));
        $contact_email = trim((string) ($guest_profile['email'] ?? $reservation['guest_email'] ?? ''));
        $contact_phone = trim((string) ($guest_profile['phone'] ?? $reservation['guest_phone'] ?? ''));

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

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Guest Check-in Portal - <?php echo esc_html($company_name); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    background: #f8f9fa;
                }
                
                .portal-container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: #fff;
                    min-height: 100vh;
                }
                
                .portal-header {
                    background: <?php echo esc_attr($primary_color); ?>;
                    color: #fff;
                    padding: 2rem 1.5rem;
                    text-align: center;
                }
                
                .company-logo {
                    max-width: 200px;
                    margin-bottom: 1rem;
                }
                
                .portal-content {
                    padding: 2rem 1.5rem;
                }
                
                .welcome-section {
                    text-align: center;
                    margin-bottom: 2rem;
                    padding: 1.5rem;
                    background: #f8f9fa;
                    border-radius: 8px;
                }
                
                .booking-details {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 1rem;
                    margin-bottom: 2rem;
                    padding: 1.5rem;
                    background: #f8f9fa;
                    border-radius: 8px;
                }
                
                .detail-item {
                    text-align: center;
                }
                
                .detail-label {
                    font-size: 0.9rem;
                    color: #666;
                    margin-bottom: 0.5rem;
                }
                
                .detail-value {
                    font-weight: 600;
                    font-size: 1.1rem;
                }
                
                .checklist {
                    margin-bottom: 2rem;
                }

                .form-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 1rem;
                    margin-bottom: 1rem;
                }

                .form-group {
                    display: flex;
                    flex-direction: column;
                    text-align: left;
                }

                .form-group label {
                    font-weight: 600;
                    margin-bottom: 0.4rem;
                }

                .form-group input {
                    padding: 0.75rem;
                    border: 1px solid #ccc;
                    border-radius: 4px;
                    font-size: 1rem;
                }

                .text-muted {
                    color: #666;
                    font-size: 0.95rem;
                }

                .contact-summary {
                    background: #f8f9fa;
                    border-radius: 6px;
                    padding: 1rem 1.25rem;
                    margin-bottom: 1rem;
                }

                .contact-summary ul {
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }

                .contact-summary li {
                    margin-bottom: 0.5rem;
                    color: #555;
                }

                .checklist-item {
                    display: flex;
                    align-items: center;
                    padding: 1rem;
                    margin-bottom: 1rem;
                    border: 2px solid #e0e0e0;
                    border-radius: 8px;
                    transition: all 0.3s ease;
                }
                
                .checklist-item.completed {
                    border-color: #27ae60;
                    background: #f8fff8;
                }
                
                .checklist-icon {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-right: 1rem;
                    background: #e0e0e0;
                    color: #666;
                    font-weight: bold;
                }
                
                .checklist-item.completed .checklist-icon {
                    background: #27ae60;
                    color: #fff;
                }
                
                .checklist-content {
                    flex: 1;
                }
                
                .checklist-title {
                    font-weight: 600;
                    margin-bottom: 0.5rem;
                }
                
                .checklist-description {
                    color: #666;
                    font-size: 0.9rem;
                }
                
                .action-section {
                    margin-bottom: 2rem;
                    padding: 1.5rem;
                    border: 1px solid #e0e0e0;
                    border-radius: 8px;
                }
                
                .section-title {
                    font-size: 1.3rem;
                    font-weight: 600;
                    margin-bottom: 1rem;
                    color: <?php echo esc_attr($primary_color); ?>;
                }
                
                .agreement-text {
                    background: #f8f9fa;
                    padding: 1rem;
                    border-radius: 4px;
                    margin-bottom: 1rem;
                    max-height: 200px;
                    overflow-y: auto;
                    font-size: 0.9rem;
                    line-height: 1.5;
                }
                
                .signature-section {
                    margin: 1rem 0;
                }
                
                .signature-canvas {
                    border: 2px dashed #ccc;
                    border-radius: 4px;
                    display: block;
                    margin: 1rem 0;
                    cursor: crosshair;
                    background: #fff;
                    width: 100%;
                    max-width: 600px;
                    height: 200px;
                }
                
                .signature-controls {
                    text-align: center;
                    margin: 1rem 0;
                }
                
                .btn {
                    display: inline-block;
                    padding: 0.75rem 1.5rem;
                    border: none;
                    border-radius: 4px;
                    font-size: 1rem;
                    font-weight: 600;
                    cursor: pointer;
                    text-decoration: none;
                    transition: all 0.3s ease;
                    margin: 0.5rem;
                }
                
                .btn-primary {
                    background: <?php echo esc_attr($primary_color); ?>;
                    color: #fff;
                }
                
                .btn-primary:hover {
                    background: <?php echo esc_attr($secondary_color); ?>;
                }
                
                .btn-secondary {
                    background: #6c757d;
                    color: #fff;
                }
                
                .btn-success {
                    background: #28a745;
                    color: #fff;
                }
                
                .btn-outline {
                    background: transparent;
                    border: 2px solid <?php echo esc_attr($primary_color); ?>;
                    color: <?php echo esc_attr($primary_color); ?>;
                }
                
                .btn:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }
                
                .checkbox-group {
                    display: flex;
                    align-items: center;
                    margin: 1rem 0;
                }
                
                .checkbox-group input[type="checkbox"] {
                    margin-right: 0.5rem;
                    transform: scale(1.2);
                }
                
                .error-message {
                    background: #f8d7da;
                    color: #721c24;
                    padding: 1rem;
                    border-radius: 4px;
                    margin: 1rem 0;
                }
                
                .success-message {
                    background: #d4edda;
                    color: #155724;
                    padding: 1rem;
                    border-radius: 4px;
                    margin: 1rem 0;
                }
                
                .pdf-download-section {
                    background: #d4edda;
                    border: 1px solid #c3e6cb;
                    padding: 1.5rem;
                    border-radius: 8px;
                    margin-bottom: 2rem;
                }
                
                .pdf-download-box {
                    background: white;
                    padding: 1.5rem;
                    border-radius: 4px;
                    margin-top: 1rem;
                }
                
                .loading {
                    text-align: center;
                    padding: 2rem;
                }
                
                .spinner {
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid <?php echo esc_attr($primary_color); ?>;
                    border-radius: 50%;
                    width: 40px;
                    height: 40px;
                    animation: spin 2s linear infinite;
                    margin: 0 auto;
                }
                
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                .progress-bar {
                    width: 100%;
                    background: #e0e0e0;
                    border-radius: 4px;
                    margin: 1rem 0;
                }
                
                .progress-fill {
                    height: 6px;
                    background: <?php echo esc_attr($primary_color); ?>;
                    border-radius: 4px;
                    transition: width 0.3s ease;
                }
                
                @media (max-width: 768px) {
                    .portal-content {
                        padding: 1rem;
                    }

                    .booking-details {
                        grid-template-columns: 1fr;
                        gap: 0.5rem;
                    }

                    .form-grid {
                        grid-template-columns: 1fr;
                    }

                    .detail-item {
                        text-align: left;
                        padding: 0.5rem;
                    }
                    
                    .checklist-item {
                        flex-direction: column;
                        text-align: center;
                    }
                    
                    .checklist-icon {
                        margin-right: 0;
                        margin-bottom: 1rem;
                    }
                    
                    .signature-canvas {
                        width: 100%;
                        height: 150px;
                    }
                }
                
                .hidden {
                    display: none !important;
                }
            </style>
        </head>
        <body>
            <div class="portal-container">
                <div class="portal-header">
                    <?php if ($company_logo): ?>
                        <img src="<?php echo esc_url($company_logo); ?>" alt="<?php echo esc_attr($company_name); ?>" class="company-logo">
                    <?php endif; ?>
                    <h1>Welcome to <?php echo esc_html($company_name); ?></h1>
                    <p>Complete your check-in process below</p>
                </div>
                
                <div class="portal-content">
                    <div class="welcome-section">
                        <h2>Hello, <span id="guest-name-display"><?php echo esc_html($display_guest_name); ?></span>!</h2>
                        <p>We're excited to host you. Please complete the following steps to finalize your check-in.</p>
                    </div>
                    
                    <div class="booking-details">
                        <div class="detail-item">
                            <div class="detail-label">Property</div>
                            <div class="detail-value"><?php echo esc_html($reservation['property_name']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Check-in</div>
                            <div class="detail-value"><?php echo esc_html(date('M j, Y', strtotime($reservation['checkin_date']))); ?></div>
                            <div class="detail-value"><?php echo esc_html(date('g:i A', strtotime($reservation['checkin_date']))); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Check-out</div>
                            <div class="detail-value"><?php echo esc_html(date('M j, Y', strtotime($reservation['checkout_date']))); ?></div>
                            <div class="detail-value"><?php echo esc_html(date('g:i A', strtotime($reservation['checkout_date']))); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Booking Reference</div>
                            <div class="detail-value"><?php echo esc_html($reservation['booking_reference']); ?></div>
                        </div>
                        <?php if ($door_code !== '') : ?>
                        <div class="detail-item">
                            <div class="detail-label">Door Code</div>
                            <div class="detail-value"><?php echo esc_html($door_code); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill" style="width: 0%;"></div>
                    </div>
                    
                    <div class="checklist">
                        <div class="checklist-item <?php echo $contact_info_complete ? 'completed' : ''; ?>" id="contact-checklist">
                            <div class="checklist-icon">
                                <?php echo $contact_info_complete ? '✓' : '1'; ?>
                            </div>
                            <div class="checklist-content">
                                <div class="checklist-title">Confirm Guest Details</div>
                                <div class="checklist-description">Share your contact information so we can finalize your stay</div>
                            </div>
                        </div>

                        <div class="checklist-item <?php echo ($agreement && $agreement['status'] === 'signed') ? 'completed' : ''; ?>" id="agreement-checklist">
                            <div class="checklist-icon">
                                <?php echo ($agreement && $agreement['status'] === 'signed') ? '✓' : '2'; ?>
                            </div>
                            <div class="checklist-content">
                                <div class="checklist-title">Sign Guest Agreement</div>
                                <div class="checklist-description">Review and sign our property agreement</div>
                            </div>
                        </div>

                        <div class="checklist-item <?php echo ($verification && $verification['verification_status'] === 'verified') ? 'completed' : ''; ?>" id="verification-checklist">
                            <div class="checklist-icon">
                                <?php echo ($verification && $verification['verification_status'] === 'verified') ? '✓' : '3'; ?>
                            </div>
                            <div class="checklist-content">
                                <div class="checklist-title">Identity Verification</div>
                                <div class="checklist-description">Verify your identity with your government ID and a live selfie</div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="action-section" id="contact-section">
                        <h3 class="section-title">👤 Guest Details</h3>

                        <div class="contact-summary <?php echo $contact_info_complete ? '' : 'hidden'; ?>" id="contact-info-summary">
                            <p class="text-muted" style="margin-bottom: 0.75rem;">We'll use these details to send arrival information and important updates about your stay.</p>
                            <ul>
                                <li><strong>Name:</strong> <span id="contact-summary-name"><?php echo esc_html($reservation['guest_name']); ?></span></li>
                                <li><strong>Email:</strong> <span id="contact-summary-email"><?php echo esc_html($reservation['guest_email']); ?></span></li>
                                <li><strong>Mobile:</strong> <span id="contact-summary-phone"><?php echo esc_html($contact_phone_display); ?></span></li>
                            </ul>
                        </div>

                        <p class="text-muted" id="contact-section-helper" style="margin-bottom: 1rem;">
                            <?php if ($contact_info_complete): ?>
                                <?php esc_html_e('Need to make a change? Update your contact details below.', 'gms'); ?>
                            <?php else: ?>
                                <?php esc_html_e('We need your legal name and contact information before we can confirm the reservation. The remaining steps will unlock once this is saved.', 'gms'); ?>
                            <?php endif; ?>
                        </p>

                        <form id="contact-info-form" novalidate>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="guest-first-name"><?php esc_html_e('First Name', 'gms'); ?></label>
                                    <input type="text" id="guest-first-name" name="first_name" value="<?php echo esc_attr($contact_first_name); ?>" required autocomplete="given-name">
                                </div>
                                <div class="form-group">
                                    <label for="guest-last-name"><?php esc_html_e('Last Name', 'gms'); ?></label>
                                    <input type="text" id="guest-last-name" name="last_name" value="<?php echo esc_attr($contact_last_name); ?>" required autocomplete="family-name">
                                </div>
                                <div class="form-group">
                                    <label for="guest-email"><?php esc_html_e('Email', 'gms'); ?></label>
                                    <input type="email" id="guest-email" name="email" value="<?php echo esc_attr($reservation['guest_email']); ?>" required autocomplete="email">
                                </div>
                                <div class="form-group">
                                    <label for="guest-phone"><?php esc_html_e('Mobile Phone', 'gms'); ?></label>
                                    <input type="tel" id="guest-phone" name="phone" value="<?php echo esc_attr($reservation['guest_phone']); ?>" required autocomplete="tel">
                                </div>
                            </div>
                            <button id="save-contact-info" class="btn btn-primary" type="submit"><?php echo esc_html($contact_info_complete ? __('Update Details', 'gms') : __('Save & Continue', 'gms')); ?></button>
                            <div id="contact-info-message"></div>
                        </form>
                    </div>

                    <!-- Agreement Section -->
                    <div class="action-section requires-contact-info <?php echo $contact_info_complete ? '' : 'hidden'; ?>" id="agreement-section" <?php echo ($agreement && $agreement['status'] === 'signed') ? 'style="display: none;"' : ''; ?>>
                        <h3 class="section-title">📋 Guest Agreement</h3>

                        <div class="agreement-text" id="agreement-text">
                            <?php echo wp_kses_post($agreement_display); ?>
                        </div>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="agreement-checkbox" required>
                            <label for="agreement-checkbox">I have read and agree to the terms above</label>
                        </div>
                        
                        <div class="signature-section">
                            <label for="signature-canvas">Your Signature:</label>
                            <canvas id="signature-canvas" class="signature-canvas"></canvas>
                            <div class="signature-controls">
                                <button type="button" id="clear-signature" class="btn btn-outline">Clear Signature</button>
                            </div>
                        </div>
                        
                        <button id="submit-agreement" class="btn btn-primary" disabled>Submit Agreement</button>
                        <div id="agreement-message"></div>
                    </div>
                    
                    <!-- PDF Download Section (shown after signing) -->
                    <?php if ($agreement && $agreement['status'] === 'signed' && !empty($agreement['pdf_url'])): ?>
                    <div class="pdf-download-section requires-contact-info <?php echo $contact_info_complete ? '' : 'hidden'; ?>">
                        <h3 class="section-title">✅ Agreement Signed Successfully</h3>
                        <div class="pdf-download-box">
                            <p style="margin-bottom: 1rem;"><strong>Your agreement has been signed and saved!</strong></p>
                            <p style="color: #666; margin-bottom: 1rem;">Signed on: <?php echo esc_html(date('F j, Y \a\t g:i A', strtotime($agreement['signed_at']))); ?></p>
                            <p style="color: #666; margin-bottom: 1.5rem;">A copy has been sent to your phone via SMS.</p>
                            <a href="<?php echo esc_url($agreement['pdf_url']); ?>" 
                               class="btn btn-success" 
                               download="<?php echo esc_attr($reservation['booking_reference']); ?>.pdf"
                               style="text-decoration: none;">
                                📄 Download Signed Agreement (PDF)
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Identity Verification Section -->
                    <div class="action-section requires-contact-info <?php echo $contact_info_complete ? '' : 'hidden'; ?>" id="verification-section" <?php echo (!$agreement || $agreement['status'] !== 'signed') ? 'style="display: none;"' : ''; ?>>
                        <h3 class="section-title">🆔 Identity Verification</h3>
                        
                        <p>Please verify your identity by uploading a photo of your government-issued ID and capturing a live selfie that matches your ID photo. This helps us ensure the security of our properties.</p>

                        <div id="verification-content">
                            <?php if ($verification && $verification['verification_status'] === 'verified'): ?>
                                <div class="success-message">
                                    ✅ Identity verification completed successfully!
                                </div>
                            <?php elseif ($verification && $verification['verification_status'] === 'processing'): ?>
                                <div class="loading">
                                    <div class="spinner"></div>
                                    <p>Verifying your identity...</p>
                                    <button id="check-verification" class="btn btn-secondary">Check Status</button>
                                </div>
                            <?php else: ?>
                                <div class="verification-help">
                                    <p style="margin-bottom: 0.75rem; color: #555;">When you start, Stripe will guide you through taking photos of your ID and a matching selfie. Please ensure you are in a well-lit area.</p>
                                </div>
                                <button id="start-verification" class="btn btn-primary">Start Identity Verification</button>
                            <?php endif; ?>
                        </div>
                        <div id="verification-message"></div>
                    </div>
                    
                </div>
            </div>
            
            <script src="https://js.stripe.com/v3/"></script>
            <script>
                // Initialize variables
                let signaturePad = null;
                let stripe = null;
                let contactInfoComplete = <?php echo $contact_info_complete ? 'true' : 'false'; ?>;
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
                                
                                // Show completion message after a delay
                                setTimeout(() => {
                                    showCompletionMessage();
                                }, 2000);
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
                }
                
                function showCompletionMessage() {
                    const portalContent = document.querySelector('.portal-content');
                    portalContent.innerHTML = `
                        <div style="text-align: center; padding: 3rem 1rem;">
                            <div style="font-size: 4rem; margin-bottom: 1rem;">🎉</div>
                            <h2 style="color: <?php echo esc_attr($primary_color); ?>; margin-bottom: 1rem;">Check-in Complete!</h2>
                            <p style="font-size: 1.2rem; margin-bottom: 2rem;">Thank you for completing your check-in process. You're all set for your stay!</p>
                            
                            <div style="background: #f8f9fa; padding: 2rem; border-radius: 8px; margin: 2rem 0;">
                                <h3>Next Steps:</h3>
                                <ul style="text-align: left; max-width: 400px; margin: 0 auto;">
                                    <li style="margin: 1rem 0;">📧 Check your email for detailed check-in instructions</li>
                                    <li style="margin: 1rem 0;">🗝️ Property access information will be sent 24 hours before check-in</li>
                                    <li style="margin: 1rem 0;">📱 Save our contact information for any questions</li>
                                </ul>
                            </div>
                            
                            <p style="color: #666;">We look forward to hosting you!</p>
                        </div>
                    `;
                    
                    // Update reservation status
                    const formData = new FormData();
                    formData.append('action', 'gms_update_reservation_status');
                    formData.append('reservation_id', reservationId);
                    formData.append('status', 'completed');
                    formData.append('nonce', guestNonce);
                    
                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
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

    private static function displayCompletionPage($reservation, $agreement, $verification) {
        $company_name = get_option('gms_company_name', get_option('blogname'));
        $company_logo = get_option('gms_company_logo');
        $primary_color = get_option('gms_portal_primary_color', '#0073aa');

        $door_code = '';
        if (!empty($reservation['door_code'])) {
            $door_code = GMS_Database::sanitizeDoorCode($reservation['door_code']);
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Check-in Complete - <?php echo esc_html($company_name); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    background: #f8f9fa;
                }
                
                .portal-container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: #fff;
                    min-height: 100vh;
                    text-align: center;
                    padding: 2rem 1rem;
                }
                
                .company-logo {
                    max-width: 200px;
                    margin-bottom: 2rem;
                }
                
                .completion-icon {
                    font-size: 6rem;
                    margin-bottom: 2rem;
                }
                
                .completion-title {
                    font-size: 2.5rem;
                    color: <?php echo esc_attr($primary_color); ?>;
                    margin-bottom: 1rem;
                }
                
                .completion-message {
                    font-size: 1.2rem;
                    margin-bottom: 3rem;
                    color: #666;
                }
                
                .details-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 2rem;
                    margin: 3rem 0;
                    text-align: left;
                }
                
                .detail-card {
                    background: #f8f9fa;
                    padding: 1.5rem;
                    border-radius: 8px;
                    border-left: 4px solid <?php echo esc_attr($primary_color); ?>;
                }
                
                .detail-title {
                    font-weight: 600;
                    margin-bottom: 0.5rem;
                    color: <?php echo esc_attr($primary_color); ?>;
                }
                
                .contact-info {
                    background: <?php echo esc_attr($primary_color); ?>;
                    color: #fff;
                    padding: 2rem;
                    border-radius: 8px;
                    margin: 2rem 0;
                }
                
                .btn-download {
                    display: inline-block;
                    padding: 1rem 2rem;
                    background: #28a745;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                    font-weight: 600;
                    margin-top: 1rem;
                }
            </style>
        </head>
        <body>
            <div class="portal-container">
                <?php if ($company_logo): ?>
                    <img src="<?php echo esc_url($company_logo); ?>" alt="<?php echo esc_attr($company_name); ?>" class="company-logo">
                <?php endif; ?>
                
                <div class="completion-icon">🎉</div>
                
                <h1 class="completion-title">Check-in Complete!</h1>
                
                <p class="completion-message">
                    Thank you, <?php echo esc_html($reservation['guest_name']); ?>! 
                    Your check-in process is complete and you're all set for your stay.
                </p>
                
                <?php if ($agreement && !empty($agreement['pdf_url'])): ?>
                <div style="margin: 2rem 0;">
                    <a href="<?php echo esc_url($agreement['pdf_url']); ?>" 
                       class="btn-download"
                       download="<?php echo esc_attr($reservation['booking_reference']); ?>.pdf">
                        📄 Download Your Signed Agreement
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="details-grid">
                    <div class="detail-card">
                        <div class="detail-title">Property</div>
                        <div><?php echo esc_html($reservation['property_name']); ?></div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-title">Check-in Date</div>
                        <div><?php echo esc_html(date('l, F j, Y', strtotime($reservation['checkin_date']))); ?></div>
                        <div><?php echo esc_html(date('g:i A', strtotime($reservation['checkin_date']))); ?></div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-title">Check-out Date</div>
                        <div><?php echo esc_html(date('l, F j, Y', strtotime($reservation['checkout_date']))); ?></div>
                        <div><?php echo esc_html(date('g:i A', strtotime($reservation['checkout_date']))); ?></div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-title">Agreement Signed</div>
                        <div><?php echo esc_html(date('M j, Y g:i A', strtotime($agreement['signed_at']))); ?></div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-title">Identity Verified</div>
                        <div><?php echo esc_html(date('M j, Y g:i A', strtotime($verification['verified_at']))); ?></div>
                    </div>
                    
                    <?php if ($door_code !== ''): ?>
                    <div class="detail-card">
                        <div class="detail-title">Door Code</div>
                        <div><?php echo esc_html($door_code); ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="detail-card">
                        <div class="detail-title">Booking Reference</div>
                        <div><?php echo esc_html($reservation['booking_reference']); ?></div>
                    </div>
                </div>
                
                <div class="contact-info">
                    <h3>Need Help?</h3>
                    <p>If you have any questions or need assistance, please contact us.</p>
                    <p>We look forward to hosting you at <?php echo esc_html($reservation['property_name']); ?>!</p>
                </div>
            </div>
        </body>
        </html>
        <?php
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