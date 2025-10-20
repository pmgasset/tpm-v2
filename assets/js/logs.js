(function() {
    'use strict';

    function initLogsApp() {
        var container = document.getElementById('gms-logs-app');
        if (!container || typeof window.gmsLogs === 'undefined') {
            return;
        }

        var layout = container.querySelector('.gms-logs');
        if (!layout) {
            return;
        }

        var config = window.gmsLogs || {};
        var strings = config.strings || {};
        var perPage = Number(config.perPage || 20);
        if (!Number.isFinite(perPage) || perPage <= 0) {
            perPage = 20;
        }

        var searchForm = layout.querySelector('.gms-logs__search');
        var searchInput = searchForm ? searchForm.querySelector('.gms-logs__search-input') : null;
        var searchButton = searchForm ? searchForm.querySelector('.gms-logs__search-button') : null;
        var listContainer = layout.querySelector('.gms-logs__list');
        var detailContainer = layout.querySelector('.gms-logs__detail-body');
        var pagination = layout.querySelector('.gms-logs__pagination');
        var paginationStatus = pagination ? pagination.querySelector('.gms-logs__pagination-status') : null;
        var prevButton = pagination ? pagination.querySelector('[data-direction="prev"]') : null;
        var nextButton = pagination ? pagination.querySelector('[data-direction="next"]') : null;

        if (!listContainer || !detailContainer) {
            return;
        }

        if (searchInput && strings.searchPlaceholder) {
            searchInput.placeholder = strings.searchPlaceholder;
            searchInput.setAttribute('aria-label', strings.searchPlaceholder);
        }
        if (searchButton && strings.searchAction) {
            searchButton.textContent = strings.searchAction;
        }

        var placeholder = container.querySelector('.gms-messaging-app__placeholder');
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
        }

        var dateFormatter;
        try {
            dateFormatter = new Intl.DateTimeFormat(config.locale || undefined, {
                dateStyle: 'medium',
                timeStyle: 'short'
            });
        } catch (err) {
            dateFormatter = null;
        }

        var state = {
            items: [],
            page: 1,
            totalPages: 1,
            searchQuery: '',
            selectedId: null,
            selectedEntry: null,
            loading: false,
            errorMessage: ''
        };

        function formatTimestamp(value) {
            if (!value) {
                return '';
            }
            var normalised = value.replace(' ', 'T');
            var date = new Date(normalised);
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            if (dateFormatter) {
                return dateFormatter.format(date);
            }
            return date.toLocaleString();
        }

        function request(action, payload) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', config.nonce || '');
            if (payload && typeof payload === 'object') {
                Object.keys(payload).forEach(function(key) {
                    if (payload[key] !== undefined && payload[key] !== null) {
                        body.append(key, payload[key]);
                    }
                });
            }

            return fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function(response) {
                return response.text().then(function(text) {
                    if (!response.ok) {
                        throw new Error(strings.loadError || 'Request failed');
                    }
                    var data;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (err) {
                        throw new Error(text || strings.loadError || 'Request failed');
                    }
                    return data;
                });
            }).then(function(data) {
                if (!data || data.success !== true) {
                    var message = data && data.data && data.data.message ? data.data.message : (strings.loadError || 'Request failed');
                    throw new Error(message);
                }
                return data.data;
            });
        }

        function renderList() {
            listContainer.innerHTML = '';

            if (state.loading) {
                var loading = document.createElement('div');
                loading.className = 'gms-logs__empty';
                loading.textContent = strings.loading || 'Loading…';
                listContainer.appendChild(loading);
                updatePaginationControls();
                return;
            }

            if (!state.items.length) {
                var empty = document.createElement('div');
                empty.className = 'gms-logs__empty';
                empty.textContent = state.errorMessage || strings.empty || '';
                listContainer.appendChild(empty);
                updatePaginationControls();
                return;
            }

            state.items.forEach(function(entry) {
                if (!entry) {
                    return;
                }

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'gms-log';
                button.setAttribute('role', 'listitem');
                button.dataset.logId = String(entry.id);
                if (Number(entry.id) === Number(state.selectedId)) {
                    button.classList.add('is-active');
                }

                var title = document.createElement('div');
                title.className = 'gms-log__title';
                title.textContent = entry.subject || entry.message || entry.channel || strings.subjectFallback || 'Log';
                button.appendChild(title);

                var metaRow = document.createElement('div');
                metaRow.className = 'gms-log__meta';

                if (entry.channel) {
                    var channel = document.createElement('span');
                    channel.className = 'gms-log__meta-channel';
                    channel.textContent = entry.channel.toUpperCase();
                    metaRow.appendChild(channel);
                }

                if (entry.property_name) {
                    var property = document.createElement('span');
                    property.className = 'gms-log__meta-property';
                    property.textContent = entry.property_name;
                    metaRow.appendChild(property);
                }

                if (entry.sent_at) {
                    var time = document.createElement('time');
                    time.className = 'gms-log__meta-time';
                    time.dateTime = entry.sent_at;
                    time.textContent = formatTimestamp(entry.sent_at);
                    metaRow.appendChild(time);
                }

                if (metaRow.childNodes.length) {
                    button.appendChild(metaRow);
                }

                button.addEventListener('click', function() {
                    selectEntry(entry.id);
                });

                listContainer.appendChild(button);
            });

            updatePaginationControls();
        }

        function renderDetail() {
            detailContainer.innerHTML = '';

            if (!state.selectedEntry) {
                var placeholderDetail = document.createElement('div');
                placeholderDetail.className = 'gms-logs__detail-empty';
                placeholderDetail.textContent = strings.detailPlaceholder || '';
                detailContainer.appendChild(placeholderDetail);
                return;
            }

            var entry = state.selectedEntry;

            var header = document.createElement('header');
            header.className = 'gms-logs__detail-header';

            var heading = document.createElement('h2');
            heading.className = 'gms-logs__detail-title';
            heading.textContent = entry.subject || entry.message || entry.channel || strings.subjectFallback || 'Log';
            header.appendChild(heading);

            if (entry.sent_at) {
                var timestamp = document.createElement('time');
                timestamp.className = 'gms-logs__detail-time';
                timestamp.dateTime = entry.sent_at;
                timestamp.textContent = formatTimestamp(entry.sent_at);
                header.appendChild(timestamp);
            }

            detailContainer.appendChild(header);

            var metaList = document.createElement('ul');
            metaList.className = 'gms-logs__detail-meta';

            if (entry.channel) {
                metaList.appendChild(buildMetaItem(strings.channelLabel || 'Channel', entry.channel.toUpperCase()));
            }
            if (entry.delivery_status) {
                metaList.appendChild(buildMetaItem(strings.statusLabel || 'Status', entry.delivery_status));
            }
            if (entry.property_name) {
                metaList.appendChild(buildMetaItem(strings.propertyLabel || 'Property', entry.property_name));
            }
            if (entry.guest_name) {
                metaList.appendChild(buildMetaItem(strings.guestLabel || 'Guest', entry.guest_name));
            }
            if (entry.guest_phone) {
                metaList.appendChild(buildMetaItem(strings.phoneLabel || 'Phone', entry.guest_phone));
            }
            if (entry.guest_email) {
                metaList.appendChild(buildMetaItem(strings.emailLabel || 'Email', entry.guest_email));
            }
            if (entry.booking_reference) {
                metaList.appendChild(buildMetaItem(strings.referenceLabel || 'Reference', entry.booking_reference));
            }

            if (metaList.childNodes.length) {
                detailContainer.appendChild(metaList);
            }

            if (entry.message) {
                var body = document.createElement('p');
                body.className = 'gms-logs__detail-message';
                body.textContent = entry.message;
                detailContainer.appendChild(body);
            }

            if (entry.response_data && typeof entry.response_data === 'object') {
                var response = document.createElement('pre');
                response.className = 'gms-logs__detail-response';
                try {
                    response.textContent = JSON.stringify(entry.response_data, null, 2);
                } catch (err) {
                    response.textContent = String(entry.response_data);
                }
                detailContainer.appendChild(response);
            } else if (typeof entry.response_data === 'string') {
                var responseText = document.createElement('pre');
                responseText.className = 'gms-logs__detail-response';
                responseText.textContent = entry.response_data;
                detailContainer.appendChild(responseText);
            }
        }

        function buildMetaItem(labelText, valueText) {
            var item = document.createElement('li');
            item.className = 'gms-logs__detail-meta-item';

            var label = document.createElement('span');
            label.className = 'gms-logs__detail-term';
            label.textContent = labelText;

            var value = document.createElement('span');
            value.className = 'gms-logs__detail-description';
            value.textContent = valueText;

            item.appendChild(label);
            item.appendChild(value);
            return item;
        }

        function updatePaginationControls() {
            if (!pagination) {
                return;
            }
            if (paginationStatus) {
                var text = strings.pagination ? strings.pagination.replace('%1$d', state.page).replace('%2$d', state.totalPages) : state.page + ' / ' + state.totalPages;
                paginationStatus.textContent = text;
            }
            if (prevButton) {
                prevButton.disabled = state.page <= 1 || state.loading;
            }
            if (nextButton) {
                nextButton.disabled = state.page >= state.totalPages || state.loading;
            }
        }

        function selectEntry(id) {
            state.selectedId = Number(id);
            state.selectedEntry = state.items.find(function(entry) {
                return entry && Number(entry.id) === state.selectedId;
            }) || null;
            renderList();
            renderDetail();
        }

        function fetchLogs(keepPage) {
            if (!keepPage) {
                state.page = 1;
            }
            state.loading = true;
            renderList();

            state.errorMessage = '';

            request('gms_list_operational_logs', {
                page: state.page,
                per_page: perPage,
                search: state.searchQuery
            }).then(function(data) {
                state.loading = false;
                state.items = Array.isArray(data.items) ? data.items : [];
                state.page = data.page || 1;
                state.totalPages = data.total_pages || 1;

                if (state.items.length) {
                    var match = state.items.find(function(entry) {
                        return entry && Number(entry.id) === Number(state.selectedId);
                    });
                    if (!match) {
                        match = state.items[0];
                    }
                    state.selectedId = match ? Number(match.id) : null;
                    state.selectedEntry = match ? Object.assign({}, match) : null;
                } else {
                    state.selectedId = null;
                    state.selectedEntry = null;
                }

                renderList();
                renderDetail();
            }).catch(function(error) {
                state.loading = false;
                state.items = [];
                state.selectedId = null;
                state.selectedEntry = null;
                state.errorMessage = error && error.message ? error.message : (strings.loadError || 'Unable to load logs');
                renderList();
                renderDetail();
            });
        }

        if (searchForm) {
            searchForm.addEventListener('submit', function(event) {
                event.preventDefault();
                state.searchQuery = searchInput ? searchInput.value.trim() : '';
                fetchLogs(false);
            });
        }

        if (prevButton) {
            prevButton.addEventListener('click', function() {
                if (state.page <= 1 || state.loading) {
                    return;
                }
                state.page -= 1;
                fetchLogs(true);
            });
        }

        if (nextButton) {
            nextButton.addEventListener('click', function() {
                if (state.page >= state.totalPages || state.loading) {
                    return;
                }
                state.page += 1;
                fetchLogs(true);
            });
        }

        fetchLogs(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLogsApp);
    } else {
        initLogsApp();
    }
})();
