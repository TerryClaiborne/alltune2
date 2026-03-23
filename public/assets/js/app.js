(() => {
    'use strict';

    const state = {
        busy: false,
        pollTimer: null,
        pollIntervalMs: 10000,
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
        connectButton: document.getElementById('connect-button'),
        disconnectButton: document.getElementById('disconnect-button'),
        helperText: document.getElementById('helper-text'),
        systemStatus: document.getElementById('system-status'),
        favoritesBody: document.getElementById('favorites-body'),
        statusBm: document.getElementById('status-bm'),
        statusTgif: document.getElementById('status-tgif'),
        statusYsf: document.getElementById('status-ysf'),
        statusAllstar: document.getElementById('status-allstar'),
    };

    function hasCoreElements() {
        return !!(
            els.targetInput &&
            els.modeSelect &&
            els.autoloadCheckbox &&
            els.autoloadModeSelect &&
            els.connectButton &&
            els.disconnectButton &&
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

    function normalizeMode(mode) {
        const value = String(mode || '').trim().toUpperCase();
        return value === 'ALLSTAR' ? 'ASL' : value;
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

        if (isConnectedStatus(statusText)) {
            setButtonVisualState(els.connectButton, false);
            setButtonVisualState(els.disconnectButton, true);
            return;
        }

        if (isWaitingStatus(statusText)) {
            setButtonVisualState(els.connectButton, true);
            setButtonVisualState(els.disconnectButton, true);
            return;
        }

        if (isDisconnectedStatus(statusText) || isErrorStatus(statusText)) {
            setButtonVisualState(els.connectButton, true);
            setButtonVisualState(els.disconnectButton, false);
            return;
        }

        setButtonVisualState(els.connectButton, true);
        setButtonVisualState(els.disconnectButton, true);
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

            return;
        }

        const currentStatus = els.systemStatus
            ? els.systemStatus.textContent.replace(/^System Status:\s*/i, '').trim()
            : 'IDLE - NO CONNECTIONS';

        updateButtonsFromStatus(currentStatus);
    }

    function setSystemStatus(text) {
        if (!els.systemStatus) {
            return;
        }

        const safeText = normalizeStatusText(text);
        els.systemStatus.textContent = 'System Status: ' + safeText;

        if (isWaitingStatus(safeText)) {
            els.systemStatus.classList.add('waiting');
        } else {
            els.systemStatus.classList.remove('waiting');
        }

        updateButtonsFromStatus(safeText);
    }

    function updateHelperText() {
        if (!els.helperText || !els.modeSelect) {
            return;
        }

        const mode = normalizeMode(els.modeSelect.value);

        if (mode === 'BM' || mode === 'TGIF') {
            els.helperText.textContent =
                'For BM and TGIF, press Connect once to prepare the network. Wait for the status to show ready, then press Connect again for the final talkgroup connect. After a true connection, Connect will dim and Disconnect will become active.';
            return;
        }

        if (mode === 'YSF') {
            els.helperText.textContent =
                'YSF is a one-step connect. Enter a YSF target or load a YSF favorite, then press Connect.';
            return;
        }

        if (mode === 'ASL') {
            els.helperText.textContent =
                'AllStar is a one-step connect. Enter a node number or load an AllStar favorite, then press Connect. If DVSwitch auto-load is enabled, it will use the selected mode when loaded.';
            return;
        }

        els.helperText.textContent =
            'Select a network, enter or load a target, then press Connect.';
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

        if (!Array.isArray(items) || items.length === 0) {
            els.favoritesBody.innerHTML = '<tr><td colspan="5">No favorites saved yet.</td></tr>';
            return;
        }

        const rows = items.map((item) => {
            const target = escapeHtml(item.target ?? item.tg ?? '');
            const name = escapeHtml(item.name ?? '');
            const description = escapeHtml(item.description ?? item.desc ?? '-');
            const mode = escapeHtml(normalizeMode(item.mode ?? 'BM'));

            return `
                <tr data-target="${target}" data-mode="${mode}">
                    <td class="favorite-target">${target}</td>
                    <td>${name}</td>
                    <td>${description}</td>
                    <td class="favorite-mode">${mode}</td>
                    <td><span class="load-button">Load</span></td>
                </tr>
            `;
        });

        els.favoritesBody.innerHTML = rows.join('');
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

        const dvsNode = String(config.dvswitch_node || '').trim();

        const autoLoadValue = autoload
            ? `Enabled${dvsNode ? ` (${dvsNode})` : ''}`
            : 'Disabled';

        updateActivityValue('Last Mode', lastMode || '-');
        updateActivityValue('Last Target', lastTarget || '-');
        updateActivityValue('Pending Target', pendingTarget || '-');
        updateActivityValue(
            'DMR Network',
            dmrNetwork ? `${dmrNetwork}${dmrReady ? ' (Ready)' : ' (Preparing)'}` : '-'
        );
        updateActivityValue('DVSwitch Auto-Load', autoLoadValue);
        updateActivityValue('DVSwitch Auto-Load Mode', autoloadModeLabel(autoloadMode));
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

        if (allowFieldSync && els.modeSelect && !state.busy) {
            if (typeof payload.selected_mode === 'string') {
                els.modeSelect.value = normalizeMode(payload.selected_mode);
            } else if (typeof system.selected_mode === 'string') {
                els.modeSelect.value = normalizeMode(system.selected_mode);
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

        if (Array.isArray(payload.favorites)) {
            renderFavorites(payload.favorites);
        }

        refreshActivityPanel(payload);
        updateHelperText();
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
        try {
            const payload = await requestJson(state.endpoints.status, {
                method: 'GET',
            });

            applyLiveStatus(payload, { allowFieldSync: false });
        } catch (error) {
            console.error(error);
            setSystemStatus('ERROR: STATUS UNAVAILABLE');
            updateActivityValue('Current Status', 'ERROR: STATUS UNAVAILABLE');
        }
    }

    async function sendAction(action) {
        if (!els.targetInput || !els.modeSelect || !els.autoloadCheckbox || !els.autoloadModeSelect) {
            return;
        }

        const payload = {
            action,
            action_type: action,
            target: els.targetInput.value.trim(),
            tgNum: els.targetInput.value.trim(),
            mode: normalizeMode(els.modeSelect.value),
            autoload_dvswitch: els.autoloadCheckbox.checked ? 1 : 0,
            autoload_dvswitch_mode: normalizeAutoloadMode(els.autoloadModeSelect.value),
        };

        setBusy(true);

        try {
            const responsePayload = await requestJson(state.endpoints.connect, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            applyLiveStatus(responsePayload, { allowFieldSync: true });
        } catch (error) {
            console.error(error);
            setSystemStatus('ERROR: REQUEST FAILED');
            updateActivityValue('Current Status', 'ERROR: REQUEST FAILED');
        } finally {
            setBusy(false);
        }
    }

    async function rememberAutoloadPreference() {
        if (!els.autoloadCheckbox || !els.autoloadModeSelect) {
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
                }),
            });

            applyLiveStatus(payload, { allowFieldSync: false });
        } catch (error) {
            console.error(error);
        }
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
            els.modeSelect.value = mode;
            updateHelperText();

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

        state.pollTimer = window.setInterval(() => {
            if (!state.busy) {
                loadStatus();
            }
        }, state.pollIntervalMs);
    }

    function init() {
        if (!hasCoreElements()) {
            return;
        }

        if (els.modeSelect) {
            els.modeSelect.addEventListener('change', updateHelperText);
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

        if (els.autoloadCheckbox) {
            els.autoloadCheckbox.addEventListener('change', rememberAutoloadPreference);
        }

        if (els.autoloadModeSelect) {
            els.autoloadModeSelect.addEventListener('change', rememberAutoloadPreference);
        }

        if (els.controlForm) {
            els.controlForm.addEventListener('submit', (event) => {
                event.preventDefault();
            });
        }

        wireFavoritesLoad();
        updateHelperText();
        loadStatus();
        startPolling();
    }

    document.addEventListener('DOMContentLoaded', init);
})();