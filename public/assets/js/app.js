
(() => {
    'use strict';

    const state = {
        busy: false,
        pollTimer: null,
        quickStatusTimers: [],
        pollIntervalMs: 2000,
        fastPollIntervalMs: 650,
        lastRequestedUiMode: '',
        userSelectionHoldUntil: 0,
        cachedDvSwitchNode: '',
        preferredAslUiMode: 'ASL',
        favoriteSortKey: 'target',
        favoriteSortDirection: 'asc',
        favoriteSortType: 'mixed',
        favoritesRaw: [],
        saveFavoriteTargetOverride: '',
        saveFavoriteModeOverride: '',
        favoritesSignature: '',
        allstarLinksSignature: '',
        pendingDisconnectNodes: new Map(),
        audioAlertsEnabled: true,
        audioStateInitialized: false,
        previousConnectedNodes: [],
        muteAudioAnnouncements: false,
        audioSettleUntil: 0,
        recentAudioEvents: new Map(),
        immediateAudioEvents: new Map(),
        lastAllstarPayload: null,
        manualAutoloadPreference: null,
        endpoints: {
            status: '/alltune2/api/status.php',
            connect: '/alltune2/api/connect.php',
            direct: '/alltune2/api/direct_link.php',
            favorites: '/alltune2/api/favorites.php',
        },
        auth: {
            enabled: !!window.ALLTUNE2_AUTH?.enabled,
            loggedIn: !!window.ALLTUNE2_AUTH?.loggedIn,
            canWrite: window.ALLTUNE2_AUTH?.canWrite !== false,
            csrfToken: String(window.ALLTUNE2_AUTH?.csrfToken || ''),
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
        statusDstar: document.getElementById('status-dstar'),
        statusP25: document.getElementById('status-p25'),
        statusNxdn: document.getElementById('status-nxdn'),
        statusAllstar: document.getElementById('status-allstar'),
        statusAllstarLinks: document.getElementById('status-allstar-links'),
        brandingTitle: document.getElementById('branding-title'),
        updateIndicator: document.getElementById('update-indicator'),
        dtmfCode: document.getElementById('dtmf-code'),
        sendDtmfButton: document.getElementById('send-dtmf-button'),
        saveFavoriteButton: document.getElementById('save-favorite-button'),
        saveFavoriteModal: document.getElementById('save-favorite-modal'),
        saveFavoriteForm: document.getElementById('save-favorite-form'),
        saveFavoriteClose: document.getElementById('save-favorite-close'),
        saveFavoriteCancel: document.getElementById('save-favorite-cancel'),
        saveFavoriteSubmit: document.getElementById('save-favorite-submit'),
        saveFavoriteName: document.getElementById('save-favorite-name'),
        saveFavoriteDescription: document.getElementById('save-favorite-description'),
        saveFavoriteTargetValue: document.getElementById('save-favorite-target-value'),
        saveFavoriteModeValue: document.getElementById('save-favorite-mode-value'),
        saveFavoriteMessage: document.getElementById('save-favorite-message'),
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

    function markAudioSettleWindow(milliseconds) {
        const until = Date.now() + Math.max(0, milliseconds);
        state.audioSettleUntil = Math.max(state.audioSettleUntil, until);
    }

    function pruneRecentAudioEvents() {
        const cutoff = Date.now() - 6000;

        for (const [signature, timestamp] of state.recentAudioEvents.entries()) {
            if (timestamp < cutoff) {
                state.recentAudioEvents.delete(signature);
            }
        }
    }

    function pruneImmediateAudioEvents() {
        const cutoff = Date.now() - 12000;

        for (const [signature, timestamp] of state.immediateAudioEvents.entries()) {
            if (timestamp < cutoff) {
                state.immediateAudioEvents.delete(signature);
            }
        }
    }

    function markImmediateAudioEvent(signature) {
        if (signature === '') {
            return;
        }

        pruneImmediateAudioEvents();
        state.immediateAudioEvents.set(signature, Date.now());
    }

    function shouldSuppressImmediateFollowup(signature, cooldownMs = 8000) {
        if (signature === '') {
            return false;
        }

        pruneImmediateAudioEvents();

        const now = Date.now();
        const last = state.immediateAudioEvents.get(signature) ?? 0;
        return (now - last) < cooldownMs;
    }

    function shouldSuppressRecentAudio(signature, cooldownMs = 3500) {
        pruneRecentAudioEvents();

        const now = Date.now();
        const last = state.recentAudioEvents.get(signature) ?? 0;

        if ((now - last) < cooldownMs) {
            return true;
        }

        state.recentAudioEvents.set(signature, now);
        return false;
    }

    function speakAudioAlert(text, signature = '') {
        if (!state.audioAlertsEnabled || state.muteAudioAnnouncements) {
            return;
        }

        if (!('speechSynthesis' in window)) {
            return;
        }

        if (signature !== '' && shouldSuppressRecentAudio(signature)) {
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

    function announceNodeConnected(node) {
        const normalizedNode = String(node || '').trim();
        if (normalizedNode === '') {
            return;
        }

        speakAudioAlert(
            `Node ${formatNodeForSpeech(normalizedNode)} has connected`,
            `connect:${normalizedNode}`
        );
    }

    function announceNodeDisconnected(node) {
        const normalizedNode = String(node || '').trim();
        if (normalizedNode === '') {
            return;
        }

        speakAudioAlert(
            `Node ${formatNodeForSpeech(normalizedNode)} has disconnected`,
            `disconnect:${normalizedNode}`
        );
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

    function withoutAllstarSnapshot(payload) {
        if (!payload || typeof payload !== 'object') {
            return payload;
        }

        const clone = { ...payload };
        delete clone.allstar;

        if (clone.networks && typeof clone.networks === 'object') {
            clone.networks = { ...clone.networks };
            delete clone.networks.allstar;
        }

        return clone;
    }

    function linkLooksKeyed(link, holdSeconds = 5) {
        if (link?.keyed) {
            return true;
        }

        const raw = String(link?.last_keyed ?? '').trim();
        if (!/^-?\d+$/.test(raw)) {
            return false;
        }

        const seconds = Number(raw);
        return seconds >= 0 && seconds <= holdSeconds;
    }

    function anyAllstarLinkLooksKeyed(allstarPayload) {
        const links = Array.isArray(allstarPayload?.connected_nodes)
            ? allstarPayload.connected_nodes
            : [];

        return links.some((link) => linkLooksKeyed(link));
    }

    function dvswitchLinkLooksKeyed(allstarPayload) {
        const links = Array.isArray(allstarPayload?.connected_nodes)
            ? allstarPayload.connected_nodes
            : [];
        const dvswitchNode = configuredDvSwitchNodeFromDom();

        if (dvswitchNode === '') {
            return false;
        }

        return links.some((link) => String(link?.node ?? '').trim() === dvswitchNode && linkLooksKeyed(link));
    }

    function payloadModeLooksActive(payload) {
        const label = payload?.label || payload?.state || payload?.status || '';
        const text = String(label).trim().toUpperCase();
        if (text === '') {
            return false;
        }

        return !(text === 'IDLE' || text === 'NO LINKS' || text === '-');
    }

    function parseNodeFromStatus(statusText) {
        const match = String(statusText || '').match(/(?:ALLSTAR NODE|ECHOLINK NODE|DVSWITCH LINK)\s+(\d{3,})/i);
        return match ? String(match[1]).trim() : '';
    }

    function configuredDvSwitchNodeFromDom() {
        const controlForm = document.getElementById('control-form');
        const configuredNode = String(controlForm?.dataset?.dvswitchNode || '').trim();

        if (configuredNode !== '') {
            state.cachedDvSwitchNode = configuredNode;
            return configuredNode;
        }

        const managedCard = document.querySelector('.private-link-managed-card');
        let match = String(managedCard?.textContent || '').match(/\b(\d{3,})\b/);

        if (match) {
            state.cachedDvSwitchNode = String(match[1]).trim();
            return state.cachedDvSwitchNode;
        }

        const checkboxLabel = document.querySelector('label[for="autoload_dvswitch"]');
        const labelText = String(checkboxLabel?.textContent || '');
        match = labelText.match(/\((\d{3,})\)/);

        if (match) {
            state.cachedDvSwitchNode = String(match[1]).trim();
            return state.cachedDvSwitchNode;
        }

        const activityRows = document.querySelectorAll('.activity-row');

        for (const row of activityRows) {
            const labelEl = row.querySelector('.activity-label');
            const valueEl = row.querySelector('.activity-value');

            if (!labelEl || !valueEl) {
                continue;
            }

            if (labelEl.textContent.trim().toUpperCase() !== 'DVSWITCH AUTO-LOAD') {
                continue;
            }

            match = String(valueEl.textContent || '').match(/\((\d{3,})\)/);
            if (match) {
                state.cachedDvSwitchNode = String(match[1]).trim();
                return state.cachedDvSwitchNode;
            }
        }

        return state.cachedDvSwitchNode || '';
    }

    function announceImmediateActionAudio(statusText) {
        const normalizedStatus = normalizeStatusText(statusText);
        const upperStatus = normalizedStatus.toUpperCase();
        const directNode = parseNodeFromStatus(normalizedStatus);

        if (directNode !== '') {
            if (upperStatus.startsWith('CONNECTED:')) {
                const signature = `connect:${directNode}`;
                markImmediateAudioEvent(signature);
                announceNodeConnected(directNode);
                return;
            }

            if (upperStatus.startsWith('DISCONNECTED:')) {
                const signature = `disconnect:${directNode}`;
                markImmediateAudioEvent(signature);
                announceNodeDisconnected(directNode);
            }

            return;
        }

        const dvswitchNode = configuredDvSwitchNodeFromDom();
        if (dvswitchNode === '') {
            return;
        }

        if (
            upperStatus.startsWith('CONNECTED: YSF TARGET') ||
            upperStatus.startsWith('CONNECTED: D-STAR TARGET') ||
            upperStatus.startsWith('CONNECTED: DSTAR TARGET') ||
            upperStatus.startsWith('CONNECTED: P25 TARGET') ||
            upperStatus.startsWith('CONNECTED: NXDN TARGET')
        ) {
            const signature = `connect:${dvswitchNode}`;
            markImmediateAudioEvent(signature);
            announceNodeConnected(dvswitchNode);
            return;
        }

        if (/^CONNECTED:\s+TG\s+/i.test(normalizedStatus) && (upperStatus.includes('(BM)') || upperStatus.includes('(TGIF)'))) {
            const signature = `connect:${dvswitchNode}`;
            markImmediateAudioEvent(signature);
            announceNodeConnected(dvswitchNode);
            return;
        }

        if (
            upperStatus === 'DISCONNECTED: YSF' ||
            upperStatus === 'DISCONNECTED: BM' ||
            upperStatus === 'DISCONNECTED: TGIF' ||
            upperStatus === 'DISCONNECTED: D-STAR' ||
            upperStatus === 'DISCONNECTED: DSTAR' ||
            upperStatus === 'DISCONNECTED: P25' ||
            upperStatus === 'DISCONNECTED: NXDN' ||
            upperStatus.startsWith('DISCONNECTED: DVSWITCH LINK')
        ) {
            const signature = `disconnect:${dvswitchNode}`;
            markImmediateAudioEvent(signature);
            announceNodeDisconnected(dvswitchNode);
        }
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

        const addedNodes = currentNodes.filter((node) => !state.previousConnectedNodes.includes(node));
        const removedNodes = state.previousConnectedNodes.filter((node) => !currentNodes.includes(node));

        state.previousConnectedNodes = currentNodes.slice();

        if (state.muteAudioAnnouncements) {
            if (currentNodes.length === 0) {
                state.muteAudioAnnouncements = false;
                cancelSpeechQueue();
                markAudioSettleWindow(250);
            }

            return;
        }

        if (Date.now() < state.audioSettleUntil) {
            return;
        }

        addedNodes.forEach((node) => {
            const signature = `connect:${String(node || '').trim()}`;
            if (shouldSuppressImmediateFollowup(signature)) {
                return;
            }

            announceNodeConnected(node);
        });

        removedNodes.forEach((node) => {
            const signature = `disconnect:${String(node || '').trim()}`;
            if (shouldSuppressImmediateFollowup(signature)) {
                return;
            }

            announceNodeDisconnected(node);
        });
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

        if ([
            'D-STAR',
            'D STAR',
            'DSTAR',
        ].includes(value)) {
            return 'DSTAR';
        }

        if ([
            'P-25',
            'P 25',
            'P25',
        ].includes(value)) {
            return 'P25';
        }

        if ([
            'N-XDN',
            'N XDN',
            'NXDN',
        ].includes(value)) {
            return 'NXDN';
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


    function holdUserSelection(milliseconds = 7000) {
        state.userSelectionHoldUntil = Date.now() + milliseconds;
    }

    function userSelectionIsHeld() {
        if (Date.now() > Number(state.userSelectionHoldUntil || 0)) {
            state.userSelectionHoldUntil = 0;
            return false;
        }

        return true;
    }


    function modeForcesDvSwitch(mode) {
        const normalized = normalizeMode(mode);
        return normalized === 'BM' || normalized === 'TGIF' || normalized === 'YSF' || normalized === 'DSTAR' || normalized === 'P25' || normalized === 'NXDN';
    }

    function syncAutoloadUiForMode(mode) {
        if (!els.autoloadCheckbox) {
            return;
        }

        const forced = modeForcesDvSwitch(mode);

        if (forced) {
            if (state.manualAutoloadPreference === null) {
                state.manualAutoloadPreference = !!els.autoloadCheckbox.checked;
            }
            els.autoloadCheckbox.checked = true;
            els.autoloadCheckbox.disabled = true;
            els.autoloadCheckbox.style.cursor = 'not-allowed';
            els.autoloadCheckbox.style.opacity = '1';
            return;
        }

        els.autoloadCheckbox.disabled = false;
        els.autoloadCheckbox.style.cursor = 'pointer';
        els.autoloadCheckbox.style.opacity = '1';

        if (state.manualAutoloadPreference !== null) {
            els.autoloadCheckbox.checked = !!state.manualAutoloadPreference;
        }
    }

    function currentAllstarPayload() {
        return state.lastAllstarPayload || null;
    }

    function currentDirectConnectedNodeCount() {
        const payload = currentAllstarPayload();
        const nodes = connectedNodeListFromPayload(payload);
        const dvswitchNode = configuredDvSwitchNodeFromDom();
        return nodes.filter((node) => node !== '' && node !== dvswitchNode).length;
    }

    function shouldUseDirectEndpoint(action, payload) {
        const uiMode = normalizeMode(payload.ui_mode || payload.mode || currentSelectedMode());

        if (action === 'connect') {
            return uiMode === 'ASL' || uiMode === 'ECHO';
        }

        if (action === 'disconnect_selected') {
            return true;
        }

        if (action === 'disconnect') {
            return currentDirectConnectedNodeCount() > 0;
        }

        return false;
    }

    function applyImmediateAllstarSnapshot(allstarPayload) {
        const allstar = allstarPayload || null;
        state.lastAllstarPayload = allstar;

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

        applyKeyedStateToCard(els.statusAllstar, false);
        renderAllstarLinks(allstar);
        syncAudioAlertsFromAllstar(allstar);
    }

    function favoriteModeLabel(mode) {
        const normalized = normalizeMode(mode);

        if (normalized === 'ASL') {
            return 'ASL';
        }

        if (normalized === 'ECHO') {
            return 'E/L';
        }

        if (normalized === 'DSTAR') {
            return 'D-Star';
        }

        if (normalized === 'P25') {
            return 'P25';
        }

        if (normalized === 'NXDN') {
            return 'NXDN';
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

    function favoriteDisplayParts(item) {
        const name = String(item?.name ?? '').trim();
        const description = String(item?.description ?? item?.desc ?? '').trim();

        if (name !== '' && description !== '' && description !== '-' && description !== name) {
            return {
                short: name,
                full: `${name} - ${description}`,
            };
        }

        if (name !== '') {
            return {
                short: name,
                full: name,
            };
        }

        if (description !== '' && description !== '-') {
            return {
                short: description,
                full: description,
            };
        }

        return {
            short: '',
            full: '',
        };
    }

    function favoriteTargetCandidates(mode, target) {
        const normalizedMode = normalizeMode(mode);
        const raw = String(target ?? '').trim();
        const candidates = [];

        if (raw !== '') {
            candidates.push(raw);
        }

        if (normalizedMode === 'ECHO' && /^3\d{6}$/.test(raw)) {
            const padded = raw.slice(1);
            const unpadded = String(Number(padded));
            candidates.push(padded);
            if (unpadded !== padded) {
                candidates.push(unpadded);
            }
        }

        return [...new Set(candidates.filter(Boolean))];
    }

    function favoriteForModeTarget(mode, target, favorites = state.favoritesRaw) {
        const normalizedMode = normalizeMode(mode);
        const candidates = favoriteTargetCandidates(normalizedMode, target);

        if (normalizedMode === '' || candidates.length === 0 || !Array.isArray(favorites)) {
            return null;
        }

        return favorites.find((favorite) => (
            candidates.includes(String(favorite?.target ?? favorite?.tg ?? '').trim())
            && normalizeMode(favorite?.mode ?? 'BM') === normalizedMode
        )) || null;
    }

    function payloadDisplayParts(link) {
        const short = String(
            link?.display_short ??
            link?.display_name ??
            link?.callsign ??
            link?.name ??
            ''
        ).trim();

        const description = String(
            link?.display_description ??
            link?.description ??
            link?.desc ??
            ''
        ).trim();

        const location = String(link?.display_location ?? '').trim();
        const fullFromPayload = String(link?.display_full ?? '').trim();

        if (fullFromPayload !== '') {
            return {
                short: short || fullFromPayload,
                full: fullFromPayload,
            };
        }

        const pieces = [];
        if (short !== '') {
            pieces.push(short);
        }
        if (description !== '' && description !== '-' && description !== short) {
            pieces.push(description);
        }

        let full = pieces.join(' - ');
        if (location !== '') {
            full = full !== '' ? `${full}, ${location}` : location;
        }

        return {
            short: short || description || location,
            full,
        };
    }

    function parseStatusTarget(value) {
        let text = String(value ?? '').trim();

        if (text === '') {
            return null;
        }

        text = text.replace(/^Connected:\s*/i, '').trim();

        const tgMatch = text.match(/^TG\s*([0-9#]+)$/i);
        if (tgMatch) {
            return {
                target: tgMatch[1],
                display: `TG ${tgMatch[1]}`,
            };
        }

        return {
            target: text,
            display: text,
        };
    }

    function statusCardValueWithFavorite(networkPayload, mode, fallback, favorites = state.favoritesRaw) {
        const raw = String(networkPayload?.label || networkPayload?.state || networkPayload?.status || fallback || '').trim();
        const baseText = raw !== '' ? raw : fallback;

        if (!payloadModeLooksActive(networkPayload)) {
            return {
                text: baseText,
                title: baseText,
            };
        }

        const parsed = parseStatusTarget(baseText);
        if (!parsed || parsed.target === '') {
            return {
                text: baseText,
                title: baseText,
            };
        }

        const favorite = favoriteForModeTarget(mode, parsed.target, favorites);
        const parts = favoriteDisplayParts(favorite);

        if (!favorite || parts.short === '') {
            return {
                text: baseText,
                title: baseText,
            };
        }

        return {
            text: `${parsed.display} • ${parts.short}`,
            title: `${baseText} - ${parts.full}`,
        };
    }

    function setStatusCardFromNetwork(element, networkPayload, mode, fallback, favorites = state.favoritesRaw) {
        const value = statusCardValueWithFavorite(networkPayload, mode, fallback, favorites);
        setStatusCardText(element, value.text, fallback, value.title);
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
        return compareFavoriteValues(
            favoriteModeLabel(left),
            favoriteModeLabel(right),
            'text',
            direction
        );
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

    function authAllowsActions() {
        return !state.auth.enabled || state.auth.loggedIn || state.auth.canWrite;
    }

    function authLoginRequired() {
        return !!(state.auth.enabled && !state.auth.loggedIn && !state.auth.canWrite);
    }

    function loginRequiredMessage() {
        return 'LOGIN REQUIRED - SIGN IN TO CONTROL ALLTUNE2';
    }

    function sanitizeDtmf(value) {
        return String(value || '')
            .replace(/[^0-9*#]/g, '')
            .slice(0, 14);
    }

    function currentDtmfValue() {
        return sanitizeDtmf(els.dtmfCode?.value || '');
    }

    function updateDtmfButtonState() {
        if (!els.sendDtmfButton) {
            return;
        }

        const enabled = authAllowsActions() && !state.busy && currentDtmfValue() !== '';
        els.sendDtmfButton.disabled = !enabled;
        els.sendDtmfButton.style.opacity = enabled ? '1' : '0.55';
        els.sendDtmfButton.style.cursor = enabled ? 'pointer' : 'not-allowed';
    }

    function setControlFieldWriteState(control, enabled) {
        if (!control) {
            return;
        }

        control.disabled = !enabled;
        control.style.opacity = enabled ? '1' : '0.58';
        control.style.cursor = enabled ? '' : 'not-allowed';

        if (enabled || !authLoginRequired()) {
            control.removeAttribute('title');
        } else {
            control.setAttribute('title', 'Login required to control AllTune2');
        }
    }

    function updateControlCenterWriteState() {
        const enabled = authAllowsActions() && !state.busy;

        [
            els.targetInput,
            els.modeSelect,
            els.autoloadModeSelect,
            els.disconnectBeforeConnectCheckbox,
            els.audioAlertsCheckbox,
            els.dtmfCode,
        ].forEach((control) => setControlFieldWriteState(control, enabled));

        updateDtmfButtonState();
    }

    function updateDashboardFavoritesWriteState() {
        if (!els.favoritesBody) {
            return;
        }

        const enabled = authAllowsActions();

        els.favoritesBody.querySelectorAll('tr[data-target][data-mode]').forEach((row) => {
            row.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            row.style.cursor = enabled ? 'pointer' : 'not-allowed';
            row.style.opacity = enabled ? '1' : '0.68';

            if (enabled) {
                row.removeAttribute('title');
            } else {
                row.setAttribute('title', 'Login required to use favorites');
            }
        });
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
                DSTAR: dataset.dstarConfigured === '1',
                P25: dataset.p25Configured === '1',
                NXDN: dataset.nxdnConfigured === '1',
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

        if (normalized === 'DSTAR') {
            return `D-Star is not configured on this system. Real MYNODE and DVSWITCH_NODE values plus DSTAR_ENABLED=1 are required in ${configPath}, and /opt/MMDVM_Bridge/dvswitch.sh must exist. Connect is disabled until that is configured.`;
        }

        if (normalized === 'P25') {
            return `P25 is not configured on this system. Real MYNODE and DVSWITCH_NODE values plus P25_ENABLED=1 are required in ${configPath}, and /opt/MMDVM_Bridge/dvswitch.sh must exist. Connect is disabled until that is configured.`;
        }

        if (normalized === 'NXDN') {
            return `NXDN is not configured on this system. Real MYNODE and DVSWITCH_NODE values plus NXDN_ENABLED=1 are required in ${configPath}, and /opt/MMDVM_Bridge/dvswitch.sh must exist. Connect is disabled until that is configured.`;
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
        const dstar = payload.networks?.dstar || payload.dstar || null;
        const p25 = payload.networks?.p25 || payload.p25 || null;
        const nxdn = payload.networks?.nxdn || payload.nxdn || null;

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

        if (dmrReady || dmrNetwork !== '' || ['YSF', 'DSTAR', 'P25', 'NXDN'].includes(lastMode)) {
            return true;
        }

        if (autoload && (
            textLooksActive(bm?.label || bm?.state || bm?.status) ||
            textLooksActive(tgif?.label || tgif?.state || tgif?.status) ||
            textLooksActive(ysf?.label || ysf?.state || ysf?.status) ||
            textLooksActive(dstar?.label || dstar?.state || dstar?.status) ||
            textLooksActive(p25?.label || p25?.state || p25?.status) ||
            textLooksActive(nxdn?.label || nxdn?.state || nxdn?.status)
        )) {
            return true;
        }

        if (
            textLooksActive(bm?.label || bm?.state || bm?.status) ||
            textLooksActive(tgif?.label || tgif?.state || tgif?.status) ||
            textLooksActive(ysf?.label || ysf?.state || ysf?.status) ||
            textLooksActive(dstar?.label || dstar?.state || dstar?.status) ||
            textLooksActive(p25?.label || p25?.state || p25?.status) ||
            textLooksActive(nxdn?.label || nxdn?.state || nxdn?.status)
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

            if (label === 'DIGITAL NETWORK' && value !== '' && value !== '-') {
                return true;
            }

            if (label === 'LAST MODE' && ['YSF', 'DSTAR', 'P25', 'NXDN'].includes(value)) {
                return true;
            }
        }

        const bmText = String(els.statusBm?.textContent || '').trim();
        const tgifText = String(els.statusTgif?.textContent || '').trim();
        const ysfText = String(els.statusYsf?.textContent || '').trim();
        const dstarText = String(els.statusDstar?.textContent || '').trim();
        const p25Text = String(els.statusP25?.textContent || '').trim();
        const nxdnText = String(els.statusNxdn?.textContent || '').trim();

        if (
            textLooksActive(bmText) ||
            textLooksActive(tgifText) ||
            textLooksActive(ysfText) ||
            textLooksActive(dstarText) ||
            textLooksActive(p25Text) ||
            textLooksActive(nxdnText)
        ) {
            return true;
        }

        const statusText = currentStatusText().toUpperCase();
        if (
            statusText.includes('(BM)') ||
            statusText.includes('(TGIF)') ||
            statusText.includes('CONNECTED: YSF TARGET') ||
            statusText.includes('CONNECTED: D-STAR TARGET') ||
            statusText.includes('CONNECTED: P25 TARGET') ||
            statusText.includes('CONNECTED: NXDN TARGET') ||
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
            return true;
        }

        if (mode === 'TGIF' && status.includes('(TGIF)')) {
            return true;
        }
        
        if (mode === 'YSF' && status.includes('CONNECTED: YSF TARGET')) {
            return disconnectFirst ? false : true;
        }

        if (mode === 'DSTAR' && status.includes('CONNECTED: D-STAR TARGET')) {
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
        updateControlCenterWriteState();
        updateDashboardFavoritesWriteState();

        if (state.busy) {
            return;
        }

        if (!authAllowsActions()) {
            setButtonVisualState(els.connectButton, false);
            setButtonVisualState(els.disconnectButton, false);
            setButtonVisualState(els.disconnectAllButton, false);
            setButtonVisualState(els.disconnectDvSwitchButton, false);
            setButtonVisualState(els.saveFavoriteButton, false);
            updateDtmfButtonState();
            return;
        }

        setButtonVisualState(els.connectButton, shouldEnableConnectButton(statusText));
        setButtonVisualState(els.disconnectButton, shouldEnableDisconnectButton(statusText));
        setButtonVisualState(els.disconnectAllButton, shouldEnableDisconnectAllButton(statusText));
        setButtonVisualState(els.disconnectDvSwitchButton, shouldEnableDisconnectDvSwitchButton(statusText));
        updateDtmfButtonState();
    }

    function setBusy(isBusy) {
        state.busy = !!isBusy;
        updateControlCenterWriteState();

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

            if (els.sendDtmfButton) {
                els.sendDtmfButton.disabled = true;
                els.sendDtmfButton.style.opacity = '0.55';
                els.sendDtmfButton.style.cursor = 'wait';
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
                ? 'BrandMeister is a one-step connect. Enter or load a talkgroup and press CONNECT once. After BM is connected, you can change to another BM talkgroup by entering or loading the new talkgroup and pressing CONNECT again without disconnecting first. DISCONNECT removes the current BM receive session. DISCONNECT DVSWITCH removes only the configured DVSwitch link and stops BM receive mode if it is active. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next managed DVSwitch connect clears the earlier managed DVSwitch session first.'
                : 'BrandMeister is a one-step connect. Enter or load a talkgroup and press CONNECT once. After BM is connected, you can change to another BM talkgroup by entering or loading the new talkgroup and pressing CONNECT again without disconnecting first. DISCONNECT removes the current BM receive session. DISCONNECT DVSWITCH removes only the configured DVSwitch link and stops BM receive mode if it is active. DISCONNECT ALL does a full reset. With Disconnect before Connect off, BM can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'TGIF') {
            return disconnectFirst
                ? 'TGIF is a one-step connect. Enter or load a talkgroup and press CONNECT once. Wait for the status to confirm the connection. After TGIF is connected, you can change to another TGIF talkgroup by entering or loading the new talkgroup and pressing CONNECT again without disconnecting first. DISCONNECT removes the current TGIF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next managed DVSwitch connect clears the earlier managed DVSwitch session first.'
                : 'TGIF is a one-step connect. Enter or load a talkgroup and press CONNECT once. Wait for the status to confirm the connection. After TGIF is connected, you can change to another TGIF talkgroup by entering or loading the new talkgroup and pressing CONNECT again without disconnecting first. DISCONNECT removes the current TGIF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect off, TGIF can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'YSF') {
            return disconnectFirst
                ? 'YSF is a one-step connect. Enter or load the YSF target and press CONNECT once. Wait for the status to confirm the connection. DISCONNECT removes the current YSF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next managed connect clears earlier managed links first.'
                : 'YSF is a one-step connect. Enter or load the YSF target and press CONNECT once. Wait for the status to confirm the connection. DISCONNECT removes the current YSF connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect off, YSF can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'DSTAR') {
            return disconnectFirst
                ? 'D-Star is a one-step managed DVSwitch connect. Enter or load a D-Star target such as REF030EL and press CONNECT once. AllTune2 switches DVSwitch to DSTAR mode, tunes the target, and forces the private DVSwitch node link automatically. DISCONNECT removes the current D-Star connection. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.'
                : 'D-Star is a one-step managed DVSwitch connect. Enter or load a D-Star target such as REF030EL and press CONNECT once. AllTune2 switches DVSwitch to DSTAR mode, tunes the target, and forces the private DVSwitch node link automatically. With Disconnect before Connect off, D-Star can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'P25') {
            return disconnectFirst
                ? 'P25 is a one-step managed DVSwitch connect. Enter or load a P25 target and press CONNECT once. AllTune2 switches DVSwitch to P25 mode, tunes the target, and forces the private DVSwitch node link automatically. DISCONNECT clears the P25 tune path and returns DVSwitch to DMR mode. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.'
                : 'P25 is a one-step managed DVSwitch connect. Enter or load a P25 target and press CONNECT once. AllTune2 switches DVSwitch to P25 mode, tunes the target, and forces the private DVSwitch node link automatically. With Disconnect before Connect off, P25 can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'NXDN') {
            return disconnectFirst
                ? 'NXDN is a one-step managed DVSwitch connect. Enter or load an NXDN target and press CONNECT once. AllTune2 switches DVSwitch to NXDN mode, tunes the target, and forces the private DVSwitch node link automatically. DISCONNECT clears the NXDN tune path and returns DVSwitch to DMR mode. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.'
                : 'NXDN is a one-step managed DVSwitch connect. Enter or load an NXDN target and press CONNECT once. AllTune2 switches DVSwitch to NXDN mode, tunes the target, and forces the private DVSwitch node link automatically. With Disconnect before Connect off, NXDN can stay up while you add direct AllStarLink or EchoLink connections.';
        }

        if (mode === 'ASL') {
            return disconnectFirst
                ? 'AllStarLink is a one-step connect. Enter or load a node and press CONNECT once. Wait for the status to confirm the connection. Then you can connect another direct node if your settings allow it. DISCONNECT removes the most recent direct AllStarLink node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next CONNECT replaces earlier managed links first.'
                : 'AllStarLink is a one-step connect. Enter or load a node and press CONNECT once. Wait for the status to confirm the connection. Then you can add another direct AllStarLink node. DISCONNECT removes the most recent direct AllStarLink node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.';
        }

        if (mode === 'ECHO') {
            return disconnectFirst
                ? 'EchoLink uses the AllStarLink connect path. Enter the target as 3 plus the zero-padded 6-digit EchoLink node number, then press CONNECT once. Example: 1234 becomes 3001234. Wait for the status to confirm the connection. Then you can connect another direct node if your settings allow it. DISCONNECT removes the most recent direct node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset. With Disconnect before Connect on, the next CONNECT replaces earlier managed links first.'
                : 'EchoLink uses the AllStarLink connect path. Enter the target as 3 plus the zero-padded 6-digit EchoLink node number, then press CONNECT once. Example: 1234 becomes 3001234. Wait for the status to confirm the connection. Then you can add another direct node. DISCONNECT removes the most recent direct node. The small Disconnect button beside a listed node removes that specific node only. DISCONNECT DVSWITCH removes only the configured DVSwitch link. DISCONNECT ALL does a full reset.';
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

    function setStatusCardText(element, value, fallback, title = '') {
        if (!element) {
            return;
        }

        const text = String(value || fallback || '').trim();
        const finalText = text !== '' ? text : fallback;
        element.textContent = finalText;
        element.title = String(title || finalText || '').trim();
    }

    function applyKeyedStateToCard(element, keyed) {
        const box = element?.closest('.status-box');
        if (!box) {
            return;
        }

        const active = !!keyed;
        box.classList.toggle('keyed', active);

        if (active) {
            box.style.background = 'linear-gradient(90deg, #ff9500, #ff2d00)';
            box.style.borderColor = '#ff9500';
            box.style.boxShadow = '0 0 15px rgba(255, 149, 0, 0.55), 0 0 25px rgba(255, 45, 0, 0.45)';
            box.style.color = '#ffffff';

            const label = box.querySelector('.status-box-label');
            const value = box.querySelector('.status-box-value');

            if (label) {
                label.style.color = '#ffffff';
            }

            if (value) {
                value.style.color = '#ffffff';
            }
            return;
        }

        box.style.background = '';
        box.style.borderColor = '';
        box.style.boxShadow = '';
        box.style.color = '';

        const label = box.querySelector('.status-box-label');
        const value = box.querySelector('.status-box-value');

        if (label) {
            label.style.color = '';
        }

        if (value) {
            value.style.color = '';
        }
    }

    function favoritesSignature(items) {
        if (!Array.isArray(items)) {
            return '[]';
        }

        return JSON.stringify(items.map((item) => ({
            target: String(item?.target ?? item?.tg ?? ''),
            mode: normalizeMode(item?.mode ?? 'BM'),
            name: String(item?.name ?? ''),
            description: String(item?.description ?? item?.desc ?? '-'),
        })));
    }

    function renderFavorites(items, options = {}) {
        if (!els.favoritesBody) {
            return;
        }

        const normalizedItems = Array.isArray(items) ? items.slice() : [];
        const signature = favoritesSignature(normalizedItems);
        const force = !!options.force;

        if (!force && signature === state.favoritesSignature) {
            return;
        }

        state.favoritesSignature = signature;
        state.favoritesRaw = normalizedItems;

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
        updateDashboardFavoritesWriteState();
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

    function notePendingDisconnect(node, ttlMs = 6000) {
        const normalizedNode = String(node || '').trim();

        if (normalizedNode === '') {
            return;
        }

        state.pendingDisconnectNodes.set(normalizedNode, Date.now() + ttlMs);
        state.allstarLinksSignature = '';
    }

    function pendingDisconnectActive(node) {
        const normalizedNode = String(node || '').trim();

        if (normalizedNode === '') {
            return false;
        }

        const expiresAt = Number(state.pendingDisconnectNodes.get(normalizedNode) || 0);

        if (!expiresAt) {
            return false;
        }

        if (Date.now() > expiresAt) {
            state.pendingDisconnectNodes.delete(normalizedNode);
            state.allstarLinksSignature = '';
            return false;
        }

        return true;
    }

    function prunePendingDisconnectNodes(activeNodes) {
        const now = Date.now();

        state.pendingDisconnectNodes.forEach((expiresAt, node) => {
            if (now > Number(expiresAt || 0) || (activeNodes && !activeNodes.has(node))) {
                state.pendingDisconnectNodes.delete(node);
                state.allstarLinksSignature = '';
            }
        });
    }

    function renderAllstarLinks(allstarPayload, options = {}) {
        if (!els.statusAllstarLinks) {
            return;
        }

        const force = !!options.force;
        const rawLinks = Array.isArray(allstarPayload?.connected_nodes)
            ? allstarPayload.connected_nodes
            : [];
        const dvswitchNode = configuredDvSwitchNodeFromDom();

        const links = rawLinks.slice().sort((left, right) => {
            const leftNode = String(left?.node ?? left?.target ?? '').trim();
            const rightNode = String(right?.node ?? right?.target ?? '').trim();

            const leftIsDvSwitch = dvswitchNode !== '' && leftNode === dvswitchNode;
            const rightIsDvSwitch = dvswitchNode !== '' && rightNode === dvswitchNode;

            if (leftIsDvSwitch !== rightIsDvSwitch) {
                return leftIsDvSwitch ? -1 : 1;
            }

            return leftNode.localeCompare(rightNode, undefined, {
                numeric: true,
                sensitivity: 'base',
            });
        });

        const activeNodeSet = new Set(links.map((link) => String(link?.node ?? link?.target ?? '').trim()).filter(Boolean));
        prunePendingDisconnectNodes(activeNodeSet);

        const linksSignature = JSON.stringify(links.map((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            const modeLabel = String(link?.mode_label ?? link?.link_mode ?? link?.mode ?? 'Connected').trim();
            const isDvSwitchNode = dvswitchNode !== '' && rawNode === dvswitchNode;
            const isLocalMonitor = modeLabel.toLowerCase().includes('monitor');
            const keyedHoldSeconds = isDvSwitchNode ? 5 : 1;
            const active = (isDvSwitchNode || !isLocalMonitor) && linkLooksKeyed(link, keyedHoldSeconds);

            return {
                node: rawNode,
                mode: modeLabel,
                live: !!link?.is_live,
                active,
                pending: pendingDisconnectActive(rawNode),
            };
        }));

        if (!force && linksSignature === state.allstarLinksSignature) {
            return;
        }

        state.allstarLinksSignature = linksSignature;

        if (links.length === 0) {
            els.statusAllstarLinks.innerHTML = `
                <div class="allstar-links-empty">
                    No AllStarLink / EchoLink links detected.
                </div>
            `;
            return;
        }

        function normalizeLinkModeLabel(link) {
            const raw = String(link?.mode_label ?? link?.link_mode ?? link?.mode ?? 'Connected').trim();
            const normalized = raw.toLowerCase().replace(/[_-]+/g, ' ');

            if (normalized === 'transceive' || normalized === 'transceive mode') {
                return 'Transceive';
            }

            if (normalized === 'local monitor' || normalized === 'monitor' || normalized === 'local monitor mode') {
                return 'Local Monitor';
            }

            return raw !== '' ? raw : 'Connected';
        }

        function networkInfoForLink(link, rawNode, isDvSwitchNode) {
            if (isDvSwitchNode) {
                return {
                    label: 'DVSwitch',
                    sublabel: 'Private Link',
                    className: 'dvswitch',
                    description: 'Private DVSwitch audio link',
                    fullDescription: 'Private DVSwitch audio link',
                    canSaveFavorite: false,
                    favoriteMode: '',
                    favoriteName: '',
                    favoriteDescription: '',
                    isSavedFavorite: false,
                };
            }

            const numericNode = Number(rawNode);
            const looksEchoLink = Number.isFinite(numericNode) && numericNode >= 3000000;
            const favoriteMode = looksEchoLink ? 'ECHO' : 'ASL';
            const favorite = favoriteForModeTarget(favoriteMode, rawNode);
            const favoriteParts = favoriteDisplayParts(favorite);
            const payloadParts = payloadDisplayParts(link);
            const description = favoriteParts.full || payloadParts.full || (looksEchoLink ? 'EchoLink / direct node' : 'AllStarLink direct node');
            const favoriteName = favoriteParts.short || payloadParts.short || '';
            const favoriteDescription = favorite
                ? String(favorite.description ?? favorite.desc ?? '').trim()
                : (payloadParts.full || '');

            if (looksEchoLink) {
                return {
                    label: 'E/L',
                    sublabel: 'EchoLink',
                    className: 'echo',
                    description,
                    fullDescription: description,
                    canSaveFavorite: true,
                    favoriteMode,
                    favoriteName,
                    favoriteDescription,
                    isSavedFavorite: !!favorite,
                };
            }

            return {
                label: 'ASL',
                sublabel: 'AllStarLink',
                className: 'asl',
                description,
                fullDescription: description,
                canSaveFavorite: true,
                favoriteMode,
                favoriteName,
                favoriteDescription,
                isSavedFavorite: !!favorite,
            };
        }

        const bridgeAudioActive = dvswitchNode !== '' && links.some((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            return rawNode === dvswitchNode && linkLooksKeyed(link);
        });

        const externalBridgeAudioActive = dvswitchNode !== '' && links.some((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            const label = normalizeLinkModeLabel(link).toLowerCase();
            const isLocalMonitor = label.includes('monitor');

            return rawNode !== ''
                && rawNode !== dvswitchNode
                && !isLocalMonitor
                && linkLooksKeyed(link, 1);
        });

        const rows = links.map((link) => {
            const rawNode = String(link.node ?? link.target ?? '').trim();
            const node = escapeHtml(rawNode);
            const linkModeLabel = normalizeLinkModeLabel(link);
            const mode = escapeHtml(linkModeLabel);
            const elapsed = escapeHtml(String(link.elapsed ?? '').trim());
            const isLive = !!link.is_live;
            const isDvSwitchNode = dvswitchNode !== '' && rawNode === dvswitchNode;
            const isLocalMonitor = linkModeLabel.toLowerCase().includes('monitor');
            const keyedHoldSeconds = isDvSwitchNode ? 5 : 1;
            const rowKeyed = (isDvSwitchNode || !isLocalMonitor) && linkLooksKeyed(link, keyedHoldSeconds);
            const bridgeAudioForNode = bridgeAudioActive && !isDvSwitchNode && !isLocalMonitor;
            const bridgeAudioForDvSwitch = isDvSwitchNode && externalBridgeAudioActive;
            const rowActive = rowKeyed || bridgeAudioForNode || bridgeAudioForDvSwitch;
            const network = networkInfoForLink(link, rawNode, isDvSwitchNode);

            const liveLabel = isLive ? 'Live AMI' : 'Tracked';
            const keyedText = rowKeyed
                ? '<span class="connected-node-keyed">Audio Active</span>'
                : (bridgeAudioForDvSwitch
                    ? '<span class="connected-node-keyed">Bridge Audio Active</span>'
                    : (bridgeAudioForNode ? '<span class="connected-node-keyed">Audio via DVSwitch</span>' : ''));
            const elapsedText = elapsed !== ''
                ? `<span class="connected-node-meta-item">Connected ${elapsed}</span>`
                : '';

            const pendingDisconnect = pendingDisconnectActive(rawNode);
            const actionBlocked = !authAllowsActions();
            const disableDisconnectButton = actionBlocked || state.busy || pendingDisconnect;
            const actionHtml = isDvSwitchNode
                ? `
                    <button
                        type="button"
                        class="connected-node-button connected-node-button-dvswitch ${pendingDisconnect ? 'connected-node-button-pending' : ''}"
                        data-disconnect-dvswitch="${node}"
                        ${disableDisconnectButton ? 'disabled' : ''} ${authLoginRequired() ? 'title="Login required to control AllTune2"' : ''}
                    >
                        ${pendingDisconnect ? 'Disconnecting...' : 'Disconnect DVSwitch'}
                    </button>
                `
                : `
                    <button
                        type="button"
                        class="connected-node-button allstar-disconnect-button ${pendingDisconnect ? 'connected-node-button-pending' : ''}"
                        data-disconnect-node="${node}"
                        ${disableDisconnectButton ? 'disabled' : ''} ${authLoginRequired() ? 'title="Login required to control AllTune2"' : ''}
                    >
                        ${pendingDisconnect ? 'Disconnecting...' : `Disconnect ${node}`}
                    </button>
                `;

            const favoriteButtonHtml = network.canSaveFavorite
                ? `
                    <button
                        type="button"
                        class="connected-node-favorite-icon ${network.isSavedFavorite ? 'is-saved' : ''}"
                        data-connected-node-favorite="1"
                        data-favorite-node="${node}"
                        data-favorite-mode="${escapeHtml(network.favoriteMode)}"
                        data-favorite-name="${escapeHtml(network.favoriteName)}"
                        data-favorite-description="${escapeHtml(network.favoriteDescription)}"
                        aria-label="${network.isSavedFavorite ? 'Edit saved favorite' : 'Add connected node to favorites'}"
                        ${actionBlocked ? 'disabled title="Login required to save favorites"' : `title="${network.isSavedFavorite ? 'Edit saved favorite' : 'Add connected node to favorites'}"`}
                    >
                        ${network.isSavedFavorite ? '★' : '☆'}
                    </button>
                `
                : '';

            return `
                <div class="connected-node-card ${rowActive ? 'keyed' : ''} ${bridgeAudioForNode ? 'bridge-audio' : ''}" data-node="${node}" data-network="${escapeHtml(network.className)}">
                    <div class="connected-node-badge connected-node-badge-${escapeHtml(network.className)}">
                        <strong>${escapeHtml(network.label)}</strong>
                        <span>${escapeHtml(network.sublabel)}</span>
                    </div>

                    <div class="connected-node-main">
                        <div class="connected-node-title">Node ${node}${favoriteButtonHtml}</div>
                        <div class="connected-node-description" title="${escapeHtml(network.fullDescription || network.description)}">${escapeHtml(network.description)}</div>
                    </div>

                    <div class="connected-node-state">
                        <span class="connected-node-mode">${mode}</span>
                        <span class="connected-node-source">${escapeHtml(liveLabel)}</span>
                        ${elapsedText}
                        ${keyedText}
                    </div>

                    <div class="connected-node-actions">
                        ${actionHtml}
                    </div>
                </div>
            `;
        });

        els.statusAllstarLinks.innerHTML = `
            <div class="connected-nodes-header">
                <span>Connected Nodes</span>
                <span>${links.length} active</span>
            </div>
            <div class="connected-nodes-helper">
                Each row has its own disconnect action. The DVSwitch private link uses Disconnect DVSwitch.
            </div>
            <div class="connected-nodes-list">
                ${rows.join('')}
            </div>
        `;
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
        const dstar = payload.networks?.dstar || payload.dstar || null;
        const p25 = payload.networks?.p25 || payload.p25 || null;
        const nxdn = payload.networks?.nxdn || payload.nxdn || null;
        const allstar = payload.allstar || payload.networks?.allstar || null;

        const liveFavorites = Array.isArray(payload.favorites) ? payload.favorites : state.favoritesRaw;

        setStatusCardFromNetwork(els.statusBm, bm, 'BM', 'Idle', liveFavorites);
        applyKeyedStateToCard(els.statusBm, payloadModeLooksActive(bm) && dvswitchLinkLooksKeyed(allstar));

        setStatusCardFromNetwork(els.statusTgif, tgif, 'TGIF', 'Idle', liveFavorites);
        applyKeyedStateToCard(els.statusTgif, payloadModeLooksActive(tgif) && dvswitchLinkLooksKeyed(allstar));

        setStatusCardFromNetwork(els.statusYsf, ysf, 'YSF', 'Idle', liveFavorites);
        applyKeyedStateToCard(els.statusYsf, payloadModeLooksActive(ysf) && dvswitchLinkLooksKeyed(allstar));

        setStatusCardFromNetwork(els.statusDstar, dstar, 'DSTAR', 'Idle', liveFavorites);
        applyKeyedStateToCard(els.statusDstar, payloadModeLooksActive(dstar) && dvswitchLinkLooksKeyed(allstar));

        setStatusCardFromNetwork(els.statusP25, p25, 'P25', 'Idle', liveFavorites);
        applyKeyedStateToCard(els.statusP25, payloadModeLooksActive(p25) && dvswitchLinkLooksKeyed(allstar));

        setStatusCardFromNetwork(els.statusNxdn, nxdn, 'NXDN', 'Idle', liveFavorites);
        applyKeyedStateToCard(els.statusNxdn, payloadModeLooksActive(nxdn) && dvswitchLinkLooksKeyed(allstar));

        applyImmediateAllstarSnapshot(allstar);

        if (allowFieldSync && els.modeSelect && !state.busy && !userSelectionIsHeld()) {
            if (typeof payload.selected_mode === 'string') {
                setSelectedModeValue(payload.selected_mode);
            } else if (typeof system.selected_mode === 'string') {
                setSelectedModeValue(system.selected_mode);
            }
        }

        if (allowFieldSync && els.targetInput && !userIsEditingTarget() && !state.busy && !userSelectionIsHeld()) {
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

        syncAutoloadUiForMode((typeof payload.selected_mode === 'string' ? payload.selected_mode : system.selected_mode || els.modeSelect?.value || ''));

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

        const bm = payload.networks?.brandmeister || payload.brandmeister || null;
        const tgif = payload.networks?.tgif || payload.tgif || null;
        const ysf = payload.networks?.ysf || payload.ysf || null;
        const dstar = payload.networks?.dstar || payload.dstar || null;
        const p25 = payload.networks?.p25 || payload.p25 || null;
        const nxdn = payload.networks?.nxdn || payload.nxdn || null;
        const allstar = payload.allstar || payload.networks?.allstar || null;

        if (bm) {
            setStatusCardFromNetwork(els.statusBm, bm, 'BM', 'Idle');
            applyKeyedStateToCard(els.statusBm, payloadModeLooksActive(bm) && dvswitchLinkLooksKeyed(allstar));
        }

        if (tgif) {
            setStatusCardFromNetwork(els.statusTgif, tgif, 'TGIF', 'Idle');
            applyKeyedStateToCard(els.statusTgif, payloadModeLooksActive(tgif) && dvswitchLinkLooksKeyed(allstar));
        }

        if (ysf) {
            setStatusCardFromNetwork(els.statusYsf, ysf, 'YSF', 'Idle');
            applyKeyedStateToCard(els.statusYsf, payloadModeLooksActive(ysf) && dvswitchLinkLooksKeyed(allstar));
        }

        if (dstar) {
            setStatusCardFromNetwork(els.statusDstar, dstar, 'DSTAR', 'Idle');
            applyKeyedStateToCard(els.statusDstar, payloadModeLooksActive(dstar) && dvswitchLinkLooksKeyed(allstar));
        }

        if (p25) {
            setStatusCardFromNetwork(els.statusP25, p25, 'P25', 'Idle');
            applyKeyedStateToCard(els.statusP25, payloadModeLooksActive(p25) && dvswitchLinkLooksKeyed(allstar));
        }

        if (nxdn) {
            setStatusCardFromNetwork(els.statusNxdn, nxdn, 'NXDN', 'Idle');
            applyKeyedStateToCard(els.statusNxdn, payloadModeLooksActive(nxdn) && dvswitchLinkLooksKeyed(allstar));
        }

        if (allstar) {
            applyImmediateAllstarSnapshot(allstar);
        }

        syncAutoloadUiForMode((typeof payload.selected_mode === 'string' ? payload.selected_mode : system.selected_mode || els.modeSelect?.value || ''));

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
        const method = String(options.method || 'GET').toUpperCase();
        const headers = {
            Accept: 'application/json',
            ...(options.headers || {}),
        };

        if (method !== 'GET' && state.auth.csrfToken !== '') {
            headers['X-CSRF-Token'] = state.auth.csrfToken;
        }

        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers,
        });

        const text = await response.text();
        let payload = {};

        if (text !== '') {
            try {
                payload = JSON.parse(text);
            } catch (error) {
                payload = { ok: false, message: text };
            }
        }

        if (!response.ok) {
            const message = payload?.message || `Request failed with status ${response.status}`;
            const error = new Error(message);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    }

    async function loadStatus() {
        const payload = await requestJson(state.endpoints.status, {
            method: 'GET',
        });

        applyLiveStatus(payload, { allowFieldSync: false });
        return payload;
    }

    function clearQuickStatusRefreshes() {
        state.quickStatusTimers.forEach((timer) => {
            window.clearTimeout(timer);
        });

        state.quickStatusTimers = [];
    }

    function queueStatusRefresh(delayMs) {
        const timer = window.setTimeout(() => {
            state.quickStatusTimers = state.quickStatusTimers.filter((item) => item !== timer);

            if (state.busy) {
                return;
            }

            loadStatus().catch((error) => {
                console.error(error);
                setSystemStatus('ERROR: STATUS UNAVAILABLE');
                updateActivityValue('Current Status', 'ERROR: STATUS UNAVAILABLE');
            });
        }, delayMs);

        state.quickStatusTimers.push(timer);
    }

    function refreshStatusInBackground() {
        queueStatusRefresh(0);
    }

    function refreshStatusSoonAfterAction() {
        clearQuickStatusRefreshes();

        // Normal polling stays moderate at 2000 ms. This short burst only runs after user actions.
        [150, 600, 1200, 1900].forEach((delayMs) => {
            queueStatusRefresh(delayMs);
        });
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

        if (!authAllowsActions()) {
            setSystemStatus(loginRequiredMessage());
            updateActivityValue('Current Status', loginRequiredMessage());
            updateButtonsFromStatus(currentStatusText());
            return;
        }

        if (action === 'connect' && !modeIsConfigured(els.modeSelect.value)) {
            updateHelperText();
            updateButtonsFromStatus(currentStatusText());
            return;
        }

        state.lastRequestedUiMode = currentSelectedMode();
        rememberPreferredAslUiMode(state.lastRequestedUiMode);

        const currentMode = currentSelectedMode();
        const forcedAutoload = modeForcesDvSwitch(currentMode);

        const payload = {
            action,
            action_type: action,
            target: els.targetInput.value.trim(),
            tgNum: els.targetInput.value.trim(),
            mode: modeRequestValue(els.modeSelect.value),
            ui_mode: currentMode,
            autoload_dvswitch: forcedAutoload ? 1 : (els.autoloadCheckbox.checked ? 1 : 0),
            autoload_dvswitch_mode: normalizeAutoloadMode(els.autoloadModeSelect.value),
            disconnect_before_connect: els.disconnectBeforeConnectCheckbox.checked ? 1 : 0,
            ...extraPayload,
        };
        const useDirectEndpoint = shouldUseDirectEndpoint(action, payload);
        const endpoint = useDirectEndpoint ? state.endpoints.direct : state.endpoints.connect;

        let busyReleasedEarly = false;
        setBusy(true);

        try {
            const responsePayload = await requestJson(endpoint, {
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

            const uiPayload = useDirectEndpoint && (
                action === 'connect' ||
                action === 'disconnect' ||
                action === 'disconnect_selected'
            )
                ? withoutAllstarSnapshot(responsePayload)
                : responsePayload;

            applyActionStatus(
                uiPayload,
                action === 'send_dtmf'
                    ? { preserveTarget: true, preserveMode: true }
                    : {}
            );

            if (action === 'send_dtmf') {
                const dtmfStatusText = responsePayload.status_text || responsePayload.status || responsePayload.last_status || '';

                if (els.dtmfCode && !isErrorStatus(dtmfStatusText)) {
                    els.dtmfCode.value = '';
                    els.dtmfCode.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else if (action === 'disconnect_all') {
                markAudioSettleWindow(1500);
            } else {
                const actionStatusText = responsePayload.status_text || responsePayload.status || responsePayload.last_status || '';
                const actionStatusUpper = String(actionStatusText || '').toUpperCase();

                /*
                 * TGIFD now returns as soon as the backend is active so the dashboard
                 * can sync quickly. Its helper still refreshes the private-node link
                 * briefly in the background. Suppress that expected link flutter so a
                 * TGIF connect/retune does not announce a false disconnect.
                 */
                const audioSettleMs = (
                    action === 'connect' &&
                    /^CONNECTED:\s+TG\s+/i.test(String(actionStatusText || '')) &&
                    actionStatusUpper.includes('(TGIF)')
                ) ? 6500 : 1200;

                markAudioSettleWindow(audioSettleMs);
                announceImmediateActionAudio(actionStatusText);
            }

            setBusy(false);
            busyReleasedEarly = true;
            updateDtmfButtonState();

            if (action !== 'send_dtmf') {
                refreshStatusSoonAfterAction();
            }
        } catch (error) {
            console.error(error);
            const message = error?.payload?.auth_required
                ? loginRequiredMessage()
                : (error?.payload?.csrf_failed ? 'SECURITY CHECK FAILED - REFRESH AND TRY AGAIN' : 'ERROR: REQUEST FAILED');
            setSystemStatus(message);
            updateActivityValue('Current Status', message);
        } finally {
            if (!busyReleasedEarly) {
                setBusy(false);
            }
            state.lastRequestedUiMode = '';
            updateDtmfButtonState();
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

    function sendDtmf() {
        if (!els.dtmfCode) {
            return;
        }

        const code = currentDtmfValue();
        els.dtmfCode.value = code;

        if (code === '' || state.busy) {
            updateDtmfButtonState();
            return;
        }

        sendAction('send_dtmf', {
            target: '',
            tgNum: '',
            dtmf_code: code,
            dtmf: code,
            digits: code,
        });
    }

    function wireAllstarDisconnectButtons() {
        if (!els.statusAllstarLinks) {
            return;
        }

        els.statusAllstarLinks.addEventListener('click', (event) => {
            const dvswitchButton = event.target.closest('[data-disconnect-dvswitch]');
            if (dvswitchButton) {
                if (!authAllowsActions()) {
                    setSystemStatus(loginRequiredMessage());
                    updateActivityValue('Current Status', loginRequiredMessage());
                    updateButtonsFromStatus(currentStatusText());
                    return;
                }

                if (state.busy || dvswitchButton.disabled) {
                    return;
                }

                const selectedDvSwitchNode = String(
                    dvswitchButton.getAttribute('data-disconnect-dvswitch') ||
                    configuredDvSwitchNodeFromDom() ||
                    ''
                ).trim();

                if (selectedDvSwitchNode !== '') {
                    notePendingDisconnect(selectedDvSwitchNode);
                }

                dvswitchButton.disabled = true;
                dvswitchButton.classList.add('connected-node-button-pending');
                dvswitchButton.textContent = 'Disconnecting...';

                sendAction('disconnect_dvswitch', {
                    target: '',
                    tgNum: '',
                });
                return;
            }

            const button = event.target.closest('[data-disconnect-node]');
            if (!button) {
                return;
            }

            if (!authAllowsActions()) {
                setSystemStatus(loginRequiredMessage());
                updateActivityValue('Current Status', loginRequiredMessage());
                updateButtonsFromStatus(currentStatusText());
                return;
            }

            if (state.busy || button.disabled) {
                return;
            }

            const selectedNode = String(button.getAttribute('data-disconnect-node') || '').trim();
            if (!selectedNode) {
                return;
            }

            notePendingDisconnect(selectedNode);

            button.disabled = true;
            button.classList.add('connected-node-button-pending');
            button.textContent = 'Disconnecting...';

            const card = button.closest('.connected-node-card');
            if (card) {
                card.classList.add('disconnecting');
            }

            sendAction('disconnect_selected', {
                selected_node: selectedNode,
                target: selectedNode,
                tgNum: selectedNode,
            });
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

            renderFavorites(state.favoritesRaw, { force: true });
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

            if (!authAllowsActions()) {
                setSystemStatus(loginRequiredMessage());
                updateActivityValue('Current Status', loginRequiredMessage());
                updateButtonsFromStatus(currentStatusText());
                updateDashboardFavoritesWriteState();
                return;
            }

            const target = row.getAttribute('data-target') || '';
            const mode = normalizeMode(row.getAttribute('data-mode') || 'BM');

            holdUserSelection();
            els.targetInput.value = target;
            setSelectedModeValue(mode);
            updateHelperText();
            updateButtonsFromStatus(currentStatusText());

        });
    }


    function setSaveFavoriteMessage(message, type = '') {
        if (!els.saveFavoriteMessage) {
            return;
        }

        els.saveFavoriteMessage.textContent = message || '';
        els.saveFavoriteMessage.classList.remove('success', 'error');

        if (type) {
            els.saveFavoriteMessage.classList.add(type);
        }
    }

    function currentModeDisplayLabel() {
        if (!els.modeSelect) {
            return favoriteModeLabel('BM');
        }

        const selectedOption = els.modeSelect.options[els.modeSelect.selectedIndex];

        if (selectedOption && selectedOption.textContent.trim() !== '') {
            return selectedOption.textContent.trim();
        }

        return favoriteModeLabel(els.modeSelect.value || 'BM');
    }

    function defaultFavoriteName(target, mode) {
        const normalizedMode = normalizeMode(mode);

        if (normalizedMode === 'BM') {
            return `Local BM TG ${target}`;
        }

        if (normalizedMode === 'TGIF') {
            return `TGIF TG ${target}`;
        }

        if (normalizedMode === 'YSF') {
            return `YSF ${target}`;
        }

        if (normalizedMode === 'ASL') {
            return `AllStar ${target}`;
        }

        if (normalizedMode === 'ECHO') {
            return `EchoLink ${target}`;
        }

        if (['DSTAR', 'P25', 'NXDN'].includes(normalizedMode)) {
            return `${favoriteModeLabel(normalizedMode)} ${target}`;
        }

        return target;
    }

    function findExistingFavorite(target, mode) {
        const normalizedTarget = String(target || '').trim();
        const normalizedMode = normalizeMode(mode || 'BM');

        if (normalizedTarget === '' || !Array.isArray(state.favoritesRaw)) {
            return null;
        }

        return state.favoritesRaw.find((favorite) => (
            String(favorite?.target ?? favorite?.tg ?? '').trim() === normalizedTarget
            && normalizeMode(favorite?.mode ?? 'BM') === normalizedMode
        )) || null;
    }

    function openSaveFavoriteModal(prefill = null) {
        if (
            !els.saveFavoriteModal ||
            !els.saveFavoriteName ||
            !els.saveFavoriteDescription ||
            !els.saveFavoriteTargetValue ||
            !els.saveFavoriteModeValue
        ) {
            return;
        }

        if (!authAllowsActions()) {
            setSystemStatus(loginRequiredMessage());
            return;
        }

        const hasPrefill = prefill && typeof prefill === 'object' && !(prefill instanceof Event);
        const target = hasPrefill
            ? String(prefill.target || '').trim()
            : String(els.targetInput?.value || '').trim();
        const mode = normalizeMode(hasPrefill
            ? String(prefill.mode || 'BM')
            : String(els.modeSelect?.value || 'BM'));

        if (target === '') {
            setSaveFavoriteMessage('Enter or load a TG / node before saving a favorite.', 'error');
            return;
        }

        state.saveFavoriteTargetOverride = hasPrefill ? target : '';
        state.saveFavoriteModeOverride = hasPrefill ? mode : '';

        const existingFavorite = findExistingFavorite(target, mode);
        const prefillName = hasPrefill ? String(prefill.name || '').trim() : '';
        const prefillDescription = hasPrefill ? String(prefill.description || '').trim() : '';

        els.saveFavoriteTargetValue.textContent = target;
        els.saveFavoriteModeValue.textContent = hasPrefill ? favoriteModeLabel(mode) : currentModeDisplayLabel();

        if (existingFavorite) {
            els.saveFavoriteName.value = String(existingFavorite.name ?? '');
            els.saveFavoriteName.placeholder = defaultFavoriteName(target, mode);
            els.saveFavoriteDescription.value = String(existingFavorite.description ?? existingFavorite.desc ?? '');
            els.saveFavoriteDescription.placeholder = 'Quick access favorite';
            setSaveFavoriteMessage('Existing favorite found. Saving will update it.', 'success');

            if (els.saveFavoriteSubmit) {
                els.saveFavoriteSubmit.textContent = 'Update Favorite';
            }
        } else {
            els.saveFavoriteName.value = prefillName;
            els.saveFavoriteName.placeholder = defaultFavoriteName(target, mode);
            els.saveFavoriteDescription.value = prefillDescription;
            els.saveFavoriteDescription.placeholder = 'Quick access favorite';
            setSaveFavoriteMessage(hasPrefill ? 'Review and save this connected node as a favorite.' : '');

            if (els.saveFavoriteSubmit) {
                els.saveFavoriteSubmit.textContent = 'Save Favorite';
            }
        }

        els.saveFavoriteModal.hidden = false;
        els.saveFavoriteModal.setAttribute('aria-hidden', 'false');

        requestAnimationFrame(() => {
            els.saveFavoriteName.focus();
            els.saveFavoriteName.select();
        });
    }

    function closeSaveFavoriteModal() {
        state.saveFavoriteTargetOverride = '';
        state.saveFavoriteModeOverride = '';

        if (!els.saveFavoriteModal) {
            return;
        }

        els.saveFavoriteModal.hidden = true;
        els.saveFavoriteModal.setAttribute('aria-hidden', 'true');
        setSaveFavoriteMessage('');

        if (els.saveFavoriteButton) {
            els.saveFavoriteButton.focus();
        }
    }

    async function submitSaveFavorite() {
        if (
            !els.targetInput ||
            !els.modeSelect ||
            !els.saveFavoriteName ||
            !els.saveFavoriteDescription ||
            !els.saveFavoriteSubmit
        ) {
            return;
        }

        const target = String(state.saveFavoriteTargetOverride || els.targetInput.value || '').trim();
        const mode = normalizeMode(state.saveFavoriteModeOverride || els.modeSelect.value || 'BM');
        const name = String(els.saveFavoriteName.value || '').trim();
        const description = String(els.saveFavoriteDescription.value || '').trim();

        if (target === '') {
            setSaveFavoriteMessage('Enter a TG / node / target before saving.', 'error');
            return;
        }

        els.saveFavoriteSubmit.disabled = true;
        setSaveFavoriteMessage('Saving favorite...');

        try {
            const body = new URLSearchParams();
            body.set('action', 'save');
            body.set('target', target);
            body.set('mode', mode);
            body.set('name', name);
            body.set('description', description);

            const payload = await requestJson(state.endpoints.favorites, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body,
            });

            if (!payload || payload.ok !== true) {
                throw new Error(payload?.message || 'Favorite save failed.');
            }

            if (Array.isArray(payload.favorites)) {
                state.favoritesSignature = '';
                renderFavorites(payload.favorites, { force: true });
            } else {
                await loadStatus();
            }

            setSaveFavoriteMessage(payload.message || (payload.updated ? 'Favorite updated.' : 'Favorite saved.'), 'success');
            refreshStatusInBackground();

            window.setTimeout(() => {
                closeSaveFavoriteModal();
            }, 650);
        } catch (error) {
            console.error(error);
            const message = error?.payload?.auth_required ? loginRequiredMessage() : (error.message || 'Unable to save favorite.');
            setSaveFavoriteMessage(message, 'error');
        } finally {
            els.saveFavoriteSubmit.disabled = false;
        }
    }

    function wireSaveFavoriteModal() {
        if (!els.saveFavoriteButton || !els.saveFavoriteModal) {
            return;
        }

        els.saveFavoriteButton.addEventListener('click', () => openSaveFavoriteModal());

        if (els.statusAllstarLinks) {
            els.statusAllstarLinks.addEventListener('click', (event) => {
                const button = event.target.closest('.connected-node-favorite-icon');

                if (!button) {
                    return;
                }

                event.preventDefault();

                if (button.disabled || !authAllowsActions()) {
                    setSystemStatus(loginRequiredMessage());
                    return;
                }

                openSaveFavoriteModal({
                    target: button.getAttribute('data-favorite-node') || '',
                    mode: button.getAttribute('data-favorite-mode') || 'ASL',
                    name: button.getAttribute('data-favorite-name') || '',
                    description: button.getAttribute('data-favorite-description') || '',
                });
            });
        }

        if (els.saveFavoriteForm) {
            els.saveFavoriteForm.addEventListener('submit', (event) => {
                event.preventDefault();
                submitSaveFavorite();
            });
        }

        if (els.saveFavoriteClose) {
            els.saveFavoriteClose.addEventListener('click', closeSaveFavoriteModal);
        }

        if (els.saveFavoriteCancel) {
            els.saveFavoriteCancel.addEventListener('click', closeSaveFavoriteModal);
        }

        els.saveFavoriteModal.addEventListener('click', (event) => {
            if (event.target === els.saveFavoriteModal) {
                closeSaveFavoriteModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !els.saveFavoriteModal.hidden) {
                closeSaveFavoriteModal();
            }
        });
    }

    function startPolling() {
        if (state.pollTimer) {
            window.clearTimeout(state.pollTimer);
        }

        const runPoll = async () => {
            if (!state.busy) {
                try {
                    await loadStatus();
                } catch (error) {
                    console.error(error);
                    setSystemStatus('ERROR: STATUS UNAVAILABLE');
                    updateActivityValue('Current Status', 'ERROR: STATUS UNAVAILABLE');
                }
            }

            const delay = currentDirectConnectedNodeCount() > 0
                ? state.fastPollIntervalMs
                : state.pollIntervalMs;

            state.pollTimer = window.setTimeout(runPoll, delay);
        };

        state.pollTimer = window.setTimeout(runPoll, state.pollIntervalMs);
    }

    function init() {
        if (!hasCoreElements()) {
            return;
        }

        rememberPreferredAslUiMode(currentSelectedMode());
        updateControlCenterWriteState();
        updateDashboardFavoritesWriteState();

        if (els.modeSelect) {
            els.modeSelect.addEventListener('change', () => {
                holdUserSelection();
                rememberPreferredAslUiMode(els.modeSelect.value);
                syncAutoloadUiForMode(els.modeSelect.value);
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
                cancelSpeechQueue();
                markAudioSettleWindow(1500);
                sendAction('disconnect_all');
            });
        }

        if (els.disconnectDvSwitchButton) {
            els.disconnectDvSwitchButton.addEventListener('click', () => {
                sendAction('disconnect_dvswitch');
            });
        }

        if (els.autoloadCheckbox) {
            els.autoloadCheckbox.addEventListener('change', () => {
                if (!modeForcesDvSwitch(currentSelectedMode())) {
                    state.manualAutoloadPreference = !!els.autoloadCheckbox.checked;
                }
                rememberPreferences();
            });
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

        if (els.dtmfCode) {
            const syncDtmfField = () => {
                const clean = sanitizeDtmf(els.dtmfCode.value);
                if (els.dtmfCode.value !== clean) {
                    els.dtmfCode.value = clean;
                }
                updateDtmfButtonState();
            };

            els.dtmfCode.addEventListener('input', syncDtmfField);
            els.dtmfCode.addEventListener('change', syncDtmfField);
            els.dtmfCode.addEventListener('blur', syncDtmfField);
            els.dtmfCode.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    sendDtmf();
                }
            });

            syncDtmfField();
        }

        if (els.sendDtmfButton) {
            els.sendDtmfButton.addEventListener('click', () => {
                sendDtmf();
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
        wireSaveFavoriteModal();
        loadAudioAlertsPreference();
        if (els.autoloadCheckbox) {
            state.manualAutoloadPreference = !!els.autoloadCheckbox.checked;
        }
        syncAutoloadUiForMode(currentSelectedMode());
        updateHelperText();
        updateDtmfButtonState();
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

/* Saved Favorites: reset list scroll position after using sort headers */
document.addEventListener('click', function (event) {
    const sortButton = event.target.closest('.favorites-sort-button');

    if (!sortButton) {
        return;
    }

    window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
            const section = sortButton.closest('.favorites-section') || document;
            const scrollTargets = section.querySelectorAll(
                '.favorites-table-wrap, .favorites-card .card-body-tight'
            );

            scrollTargets.forEach(function (target) {
                if (target && target.scrollHeight > target.clientHeight) {
                    target.scrollTop = 0;
                }
            });
        });
    });
});
