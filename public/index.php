<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\App\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/AppCsrf.php';

use App\Support\AppAuth;
use App\Support\Config;

$config = new Config(dirname(__DIR__) . '/config.ini');
$auth = new AppAuth($config);
$authEnabled = $auth->isEnabled();
$authLoggedIn = $auth->isLoggedIn();
$authHttpsWarning = $authEnabled && !\App\Support\AppSession::isHttps();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_placeholder_config_value(mixed $value): bool
{
    $normalized = strtoupper(trim((string) $value));

    if ($normalized === '') {
        return true;
    }

    return in_array($normalized, [
        'CHANGE_ME',
        'YOUR NODE',
        'YOUR DVSWITCH NODE',
        'YOUR_REAL_PASSWORD',
        'YOUR_REAL_KEY',
        'YOUR PASSWORD',
        'YOUR KEY',
    ], true);
}

function config_flag_enabled(Config $config, string $key): bool
{
    $value = strtolower(trim((string) $config->get($key, '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$appName = 'AllTune2';
$repoUrl = 'https://github.com/TerryClaiborne/alltune2';
$remoteVersionUrl = 'https://raw.githubusercontent.com/TerryClaiborne/alltune2/main/VERSION';
$versionFile = dirname(__DIR__) . '/VERSION';
$localVersion = '0.0.0';

if (is_readable($versionFile)) {
    $versionText = trim((string) @file_get_contents($versionFile));
    if ($versionText !== '') {
        $localVersion = $versionText;
    }
}

$dvswitchNode = trim((string) $config->get('DVSWITCH_NODE', ''));
$myNode = trim((string) $config->get('MYNODE', ''));
$bmPassword = trim((string) $config->get('BM_SelfcarePassword', ''));
$tgifKey = trim((string) $config->get('TGIF_HotspotSecurityKey', ''));
$dstarEnabled = config_flag_enabled($config, 'DSTAR_ENABLED');
$p25Enabled = config_flag_enabled($config, 'P25_ENABLED');
$nxdnEnabled = config_flag_enabled($config, 'NXDN_ENABLED');
$dvswitchScriptAvailable = is_file('/opt/MMDVM_Bridge/dvswitch.sh');

$hasRealMyNode = !is_placeholder_config_value($myNode);
$hasRealDvSwitchNode = !is_placeholder_config_value($dvswitchNode);
$hasRealBmPassword = !is_placeholder_config_value($bmPassword);
$hasRealTgifKey = !is_placeholder_config_value($tgifKey);

$displayMyNode = $hasRealMyNode ? $myNode : 'Not Set';
$displayDvSwitchNode = $hasRealDvSwitchNode ? $dvswitchNode : '';

$modeAvailability = [
    'ASL'  => $hasRealMyNode,
    'ECHO' => $hasRealMyNode,
    'BM'   => $hasRealMyNode && $hasRealDvSwitchNode && $hasRealBmPassword,
    'TGIF'  => $hasRealMyNode && $hasRealDvSwitchNode && $hasRealTgifKey,
    'YSF'   => $hasRealMyNode && $hasRealDvSwitchNode,
    'DSTAR' => $hasRealMyNode && $hasRealDvSwitchNode && $dstarEnabled && $dvswitchScriptAvailable,
    'P25'   => $hasRealMyNode && $hasRealDvSwitchNode && $p25Enabled && $dvswitchScriptAvailable,
    'NXDN'  => $hasRealMyNode && $hasRealDvSwitchNode && $nxdnEnabled && $dvswitchScriptAvailable,
];

$autoloadDvSwitch = isset($_SESSION['autoload_dvswitch'])
    ? (bool) $_SESSION['autoload_dvswitch']
    : true;

$autoloadDvSwitchMode = strtolower(trim((string) ($_SESSION['autoload_dvswitch_mode'] ?? 'transceive')));
if ($autoloadDvSwitchMode !== 'local_monitor') {
    $autoloadDvSwitchMode = 'transceive';
}

$rawDvSwitchActiveMode = strtolower(trim((string) ($_SESSION['dvswitch_active_mode'] ?? '')));
$dvswitchActiveMode = in_array($rawDvSwitchActiveMode, ['local_monitor', 'transceive'], true)
    ? $rawDvSwitchActiveMode
    : '';

$disconnectBeforeConnect = isset($_SESSION['disconnect_before_connect'])
    ? (bool) $_SESSION['disconnect_before_connect']
    : false;

$selectedMode = strtoupper((string) ($_SESSION['selected_mode'] ?? 'BM'));
if (in_array($selectedMode, ['ALLSTAR', 'ALLSTAR LINK', 'ALLSTARLINK'], true)) {
    $selectedMode = 'ASL';
}
if (in_array($selectedMode, ['ECHO', 'ECHO LINK', 'ECHOLINK', 'EL', 'E/L'], true)) {
    $selectedMode = 'ECHO';
}

$targetValue = (string) ($_SESSION['pending_target'] ?? $_SESSION['last_target'] ?? '');
$lastStatus = (string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS');
$lastMode = strtoupper((string) ($_SESSION['last_mode'] ?? ''));
$lastTarget = (string) ($_SESSION['last_target'] ?? '');
$pendingTarget = (string) ($_SESSION['pending_target'] ?? $_SESSION['pending_tg'] ?? '');
$dmrNetwork = strtoupper((string) ($_SESSION['dmr_network'] ?? ''));
$dmrReady = !empty($_SESSION['dmr_ready']);
$dvswitchLinkActive = !empty($_SESSION['dvswitch_autoloaded']) || $dmrReady || in_array($lastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true);

$nodeStatsUrl = $hasRealMyNode
    ? 'https://stats.allstarlink.org/stats/' . rawurlencode($myNode)
    : 'https://stats.allstarlink.org/';

$dvswitchCockpitDir = dirname(__DIR__, 2) . '/dvswitch_cockpit';
$dvswitchHref = is_dir($dvswitchCockpitDir)
    ? '/dvswitch_cockpit/'
    : '/dvswitch/';

$navItems = [
    ['label' => 'Dashboard', 'href' => '/alltune2/public/index.php', 'active' => true],
    ['label' => 'Favorites', 'href' => '/alltune2/public/favorites.php', 'active' => false],
    ['label' => 'Node Stats', 'href' => $nodeStatsUrl, 'active' => false, 'target' => '_blank'],
    ['label' => 'DVSwitch', 'href' => $dvswitchHref, 'active' => false, 'target' => '_blank'],
];

$modeOptions = [
    'BM'   => 'BrandMeister (DMR)',
    'TGIF' => 'TGIF Network',
    'ASL'  => 'AllStarLink',
    'ECHO' => 'EchoLink',
    'YSF'  => 'System Fusion (YSF)',
];

if ($modeAvailability['DSTAR']) {
    $modeOptions['DSTAR'] = 'D-Star';
}

if ($modeAvailability['P25']) {
    $modeOptions['P25'] = 'P25';
}

if ($modeAvailability['NXDN']) {
    $modeOptions['NXDN'] = 'NXDN';
}

if (!array_key_exists($selectedMode, $modeOptions)) {
    $selectedMode = 'BM';
}

$activityLines = [];

$activityLines[] = [
    'label' => 'DVSwitch Auto-Load',
    'value' => $autoloadDvSwitch
        ? 'Enabled' . ($displayDvSwitchNode !== '' ? ' (' . $displayDvSwitchNode . ')' : '')
        : 'Disabled',
];
$activityLines[] = [
    'label' => 'Link Mode',
    'value' => $autoloadDvSwitchMode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
];
$activityLines[] = [
    'label' => 'DVSwitch Link Active',
    'value' => $dvswitchLinkActive ? 'Yes' : 'No',
];
$activityLines[] = [
    'label' => 'Disconnect Before Connect',
    'value' => $disconnectBeforeConnect ? 'Enabled' : 'Disabled',
];
$activityLines[] = [
    'label' => 'Current Status',
    'value' => $lastStatus,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?></title>
    <script>
        (function () {
            try {
                var savedTheme = window.localStorage.getItem('alltune2_theme');
                document.documentElement.setAttribute('data-theme', savedTheme === 'light' ? 'light' : 'dark');
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        }());
    </script>
    <link rel="stylesheet" href="/alltune2/public/assets/css/style.css">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="branding">
            <a
                class="branding-link"
                href="<?= e($repoUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Open AllTune2 GitHub repository"
            >
                <h1
                    class="branding-title"
                    id="branding-title"
                    data-local-version="<?= e($localVersion) ?>"
                    data-version-url="<?= e($remoteVersionUrl) ?>"
                    title="AllTune2 v<?= e($localVersion) ?>"
                >
                    <span class="branding-title-text"><?= e($appName) ?></span>
                    <span
                        class="branding-title-bolt"
                        id="update-indicator"
                        aria-hidden="true"
                        title="Installed version: v<?= e($localVersion) ?>"
                    >&#9889;</span>
                </h1>
            </a>
            <div class="branding-subtitle">
                Modernized control flow with backend-first switching
            </div>
        </div>

        <div class="nav-row">
            <div class="theme-toggle-group" aria-label="Theme selector">
                <span class="theme-toggle-caption">Theme</span>
                <button
                    type="button"
                    class="theme-toggle"
                    id="theme-toggle"
                    aria-label="Toggle light and dark theme"
                    role="switch"
                    aria-checked="false"
                >
                    <span class="theme-toggle-text theme-toggle-text-light">Light</span>
                    <span class="theme-toggle-text theme-toggle-text-dark">Dark</span>
                    <span class="theme-toggle-thumb" aria-hidden="true"></span>
                </button>
            </div>

            <nav class="nav" aria-label="Primary">
                <?php foreach ($navItems as $item): ?>
                    <a
                        class="nav-button<?= !empty($item['active']) ? ' active' : '' ?>"
                        href="<?= e($item['href']) ?>"
                        <?= isset($item['target']) ? ' target="' . e((string) $item['target']) . '"' : '' ?>
                    >
                        <?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="auth-status" aria-label="Authentication status">
                <?php if (!$authEnabled): ?>
                    <span class="auth-pill auth-pill-normal">Normal Mode</span>
                <?php elseif ($authLoggedIn): ?>
                    <span class="auth-pill auth-pill-signed-in">Signed In</span>
                    <a class="auth-link" href="/alltune2/public/logout.php">Logout</a>
                <?php else: ?>
                    <span class="auth-pill auth-pill-view-only">View Only</span>
                    <a class="auth-link" href="/alltune2/public/login.php">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($authHttpsWarning): ?>
        <div class="auth-https-warning">
            Web login is enabled, but this page is not using HTTPS. Use HTTPS or a VPN before allowing outside access.
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/alltune2_ribbon_bar.php'; ?>

    <main class="dashboard-grid">
        <section class="left-stack">
            <article class="card">
                <div class="card-header">
                    <span>Control Center</span>
                    <a
                        class="badge control-node-badge"
                        href="<?= e($nodeStatsUrl) ?>"
                        target="_blank"
                        rel="noopener"
                        title="Open AllStar stats for node <?= e($displayMyNode) ?>"
                    >Node <?= e($displayMyNode) ?></a>
                </div>

                <div class="card-body">
                    <form
                        id="control-form"
                        autocomplete="off"
                        data-config-path="/var/www/html/alltune2/config.ini"
                        data-has-real-mynode="<?= $hasRealMyNode ? '1' : '0' ?>"
                        data-has-real-dvswitch-node="<?= $hasRealDvSwitchNode ? '1' : '0' ?>"
                        data-dvswitch-node="<?= e($displayDvSwitchNode) ?>"
                        data-has-real-bm-password="<?= $hasRealBmPassword ? '1' : '0' ?>"
                        data-has-real-tgif-key="<?= $hasRealTgifKey ? '1' : '0' ?>"
                        data-asl-configured="<?= $modeAvailability['ASL'] ? '1' : '0' ?>"
                        data-echo-configured="<?= $modeAvailability['ECHO'] ? '1' : '0' ?>"
                        data-bm-configured="<?= $modeAvailability['BM'] ? '1' : '0' ?>"
                        data-tgif-configured="<?= $modeAvailability['TGIF'] ? '1' : '0' ?>"
                        data-ysf-configured="<?= $modeAvailability['YSF'] ? '1' : '0' ?>"
                        data-dstar-configured="<?= $modeAvailability['DSTAR'] ? '1' : '0' ?>"
                        data-p25-configured="<?= $modeAvailability['P25'] ? '1' : '0' ?>"
                        data-nxdn-configured="<?= $modeAvailability['NXDN'] ? '1' : '0' ?>"
                    >
                        <div class="control-panel-redesign">
                            <div class="control-top-row">
                                <label class="sr-only" for="target">TG / Node #</label>
                                <input
                                    id="target"
                                    name="target"
                                    class="control control-target-entry"
                                    type="text"
                                    placeholder="TG / Node #"
                                    value="<?= e($targetValue) ?>"
                                >

                                <label class="sr-only" for="mode">Mode</label>
                                <select id="mode" name="mode" class="control control-network-select">
                                    <?php foreach ($modeOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $selectedMode === $value ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="sr-only" for="autoload_dvswitch_mode">Link Mode</label>
                                <select
                                    id="autoload_dvswitch_mode"
                                    name="autoload_dvswitch_mode"
                                    class="control control-link-mode-select"
                                    aria-label="Link Mode"
                                >
                                    <option value="transceive" <?= $autoloadDvSwitchMode === 'transceive' ? 'selected' : '' ?>>
                                        Transceive
                                    </option>
                                    <option value="local_monitor" <?= $autoloadDvSwitchMode === 'local_monitor' ? 'selected' : '' ?>>
                                        Local Monitor
                                    </option>
                                </select>

                                <button
                                    type="button"
                                    class="control-save-favorite-button"
                                    id="save-favorite-button"
                                    title="Save current manual entry as a favorite"
                                    aria-haspopup="dialog"
                                >
                                    <span class="control-save-icon" aria-hidden="true">☆</span>
                                    <span class="control-save-text">Save<br>Favorite</span>
                                </button>
                            </div>

                            <div class="control-action-row">
                                <button type="button" class="btn btn-primary control-action-button" id="connect-button">
                                    Connect
                                </button>

                                <button type="button" class="btn btn-danger control-action-button" id="disconnect-button">
                                    Disconnect
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-warning control-action-button"
                                    id="disconnect-dvswitch-button"
                                >
                                    Disconnect DVSwitch
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-danger control-action-button btn-with-subtext"
                                    id="disconnect-all-button"
                                >
                                    <span class="btn-main">Disconnect All</span>
                                    <span class="btn-subtext">Restart Asterisk</span>
                                </button>
                            </div>

                            <div class="control-utility-row control-utility-row-compact">
                                <input
                                    type="checkbox"
                                    id="autoload_dvswitch"
                                    name="autoload_dvswitch"
                                    value="1"
                                    class="private-link-managed-input"
                                    <?= $autoloadDvSwitch ? 'checked' : '' ?>
                                    aria-hidden="true"
                                    tabindex="-1"
                                >

                                <div class="control-checkbox-group control-checkbox-group-primary">
                                    <label class="checkbox-inline" for="disconnect_before_connect">
                                        <input
                                            type="checkbox"
                                            id="disconnect_before_connect"
                                            name="disconnect_before_connect"
                                            value="1"
                                            <?= $disconnectBeforeConnect ? 'checked' : '' ?>
                                        >
                                        <span>Disconnect before Connect</span>
                                    </label>

                                    <label class="checkbox-inline" for="audio_alerts">
                                        <input
                                            type="checkbox"
                                            id="audio_alerts"
                                            name="audio_alerts"
                                            value="1"
                                            checked
                                        >
                                        <span>Audio Alerts</span>
                                    </label>
                                </div>

                                <div class="dtmf-control-group">
                                    <label class="dtmf-control-label" for="dtmf-code">
                                        DTMF
                                    </label>

                                    <input
                                        id="dtmf-code"
                                        name="dtmf_code"
                                        class="dtmf-control-input"
                                        type="text"
                                        value=""
                                        placeholder="*70 or 1234#"
                                        maxlength="14"
                                        aria-label="DTMF Command"
                                    >

                                    <button
                                        type="button"
                                        class="btn btn-primary dtmf-send-button"
                                        id="send-dtmf-button"
                                        disabled
                                    >
                                        Send
                                    </button>
                                </div>
                            </div>

                            <div class="inline-status-wrap control-status-row" aria-live="polite">
                                <span class="inline-status-label">Status</span>
                                <span
                                    class="inline-status-value<?= str_starts_with(strtoupper($lastStatus), 'WAITING') ? ' waiting' : '' ?>"
                                    id="system-status"
                                >
                                    <?= e($lastStatus) ?>
                                </span>
                            </div>
                        </div>

                    </form>
                </div>
            </article>

            <article class="card" id="network-center-section">
                <div class="card-header">
                    <span>Network Center</span>
                    <span class="meta-line">Flow and guidance</span>
                </div>
                <div class="card-body">
                    <div class="helper-panel helper-panel-standalone" id="helper-panel">
                        <div class="helper-title">Network Flow</div>
                        <p class="helper-text" id="helper-text">
                            BrandMeister, TGIF, YSF, AllStarLink, and EchoLink are all one-step connects. Enter or load the target and press Connect once. BrandMeister private calls are supported with a single trailing #. Disconnect removes the current managed connection. Disconnect DVSwitch removes only the configured DVSwitch link and stops BM receive mode if it is active. Disconnect All performs a full reset. With Disconnect before Connect off, BM, TGIF, YSF, D-Star, P25, or NXDN can stay up while you add direct AllStarLink or EchoLink connections. When Disconnect before Connect is on, a new managed connect clears the earlier managed session first. When DVSwitch auto-load is enabled, the configured DVSwitch link is loaded using the selected link mode.
                        </p>
                    </div>
                </div>
            </article>
        </section>

        <aside class="right-stack">
            <article class="card">
                <div class="card-header">
                    <span>Live Status</span>
                    <span class="meta-line">Read only</span>
                </div>
                <div class="card-body">
                    <div class="status-grid">
                        <div class="status-box">
                            <div class="status-box-label">BrandMeister</div>
                            <div class="status-box-value" id="status-bm">Idle</div>
                        </div>
                        <div class="status-box">
                            <div class="status-box-label">TGIF</div>
                            <div class="status-box-value" id="status-tgif">Idle</div>
                        </div>
                        <div class="status-box">
                            <div class="status-box-label">YSF</div>
                            <div class="status-box-value" id="status-ysf">Idle</div>
                        </div>
                        <div class="status-box">
                            <div class="status-box-label">D-Star</div>
                            <div class="status-box-value" id="status-dstar">Idle</div>
                        </div>
                        <div class="status-box">
                            <div class="status-box-label">P25</div>
                            <div class="status-box-value" id="status-p25">Idle</div>
                        </div>
                        <div class="status-box">
                            <div class="status-box-label">NXDN</div>
                            <div class="status-box-value" id="status-nxdn">Idle</div>
                        </div>
                        <div class="status-box status-box-wide">
                            <div class="status-box-label">AllStarLink / EchoLink</div>
                            <div class="status-box-value" id="status-allstar">No links</div>
                            <div id="status-allstar-links">
                                <div>No links</div>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="card activity-card">
                <div class="card-header">
                    <span>Activity</span>
                    <span class="meta-line">Read only</span>
                </div>
                <div class="card-body">
                    <div class="activity-list">
                        <?php foreach ($activityLines as $line): ?>
                            <div class="activity-row">
                                <div class="activity-label"><?= e($line['label']) ?></div>
                                <div class="activity-value"><?= e($line['value']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        </aside>
    </main>

    <section class="favorites-section" id="favorites-section">
        <article class="card favorites-card">
            <div class="card-header">
                <span>Saved Favorites</span>
                <span class="meta-line favorites-network-line">
                    <span class="shared-label">Shared —</span>
                    <span class="net-bm">BM</span>
                    <span class="separator">/</span>
                    <span class="net-tgif">TGIF</span>
                    <span class="separator">/</span>
                    <span class="net-ysf">YSF</span>
                    <span class="separator">/</span>
                    <span class="net-dstar">D-Star</span>
                    <span class="separator">/</span>
                    <span class="net-p25">P25</span>
                    <span class="separator">/</span>
                    <span class="net-nxdn">NXDN</span>
                    <span class="separator">/</span>
                    <span class="net-asl">AllStar</span>
                    <span class="separator">/</span>
                    <span class="net-echo">EchoLink</span>
                </span>
            </div>
            <div class="card-body card-body-tight">
                <div class="favorites-table-wrap">
                    <table class="favorites-table" id="favorites-table">
                        <thead>
                        <tr>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="target" data-sort-type="mixed" title="Sort by TG / Node #">
                                    <span class="favorites-sort-label">TG / Node #</span>
                                    <span class="favorites-sort-hint">sort target</span>
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="name" data-sort-type="text" title="Sort by Station Name">
                                    <span class="favorites-sort-label">Station Name</span>
                                    <span class="favorites-sort-hint">sort name</span>
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="description" data-sort-type="text" title="Sort by Description">
                                    <span class="favorites-sort-label">Description</span>
                                    <span class="favorites-sort-hint">sort notes</span>
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="mode" data-sort-type="text" title="Sort by Mode">
                                    <span class="favorites-sort-label">Mode</span>
                                    <span class="favorites-sort-hint">sort network</span>
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody id="favorites-body">
                        <tr>
                            <td colspan="5">Loading favorites...</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="favorites-note">
                    Shared favorites for BM, TGIF, YSF, D-Star, P25, NXDN, AllStar, and EchoLink.
                </div>
            </div>
        </article>
    </section>
</div>

    <div class="favorite-modal-backdrop" id="save-favorite-modal" hidden aria-hidden="true">
        <div class="favorite-modal-card" role="dialog" aria-modal="true" aria-labelledby="save-favorite-title">
            <button type="button" class="favorite-modal-close" id="save-favorite-close" aria-label="Close Save Favorite">
                ×
            </button>

            <h2 id="save-favorite-title">Save Favorite</h2>
            <p class="favorite-modal-help">
                Saves directly from the Control Center without leaving the dashboard.
            </p>

            <div class="favorite-modal-summary">
                <div>
                    <span class="favorite-modal-summary-label">Target / TG / Node</span>
                    <strong id="save-favorite-target-value">—</strong>
                </div>
                <div>
                    <span class="favorite-modal-summary-label">Network / Mode</span>
                    <strong id="save-favorite-mode-value">—</strong>
                </div>
            </div>

            <form id="save-favorite-form" class="favorite-modal-form">
                <label for="save-favorite-name">Station Name</label>
                <input
                    type="text"
                    id="save-favorite-name"
                    maxlength="96"
                    autocomplete="off"
                    placeholder="Local BM TG 91"
                >

                <label for="save-favorite-description">Description</label>
                <input
                    type="text"
                    id="save-favorite-description"
                    maxlength="180"
                    autocomplete="off"
                    placeholder="Quick access favorite"
                >

                <div class="favorite-modal-message" id="save-favorite-message" aria-live="polite"></div>

                <div class="favorite-modal-actions">

                    <button type="button" class="favorite-modal-cancel" id="save-favorite-cancel">
                        Cancel
                    </button>

                    <button type="submit" class="favorite-modal-save" id="save-favorite-submit">
                        Save Favorite
                    </button>
                </div>
            </form>
        </div>
    </div>


<script>
window.ALLTUNE2_AUTH = {
    enabled: <?= $authEnabled ? 'true' : 'false' ?>,
    loggedIn: <?= $authLoggedIn ? 'true' : 'false' ?>,
    canWrite: <?= (!$authEnabled || $authLoggedIn) ? 'true' : 'false' ?>,
    csrfToken: <?= json_encode(\App\Support\AppCsrf::token()) ?>
};
</script>

<script>
(function () {
    const dtmfInput = document.getElementById('dtmf-code');
    const sendButton = document.getElementById('send-dtmf-button');

    if (!dtmfInput || !sendButton) {
        return;
    }

    const syncDtmfUi = () => {
        const canWrite = !window.ALLTUNE2_AUTH || window.ALLTUNE2_AUTH.canWrite !== false;
        const hasValue = canWrite && String(dtmfInput.value || '').trim() !== '';
        sendButton.disabled = !hasValue;
        sendButton.style.opacity = hasValue ? '1' : '0.45';
        sendButton.style.cursor = hasValue ? 'pointer' : 'not-allowed';
    };

    dtmfInput.addEventListener('input', syncDtmfUi);
    syncDtmfUi();
}());
</script>

<script src="/alltune2/public/assets/js/theme.js"></script>
<script src="/alltune2/public/assets/js/app.js"></script>
<script>
(function () {
    const dtmfInput = document.getElementById('dtmf-code');
    const sendButton = document.getElementById('send-dtmf-button');

    if (!dtmfInput || !sendButton) {
        return;
    }

    function syncDtmfUi() {
        const canWrite = !window.ALLTUNE2_AUTH || window.ALLTUNE2_AUTH.canWrite !== false;
        const hasValue = canWrite && String(dtmfInput.value || '').trim() !== '';

        sendButton.disabled = !hasValue;
        sendButton.style.opacity = hasValue ? '1' : '0.38';
        sendButton.style.cursor = hasValue ? 'pointer' : 'not-allowed';
        sendButton.style.filter = hasValue ? 'none' : 'saturate(0.45) brightness(0.88)';
    }

    dtmfInput.addEventListener('input', syncDtmfUi);
    syncDtmfUi();
}());
</script>
</body>
</html>
