
(() => {
    'use strict';

    const state = {
        busy: false,
        pollTimer: null,
        quickStatusTimers: [],
        statusRequest: null,
        pollIntervalMs: 1000,
        fastPollIntervalMs: 650,
        dvswitchKeyedHoldSeconds: 2,
        lastRequestedUiMode: '',
        userSelectionHoldUntil: 0,
        cachedDvSwitchNode: '',
        preferredAslUiMode: 'ASL',
        favoriteSortPreset: 'mode-target',
        favoriteSortKey: 'mode',
        favoriteSortDirection: 'asc',
        favoriteSortType: 'text',
        favoriteSearchQuery: '',
        favoritesRaw: [],
        saveFavoriteTargetOverride: '',
        saveFavoriteModeOverride: '',
        favoritesSignature: '',
        allstarLinksSignature: '',
        pendingDisconnectNodes: new Map(),
        pendingModeSwitches: new Map(),
        audioAlertsEnabled: true,
        audioStateInitialized: false,
        previousConnectedNodes: [],
        muteAudioAnnouncements: false,
        audioSettleUntil: 0,
        directStatusCorrectionHoldUntil: 0,
        actionStatusHoldText: '',
        actionStatusHoldUntil: 0,
        recentAudioEvents: new Map(),
        immediateAudioEvents: new Map(),
        lastCorrectedDirectDisconnectSignature: '',
        lastAllstarPayload: null,
        activeManagedDvSwitchMode: '',
        activeManagedDvSwitchTarget: '',
        managedConnectionSignature: '',
        managedConnectionAudioInitialized: false,
        managedConnectionMissingSince: 0,
        pendingPrivateDisconnectTimer: null,
        privateLinkReconnectPending: false,
        activeModePointerNode: '',
        activeModePointerTimer: null,
        manualAutoloadPreference: null,
        privateNodeLossCleanupInFlight: false,
        privateNodeLossCleanupDone: false,
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
        favoritesSearch: document.getElementById('favorites-search'),
        favoritesSearchClear: document.getElementById('favorites-search-clear'),
        favoritesSortSelect: document.getElementById('favorites-sort-select'),
        favoritesSortDirection: document.getElementById('favorites-sort-direction'),
        favoritesResultCount: document.getElementById('favorites-result-count'),
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

    function markDirectStatusCorrectionHold(milliseconds) {
        const until = Date.now() + Math.max(0, milliseconds);
        state.directStatusCorrectionHoldUntil = Math.max(state.directStatusCorrectionHoldUntil, until);
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

    function audioSpeechLabelForNodeId(node) {
        const normalizedNode = String(node || '').trim();
        if (normalizedNode === '') {
            return '';
        }

        if (normalizedNode.startsWith('iax-channel:')) {
            return 'IAX client';
        }

        return `Node ${formatNodeForSpeech(normalizedNode)}`;
    }

    function announceNodeConnected(node) {
        const normalizedNode = String(node || '').trim();
        const speechLabel = audioSpeechLabelForNodeId(normalizedNode);
        if (speechLabel === '') {
            return;
        }

        speakAudioAlert(
            `${speechLabel} has connected`,
            `connect:${normalizedNode}`
        );
    }

    function announceNodeDisconnected(node) {
        const normalizedNode = String(node || '').trim();
        const speechLabel = audioSpeechLabelForNodeId(normalizedNode);
        if (speechLabel === '') {
            return;
        }

        speakAudioAlert(
            `${speechLabel} has disconnected`,
            `disconnect:${normalizedNode}`
        );
    }

    function connectionTypeForLink(link) {
        return String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
    }

    function iaxChannelForAudioLink(link) {
        return String(link?.iax_channel ?? link?.asterisk_channel ?? link?.channel ?? '').trim();
    }

    function audioNodeIdForLink(link) {
        const connectionType = connectionTypeForLink(link);
        const channel = iaxChannelForAudioLink(link);

        if (connectionType === 'iax_channel' && channel !== '') {
            return `iax-channel:${channel}`;
        }

        return String(link?.node ?? link?.target ?? '').trim();
    }

    function connectedNodeListFromPayload(allstarPayload) {
        const connected = Array.isArray(allstarPayload?.connected_nodes)
            ? allstarPayload.connected_nodes
            : [];

        const seen = new Set();
        const nodes = [];

        connected.forEach((item) => {
            const node = audioNodeIdForLink(item);
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

        return links.some((link) => String(link?.node ?? '').trim() === dvswitchNode && linkLooksKeyed(link, state.dvswitchKeyedHoldSeconds));
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


    function directStatusDetails(statusText) {
        const normalized = normalizeStatusText(statusText);
        const match = normalized.match(/^(CONNECTED|DISCONNECTED):\s+(ALLSTAR NODE|ECHOLINK NODE)\s+(\d{3,})/i);

        if (!match) {
            return null;
        }

        return {
            state: String(match[1] || '').toUpperCase(),
            label: String(match[2] || '').toUpperCase(),
            node: String(match[3] || '').trim(),
        };
    }

    function liveAllstarNodeSet(allstarPayload) {
        return new Set(connectedNodeListFromPayload(allstarPayload));
    }

    function correctDirectStatusFromLive(statusText, allstarPayload) {
        const details = directStatusDetails(statusText);

        if (!details || details.state !== 'CONNECTED' || details.node === '' || !allstarPayload || typeof allstarPayload !== 'object') {
            return {
                statusText: normalizeStatusText(statusText),
                corrected: false,
                node: '',
            };
        }

        if (Date.now() < state.directStatusCorrectionHoldUntil) {
            return {
                statusText: normalizeStatusText(statusText),
                corrected: false,
                node: '',
            };
        }

        const liveNodes = liveAllstarNodeSet(allstarPayload);
        const signature = `disconnect:${details.node}`;

        if (liveNodes.has(details.node)) {
            if (state.lastCorrectedDirectDisconnectSignature === signature) {
                state.lastCorrectedDirectDisconnectSignature = '';
            }

            return {
                statusText: normalizeStatusText(statusText),
                corrected: false,
                node: '',
            };
        }

        return {
            statusText: `DISCONNECTED: ${details.label} ${details.node}`,
            corrected: true,
            node: details.node,
        };
    }

    function announceCorrectedDirectDisconnect(correction, previousStatusText = '') {
        if (!correction || !correction.corrected || String(correction.node || '').trim() === '') {
            return;
        }

        const correctedStatusText = normalizeStatusText(correction.statusText || '');
        if (correctedStatusText !== '' && normalizeStatusText(previousStatusText) === correctedStatusText) {
            return;
        }

        const node = String(correction.node || '').trim();
        const signature = `disconnect:${node}`;

        markImmediateAudioEvent(signature);
        announceNodeDisconnected(node);
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

    function clearPendingPrivateDisconnectAlert() {
        if (state.pendingPrivateDisconnectTimer) {
            window.clearTimeout(state.pendingPrivateDisconnectTimer);
            state.pendingPrivateDisconnectTimer = null;
        }
    }

    function managedConnectionSnapshot(payload, system, networks) {
        const mode = normalizeMode(
            payload?.managed_dvswitch_mode ?? system?.managed_dvswitch_mode ?? ''
        );
        let target = String(
            payload?.managed_dvswitch_target ?? system?.managed_dvswitch_target ?? ''
        ).trim();

        if (mode === 'BM' || mode === 'TGIF') {
            target = String(
                payload?.dmr_active_target ?? system?.dmr_active_target ?? target
            ).trim();
        }

        const networkByMode = {
            BM: networks?.brandmeister,
            TGIF: networks?.tgif,
            YSF: networks?.ysf,
            DSTAR: networks?.dstar,
            P25: networks?.p25,
            NXDN: networks?.nxdn,
        };
        const network = networkByMode[mode] || null;
        const privateLinked = system?.private_node_linked !== false;
        const active = modeForcesDvSwitch(mode)
            && target !== ''
            && privateLinked
            && (payloadModeLooksActive(network) || !!system?.dvswitch_link_active);

        if (!active) {
            return { signature: '', mode: '', target: '' };
        }

        return {
            signature: `${mode}:${target}`,
            mode,
            target,
        };
    }

    function syncManagedConnectionAudio(payload, system, networks) {
        const snapshot = managedConnectionSnapshot(payload, system, networks);
        const now = Date.now();

        if (!state.managedConnectionAudioInitialized) {
            state.managedConnectionSignature = snapshot.signature;
            state.managedConnectionAudioInitialized = true;
            state.managedConnectionMissingSince = snapshot.signature === '' ? now : 0;
            return;
        }

        if (snapshot.signature === '') {
            if (!state.managedConnectionMissingSince) {
                state.managedConnectionMissingSince = now;
            }

            if (now - state.managedConnectionMissingSince >= 3500) {
                state.managedConnectionSignature = '';
            }
            return;
        }

        state.managedConnectionMissingSince = 0;
        clearPendingPrivateDisconnectAlert();

        if (snapshot.signature !== state.managedConnectionSignature) {
            state.managedConnectionSignature = snapshot.signature;
            const dvswitchNode = configuredDvSwitchNodeFromDom();
            const signature = `connect:${dvswitchNode}`;
            if (dvswitchNode !== '' && !shouldSuppressImmediateFollowup(signature)) {
                announceNodeConnected(dvswitchNode);
            }
        }
    }

    function schedulePrivateDisconnectAlert(node) {
        clearPendingPrivateDisconnectAlert();
        const normalizedNode = String(node || '').trim();
        if (normalizedNode === '') {
            return;
        }

        state.privateLinkReconnectPending = true;
        state.pendingPrivateDisconnectTimer = window.setTimeout(() => {
            state.pendingPrivateDisconnectTimer = null;
            const liveNodes = connectedNodeListFromPayload(state.lastAllstarPayload);
            if (liveNodes.includes(normalizedNode)) {
                return;
            }

            /*
             * A real managed disconnect clears the stable managed signature
             * after the normal status grace period. During a target change or
             * private-node mode switch, the signature remains live (or is
             * replaced by the new target), so do not announce the temporary
             * private-link teardown.
             */
            if (state.managedConnectionSignature !== '') {
                return;
            }

            state.privateLinkReconnectPending = false;
            const signature = `disconnect:${normalizedNode}`;
            if (!shouldSuppressImmediateFollowup(signature)) {
                announceNodeDisconnected(normalizedNode);
            }
        }, 4500);
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

        const dvswitchNode = configuredDvSwitchNodeFromDom();

        addedNodes.forEach((node) => {
            const normalizedNode = String(node || '').trim();
            const resumedManagedPrivateLink = normalizedNode === dvswitchNode
                && state.privateLinkReconnectPending
                && state.managedConnectionSignature !== '';

            if (normalizedNode === dvswitchNode) {
                clearPendingPrivateDisconnectAlert();
                state.privateLinkReconnectPending = false;
            }

            /*
             * A private-node mode change briefly removes and restores the same
             * link. That is not a new network connection and should remain
             * silent. A real network/target change is announced separately by
             * syncManagedConnectionAudio().
             */
            if (resumedManagedPrivateLink) {
                return;
            }

            const signature = `connect:${normalizedNode}`;
            if (shouldSuppressImmediateFollowup(signature)) {
                return;
            }

            announceNodeConnected(normalizedNode);
        });

        removedNodes.forEach((node) => {
            const normalizedNode = String(node || '').trim();
            if (normalizedNode === dvswitchNode) {
                schedulePrivateDisconnectAlert(normalizedNode);
                return;
            }

            const signature = `disconnect:${normalizedNode}`;
            if (shouldSuppressImmediateFollowup(signature)) {
                return;
            }

            announceNodeDisconnected(normalizedNode);
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

        if (action === 'disconnect_selected' || action === 'disconnect_live_client' || action === 'disconnect_iax_channel') {
            return true;
        }

        if (action === 'disconnect') {
            return currentDirectConnectedNodeCount() > 0;
        }

        return false;
    }



    function allstarLinksFromPayload(payload) {
        const allstar = payload?.allstar || payload?.networks?.allstar || null;
        const links = allstar?.connected_nodes;
        return Array.isArray(links) ? links : [];
    }

    function inboundAllstarClientNamesFromPayload(payload) {
        const names = new Set();

        allstarLinksFromPayload(payload).forEach((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            if (rawNode === '') {
                return;
            }

            const connectionType = String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
            if (!/^\d+$/.test(rawNode) || connectionType === 'client' || connectionType === 'iax') {
                names.add(rawNode);
            }
        });

        return names;
    }

    function targetValueIsInboundAllstarClient(value, payload) {
        const target = String(value || '').trim();
        return target !== '' && inboundAllstarClientNamesFromPayload(payload).has(target);
    }

    function targetValueLooksClientOnly(value) {
        const target = String(value || '').trim();
        if (target === '') {
            return false;
        }

        return /^IAX2[:/]/i.test(target) || /-P$/i.test(target);
    }

    function iaxChannelForLink(link) {
        return String(link?.iax_channel ?? link?.asterisk_channel ?? link?.channel ?? '').trim();
    }

    function disconnectKeyForLink(link) {
        const rawNode = String(link?.node ?? link?.target ?? '').trim();
        const connectionType = String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
        const iaxChannel = iaxChannelForLink(link);

        return connectionType === 'iax_channel' && iaxChannel !== '' ? iaxChannel : rawNode;
    }

    function directModeShouldUseNumericTarget(payload, system) {
        const mode = normalizeMode(
            payload?.selected_mode ||
            system?.selected_mode ||
            payload?.last_mode ||
            system?.last_mode ||
            currentSelectedMode()
        );

        return mode === 'ASL' || mode === 'ECHO';
    }

    function targetCandidateForFieldSync(payload, system) {
        const candidates = [
            payload?.pending_target,
            system?.pending_target,
            payload?.last_target,
            system?.last_target,
        ];

        const directNumericOnly = directModeShouldUseNumericTarget(payload, system);

        for (const candidate of candidates) {
            const value = String(candidate || '').trim();
            if (value === '') {
                continue;
            }

            if (targetValueLooksClientOnly(value) || targetValueIsInboundAllstarClient(value, payload)) {
                continue;
            }

            if (directNumericOnly && !/^\d+$/.test(value)) {
                continue;
            }

            return value;
        }

        return '';
    }

    function syncTargetInputFromPayload(payload, system) {
        if (!els.targetInput) {
            return;
        }

        const nextTarget = targetCandidateForFieldSync(payload, system);
        if (nextTarget !== '') {
            els.targetInput.value = nextTarget;
            return;
        }

        const currentTarget = els.targetInput.value.trim();
        if (
            currentTarget !== '' &&
            (
                targetValueLooksClientOnly(currentTarget) ||
                targetValueIsInboundAllstarClient(currentTarget, payload) ||
                (directModeShouldUseNumericTarget(payload, system) && !/^\d+$/.test(currentTarget))
            )
        ) {
            els.targetInput.value = '';
        }
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

    function favoriteSearchText(item) {
        return [
            favoriteFieldValue(item, 'target'),
            favoriteFieldValue(item, 'mode'),
            normalizeMode(item?.mode ?? 'BM'),
            favoriteFieldValue(item, 'name'),
            favoriteFieldValue(item, 'description'),
        ].join(' ').toLocaleLowerCase();
    }

    function favoriteSortPrimaryKey(preset) {
        if (preset === 'name') {
            return 'name';
        }

        if (preset === 'description') {
            return 'description';
        }

        if (preset === 'target') {
            return 'target';
        }

        return 'mode';
    }

    function favoriteSortChain(preset) {
        switch (preset) {
            case 'mode-name':
                return [
                    ['mode', 'text'],
                    ['name', 'text'],
                    ['target', 'mixed'],
                    ['description', 'text'],
                ];
            case 'mode-description':
                return [
                    ['mode', 'text'],
                    ['description', 'text'],
                    ['target', 'mixed'],
                    ['name', 'text'],
                ];
            case 'name':
                return [
                    ['name', 'text'],
                    ['mode', 'text'],
                    ['target', 'mixed'],
                    ['description', 'text'],
                ];
            case 'description':
                return [
                    ['description', 'text'],
                    ['mode', 'text'],
                    ['target', 'mixed'],
                    ['name', 'text'],
                ];
            case 'target':
                return [
                    ['target', 'mixed'],
                    ['mode', 'text'],
                    ['name', 'text'],
                    ['description', 'text'],
                ];
            case 'mode-target':
            default:
                return [
                    ['mode', 'text'],
                    ['target', 'mixed'],
                    ['name', 'text'],
                    ['description', 'text'],
                ];
        }
    }

    function getSortedFavorites(items) {
        if (!Array.isArray(items)) {
            return [];
        }

        const query = String(state.favoriteSearchQuery || '').trim().toLocaleLowerCase();
        const filteredItems = query === ''
            ? items.slice()
            : items.filter((item) => favoriteSearchText(item).includes(query));
        const preset = String(state.favoriteSortPreset || 'mode-target').trim();
        const direction = state.favoriteSortDirection === 'desc' ? 'desc' : 'asc';
        const chain = favoriteSortChain(preset);

        return filteredItems.sort((leftItem, rightItem) => {
            for (const [key, type] of chain) {
                const compare = compareFavoriteValues(
                    favoriteFieldValue(leftItem, key),
                    favoriteFieldValue(rightItem, key),
                    type,
                    direction
                );

                if (compare !== 0) {
                    return compare;
                }
            }

            return 0;
        });
    }

    function updateFavoritesSortControls() {
        const preset = String(state.favoriteSortPreset || 'mode-target').trim();
        const primaryKey = favoriteSortPrimaryKey(preset);
        const buttons = document.querySelectorAll('.favorites-sort-button');

        state.favoriteSortKey = primaryKey;
        state.favoriteSortType = primaryKey === 'target' ? 'mixed' : 'text';

        buttons.forEach((button) => {
            const key = String(button.getAttribute('data-sort-key') || '').trim();
            const indicator = button.querySelector('.favorites-sort-indicator');

            if (key !== '' && key === primaryKey) {
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

        if (els.favoritesSortSelect && els.favoritesSortSelect.value !== preset) {
            els.favoritesSortSelect.value = preset;
        }

        if (els.favoritesSortDirection) {
            const descending = state.favoriteSortDirection === 'desc';
            els.favoritesSortDirection.textContent = descending ? 'Z–A' : 'A–Z';
            els.favoritesSortDirection.setAttribute(
                'aria-label',
                descending ? 'Sort favorites ascending' : 'Sort favorites descending'
            );
            els.favoritesSortDirection.setAttribute(
                'title',
                descending ? 'Sort favorites ascending' : 'Sort favorites descending'
            );
        }
    }

    function updateFavoritesResultCount(visibleCount, totalCount) {
        if (!els.favoritesResultCount) {
            return;
        }

        const queryActive = String(state.favoriteSearchQuery || '').trim() !== '';
        const totalLabel = `${totalCount} favorite${totalCount === 1 ? '' : 's'}`;
        els.favoritesResultCount.textContent = queryActive
            ? `${visibleCount} of ${totalLabel}`
            : totalLabel;
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

    function currentTargetValue() {
        return String(els.targetInput?.value || '').trim();
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

    function payloadHasConfiguredDvSwitchNode(payload) {
        if (!payload || typeof payload !== 'object') {
            return false;
        }

        const configuredNode = String(
            payload.config?.dvswitch_node ||
            payload.system?.config?.dvswitch_node ||
            configuredDvSwitchNodeFromDom() ||
            ''
        ).trim();

        if (configuredNode === '') {
            return false;
        }

        const allstar = payload.allstar || payload.networks?.allstar || null;
        const links = Array.isArray(allstar?.connected_nodes) ? allstar.connected_nodes : [];

        return links.some((link) => String(link?.node ?? link?.target ?? '').trim() === configuredNode);
    }

    function inferDvSwitchActiveFromPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return false;
        }

        if (payloadHasConfiguredDvSwitchNode(payload)) {
            return true;
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

        const dvswitchNode = configuredDvSwitchNodeFromDom();
        if (dvswitchNode !== '') {
            const allstarPayload = state.lastAllstarPayload || null;
            const links = Array.isArray(allstarPayload?.connected_nodes) ? allstarPayload.connected_nodes : [];
            if (links.some((link) => String(link?.node ?? link?.target ?? '').trim() === dvswitchNode)) {
                return true;
            }
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
        const status = normalizeStatusText(statusText).toUpperCase();

        if (!modeIsConfigured(mode)) {
            return false;
        }

        if (currentTargetValue() === '') {
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
            return true;
        }

        if (mode === 'DSTAR' && status.includes('CONNECTED: D-STAR TARGET')) {
            return true;
        }

        // Disconnect Before Connect is an instruction for the backend, not a
        // reason to block selecting a different configured network.
        return true;
    }

    function managedNetworkLooksDisconnectable(statusText) {
        const networkTexts = [
            els.statusBm,
            els.statusTgif,
            els.statusYsf,
            els.statusDstar,
            els.statusP25,
            els.statusNxdn,
        ].map((element) => String(element?.textContent || '').trim());

        if (networkTexts.some((text) => textLooksActive(text))) {
            return true;
        }

        const status = normalizeStatusText(statusText).toUpperCase();
        return (
            status.includes('(BM)') ||
            status.includes('(TGIF)') ||
            status.includes('CONNECTED: YSF TARGET') ||
            status.includes('CONNECTED: D-STAR TARGET') ||
            status.includes('CONNECTED: DSTAR TARGET') ||
            status.includes('CONNECTED: P25 TARGET') ||
            status.includes('CONNECTED: NXDN TARGET') ||
            status.includes('WAITING: BM READY') ||
            status.includes('WAITING: TGIF READY')
        );
    }

    function shouldEnableDisconnectButton(statusText) {
        if (currentDirectConnectedNodeCount() > 0) {
            return true;
        }

        return managedNetworkLooksDisconnectable(statusText);
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
        updateSaveFavoriteButtonState();

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
        setButtonVisualState(els.disconnectAllButton, true);
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

            return;
        }

        updateButtonsFromStatus(currentStatusText());
    }

    function clearActionStatusHold() {
        state.actionStatusHoldText = '';
        state.actionStatusHoldUntil = 0;
    }

    function holdActionStatus(text, milliseconds = 5000) {
        const safeText = normalizeStatusText(text);
        state.actionStatusHoldText = safeText;
        state.actionStatusHoldUntil = Date.now() + Math.max(0, milliseconds);
        setSystemStatus(safeText);
        updateActivityValue('Current Status', safeText);
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
        const managedModes = {
            BM: {
                name: 'BrandMeister',
                target: 'a talkgroup',
                extra: '',
                disconnect: 'Disconnect stops the current BrandMeister receive session.',
            },
            TGIF: {
                name: 'TGIF',
                target: 'a talkgroup',
                extra: '',
                disconnect: 'Disconnect stops the current TGIF connection.',
            },
            YSF: {
                name: 'YSF',
                target: 'a room or reflector',
                extra: '',
                disconnect: 'Disconnect removes the current YSF connection.',
            },
            DSTAR: {
                name: 'D-Star',
                target: 'a reflector such as REF030EL',
                extra: ' AllTune2 switches DVSwitch to D-Star and loads the configured private node automatically.',
                disconnect: 'Disconnect removes the current D-Star connection.',
            },
            P25: {
                name: 'P25',
                target: 'a talkgroup',
                extra: ' AllTune2 switches DVSwitch to P25 and loads the configured private node automatically.',
                disconnect: 'Disconnect clears the P25 connection and returns DVSwitch to DMR mode.',
            },
            NXDN: {
                name: 'NXDN',
                target: 'a talkgroup',
                extra: ' AllTune2 switches DVSwitch to NXDN and loads the configured private node automatically.',
                disconnect: 'Disconnect clears the NXDN connection and returns DVSwitch to DMR mode.',
            },
        };

        if (managedModes[mode]) {
            const info = managedModes[mode];
            const disconnectBeforeText = disconnectFirst
                ? `With Disconnect Before Connect on, ${info.name} still uses the normal managed-network handoff and leaves direct AllStarLink and EchoLink connections alone. The checkbox takes effect when the next connection is AllStarLink or EchoLink.`
                : `With Disconnect Before Connect off, ${info.name} can stay connected while you add direct AllStarLink nodes or one dashboard-started EchoLink link.`;

            return `${info.name} is a one-step connect. Enter ${info.target}, or load one from Saved Favorites. Choose Transceive or Local Monitor, and press Connect once. Wait for Live Status to confirm the connection. To change the target, enter or load the new one and press Connect again.${info.extra} In the Live Status box, where you see the nodes connected, click Transceive or Local Monitor on the private node to change how it is linked. ${info.disconnect} Disconnect DVSwitch stops the managed network and removes its private-node link while leaving direct AllStarLink and EchoLink connections alone. ${disconnectBeforeText}`;
        }

        if (mode === 'ASL') {
            const disconnectBeforeText = disconnectFirst
                ? 'With Disconnect Before Connect on, the private node and earlier dashboard-started direct links are removed first. True IAX, Web Transceiver, and named app_rpt/IAX clients stay connected and use their own row Disconnect.'
                : 'With Disconnect Before Connect off, the private node and existing direct links stay connected, and more AllStarLink nodes can be added.';

            return `AllStarLink: enter a node number or load it from Saved Favorites, choose Transceive or Local Monitor, and press Connect. In the Live Status box, where you see the nodes connected, click Transceive or Local Monitor to change that exact node, or use its row Disconnect. The main Disconnect removes the most recent dashboard-started direct node. Disconnect DVSwitch clears the managed network/private-node path but leaves direct nodes alone; ${disconnectBeforeText}`;
        }

        if (mode === 'ECHO') {
            const disconnectBeforeText = disconnectFirst
                ? 'With Disconnect Before Connect on, the private node and earlier dashboard-started direct links are removed first. An existing EchoLink link uses EchoLink’s protected disconnect timing and cleanup.'
                : 'With Disconnect Before Connect off, the private node and AllStarLink connections stay connected. Only one dashboard-started outbound EchoLink link is allowed; incoming links are separate.';

            return `EchoLink: enter the mapped node as 3 plus the six-digit EchoLink number, or load it from Saved Favorites, choose Transceive or Local Monitor, and press Connect. Example: 3001234. In the Live Status box, where you see the nodes connected, click Transceive or Local Monitor to change that exact node, or use its row Disconnect. An outbound mode change may be blocked while another EchoLink link is active. Disconnect DVSwitch clears the managed network/private-node path but leaves direct nodes alone; ${disconnectBeforeText}`;
        }

        return 'Select a network, enter a target or load it from Saved Favorites, choose Transceive or Local Monitor, and press Connect. Wait for Live Status to confirm the result before the next action.';
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

        state.allstarLinksSignature = '';
        if (state.lastAllstarPayload) {
            renderAllstarLinks(state.lastAllstarPayload, { force: true });
        }

        const renderItems = getSortedFavorites(state.favoritesRaw);
        updateFavoritesResultCount(renderItems.length, state.favoritesRaw.length);

        if (renderItems.length === 0) {
            const emptyMessage = state.favoritesRaw.length === 0
                ? 'No favorites saved yet.'
                : 'No favorites match your search.';
            els.favoritesBody.innerHTML = `<tr><td colspan="5">${emptyMessage}</td></tr>`;
            updateFavoritesSortControls();
            updateSaveFavoriteButtonState();
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
                    <td class="favorite-mode-cell"><span class="favorite-mode-badge">${modeDisplay}</span></td>
                    <td class="favorite-name">${name}</td>
                    <td class="favorite-description">${description}</td>
                    <td class="favorite-action"><span class="load-button">Load</span></td>
                </tr>
            `;
        });

        els.favoritesBody.innerHTML = rows.join('');
        updateDashboardFavoritesWriteState();
        updateFavoritesSortControls();
        updateSaveFavoriteButtonState();
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
            if (now > Number(expiresAt || 0)) {
                state.pendingDisconnectNodes.delete(node);
                state.allstarLinksSignature = '';
            }
        });
    }

    function connectedLinkMode(link) {
        const value = String(link?.link_mode ?? link?.mode_label ?? link?.mode ?? '')
            .trim().toLowerCase().replace(/[\s_-]+/g, ' ');
        return value.includes('monitor') ? 'local_monitor' : (value.includes('transceive') ? 'transceive' : '');
    }

    function syncControlCenterToConfirmedDirectLink(node, kind, linkMode) {
        const uiMode = kind === 'echo' ? 'ECHO' : (kind === 'asl' ? 'ASL' : '');
        if (uiMode === '') {
            return;
        }

        setSelectedModeValue(uiMode);
        if (els.targetInput) {
            els.targetInput.value = node;
        }
        if (els.autoloadModeSelect) {
            els.autoloadModeSelect.value = normalizeAutoloadMode(linkMode);
        }
        syncAutoloadUiForMode(uiMode);
        holdUserSelection();
        updateHelperText();
        updateButtonsFromStatus(currentStatusText());
    }

    function connectedNodeModeControl(link, node, dvswitchNode, label) {
        const currentMode = connectedLinkMode(link);
        const type = String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
        let kind = '';

        if (link?.is_live && /^\d+$/.test(node) && currentMode !== '' && !['iax_channel', 'client', 'iax'].includes(type)) {
            if (node === dvswitchNode) {
                kind = modeForcesDvSwitch(state.activeManagedDvSwitchMode)
                    && state.activeManagedDvSwitchTarget !== '' ? 'dvswitch' : '';
            } else {
                kind = /^3\d{6}$/.test(node) ? 'echo' : 'asl';
            }
        }

        let pending = state.pendingModeSwitches.get(node) || null;
        if (pending) {
            const now = Date.now();

            if (now > pending.expiresAt) {
                state.pendingModeSwitches.delete(node);
                pending = null;
                holdActionStatus('ERROR: MODE SWITCH WAS NOT CONFIRMED');
            } else if (currentMode === pending.mode) {
                /*
                 * Private-node and EchoLink mode changes intentionally tear the
                 * row down and rebuild it. Require the requested live mode to
                 * remain visible across more than one status cycle before the
                 * pill is released. This prevents a brief intermediate status
                 * from making the first click look as though it was ignored.
                 */
                if (!pending.confirmedAt) {
                    pending.confirmedAt = now;
                } else if (now - pending.confirmedAt >= 750) {
                    const confirmedKind = String(pending.kind || '').trim();
                    const confirmedMode = String(pending.mode || '').trim();
                    state.pendingModeSwitches.delete(node);
                    pending = null;
                    syncControlCenterToConfirmedDirectLink(node, confirmedKind, confirmedMode);
                }
            } else {
                pending.confirmedAt = 0;
            }
        }

        if (kind === '') {
            return `<span class="connected-node-mode">${escapeHtml(label)}</span>`;
        }

        const nextMode = currentMode === 'local_monitor' ? 'transceive' : 'local_monitor';
        const nextLabel = autoloadModeLabel(nextMode);
        const title = authLoginRequired()
            ? 'Login required to control AllTune2'
            : (pending ? `Switching to ${nextLabel}` : `Switch this link to ${nextLabel}`);

        return `<button type="button"
            class="connected-node-mode connected-node-mode-toggle ${pending ? 'connected-node-mode-pending' : ''}"
            data-switch-node="${escapeHtml(node)}"
            data-switch-kind="${kind}"
            data-current-link-mode="${currentMode}"
            data-requested-link-mode="${nextMode}"
            title="${escapeHtml(title)}" aria-label="${escapeHtml(title)}"
            ${pending || state.busy || !authAllowsActions() ? 'disabled' : ''}>${pending ? 'Switching...' : escapeHtml(label)}</button>`;
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

        const activeDisconnectKeySet = new Set(links.map((link) => disconnectKeyForLink(link)).filter(Boolean));
        prunePendingDisconnectNodes(activeDisconnectKeySet);

        state.pendingModeSwitches.forEach((pending, node) => {
            if (Date.now() > pending.expiresAt) {
                state.pendingModeSwitches.delete(node);
            }
        });

        const displayLinks = links.filter((link) => !pendingDisconnectActive(disconnectKeyForLink(link)));

        const linksSignature = JSON.stringify(displayLinks.map((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            const modeLabel = String(link?.mode_label ?? link?.link_mode ?? link?.mode ?? 'Connected').trim();
            const connectionType = String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
            const iaxChannel = iaxChannelForLink(link);
            const pendingKey = disconnectKeyForLink(link);

            return {
                node: rawNode,
                type: connectionType,
                iaxChannel,
                mode: modeLabel,
                live: !!link?.is_live,
                pending: pendingDisconnectActive(pendingKey),
                pendingMode: state.pendingModeSwitches.get(rawNode)?.mode || '',
                privateMode: rawNode === dvswitchNode
                    ? `${state.activeManagedDvSwitchMode}:${state.activeManagedDvSwitchTarget}`
                    : '',
            };
        }));

        function normalizeLinkModeLabel(link) {
            const raw = String(link?.mode_label ?? link?.link_mode ?? link?.mode ?? 'Connected').trim();
            const mode = connectedLinkMode(link);
            return mode !== '' ? autoloadModeLabel(mode) : (raw || 'Connected');
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

            const connectionType = String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
            const isNumericNode = /^\d+$/.test(rawNode);

            if (connectionType === 'iax_channel') {
                const channel = iaxChannelForLink(link);
                const description = channel !== ''
                    ? `Direct IAX client channel ${channel}`
                    : 'Direct IAX client channel';
                return {
                    label: 'IAX',
                    sublabel: 'Channel',
                    className: 'asl',
                    description,
                    fullDescription: description,
                    canSaveFavorite: false,
                    favoriteMode: '',
                    favoriteName: '',
                    favoriteDescription: '',
                    isSavedFavorite: false,
                };
            }

            if (!isNumericNode || connectionType === 'client' || connectionType === 'iax') {
                const payloadParts = payloadDisplayParts(link);
                const looksAppRptClient = /-P$/i.test(rawNode);
                const description = payloadParts.full || (looksAppRptClient ? 'IAX / app_rpt client' : 'IAX / Web Transceiver client');
                return {
                    label: 'IAX',
                    sublabel: 'Client',
                    className: 'asl',
                    description,
                    fullDescription: description,
                    canSaveFavorite: false,
                    favoriteMode: '',
                    favoriteName: '',
                    favoriteDescription: '',
                    isSavedFavorite: false,
                };
            }

            const looksEchoLink = /^3\d{6}$/.test(rawNode);
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

        const bridgeAudioActive = dvswitchNode !== '' && displayLinks.some((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            return rawNode === dvswitchNode && linkLooksKeyed(link, state.dvswitchKeyedHoldSeconds);
        });

        const externalBridgeAudioActive = dvswitchNode !== '' && displayLinks.some((link) => {
            const rawNode = String(link?.node ?? link?.target ?? '').trim();
            const label = normalizeLinkModeLabel(link).toLowerCase();
            const isLocalMonitor = label.includes('monitor');

            return rawNode !== ''
                && rawNode !== dvswitchNode
                && !isLocalMonitor
                && linkLooksKeyed(link, 1);
        });

        if (!force && linksSignature === state.allstarLinksSignature) {
            const renderedCards = els.statusAllstarLinks.querySelectorAll('.connected-node-card');

            if (renderedCards.length === displayLinks.length) {
                displayLinks.forEach((link, index) => {
                    const card = renderedCards[index];

                    if (!card) {
                        return;
                    }

                    const rawNode = String(link.node ?? link.target ?? '').trim();
                    const linkModeLabel = normalizeLinkModeLabel(link);
                    const isDvSwitchNode = dvswitchNode !== '' && rawNode === dvswitchNode;
                    const isLocalMonitor = linkModeLabel.toLowerCase().includes('monitor');
                    const keyedHoldSeconds = isDvSwitchNode ? state.dvswitchKeyedHoldSeconds : 0.5;
                    const rowKeyed = linkLooksKeyed(link, keyedHoldSeconds);
                    const bridgeAudioForNode = bridgeAudioActive && !isDvSwitchNode && !isLocalMonitor;
                    const bridgeAudioForDvSwitch = isDvSwitchNode && externalBridgeAudioActive;
                    const rowActive = rowKeyed || bridgeAudioForNode || bridgeAudioForDvSwitch;

                    card.classList.toggle('keyed', rowActive);
                    card.classList.toggle('bridge-audio', bridgeAudioForNode);

                    const stateEl = card.querySelector('.connected-node-state');

                    if (stateEl && state.activeModePointerNode !== rawNode) {
                        const modeControl = connectedNodeModeControl(link, rawNode, dvswitchNode, linkModeLabel);
                        const elapsed = escapeHtml(String(link.elapsed ?? '').trim());
                        const liveLabel = link.is_live ? 'Live AMI' : 'Tracked';
                        const keyedText = rowKeyed
                            ? '<span class="connected-node-keyed">Audio Active</span>'
                            : (bridgeAudioForDvSwitch
                                ? '<span class="connected-node-keyed">Bridge Audio Active</span>'
                                : (bridgeAudioForNode ? '<span class="connected-node-keyed">Audio via DVSwitch</span>' : ''));
                        const elapsedText = elapsed !== ''
                            ? `<span class="connected-node-meta-item">Connected ${elapsed}</span>`
                            : '';

                        stateEl.innerHTML = `
                            ${modeControl}
                            <span class="connected-node-source">${escapeHtml(liveLabel)}</span>
                            ${elapsedText}
                            ${keyedText}
                        `;
                    }
                });

                return;
            }
        }

        state.allstarLinksSignature = linksSignature;

        if (displayLinks.length === 0) {
            els.statusAllstarLinks.innerHTML = `
                <div class="allstar-links-empty">
                    No AllStarLink / EchoLink links detected.
                </div>
            `;
            return;
        }

        const rows = displayLinks.map((link) => {
            const rawNode = String(link.node ?? link.target ?? '').trim();
            const node = escapeHtml(rawNode);
            const linkModeLabel = normalizeLinkModeLabel(link);
            const modeControl = connectedNodeModeControl(link, rawNode, dvswitchNode, linkModeLabel);
            const elapsed = escapeHtml(String(link.elapsed ?? '').trim());
            const isLive = !!link.is_live;
            const isDvSwitchNode = dvswitchNode !== '' && rawNode === dvswitchNode;
            const connectionType = String(link?.connection_type ?? link?.type ?? '').trim().toLowerCase();
            const iaxChannel = iaxChannelForLink(link);
            const isPureIaxChannelLink = connectionType === 'iax_channel' && iaxChannel !== '';
            const isIaxClientLink = rawNode !== '' && !isDvSwitchNode && !isPureIaxChannelLink && (!/^\d+$/.test(rawNode) || connectionType === 'client' || connectionType === 'iax');
            const isLocalMonitor = linkModeLabel.toLowerCase().includes('monitor');
            const keyedHoldSeconds = isDvSwitchNode ? state.dvswitchKeyedHoldSeconds : 0.5;
            const rowKeyed = linkLooksKeyed(link, keyedHoldSeconds);
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

            const disconnectKey = isPureIaxChannelLink ? iaxChannel : rawNode;
            const pendingDisconnect = pendingDisconnectActive(disconnectKey);
            const actionBlocked = !authAllowsActions();
            const disableDisconnectButton = actionBlocked || pendingDisconnect;
            const canDisconnectLink = isDvSwitchNode || isPureIaxChannelLink || isIaxClientLink || (/^\d+$/.test(rawNode) && link?.disconnectable !== false);
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
                : isPureIaxChannelLink
                ? `
                    <button
                        type="button"
                        class="connected-node-button allstar-disconnect-button ${pendingDisconnect ? 'connected-node-button-pending' : ''}"
                        data-disconnect-iax-channel="${escapeHtml(iaxChannel)}"
                        data-disconnect-iax-row-node="${node}"
                        ${disableDisconnectButton ? 'disabled' : ''} ${authLoginRequired() ? 'title="Login required to control AllTune2"' : 'title="Disconnect direct IAX channel"'}
                    >
                        ${pendingDisconnect ? 'Disconnecting...' : 'Disconnect IAX'}
                    </button>
                `
                : isIaxClientLink
                ? `
                    <button
                        type="button"
                        class="connected-node-button allstar-disconnect-button ${pendingDisconnect ? 'connected-node-button-pending' : ''}"
                        data-disconnect-live-client="${node}"
                        ${disableDisconnectButton ? 'disabled' : ''} ${authLoginRequired() ? 'title="Login required to control AllTune2"' : 'title="Disconnect inbound IAX / Web Transceiver client"'}
                    >
                        ${pendingDisconnect ? 'Disconnecting...' : `Disconnect ${node}`}
                    </button>
                `
                : !canDisconnectLink
                ? `
                    <button
                        type="button"
                        class="connected-node-button"
                        disabled
                    >Disconnect</button>
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

            const titleLabel = isPureIaxChannelLink && iaxChannel !== ''
                ? escapeHtml(iaxChannel)
                : `Node ${node}`;

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
                        <div class="connected-node-title">${titleLabel}${favoriteButtonHtml}</div>
                        <div class="connected-node-description" title="${escapeHtml(network.fullDescription || network.description)}">${escapeHtml(network.description)}</div>
                    </div>

                    <div class="connected-node-state">
                        ${modeControl}
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
                <span>${displayLinks.length} active</span>
            </div>
            <div class="connected-nodes-helper">
                Click a live mode pill to switch only that link. Each row keeps its own disconnect action.
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
        let statusText =
            payload.status_text ||
            payload.status ||
            payload.last_status ||
            system.status_text ||
            'IDLE - NO CONNECTIONS';

        const bm = payload.networks?.brandmeister || payload.brandmeister || null;
        const tgif = payload.networks?.tgif || payload.tgif || null;
        const ysf = payload.networks?.ysf || payload.ysf || null;
        const dstar = payload.networks?.dstar || payload.dstar || null;
        const p25 = payload.networks?.p25 || payload.p25 || null;
        const nxdn = payload.networks?.nxdn || payload.nxdn || null;
        const allstar = payload.allstar || payload.networks?.allstar || null;
        state.activeManagedDvSwitchMode = normalizeMode(
            payload.managed_dvswitch_mode ?? system.managed_dvswitch_mode ?? ''
        );
        state.activeManagedDvSwitchTarget = String(
            payload.managed_dvswitch_target ?? system.managed_dvswitch_target ?? ''
        ).trim();
        const directStatusCorrection = correctDirectStatusFromLive(statusText, allstar);
        statusText = directStatusCorrection.statusText;
        const liveStatusText = statusText;
        if (state.actionStatusHoldText !== '' && Date.now() < state.actionStatusHoldUntil) {
            statusText = state.actionStatusHoldText;
        } else {
            clearActionStatusHold();
        }
        const previousSystemStatusText = currentStatusText();

        setSystemStatus(statusText);

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

        syncManagedConnectionAudio(payload, system, {
            brandmeister: bm,
            tgif,
            ysf,
            dstar,
            p25,
            nxdn,
        });
        applyImmediateAllstarSnapshot(allstar);
        announceCorrectedDirectDisconnect(directStatusCorrection, previousSystemStatusText);

        if (allowFieldSync && els.modeSelect && !state.busy && !userSelectionIsHeld()) {
            if (typeof payload.selected_mode === 'string') {
                setSelectedModeValue(payload.selected_mode);
            } else if (typeof system.selected_mode === 'string') {
                setSelectedModeValue(system.selected_mode);
            }
        }

        if (allowFieldSync && els.targetInput && !userIsEditingTarget() && !state.busy && !userSelectionIsHeld()) {
            syncTargetInputFromPayload(payload, system);
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
        if (statusText !== liveStatusText) {
            updateActivityValue('Current Status', statusText);
        }
        updateHelperText();
        updateButtonsFromStatus(liveStatusText);
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

        clearActionStatusHold();
        setSystemStatus(statusText);

        if (!preserveMode && els.modeSelect) {
            if (typeof payload.selected_mode === 'string') {
                setSelectedModeValue(payload.selected_mode);
            } else if (typeof system.selected_mode === 'string') {
                setSelectedModeValue(system.selected_mode);
            }
        }

        if (!preserveTarget && els.targetInput && !userIsEditingTarget()) {
            syncTargetInputFromPayload(payload, system);
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


    function payloadPrivateNodeLinkLost(payload) {
        if (!payload || typeof payload !== 'object') {
            return false;
        }

        const system = payload.system || {};
        return !!(payload.private_node_link_lost || system.private_node_link_lost);
    }

    async function cleanupLostPrivateNodeIfNeeded(payload) {
        const lost = payloadPrivateNodeLinkLost(payload);

        if (!lost) {
            state.privateNodeLossCleanupDone = false;
            return;
        }

        if (state.privateNodeLossCleanupInFlight || state.privateNodeLossCleanupDone || state.busy || !authAllowsActions()) {
            return;
        }

        state.privateNodeLossCleanupInFlight = true;
        state.privateNodeLossCleanupDone = true;

        try {
            await requestJson(state.endpoints.connect, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'private_node_lost_cleanup',
                    action_type: 'private_node_lost_cleanup',
                }),
            });

            queueStatusRefresh(250);
        } catch (error) {
            console.error(error);
            state.privateNodeLossCleanupDone = false;
        } finally {
            state.privateNodeLossCleanupInFlight = false;
        }
    }

    async function loadFavorites() {
        const payload = await requestJson(state.endpoints.favorites, {
            method: 'GET',
        });

        if (Array.isArray(payload.favorites)) {
            renderFavorites(payload.favorites);
        }

        return payload;
    }

    async function loadStatus() {
        if (state.statusRequest) {
            return state.statusRequest;
        }

        state.statusRequest = requestJson(state.endpoints.status, {
            method: 'GET',
        });

        try {
            const payload = await state.statusRequest;
            applyLiveStatus(payload, { allowFieldSync: false });
            cleanupLostPrivateNodeIfNeeded(payload);
            return payload;
        } finally {
            state.statusRequest = null;
        }
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

            if (state.busy || state.statusRequest) {
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

        const requestedUiMode = normalizeMode(extraPayload.ui_mode ?? currentSelectedMode());
        const requestedTarget = String(extraPayload.target ?? extraPayload.tgNum ?? currentTargetValue()).trim();

        if (action === 'connect' && !modeIsConfigured(requestedUiMode)) {
            updateHelperText();
            updateButtonsFromStatus(currentStatusText());
            return null;
        }

        if (action === 'connect' && requestedTarget === '') {
            updateHelperText();
            updateButtonsFromStatus(currentStatusText());
            return null;
        }

        clearActionStatusHold();
        state.lastRequestedUiMode = requestedUiMode;
        rememberPreferredAslUiMode(state.lastRequestedUiMode);

        const forcedAutoload = modeForcesDvSwitch(requestedUiMode);

        const payload = {
            action,
            action_type: action,
            target: requestedTarget,
            tgNum: requestedTarget,
            mode: modeRequestValue(requestedUiMode),
            ui_mode: requestedUiMode,
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
            // Direct Disconnect Before Connect must remove the private DVSwitch link too.
            if (action === 'connect' && useDirectEndpoint && !!payload.disconnect_before_connect && readConfigAvailability().hasRealDvSwitchNode) {
                markAudioSettleWindow(state.lastRequestedUiMode === 'ECHO' ? 6500 : 3000);
                await requestJson(state.endpoints.connect, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'disconnect_dvswitch', action_type: 'disconnect_dvswitch' }),
                });
            }

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
                action === 'disconnect_selected' ||
                action === 'disconnect_live_client' ||
                action === 'disconnect_iax_channel'
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
                const directAllstarAction = useDirectEndpoint && ['connect', 'disconnect', 'disconnect_selected', 'disconnect_live_client', 'disconnect_iax_channel'].includes(action);
                const actionStatusText = responsePayload.status_text || responsePayload.status || responsePayload.last_status || '';
                const actionStatusUpper = String(actionStatusText || '').toUpperCase();

                if (action === 'disconnect_iax_channel') {
                    [
                        responsePayload.iax_requested_channel,
                        responsePayload.iax_disconnected_channel,
                    ].forEach((channel) => {
                        const normalizedChannel = String(channel || '').trim();

                        if (normalizedChannel !== '') {
                            notePendingDisconnect(normalizedChannel, 8000);
                        }
                    });
                }

                if (
                    directAllstarAction &&
                    action === 'connect' &&
                    !isErrorStatus(actionStatusText)
                ) {
                    /*
                     * A successful direct Connect response is authoritative for
                     * the browser that initiated it. Announce the new node now
                     * instead of depending on a later Live Status poll, which
                     * can observe the Disconnect-Before-Connect teardown first.
                     * The immediate-event signature suppresses the later status
                     * duplicate. While replacing a link, quietly absorb the
                     * intermediate removal so the meaningful alert is Connected.
                     */
                    if (!!payload.disconnect_before_connect) {
                        markAudioSettleWindow(state.lastRequestedUiMode === 'ECHO' ? 5500 : 2200);
                    }
                    announceImmediateActionAudio(actionStatusText);

                    if (state.lastRequestedUiMode === 'ECHO') {
                        /*
                         * EchoLink can take a few status polls to appear in live
                         * app_rpt output after direct_link.php returns. Hold only
                         * direct-status correction so a fresh connect is not
                         * immediately changed to DISCONNECTED.
                         */
                        markDirectStatusCorrectionHold(4500);
                    }
                }

                if (!directAllstarAction) {
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
            }

            setBusy(false);
            busyReleasedEarly = true;
            updateDtmfButtonState();

            if (action !== 'send_dtmf') {
                refreshStatusSoonAfterAction();
            }

            return responsePayload;
        } catch (error) {
            console.error(error);
            const message = error?.payload?.auth_required
                ? loginRequiredMessage()
                : (error?.payload?.csrf_failed ? 'SECURITY CHECK FAILED - REFRESH AND TRY AGAIN' : (error?.payload?.status_text || 'ERROR: REQUEST FAILED'));
            holdActionStatus(message);
            return null;
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

    function directNodeModeRequest(node, kind, requestedMode) {
        return {
            endpoint: state.endpoints.direct,
            payload: {
                action: 'switch_mode', action_type: 'switch_mode', selected_node: node,
                ui_mode: kind === 'echo' ? 'ECHO' : 'ASL', link_mode: requestedMode,
            },
        };
    }

    async function switchConnectedNodeMode(button) {
        if (button.disabled || state.busy || !authAllowsActions()) {
            if (!authAllowsActions()) {
                setSystemStatus(loginRequiredMessage());
            }
            return;
        }

        const node = String(button.dataset.switchNode || '').trim();
        const kind = String(button.dataset.switchKind || '').trim();
        const currentMode = String(button.dataset.currentLinkMode || '').trim();
        const requestedMode = String(button.dataset.requestedLinkMode || '').trim();
        if (!/^\d+$/.test(node) || !['asl', 'echo', 'dvswitch'].includes(kind)
            || !['transceive', 'local_monitor'].includes(currentMode)
            || !['transceive', 'local_monitor'].includes(requestedMode)
            || currentMode === requestedMode) {
            return;
        }

        const activeManagedMode = normalizeMode(state.activeManagedDvSwitchMode);
        const activeManagedTarget = String(state.activeManagedDvSwitchTarget || '').trim();
        if (kind === 'dvswitch' && (!modeForcesDvSwitch(activeManagedMode) || activeManagedTarget === '')) {
            holdActionStatus('ERROR: ACTIVE DVSWITCH TARGET NOT AVAILABLE');
            return;
        }

        clearActionStatusHold();
        state.pendingModeSwitches.set(node, {
            kind,
            mode: requestedMode,
            expiresAt: Date.now() + 30000,
            confirmedAt: 0,
        });
        renderAllstarLinks(state.lastAllstarPayload, { force: true });

        if (kind === 'dvswitch') {
            const payload = await sendAction('connect', {
                target: activeManagedTarget,
                tgNum: activeManagedTarget,
                mode: activeManagedMode,
                ui_mode: activeManagedMode,
                autoload_dvswitch_mode: requestedMode,
            });

            if (!payload) {
                state.pendingModeSwitches.delete(node);
                renderAllstarLinks(state.lastAllstarPayload, { force: true });
            }
            return;
        }

        const request = directNodeModeRequest(node, kind, requestedMode);
        setBusy(true);

        try {
            const payload = await requestJson(request.endpoint, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(request.payload),
            });
            const statusText = payload.status_text || payload.status || payload.last_status || 'MODE SWITCH REQUESTED';
            clearActionStatusHold();
            setSystemStatus(statusText);
            updateActivityValue('Current Status', statusText);
            if (kind === 'echo') {
                markDirectStatusCorrectionHold(4500);
            }
            refreshStatusSoonAfterAction();
            queueStatusRefresh(6500);
        } catch (error) {
            console.error(error);
            state.pendingModeSwitches.delete(node);
            renderAllstarLinks(state.lastAllstarPayload, { force: true });
            const message = error?.payload?.auth_required
                ? loginRequiredMessage()
                : (error?.payload?.status_text || error?.payload?.status || 'ERROR: MODE SWITCH FAILED');
            holdActionStatus(message);
        } finally {
            setBusy(false);
        }
    }

    function clearActiveModePointer(node = '') {
        if (node !== '' && state.activeModePointerNode !== node) {
            return;
        }
        if (state.activeModePointerTimer) {
            window.clearTimeout(state.activeModePointerTimer);
            state.activeModePointerTimer = null;
        }
        state.activeModePointerNode = '';
    }

    function holdActiveModePointer(button) {
        const node = String(button?.dataset?.switchNode || '').trim();
        if (node === '') {
            return;
        }
        clearActiveModePointer();
        state.activeModePointerNode = node;
        state.activeModePointerTimer = window.setTimeout(() => {
            clearActiveModePointer(node);
        }, 1800);
    }

    function wireAllstarDisconnectButtons() {
        if (!els.statusAllstarLinks) {
            return;
        }

        els.statusAllstarLinks.addEventListener('pointerdown', (event) => {
            const modeButton = event.target.closest('.connected-node-mode-toggle');
            if (modeButton) {
                holdActiveModePointer(modeButton);
            }
        });

        els.statusAllstarLinks.addEventListener('pointercancel', () => {
            clearActiveModePointer();
        });

        els.statusAllstarLinks.addEventListener('click', (event) => {
            const modeButton = event.target.closest('.connected-node-mode-toggle');
            if (modeButton) {
                const node = String(modeButton.dataset.switchNode || '').trim();
                window.setTimeout(() => clearActiveModePointer(node), 0);
                switchConnectedNodeMode(modeButton);
                return;
            }

            const dvswitchButton = event.target.closest('[data-disconnect-dvswitch]');
            if (dvswitchButton) {
                if (!authAllowsActions()) {
                    setSystemStatus(loginRequiredMessage());
                    updateActivityValue('Current Status', loginRequiredMessage());
                    updateButtonsFromStatus(currentStatusText());
                    return;
                }

                if (dvswitchButton.disabled) {
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


            const iaxChannelButton = event.target.closest('[data-disconnect-iax-channel]');
            if (iaxChannelButton) {
                if (!authAllowsActions()) {
                    setSystemStatus(loginRequiredMessage());
                    updateActivityValue('Current Status', loginRequiredMessage());
                    updateButtonsFromStatus(currentStatusText());
                    return;
                }

                if (iaxChannelButton.disabled) {
                    return;
                }

                const selectedChannel = String(iaxChannelButton.getAttribute('data-disconnect-iax-channel') || '').trim();
                const selectedRowNode = String(iaxChannelButton.getAttribute('data-disconnect-iax-row-node') || '').trim();
                if (!selectedChannel) {
                    return;
                }

                notePendingDisconnect(selectedChannel);

                iaxChannelButton.disabled = true;
                iaxChannelButton.classList.add('connected-node-button-pending');
                iaxChannelButton.textContent = 'Disconnecting...';

                const card = iaxChannelButton.closest('.connected-node-card');
                if (card) {
                    card.classList.add('disconnecting');
                }

                sendAction('disconnect_iax_channel', {
                    selected_channel: selectedChannel,
                    selected_row_node: selectedRowNode,
                    target: '',
                    tgNum: '',
                });
                return;
            }

            const liveClientButton = event.target.closest('[data-disconnect-live-client]');
            if (liveClientButton) {
                if (!authAllowsActions()) {
                    setSystemStatus(loginRequiredMessage());
                    updateActivityValue('Current Status', loginRequiredMessage());
                    updateButtonsFromStatus(currentStatusText());
                    return;
                }

                if (liveClientButton.disabled) {
                    return;
                }

                const selectedClient = String(liveClientButton.getAttribute('data-disconnect-live-client') || '').trim();
                if (!selectedClient) {
                    return;
                }

                notePendingDisconnect(selectedClient);

                liveClientButton.disabled = true;
                liveClientButton.classList.add('connected-node-button-pending');
                liveClientButton.textContent = 'Disconnecting...';

                const card = liveClientButton.closest('.connected-node-card');
                if (card) {
                    card.classList.add('disconnecting');
                }

                sendAction('disconnect_live_client', {
                    selected_client: selectedClient,
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

            if (button.disabled) {
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

            const preset = sortKey === 'mode' ? 'mode-target' : sortKey;

            if (state.favoriteSortPreset === preset) {
                state.favoriteSortDirection = state.favoriteSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                state.favoriteSortPreset = preset;
                state.favoriteSortDirection = 'asc';
            }

            state.favoriteSortKey = sortKey;
            state.favoriteSortType = sortType === 'mixed' ? 'mixed' : 'text';
            renderFavorites(state.favoritesRaw, { force: true });
        });
    }

    function wireFavoritesControls() {
        const updateFavoritesSearchClear = () => {
            if (!els.favoritesSearchClear || !els.favoritesSearch) {
                return;
            }

            els.favoritesSearchClear.hidden = els.favoritesSearch.value === '';
        };

        if (els.favoritesSearch) {
            els.favoritesSearch.addEventListener('input', () => {
                state.favoriteSearchQuery = els.favoritesSearch.value || '';
                updateFavoritesSearchClear();
                renderFavorites(state.favoritesRaw, { force: true });
            });
        }

        if (els.favoritesSearchClear && els.favoritesSearch) {
            els.favoritesSearchClear.addEventListener('click', () => {
                els.favoritesSearch.value = '';
                state.favoriteSearchQuery = '';
                updateFavoritesSearchClear();
                renderFavorites(state.favoritesRaw, { force: true });
                els.favoritesSearch.focus();
            });
        }

        if (els.favoritesSortSelect) {
            els.favoritesSortSelect.addEventListener('change', () => {
                const preset = String(els.favoritesSortSelect.value || 'mode-target').trim();
                state.favoriteSortPreset = preset;
                state.favoriteSortDirection = 'asc';
                state.favoriteSortKey = favoriteSortPrimaryKey(preset);
                state.favoriteSortType = state.favoriteSortKey === 'target' ? 'mixed' : 'text';
                renderFavorites(state.favoritesRaw, { force: true });
            });
        }

        if (els.favoritesSortDirection) {
            els.favoritesSortDirection.addEventListener('click', () => {
                state.favoriteSortDirection = state.favoriteSortDirection === 'asc' ? 'desc' : 'asc';
                renderFavorites(state.favoritesRaw, { force: true });
            });
        }

        updateFavoritesSearchClear();
        updateFavoritesSortControls();
        updateFavoritesResultCount(0, state.favoritesRaw.length);
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
            updateSaveFavoriteButtonState();

            if (window.matchMedia('(max-width: 760px)').matches) {
                const connectButton = document.getElementById('connect-button');
                if (connectButton) {
                    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    window.requestAnimationFrame(() => {
                        connectButton.scrollIntoView({
                            behavior: reduceMotion ? 'auto' : 'smooth',
                            block: 'center',
                        });
                    });
                }
            }

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

    function updateSaveFavoriteButtonState() {
        if (!els.saveFavoriteButton || !els.targetInput || !els.modeSelect) {
            return;
        }

        const target = String(els.targetInput.value || '').trim();
        const mode = normalizeMode(els.modeSelect.value || 'BM');
        const existingFavorite = findExistingFavorite(target, mode);
        const icon = els.saveFavoriteButton.querySelector('.control-save-icon');
        const text = els.saveFavoriteButton.querySelector('.control-save-text');
        const hasFavorite = !!existingFavorite && target !== '';

        els.saveFavoriteButton.classList.toggle('is-saved-favorite', hasFavorite);
        els.saveFavoriteButton.classList.toggle('is-new-favorite', !hasFavorite);
        els.saveFavoriteButton.setAttribute('aria-label', hasFavorite ? 'Edit saved favorite' : 'Save favorite');
        els.saveFavoriteButton.title = hasFavorite ? 'Edit saved favorite' : 'Save current manual entry as a favorite';

        if (icon) {
            icon.textContent = hasFavorite ? '★' : '☆';
        }

        if (text) {
            text.innerHTML = hasFavorite ? 'Edit<br>Favorite' : 'Save<br>Favorite';
        }
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

        if (els.targetInput) {
            els.targetInput.addEventListener('input', updateSaveFavoriteButtonState);
            els.targetInput.addEventListener('change', updateSaveFavoriteButtonState);
        }

        if (els.modeSelect) {
            els.modeSelect.addEventListener('change', () => {
                holdUserSelection();
                rememberPreferredAslUiMode(els.modeSelect.value);
                syncAutoloadUiForMode(els.modeSelect.value);
                updateHelperText();
                updateButtonsFromStatus(currentStatusText());
                updateSaveFavoriteButtonState();
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
        wireFavoritesControls();
        wireFavoritesLoad();
        wireSaveFavoriteModal();
        loadAudioAlertsPreference();
        if (els.autoloadCheckbox) {
            state.manualAutoloadPreference = !!els.autoloadCheckbox.checked;
        }
        syncAutoloadUiForMode(currentSelectedMode());
        updateHelperText();
        updateDtmfButtonState();
        updateSaveFavoriteButtonState();
        checkForRepoUpdate();

        loadFavorites().catch((error) => {
            console.error('Unable to load Saved Favorites independently:', error);
        });

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
