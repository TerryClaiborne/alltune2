<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/app/Support/Config.php';

use App\Support\Config;

header('Content-Type: application/json');

$config = new Config(dirname(__DIR__) . '/config.ini');

function respond(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');

    if (stripos($contentType, 'application/json') !== false && $raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

function shell_run(string $command): string
{
    $output = shell_exec($command . ' 2>&1');
    return is_string($output) ? trim($output) : '';
}

function asterisk_rpt_fun(string $node, string $digits): string
{
    $command = 'sudo /usr/sbin/asterisk -rx ' . escapeshellarg("rpt fun {$node} {$digits}");
    return shell_run($command);
}

function asterisk_rpt_cmd(string $node, string $command): string
{
    $full = 'sudo /usr/sbin/asterisk -rx ' . escapeshellarg("rpt cmd {$node} {$command}");
    return shell_run($full);
}

function asterisk_ilink_disconnect(string $node, string $remoteNode): string
{
    return asterisk_rpt_cmd($node, "ilink 1 {$remoteNode}");
}

function asterisk_ilink_connect(string $node, string $remoteNode, string $linkMode): string
{
    $ilink = $linkMode === 'local_monitor' ? '8' : '3';
    return asterisk_rpt_cmd($node, "ilink {$ilink} {$remoteNode}");
}

function load_dvswitch_link(string $myNode, string $dvSwitchNode, string $autoloadMode): string
{
    return asterisk_ilink_connect($myNode, $dvSwitchNode, $autoloadMode);
}

function dvswitch_tune(string $value): string
{
    $command = '/opt/MMDVM_Bridge/dvswitch.sh tune ' . escapeshellarg($value);
    return shell_run($command);
}

function dvswitch_mode(string $value): string
{
    $command = '/opt/MMDVM_Bridge/dvswitch.sh mode ' . escapeshellarg($value);
    return shell_run($command);
}

function pause_seconds(float $seconds): void
{
    usleep((int) round($seconds * 1000000));
}

function is_dmr_mode(string $mode): bool
{
    return in_array($mode, ['BM', 'TGIF'], true);
}

function normalize_mode(string $mode): string
{
    $mode = strtoupper(trim($mode));
    if ($mode === 'ALLSTAR') {
        return 'ASL';
    }

    return $mode;
}

function normalize_autoload_dvswitch_mode(mixed $mode): string
{
    $value = strtolower(trim((string) $mode));
    return $value === 'local_monitor' ? 'local_monitor' : 'transceive';
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

function has_real_config_value(mixed $value): bool
{
    return !is_placeholder_config_value($value);
}

function ensure_allstar_tracking_structures(): void
{
    if (!isset($_SESSION['allstar_link_modes']) || !is_array($_SESSION['allstar_link_modes'])) {
        $_SESSION['allstar_link_modes'] = [];
    }

    if (!isset($_SESSION['allstar_link_order']) || !is_array($_SESSION['allstar_link_order'])) {
        $_SESSION['allstar_link_order'] = [];
    }
}

function track_allstar_link(string $node, string $mode): void
{
    ensure_allstar_tracking_structures();

    $_SESSION['allstar_link_modes'][$node] = normalize_autoload_dvswitch_mode($mode);

    $order = array_values(array_filter(
        $_SESSION['allstar_link_order'],
        static fn ($value) => trim((string) $value) !== '' && trim((string) $value) !== $node
    ));

    $order[] = $node;
    $_SESSION['allstar_link_order'] = $order;
}

function untrack_allstar_link(string $node): void
{
    ensure_allstar_tracking_structures();

    unset($_SESSION['allstar_link_modes'][$node]);

    $_SESSION['allstar_link_order'] = array_values(array_filter(
        $_SESSION['allstar_link_order'],
        static fn ($value) => trim((string) $value) !== '' && trim((string) $value) !== $node
    ));
}

function clear_allstar_tracking(): void
{
    $_SESSION['allstar_link_modes'] = [];
    $_SESSION['allstar_link_order'] = [];
}

function last_tracked_allstar_node(): string
{
    ensure_allstar_tracking_structures();

    $order = $_SESSION['allstar_link_order'];
    if ($order === []) {
        return '';
    }

    $last = end($order);
    return is_string($last) ? trim($last) : '';
}

function clear_dmr_session_state(): void
{
    unset($_SESSION['pending_tg']);
}

function clear_dmr_active_state(): void
{
    unset(
        $_SESSION['dmr_active_network'],
        $_SESSION['dmr_active_target']
    );
}

function clear_runtime_targets(): void
{
    unset(
        $_SESSION['last_mode'],
        $_SESSION['last_target'],
        $_SESSION['pending_target'],
        $_SESSION['pending_tg'],
        $_SESSION['dmr_network'],
        $_SESSION['dmr_ready'],
        $_SESSION['dvswitch_autoloaded']
    );

    clear_dmr_active_state();
}

function disconnect_dvswitch_runtime(string $myNode, string $dvSwitchNode): void
{
    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));

    $hasDmrRuntime = $dmrNetwork === 'BM' || $dmrNetwork === 'TGIF';
    $hasYsfRuntime = $lastMode === 'YSF';
    $shouldDisconnectDvSwitchNode = $dvSwitchNode !== '' && (
        $dvswitchAutoloaded ||
        $hasDmrRuntime ||
        $hasYsfRuntime
    );

    if ($hasDmrRuntime || $hasYsfRuntime) {
        dvswitch_tune('disconnect');
        pause_seconds(1.0);
    }

    if ($shouldDisconnectDvSwitchNode) {
        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);
    }
}

