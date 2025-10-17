(function($) {
    'use strict';

    if (typeof gmsHousekeeperChecklist === 'undefined') {
        return;
    }

    var settings = gmsHousekeeperChecklist;

    function getPhases(form) {
        return Array.prototype.slice.call(form.querySelectorAll('.gms-housekeeper-phase'));
    }

    function setActivePhase(phases, index) {
        phases.forEach(function(phase, idx) {
            if (idx === index) {
                phase.classList.add('is-active');
                phase.removeAttribute('aria-hidden');
            } else {
                phase.classList.remove('is-active');
                phase.setAttribute('aria-hidden', 'true');
            }
        });
    }

    function focusFirstInput(phase) {
        var input = phase.querySelector('input, textarea, select');
        if (input) {
            input.focus({ preventScroll: false });
        }
    }

    function validatePhase(phase) {
        var valid = true;
        var requiredFields = phase.querySelectorAll('[required]');

        requiredFields.forEach(function(field) {
            if (field.type === 'checkbox') {
                if (!field.checked) {
                    valid = false;
                }
            } else if (field.type === 'file') {
                if (!field.files || !field.files.length) {
                    valid = false;
                }
            } else if (field.value.trim() === '') {
                valid = false;
            }
        });

        if (!valid) {
            showStatus('error', settings.i18n.taskRequirement);
        }

        return valid;
    }

    function validatePhotos(form) {
        var photoGroups = form.querySelectorAll('.gms-housekeeper-photos');
        var missing = [];

        photoGroups.forEach(function(group) {
            var inputs = group.querySelectorAll('input[type="file"]');
            var groupValid = true;

            inputs.forEach(function(input) {
                if (!input.files || !input.files.length) {
                    groupValid = false;
                }
            });

            if (!groupValid) {
                missing.push(group.getAttribute('data-photo-group'));
            }
        });

        if (missing.length) {
            showStatus('error', settings.i18n.photoRequirement);
            return false;
        }

        return true;
    }

    function showStatus(type, message) {
        var region = document.querySelector('.gms-housekeeper-status');
        if (!region) {
            return;
        }

        region.className = 'gms-housekeeper-status gms-housekeeper-status--' + type;
        region.textContent = message;
    }

    function disableForm(form) {
        Array.prototype.forEach.call(form.elements, function(element) {
            element.disabled = true;
        });
    }

    function enableForm(form) {
        Array.prototype.forEach.call(form.elements, function(element) {
            element.disabled = false;
        });
    }

    function handleSubmit(event) {
        event.preventDefault();

        var form = event.currentTarget;
        var phases = getPhases(form);

        if (!validatePhotos(form)) {
            return;
        }

        var formData = new FormData(form);
        formData.append('token', settings.token);
        formData.append('submit_nonce', settings.submitNonce);

        disableForm(form);
        showStatus('info', settings.i18n.saving);

        fetch(settings.restUrl, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': settings.restNonce
            },
            body: formData
        }).then(function(response) {
            if (!response.ok) {
                return response.json().then(function(data) {
                    var message = data && data.message ? data.message : settings.i18n.taskRequirement;
                    throw new Error(message);
                });
            }
            return response.json();
        }).then(function(data) {
            showStatus('success', data && data.message ? data.message : settings.i18n.success);
            disableForm(form);
        }).catch(function(error) {
            showStatus('error', error.message);
            enableForm(form);
        });
    }

    $(function() {
        var form = document.getElementById('gms-housekeeper-checklist-form');
        if (!form) {
            return;
        }

        var phases = getPhases(form);
        if (!phases.length) {
            return;
        }

        var currentIndex = 0;
        setActivePhase(phases, currentIndex);
        focusFirstInput(phases[currentIndex]);

        var nextButton = form.querySelector('[data-action="next"]');
        var prevButton = form.querySelector('[data-action="previous"]');
        var submitButton = form.querySelector('[data-action="submit"]');

        function updateControls() {
            prevButton.style.display = currentIndex === 0 ? 'none' : '';
            nextButton.style.display = currentIndex >= phases.length - 1 ? 'none' : '';
            submitButton.style.display = currentIndex >= phases.length - 1 ? '' : 'none';
        }

        updateControls();

        nextButton.addEventListener('click', function() {
            if (!validatePhase(phases[currentIndex])) {
                return;
            }

            if (currentIndex < phases.length - 1) {
                currentIndex += 1;
                setActivePhase(phases, currentIndex);
                focusFirstInput(phases[currentIndex]);
                updateControls();
                showStatus('info', '');
            }
        });

        prevButton.addEventListener('click', function() {
            if (currentIndex > 0) {
                currentIndex -= 1;
                setActivePhase(phases, currentIndex);
                focusFirstInput(phases[currentIndex]);
                updateControls();
                showStatus('info', '');
            }
        });

        form.addEventListener('submit', handleSubmit);
    });
})(jQuery);
