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

        var channelOrder = Array.isArray(config.channels) && config.channels.length ? config.channels.slice() : ['sms', 'email', 'logs'];
        var channelLabels = config.channelLabels || {};

        var defaultChannel = channelOrder[0] || 'sms';
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
            templateChannel: '',
            logsItems: [],
            logsPage: 1,
            logsTotalPages: 1,
            selectedLogId: null,
            selectedLog: null,
        };

        var channelCaches = {};
        channelOrder.forEach(function(channel) {
            var key = (channel || '').toLowerCase();
            if (!key) {
                return;
            }

            if (key === 'logs') {
                channelCaches[key] = {
                    items: [],
                    page: 1,
                    totalPages: 1,
                    selectedLogId: null,
                    selectedLog: null,
                    searchQuery: '',
                    loaded: false,
                    loading: false,
                };
            } else {
                channelCaches[key] = {
                    threads: [],
                    page: 1,
                    totalPages: 1,
                    selectedThreadKey: null,
                    selectedThread: null,
                    messages: [],
                    searchQuery: '',
                    loaded: false,
                    loadingThreads: false,
                    loadingMessages: false,
                    initialized: false,
                };
            }
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
                    loaded: false,
                    loadingThreads: false,
                    loadingMessages: false,
                    initialized: false,
                };
            }
        }

        var layout = document.createElement('div');
        layout.className = 'gms-messaging';

        var channelNav = document.createElement('div');
        channelNav.className = 'gms-messaging__channels';
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

        channelOrder.forEach(function(channel) {
            var key = (channel || '').toLowerCase();
            if (!key) {
                return;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-secondary gms-messaging__channel';
            button.textContent = getChannelLabel(key);
            button.dataset.channel = key;
            button.addEventListener('click', function() {
                switchChannel(key);
            });

            channelButtons.set(key, button);
            channelNav.appendChild(button);
        });

        layout.appendChild(channelNav);

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
        templateRow.className = 'gms-messaging__composer-row gms-messaging__template-row';

        var templateSearchInput = document.createElement('input');
        templateSearchInput.type = 'search';
        templateSearchInput.className = 'gms-messaging__template-search';
        templateSearchInput.placeholder = strings.templateSearchPlaceholder || '';
        templateSearchInput.setAttribute('aria-label', strings.templateSearchPlaceholder || '');
        templateRow.appendChild(templateSearchInput);

        var templateSelect = document.createElement('select');
        templateSelect.className = 'gms-messaging__template';
        templateSelect.setAttribute('aria-label', strings.templatePlaceholder || 'Templates');
        templateSelect.disabled = true;

        var templatePlaceholder = document.createElement('option');
        templatePlaceholder.value = '';
        templatePlaceholder.textContent = strings.templatePlaceholder || '';
        templateSelect.appendChild(templatePlaceholder);

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

        function updateChannelButtons() {
            channelButtons.forEach(function(button, key) {
                if (!button) {
                    return;
                }
                button.classList.toggle('is-active', key === state.activeChannel);
            });
        }

        function updateSearchControls() {
            if (!searchInput || !searchButton) {
                return;
            }

            var placeholder = strings.searchPlaceholder || '';
            var actionText = strings.searchAction || 'Search';

            if (state.activeChannel === 'logs') {
                placeholder = strings.logsSearchPlaceholder || placeholder;
                actionText = strings.logsSearchAction || actionText;
            }

            searchInput.placeholder = placeholder;
            searchInput.setAttribute('aria-label', placeholder);
            searchButton.textContent = actionText;
        }

        function saveActiveChannelCache() {
            var key = (state.activeChannel || '').toLowerCase();
            var cache = channelCaches[key];
            if (!cache) {
                return;
            }

            if (key === 'logs') {
                cache.items = state.logsItems.slice();
                cache.page = state.logsPage;
                cache.totalPages = state.logsTotalPages;
                cache.selectedLogId = state.selectedLogId;
                cache.selectedLog = state.selectedLog ? Object.assign({}, state.selectedLog) : null;
                cache.searchQuery = state.searchQuery;
                cache.loading = state.loadingThreads;
                cache.loaded = true;
            } else {
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
                cache.loaded = true;
            }
        }

        function applyChannelState(channel) {
            var key = (channel || '').toLowerCase();
            var cache = channelCaches[key];
            if (!cache) {
                return;
            }

            state.activeChannel = key;

            if (key === 'logs') {
                state.logsItems = (cache.items || []).slice();
                state.logsPage = cache.page || 1;
                state.logsTotalPages = cache.totalPages || 1;
                state.selectedLogId = cache.selectedLogId || null;
                state.selectedLog = cache.selectedLog ? Object.assign({}, cache.selectedLog) : null;
                state.threads = [];
                state.threadsPage = 1;
                state.threadsTotalPages = 1;
                state.selectedThreadKey = null;
                state.selectedThread = null;
                state.messages = [];
                state.loadingMessages = false;
                state.loadingThreads = !!cache.loading;
                state.initialized = true;
                state.searchQuery = cache.searchQuery || '';
                state.templates = [];
                state.templatesKey = '';
                state.templatesLoading = false;
                state.templateChannel = '';
                state.templateSearchTerm = '';
                if (state.pendingMessages && typeof state.pendingMessages.clear === 'function') {
                    state.pendingMessages.clear();
                }
            } else {
                state.threads = (cache.threads || []).slice();
                state.threadsPage = cache.page || 1;
                state.threadsTotalPages = cache.totalPages || 1;
                state.selectedThreadKey = cache.selectedThreadKey || null;
                state.selectedThread = cache.selectedThread ? Object.assign({}, cache.selectedThread) : null;
                state.messages = (cache.messages || []).slice();
                state.logsItems = [];
                state.logsPage = 1;
                state.logsTotalPages = 1;
                state.selectedLogId = null;
                state.selectedLog = null;
                state.loadingMessages = !!cache.loadingMessages;
                state.loadingThreads = !!cache.loadingThreads;
                state.initialized = !!cache.initialized;
                state.searchQuery = cache.searchQuery || '';
            }

            if (searchInput) {
                searchInput.value = state.searchQuery;
            }

            updateSearchControls();
            updateChannelButtons();
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
                composerForm.hidden = state.activeChannel === 'logs';
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
                sendButton.disabled = true;
                sendButton.classList.toggle('is-disabled', !smsActive);
            }

            if (templateRow) {
                templateRow.style.display = smsActive ? '' : 'none';
            }

            if (templateSearchInput) {
                templateSearchInput.disabled = !smsActive || state.templatesLoading;
            }

            if (templateSelect) {
                templateSelect.disabled = !smsActive || state.templatesLoading || !state.templates.length;
            }
        }

        function switchChannel(channel) {
            var key = (channel || '').toLowerCase();
            if (!key || key === state.activeChannel) {
                return;
            }

            saveActiveChannelCache();
            applyChannelState(key);
            updateComposerVisibility();

            if (key === 'logs') {
                if (!channelCaches[key].loaded) {
                    fetchLogs(false);
                } else {
                    renderThreads();
                    renderThreadDetails();
                    renderMessages();
                }
            } else {
                if (!channelCaches[key].loaded) {
                    fetchThreads(false);
                } else {
                    renderThreads();
                    renderThreadDetails();
                    renderMessages();
                }
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

        var templateSearchTimer = null;

        function renderTemplateOptions() {
            if (!templateSelect) {
                return;
            }

            if (!isSmsComposerActive()) {
                templateRow.classList.remove('is-loading');
                templateSelect.disabled = true;
                templateSelect.value = '';
                templateSelect.selectedIndex = 0;
                templatePlaceholder.textContent = strings.templateUnavailable || strings.templatePlaceholder || '';
                return;
            }

            while (templateSelect.options.length > 1) {
                templateSelect.remove(1);
            }

            var hasThread = !!state.selectedThread;
            templateRow.classList.toggle('is-loading', state.templatesLoading);

            if (templateSearchInput) {
                templateSearchInput.disabled = !hasThread || state.templatesLoading;
                if (templateSearchInput.value !== state.templateSearchTerm) {
                    templateSearchInput.value = state.templateSearchTerm;
                }
            }

            templateSelect.disabled = true;
            templateSelect.value = '';
            templateSelect.selectedIndex = 0;

            if (!hasThread) {
                templatePlaceholder.textContent = strings.templateUnavailable || strings.templatePlaceholder || '';
                return;
            }

            if (state.templatesLoading) {
                templatePlaceholder.textContent = strings.templateLoading || strings.templatePlaceholder || '';
                return;
            }

            if (!state.templates.length) {
                if (state.templateSearchTerm) {
                    templatePlaceholder.textContent = strings.templateEmptySearch || strings.templateEmpty || strings.templatePlaceholder || '';
                } else {
                    templatePlaceholder.textContent = strings.templateEmpty || strings.templatePlaceholder || '';
                }
                return;
            }

            templatePlaceholder.textContent = strings.templatePlaceholder || '';

            state.templates.forEach(function(template) {
                if (!template || !template.content) {
                    return;
                }
                var option = document.createElement('option');
                option.value = template.content;
                option.textContent = template.label || template.id || '';
                option.setAttribute('data-channel', template.channel || '');
                templateSelect.appendChild(option);
            });

            templateSelect.disabled = false;
        }

        function handleTemplateSearchInput() {
            if (!templateSearchInput) {
                return;
            }

            state.templateSearchTerm = templateSearchInput.value.trim();

            if (!isSmsComposerActive()) {
                renderTemplateOptions();
                return;
            }

            if (!state.selectedThread) {
                renderTemplateOptions();
                return;
            }

            if (templateSearchTimer) {
                window.clearTimeout(templateSearchTimer);
            }

            templateSearchTimer = window.setTimeout(function() {
                var channel = state.templateChannel || (state.selectedThread && state.selectedThread.channel) || 'sms';
                fetchTemplates({
                    channel: channel,
                    searchTerm: state.templateSearchTerm
                });
            }, 250);
        }

        function fetchTemplates(options) {
            if (!state.selectedThread) {
                state.templates = [];
                state.templatesKey = '';
                state.templatesLoading = false;
                renderTemplateOptions();
                return Promise.resolve(null);
            }

            var desiredChannel = (options && options.channel) || state.templateChannel || ((state.selectedThread && state.selectedThread.channel) || 'sms');
            var searchTerm = (options && options.searchTerm !== undefined) ? options.searchTerm : state.templateSearchTerm || '';
            var key = desiredChannel + '|' + searchTerm;

            state.templateChannel = desiredChannel;
            state.templateSearchTerm = searchTerm;
            state.templatesLoading = true;
            renderTemplateOptions();

            return request('gms_list_message_templates', {
                channel: desiredChannel,
                search: searchTerm,
                page: 1,
                per_page: 100
            }).then(function(data) {
                state.templatesLoading = false;
                state.templatesKey = key;
                state.templates = (data && Array.isArray(data.items)) ? data.items : [];
                renderTemplateOptions();
                return data;
            }).catch(function(error) {
                state.templatesLoading = false;
                state.templatesKey = '';
                state.templates = [];
                renderTemplateOptions();
                if (error && error.message) {
                    showStatus(error.message, true);
                } else if (strings.templateLoadError) {
                    showStatus(strings.templateLoadError, true);
                }
                return null;
            });
        }

        function ensureTemplatesForChannel(channel, options) {
            var normalized = (channel || 'sms').toLowerCase();
            var resetSearch = options && options.resetSearch;

            if (!isSmsComposerActive()) {
                state.templates = [];
                state.templatesKey = '';
                state.templatesLoading = false;
                renderTemplateOptions();
                return;
            }

            if (resetSearch) {
                state.templateSearchTerm = '';
            }

            state.templateChannel = normalized;

            if (!state.selectedThread) {
                state.templates = [];
                state.templatesKey = '';
                state.templatesLoading = false;
                renderTemplateOptions();
                return;
            }

            var key = normalized + '|' + (state.templateSearchTerm || '');

            if (options && options.force) {
                fetchTemplates({ channel: normalized, searchTerm: state.templateSearchTerm || '' });
                return;
            }

            if (state.templatesKey !== key) {
                fetchTemplates({ channel: normalized, searchTerm: state.templateSearchTerm || '' });
            } else {
                renderTemplateOptions();
            }
        }

        applyChannelState(state.activeChannel);
        updateComposerVisibility();
        renderTemplateOptions();

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

            if (state.activeChannel === 'logs') {
                if (state.loadingThreads) {
                    var logLoading = document.createElement('div');
                    logLoading.className = 'gms-messaging__empty';
                    logLoading.textContent = strings.loading || 'Loading…';
                    threadsList.appendChild(logLoading);
                } else if (!state.logsItems.length) {
                    var logEmpty = document.createElement('div');
                    logEmpty.className = 'gms-messaging__empty';
                    logEmpty.textContent = strings.logsEmpty || strings.noConversations || '';
                    threadsList.appendChild(logEmpty);
                } else {
                    state.logsItems.forEach(function(entry) {
                        if (!entry) {
                            return;
                        }

                        var logButton = document.createElement('button');
                        logButton.type = 'button';
                        logButton.className = 'gms-thread';
                        logButton.dataset.logId = String(entry.id);
                        logButton.setAttribute('role', 'listitem');

                        if (Number(entry.id) === Number(state.selectedLogId)) {
                            logButton.classList.add('is-active');
                        }

                        var logTitle = document.createElement('div');
                        logTitle.className = 'gms-thread__title';
                        logTitle.textContent = entry.subject || entry.message || getChannelLabel(entry.channel || 'logs');
                        logButton.appendChild(logTitle);

                        var subtitleParts = [];
                        if (entry.channel) {
                            subtitleParts.push(getChannelLabel(entry.channel));
                        }
                        if (entry.property_name) {
                            subtitleParts.push(entry.property_name);
                        }

                        if (subtitleParts.length) {
                            var logSubtitle = document.createElement('div');
                            logSubtitle.className = 'gms-thread__subtitle';
                            logSubtitle.textContent = subtitleParts.join(' • ');
                            logButton.appendChild(logSubtitle);
                        }

                        if (entry.sent_at) {
                            var logStamp = document.createElement('time');
                            logStamp.className = 'gms-thread__timestamp';
                            logStamp.dateTime = entry.sent_at;
                            logStamp.textContent = formatTimestamp(entry.sent_at);
                            logButton.appendChild(logStamp);
                        }

                        logButton.addEventListener('click', function() {
                            selectLog(entry.id);
                        });

                        threadsList.appendChild(logButton);
                    });
                }

                var logPage = state.logsPage;
                var logTotalPages = state.logsTotalPages;
                paginationStatus.textContent = strings.pagination ? strings.pagination.replace('%1$d', logPage).replace('%2$d', logTotalPages) : logPage + ' / ' + logTotalPages;
                prevButton.disabled = logPage <= 1 || state.loadingThreads;
                nextButton.disabled = logPage >= logTotalPages || state.loadingThreads;
                return;
            }

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
                paginationStatus.textContent = strings.pagination ? strings.pagination.replace('%1$d', state.threadsPage).replace('%2$d', state.threadsTotalPages) : state.threadsPage + ' / ' + state.threadsTotalPages;
                prevButton.disabled = state.threadsPage <= 1 || state.loadingThreads;
                nextButton.disabled = state.threadsPage >= state.threadsTotalPages || state.loadingThreads;
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

        function selectLog(logId) {
            if (!state.logsItems || !state.logsItems.length) {
                return;
            }

            var numericId = parseInt(logId, 10);
            if (!Number.isFinite(numericId)) {
                numericId = Number(logId);
            }

            var chosen = null;
            state.logsItems.forEach(function(entry) {
                if (entry && Number(entry.id) === Number(numericId)) {
                    chosen = entry;
                }
            });

            if (!chosen) {
                return;
            }

            state.selectedLogId = Number(chosen.id);
            state.selectedLog = Object.assign({}, chosen);
            saveActiveChannelCache();
            renderThreads();
            renderThreadDetails();
            renderMessages();
        }

        function renderThreadDetails() {
            if (state.activeChannel === 'logs') {
                var logEntry = state.selectedLog;
                if (!logEntry) {
                    threadTitle.textContent = strings.logsHeading || getChannelLabel('logs');
                    threadSubtitle.textContent = '';
                    threadMeta.innerHTML = '';
                    markReadButton.disabled = true;
                    updateComposerVisibility();
                    return;
                }

                threadTitle.textContent = logEntry.subject || getChannelLabel(logEntry.channel || 'logs');
                threadSubtitle.textContent = logEntry.property_name || '';

                var logMetaItems = [];
                if (logEntry.channel) {
                    logMetaItems.push({ label: strings.logChannelLabel || 'Channel', value: getChannelLabel(logEntry.channel) });
                }
                if (logEntry.delivery_status) {
                    logMetaItems.push({ label: strings.logStatusLabel || 'Status', value: logEntry.delivery_status });
                }
                if (logEntry.guest_email) {
                    logMetaItems.push({ label: strings.guestEmail || 'Email', value: logEntry.guest_email });
                }
                if (logEntry.guest_phone) {
                    logMetaItems.push({ label: strings.guestPhone || 'Phone', value: logEntry.guest_phone });
                }
                if (logEntry.booking_reference) {
                    logMetaItems.push({ label: strings.bookingReference || 'Reference', value: logEntry.booking_reference });
                }

                threadMeta.innerHTML = '';
                if (logMetaItems.length) {
                    var logList = document.createElement('ul');
                    logList.className = 'gms-messaging__meta-list';
                    logMetaItems.forEach(function(meta) {
                        var logItem = document.createElement('li');
                        logItem.className = 'gms-messaging__meta-item';

                        var logLabel = document.createElement('span');
                        logLabel.className = 'gms-messaging__meta-label';
                        logLabel.textContent = meta.label + ':';

                        var logValue = document.createElement('span');
                        logValue.className = 'gms-messaging__meta-value';
                        logValue.textContent = meta.value;

                        logItem.appendChild(logLabel);
                        logItem.appendChild(logValue);
                        logList.appendChild(logItem);
                    });
                    threadMeta.appendChild(logList);
                }

                markReadButton.disabled = true;
                updateComposerVisibility();
                return;
            }

            var thread = state.selectedThread;
            if (!thread) {
                threadTitle.textContent = strings.conversationHeading || '';
                threadSubtitle.textContent = '';
                threadMeta.innerHTML = '';
                markReadButton.disabled = true;
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

            markReadButton.disabled = false;
            updateComposerVisibility();
            sendButton.disabled = !isSmsComposerActive() || !textarea.value.trim().length;
        }

        function renderMessages(options) {
            var maintainScroll = options && options.maintainScroll ? options.maintainScroll : false;
            var shouldStickToBottom = maintainScroll || isNearBottom(messagesList);

            if (state.activeChannel === 'logs') {
                messagesList.innerHTML = '';

                var logEntry = state.selectedLog;
                if (!logEntry) {
                    var logPrompt = document.createElement('div');
                    logPrompt.className = 'gms-messaging__messages-empty';
                    logPrompt.textContent = strings.logsHeading || strings.conversationHeading || '';
                    messagesList.appendChild(logPrompt);
                    return;
                }

                var logArticle = document.createElement('article');
                logArticle.className = 'gms-log-entry-detail';

                var logMeta = document.createElement('header');
                logMeta.className = 'gms-log-entry-detail__meta';

                var logChannel = document.createElement('span');
                logChannel.className = 'gms-log-entry-detail__channel';
                logChannel.textContent = getChannelLabel(logEntry.channel || 'logs');
                logMeta.appendChild(logChannel);

                if (logEntry.sent_at) {
                    var logTime = document.createElement('time');
                    logTime.className = 'gms-log-entry-detail__time';
                    logTime.dateTime = logEntry.sent_at;
                    logTime.textContent = formatTimestamp(logEntry.sent_at);
                    logMeta.appendChild(logTime);
                }

                if (logEntry.delivery_status) {
                    var logStatus = document.createElement('span');
                    logStatus.className = 'gms-log-entry-detail__status';
                    logStatus.textContent = logEntry.delivery_status;
                    logMeta.appendChild(logStatus);
                }

                logArticle.appendChild(logMeta);

                if (logEntry.message) {
                    var logMessage = document.createElement('p');
                    logMessage.className = 'gms-log-entry-detail__message';
                    logMessage.textContent = logEntry.message;
                    logArticle.appendChild(logMessage);
                }

                if (logEntry.response_data && typeof logEntry.response_data === 'object') {
                    var responsePre = document.createElement('pre');
                    responsePre.className = 'gms-log-entry-detail__response';
                    try {
                        responsePre.textContent = JSON.stringify(logEntry.response_data, null, 2);
                    } catch (err) {
                        responsePre.textContent = String(logEntry.response_data);
                    }
                    logArticle.appendChild(responsePre);
                }

                messagesList.appendChild(logArticle);
                return;
            }

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

            if (state.activeChannel === 'logs') {
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

            saveActiveChannelCache();
        }

        function fetchThreads(preservePage) {
            if (state.activeChannel === 'logs') {
                fetchLogs(preservePage);
                return;
            }

            if (state.loadingThreads) {
                return;
            }

            state.loadingThreads = true;
            renderThreads();

            var page = preservePage ? state.threadsPage : 1;

            request('gms_list_message_threads', {
                page: page,
                per_page: threadsPerPage,
                search: state.searchQuery,
                channel: state.activeChannel
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
                saveActiveChannelCache();
            }).catch(function(error) {
                state.loadingThreads = false;
                showStatus(error.message || strings.loadError, true);
                renderThreads();
            });
        }

        function fetchLogs(preservePage) {
            if (state.activeChannel !== 'logs') {
                return;
            }

            if (state.loadingThreads) {
                return;
            }

            state.loadingThreads = true;
            renderThreads();

            var page = preservePage ? state.logsPage : 1;

            request('gms_list_operational_logs', {
                page: page,
                per_page: threadsPerPage,
                search: state.searchQuery
            }).then(function(data) {
                state.loadingThreads = false;
                state.logsItems = Array.isArray(data.items) ? data.items : [];
                state.logsPage = data.page || 1;
                state.logsTotalPages = data.total_pages || 1;

                if (state.logsItems.length) {
                    var match = null;
                    if (state.selectedLogId) {
                        state.logsItems.forEach(function(entry) {
                            if (entry && Number(entry.id) === Number(state.selectedLogId)) {
                                match = entry;
                            }
                        });
                    }

                    if (!match) {
                        match = state.logsItems[0];
                    }

                    state.selectedLogId = match ? Number(match.id) : null;
                    state.selectedLog = match ? Object.assign({}, match) : null;
                } else {
                    state.selectedLogId = null;
                    state.selectedLog = null;
                }

                renderThreads();
                renderThreadDetails();
                renderMessages();
                saveActiveChannelCache();
            }).catch(function(error) {
                state.loadingThreads = false;
                showStatus(error.message || strings.logsLoadError || strings.loadError, true);
                renderThreads();
            });
        }

        function loadThread(threadKey) {
            if (!threadKey) {
                return;
            }

            if (state.activeChannel === 'logs') {
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
                saveActiveChannelCache();
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

            if (state.activeChannel === 'logs') {
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

            if (!isSmsComposerActive()) {
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
                saveActiveChannelCache();
            }).catch(function(error) {
                state.sending = false;
                state.pendingMessages.delete(temporaryId);
                state.messages = state.messages.filter(function(message) {
                    return message.id !== temporaryId;
                });
                renderMessages({ maintainScroll: true });
                showStatus(error.message || strings.sendFailed, true);
                sendButton.disabled = false;
                saveActiveChannelCache();
            });
        }

        searchForm.addEventListener('submit', function(event) {
            event.preventDefault();
            state.searchQuery = searchInput.value.trim();
            if (state.activeChannel === 'logs') {
                state.logsPage = 1;
                fetchLogs(false);
            } else {
                state.threadsPage = 1;
                fetchThreads(false);
            }
        });

        prevButton.addEventListener('click', function() {
            if (state.activeChannel === 'logs') {
                if (state.logsPage <= 1) {
                    return;
                }
                state.logsPage -= 1;
                fetchLogs(true);
                return;
            }

            if (state.threadsPage <= 1) {
                return;
            }
            state.threadsPage -= 1;
            fetchThreads(true);
        });

        nextButton.addEventListener('click', function() {
            if (state.activeChannel === 'logs') {
                if (state.logsPage >= state.logsTotalPages) {
                    return;
                }
                state.logsPage += 1;
                fetchLogs(true);
                return;
            }

            if (state.threadsPage >= state.threadsTotalPages) {
                return;
            }
            state.threadsPage += 1;
            fetchThreads(true);
        });

        markReadButton.addEventListener('click', function() {
            if (state.activeChannel === 'logs') {
                return;
            }
            markThreadRead(false);
        });

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

        textarea.addEventListener('input', function() {
            if (isSmsComposerActive() && textarea.value.trim().length) {
                sendButton.disabled = false;
            } else {
                sendButton.disabled = true;
            }
        });

        if (state.activeChannel === 'logs') {
            fetchLogs(false);
        } else {
            fetchThreads(false);
        }

        if (pollInterval > 0) {
            state.pollTimer = window.setInterval(function() {
                if (state.activeChannel === 'logs') {
                    fetchLogs(true);
                } else {
                    fetchThreads(true);
                    refreshThreadMessages();
                }
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