function disconnect_only_dvswitch_link(string $myNode, string $dvSwitchNode): void
{
    if ($dvSwitchNode === '') {
        return;
    }

    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
    $dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);

    $hasDmrRuntime = $dmrNetwork === 'BM' || $dmrNetwork === 'TGIF';
    $hasYsfRuntime = $lastMode === 'YSF';

    if ($hasDmrRuntime || $hasYsfRuntime) {
        dvswitch_tune('disconnect');
        pause_seconds(1.0);
    }

    if ($dvswitchAutoloaded || $hasDmrRuntime || $hasYsfRuntime) {
        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);
    }

    unset(
        $_SESSION['dvswitch_autoloaded'],
        $_SESSION['dmr_network'],
        $_SESSION['dmr_ready'],
        $_SESSION['pending_tg']
    );

    clear_dmr_active_state();

    if ($hasYsfRuntime || $hasDmrRuntime) {
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
    }
}

function disconnect_all_managed_links(string $myNode, string $dvSwitchNode): void
{
    ensure_allstar_tracking_structures();

    $trackedOrder = array_reverse($_SESSION['allstar_link_order']);
    foreach ($trackedOrder as $trackedNode) {
        $trackedNode = trim((string) $trackedNode);
        if ($trackedNode === '') {
            continue;
        }

        asterisk_ilink_disconnect($myNode, $trackedNode);
        pause_seconds(0.5);
        untrack_allstar_link($trackedNode);
    }

    disconnect_dvswitch_runtime($myNode, $dvSwitchNode);
    clear_allstar_tracking();
    clear_runtime_targets();
}

function disconnect_managed_links_before_connect(string $myNode, string $dvSwitchNode): void
{
    disconnect_all_managed_links($myNode, $dvSwitchNode);
}

function session_payload(string $statusText, array $extra = []): array
{
    return array_merge([
        'ok' => !str_starts_with($statusText, 'ERROR:'),
        'status' => $statusText,
        'status_text' => $statusText,
        'last_status' => $statusText,
        'selected_mode' => (string) ($_SESSION['selected_mode'] ?? 'BM'),
        'last_mode' => (string) ($_SESSION['last_mode'] ?? ''),
        'last_target' => (string) ($_SESSION['last_target'] ?? ''),
        'pending_target' => (string) ($_SESSION['pending_target'] ?? ''),
        'pending_tg' => (string) ($_SESSION['pending_tg'] ?? ''),
        'autoload_dvswitch' => !empty($_SESSION['autoload_dvswitch']),
        'autoload_dvswitch_mode' => (string) ($_SESSION['autoload_dvswitch_mode'] ?? 'transceive'),
        'disconnect_before_connect' => !empty($_SESSION['disconnect_before_connect']),
        'dmr_network' => (string) ($_SESSION['dmr_network'] ?? ''),
        'dmr_ready' => !empty($_SESSION['dmr_ready']),
        'dmr_active_network' => (string) ($_SESSION['dmr_active_network'] ?? ''),
        'dmr_active_target' => (string) ($_SESSION['dmr_active_target'] ?? ''),
        'dvswitch_link_active' => !empty($_SESSION['dvswitch_autoloaded']) || !empty($_SESSION['dmr_ready']) || normalize_mode((string) ($_SESSION['last_mode'] ?? '')) === 'YSF',
    ], $extra);
}

$request = request_data();

