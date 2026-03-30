<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/app/Support/Config.php';

use App\Support\Config;

$config = new Config(dirname(__DIR__) . '/config.ini');

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

$appName = 'AllTune2';

$dvswitchNode = trim((string) $config->get('DVSWITCH_NODE', ''));
$myNode = trim((string) $config->get('MYNODE', ''));
$bmPassword = trim((string) $config->get('BM_SelfcarePassword', ''));
$tgifKey = trim((string) $config->get('TGIF_HotspotSecurityKey', ''));

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
    'TGIF' => $hasRealMyNode && $hasRealDvSwitchNode && $hasRealTgifKey,
    'YSF'  => $hasRealMyNode && $hasRealDvSwitchNode,
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
$dvswitchLinkActive = !empty($_SESSION['dvswitch_autoloaded']) || $dmrReady || $lastMode === 'YSF';

$navItems = [
    ['label' => 'Dashboard', 'href' => '/alltune2/public/index.php', 'active' => true],
    ['label' => 'Favorites', 'href' => '/alltune2/public/favorites.php', 'active' => false],
    ['label' => 'Allscan', 'href' => '/allscan/', 'active' => false, 'target' => '_blank'],
    ['label' => 'DVSwitch', 'href' => '/dvswitch/', 'active' => false, 'target' => '_blank'],
];

$modeOptions = [
    'BM'   => 'BrandMeister (DMR)',
    'TGIF' => 'TGIF Network',
    'ASL'  => 'AllStar Link',
    'ECHO' => 'EchoLink',
    'YSF'  => 'System Fusion (YSF)',
];

$activityLines = [];

if ($lastMode !== '') {
    $activityLines[] = [
        'label' => 'Last Mode',
        'value' => $lastMode,
    ];
}

if ($lastTarget !== '') {
    $activityLines[] = [
        'label' => 'Last Target',
        'value' => $lastTarget,
    ];
}

if ($pendingTarget !== '') {
    $activityLines[] = [
        'label' => 'Pending Target',
        'value' => $pendingTarget,
    ];
}

if ($dmrNetwork !== '') {
    $activityLines[] = [
        'label' => 'DMR Network',
        'value' => $dmrNetwork . ($dmrReady ? ' (Ready)' : ' (Preparing)'),
    ];
}

