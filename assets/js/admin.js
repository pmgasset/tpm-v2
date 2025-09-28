/**
 * File: admin.js
 * Location: /wp-content/plugins/guest-management-system/assets/js/admin.js
 * 
 * Admin JavaScript for Guest Management System
 */

(function($) {
    'use strict';

    var adminConfig = window.gmsAdmin || {};

    function getNonce() {
        return adminConfig.gms_admin_nonce || adminConfig.nonce || '';
    }

    function getAjaxUrl() {
        if (adminConfig.ajaxUrl) {
            return adminConfig.ajaxUrl;
        }

        if (typeof window.ajaxurl !== 'undefined') {
            return window.ajaxurl;
        }

        return '';
    }

    function getGenericWebhookUrl() {
        if (adminConfig.webhookUrls && adminConfig.webhookUrls.generic) {
            return adminConfig.webhookUrls.generic;
        }

        if (adminConfig.gms_webhook_url) {
            return adminConfig.gms_webhook_url.replace(/\/$/, '') + '/generic';
        }

        return '';
    }

    function getString(key, fallback) {
        if (adminConfig.strings && adminConfig.strings[key]) {
            return adminConfig.strings[key];
        }

        return fallback;
    }

    function copyTextToClipboard(text) {
        var deferred = $.Deferred();

        if (!text) {
            deferred.resolve(false);
            return deferred.promise();
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                deferred.resolve(true);
            }).catch(function() {
                fallbackCopyText(text, deferred);
            });
        } else {
            fallbackCopyText(text, deferred);
        }

        return deferred.promise();
    }

    function fallbackCopyText(text, deferred) {
        var $helper = $('<textarea>', {
            text: text,
            readonly: true,
            class: 'gms-clipboard-helper'
        }).css({
            position: 'absolute',
            left: '-9999px',
            top: '0',
            opacity: '0'
        });

        $('body').append($helper);
        $helper[0].focus();
        $helper[0].select();

        var successful = false;

        try {
            successful = document.execCommand('copy');
        } catch (err) {
            successful = false;
        }

        $helper.remove();
        deferred.resolve(successful);
    }

    function showCopyFeedback($element, message, isError) {
        if (!$element || !$element.length) {
            return;
        }

        var originalText = $element.data('original-text');
        if (typeof originalText === 'undefined') {
            originalText = $.trim($element.text());
            $element.data('original-text', originalText);
        }

        var originalAria = $element.data('original-aria-label');
        if (typeof originalAria === 'undefined') {
            originalAria = $element.attr('aria-label') || '';
            $element.data('original-aria-label', originalAria);
        }

        $element.toggleClass('gms-copy-error', !!isError);
        $element.toggleClass('gms-copy-success', !isError);
        $element.text(message);
        $element.attr('aria-label', message);

        setTimeout(function() {
            $element.removeClass('gms-copy-success gms-copy-error');
            $element.text(originalText);

            if (originalAria) {
                $element.attr('aria-label', originalAria);
            } else {
                $element.removeAttr('aria-label');
            }
        }, 2000);
    }

    function getColumnCount($row) {
        var count = $row.children('th, td').length;

        if (!count) {
            count = $row.closest('table').find('thead th').length;
        }

        return count || 1;
    }

    function createField(reservation, name, label, type) {
        var fieldId = 'gms-reservation-' + name + '-' + (reservation.id || 'new');
        var $wrapper = $('<div/>', { 'class': 'gms-reservation-form__field' });
        var $label = $('<label/>', { 'for': fieldId, text: label });
        var $input = $('<input/>', {
            type: type || 'text',
            id: fieldId,
            name: name,
            'class': 'regular-text'
        });

        if (reservation && reservation[name] !== undefined && reservation[name] !== null) {
            $input.val(reservation[name]);
        }

        $wrapper.append($label).append($input);

        return $wrapper;
    }

    function buildReservationForm(reservation) {
        var $form = $('<form/>', {
            'class': 'gms-reservation-form',
            'data-reservation-id': reservation.id || ''
        });

        var $grid = $('<div/>', { 'class': 'gms-reservation-form__grid' });

        $grid.append(createField(reservation, 'guest_name', getString('guestName', 'Guest Name')));
        $grid.append(createField(reservation, 'guest_email', getString('guestEmail', 'Guest Email'), 'email'));
        $grid.append(createField(reservation, 'guest_phone', getString('guestPhone', 'Guest Phone'), 'tel'));
        $grid.append(createField(reservation, 'property_name', getString('propertyName', 'Property Name')));
        $grid.append(createField(reservation, 'booking_reference', getString('bookingReference', 'Booking Reference')));
        $grid.append(createField(reservation, 'checkin_date', getString('checkinDate', 'Check-in Date')));
        $grid.append(createField(reservation, 'checkout_date', getString('checkoutDate', 'Check-out Date')));
        $grid.append(createField(reservation, 'status', getString('statusLabel', 'Status')));

        $form.append($grid);

        var $feedback = $('<div/>', {
            'class': 'gms-reservation-feedback',
            'aria-live': 'polite'
        });

        $form.append($feedback);

        var $actions = $('<p/>', { 'class': 'submit' });
        var $saveButton = $('<button/>', {
            type: 'submit',
            'class': 'button button-primary',
            text: getString('saveChanges', 'Save Changes')
        });
        var $cancelButton = $('<button/>', {
            type: 'button',
            'class': 'button button-secondary gms-reservation-cancel',
            text: getString('cancel', 'Cancel')
        });

        $actions.append($saveButton).append(' ').append($cancelButton);
        $form.append($actions);

        return $form;
    }

    function populateReservationForm($form, reservation) {
        if (!$form || !$form.length || !reservation) {
            return;
        }

        $.each(reservation, function(key, value) {
            var $field = $form.find('[name="' + key + '"]');

            if ($field.length) {
                $field.val(value == null ? '' : value);
            }
        });
    }

    function renderEditorContent($editorRow, reservationId, reservation) {
        if (!$editorRow || !$editorRow.length) {
            return;
        }

        var $cell = $editorRow.find('td');
        $cell.empty();

        var $container = $('<div/>', { 'class': 'gms-reservation-editor__container' });
        var titleText = getString('editReservationTitle', 'Edit Reservation');

        if (reservation && reservation.id) {
            titleText += ' #' + reservation.id;
        }

        var $title = $('<h3/>', { 'class': 'gms-reservation-editor__title' }).text(titleText);
        $container.append($title);

        var $form = buildReservationForm(reservation || {});
        $form.attr('data-reservation-id', reservationId || '');
        populateReservationForm($form, reservation || {});
        $container.append($form);

        $cell.append($container);
        $editorRow.removeClass('is-loading');
    }

    function fetchReservation(reservationId) {
        var ajaxUrl = getAjaxUrl();

        if (!ajaxUrl) {
            return $.Deferred().reject({
                data: getString('ajaxUnavailable', 'Unable to communicate with the server.')
            }).promise();
        }

        return $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'gms_get_reservation',
                reservation_id: reservationId,
                nonce: getNonce()
            }
        });
    }

    function handleReservationError($editorRow, message) {
        if (!$editorRow || !$editorRow.length) {
            return;
        }

        var $cell = $editorRow.find('td');
        $cell.empty();

        var $container = $('<div/>', { 'class': 'gms-reservation-editor__container' });
        $container.append($('<p/>', { 'class': 'gms-reservation-editor__error' }).text(message));
        $cell.append($container);

        setTimeout(function() {
            var $triggerRow = $editorRow.prev('tr');
            $editorRow.remove();

            if ($triggerRow && $triggerRow.length) {
                $triggerRow.find('.gms-reservation-toggle').attr('aria-expanded', 'false').removeClass('is-active');
            }
        }, 2500);
    }

    function updateReservationRow($row, display) {
        if (!$row || !$row.length || !display) {
            return;
        }

        $.each(display, function(columnKey, value) {
            if (columnKey === 'cb') {
                return;
            }

            var $cell = $row.find('.column-' + columnKey);

            if ($cell.length) {
                $cell.html(value);
            }
        });
    }


    $(document).ready(function() {

        $(document).on('click', '.gms-open-portal', function(e) {
            e.preventDefault();

            var $link = $(this);
            var url = $link.attr('href') || $link.data('copy-url');

            if (!url) {
                return;
            }

            var newWindow = window.open(url, '_blank', 'noopener');

            if (newWindow) {
                newWindow.opener = null;
            }

            var copyValue = $link.data('copy-url') || url;

            copyTextToClipboard(copyValue).done(function(success) {
                var message = success
                    ? getString('copySuccess', 'Link copied to clipboard.')
                    : getString('copyError', 'Unable to copy link.');

                showCopyFeedback($link, message, !success);
            });
        });

        $(document).on('click', '.gms-reservation-toggle', function(e) {
            e.preventDefault();

            var $button = $(this);
            var reservationId = parseInt($button.data('reservation-id'), 10);

            if (!reservationId) {
                return;
            }

            var $row = $button.closest('tr');
            var $existing = $row.next('.gms-reservation-editor');

            if ($existing.length && !$existing.hasClass('is-loading')) {
                $existing.remove();
                $button.attr('aria-expanded', 'false').removeClass('is-active');
                return;
            }

            if ($existing.length) {
                $existing.remove();
            }

            var columnCount = getColumnCount($row);
            var $editorRow = $('<tr class="gms-reservation-editor is-loading"></tr>');
            var $cell = $('<td/>', { colspan: columnCount });
            var $container = $('<div/>', { 'class': 'gms-reservation-editor__container' });
            $container.append($('<p/>', { 'class': 'gms-reservation-editor__loading' }).text(getString('loadingReservation', 'Loading reservation…')));
            $cell.append($container);
            $editorRow.append($cell);
            $row.after($editorRow);

            $button.attr('aria-expanded', 'true').addClass('is-active');

            var cached = reservationCache[reservationId];

            if (cached && cached.reservation) {
                renderEditorContent($editorRow, reservationId, cached.reservation);
                return;
            }

            fetchReservation(reservationId).done(function(response) {
                if (response && response.success && response.data && response.data.reservation) {
                    reservationCache[reservationId] = response.data;
                    renderEditorContent($editorRow, reservationId, response.data.reservation);
                } else {
                    handleReservationError($editorRow, getString('loadError', 'Unable to load reservation details. Please try again.'));
                }
            }).fail(function(response) {
                var message = getString('loadError', 'Unable to load reservation details. Please try again.');

                if (response && response.data) {
                    message = response.data;
                }

                handleReservationError($editorRow, message);
            });
        });

        $(document).on('click', '.gms-reservation-cancel', function(e) {
            e.preventDefault();

            var $editorRow = $(this).closest('tr.gms-reservation-editor');
            var $triggerRow = $editorRow.prev('tr');

            $editorRow.remove();

            if ($triggerRow && $triggerRow.length) {
                $triggerRow.find('.gms-reservation-toggle').attr('aria-expanded', 'false').removeClass('is-active');
            }
        });

        $(document).on('submit', '.gms-reservation-form', function(e) {
            e.preventDefault();

            var $form = $(this);
            var reservationId = parseInt($form.data('reservation-id'), 10);

            if (!reservationId) {
                return;
            }

            var $editorRow = $form.closest('tr.gms-reservation-editor');
            var $row = $editorRow.prev('tr');
            var $submitButton = $form.find('button[type="submit"]');
            var originalSubmitText = $submitButton.text();
            var $feedback = $form.find('.gms-reservation-feedback');

            $feedback.removeClass('is-error is-success').text('');
            $submitButton.prop('disabled', true).text(getString('saving', 'Saving…'));

            var formData = {};

            $.each($form.serializeArray(), function(_, field) {
                if (field.name) {
                    formData[field.name] = field.value;
                }
            });

            var ajaxUrl = getAjaxUrl();

            if (!ajaxUrl) {
                $feedback.addClass('is-error').text(getString('ajaxUnavailable', 'Unable to communicate with the server.'));
                $submitButton.prop('disabled', false).text(originalSubmitText);
                return;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'gms_update_reservation',
                    reservation_id: reservationId,
                    reservation: formData,
                    nonce: getNonce()
                }
            }).done(function(response) {
                if (!response || !response.success || !response.data) {
                    var genericError = getString('updateError', 'Unable to save the reservation. Please try again.');
                    var message = response && response.data ? response.data : genericError;
                    $feedback.addClass('is-error').text(message);
                    return;
                }

                var payload = response.data;
                reservationCache[reservationId] = payload;

                populateReservationForm($form, payload.reservation || {});
                updateReservationRow($row, payload.display);

                var successMessage = getString('updateSuccess', 'Reservation updated.');
                $feedback.addClass('is-success').text(successMessage);

                var $newToggle = $row.find('.column-booking_reference .gms-reservation-toggle');

                if ($newToggle.length) {
                    $newToggle.attr('aria-expanded', 'true').addClass('is-active');
                }
            }).fail(function(xhr) {
                var genericError = getString('updateError', 'Unable to save the reservation. Please try again.');
                var message = genericError;

                if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                    message = xhr.responseJSON.data;
                }

                $feedback.addClass('is-error').text(message);
            }).always(function() {
                $submitButton.prop('disabled', false).text(originalSubmitText);
            });
        });


        // Tab switching functionality for hash-based tabs only
        $('.nav-tab').on('click', function(e) {
            var target = $(this).attr('href');
            if (target && target.charAt(0) === '#') {
                e.preventDefault();

                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                $('.gms-tab-content').removeClass('active');
                $(target).addClass('active');
            }
        });

        // Media uploader for logo selection
        $('.gms-upload-logo').on('click', function(e) {
            e.preventDefault();

            var button = $(this);
            var field = $('#' + button.data('target'));
            var preview = $('#' + button.data('preview'));

            var frame = wp.media({
                title: button.data('title') || 'Select Logo',
                button: {
                    text: button.data('button-text') || 'Use this logo'
                },
                multiple: false,
                library: {
                    type: ['image']
                }
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                field.val(attachment.url).trigger('change');
                var image = $('<img />', {
                    src: attachment.url,
                    alt: attachment.alt || '',
                    css: {
                        maxWidth: '200px',
                        height: 'auto',
                        marginTop: '10px'
                    }
                });
                preview.html(image).show();
            });

            frame.open();
        });

        $('.gms-remove-logo').on('click', function(e) {
            e.preventDefault();

            var button = $(this);
            var field = $('#' + button.data('target'));
            var preview = $('#' + button.data('preview'));

            field.val('').trigger('change');
            preview.empty().hide();
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
                    nonce: getNonce()
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
                    nonce: getNonce()
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
                    nonce: getNonce()
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
                    nonce: getNonce()
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
            
            var genericWebhookUrl = getGenericWebhookUrl();

            if (!genericWebhookUrl) {
                alert('Webhook URL is not configured.');
                button.prop('disabled', false).text('Test Webhook');
                return;
            }

            $.ajax({
                url: genericWebhookUrl,
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
                        nonce: getNonce()
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
                    nonce: getNonce()
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