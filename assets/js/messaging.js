(function() {
    'use strict';

    function initMessagingApp() {
        var container = document.getElementById('gms-messaging-app');
        if (!container || typeof window.gmsMessaging === 'undefined') {
            return;
        }

        var layout = container.querySelector('.gms-messaging');
        if (!layout) {
            return;
        }

        var config = window.gmsMessaging || {};
        var strings = config.strings || {};
        var threadsPerPage = Number(config.perPage || 20);
        if (!Number.isFinite(threadsPerPage) || threadsPerPage <= 0) {
            threadsPerPage = 20;
        }

        var pollInterval = Number(config.refreshInterval || 0);
        if (!Number.isFinite(pollInterval) || pollInterval < 10000) {
            pollInterval = 20000;
        }

        var channelOrder = Array.isArray(config.channels) && config.channels.length ? config.channels.slice() : ['sms'];
        channelOrder = channelOrder.map(function(channel) {
            return (channel || '').toLowerCase();
        }).filter(function(channel, index, list) {
            return channel && list.indexOf(channel) === index;
        });
        if (!channelOrder.length) {
            channelOrder = ['sms'];
        }

        var channelLabels = config.channelLabels || {};
        var defaultChannel = channelOrder[0];

        var channelNav = layout.querySelector('.gms-messaging__channels');
        var searchForm = layout.querySelector('.gms-messaging__search');
        var searchInput = searchForm ? searchForm.querySelector('.gms-messaging__search-input') : null;
        var searchButton = searchForm ? searchForm.querySelector('.gms-messaging__search-button') : null;
        var threadsList = layout.querySelector('.gms-messaging__threads');
        var pagination = layout.querySelector('.gms-messaging__pagination');
        var paginationStatus = pagination ? pagination.querySelector('.gms-messaging__pagination-status') : null;
        var prevButton = pagination ? pagination.querySelector('[data-direction="prev"]') : null;
        var nextButton = pagination ? pagination.querySelector('[data-direction="next"]') : null;
        var threadTitle = layout.querySelector('.gms-messaging__thread-title');
        var threadSubtitle = layout.querySelector('.gms-messaging__thread-subtitle');
        var threadMeta = layout.querySelector('.gms-messaging__thread-meta');
        var messagesWrapper = layout.querySelector('.gms-messaging__messages-wrapper');
        var messagesList = layout.querySelector('.gms-messaging__messages');
        var markReadButton = layout.querySelector('.gms-messaging__mark-read');
        var composerForm = layout.querySelector('.gms-messaging__composer');
        var templateRow = composerForm ? composerForm.querySelector('.gms-messaging__template-row') : null;
        var templateSearchInput = composerForm ? composerForm.querySelector('.gms-messaging__template-search') : null;
        var templateSelect = composerForm ? composerForm.querySelector('.gms-messaging__template') : null;
        var textarea = composerForm ? composerForm.querySelector('.gms-messaging__input') : null;
        var statusText = composerForm ? composerForm.querySelector('.gms-messaging__status') : null;
        var sendButton = composerForm ? composerForm.querySelector('.gms-messaging__send') : null;

        if (!channelNav || !threadsList || !messagesList || !composerForm || !textarea || !sendButton) {
            return;
        }

        if (!messagesWrapper) {
            messagesWrapper = messagesList.parentElement || messagesList;
        }

        var placeholder = container.querySelector('.gms-messaging-app__placeholder');
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
        }

        if (searchInput && strings.searchPlaceholder) {
            searchInput.placeholder = strings.searchPlaceholder;
            searchInput.setAttribute('aria-label', strings.searchPlaceholder);
        }
        if (searchButton && strings.searchAction) {
            searchButton.textContent = strings.searchAction;
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

        var statusTimer = null;
        var templateSearchTimer = null;

        var state = {
            activeChannel: defaultChannel,
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
            templates: [],
            templatesKey: '',
            templatesLoading: false,
            templateSearchTerm: '',
            templateChannel: ''
        };

        var channelCaches = {};
        channelOrder.forEach(function(channel) {
            channelCaches[channel] = {
                threads: [],
                page: 1,
                totalPages: 1,
                selectedThreadKey: null,
                selectedThread: null,
                messages: [],
                searchQuery: '',
                loadingThreads: false,
                loadingMessages: false,
                initialized: false,
                templates: [],
                templatesKey: '',
                templatesLoading: false,
                templateSearchTerm: '',
                templateChannel: ''
            };
        });

        if (!channelCaches[state.activeChannel]) {
            state.activeChannel = 'sms';
            if (!channelCaches[state.activeChannel]) {
                channelCaches[state.activeChannel] = {
                    threads: [],
                    page: 1,
                    totalPages: 1,
                    selectedThreadKey: null,
                    selectedThread: null,
                    messages: [],
                    searchQuery: '',
                    loadingThreads: false,
                    loadingMessages: false,
                    initialized: false,
                    templates: [],
                    templatesKey: '',
                    templatesLoading: false,
                    templateSearchTerm: '',
                    templateChannel: ''
                };
            }
        }

        var channelButtons = new Map();

        function getChannelLabel(channel) {
            var key = (channel || '').toLowerCase();
            if (channelLabels[key]) {
                return channelLabels[key];
            }
            if (channelLabels[channel]) {
                return channelLabels[channel];
            }
            if (!channel) {
                return '';
            }
            return channel.charAt(0).toUpperCase() + channel.slice(1);
        }

        channelNav.innerHTML = '';
        channelOrder.forEach(function(channel) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-secondary gms-messaging__channel';
            button.textContent = getChannelLabel(channel);
            button.dataset.channel = channel;
            button.addEventListener('click', function() {
                switchChannel(channel);
            });
            channelButtons.set(channel, button);
            channelNav.appendChild(button);
        });

        if (channelOrder.length <= 1) {
            channelNav.style.display = 'none';
            channelNav.setAttribute('aria-hidden', 'true');
        } else {
            channelNav.style.display = '';
            channelNav.removeAttribute('aria-hidden');
        }

        function updateChannelButtons() {
            channelButtons.forEach(function(button, channel) {
                button.classList.toggle('is-active', channel === state.activeChannel);
            });
        }

        function updateSearchControls() {
            if (!searchInput || !searchButton) {
                return;
            }
            var placeholder = strings.searchPlaceholder || '';
            var actionText = strings.searchAction || 'Search';
            searchInput.placeholder = placeholder;
            searchInput.setAttribute('aria-label', placeholder);
            searchButton.textContent = actionText;
        }

        function saveActiveChannelCache() {
            var key = state.activeChannel;
            var cache = channelCaches[key];
            if (!cache) {
                return;
            }

            cache.threads = state.threads.slice();
            cache.page = state.threadsPage;
            cache.totalPages = state.threadsTotalPages;
            cache.selectedThreadKey = state.selectedThreadKey;
            cache.selectedThread = state.selectedThread ? Object.assign({}, state.selectedThread) : null;
            cache.messages = state.messages.slice();
            cache.searchQuery = state.searchQuery;
            cache.loadingThreads = state.loadingThreads;
            cache.loadingMessages = state.loadingMessages;
            cache.initialized = state.initialized;
            cache.templates = state.templates.slice();
            cache.templatesKey = state.templatesKey;
            cache.templatesLoading = state.templatesLoading;
            cache.templateSearchTerm = state.templateSearchTerm;
            cache.templateChannel = state.templateChannel;
        }

        function applyChannelState(channel) {
            var key = (channel || '').toLowerCase();
            var cache = channelCaches[key];
            if (!cache) {
                return;
            }

            state.activeChannel = key;
            state.threads = cache.threads.slice();
            state.threadsPage = cache.page;
            state.threadsTotalPages = cache.totalPages;
            state.selectedThreadKey = cache.selectedThreadKey;
            state.selectedThread = cache.selectedThread ? Object.assign({}, cache.selectedThread) : null;
            state.messages = cache.messages.slice();
            state.loadingMessages = cache.loadingMessages;
            state.loadingThreads = cache.loadingThreads;
            state.initialized = cache.initialized;
            state.searchQuery = cache.searchQuery;
            state.templates = cache.templates.slice();
            state.templatesKey = cache.templatesKey;
            state.templatesLoading = cache.templatesLoading;
            state.templateSearchTerm = cache.templateSearchTerm;
            state.templateChannel = cache.templateChannel;
            if (state.pendingMessages && typeof state.pendingMessages.clear === 'function') {
                state.pendingMessages.clear();
            }

            if (templateRow) {
                templateRow.classList.toggle('is-loading', !!state.templatesLoading);
            }

            if (searchInput) {
                searchInput.value = state.searchQuery;
            }

            updateSearchControls();
            updateChannelButtons();
            updateComposerVisibility();
            renderThreads();
            renderThreadDetails();
            renderMessages();
        }

        function isSmsComposerActive() {
            if (state.activeChannel !== 'sms') {
                return false;
            }
            if (!state.selectedThread) {
                return false;
            }
            var threadChannel = (state.selectedThread.channel || 'sms').toLowerCase();
            return threadChannel === 'sms';
        }

        function updateComposerVisibility() {
            var smsActive = isSmsComposerActive();

            if (composerForm) {
                composerForm.classList.toggle('is-disabled', !smsActive);
            }
            if (textarea) {
                textarea.disabled = !smsActive;
                if (!smsActive) {
                    textarea.value = '';
                }
                textarea.placeholder = smsActive ? (strings.sendPlaceholder || '') : (strings.sendDisabled || strings.sendPlaceholder || '');
            }
            if (sendButton) {
                sendButton.disabled = !smsActive || !(textarea && textarea.value.trim().length);
                sendButton.classList.toggle('is-disabled', !smsActive);
            }
            if (templateRow) {
                templateRow.style.display = smsActive ? '' : 'none';
            }
            if (templateSearchInput) {
                templateSearchInput.disabled = !smsActive || state.templatesLoading || !state.selectedThread;
            }
            if (templateSelect) {
                templateSelect.disabled = !smsActive || state.templatesLoading || !state.templates.length;
            }
        }

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
            return pad(date.getMonth() + 1) + '/' + pad(date.getDate()) + '/' + date.getFullYear() + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
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
            if (!threadsList) {
                return;
            }
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
                if (paginationStatus) {
                    var emptyStatus = strings.pagination ? strings.pagination.replace('%1$d', state.threadsPage).replace('%2$d', state.threadsTotalPages) : state.threadsPage + ' / ' + state.threadsTotalPages;
                    paginationStatus.textContent = emptyStatus;
                }
                if (prevButton) {
                    prevButton.disabled = state.threadsPage <= 1;
                }
                if (nextButton) {
                    nextButton.disabled = state.threadsPage >= state.threadsTotalPages;
                }
                return;
            }

            state.threads.forEach(function(thread) {
                if (!thread || !thread.thread_key) {
                    return;
                }
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

                if (Number(thread.unread_count) > 0) {
                    var badge = document.createElement('span');
                    badge.className = 'gms-thread__badge';
                    badge.textContent = Number(thread.unread_count) > 9 ? '9+' : String(thread.unread_count);
                    item.appendChild(badge);
                }

                item.addEventListener('click', function() {
                    loadThread(thread.thread_key);
                });

                threadsList.appendChild(item);
            });

            if (paginationStatus) {
                var statusTextValue = strings.pagination ? strings.pagination.replace('%1$d', state.threadsPage).replace('%2$d', state.threadsTotalPages) : state.threadsPage + ' / ' + state.threadsTotalPages;
                paginationStatus.textContent = statusTextValue;
            }
            if (prevButton) {
                prevButton.disabled = state.threadsPage <= 1 || state.loadingThreads;
            }
            if (nextButton) {
                nextButton.disabled = state.threadsPage >= state.threadsTotalPages || state.loadingThreads;
            }
        }

        function renderThreadDetails() {
            if (!threadTitle || !threadSubtitle || !threadMeta) {
                return;
            }

            var thread = state.selectedThread;
            if (!thread) {
                threadTitle.textContent = strings.conversationHeading || '';
                threadSubtitle.textContent = '';
                threadMeta.innerHTML = '';
                if (markReadButton) {
                    markReadButton.disabled = true;
                }
                state.templateChannel = '';
                state.templateSearchTerm = '';
                state.templates = [];
                state.templatesKey = '';
                state.templatesLoading = false;
                updateComposerVisibility();
                renderTemplateOptions();
                return;
            }

            threadTitle.textContent = thread.guest_name || strings.unknownGuest || '';
            threadSubtitle.textContent = thread.property_name || '';

            var threadChannel = (thread.channel || 'sms').toLowerCase();
            var channelChanged = state.templateChannel !== threadChannel;
            ensureTemplatesForChannel(threadChannel, { resetSearch: channelChanged });

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

            if (markReadButton) {
                markReadButton.disabled = false;
            }
            updateComposerVisibility();
            sendButton.disabled = !isSmsComposerActive() || !textarea.value.trim().length;
        }

        function renderMessages(options) {
            var maintainScroll = options && options.maintainScroll ? options.maintainScroll : false;
            var shouldStickToBottom = maintainScroll || isNearBottom(messagesWrapper);

            messagesList.innerHTML = '';

            if (state.loadingMessages) {
                var loading = document.createElement('div');
                loading.className = 'gms-messaging__messages-empty';
                loading.textContent = strings.loading || 'Loading…';
                messagesList.appendChild(loading);
                return;
            }

            if (!state.selectedThread) {
                var placeholder = document.createElement('div');
                placeholder.className = 'gms-messaging__messages-empty';
                placeholder.textContent = strings.conversationHeading || '';
                messagesList.appendChild(placeholder);
                return;
            }

            var messages = getMessagesWithPending();
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
                scrollToBottom(messagesWrapper);
            }
        }

        function updateThreadCollection(thread) {
            if (!thread || !thread.thread_key) {
                return;
            }
            state.threads = state.threads.map(function(existing) {
                if (!existing || existing.thread_key !== thread.thread_key) {
                    return existing;
                }
                return Object.assign({}, existing, thread);
            });
        }

        function loadThread(threadKey) {
            if (!threadKey) {
                return;
            }

            state.initialized = true;
            state.selectedThreadKey = threadKey;
            state.loadingMessages = true;
            state.selectedThread = null;
            state.messages = [];
            state.templates = [];
            state.templatesKey = '';
            state.templateChannel = '';
            state.templateSearchTerm = '';
            state.templatesLoading = false;
            if (templateRow) {
                templateRow.classList.remove('is-loading');
            }
            if (state.pendingMessages && typeof state.pendingMessages.clear === 'function') {
                state.pendingMessages.clear();
            }
            textarea.value = '';
            sendButton.disabled = true;
            if (templateSearchTimer) {
                window.clearTimeout(templateSearchTimer);
                templateSearchTimer = null;
            }
            renderTemplateOptions();
            renderThreadDetails();
            renderMessages();
            renderThreads();

            request('gms_fetch_thread_messages', {
                thread_key: threadKey,
                channel: state.activeChannel
            }).then(function(data) {
                state.loadingMessages = false;
                state.selectedThread = data && data.thread ? Object.assign({}, data.thread) : null;
                state.messages = Array.isArray(data.messages) ? data.messages : [];
                if (state.selectedThread) {
                    updateThreadCollection(state.selectedThread);
                }
                renderThreads();
                renderThreadDetails();
                renderMessages({ maintainScroll: true });
                saveActiveChannelCache();
            }).catch(function(error) {
                state.loadingMessages = false;
                showStatus(error.message || strings.messageLoadError || strings.loadError, true);
                renderMessages();
            });
        }

        function fetchThreads(keepPage) {
            if (!keepPage) {
                state.threadsPage = 1;
            }

            state.loadingThreads = true;
            renderThreads();

            request('gms_list_message_threads', {
                channel: state.activeChannel,
                page: state.threadsPage,
                per_page: threadsPerPage,
                search: state.searchQuery
            }).then(function(data) {
                state.loadingThreads = false;
                state.threads = Array.isArray(data.items) ? data.items : [];
                state.threadsPage = data.page || 1;
                state.threadsTotalPages = data.total_pages || 1;
                channelCaches[state.activeChannel].initialized = true;

                if (!state.selectedThreadKey && state.threads.length) {
                    state.selectedThreadKey = state.threads[0].thread_key;
                    state.selectedThread = Object.assign({}, state.threads[0]);
                    fetchMessages({ maintainScroll: false });
                } else {
                    if (state.selectedThreadKey) {
                        var updated = state.threads.find(function(thread) {
                            return thread.thread_key === state.selectedThreadKey;
                        });
                        if (updated) {
                            state.selectedThread = Object.assign({}, state.selectedThread || {}, updated);
                        } else {
                            state.selectedThread = null;
                            state.selectedThreadKey = null;
                        }
                    } else {
                        state.selectedThread = null;
                        state.selectedThreadKey = null;
                    }
                    renderThreads();
                    renderThreadDetails();
                    renderMessages();
                }
                saveActiveChannelCache();
            }).catch(function(error) {
                state.loadingThreads = false;
                showStatus(error.message || strings.loadError, true);
                renderThreads();
            });
        }

        function fetchMessages(options) {
            if (!state.selectedThreadKey) {
                return;
            }
            state.loadingMessages = true;
            renderMessages(options);

            request('gms_fetch_thread_messages', {
                thread_key: state.selectedThreadKey,
                channel: state.activeChannel
            }).then(function(data) {
                state.loadingMessages = false;
                state.selectedThread = data && data.thread ? Object.assign({}, data.thread) : state.selectedThread;
                state.messages = Array.isArray(data.messages) ? data.messages : [];
                if (state.selectedThread) {
                    updateThreadCollection(state.selectedThread);
                }
                renderThreads();
                renderThreadDetails();
                renderMessages({ maintainScroll: true });
                saveActiveChannelCache();
            }).catch(function(error) {
                state.loadingMessages = false;
                showStatus(error.message || strings.messageLoadError || strings.loadError, true);
                renderMessages(options);
            });
        }

        function refreshThreadMessages() {
            if (!state.selectedThreadKey) {
                return;
            }
            request('gms_fetch_thread_messages', {
                thread_key: state.selectedThreadKey,
                channel: state.activeChannel
            }).then(function(data) {
                var previousCount = state.messages.length;
                state.selectedThread = data && data.thread ? Object.assign({}, data.thread) : state.selectedThread;
                state.messages = Array.isArray(data.messages) ? data.messages : [];
                if (state.selectedThread) {
                    updateThreadCollection(state.selectedThread);
                }
                if (data.messages && data.messages.length !== previousCount) {
                    renderMessages({ maintainScroll: false });
                }
                renderThreads();
                renderThreadDetails();
                saveActiveChannelCache();
            }).catch(function() {
                // Ignore polling errors
            });
        }

        function markThreadRead(showFeedback) {
            if (!state.selectedThreadKey) {
                return;
            }
            request('gms_mark_thread_read', {
                thread_key: state.selectedThreadKey,
                channel: state.activeChannel
            }).then(function() {
                state.threads = state.threads.map(function(thread) {
                    if (thread.thread_key === state.selectedThreadKey) {
                        var updated = Object.assign({}, thread);
                        updated.unread_count = 0;
                        return updated;
                    }
                    return thread;
                });
                if (state.selectedThread) {
                    state.selectedThread.unread_count = 0;
                }
                renderThreads();
                if (showFeedback) {
                    showStatus(strings.markRead || 'Marked as read', false);
                }
                saveActiveChannelCache();
            }).catch(function(error) {
                if (showFeedback) {
                    showStatus(error.message || strings.loadError, true);
                }
            });
        }

        function sendMessage(rawText) {
            if (!isSmsComposerActive() || !rawText || !rawText.trim()) {
                return;
            }
            if (state.sending) {
                return;
            }

            var trimmed = rawText.trim();
            state.sending = true;
            sendButton.disabled = true;
            showStatus(strings.sending || 'Sending…', false);

            var temporaryId = 'pending-' + Date.now();
            var pendingMessage = {
                id: temporaryId,
                message: trimmed,
                sent_at: new Date().toISOString(),
                direction: 'outbound',
                pending: true
            };
            state.pendingMessages.set(temporaryId, pendingMessage);
            renderMessages({ maintainScroll: true });

            request('gms_send_message_reply', {
                thread_key: state.selectedThreadKey,
                channel: state.activeChannel,
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
                }
                showStatus(strings.sendSuccess || 'Message sent', false);
                renderMessages({ maintainScroll: true });
                textarea.value = '';
                sendButton.disabled = true;
                saveActiveChannelCache();
            }).catch(function(error) {
                state.sending = false;
                state.pendingMessages.delete(temporaryId);
                state.messages = state.messages.filter(function(message) {
                    return message.id !== temporaryId;
                });
                renderMessages({ maintainScroll: true });
                showStatus(error.message || strings.sendFailed || strings.loadError, true);
                sendButton.disabled = false;
                saveActiveChannelCache();
            });
        }

        function renderTemplateOptions() {
            if (!templateSelect) {
                return;
            }
            templateSelect.innerHTML = '';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            var placeholderText = strings.templatePlaceholder || '';
            if (!state.selectedThread) {
                placeholderText = strings.templateUnavailable || placeholderText;
            } else if (state.templatesLoading) {
                placeholderText = strings.templateLoading || placeholderText;
            } else if (!state.templates.length) {
                placeholderText = state.templateSearchTerm ? (strings.templateEmptySearch || strings.templateEmpty || placeholderText) : (strings.templateEmpty || placeholderText);
            }
            placeholder.textContent = placeholderText;
            templateSelect.appendChild(placeholder);

            if (!state.selectedThread || state.templatesLoading || !state.templates.length) {
                templateSelect.disabled = true;
                return;
            }

            state.templates.forEach(function(template) {
                var option = document.createElement('option');
                option.value = template.content || '';
                option.textContent = template.label || template.content || '';
                templateSelect.appendChild(option);
            });

            templateSelect.disabled = false;
        }

        function fetchTemplates(options) {
            if (!state.selectedThread) {
                state.templates = [];
                state.templatesKey = '';
                state.templatesLoading = false;
                renderTemplateOptions();
                return;
            }

            var channel = (state.selectedThread.channel || 'sms').toLowerCase();
            var searchTerm = state.templateSearchTerm || '';
            var key = channel + '|' + searchTerm;
            if (!options || !options.force) {
                if (state.templatesKey === key && !state.templatesLoading) {
                    renderTemplateOptions();
                    return;
                }
            }

            state.templatesKey = key;
            state.templatesLoading = true;
            updateComposerVisibility();
            if (templateRow) {
                templateRow.classList.add('is-loading');
            }
            renderTemplateOptions();

            request('gms_list_message_templates', {
                channel: channel,
                search: searchTerm
            }).then(function(data) {
                state.templatesLoading = false;
                state.templates = Array.isArray(data.items) ? data.items : [];
                state.templatesKey = key;
                renderTemplateOptions();
                updateComposerVisibility();
                if (templateRow) {
                    templateRow.classList.remove('is-loading');
                }
                saveActiveChannelCache();
            }).catch(function(error) {
                state.templatesLoading = false;
                state.templates = [];
                state.templatesKey = '';
                renderTemplateOptions();
                updateComposerVisibility();
                if (templateRow) {
                    templateRow.classList.remove('is-loading');
                }
                if (options && options.silent) {
                    return;
                }
                showStatus(error.message || strings.templateLoadError || strings.loadError, true);
            });
        }

        function ensureTemplatesForChannel(channel, options) {
            var normalized = (channel || 'sms').toLowerCase();
            if (options && options.resetSearch) {
                state.templateSearchTerm = '';
                if (templateSearchInput) {
                    templateSearchInput.value = '';
                }
            }
            state.templateChannel = normalized;
            state.templates = [];
            state.templatesKey = '';
            state.templatesLoading = false;
            renderTemplateOptions();

            if (!isSmsComposerActive()) {
                return;
            }

            fetchTemplates({ force: true });
        }

        function handleTemplateSearchInput() {
            if (!templateSearchInput) {
                return;
            }
            if (templateSearchTimer) {
                window.clearTimeout(templateSearchTimer);
            }
            templateSearchTimer = window.setTimeout(function() {
                state.templateSearchTerm = templateSearchInput.value.trim();
                fetchTemplates({ force: true });
            }, 250);
        }

        function switchChannel(channel) {
            var key = (channel || '').toLowerCase();
            if (!key || key === state.activeChannel) {
                return;
            }
            saveActiveChannelCache();
            applyChannelState(key);
            if (!channelCaches[key].initialized) {
                channelCaches[key].initialized = true;
                fetchThreads(false);
            }
        }

        if (searchForm) {
            searchForm.addEventListener('submit', function(event) {
                event.preventDefault();
                state.searchQuery = searchInput ? searchInput.value.trim() : '';
                state.threadsPage = 1;
                fetchThreads(false);
            });
        }

        if (prevButton) {
            prevButton.addEventListener('click', function() {
                if (state.threadsPage <= 1) {
                    return;
                }
                state.threadsPage -= 1;
                fetchThreads(true);
            });
        }

        if (nextButton) {
            nextButton.addEventListener('click', function() {
                if (state.threadsPage >= state.threadsTotalPages) {
                    return;
                }
                state.threadsPage += 1;
                fetchThreads(true);
            });
        }

        if (markReadButton) {
            markReadButton.addEventListener('click', function() {
                markThreadRead(true);
            });
        }

        composerForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (!state.sending && isSmsComposerActive()) {
                sendMessage(textarea.value);
            }
        });

        if (templateSearchInput) {
            templateSearchInput.addEventListener('input', handleTemplateSearchInput);
            templateSearchInput.addEventListener('search', handleTemplateSearchInput);
        }

        if (templateSelect) {
            templateSelect.addEventListener('change', function() {
                var templateText = templateSelect.value;
                if (!templateText) {
                    return;
                }

                var existing = textarea.value || '';
                var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : null;
                var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : null;

                if (start === null || end === null) {
                    var prefix = existing && !/[\s\n]$/.test(existing) ? '\n\n' : '';
                    textarea.value = existing + prefix + templateText;
                } else {
                    var before = existing.slice(0, start);
                    var after = existing.slice(end);
                    var needsGapBefore = before && !/[\s\n]$/.test(before);
                    var needsGapAfter = after && !/^\s/.test(after);
                    var insertion = (needsGapBefore ? '\n\n' : '') + templateText + (needsGapAfter ? '\n\n' : '');
                    textarea.value = before + insertion + after;
                    if (typeof textarea.setSelectionRange === 'function') {
                        var cursorPosition = before.length + insertion.length;
                        textarea.setSelectionRange(cursorPosition, cursorPosition);
                    }
                }

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
        }

        textarea.addEventListener('input', function() {
            if (isSmsComposerActive() && textarea.value.trim().length) {
                sendButton.disabled = false;
            } else {
                sendButton.disabled = true;
            }
        });

        applyChannelState(state.activeChannel);
        if (!channelCaches[state.activeChannel].initialized) {
            channelCaches[state.activeChannel].initialized = true;
            fetchThreads(false);
        }

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
