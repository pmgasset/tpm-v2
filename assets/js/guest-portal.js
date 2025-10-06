/**
 * File: guest-portal.js
 * Location: /wp-content/plugins/guest-management-system/assets/js/guest-portal.js
 * 
 * Guest Portal JavaScript for Guest Management System
 */

(function() {
    'use strict';

    // Signature Pad Implementation
    class SignaturePad {
        constructor(canvasId) {
            this.canvas = document.getElementById(canvasId);
            if (!this.canvas) return;

            this.ctx = this.canvas.getContext('2d');
            this.isDrawing = false;
            this.lastX = 0;
            this.lastY = 0;

            this.init();
        }

        init() {
            // Set canvas styles
            this.ctx.strokeStyle = '#000';
            this.ctx.lineWidth = 2;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';

            // Mouse events
            this.canvas.addEventListener('mousedown', (e) => this.startDrawing(e));
            this.canvas.addEventListener('mousemove', (e) => this.draw(e));
            this.canvas.addEventListener('mouseup', () => this.stopDrawing());
            this.canvas.addEventListener('mouseout', () => this.stopDrawing());

            // Touch events for mobile
            this.canvas.addEventListener('touchstart', (e) => {
                e.preventDefault();
                this.startDrawing(e.touches[0]);
            });
            
            this.canvas.addEventListener('touchmove', (e) => {
                e.preventDefault();
                this.draw(e.touches[0]);
            });
            
            this.canvas.addEventListener('touchend', (e) => {
                e.preventDefault();
                this.stopDrawing();
            });
        }

        getPosition(e) {
            const rect = this.canvas.getBoundingClientRect();
            return {
                x: (e.clientX - rect.left) * (this.canvas.width / rect.width),
                y: (e.clientY - rect.top) * (this.canvas.height / rect.height)
            };
        }

        startDrawing(e) {
            this.isDrawing = true;
            const pos = this.getPosition(e);
            this.lastX = pos.x;
            this.lastY = pos.y;
        }

        draw(e) {
            if (!this.isDrawing) return;

            const pos = this.getPosition(e);

            this.ctx.beginPath();
            this.ctx.moveTo(this.lastX, this.lastY);
            this.ctx.lineTo(pos.x, pos.y);
            this.ctx.stroke();

            this.lastX = pos.x;
            this.lastY = pos.y;

            // Trigger validation check
            if (window.checkFormValidity) {
                window.checkFormValidity();
            }
        }

        stopDrawing() {
            if (this.isDrawing) {
                this.isDrawing = false;
                // Trigger validation check
                if (window.checkFormValidity) {
                    window.checkFormValidity();
                }
            }
        }

        clear() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            // Trigger validation check
            if (window.checkFormValidity) {
                window.checkFormValidity();
            }
        }

        isEmpty() {
            const imageData = this.ctx.getImageData(0, 0, this.canvas.width, this.canvas.height);
            return !imageData.data.some(channel => channel !== 0);
        }

        toDataURL() {
            return this.canvas.toDataURL();
        }
    }

    function parseBoolean(value) {
        if (typeof value === 'boolean') {
            return value;
        }

        if (typeof value === 'string') {
            return value.toLowerCase() === 'true';
        }

        return Boolean(value);
    }

    const reservationId = typeof window.gmsReservationId !== 'undefined'
        ? parseInt(window.gmsReservationId, 10) || 0
        : 0;
    const ajaxUrl = typeof window.gmsAjaxUrl === 'string'
        ? window.gmsAjaxUrl
        : (typeof window.ajaxurl === 'string' ? window.ajaxurl : '');
    const guestNonce = typeof window.gmsNonce === 'string' ? window.gmsNonce : '';
    const contactStrings = window.gmsContactStrings || {
        helperComplete: 'Need to make a change? Update your contact details below.',
        helperIncomplete: 'We need your legal name and contact information before we can confirm the reservation. The remaining steps will unlock once this is saved.',
        saving: 'Saving your details…',
        success: 'Contact information saved. You can move on to the next step.',
        failure: 'Unable to save contact information. Please try again.',
        networkError: 'We could not save your details due to a network error. Please try again.',
        saveLabel: 'Save & Continue',
        updateLabel: 'Update Details',
        missingConfig: 'We could not save your details because the portal is missing required configuration.'
    };

    let contactInfoComplete = parseBoolean(window.gmsContactInfoComplete);

    function toggleContactDependentSections() {
        const sections = document.querySelectorAll('.requires-contact-info');
        sections.forEach((section) => {
            if (!section) {
                return;
            }

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
            const nameEl = document.getElementById('contact-summary-name');
            if (nameEl && payload.guest_name) {
                nameEl.textContent = payload.guest_name;
            }

            const emailEl = document.getElementById('contact-summary-email');
            if (emailEl && payload.guest_email) {
                emailEl.textContent = payload.guest_email;
            }

            const phoneEl = document.getElementById('contact-summary-phone');
            if (phoneEl && (payload.display_phone || payload.guest_phone)) {
                phoneEl.textContent = payload.display_phone || payload.guest_phone;
            }

            summary.classList.remove('hidden');
        }

        const helper = document.getElementById('contact-section-helper');
        if (helper) {
            helper.textContent = contactStrings.helperComplete;
        }
    }

    function submitContactInfo(context) {
        const { contactForm, submitBtn, messageDiv, helper, updateSubmitState } = context;
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
        const originalLabel = contactInfoComplete ? contactStrings.updateLabel : contactStrings.saveLabel;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = contactStrings.saving;
        }

        if (messageDiv) {
            messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>' + contactStrings.saving + '</p></div>';
        }

        if (!canSubmit) {
            if (messageDiv) {
                messageDiv.innerHTML = '<div class="error-message">❌ ' + contactStrings.missingConfig + '</div>';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
            return;
        }

        const wasComplete = contactInfoComplete;

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
                        helper.textContent = contactStrings.helperComplete;
                    }

                    if (messageDiv) {
                        messageDiv.innerHTML = '<div class="success-message">✅ ' + contactStrings.success + '</div>';
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

                    if (typeof updateProgress === 'function') {
                        updateProgress();
                    }

                    if (!wasComplete) {
                        const agreementSection = document.getElementById('agreement-section');
                        if (agreementSection) {
                            setTimeout(() => {
                                agreementSection.scrollIntoView({ behavior: 'smooth' });
                            }, 400);
                        }
                    }
                } else if (messageDiv) {
                    messageDiv.innerHTML = '<div class="error-message">❌ ' + (data.data || contactStrings.failure) + '</div>';
                }
            })
            .catch(() => {
                if (messageDiv) {
                    messageDiv.innerHTML = '<div class="error-message">❌ ' + contactStrings.networkError + '</div>';
                }
            })
            .finally(() => {
                if (submitBtn) {
                    submitBtn.textContent = contactInfoComplete ? contactStrings.updateLabel : contactStrings.saveLabel;
                    submitBtn.disabled = false;
                }

                if (typeof updateSubmitState === 'function') {
                    updateSubmitState();
                }
            });
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
            helper.textContent = contactInfoComplete ? contactStrings.helperComplete : contactStrings.helperIncomplete;
        }

        const updateSubmitState = () => {
            if (!submitBtn) {
                return;
            }
            submitBtn.disabled = !contactForm.checkValidity();
        };

        contactForm.querySelectorAll('input').forEach((input) => {
            input.addEventListener('input', updateSubmitState);
            input.addEventListener('blur', () => {
                input.value = input.value.trim();
                updateSubmitState();
            });
        });

        updateSubmitState();

        contactForm.addEventListener('submit', (event) => {
            event.preventDefault();

            if (!contactForm.checkValidity()) {
                contactForm.reportValidity();
                return;
            }

            submitContactInfo({
                contactForm,
                submitBtn,
                messageDiv,
                helper,
                updateSubmitState
            });
        });
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {

        // Initialize signature pad
        let signaturePad = null;
        const canvas = document.getElementById('signature-canvas');
        
        if (canvas) {
            signaturePad = new SignaturePad('signature-canvas');
        }

        // Clear signature button
        const clearButton = document.getElementById('clear-signature');
        if (clearButton && signaturePad) {
            clearButton.addEventListener('click', function() {
                signaturePad.clear();
            });
        }

        // Form validation
        window.checkFormValidity = function() {
            const checkbox = document.getElementById('agreement-checkbox');
            const submitBtn = document.getElementById('submit-agreement');
            
            if (checkbox && submitBtn && signaturePad) {
                const isValid = checkbox.checked && !signaturePad.isEmpty();
                submitBtn.disabled = !isValid;
            }
        };

        // Agreement checkbox
        const agreementCheckbox = document.getElementById('agreement-checkbox');
        if (agreementCheckbox) {
            agreementCheckbox.addEventListener('change', checkFormValidity);
        }

        // Submit agreement
        const submitAgreementBtn = document.getElementById('submit-agreement');
        if (submitAgreementBtn && signaturePad) {
            submitAgreementBtn.addEventListener('click', function() {
                submitAgreement(signaturePad);
            });
        }

        // Stripe Identity verification
        const startVerificationBtn = document.getElementById('start-verification');
        if (startVerificationBtn) {
            startVerificationBtn.addEventListener('click', function() {
                startIdentityVerification();
            });
        }

        // Check verification status
        const checkVerificationBtn = document.getElementById('check-verification');
        if (checkVerificationBtn) {
            checkVerificationBtn.addEventListener('click', function() {
                checkVerificationStatus();
            });
        }

        setupContactForm();
        toggleContactDependentSections();

        // Progress bar update
        updateProgress();
    });

    // Submit Agreement Function
    function submitAgreement(signaturePad) {
        const messageDiv = document.getElementById('agreement-message');
        const submitBtn = document.getElementById('submit-agreement');
        
        if (!signaturePad || signaturePad.isEmpty()) {
            messageDiv.innerHTML = '<div class="error-message">Please provide your signature</div>';
            return;
        }

        submitBtn.disabled = true;
        messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Submitting agreement...</p></div>';

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
                messageDiv.innerHTML = '<div class="success-message">✅ Agreement signed successfully!</div>';
                
                // Update UI
                document.getElementById('agreement-section').style.display = 'none';
                document.getElementById('verification-section').style.display = 'block';
                
                const agreementChecklist = document.getElementById('agreement-checklist');
                agreementChecklist.classList.add('completed');
                agreementChecklist.querySelector('.checklist-icon').textContent = '✓';
                
                updateProgress();
                
                // Scroll to verification section
                setTimeout(() => {
                    document.getElementById('verification-section').scrollIntoView({ 
                        behavior: 'smooth' 
                    });
                }, 500);
            } else {
                messageDiv.innerHTML = '<div class="error-message">❌ Error: ' + (data.data || 'Failed to submit agreement') + '</div>';
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            messageDiv.innerHTML = '<div class="error-message">❌ Network error occurred</div>';
            submitBtn.disabled = false;
            console.error('Error:', error);
        });
    }

    // Start Identity Verification
    function startIdentityVerification() {
        if (!window.Stripe || !window.gmsStripeKey) {
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
            if (data.success && data.data.client_secret) {
                const stripe = Stripe(window.gmsStripeKey);
                return stripe.verifyIdentity(data.data.client_secret);
            } else {
                throw new Error(data.data || 'Failed to create verification session');
            }
        })
        .then(result => {
            if (result.error) {
                const code = result.error.code || '';
                let errorMessage = result.error.message || 'Verification was not completed.';

                if (typeof code === 'string' && code.toLowerCase().includes('selfie')) {
                    errorMessage = 'Selfie verification failed. Please allow camera access and capture a clear, well-lit selfie that matches your ID.';
                }

                messageDiv.innerHTML = '<div class="error-message">❌ ' + errorMessage + '</div>';
                startBtn.disabled = false;
            } else {
                // Verification completed, check status
                checkVerificationStatus();
            }
        })
        .catch(error => {
            let errorMessage = error.message || 'Unexpected error occurred.';

            if (errorMessage.toLowerCase().includes('selfie')) {
                errorMessage += ' Please make sure you complete the selfie step and follow the on-screen prompts from Stripe.';
            }

            messageDiv.innerHTML = '<div class="error-message">❌ Error: ' + errorMessage + '</div>';
            startBtn.disabled = false;
            console.error('Error:', error);
        });
    }

    // Check Verification Status
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
                
                const lastError = data.data.last_error || null;

                if (status === 'verified') {
                    document.getElementById('verification-content').innerHTML =
                        '<div class="success-message">✅ Identity verification completed successfully!</div>';

                    const verificationChecklist = document.getElementById('verification-checklist');
                    verificationChecklist.classList.add('completed');
                    verificationChecklist.querySelector('.checklist-icon').textContent = '✓';
                    
                    updateProgress();
                    
                    // Show completion message
                    setTimeout(() => {
                        showCompletionMessage();
                    }, 2000);
                    
                } else if (status === 'requires_input') {
                    let needsSelfieHelp = false;
                    let requiresMessage = 'Additional information required. Please try again.';

                    if (lastError) {
                        const code = (lastError.code || '').toLowerCase();
                        const reason = lastError.reason || '';
                        const requirement = (lastError.requirement || '').toLowerCase();

                        if (code.includes('selfie') || requirement === 'selfie') {
                            needsSelfieHelp = true;
                            requiresMessage = 'Your selfie could not be verified. Please retake the live selfie so Stripe can match it to your ID.';
                        } else if (reason) {
                            requiresMessage = reason;
                        }
                    }

                    if (!needsSelfieHelp) {
                        requiresMessage += ' Ensure you complete every step, including the live selfie capture.';
                    }

                    messageDiv.innerHTML = '<div class="error-message">❌ ' + requiresMessage + '</div>';
                    document.getElementById('verification-content').innerHTML =
                        '<div class="verification-help"><p style="margin-bottom: 0.75rem; color: #555;">Stripe will walk you through uploading your ID and taking a live selfie. Find a well-lit area and remove hats or glasses before trying again.</p></div>' +
                        '<button id="start-verification" class="btn btn-primary">Retry Identity Verification</button>';

                    // Re-attach event listener
                    document.getElementById('start-verification').addEventListener('click', startIdentityVerification);

                } else {
                    messageDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Still processing...</p><button id="check-verification" class="btn btn-secondary">Check Again</button></div>';
                    
                    // Re-attach event listener
                    document.getElementById('check-verification').addEventListener('click', checkVerificationStatus);
                }
            } else {
                messageDiv.innerHTML = '<div class="error-message">❌ Error checking status: ' + (data.data || 'Unknown error') + '</div>';
            }
        })
        .catch(error => {
            messageDiv.innerHTML = '<div class="error-message">❌ Network error occurred</div>';
            console.error('Error:', error);
        });
    }

    // Update Progress Bar
    function updateProgress() {
        const completedItems = document.querySelectorAll('.checklist-item.completed').length;
        const totalItems = document.querySelectorAll('.checklist-item').length;
        const percentage = (completedItems / totalItems) * 100;

        const progressFill = document.getElementById('progress-fill');
        if (progressFill) {
            progressFill.style.width = percentage + '%';
        }
    }

    // Show Completion Message
    function showCompletionMessage() {
        const portalContent = document.querySelector('.portal-content');
        
        portalContent.innerHTML = `
            <div style="text-align: center; padding: 3rem 1rem;">
                <div style="font-size: 4rem; margin-bottom: 1rem;">🎉</div>
                <h2 style="color: var(--primary-color, #0073aa); margin-bottom: 1rem;">Check-in Complete!</h2>
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
        }).catch(error => console.error('Error updating status:', error));
    }

    // Expose functions globally if needed
    window.GMS = {
        submitAgreement: submitAgreement,
        startIdentityVerification: startIdentityVerification,
        checkVerificationStatus: checkVerificationStatus,
        updateProgress: updateProgress,
        showCompletionMessage: showCompletionMessage
    };

})();