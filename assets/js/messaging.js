(function() {
    'use strict';

    function initMessagingApp() {
        var container = document.getElementById('gms-messaging-app');
        if (!container || typeof window.gmsMessaging === 'undefined') {
            return;
        }

        var config = window.gmsMessaging || {};
        var strings = config.strings || {};
        var threadsPerPage = config.perPage || 20;
        var pollInterval = Number(config.refreshInterval || 0);
        if (Number.isNaN(pollInterval) || pollInterval < 10000) {
            pollInterval = 20000;
        }

        var state = {
            threads: [],
            threadsPage: 1,
            threadsTotalPages: 1,
            selectedThreadKey: null,
            selectedThread: null,
            messages: [],
            loadingThreads: false,
            loadingMessages: false,
            sending: false,
            searchQuery: '',
            pendingMessages: new Map(),
            pollTimer: null,
            initialized: false,
        };

        var layout = document.createElement('div');
        layout.className = 'gms-messaging';

        var sidebar = document.createElement('aside');
        sidebar.className = 'gms-messaging__sidebar';

        var searchForm = document.createElement('form');
        searchForm.className = 'gms-messaging__search';
        searchForm.setAttribute('role', 'search');

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'gms-messaging__search-input';
        searchInput.placeholder = strings.searchPlaceholder || '';
        searchInput.setAttribute('aria-label', strings.searchPlaceholder || '');

        var searchButton = document.createElement('button');
        searchButton.type = 'submit';
        searchButton.className = 'gms-messaging__search-button button';
        searchButton.textContent = strings.searchAction || 'Search';

        searchForm.appendChild(searchInput);
        searchForm.appendChild(searchButton);
        sidebar.appendChild(searchForm);

        var threadsList = document.createElement('div');
        threadsList.className = 'gms-messaging__threads';
        threadsList.setAttribute('role', 'list');
        sidebar.appendChild(threadsList);

        var pagination = document.createElement('div');
        pagination.className = 'gms-messaging__pagination';

        var prevButton = document.createElement('button');
        prevButton.type = 'button';
        prevButton.className = 'button gms-messaging__pagination-button';
        prevButton.textContent = '‹';
        prevButton.setAttribute('aria-label', strings.previousPage || 'Previous conversations');

        var paginationStatus = document.createElement('span');
        paginationStatus.className = 'gms-messaging__pagination-status';

        var nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'button gms-messaging__pagination-button';
        nextButton.textContent = '›';
        nextButton.setAttribute('aria-label', strings.nextPage || 'Next conversations');

        pagination.appendChild(prevButton);
        pagination.appendChild(paginationStatus);
        pagination.appendChild(nextButton);
        sidebar.appendChild(pagination);

        var panel = document.createElement('section');
        panel.className = 'gms-messaging__panel';

        var panelHeader = document.createElement('header');
        panelHeader.className = 'gms-messaging__panel-header';

        var headerTitles = document.createElement('div');
        headerTitles.className = 'gms-messaging__panel-titles';

        var threadTitle = document.createElement('h2');
        threadTitle.className = 'gms-messaging__thread-title';
        headerTitles.appendChild(threadTitle);

        var threadSubtitle = document.createElement('p');
        threadSubtitle.className = 'gms-messaging__thread-subtitle';
        headerTitles.appendChild(threadSubtitle);

        var markReadButton = document.createElement('button');
        markReadButton.type = 'button';
        markReadButton.className = 'button button-secondary gms-messaging__mark-read';
        markReadButton.textContent = strings.markRead || 'Mark as read';
        markReadButton.disabled = true;

        panelHeader.appendChild(headerTitles);
        panelHeader.appendChild(markReadButton);

        var threadMeta = document.createElement('div');
        threadMeta.className = 'gms-messaging__thread-meta';

        var messagesWrapper = document.createElement('div');
        messagesWrapper.className = 'gms-messaging__messages-wrapper';

        var messagesList = document.createElement('div');
        messagesList.className = 'gms-messaging__messages';
        messagesList.setAttribute('role', 'log');
        messagesList.setAttribute('aria-live', 'polite');
        messagesWrapper.appendChild(messagesList);

        var composerForm = document.createElement('form');
        composerForm.className = 'gms-messaging__composer';
        composerForm.noValidate = true;

        var templateRow = document.createElement('div');
        templateRow.className = 'gms-messaging__composer-row';

        var templateSelect = document.createElement('select');
        templateSelect.className = 'gms-messaging__template';
        templateSelect.setAttribute('aria-label', strings.templatePlaceholder || 'Templates');

        var templatePlaceholder = document.createElement('option');
        templatePlaceholder.value = '';
        templatePlaceholder.textContent = strings.templatePlaceholder || '';
        templateSelect.appendChild(templatePlaceholder);

        if (Array.isArray(config.templates)) {
            config.templates.forEach(function(template) {
                if (!template || !template.content) {
                    return;
                }
                var option = document.createElement('option');
                option.value = template.content;
                option.textContent = template.label || template.id;
                templateSelect.appendChild(option);
            });
        }

        templateRow.appendChild(templateSelect);
        composerForm.appendChild(templateRow);

        var textarea = document.createElement('textarea');
        textarea.className = 'gms-messaging__input';
        textarea.placeholder = strings.sendPlaceholder || '';
        textarea.setAttribute('rows', '4');
        textarea.setAttribute('aria-label', strings.sendPlaceholder || '');
        composerForm.appendChild(textarea);

        var composerFooter = document.createElement('div');
        composerFooter.className = 'gms-messaging__composer-footer';

        var statusText = document.createElement('span');
        statusText.className = 'gms-messaging__status';
        statusText.setAttribute('aria-live', 'polite');
        composerFooter.appendChild(statusText);

        var sendButton = document.createElement('button');
        sendButton.type = 'submit';
        sendButton.className = 'button button-primary gms-messaging__send';
        sendButton.textContent = strings.sendLabel || 'Send';
        sendButton.disabled = true;

        composerFooter.appendChild(sendButton);
        composerForm.appendChild(composerFooter);

        panel.appendChild(panelHeader);
        panel.appendChild(threadMeta);
        panel.appendChild(messagesWrapper);
        panel.appendChild(composerForm);

        layout.appendChild(sidebar);
        layout.appendChild(panel);

        container.innerHTML = '';
        container.appendChild(layout);

        var dateFormatter;
        try {
            dateFormatter = new Intl.DateTimeFormat(config.locale || undefined, {
                dateStyle: 'medium',
                timeStyle: 'short'
            });
        } catch (err) {
            dateFormatter = null;
        }

        var statusTimer = null;

        function showStatus(message, isError) {
            if (!statusText) {
                return;
            }
            statusText.textContent = message || '';
            statusText.classList.toggle('is-error', !!isError);
            if (statusTimer) {
                window.clearTimeout(statusTimer);
            }
            if (message) {
                statusTimer = window.setTimeout(function() {
                    statusText.textContent = '';
                    statusText.classList.remove('is-error');
                }, 5000);
            }
        }

        function pad(number) {
            return number < 10 ? '0' + number : String(number);
        }

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

        function isNearBottom(element) {
            if (!element) {
                return false;
            }
            var threshold = 60;
            return element.scrollHeight - element.scrollTop - element.clientHeight < threshold;
        }

        function scrollToBottom(element) {
            if (!element) {
                return;
            }
            element.scrollTop = element.scrollHeight;
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

        function getMessagesWithPending() {
            var combined = state.messages.slice();
            state.pendingMessages.forEach(function(message) {
                combined.push(message);
            });
            combined.sort(function(a, b) {
                var aTime = a.sent_at || '';
                var bTime = b.sent_at || '';
                if (aTime === bTime) {
                    return (a.id || '').localeCompare(b.id || '');
                }
                return aTime < bTime ? -1 : 1;
            });
            return combined;
        }

        function renderThreads() {
            threadsList.innerHTML = '';

            if (state.loadingThreads) {
                var loading = document.createElement('div');
                loading.className = 'gms-messaging__empty';
                loading.textContent = strings.loading || 'Loading…';
                threadsList.appendChild(loading);
                return;
            }

            if (!state.threads.length) {
                var empty = document.createElement('div');
                empty.className = 'gms-messaging__empty';
                empty.textContent = strings.noConversations || '';
                threadsList.appendChild(empty);
                return;
            }

            state.threads.forEach(function(thread) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'gms-thread';
                item.setAttribute('role', 'listitem');
                item.dataset.threadKey = thread.thread_key;

                if (thread.thread_key === state.selectedThreadKey) {
                    item.classList.add('is-active');
                }

                var title = document.createElement('div');
                title.className = 'gms-thread__title';
                title.textContent = thread.guest_name || strings.unknownGuest || '';
                item.appendChild(title);

                if (thread.property_name) {
                    var subtitle = document.createElement('div');
                    subtitle.className = 'gms-thread__subtitle';
                    subtitle.textContent = thread.property_name;
                    item.appendChild(subtitle);
                }

                if (thread.last_message_preview) {
                    var preview = document.createElement('div');
                    preview.className = 'gms-thread__preview';
                    preview.textContent = thread.last_message_preview;
                    item.appendChild(preview);
                }

                if (thread.last_message_at) {
                    var stamp = document.createElement('time');
                    stamp.className = 'gms-thread__timestamp';
                    stamp.dateTime = thread.last_message_at;
                    stamp.textContent = formatTimestamp(thread.last_message_at);
                    item.appendChild(stamp);
                }

                if (thread.unread_count && thread.unread_count > 0) {
                    var badge = document.createElement('span');
                    badge.className = 'gms-thread__badge';
                    badge.textContent = String(thread.unread_count);
                    item.appendChild(badge);
                }

                item.addEventListener('click', function() {
                    if (thread.thread_key !== state.selectedThreadKey) {
                        loadThread(thread.thread_key);
                    }
                });

                threadsList.appendChild(item);
            });

            paginationStatus.textContent = strings.pagination ? strings.pagination.replace('%1$d', state.threadsPage).replace('%2$d', state.threadsTotalPages) : state.threadsPage + ' / ' + state.threadsTotalPages;
            prevButton.disabled = state.threadsPage <= 1 || state.loadingThreads;
            nextButton.disabled = state.threadsPage >= state.threadsTotalPages || state.loadingThreads;
        }

        function renderThreadDetails() {
            var thread = state.selectedThread;
            if (!thread) {
                threadTitle.textContent = strings.conversationHeading || '';
                threadSubtitle.textContent = '';
                threadMeta.innerHTML = '';
                markReadButton.disabled = true;
                sendButton.disabled = true;
                return;
            }

            threadTitle.textContent = thread.guest_name || strings.unknownGuest || '';
            threadSubtitle.textContent = thread.property_name || '';

            var metaItems = [];
            if (thread.guest_phone) {
                metaItems.push({ label: strings.guestPhone || 'Phone', value: thread.guest_phone });
            }
            if (thread.guest_email) {
                metaItems.push({ label: strings.guestEmail || 'Email', value: thread.guest_email });
            }
            if (thread.booking_reference) {
                metaItems.push({ label: strings.bookingReference || 'Reference', value: thread.booking_reference });
            }

            threadMeta.innerHTML = '';
            if (metaItems.length) {
                var list = document.createElement('ul');
                list.className = 'gms-messaging__meta-list';
                metaItems.forEach(function(meta) {
                    var item = document.createElement('li');
                    item.className = 'gms-messaging__meta-item';

                    var label = document.createElement('span');
                    label.className = 'gms-messaging__meta-label';
                    label.textContent = meta.label + ':';

                    var value = document.createElement('span');
                    value.className = 'gms-messaging__meta-value';
                    value.textContent = meta.value;

                    item.appendChild(label);
                    item.appendChild(value);
                    list.appendChild(item);
                });
                threadMeta.appendChild(list);
            }

            markReadButton.disabled = false;
            sendButton.disabled = !textarea.value.trim().length;
        }

        function renderMessages(options) {
            var maintainScroll = options && options.maintainScroll ? options.maintainScroll : false;
            var shouldStickToBottom = maintainScroll || isNearBottom(messagesList);
            var messages = getMessagesWithPending();

            messagesList.innerHTML = '';

            if (state.loadingMessages) {
                var loading = document.createElement('div');
                loading.className = 'gms-messaging__messages-empty';
                loading.textContent = strings.loading || 'Loading…';
                messagesList.appendChild(loading);
                return;
            }

            if (!messages.length) {
                var empty = document.createElement('div');
                empty.className = 'gms-messaging__messages-empty';
                empty.textContent = strings.emptyThread || '';
                messagesList.appendChild(empty);
                return;
            }

            messages.forEach(function(message) {
                var bubble = document.createElement('article');
                bubble.className = 'gms-message gms-message--' + (message.direction === 'inbound' ? 'inbound' : 'outbound');
                if (message.pending) {
                    bubble.classList.add('is-pending');
                }

                var meta = document.createElement('header');
                meta.className = 'gms-message__meta';

                var sender = document.createElement('span');
                sender.className = 'gms-message__sender';
                sender.textContent = message.direction === 'inbound' ? (strings.unknownGuest || 'Guest') : 'You';
                meta.appendChild(sender);

                if (message.sent_at) {
                    var time = document.createElement('time');
                    time.className = 'gms-message__time';
                    time.dateTime = message.sent_at;
                    time.textContent = formatTimestamp(message.sent_at);
                    meta.appendChild(time);
                }

                bubble.appendChild(meta);

                var body = document.createElement('p');
                body.className = 'gms-message__body';
                body.textContent = message.message || '';
                bubble.appendChild(body);

                messagesList.appendChild(bubble);
            });

            if (shouldStickToBottom) {
                scrollToBottom(messagesList);
            }
        }

        function updateThreadCollection(updatedThread) {
            if (!updatedThread) {
                return;
            }
            var found = false;
            state.threads = state.threads.map(function(thread) {
                if (thread.thread_key === updatedThread.thread_key) {
                    found = true;
                    return Object.assign({}, thread, updatedThread);
                }
                return thread;
            });

            if (!found && updatedThread.thread_key) {
                state.threads.unshift(updatedThread);
            }

            if (state.selectedThreadKey === updatedThread.thread_key) {
                state.selectedThread = Object.assign({}, state.selectedThread || {}, updatedThread);
            }
        }

        function fetchThreads(preservePage) {
            if (state.loadingThreads) {
                return;
            }

            state.loadingThreads = true;
            renderThreads();

            var page = preservePage ? state.threadsPage : 1;

            request('gms_list_message_threads', {
                page: page,
                per_page: threadsPerPage,
                search: state.searchQuery
            }).then(function(data) {
                state.loadingThreads = false;
                state.threads = Array.isArray(data.items) ? data.items : [];
                state.threadsPage = data.page || 1;
                state.threadsTotalPages = data.total_pages || 1;
                if (!state.initialized && !state.selectedThreadKey && state.threads.length) {
                    renderThreads();
                    loadThread(state.threads[0].thread_key);
                } else {
                    renderThreads();
                }
            }).catch(function(error) {
                state.loadingThreads = false;
                showStatus(error.message || strings.loadError, true);
                renderThreads();
            });
        }

        function loadThread(threadKey) {
            if (!threadKey) {
                return;
            }

            state.initialized = true;
            state.selectedThreadKey = threadKey;
            state.loadingMessages = true;
            state.messages = [];
            renderThreadDetails();
            renderMessages();
            renderThreads();

            request('gms_fetch_thread_messages', {
                thread_key: threadKey,
                page: 1,
                per_page: 200,
                order: 'ASC'
            }).then(function(data) {
                state.loadingMessages = false;
                state.messages = (data.messages && Array.isArray(data.messages.items)) ? data.messages.items : [];
                state.selectedThread = data.thread || null;
                if (state.selectedThread) {
                    updateThreadCollection(state.selectedThread);
                }
                renderThreadDetails();
                renderMessages({ maintainScroll: true });
                renderThreads();
                markThreadRead(true);
            }).catch(function(error) {
                state.loadingMessages = false;
                showStatus(error.message || strings.messageLoadError, true);
                renderMessages();
            });
        }

        function refreshThreadMessages() {
            if (!state.selectedThreadKey) {
                return;
            }

            request('gms_fetch_thread_messages', {
                thread_key: state.selectedThreadKey,
                page: 1,
                per_page: 200,
                order: 'ASC'
            }).then(function(data) {
                state.messages = (data.messages && Array.isArray(data.messages.items)) ? data.messages.items : [];
                if (data.thread) {
                    state.selectedThread = data.thread;
                    updateThreadCollection(data.thread);
                }
                renderThreadDetails();
                renderMessages();
                renderThreads();
            }).catch(function() {
                // Silently ignore polling errors to avoid noise.
            });
        }

        function markThreadRead(silent) {
            if (!state.selectedThreadKey) {
                return;
            }

            state.threads = state.threads.map(function(thread) {
                if (thread.thread_key === state.selectedThreadKey) {
                    return Object.assign({}, thread, { unread_count: 0 });
                }
                return thread;
            });

            if (state.selectedThread) {
                state.selectedThread.unread_count = 0;
            }

            renderThreads();

            request('gms_mark_thread_read', {
                thread_key: state.selectedThreadKey
            }).then(function(data) {
                if (data && data.thread) {
                    updateThreadCollection(data.thread);
                    renderThreads();
                }
            }).catch(function(error) {
                if (!silent) {
                    showStatus(error.message || strings.loadError, true);
                }
            });
        }

        function sendMessage(text) {
            if (!state.selectedThreadKey || !text) {
                return;
            }

            var trimmed = text.trim();
            if (!trimmed) {
                return;
            }

            state.sending = true;
            sendButton.disabled = true;
            showStatus(strings.sending || 'Sending…', false);

            var temporaryId = 'pending-' + Date.now();
            var now = new Date();
            var isoTime = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

            var pendingMessage = {
                id: temporaryId,
                message: trimmed,
                direction: 'outbound',
                sent_at: isoTime,
                pending: true
            };

            state.pendingMessages.set(temporaryId, pendingMessage);
            renderMessages({ maintainScroll: true });

            request('gms_send_message_reply', {
                thread_key: state.selectedThreadKey,
                channel: (state.selectedThread && state.selectedThread.channel) || 'sms',
                message: trimmed
            }).then(function(data) {
                state.sending = false;
                state.pendingMessages.delete(temporaryId);
                if (data && data.message) {
                    var replaced = false;
                    state.messages = state.messages.map(function(message) {
                        if (message.id === temporaryId) {
                            replaced = true;
                            return data.message;
                        }
                        return message;
                    });
                    if (!replaced) {
                        state.messages.push(data.message);
                    }
                    if (state.selectedThread) {
                        state.selectedThread.last_message_at = data.message.sent_at;
                        state.selectedThread.last_message_preview = data.message.message;
                    }
                    updateThreadCollection(state.selectedThread);
                    fetchThreads(true);
                } else {
                    state.pendingMessages.delete(temporaryId);
                }
                showStatus(strings.sendSuccess || 'Sent', false);
                renderMessages({ maintainScroll: true });
                textarea.value = '';
                sendButton.disabled = true;
            }).catch(function(error) {
                state.sending = false;
                state.pendingMessages.delete(temporaryId);
                state.messages = state.messages.filter(function(message) {
                    return message.id !== temporaryId;
                });
                renderMessages({ maintainScroll: true });
                showStatus(error.message || strings.sendFailed, true);
                sendButton.disabled = false;
            });
        }

        searchForm.addEventListener('submit', function(event) {
            event.preventDefault();
            state.searchQuery = searchInput.value.trim();
            state.threadsPage = 1;
            fetchThreads(false);
        });

        prevButton.addEventListener('click', function() {
            if (state.threadsPage <= 1) {
                return;
            }
            state.threadsPage -= 1;
            fetchThreads(true);
        });

        nextButton.addEventListener('click', function() {
            if (state.threadsPage >= state.threadsTotalPages) {
                return;
            }
            state.threadsPage += 1;
            fetchThreads(true);
        });

        markReadButton.addEventListener('click', function() {
            markThreadRead(false);
        });

        composerForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (!state.sending) {
                sendMessage(textarea.value);
            }
        });

        templateSelect.addEventListener('change', function() {
            if (!templateSelect.value) {
                return;
            }
            textarea.value = templateSelect.value;
            templateSelect.selectedIndex = 0;
            textarea.focus();
            try {
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (err) {
                var evt = document.createEvent('Event');
                evt.initEvent('input', true, true);
                textarea.dispatchEvent(evt);
            }
        });

        textarea.addEventListener('input', function() {
            if (state.selectedThread && textarea.value.trim().length) {
                sendButton.disabled = false;
            } else {
                sendButton.disabled = true;
            }
        });

        fetchThreads(false);

        if (pollInterval > 0) {
            state.pollTimer = window.setInterval(function() {
                fetchThreads(true);
                refreshThreadMessages();
            }, pollInterval);
        }

        window.addEventListener('beforeunload', function() {
            if (state.pollTimer) {
                window.clearInterval(state.pollTimer);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMessagingApp);
    } else {
        initMessagingApp();
    }
})();