$activityLines[] = [
    'label' => 'DVSwitch Auto-Load',
    'value' => $autoloadDvSwitch
        ? 'Enabled' . ($displayDvSwitchNode !== '' ? ' (' . e($displayDvSwitchNode) . ')' : '')
        : 'Disabled',
];
$activityLines[] = [
    'label' => 'Link Mode',
    'value' => $autoloadDvSwitchMode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
];
$activityLines[] = [
    'label' => 'DVSwitch Active Link Mode',
    'value' => $dvswitchActiveMode === 'local_monitor'
        ? 'Local Monitor'
        : ($dvswitchActiveMode === 'transceive' ? 'Transceive' : '-'),
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
    <link rel="stylesheet" href="/alltune2/public/assets/css/style.css">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="branding">
            <h1 class="branding-title"><?= e($appName) ?></h1>
            <div class="branding-subtitle">
                Modernized control flow with backend-first switching
            </div>
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
    </header>

    <main class="dashboard-grid">
        <section class="left-stack">
            <article class="card">
                <div class="card-header">
                    <span>Control Center</span>
                    <span class="badge">Node <?= e($displayMyNode) ?></span>
                </div>

                <div class="card-body">
                    <form
                        id="control-form"
                        autocomplete="off"
                        data-config-path="/var/www/html/alltune2/config.ini"
                        data-has-real-mynode="<?= $hasRealMyNode ? '1' : '0' ?>"
                        data-has-real-dvswitch-node="<?= $hasRealDvSwitchNode ? '1' : '0' ?>"
                        data-has-real-bm-password="<?= $hasRealBmPassword ? '1' : '0' ?>"
                        data-has-real-tgif-key="<?= $hasRealTgifKey ? '1' : '0' ?>"
                        data-asl-configured="<?= $modeAvailability['ASL'] ? '1' : '0' ?>"
                        data-bm-configured="<?= $modeAvailability['BM'] ? '1' : '0' ?>"
                        data-tgif-configured="<?= $modeAvailability['TGIF'] ? '1' : '0' ?>"
                        data-ysf-configured="<?= $modeAvailability['YSF'] ? '1' : '0' ?>"
                    >
                        <div class="control-grid">
                            <label class="sr-only" for="target">TG / Node / YSF #</label>
                            <input
                                id="target"
                                name="target"
                                class="control"
                                type="text"
                                placeholder="TG / Node / YSF #"
                                value="<?= e($targetValue) ?>"
                            >

                            <label class="sr-only" for="mode">Mode</label>
                            <select id="mode" name="mode" class="control">
                                <?php foreach ($modeOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $selectedMode === $value ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="btn btn-primary" id="connect-button">
                                Connect
                            </button>

                            <button type="button" class="btn btn-danger" id="disconnect-button">
                                Disconnect
                            </button>
                        </div>

                        <div class="control-settings-grid">
                            <div class="control-settings-left">
                                <label class="checkbox-inline" for="autoload_dvswitch">
                                    <input
                                        type="checkbox"
                                        id="autoload_dvswitch"
                                        name="autoload_dvswitch"
                                        value="1"
                                        <?= $autoloadDvSwitch ? 'checked' : '' ?>
                                    >
                                    <span>
                                        <span>
                                            Auto-connect DVSwitch link<?= $displayDvSwitchNode !== '' ? ' (' . e($displayDvSwitchNode) . ')' : '' ?>
                                        </span>
                                    </span>
                                </label>

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
                            </div>

                            <div class="control-settings-right">
                                <button
                                    type="button"
                                    class="btn btn-warning"
                                    id="disconnect-dvswitch-button"
                                >
                                    Disconnect DVSwitch
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    id="disconnect-all-button"
                                >
                                    Disconnect All
                                </button>
                            </div>
                        </div>

                        <div class="control-mode-row">
                            <label class="control-mode-label" for="autoload_dvswitch_mode">
                                Link Mode
                            </label>

                            <select
                                id="autoload_dvswitch_mode"
                                name="autoload_dvswitch_mode"
                                class="control control-compact"
                                aria-label="Link Mode"
                            >
                                <option value="transceive" <?= $autoloadDvSwitchMode === 'transceive' ? 'selected' : '' ?>>
                                    Transceive
                                </option>
                                <option value="local_monitor" <?= $autoloadDvSwitchMode === 'local_monitor' ? 'selected' : '' ?>>
                                    Local Monitor
                                </option>
                            </select>
                        </div>

                        <div class="helper-panel" id="helper-panel">
                            <div class="helper-title">Network Flow</div>
                            <p class="helper-text" id="helper-text">
                                For BM and TGIF, enter or load a talkgroup, press Connect, wait until the system is ready, then press Connect again. AllStar Link, EchoLink, and YSF are one-step connects. When DVSwitch auto-load is enabled, the configured DVSwitch link will be loaded using the selected mode.
                            </p>
                        </div>
                    </form>
                </div>
            </article>

            <article class="card" id="status-section">
                <div class="card-header">
                    <span>System Status</span>
                    <span class="meta-line">Config-driven DVSwitch auto-load</span>
                </div>
                <div class="status-line<?= str_starts_with(strtoupper($lastStatus), 'WAITING') ? ' waiting' : '' ?>" id="system-status">
                    System Status: <?= e($lastStatus) ?>
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
                            <div class="status-box-label">AllStarLink / EchoLink</div>
                            <div class="status-box-value" id="status-allstar">No links</div>
                            <div id="status-allstar-links">
                                <div>No links</div>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="card">
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
                <span class="meta-line">Shared BM / TGIF / YSF / AllStar / EchoLink</span>
            </div>
            <div class="card-body card-body-tight">
                <div class="favorites-table-wrap">
                    <table class="favorites-table" id="favorites-table">
                        <thead>
                        <tr>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="target" data-sort-type="mixed">
                                    TG / Node / YSF
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="name" data-sort-type="text">
                                    Station Name
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="description" data-sort-type="text">
                                    Description
                                    <span class="favorites-sort-indicator" aria-hidden="true"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="favorites-sort-button" data-sort-key="mode" data-sort-type="text">
                                    Mode
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
                    Shared favorites for BM, TGIF, YSF, AllStar, and EchoLink.
                </div>
            </div>
        </article>
    </section>
</div>

<script src="/alltune2/public/assets/js/app.js"></script>
</body>
</html>