$action = strtolower(trim((string) ($request['action'] ?? $request['action_type'] ?? '')));
$rawTarget = trim((string) ($request['target'] ?? $request['tgNum'] ?? ''));
$selectedNode = preg_replace('/[^0-9]/', '', (string) ($request['selected_node'] ?? '')) ?? '';
$mode = normalize_mode((string) ($request['mode'] ?? ($_SESSION['selected_mode'] ?? 'BM')));
$autoloadPosted = array_key_exists('autoload_dvswitch', $request);
$autoloadModePosted = array_key_exists('autoload_dvswitch_mode', $request);
$disconnectBeforeConnectPosted = array_key_exists('disconnect_before_connect', $request);

if ($autoloadPosted) {
    $_SESSION['autoload_dvswitch'] = !empty($request['autoload_dvswitch']);
} elseif (!isset($_SESSION['autoload_dvswitch'])) {
    $_SESSION['autoload_dvswitch'] = true;
}

if ($autoloadModePosted) {
    $_SESSION['autoload_dvswitch_mode'] = normalize_autoload_dvswitch_mode($request['autoload_dvswitch_mode']);
} elseif (!isset($_SESSION['autoload_dvswitch_mode'])) {
    $_SESSION['autoload_dvswitch_mode'] = 'transceive';
}

if ($disconnectBeforeConnectPosted) {
    $_SESSION['disconnect_before_connect'] = !empty($request['disconnect_before_connect']);
} elseif (!isset($_SESSION['disconnect_before_connect'])) {
    $_SESSION['disconnect_before_connect'] = false;
}

ensure_allstar_tracking_structures();
$_SESSION['selected_mode'] = $mode;

$myNode = $config->getString('MYNODE', '');
$dvSwitchNode = $config->getString('DVSWITCH_NODE', '');
$bmPassword = $config->getString('BM_SelfcarePassword', '');
$tgifPassword = $config->getString('TGIF_HotspotSecurityKey', '');
$autoloadDvSwitchMode = normalize_autoload_dvswitch_mode($_SESSION['autoload_dvswitch_mode'] ?? 'transceive');
$disconnectBeforeConnect = !empty($_SESSION['disconnect_before_connect']);

$hasRealMyNode = has_real_config_value($myNode);
$hasRealDvSwitchNode = has_real_config_value($dvSwitchNode);
$hasRealBmPassword = has_real_config_value($bmPassword);
$hasRealTgifPassword = has_real_config_value($tgifPassword);

