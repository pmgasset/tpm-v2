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
        $secondary_color = get_option('gms_portal_secondary_color', '#005a87');
        
        $agreement_template = get_option('gms_agreement_template');
        
        // Replace template variables
        $agreement_display = str_replace(
            ['{guest_name}', '{guest_email}', '{guest_phone}', '{property_name}', '{booking_reference}', 
             '{checkin_date}', '{checkout_date}', '{checkin_time}', '{checkout_time}', '{company_name}'],
            [$reservation['guest_name'], $reservation['guest_email'], $reservation['guest_phone'], 
             $reservation['property_name'], $reservation['booking_reference'],
             date('F j, Y', strtotime($reservation['checkin_date'])), 
             date('F j, Y', strtotime($reservation['checkout_date'])),
             $reservation['checkin_time'] ?? '3:00 PM', $reservation['checkout_time'] ?? '11:00 AM',
             $company_name],
            $agreement_template
        );
        
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
                        <h2>Hello, <?php echo esc_html($reservation['guest_name']); ?>!</h2>
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
                    </div>
                    
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill" style="width: 0%;"></div>
                    </div>
                    
                    <div class="checklist">
                        <div class="checklist-item <?php echo ($agreement && $agreement['status'] === 'signed') ? 'completed' : ''; ?>" id="agreement-checklist">
                            <div class="checklist-icon">
                                <?php echo ($agreement && $agreement['status'] === 'signed') ? '✓' : '1'; ?>
                            </div>
                            <div class="checklist-content">
                                <div class="checklist-title">Sign Guest Agreement</div>
                                <div class="checklist-description">Review and sign our property agreement</div>
                            </div>
                        </div>
                        
                        <div class="checklist-item <?php echo ($verification && $verification['verification_status'] === 'verified') ? 'completed' : ''; ?>" id="verification-checklist">
                            <div class="checklist-icon">
                                <?php echo ($verification && $verification['verification_status'] === 'verified') ? '✓' : '2'; ?>
                            </div>
                            <div class="checklist-content">
                                <div class="checklist-title">Identity Verification</div>
                                <div class="checklist-description">Verify your identity with a government ID</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Agreement Section -->
                    <div class="action-section" id="agreement-section" <?php echo ($agreement && $agreement['status'] === 'signed') ? 'style="display: none;"' : ''; ?>>
                        <h3 class="section-title">📋 Guest Agreement</h3>
                        
                        <div class="agreement-text">
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
                    <div class="pdf-download-section">
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
                    <div class="action-section" id="verification-section" <?php echo (!$agreement || $agreement['status'] !== 'signed') ? 'style="display: none;"' : ''; ?>>
                        <h3 class="section-title">🆔 Identity Verification</h3>
                        
                        <p>Please verify your identity by uploading a photo of your government-issued ID. This helps us ensure the security of our properties.</p>
                        
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
                const reservationId = <?php echo intval($reservation['id']); ?>;
                const portalToken = '<?php echo esc_js($reservation['portal_token']); ?>';
                
                // Initialize Stripe
                <?php if (get_option('gms_stripe_pk')): ?>
                stripe = Stripe('<?php echo esc_js(get_option('gms_stripe_pk')); ?>');
                <?php endif; ?>
                
                document.addEventListener('DOMContentLoaded', function() {
                    initializeSignaturePad();
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
                    formData.append('nonce', '<?php echo wp_create_nonce('gms_guest_nonce'); ?>');
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
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
                    formData.append('nonce', '<?php echo wp_create_nonce('gms_guest_nonce'); ?>');
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
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
                    formData.append('nonce', '<?php echo wp_create_nonce('gms_guest_nonce'); ?>');
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
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
                    formData.append('nonce', '<?php echo wp_create_nonce('gms_guest_nonce'); ?>');
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    });
                }
            </script>
        </body>
        </html>
        <?php
    }
    
    private static function displayCompletionPage($reservation, $agreement, $verification) {
        $company_name = get_option('gms_company_name', get_option('blogname'));
        $company_logo = get_option('gms_company_logo');
        $primary_color = get_option('gms_portal_primary_color', '#0073aa');
        
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
        
        $agreement_text = get_option('gms_agreement_template');
        
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
            
            wp_send_json_success(array('status' => $status['status']));
        } else {
            wp_send_json_error('Failed to check verification status');
        }
    }
}