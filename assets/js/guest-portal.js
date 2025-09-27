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
        formData.append('reservation_id', window.gmsReservationId);
        formData.append('signature_data', signaturePad.toDataURL());
        formData.append('nonce', window.gmsNonce);

        fetch(window.gmsAjaxUrl, {
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
        formData.append('reservation_id', window.gmsReservationId);
        formData.append('nonce', window.gmsNonce);

        fetch(window.gmsAjaxUrl, {
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
                throw new Error(result.error.message);
            } else {
                // Verification completed, check status
                checkVerificationStatus();
            }
        })
        .catch(error => {
            messageDiv.innerHTML = '<div class="error-message">❌ Error: ' + error.message + '</div>';
            startBtn.disabled = false;
            console.error('Error:', error);
        });
    }

    // Check Verification Status
    function checkVerificationStatus() {
        const messageDiv = document.getElementById('verification-message');

        const formData = new FormData();
        formData.append('action', 'gms_check_verification_status');
        formData.append('reservation_id', window.gmsReservationId);
        formData.append('nonce', window.gmsNonce);

        fetch(window.gmsAjaxUrl, {
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
                    
                    const verificationChecklist = document.getElementById('verification-checklist');
                    verificationChecklist.classList.add('completed');
                    verificationChecklist.querySelector('.checklist-icon').textContent = '✓';
                    
                    updateProgress();
                    
                    // Show completion message
                    setTimeout(() => {
                        showCompletionMessage();
                    }, 2000);
                    
                } else if (status === 'requires_input') {
                    messageDiv.innerHTML = '<div class="error-message">❌ Additional information required. Please try again.</div>';
                    document.getElementById('verification-content').innerHTML = 
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
        formData.append('reservation_id', window.gmsReservationId);
        formData.append('status', 'completed');
        formData.append('nonce', window.gmsNonce);

        fetch(window.gmsAjaxUrl, {
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