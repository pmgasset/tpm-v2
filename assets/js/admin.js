/**
 * File: admin.js
 * Location: /wp-content/plugins/guest-management-system/assets/js/admin.js
 * 
 * Admin JavaScript for Guest Management System
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Tab switching functionality
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            var target = $(this).attr('href');
            
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            $('.gms-tab-content').removeClass('active');
            $(target).addClass('active');
        });

        // Test SMS functionality
        $('#send-test-sms').on('click', function() {
            var button = $(this);
            var number = $('#test-sms-number').val();
            var resultDiv = $('#sms-test-result');
            
            if (!number) {
                resultDiv.html('<p style="color: red;">Please enter a phone number</p>');
                return;
            }
            
            button.prop('disabled', true).text('Sending...');
            resultDiv.html('<p style="color: #666;">Sending test SMS...</p>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gms_test_sms',
                    number: number,
                    nonce: gms_admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<p style="color: green;">✓ SMS sent successfully!</p>');
                    } else {
                        resultDiv.html('<p style="color: red;">✗ Error: ' + (response.data || 'Failed to send SMS') + '</p>');
                    }
                },
                error: function() {
                    resultDiv.html('<p style="color: red;">✗ Network error occurred</p>');
                },
                complete: function() {
                    button.prop('disabled', false).text('Send Test SMS');
                }
            });
        });

        // Test Email functionality
        $('#send-test-email').on('click', function() {
            var button = $(this);
            var email = $('#test-email-address').val();
            var resultDiv = $('#email-test-result');
            
            if (!email) {
                resultDiv.html('<p style="color: red;">Please enter an email address</p>');
                return;
            }
            
            button.prop('disabled', true).text('Sending...');
            resultDiv.html('<p style="color: #666;">Sending test email...</p>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gms_test_email',
                    email: email,
                    nonce: gms_admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<p style="color: green;">✓ Email sent successfully!</p>');
                    } else {
                        resultDiv.html('<p style="color: red;">✗ Error: ' + (response.data || 'Failed to send email') + '</p>');
                    }
                },
                error: function() {
                    resultDiv.html('<p style="color: red;">✗ Network error occurred</p>');
                },
                complete: function() {
                    button.prop('disabled', false).text('Send Test Email');
                }
            });
        });

        // Resend notification for single reservation
        $('.resend-notification').on('click', function() {
            var button = $(this);
            var reservationId = button.data('id');
            
            if (!confirm('Are you sure you want to resend notifications for this reservation?')) {
                return;
            }
            
            button.prop('disabled', true).text('Sending...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gms_resend_notification',
                    reservation_id: reservationId,
                    nonce: gms_admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Notifications sent successfully!');
                    } else {
                        alert('Error: ' + (response.data || 'Failed to send notifications'));
                    }
                },
                error: function() {
                    alert('Network error occurred');
                },
                complete: function() {
                    button.prop('disabled', false).text('Resend');
                }
            });
        });

        // Bulk actions for reservations
        $('#doaction, #doaction2').on('click', function(e) {
            e.preventDefault();
            
            var action = $(this).prev('select').val();
            var selectedIds = [];
            
            $('input[type="checkbox"]:checked').each(function() {
                var id = $(this).val();
                if (id && id !== 'on') {
                    selectedIds.push(id);
                }
            });
            
            if (action === '-1' || selectedIds.length === 0) {
                alert('Please select an action and at least one reservation');
                return;
            }
            
            if (!confirm('Are you sure you want to perform this action on ' + selectedIds.length + ' reservation(s)?')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gms_bulk_action',
                    bulk_action: action,
                    reservation_ids: selectedIds,
                    nonce: gms_admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Action completed successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Action failed'));
                    }
                },
                error: function() {
                    alert('Network error occurred');
                }
            });
        });

        // Test webhook functionality
        $('#test-webhook').on('click', function() {
            var button = $(this);
            
            button.prop('disabled', true).text('Testing...');
            
            var testData = {
                booking_reference: 'TEST-' + Date.now(),
                guest_name: 'Test Guest',
                guest_email: 'test@example.com',
                guest_phone: '+12025551234',
                property_name: 'Test Property',
                property_id: 'PROP-TEST',
                checkin_date: '2025-12-01 16:00:00',
                checkout_date: '2025-12-05 11:00:00',
                guests_count: 2,
                total_amount: 500,
                currency: 'USD'
            };
            
            $.ajax({
                url: gms_webhook_url + '/generic',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(testData),
                success: function(response) {
                    alert('Webhook test successful! Check the Reservations page for the test booking.');
                },
                error: function(xhr) {
                    alert('Webhook test failed. Check error logs for details.');
                },
                complete: function() {
                    button.prop('disabled', false).text('Test Webhook');
                }
            });
        });

        // Character counter for SMS template
        $('#gms_sms_template').on('input', function() {
            var length = $(this).val().length;
            var counter = $('#sms-char-count');
            
            if (!counter.length) {
                counter = $('<div id="sms-char-count" style="margin-top: 5px; font-size: 12px;"></div>');
                $(this).after(counter);
            }
            
            var color = length > 160 ? 'red' : '#666';
            counter.html('<span style="color: ' + color + ';">' + length + ' / 160 characters</span>');
            
            if (length > 160) {
                var messages = Math.ceil(length / 153);
                counter.append(' <span style="color: red;">(will be sent as ' + messages + ' messages)</span>');
            }
        });

        // Logo uploader
        $('#upload-logo').on('click', function(e) {
            e.preventDefault();
            
            var mediaUploader = wp.media({
                title: 'Select Company Logo',
                button: {
                    text: 'Use this image'
                },
                multiple: false
            });
            
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('input[name="gms_company_logo"]').val(attachment.url);
            });
            
            mediaUploader.open();
        });

        // Select all checkboxes
        $('.check-column input[type="checkbox"]').first().on('change', function() {
            var checked = $(this).prop('checked');
            $('.check-column input[type="checkbox"]').prop('checked', checked);
        });

        // Confirmation for delete actions
        $('.delete-reservation').on('click', function(e) {
            if (!confirm('Are you sure you want to delete this reservation? This action cannot be undone.')) {
                e.preventDefault();
            }
        });

        // Real-time validation for API keys
        $('input[name="gms_stripe_pk"]').on('blur', function() {
            var value = $(this).val();
            var feedback = $(this).siblings('.validation-feedback');
            
            if (!feedback.length) {
                feedback = $('<span class="validation-feedback" style="display: block; margin-top: 5px; font-size: 12px;"></span>');
                $(this).after(feedback);
            }
            
            if (value && !value.startsWith('pk_')) {
                feedback.html('<span style="color: red;">⚠ Publishable key should start with "pk_"</span>');
            } else {
                feedback.html('');
            }
        });

        $('input[name="gms_stripe_sk"]').on('blur', function() {
            var value = $(this).val();
            var feedback = $(this).siblings('.validation-feedback');
            
            if (!feedback.length) {
                feedback = $('<span class="validation-feedback" style="display: block; margin-top: 5px; font-size: 12px;"></span>');
                $(this).after(feedback);
            }
            
            if (value && !value.startsWith('sk_')) {
                feedback.html('<span style="color: red;">⚠ Secret key should start with "sk_"</span>');
            } else {
                feedback.html('');
            }
        });

        // Copy webhook URL to clipboard
        $('.copy-webhook-url').on('click', function() {
            var url = $(this).data('url');
            var button = $(this);
            
            navigator.clipboard.writeText(url).then(function() {
                var originalText = button.text();
                button.text('Copied!').css('color', 'green');
                
                setTimeout(function() {
                    button.text(originalText).css('color', '');
                }, 2000);
            }).catch(function(err) {
                alert('Failed to copy: ' + err);
            });
        });

        // Auto-save draft templates
        var autoSaveTimer;
        $('.gms-template-textarea').on('input', function() {
            clearTimeout(autoSaveTimer);
            var field = $(this).attr('name');
            var value = $(this).val();
            
            autoSaveTimer = setTimeout(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gms_autosave_template',
                        field: field,
                        value: value,
                        nonce: gms_admin_nonce
                    },
                    success: function() {
                        console.log('Template auto-saved');
                    }
                });
            }, 2000);
        });

        // Dashboard stats refresh
        $('#refresh-stats').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).html('<span class="spinner is-active"></span> Refreshing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'gms_refresh_stats',
                    nonce: gms_admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    }
                },
                complete: function() {
                    button.prop('disabled', false).html('Refresh Stats');
                }
            });
        });

        // Filter reservations by status
        $('#filter-by-status').on('change', function() {
            var status = $(this).val();
            var currentUrl = window.location.href.split('?')[0];
            
            if (status) {
                window.location.href = currentUrl + '?page=guest-management-reservations&status=' + status;
            } else {
                window.location.href = currentUrl + '?page=guest-management-reservations';
            }
        });

        // Search reservations
        $('#search-reservations').on('submit', function(e) {
            e.preventDefault();
            var searchTerm = $(this).find('input[name="s"]').val();
            var currentUrl = window.location.href.split('?')[0];
            
            window.location.href = currentUrl + '?page=guest-management-reservations&s=' + encodeURIComponent(searchTerm);
        });

        // Tooltips
        if (typeof $.fn.tooltip === 'function') {
            $('[data-tooltip]').tooltip();
        }

        // Confirmation dialogs
        $('.requires-confirmation').on('click', function(e) {
            var message = $(this).data('confirm-message') || 'Are you sure you want to perform this action?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });

    });

})(jQuery);