(() => {
    'use strict';

    const state = {
        busy: false,
        pollTimer: null,
        pollIntervalMs: 3000,
        audioSuppressUntil: 0,
        lastRequestedUiMode: '',
        preferredAslUiMode: 'ASL',
        favoriteSortKey: '',
        favoriteSortDirection: 'asc',
        favoriteSortType: 'text',
        favoritesRaw: [],
        audioAlertsEnabled: true,
        audioStateInitialized: false,
        previousConnectedNodes: [],
        muteAudioAnnouncements: false,
        endpoints: {
            status: '/alltune2/api/status.php',
            connect: '/alltune2/api/connect.php',
        },
    };

    const els = {
        controlForm: document.getElementById('control-form'),
        targetInput: document.getElementById('target'),
        modeSelect: document.getElementById('mode'),
        autoloadCheckbox: document.getElementById('autoload_dvswitch'),
        autoloadModeSelect: document.getElementById('autoload_dvswitch_mode'),
        disconnectBeforeConnectCheckbox: document.getElementById('disconnect_before_connect'),
        audioAlertsCheckbox: document.getElementById('audio_alerts'),
        connectButton: document.getElementById('connect-button'),
        disconnectButton: document.getElementById('disconnect-button'),
        disconnectAllButton: document.getElementById('disconnect-all-button'),
        disconnectDvSwitchButton: document.getElementById('disconnect-dvswitch-button'),
        helperText: document.getElementById('helper-text'),
        systemStatus: document.getElementById('system-status'),
        favoritesBody: document.getElementById('favorites-body'),
        statusBm: document.getElementById('status-bm'),
        statusTgif: document.getElementById('status-tgif'),
        statusYsf: document.getElementById('status-ysf'),
        statusAllstar: document.getElementById('status-allstar'),
        statusAllstarLinks: document.getElementById('status-allstar-links'),
        brandingTitle: document.getElementById('branding-title'),
        updateIndicator: document.getElementById('update-indicator'),
    };

    function hasCoreElements() {
        return !!(
            els.targetInput &&
            els.modeSelect &&
            els.autoloadCheckbox &&
            els.autoloadModeSelect &&
            els.disconnectBeforeConnectCheckbox &&
            els.connectButton &&
            els.disconnectButton &&
            els.disconnectAllButton &&
            els.disconnectDvSwitchButton &&
            els.systemStatus
        );
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function parseVersionString(value) {
        const match = String(value || '').trim().match(/^(\d+)\.(\d+)\.(\d+)$/);

        if (!match) {
            return null;
        }

        return [
            Number(match[1]),
            Number(match[2]),
            Number(match[3]),
        ];
    }

    function compareVersions(left, right) {
        const leftParts = parseVersionString(left);
        const rightParts = parseVersionString(right);

        if (!leftParts || !rightParts) {
            return 0;
        }

        for (let index = 0; index < leftParts.length; index += 1) {
            if (leftParts[index] > rightParts[index]) {
                return 1;
            }

            if (leftParts[index] < rightParts[index]) {
                return -1;
            }
        }

        return 0;
    }

    async function checkForRepoUpdate() {
        const title = els.brandingTitle;
        const indicator = els.updateIndicator;

        if (!title || !indicator) {
            return;
        }

        const localVersion = String(title.dataset.localVersion || '').trim();
        const versionUrl = String(title.dataset.versionUrl || '').trim();

        if (localVersion !== '') {
            title.title = `AllTune2 v${localVersion}`;
            indicator.title = `Installed version: v${localVersion}`;
        }

        if (localVersion === '' || versionUrl === '') {
            return;
        }

        try {
            const response = await fetch(versionUrl, {
                method: 'GET',
                cache: 'no-store',
            });

            if (!response.ok) {
                return;
            }

            const remoteVersion = String(await response.text()).trim();

            if (compareVersions(remoteVersion, localVersion) > 0) {
                indicator.classList.add('update-available');
                title.title = `AllTune2 v${localVersion} - update available: v${remoteVersion}`;
                indicator.title = `Update available: v${remoteVersion} (installed v${localVersion})`;
            }
        } catch (error) {
            // Fail quietly if GitHub cannot be reached.
        }
    }

    const AUDIO_ALERTS_STORAGE_KEY = 'alltune2_audio_alerts_enabled';

    function formatNodeForSpeech(node) {
        return String(node || '').trim().split('').join(' ');
    }

    function persistAudioAlertsPreference(enabled) {
        try {
            window.localStorage.setItem(AUDIO_ALERTS_STORAGE_KEY, enabled ? '1' : '0');
        } catch (error) {
            // Ignore storage issues and keep the current in-memory preference.
        }
    }

    function cancelSpeechQueue() {
        if (!('speechSynthesis' in window)) {
            return;
        }

        try {
            window.speechSynthesis.cancel();
        } catch (error) {
            // Ignore browser speech errors.
        }
    }


    function suppressAudioDuringTransitions(milliseconds = 1000) {
        state.audioSuppressUntil = Date.now() + milliseconds;
        cancelSpeechQueue();
    }

    function loadAudioAlertsPreference() {
        let enabled = true;

        try {
            const stored = window.localStorage.getItem(AUDIO_ALERTS_STORAGE_KEY);
            if (stored === '0') {
                enabled = false;
            } else if (stored === '1') {
                enabled = true;
            }
        } catch (error) {
            // Ignore storage issues and keep alerts enabled by default.
        }

        state.audioAlertsEnabled = enabled;

        if (els.audioAlertsCheckbox) {
            els.audioAlertsCheckbox.checked = enabled;
        }

        if ('speechSynthesis' in window) {
            try {
                window.speechSynthesis.getVoices();
            } catch (error) {
                // Ignore voice enumeration errors.
            }
        }
    }

    function speakAudioAlert(text) {
        if (!state.audioAlertsEnabled || state.muteAudioAnnouncements) {
            return;
        }

        if (!('speechSynthesis' in window)) {
            return;
        }

        try {
            window.speechSynthesis.cancel();
        } catch (error) {
            // Ignore browser speech errors.
        }

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.25;
        utterance.pitch = 1.0;

        try {
            const voices = window.speechSynthesis.getVoices();
            const ziraVoice = voices.find((voice) =>
                String(voice.name || '').toLowerCase().includes('zira')
            );

            if (ziraVoice) {
                utterance.voice = ziraVoice;
            }

            window.speechSynthesis.speak(utterance);
        } catch (error) {
            // Ignore browser speech errors.
        }
    }

    function connectedNodeListFromPayload(allstarPayload) {
        const connected = Array.isArray(allstarPayload?.connected_nodes)
            ? allstarPayload.connected_nodes
            : [];

        const seen = new Set();
        const nodes = [];

        connected.forEach((item) => {
            const node = String(item?.node ?? item?.target ?? '').trim();
            if (node === '' || seen.has(node)) {
                return;
            }

            seen.add(node);
            nodes.push(node);
        });

        return nodes;
    }

    function syncAudioAlertsFromAllstar(allstarPayload) {
        const currentNodes = connectedNodeListFromPayload(allstarPayload);

        if (!state.audioStateInitialized) {
            state.previousConnectedNodes = currentNodes.slice();
            state.audioStateInitialized = true;

            if (state.muteAudioAnnouncements && currentNodes.length === 0) {
                state.muteAudioAnnouncements = false;
            }

            return;
        }

        if (state.muteAudioAnnouncements) {
            state.previousConnectedNodes = currentNodes.slice();

            if (currentNodes.length === 0) {
                state.muteAudioAnnouncements = false;
                cancelSpeechQueue();
            }

            return;
        }

        if (Date.now() < state.audioSuppressUntil) {
            return;
        }

        const addedNodes = currentNodes.filter((node) => !state.previousConnectedNodes.includes(node));
        const removedNodes = state.previousConnectedNodes.filter((node) => !currentNodes.includes(node));

        addedNodes.forEach((node) => {
            speakAudioAlert(`Node ${formatNodeForSpeech(node)} has connected`);
        });

        removedNodes.forEach((node) => {
            speakAudioAlert(`Node ${formatNodeForSpeech(node)} has disconnected`);
        });

        state.previousConnectedNodes = currentNodes.slice();
    }

    function normalizeMode(mode) {
        const value = String(mode || '').trim().toUpperCase();

        if ([
            'ALLSTAR',
            'ALLSTAR LINK',
            'ALLSTARLINK',
        ].includes(value)) {
            return 'ASL';
        }

        if ([
            'ECHO',
            'ECHO LINK',
            'ECHOLINK',
            'EL',
            'E/L',
        ].includes(value)) {
            return 'ECHO';
        }

        return value;
    }

    function modeRequestValue(mode) {
        const normalized = normalizeMode(mode);
        return normalized === 'ECHO' ? 'ASL' : normalized;
    }

    function modeConfigKey(mode) {
        const normalized = normalizeMode(mode);
        return normalized === 'ECHO' ? 'ECHO' : normalized;
    }

    function favoriteModeLabel(mode) {
        const normalized = normalizeMode(mode);

        if (normalized === 'ASL') {
            return 'ASL';
        }

        if (normalized === 'ECHO') {
            return 'E/L';
        }

        return normalized;
    }

    function favoriteFieldValue(item, key) {
        if (key === 'target') {
            return String(item.target ?? item.tg ?? '').trim();
        }

        if (key === 'name') {
            return String(item.name ?? '').trim();
        }

        if (key === 'description') {
            return String(item.description ?? item.desc ?? '-').trim();
        }

        if (key === 'mode') {
            return favoriteModeLabel(item.mode ?? 'BM');
        }

        return '';
    }

    function compareFavoriteValues(left, right, type, direction) {
        const leftText = String(left ?? '').trim();
        const rightText = String(right ?? '').trim();

        if (type === 'mixed') {
            const leftIsNumber = /^[0-9]+$/.test(leftText);
            const rightIsNumber = /^[0-9]+$/.test(rightText);

            if (leftIsNumber && rightIsNumber) {
                return direction === 'desc'
                    ? Number(rightText) - Number(leftText)
                    : Number(leftText) - Number(rightText);
            }
        }

        const collator = new Intl.Collator(undefined, {
            numeric: true,
            sensitivity: 'base',
        });

        return direction === 'desc'
            ? collator.compare(rightText, leftText)
            : collator.compare(leftText, rightText);
    }

    function compareFavoriteModes(left, right, direction) {
        const order = {
            ASL: 1,
            BM: 2,
            'E/L': 3,
            TGIF: 4,
            YSF: 5,
        };

        const multiplier = direction === 'desc' ? -1 : 1;
        const leftMode = favoriteModeLabel(left);
        const rightMode = favoriteModeLabel(right);
        const leftRank = order[leftMode] ?? 999;
        const rightRank = order[rightMode] ?? 999;

        if (leftRank !== rightRank) {
            return (leftRank - rightRank) * multiplier;
        }

        return 0;
    }

    function getSortedFavorites(items) {
        if (!Array.isArray(items)) {
            return [];
        }

        if (state.favoriteSortKey === '') {
            return items.slice();
        }

        return items.slice().sort((leftItem, rightItem) => {
            let primaryCompare = 0;

            if (state.favoriteSortKey === 'mode') {
                primaryCompare = compareFavoriteModes(
                    leftItem.mode ?? 'BM',
                    rightItem.mode ?? 'BM',
                    state.favoriteSortDirection
                );
            } else {
                const leftValue = favoriteFieldValue(leftItem, state.favoriteSortKey);
                const rightValue = favoriteFieldValue(rightItem, state.favoriteSortKey);

                primaryCompare = compareFavoriteValues(
                    leftValue,
                    rightValue,
                    state.favoriteSortType,
                    state.favoriteSortDirection
                );
            }

            if (primaryCompare !== 0) {
                return primaryCompare;
            }

            if (state.favoriteSortKey !== 'target') {
                const secondaryCompare = compareFavoriteValues(
                    favoriteFieldValue(leftItem, 'target'),
                    favoriteFieldValue(rightItem, 'target'),
                    'mixed',
                    'asc'
                );

                if (secondaryCompare !== 0) {
                    return secondaryCompare;
                }
            }

            const tertiaryCompare = compareFavoriteValues(
                favoriteFieldValue(leftItem, 'name'),
                favoriteFieldValue(rightItem, 'name'),
                'text',
                'asc'
            );

            if (tertiaryCompare !== 0) {
                return tertiaryCompare;
            }

            return compareFavoriteValues(
                favoriteFieldValue(leftItem, 'description'),
                favoriteFieldValue(rightItem, 'description'),
                'text',
                'asc'
            );
        });
    }

    function updateFavoritesSortButtons() {
        const buttons = document.querySelectorAll('.favorites-sort-button');

        buttons.forEach((button) => {
            const key = String(button.getAttribute('data-sort-key') || '').trim();
            const indicator = button.querySelector('.favorites-sort-indicator');

            if (key !== '' && key === state.favoriteSortKey) {
                button.setAttribute(
                    'aria-sort',
                    state.favoriteSortDirection === 'desc' ? 'descending' : 'ascending'
                );

                if (indicator) {
                    indicator.textContent = state.favoriteSortDirection === 'desc' ? 'v' : '^';
                }
            } else {
                button.setAttribute('aria-sort', 'none');

                if (indicator) {
                    indicator.textContent = '';
                }
            }
        });
    }

    function rememberPreferredAslUiMode(mode) {
        const normalized = normalizeMode(mode);

        if (normalized === 'ASL' || normalized === 'ECHO') {
            state.preferredAslUiMode = normalized;
        }
    }

    function findModeSelectValue(mode) {
        if (!els.modeSelect) {
            return '';
        }

        const desired = normalizeMode(mode);
        const options = Array.from(els.modeSelect.options || []);

        if (desired === 'ASL') {
            const preferred = state.preferredAslUiMode === 'ECHO' ? 'ECHO' : 'ASL';
            const preferredMatch = options.find((option) => normalizeMode(option.value) === preferred);
            if (preferredMatch) {
                return preferredMatch.value;
            }
        }

        const exactMatch = options.find((option) => normalizeMode(option.value) === desired);
        if (exactMatch) {
            return exactMatch.value;
        }

        if (desired === 'ECHO') {
            const fallbackAsl = options.find((option) => normalizeMode(option.value) === 'ASL');
            if (fallbackAsl) {
                return fallbackAsl.value;
            }
        }

        if (desired === 'ASL') {
            const fallbackAsl = options.find((option) => normalizeMode(option.value) === 'ASL');
            if (fallbackAsl) {
                return fallbackAsl.value;
            }
        }

        return '';
    }

    function setSelectedModeValue(mode) {
        if (!els.modeSelect) {
            return;
        }

        rememberPreferredAslUiMode(mode);

        const value = findModeSelectValue(mode);
        if (value !== '') {
            els.modeSelect.value = value;
        }
    }

    function normalizeAutoloadMode(mode) {
        const value = String(mode || '').trim().toLowerCase();
        return value === 'local_monitor' ? 'local_monitor' : 'transceive';
    }

    function autoloadModeLabel(mode) {
        return normalizeAutoloadMode(mode) === 'local_monitor'
            ? 'Local Monitor'
            : 'Transceive';
    }

    function normalizeStatusText(text) {
        return String(text || 'IDLE - NO CONNECTIONS').trim();
    }

    function isWaitingStatus(text) {
        return normalizeStatusText(text).toUpperCase().startsWith('WAITING');
    }

    function isConnectedStatus(text) {
        return normalizeStatusText(text).toUpperCase().startsWith('CONNECTED:');
    }

    function isDisconnectedStatus(text) {
        const value = normalizeStatusText(text).toUpperCase();
        return (
            value === 'DISCONNECTED' ||
            value === 'IDLE - NO CONNECTIONS'
        );
    }

    function isErrorStatus(text) {
        return normalizeStatusText(text).toUpperCase().startsWith('ERROR:');
    }

    function disconnectBeforeConnectEnabled() {
        return !!(els.disconnectBeforeConnectCheckbox && els.disconnectBeforeConnectCheckbox.checked);
    }

    function currentSelectedMode() {
        return normalizeMode(els.modeSelect?.value || '');
    }

    function currentStatusText() {
        return els.systemStatus
            ? String(els.systemStatus.textContent || '').trim()
            : 'IDLE - NO CONNECTIONS';
    }

    function currentAllstarCount() {
        if (!els.statusAllstar) {
            return 0;
        }

        const text = String(els.statusAllstar.textContent || '').trim();
        const match = text.match(/Connected:\s*(\d+)/i);
        return match ? Number(match[1]) || 0 : 0;
    }

    function textLooksActive(value) {
        const text = String(value || '').trim().toUpperCase();
        if (text === '') {
            return false;
        }

        return !(
            text === 'IDLE' ||
            text === 'NO LINKS' ||
            text === '-' ||
            text === 'DISABLED' ||
            text === 'NO' ||
            text === 'UNKNOWN'
        );
    }

    function isPlaceholderConfigValue(value) {
        const normalized = String(value || '').trim().toUpperCase();

        if (normalized === '') {
            return true;
        }

        return [
            'CHANGE_ME',
            'YOUR NODE',
            'YOUR DVSWITCH NODE',
            'YOUR_REAL_PASSWORD',
            'YOUR_REAL_KEY',
            'YOUR PASSWORD',
            'YOUR KEY',
        ].includes(normalized);
    }

    function readConfigAvailability() {
        const form = els.controlForm;
        const dataset = form?.dataset || {};
        const aslConfigured = dataset.aslConfigured === '1';
        const echoConfigured = Object.prototype.hasOwnProperty.call(dataset, 'echoConfigured')
            ? dataset.echoConfigured === '1'
            : aslConfigured;

        return {
            configPath: dataset.configPath || '/var/www/html/alltune2/config.ini',
            hasRealMyNode: dataset.hasRealMynode === '1',
            hasRealDvSwitchNode: dataset.hasRealDvswitchNode === '1',
            hasRealBmPassword: dataset.hasRealBmPassword === '1',
            hasRealTgifKey: dataset.hasRealTgifKey === '1',
            modes: {
                ASL: aslConfigured,
                ECHO: echoConfigured,
                BM: dataset.bmConfigured === '1',
                TGIF: dataset.tgifConfigured === '1',
                YSF: dataset.ysfConfigured === '1',
            },
        };
    }

    function modeIsConfigured(mode) {
        const config = readConfigAvailability();
        const normalized = modeConfigKey(mode);
        return !!config.modes[normalized];
    }

    function unavailableModeMessage(mode) {
        const normalized = normalizeMode(mode);
        const config = readConfigAvailability();
        const configPath = config.configPath;

        if (normalized === 'ASL') {
            return `AllStarLink is not configured on this system. A real MYNODE value is required in ${configPath}. Connect is disabled until that value is set.`;
        }

        if (normalized === 'ECHO') {
            return `EchoLink is not configured on this system. EchoLink requires a real MYNODE value and a working EchoLink setup on this ASL3 system. Connect is disabled until that is configured.`;
        }

        if (normalized === 'YSF') {
            return `YSF is not configured on this system. Real MYNODE and DVSWITCH_NODE values are required in ${configPath}. Connect is disabled until those values are set.`;
        }

        if (normalized === 'BM') {
            return `BrandMeister is not configured on this system. Real MYNODE, DVSWITCH_NODE, and BM_SelfcarePassword values are required in ${configPath}. Connect is disabled until those values are set.`;
        }

        if (normalized === 'TGIF') {
            return `TGIF is not configured on this system. Real MYNODE, DVSWITCH_NODE, and TGIF_HotspotSecurityKey values are required in ${configPath}. Connect is disabled until those values are set.`;
        }

        return `This mode is not configured on this system. Update ${configPath} with real values before using it. Connect is disabled until configuration is complete.`;
    }

    function inferDvSwitchActiveFromPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return false;
        }

        const system = payload.system || {};
        const bm = payload.networks?.brandmeister || payload.brandmeister || null;
        const tgif = payload.networks?.tgif || payload.tgif || null;
        const ysf = payload.networks?.ysf || payload.ysf || null;

        const explicitFlag =
            payload.dvswitch_link_active ??
            system.dvswitch_link_active;

        if (typeof explicitFlag !== 'undefined') {
            return !!explicitFlag;
        }

        const dmrReady = !!(payload.dmr_ready ?? system.dmr_ready ?? false);
        const dmrNetwork = String(payload.dmr_network ?? system.dmr_network ?? '').trim();
        const lastMode = normalizeMode(payload.last_mode ?? system.last_mode ?? '');
        const autoload = !!(payload.autoload_dvswitch ?? system.autoload_dvswitch ?? false);

        if (dmrReady || dmrNetwork !== '' || lastMode === 'YSF') {
            return true;
        }

        if (autoload && (
            textLooksActive(bm?.label || bm?.state || bm?.status) ||
            textLooksActive(tgif?.label || tgif?.state || tgif?.status) ||
            textLooksActive(ysf?.label || ysf?.state || ysf?.status)
        )) {
            return true;
        }

        if (
            textLooksActive(bm?.label || bm?.state || bm?.status) ||
            textLooksActive(tgif?.label || tgif?.state || tgif?.status) ||
            textLooksActive(ysf?.label || ysf?.state || ysf?.status)
        ) {
            return true;
        }

        return false;
    }

    function inferDvSwitchActiveFromDom() {
        const activityRows = document.querySelectorAll('.activity-row');

        for (const row of activityRows) {
            const labelEl = row.querySelector('.activity-label');
            const valueEl = row.querySelector('.activity-value');

            if (!labelEl || !valueEl) {
                continue;
            }

            const label = labelEl.textContent.trim().toUpperCase();
            const value = valueEl.textContent.trim().toUpperCase();

            if (label === 'DVSWITCH LINK ACTIVE') {
                if (value === 'YES') {
                    return true;
                }
                if (value === 'NO') {
                    return false;
                }
            }

            if (label === 'DMR NETWORK' && value !== '' && value !== '-') {
                return true;
            }

            if (label === 'LAST MODE' && value === 'YSF') {
                return true;
            }
        }

        const bmText = String(els.statusBm?.textContent || '').trim();
        const tgifText = String(els.statusTgif?.textContent || '').trim();
        const ysfText = String(els.statusYsf?.textContent || '').trim();

        if (
            textLooksActive(bmText) ||
            textLooksActive(tgifText) ||
            textLooksActive(ysfText)
        ) {
            return true;
        }

        const statusText = currentStatusText().toUpperCase();
        if (
            statusText.includes('(BM)') ||
            statusText.includes('(TGIF)') ||
            statusText.includes('CONNECTED: YSF TARGET') ||
            statusText.includes('WAITING: BM READY') ||
            statusText.includes('WAITING: TGIF READY')
        ) {
            return true;
        }

        return false;
    }

    function currentDvSwitchActive(payload = null) {
        if (payload && typeof payload === 'object') {
            return inferDvSwitchActiveFromPayload(payload);
        }

        return inferDvSwitchActiveFromDom();
    }

    function shouldEnableConnectButton(statusText) {
        const mode = currentSelectedMode();
        const disconnectFirst = disconnectBeforeConnectEnabled();
        const status = normalizeStatusText(statusText).toUpperCase();

        if (!modeIsConfigured(mode)) {
            return false;
        }

        if (isErrorStatus(statusText) || isDisconnectedStatus(statusText)) {
            return true;
        }

        if (isWaitingStatus(statusText)) {
            return true;
        }

        if (!isConnectedStatus(statusText)) {
            return true;
        }

        if (mode === 'ASL' || mode === 'ECHO') {
            return true;
        }

        if (mode === 'BM' && status.includes('(BM)')) {
            return false;
        }

        if (mode === 'TGIF' && status.includes('(TGIF)')) {
            return false;
        }

        if (mode === 'YSF' && status.includes('CONNECTED: YSF TARGET')) {
            return disconnectFirst ? false : true;
        }

        if (disconnectFirst) {
            return false;
        }

        return true;
    }

    function shouldEnableDisconnectButton(statusText) {
        if (isDisconnectedStatus(statusText) || isErrorStatus(statusText)) {
            return currentAllstarCount() > 0;
        }

        if (isWaitingStatus(statusText) || isConnectedStatus(statusText)) {
            return true;
        }

        return true;
    }

    function shouldEnableDisconnectAllButton(statusText) {
        const hasAllstar = currentAllstarCount() > 0;
        const hasDvSwitch = currentDvSwitchActive();

        if (isDisconnectedStatus(statusText) || isErrorStatus(statusText)) {
            return hasAllstar || hasDvSwitch;
        }

        if (isWaitingStatus(statusText) || isConnectedStatus(statusText)) {
            return true;
        }

        return hasAllstar || hasDvSwitch;
    }

    function shouldEnableDisconnectDvSwitchButton(statusText) {
        const hasDvSwitch = currentDvSwitchActive();

        if (isWaitingStatus(statusText) || isConnectedStatus(statusText)) {
            return hasDvSwitch;
        }

        if (isDisconnectedStatus(statusText) || isErrorStatus(statusText)) {
            return hasDvSwitch;
        }

        return hasDvSwitch;
    }

    function setButtonVisualState(button, enabled) {
        if (!button) {
            return;
        }

        button.disabled = !enabled;
        button.style.opacity = enabled ? '1' : '0.55';
        button.style.cursor = enabled ? 'pointer' : 'not-allowed';
    }

    function updateButtonsFromStatus(statusText) {
        if (state.busy) {
            return;
        }

        setButtonVisualState(els.connectButton, shouldEnableConnectButton(statusText));
        setButtonVisualState(els.disconnectButton, shouldEnableDisconnectButton(statusText));
        setButtonVisualState(els.disconnectAllButton, shouldEnableDisconnectAllButton(statusText));
        setButtonVisualState(els.disconnectDvSwitchButton, shouldEnableDisconnectDvSwitchButton(statusText));
    }

    function setBusy(isBusy) {
        state.busy = !!isBusy;

        if (state.busy) {
            if (els.connectButton) {
                els.connectButton.disabled = true;
                els.connectButton.style.opacity = '0.7';
                els.connectButton.style.cursor = 'wait';
            }

            if (els.disconnectButton) {
                els.disconnectButton.disabled = true;
                els.disconnectButton.style.opacity = '0.7';
                els.disconnectButton.style.cursor = 'wait';
            }

            if (els.disconnectAllButton) {
                els.disconnectAllButton.disabled = true;
                els.disconnectAllButton.style.opacity = '0.7';
                els.disconnectAllButton.style.cursor = 'wait';
            }

            if (els.disconnectDvSwitchButton) {
                els.disconnectDvSwitchButton.disabled = true;
                els.disconnectDvSwitchButton.style.opacity = '0.7';
                els.disconnectDvSwitchButton.style.cursor = 'wait';
            }

            const rowButtons = document.querySelectorAll('.allstar-disconnect-button');
            rowButtons.forEach((button) => {
                button.disabled = true;
                button.style.opacity = '0.7';
                button.style.cursor = 'wait';
            });

            return;
        }

        updateButtonsFromStatus(currentStatusText());
    }

    function setSystemStatus(text) {
        if (!els.systemStatus) {
            return;
        }

        const safeText = normalizeStatusText(text);
        els.systemStatus.textContent = safeText;
        els.systemStatus.classList.remove('waiting', 'error', 'disconnected');

        if (isWaitingStatus(safeText)) {
            els.systemStatus.classList.add('waiting');
        } else if (isErrorStatus(safeText)) {
            els.systemStatus.classList.add('error');
        } else if (isDisconnectedStatus(safeText)) {
            els.systemStatus.classList.add('disconnected');
        }

        updateButtonsFromStatus(safeText);
    }

    function configuredModeHelperText(mode, disconnectFirst) {
        if (mode === 'BM') {
            return disconnectFirst
                ? 'BrandMeister is a two-step connect. Enter or load a talkgroup and press CONNECT once. The button dims while the request runs. When the status says BM is ready and CONNECT becomes bright again, press CONNECT a second time. DISCONNECT removes the current BM connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next managed connect clears earlier managed links first.'
                : 'BrandMeister is a two-step connect. Enter or load a talkgroup and press CONNECT once. The button dims while the request runs. When the status says BM is ready and CONNECT becomes bright again, press CONNECT a second time. DISCONNECT removes the current BM connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect off, BM can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'TGIF') {
            return disconnectFirst
                ? 'TGIF is a two-step connect. Enter or load a talkgroup and press CONNECT once. The button dims while the request runs. When the status says TGIF is ready and CONNECT becomes bright again, press CONNECT a second time. DISCONNECT removes the current TGIF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next managed connect clears earlier managed links first.'
                : 'TGIF is a two-step connect. Enter or load a talkgroup and press CONNECT once. The button dims while the request runs. When the status says TGIF is ready and CONNECT becomes bright again, press CONNECT a second time. DISCONNECT removes the current TGIF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect off, TGIF can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'YSF') {
            return disconnectFirst
                ? 'YSF is a one-step connect. Enter or load the YSF target and press CONNECT once. The button dims while the request runs. Wait for the status to confirm the connection. DISCONNECT removes the current YSF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next managed connect clears earlier managed links first.'
                : 'YSF is a one-step connect. Enter or load the YSF target and press CONNECT once. The button dims while the request runs. Wait for the status to confirm the connection. DISCONNECT removes the current YSF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect off, YSF can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'ASL') {
            return disconnectFirst
                ? 'AllStarLink is a one-step connect. Enter or load a node and press CONNECT once. The button dims while the request runs. Wait for the status to confirm the connection, then you can connect another direct node if your settings allow it. DISCONNECT removes the most recent direct AllStarLink node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next CONNECT replaces earlier managed links first.'
                : 'AllStarLink is a one-step connect. Enter or load a node and press CONNECT once. The button dims while the request runs. Wait for the status to confirm the connection, then you can add another direct AllStarLink node. DISCONNECT removes the most recent direct AllStarLink node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.';
        }

        if (mode === 'ECHO') {
            return disconnectFirst
                ? 'EchoLink uses the AllStarLink connect path. Enter the target as 3 plus the zero-padded 6-digit EchoLink node number, then press CONNECT once. Example: 1234 becomes 3001234. The button dims while the request runs. Wait for the status to confirm the connection, then you can connect another direct node if your settings allow it. DISCONNECT removes the most recent direct node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next CONNECT replaces earlier managed links first.'
                : 'EchoLink uses the AllStarLink connect path. Enter the target as 3 plus the zero-padded 6-digit EchoLink node number, then press CONNECT once. Example: 1234 becomes 3001234. The button dims while the request runs. Wait for the status to confirm the connection, then you can add another direct node. DISCONNECT removes the most recent direct node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.';
        }

        return 'Select a mode, enter or load a target, and press CONNECT. The button dims while the request runs. Wait for the status and button state to update before the next step.';
    }

    function updateHelperText() {
        if (!els.helperText || !els.modeSelect) {
            return;
        }

        const mode = normalizeMode(els.modeSelect.value);
        const disconnectFirst = disconnectBeforeConnectEnabled();

        if (!modeIsConfigured(mode)) {
            els.helperText.textContent = unavailableModeMessage(mode);
            return;
        }

        els.helperText.textContent = configuredModeHelperText(mode, disconnectFirst);
    }

    function setStatusCardText(element, value, fallback) {
        if (!element) {
            return;
        }

        const text = String(value || fallback || '').trim();
        element.textContent = text !== '' ? text : fallback;
    }

    function renderFavorites(items) {
        if (!els.favoritesBody) {
            return;
        }

        state.favoritesRaw = Array.isArray(items) ? items.slice() : [];

        const renderItems = getSortedFavorites(state.favoritesRaw);

        if (renderItems.length === 0) {
            els.favoritesBody.innerHTML = '<tr><td colspan="5">No favorites saved yet.</td></tr>';
            updateFavoritesSortButtons();
            return;
        }

        const rows = renderItems.map((item) => {
            const target = escapeHtml(item.target ?? item.tg ?? '');
            const name = escapeHtml(item.name ?? '');
            const description = escapeHtml(item.description ?? item.desc ?? '-');
            const mode = normalizeMode(item.mode ?? 'BM');
            const modeDisplay = escapeHtml(favoriteModeLabel(mode));

            return `
                <tr data-target="${target}" data-mode="${escapeHtml(mode)}">
                    <td class="favorite-target">${target}</td>
                    <td>${name}</td>
                    <td>${description}</td>
                    <td class="favorite-mode">${modeDisplay}</td>
                    <td><span class="load-button">Load</span></td>
                </tr>
            `;
        });

        els.favoritesBody.innerHTML = rows.join('');
        updateFavoritesSortButtons();
    }

    function updateActivityValue(label, value) {
        const activityRows = document.querySelectorAll('.activity-row');

        activityRows.forEach((row) => {
            const labelEl = row.querySelector('.activity-label');
            const valueEl = row.querySelector('.activity-value');

            if (!labelEl || !valueEl) {
                return;
            }

            if (labelEl.textContent.trim().toUpperCase() === String(label).trim().toUpperCase()) {
                valueEl.textContent = value;
            }
        });
    }

    function renderAllstarLinks(allstarPayload) {
        if (!els.statusAllstarLinks) {
            return;
        }

        const links = Array.isArray(allstarPayload?.connected_nodes)
            ? allstarPayload.connected_nodes
            : [];

        if (links.length === 0) {
            els.statusAllstarLinks.innerHTML = '<div>No links</div>';
            return;
        }

        const rows = links.map((link) => {
            const rawNode = String(link.node ?? link.target ?? '').trim();
            const node = escapeHtml(rawNode);
            const mode = escapeHtml(
                String(link.mode_label ?? link.link_mode ?? link.mode ?? 'Connected').trim()
            );

            return `
                <div class="allstar-link-row" style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px;">
                    <span class="allstar-link-text">${node}${mode ? ' - ' + mode : ''}</span>
                    <button
                        type="button"
                        class="allstar-disconnect-button"
                        data-disconnect-node="${node}"
                        style="
                            background:#6b46c1;
                            color:#ffffff;
                            border:1px solid #8b5cf6;
                            border-radius:6px;
                            padding:4px 10px;
                            font-size:0.82rem;
                            font-weight:600;
                            cursor:pointer;
                            opacity:${state.busy ? '0.7' : '1'};
                        "
                        ${state.busy ? 'disabled' : ''}
                    >
                        Disconnect
                    </button>
                </div>
            `;
        });

        els.statusAllstarLinks.innerHTML = rows.join('');
    }

    function refreshActivityPanel(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const system = payload.system || {};
        const config = payload.config || {};

        const statusText =
            payload.status_text ||
            payload.status ||
            payload.last_status ||
            system.status_text ||
            'IDLE - NO CONNECTIONS';

        const lastMode = normalizeMode(
            payload.last_mode ||
            system.last_mode ||
            ''
        );

        const lastTarget = String(
            payload.last_target ||
            system.last_target ||
            ''
        ).trim();

        const pendingTarget = String(
            payload.pending_target ||
            system.pending_target ||
            payload.pending_tg ||
            ''
        ).trim();

        const dmrNetwork = normalizeMode(
            payload.dmr_network ||
            system.dmr_network ||
            ''
        );

        const dmrReady = !!(
            payload.dmr_ready ??
            system.dmr_ready ??
            false
        );

        const dmrActiveNetwork = normalizeMode(
            payload.dmr_active_network ||
            system.dmr_active_network ||
            ''
        );

        const dmrActiveTarget = String(
            payload.dmr_active_target ||
            system.dmr_active_target ||
            ''
        ).trim();

        const autoload = !!(
            payload.autoload_dvswitch ??
            system.autoload_dvswitch ??
            false
        );

        const autoloadMode = normalizeAutoloadMode(
            payload.autoload_dvswitch_mode ??
            system.autoload_dvswitch_mode ??
            'transceive'
        );

        const rawActiveDvSwitchMode = String(
            payload.dvswitch_active_mode ??
            system.dvswitch_active_mode ??
            ''
        ).trim().toLowerCase();

        const activeDvSwitchMode =
            rawActiveDvSwitchMode === 'local_monitor' || rawActiveDvSwitchMode === 'transceive'
                ? rawActiveDvSwitchMode
                : '';

        const disconnectBeforeConnect = !!(
            payload.disconnect_before_connect ??
            system.disconnect_before_connect ??
            false
        );

        const rawDvsNode = String(config.dvswitch_node || '').trim();
        const dvsNode = isPlaceholderConfigValue(rawDvsNode) ? '' : rawDvsNode;
        const dvswitchActive = currentDvSwitchActive(payload);

        const autoLoadValue = autoload
            ? `Enabled${dvsNode ? ` (${dvsNode})` : ''}`
            : 'Disabled';

        updateActivityValue('Last Mode', lastMode || '-');
        updateActivityValue('Last Target', lastTarget || '-');
        updateActivityValue('Pending Target', pendingTarget || '-');
        updateActivityValue(
            'DMR Network',
            dmrActiveNetwork
                ? `${dmrActiveNetwork}${dmrActiveTarget ? ` (TG ${dmrActiveTarget})` : ''}`
                : (dmrNetwork ? `${dmrNetwork}${dmrReady ? ' (Ready)' : ' (Preparing)'}` : '-')
        );
        updateActivityValue('DVSwitch Auto-Load', autoLoadValue);
        updateActivityValue('Link Mode', autoloadModeLabel(autoloadMode));
        updateActivityValue(
            'DVSwitch Active Link Mode',
            activeDvSwitchMode ? autoloadModeLabel(activeDvSwitchMode) : '-'
        );
        updateActivityValue('DVSwitch Link Active', dvswitchActive ? 'Yes' : 'No');
        updateActivityValue('Disconnect Before Connect', disconnectBeforeConnect ? 'Enabled' : 'Disabled');
        updateActivityValue('Current Status', statusText);
    }

    function userIsEditingTarget() {
        if (!els.targetInput) {
            return false;
        }

        return document.activeElement === els.targetInput;
    }

    function applyLiveStatus(payload, options = {}) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const { allowFieldSync = false } = options;
        const system = payload.system || {};
        const statusText =
            payload.status_text ||
            payload.status ||
            payload.last_status ||
            system.status_text ||
            'IDLE - NO CONNECTIONS';

        setSystemStatus(statusText);

        const bm = payload.networks?.brandmeister || payload.brandmeister || null;
        const tgif = payload.networks?.tgif || payload.tgif || null;
        const ysf = payload.networks?.ysf || payload.ysf || null;
        const allstar = payload.allstar || payload.networks?.allstar || null;

        setStatusCardText(
            els.statusBm,
            bm?.label || bm?.state || bm?.status,
            'Idle'
        );

        setStatusCardText(
            els.statusTgif,
            tgif?.label || tgif?.state || tgif?.status,
            'Idle'
        );

        setStatusCardText(
            els.statusYsf,
            ysf?.label || ysf?.state || ysf?.status,
            'Idle'
        );

        if (allstar?.connected_nodes_count !== undefined) {
            const count = Number(allstar.connected_nodes_count) || 0;
            setStatusCardText(
                els.statusAllstar,
                count > 0 ? `Connected: ${count}` : 'No links',
                'No links'
            );
        } else {
            setStatusCardText(
                els.statusAllstar,
                allstar?.label || allstar?.state || allstar?.status,
                'No links'
            );
        }

        renderAllstarLinks(allstar);
        syncAudioAlertsFromAllstar(allstar);

        if (allowFieldSync && els.modeSelect && !state.busy) {
            if (typeof payload.selected_mode === 'string') {
                setSelectedModeValue(payload.selected_mode);
            } else if (typeof system.selected_mode === 'string') {
                setSelectedModeValue(system.selected_mode);
            }
        }

        if (allowFieldSync && els.targetInput && !userIsEditingTarget() && !state.busy) {
            if (typeof payload.pending_target === 'string' && payload.pending_target !== '') {
                els.targetInput.value = payload.pending_target;
            } else if (typeof system.pending_target === 'string' && system.pending_target !== '') {
                els.targetInput.value = system.pending_target;
            } else if (typeof payload.last_target === 'string' && payload.last_target !== '') {
                els.targetInput.value = payload.last_target;
            }
        }

        if (allowFieldSync && typeof payload.autoload_dvswitch !== 'undefined' && els.autoloadCheckbox && !state.busy) {
            els.autoloadCheckbox.checked = !!payload.autoload_dvswitch;
        } else if (allowFieldSync && typeof system.autoload_dvswitch !== 'undefined' && els.autoloadCheckbox && !state.busy) {
            els.autoloadCheckbox.checked = !!system.autoload_dvswitch;
        }

        if (allowFieldSync && els.autoloadModeSelect && !state.busy) {
            if (typeof payload.autoload_dvswitch_mode === 'string') {
                els.autoloadModeSelect.value = normalizeAutoloadMode(payload.autoload_dvswitch_mode);
            } else if (typeof system.autoload_dvswitch_mode === 'string') {
                els.autoloadModeSelect.value = normalizeAutoloadMode(system.autoload_dvswitch_mode);
            }
        }

        if (allowFieldSync && els.disconnectBeforeConnectCheckbox && !state.busy) {
            if (typeof payload.disconnect_before_connect !== 'undefined') {
                els.disconnectBeforeConnectCheckbox.checked = !!payload.disconnect_before_connect;
            } else if (typeof system.disconnect_before_connect !== 'undefined') {
                els.disconnectBeforeConnectCheckbox.checked = !!system.disconnect_before_connect;
            }
        }

        if (Array.isArray(payload.favorites)) {
            renderFavorites(payload.favorites);
        }

        refreshActivityPanel(payload);
        updateHelperText();
        updateButtonsFromStatus(statusText);
    }

    function applyActionStatus(payload, options = {}) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const { preserveTarget = false, preserveMode = false } = options;
        const system = payload.system || {};
        const statusText =
            payload.status_text ||
            payload.status ||
            payload.last_status ||
            system.status_text ||
            'IDLE - NO CONNECTIONS';

        setSystemStatus(statusText);

        if (!preserveMode && els.modeSelect) {
            if (typeof payload.selected_mode === 'string') {
                setSelectedModeValue(payload.selected_mode);
            } else if (typeof system.selected_mode === 'string') {
                setSelectedModeValue(system.selected_mode);
            }
        }

        if (!preserveTarget && els.targetInput && !userIsEditingTarget()) {
            if (typeof payload.pending_target === 'string' && payload.pending_target !== '') {
                els.targetInput.value = payload.pending_target;
            } else if (typeof system.pending_target === 'string' && system.pending_target !== '') {
                els.targetInput.value = system.pending_target;
            } else if (typeof payload.last_target === 'string' && payload.last_target !== '') {
                els.targetInput.value = payload.last_target;
            }
        }

        if (typeof payload.autoload_dvswitch !== 'undefined' && els.autoloadCheckbox) {
            els.autoloadCheckbox.checked = !!payload.autoload_dvswitch;
        } else if (typeof system.autoload_dvswitch !== 'undefined' && els.autoloadCheckbox) {
            els.autoloadCheckbox.checked = !!system.autoload_dvswitch;
        }

        if (els.autoloadModeSelect) {
            if (typeof payload.autoload_dvswitch_mode === 'string') {
                els.autoloadModeSelect.value = normalizeAutoloadMode(payload.autoload_dvswitch_mode);
            } else if (typeof system.autoload_dvswitch_mode === 'string') {
                els.autoloadModeSelect.value = normalizeAutoloadMode(system.autoload_dvswitch_mode);
            }
        }

        if (els.disconnectBeforeConnectCheckbox) {
            if (typeof payload.disconnect_before_connect !== 'undefined') {
                els.disconnectBeforeConnectCheckbox.checked = !!payload.disconnect_before_connect;
            } else if (typeof system.disconnect_before_connect !== 'undefined') {
                els.disconnectBeforeConnectCheckbox.checked = !!system.disconnect_before_connect;
            }
        }

        refreshActivityPanel(payload);
        updateHelperText();
        updateButtonsFromStatus(statusText);
    }

    async function requestJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(options.headers || {}),
            },
            ...options,
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        return response.json();
    }

    async function loadStatus() {
        const payload = await requestJson(state.endpoints.status, {
            method: 'GET',
        });

        applyLiveStatus(payload, { allowFieldSync: false });
        return payload;
    }

    function refreshStatusInBackground() {
        window.setTimeout(() => {
            loadStatus().catch((error) => {
                console.error(error);
                setSystemStatus('ERROR: STATUS UNAVAILABLE');
                updateActivityValue('Current Status', 'ERROR: STATUS UNAVAILABLE');
            });
        }, 0);
    }

    async function sendAction(action, extraPayload = {}) {
        if (
            !els.targetInput ||
            !els.modeSelect ||
            !els.autoloadCheckbox ||
            !els.autoloadModeSelect ||
            !els.disconnectBeforeConnectCheckbox
        ) {
            return;
        }

        if (action === 'connect' && !modeIsConfigured(els.modeSelect.value)) {
            updateHelperText();
            updateButtonsFromStatus(currentStatusText());
            return;
        }

        state.lastRequestedUiMode = currentSelectedMode();
        rememberPreferredAslUiMode(state.lastRequestedUiMode);

        const payload = {
            action,
            action_type: action,
            target: els.targetInput.value.trim(),
            tgNum: els.targetInput.value.trim(),
            mode: modeRequestValue(els.modeSelect.value),
            ui_mode: currentSelectedMode(),
            autoload_dvswitch: els.autoloadCheckbox.checked ? 1 : 0,
            autoload_dvswitch_mode: normalizeAutoloadMode(els.autoloadModeSelect.value),
            disconnect_before_connect: els.disconnectBeforeConnectCheckbox.checked ? 1 : 0,
            ...extraPayload,
        };

        suppressAudioDuringTransitions();

        let busyReleasedEarly = false;
        setBusy(true);

        try {
            const responsePayload = await requestJson(state.endpoints.connect, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (
                state.lastRequestedUiMode === 'ECHO' &&
                normalizeMode(responsePayload.selected_mode) === 'ASL'
            ) {
                responsePayload.selected_mode = 'ECHO';
            }

            applyActionStatus(responsePayload);

            setBusy(false);
            busyReleasedEarly = true;
            refreshStatusInBackground();
        } catch (error) {
            console.error(error);
            setSystemStatus('ERROR: REQUEST FAILED');
            updateActivityValue('Current Status', 'ERROR: REQUEST FAILED');
        } finally {
            if (!busyReleasedEarly) {
                setBusy(false);
            }
            state.lastRequestedUiMode = '';
        }
    }

    async function rememberPreferences() {
        if (
            !els.autoloadCheckbox ||
            !els.autoloadModeSelect ||
            !els.disconnectBeforeConnectCheckbox
        ) {
            return;
        }

        try {
            const payload = await requestJson(state.endpoints.connect, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'remember_autoload',
                    action_type: 'remember_autoload',
                    autoload_dvswitch: els.autoloadCheckbox.checked ? 1 : 0,
                    autoload_dvswitch_mode: normalizeAutoloadMode(els.autoloadModeSelect.value),
                    disconnect_before_connect: els.disconnectBeforeConnectCheckbox.checked ? 1 : 0,
                }),
            });

            applyActionStatus(payload, { preserveTarget: true, preserveMode: true });
        } catch (error) {
            console.error(error);
        }
    }

    function wireAllstarDisconnectButtons() {
        if (!els.statusAllstarLinks) {
            return;
        }

        els.statusAllstarLinks.addEventListener('click', (event) => {
            const button = event.target.closest('[data-disconnect-node]');
            if (!button || state.busy) {
                return;
            }

            const selectedNode = String(button.getAttribute('data-disconnect-node') || '').trim();
            if (!selectedNode) {
                return;
            }

            sendAction('disconnect_selected', {
                selected_node: selectedNode,
                target: selectedNode,
                tgNum: selectedNode,
            });
        });

        els.statusAllstarLinks.addEventListener('mouseover', (event) => {
            const button = event.target.closest('.allstar-disconnect-button');
            if (!button || button.disabled) {
                return;
            }

            button.style.background = '#7c3aed';
            button.style.borderColor = '#a78bfa';
            button.style.cursor = 'pointer';
        });

        els.statusAllstarLinks.addEventListener('mouseout', (event) => {
            const button = event.target.closest('.allstar-disconnect-button');
            if (!button) {
                return;
            }

            button.style.background = '#6b46c1';
            button.style.borderColor = '#8b5cf6';
            button.style.cursor = button.disabled ? 'not-allowed' : 'pointer';
        });
    }

    function wireFavoritesSort() {
        const table = document.getElementById('favorites-table');
        if (!table) {
            return;
        }

        table.addEventListener('click', (event) => {
            const button = event.target.closest('.favorites-sort-button');
            if (!button) {
                return;
            }

            const sortKey = String(button.getAttribute('data-sort-key') || '').trim();
            const sortType = String(button.getAttribute('data-sort-type') || 'text').trim().toLowerCase();

            if (sortKey === '') {
                return;
            }

            if (state.favoriteSortKey === sortKey) {
                state.favoriteSortDirection = state.favoriteSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                state.favoriteSortKey = sortKey;
                state.favoriteSortDirection = 'asc';
                state.favoriteSortType = sortType === 'mixed' ? 'mixed' : 'text';
            }

            renderFavorites(state.favoritesRaw);
        });
    }

    function wireFavoritesLoad() {
        if (!els.favoritesBody || !els.targetInput || !els.modeSelect) {
            return;
        }

        els.favoritesBody.addEventListener('click', (event) => {
            const row = event.target.closest('tr[data-target][data-mode]');
            if (!row) {
                return;
            }

            const target = row.getAttribute('data-target') || '';
            const mode = normalizeMode(row.getAttribute('data-mode') || 'BM');

            els.targetInput.value = target;
            setSelectedModeValue(mode);
            updateHelperText();
            updateButtonsFromStatus(currentStatusText());

            window.scrollTo({
                top: 0,
                behavior: 'smooth',
            });
        });
    }

    function startPolling() {
        if (state.pollTimer) {
            window.clearInterval(state.pollTimer);
        }

        state.pollTimer = window.setInterval(async () => {
            if (!state.busy) {
                try {
                    await loadStatus();
                } catch (error) {
                    console.error(error);
                    setSystemStatus('ERROR: STATUS UNAVAILABLE');
                    updateActivityValue('Current Status', 'ERROR: STATUS UNAVAILABLE');
                }
            }
        }, state.pollIntervalMs);
    }

    function init() {
        if (!hasCoreElements()) {
            return;
        }

        rememberPreferredAslUiMode(currentSelectedMode());

        if (els.modeSelect) {
            els.modeSelect.addEventListener('change', () => {
                rememberPreferredAslUiMode(els.modeSelect.value);
                updateHelperText();
                updateButtonsFromStatus(currentStatusText());
            });
        }

        if (els.connectButton) {
            els.connectButton.addEventListener('click', () => {
                sendAction('connect');
            });
        }

        if (els.disconnectButton) {
            els.disconnectButton.addEventListener('click', () => {
                sendAction('disconnect');
            });
        }

        if (els.disconnectAllButton) {
            els.disconnectAllButton.addEventListener('click', () => {
                state.muteAudioAnnouncements = true;
                suppressAudioDuringTransitions(1500);
                sendAction('disconnect_all');
            });
        }

        if (els.disconnectDvSwitchButton) {
            els.disconnectDvSwitchButton.addEventListener('click', () => {
                sendAction('disconnect_dvswitch');
            });
        }

        if (els.autoloadCheckbox) {
            els.autoloadCheckbox.addEventListener('change', rememberPreferences);
        }

        if (els.autoloadModeSelect) {
            els.autoloadModeSelect.addEventListener('change', rememberPreferences);
        }

        if (els.disconnectBeforeConnectCheckbox) {
            els.disconnectBeforeConnectCheckbox.addEventListener('change', () => {
                rememberPreferences();
                updateHelperText();
                updateButtonsFromStatus(currentStatusText());
            });
        }

        if (els.audioAlertsCheckbox) {
            els.audioAlertsCheckbox.addEventListener('change', () => {
                state.audioAlertsEnabled = !!els.audioAlertsCheckbox.checked;
                persistAudioAlertsPreference(state.audioAlertsEnabled);

                if (!state.audioAlertsEnabled) {
                    cancelSpeechQueue();
                }
            });
        }

        if (els.controlForm) {
            els.controlForm.addEventListener('submit', (event) => {
                event.preventDefault();
            });
        }

        wireAllstarDisconnectButtons();
        wireFavoritesSort();
        wireFavoritesLoad();
        loadAudioAlertsPreference();
        updateHelperText();
        checkForRepoUpdate();

        loadStatus().catch((error) => {
            console.error(error);
            setSystemStatus('ERROR: STATUS UNAVAILABLE');
            updateActivityValue('Current Status', 'ERROR: STATUS UNAVAILABLE');
        });

        startPolling();
    }

    document.addEventListener('DOMContentLoaded', init);
})();