if ($action === 'remember_autoload') {
    $status = (string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS');
    respond(session_payload($status));
}

if ($action === '') {
    respond(session_payload((string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS')));
}

if (
    $action !== 'connect' &&
    $action !== 'disconnect' &&
    $action !== 'disconnect_all' &&
    $action !== 'disconnect_selected' &&
    $action !== 'disconnect_dvswitch'
) {
    respond(session_payload('ERROR: INVALID ACTION'), 400);
}

if ($action === 'disconnect_all') {
    shell_run('sudo systemctl restart asterisk');
    pause_seconds(2.0);

    clear_allstar_tracking();
    clear_runtime_targets();

    $_SESSION['last_status'] = 'IDLE - NO CONNECTIONS';
    respond(session_payload($_SESSION['last_status']));
}

if ($action === 'disconnect_dvswitch') {
    if (!$hasRealMyNode) {
        $_SESSION['last_status'] = 'ERROR: MYNODE NOT CONFIGURED';
        respond(session_payload($_SESSION['last_status']), 500);
    }

    if (!$hasRealDvSwitchNode) {
        $_SESSION['last_status'] = 'ERROR: DVSWITCH NOT CONFIGURED';
        respond(session_payload($_SESSION['last_status']), 500);
    }

    disconnect_only_dvswitch_link($myNode, $dvSwitchNode);

    $_SESSION['last_status'] = 'DISCONNECTED: DVSWITCH LINK ' . $dvSwitchNode;
    respond(session_payload($_SESSION['last_status']));
}

if ($action === 'disconnect_selected') {
    if (!$hasRealMyNode) {
        $_SESSION['last_status'] = 'ERROR: MYNODE NOT CONFIGURED';
        respond(session_payload($_SESSION['last_status']), 500);
    }

    if ($selectedNode === '') {
        $_SESSION['last_status'] = 'ERROR: INVALID ALLSTAR NODE';
        respond(session_payload($_SESSION['last_status']), 422);
    }

    ensure_allstar_tracking_structures();

    $trackedModes = $_SESSION['allstar_link_modes'];
    $trackedOrder = $_SESSION['allstar_link_order'];

    $isTrackedDirectNode =
        array_key_exists($selectedNode, $trackedModes) ||
        in_array($selectedNode, $trackedOrder, true);

    if (!$isTrackedDirectNode) {
        $_SESSION['last_status'] = 'ERROR: ALLSTAR NODE NOT TRACKED';
        respond(session_payload($_SESSION['last_status']), 404);
    }

    asterisk_ilink_disconnect($myNode, $selectedNode);
    pause_seconds(0.5);
    untrack_allstar_link($selectedNode);

    $remainingTracked = last_tracked_allstar_node();
    if ($remainingTracked !== '') {
        $_SESSION['last_mode'] = 'ASL';
        $_SESSION['last_target'] = $remainingTracked;
        $_SESSION['pending_target'] = $remainingTracked;
    } else {
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
    }

    $_SESSION['last_status'] = 'DISCONNECTED: ALLSTAR NODE ' . $selectedNode;
    respond(session_payload($_SESSION['last_status']));
}

if ($action === 'connect') {
    $digitsOnlyTarget = preg_replace('/[^0-9]/', '', $rawTarget) ?? '';
    $_SESSION['selected_mode'] = $mode;

    if (is_dmr_mode($mode) && $digitsOnlyTarget === '') {
        $pendingTarget = (string) ($_SESSION['pending_target'] ?? $_SESSION['pending_tg'] ?? '');
        if ($pendingTarget !== '') {
            $rawTarget = $pendingTarget;
            $digitsOnlyTarget = preg_replace('/[^0-9]/', '', $pendingTarget) ?? '';
        }
    }

    if ($rawTarget === '' && $digitsOnlyTarget === '') {
        $_SESSION['last_status'] = 'ERROR: INVALID TG / NODE / YSF';
        respond(session_payload($_SESSION['last_status']), 422);
    }

    if ($mode === 'ASL') {
        if (!$hasRealMyNode) {
            $_SESSION['last_status'] = 'ERROR: ALLSTAR NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        if ($digitsOnlyTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID ALLSTAR NODE';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        if ($disconnectBeforeConnect) {
            disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
        }

        asterisk_ilink_connect($myNode, $digitsOnlyTarget, $autoloadDvSwitchMode);
        track_allstar_link($digitsOnlyTarget, $autoloadDvSwitchMode);

        $_SESSION['last_mode'] = 'ASL';
        $_SESSION['last_target'] = $digitsOnlyTarget;
        $_SESSION['pending_target'] = $digitsOnlyTarget;

        $_SESSION['last_status'] = 'CONNECTED: ALLSTAR NODE ' . $digitsOnlyTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if ($mode === 'YSF') {
        if (!$hasRealMyNode || !$hasRealDvSwitchNode) {
            $_SESSION['last_status'] = 'ERROR: YSF NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        if ($disconnectBeforeConnect) {
            disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
        }

        load_dvswitch_link($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
        pause_seconds(0.5);

        dvswitch_mode('YSF');
        pause_seconds(0.5);

        dvswitch_tune($rawTarget);

        $_SESSION['last_mode'] = 'YSF';
        $_SESSION['last_target'] = $rawTarget;
        $_SESSION['pending_target'] = $rawTarget;
        clear_dmr_session_state();
        clear_dmr_active_state();
        $_SESSION['dvswitch_autoloaded'] = true;

        $_SESSION['last_status'] = 'CONNECTED: YSF TARGET ' . $rawTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if ($mode === 'BM' || $mode === 'TGIF') {
        if (!$hasRealMyNode || !$hasRealDvSwitchNode) {
            $_SESSION['last_status'] = 'ERROR: ' . $mode . ' NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        if ($digitsOnlyTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID TG';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        $server = $mode === 'TGIF'
            ? 'tgif.network:62031'
            : '3103.master.brandmeister.network:62031';

        $key = $mode === 'TGIF' ? $tgifPassword : $bmPassword;
        $hasRealKey = $mode === 'TGIF' ? $hasRealTgifPassword : $hasRealBmPassword;

        if (!$hasRealKey) {
            $_SESSION['last_status'] = 'ERROR: ' . $mode . ' NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $currentNetwork = (string) ($_SESSION['dmr_network'] ?? '');
        $dmrReady = !empty($_SESSION['dmr_ready']);
        $autoload = !empty($_SESSION['autoload_dvswitch']);

        if ($currentNetwork !== $mode || !$dmrReady) {
            if ($disconnectBeforeConnect) {
                disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
            }

            if ($autoload && $hasRealDvSwitchNode) {
                load_dvswitch_link($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
                pause_seconds(1.0);
                $_SESSION['dvswitch_autoloaded'] = true;
            } else {
                $_SESSION['dvswitch_autoloaded'] = false;
            }

            dvswitch_mode('DMR');
            pause_seconds(1.0);

            dvswitch_tune('disconnect');
            pause_seconds(1.0);

            dvswitch_tune($key . '@' . $server);
            pause_seconds(10.0);

            $_SESSION['dmr_network'] = $mode;
            $_SESSION['dmr_ready'] = true;
            $_SESSION['pending_tg'] = $digitsOnlyTarget;
            $_SESSION['pending_target'] = $digitsOnlyTarget;
            $_SESSION['last_mode'] = $mode;
            $_SESSION['last_target'] = '';

            clear_dmr_active_state();

            $_SESSION['last_status'] = 'WAITING: ' . $mode . ' READY - CLICK CONNECT AGAIN FOR TG ' . $digitsOnlyTarget;
            respond(session_payload($_SESSION['last_status']));
        }

        dvswitch_tune($digitsOnlyTarget);
        pause_seconds(1.0);

        $_SESSION['last_mode'] = $mode;
        $_SESSION['last_target'] = $digitsOnlyTarget;
        $_SESSION['pending_target'] = $digitsOnlyTarget;
        $_SESSION['pending_tg'] = $digitsOnlyTarget;
        $_SESSION['dmr_active_network'] = $mode;
        $_SESSION['dmr_active_target'] = $digitsOnlyTarget;
        $_SESSION['last_status'] = 'CONNECTED: TG ' . $digitsOnlyTarget . ' (' . $mode . ')';

        respond(session_payload($_SESSION['last_status']));
    }

    $_SESSION['last_status'] = 'ERROR: INVALID MODE';
    respond(session_payload($_SESSION['last_status']), 422);
}

if (!$hasRealMyNode) {
    $_SESSION['last_status'] = 'ERROR: MYNODE NOT CONFIGURED';
    respond(session_payload($_SESSION['last_status']), 500);
}

/*
 * Deterministic disconnect order:
 * 1. Last tracked AllStar direct link
 * 2. Active DVSwitch-managed state
 * 3. Final stale-state cleanup to IDLE
 */

$trackedAllstarNode = last_tracked_allstar_node();
if ($trackedAllstarNode !== '') {
    asterisk_ilink_disconnect($myNode, $trackedAllstarNode);
    pause_seconds(0.5);
    untrack_allstar_link($trackedAllstarNode);

    $remainingTracked = last_tracked_allstar_node();
    if ($remainingTracked !== '') {
        $_SESSION['last_mode'] = 'ASL';
        $_SESSION['last_target'] = $remainingTracked;
        $_SESSION['pending_target'] = $remainingTracked;
    } else {
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
    }

    $_SESSION['last_status'] = 'DISCONNECTED: ALLSTAR NODE ' . $trackedAllstarNode;
    respond(session_payload($_SESSION['last_status']));
}

$lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
$dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);

if ($lastMode === 'YSF') {
    if ($hasRealDvSwitchNode) {
        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);
    }
    dvswitch_tune('disconnect');
    clear_runtime_targets();
    $_SESSION['last_status'] = 'DISCONNECTED: YSF';
    respond(session_payload($_SESSION['last_status']));
}

if ($lastMode === 'BM' || $lastMode === 'TGIF') {
    dvswitch_tune('disconnect');
    pause_seconds(1.0);

    if ($dvswitchAutoloaded && $hasRealDvSwitchNode) {
        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);
    }

    clear_runtime_targets();
    $_SESSION['last_status'] = 'DISCONNECTED: ' . $lastMode;
    respond(session_payload($_SESSION['last_status']));
}

if ($dvswitchAutoloaded && $hasRealDvSwitchNode) {
    asterisk_ilink_disconnect($myNode, $dvSwitchNode);
    pause_seconds(0.5);
    unset($_SESSION['dvswitch_autoloaded']);
    clear_dmr_active_state();
    $_SESSION['last_status'] = 'DISCONNECTED: DVSWITCH LINK';
    respond(session_payload($_SESSION['last_status']));
}

clear_runtime_targets();
clear_allstar_tracking();
$_SESSION['last_status'] = 'DISCONNECTED';

respond(session_payload($_SESSION['last_status']));