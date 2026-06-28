<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\App\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/ApiAuthGuard.php';

use App\Support\ApiAuthGuard;
use App\Support\Config;

header('Content-Type: application/json; charset=UTF-8');

$config = new Config(dirname(__DIR__) . '/config.ini');
ApiAuthGuard::requireLoginIfEnabled($config);

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

function asterisk_rpt_cmd(string $node, string $command): string
{
    return shell_run('sudo /usr/sbin/asterisk -rx ' . escapeshellarg("rpt cmd {$node} {$command}"));
}

function asterisk_ilink_disconnect(string $node, string $remoteNode): string
{
    return asterisk_rpt_cmd($node, "ilink 1 {$remoteNode}");
}

function asterisk_reset_echolink_module(): void
{
    shell_run('sudo /usr/sbin/asterisk -rx ' . escapeshellarg('module unload chan_echolink.so'));
    usleep(1000000);
    shell_run('sudo /usr/sbin/asterisk -rx ' . escapeshellarg('module load chan_echolink.so'));
    usleep(2000000);
}

function asterisk_ilink_connect(string $node, string $remoteNode, string $linkMode): string
{
    $ilink = $linkMode === 'local_monitor' ? '8' : '3';
    return asterisk_rpt_cmd($node, "ilink {$ilink} {$remoteNode}");
}

function pause_seconds(float $seconds): void
{
    usleep((int) round($seconds * 1000000));
}

function normalize_mode(string $mode): string
{
    $mode = strtoupper(trim($mode));

    if (in_array($mode, ['ALLSTAR', 'ALLSTAR LINK', 'ALLSTARLINK'], true)) {
        return 'ASL';
    }

    if (in_array($mode, ['ECHO', 'ECHO LINK', 'ECHOLINK', 'EL', 'E/L'], true)) {
        return 'ECHO';
    }

    return $mode;
}

function normalize_direct_ui_mode(string $mode): string
{
    return normalize_mode($mode) === 'ECHO' ? 'ECHO' : 'ASL';
}

function normalize_autoload_dvswitch_mode(mixed $mode): string
{
    $value = strtolower(trim((string) $mode));
    return $value === 'local_monitor' ? 'local_monitor' : 'transceive';
}

function has_real_config_value(mixed $value): bool
{
    $normalized = strtoupper(trim((string) $value));
    if ($normalized === '') {
        return false;
    }

    return !in_array($normalized, [
        'CHANGE_ME',
        'YOUR NODE',
        'YOUR DVSWITCH NODE',
        'YOUR_REAL_PASSWORD',
        'YOUR_REAL_KEY',
        'YOUR PASSWORD',
        'YOUR KEY',
    ], true);
}

function direct_node_status_label(string $mode): string
{
    return normalize_direct_ui_mode($mode) === 'ECHO' ? 'ECHOLINK NODE' : 'ALLSTAR NODE';
}

function echolink_node_from_mapped_target(string $target): string
{
    $target = trim($target);
    if (preg_match('/^3(\d{6})$/', $target, $matches) !== 1) {
        return '';
    }

    $node = ltrim($matches[1], '0');
    return $node === '' ? '0' : $node;
}

function echolink_node_exists(string $echolinkNode): bool
{
    $echolinkNode = trim($echolinkNode);
    if ($echolinkNode === '' || preg_match('/^\d+$/', $echolinkNode) !== 1) {
        return false;
    }

    $output = shell_run('/usr/bin/timeout 5 sudo /usr/sbin/asterisk -rx ' . escapeshellarg("echolink dbget nodename {$echolinkNode}"));
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($echolinkNode, '/') . '\|/', trim((string) $line)) === 1) {
            return true;
        }
    }

    return false;
}

function validate_echolink_target_or_fail(string $target, callable $fail): void
{
    $echolinkNode = echolink_node_from_mapped_target($target);
    if ($echolinkNode === '') {
        $fail('ERROR: INVALID ECHOLINK NODE - USE 3 PLUS ZERO-PADDED 6-DIGIT NODE', 422);
    }

    if (!echolink_node_exists($echolinkNode)) {
        $fail('ERROR: ECHOLINK NODE NOT FOUND: ' . $echolinkNode, 404);
    }
}

function ensure_allstar_tracking_structures(): void
{
    if (!isset($_SESSION['allstar_link_modes']) || !is_array($_SESSION['allstar_link_modes'])) {
        $_SESSION['allstar_link_modes'] = [];
    }
    if (!isset($_SESSION['allstar_link_order']) || !is_array($_SESSION['allstar_link_order'])) {
        $_SESSION['allstar_link_order'] = [];
    }
    if (!isset($_SESSION['allstar_link_ui_modes']) || !is_array($_SESSION['allstar_link_ui_modes'])) {
        $_SESSION['allstar_link_ui_modes'] = [];
    }
}

function track_allstar_link(string $node, string $mode, string $uiMode = 'ASL'): void
{
    ensure_allstar_tracking_structures();
    $_SESSION['allstar_link_modes'][$node] = normalize_autoload_dvswitch_mode($mode);
    $_SESSION['allstar_link_ui_modes'][$node] = normalize_direct_ui_mode($uiMode);

    $order = array_values(array_filter(
        $_SESSION['allstar_link_order'],
        static fn ($value) => trim((string) $value) !== '' && trim((string) $value) !== $node
    ));
    $order[] = $node;
    $_SESSION['allstar_link_order'] = $order;
}

function tracked_allstar_ui_mode(string $node): string
{
    ensure_allstar_tracking_structures();
    $stored = $_SESSION['allstar_link_ui_modes'][$node] ?? '';
    return is_string($stored) && $stored !== '' ? normalize_direct_ui_mode($stored) : 'ASL';
}

function untrack_allstar_link(string $node): void
{
    ensure_allstar_tracking_structures();
    unset($_SESSION['allstar_link_modes'][$node], $_SESSION['allstar_link_ui_modes'][$node]);
    $_SESSION['allstar_link_order'] = array_values(array_filter(
        $_SESSION['allstar_link_order'],
        static fn ($value) => trim((string) $value) !== '' && trim((string) $value) !== $node
    ));
}

function sanitize_allstar_tracking(?string $excludedNode = null): void
{
    ensure_allstar_tracking_structures();

    $excludedNode = trim((string) $excludedNode);
    $seen = [];
    $cleanOrder = [];

    foreach ($_SESSION['allstar_link_order'] as $value) {
        $node = trim((string) $value);
        if ($node === '' || ($excludedNode !== '' && $node === $excludedNode) || isset($seen[$node])) {
            continue;
        }
        $seen[$node] = true;
        $cleanOrder[] = $node;
    }

    $_SESSION['allstar_link_order'] = $cleanOrder;

    foreach (array_keys($_SESSION['allstar_link_modes']) as $node) {
        $node = trim((string) $node);
        if ($node === '' || ($excludedNode !== '' && $node === $excludedNode)) {
            unset($_SESSION['allstar_link_modes'][$node]);
        }
    }
    foreach (array_keys($_SESSION['allstar_link_ui_modes']) as $node) {
        $node = trim((string) $node);
        if ($node === '' || ($excludedNode !== '' && $node === $excludedNode)) {
            unset($_SESSION['allstar_link_ui_modes'][$node]);
        }
    }
}

function allstar_tracked_nodes_in_order(?string $excludedNode = null): array
{
    sanitize_allstar_tracking($excludedNode);
    return array_values($_SESSION['allstar_link_order']);
}

function last_tracked_allstar_node(?string $excludedNode = null): string
{
    $order = allstar_tracked_nodes_in_order($excludedNode);
    if ($order === []) {
        return '';
    }
    $last = end($order);
    return is_string($last) ? trim($last) : '';
}

function last_tracked_allstar_ui_mode(?string $excludedNode = null): string
{
    $node = last_tracked_allstar_node($excludedNode);
    return $node !== '' ? tracked_allstar_ui_mode($node) : 'ASL';
}

function sync_last_direct_target_from_tracking(?string $excludedNode = null): void
{
    $remaining = last_tracked_allstar_node($excludedNode);
    if ($remaining !== '') {
        $_SESSION['last_mode'] = last_tracked_allstar_ui_mode($excludedNode);
        $_SESSION['last_target'] = $remaining;
        $_SESSION['pending_target'] = $remaining;
        return;
    }

    unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
}

function session_forces_private_node(): bool
{
    $selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? ''));
    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
    $dmrActiveNetwork = normalize_mode((string) ($_SESSION['dmr_active_network'] ?? ''));

    return in_array($selectedMode, ['BM', 'TGIF', 'YSF'], true)
        || in_array($lastMode, ['BM', 'TGIF', 'YSF'], true)
        || in_array($dmrNetwork, ['BM', 'TGIF'], true)
        || in_array($dmrActiveNetwork, ['BM', 'TGIF'], true)
        || !empty($_SESSION['dmr_ready'])
        || !empty($_SESSION['dvswitch_autoloaded']);
}

function direct_allstar_snapshot(string $dvSwitchNode = ''): array
{
    ensure_allstar_tracking_structures();
    $links = [];
    $seen = [];
    $storedModes = is_array($_SESSION['allstar_link_modes'] ?? null) ? $_SESSION['allstar_link_modes'] : [];
    $storedUiModes = is_array($_SESSION['allstar_link_ui_modes'] ?? null) ? $_SESSION['allstar_link_ui_modes'] : [];

    foreach (allstar_tracked_nodes_in_order($dvSwitchNode) as $node) {
        $node = trim((string) $node);
        if ($node === '' || isset($seen[$node])) {
            continue;
        }

        $mode = normalize_autoload_dvswitch_mode((string) ($storedModes[$node] ?? ($_SESSION['autoload_dvswitch_mode'] ?? 'transceive')));
        $uiMode = normalize_direct_ui_mode((string) ($storedUiModes[$node] ?? 'ASL'));
        $links[] = [
            'node' => $node,
            'label' => 'Connected Node',
            'link_mode' => $mode,
            'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
            'ui_mode' => $uiMode,
            'is_live' => false,
        ];
        $seen[$node] = true;
    }

    if ($dvSwitchNode !== '' && session_forces_private_node() && !isset($seen[$dvSwitchNode])) {
        $mode = normalize_autoload_dvswitch_mode((string) ($_SESSION['dvswitch_active_mode'] ?? $_SESSION['autoload_dvswitch_mode'] ?? 'transceive'));
        $links[] = [
            'node' => $dvSwitchNode,
            'label' => 'Connected Node',
            'link_mode' => $mode,
            'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
            'ui_mode' => 'ASL',
            'is_live' => false,
        ];
    }

    $label = count($links) > 0 ? 'Connected: ' . count($links) : 'No links';
    return [
        'state' => $label,
        'label' => $label,
        'status' => $label,
        'connected_nodes_count' => count($links),
        'connected_nodes' => $links,
        'local_nodes' => array_values(array_filter([$dvSwitchNode])),
    ];
}

function direct_payload(string $statusText, string $dvSwitchNode, array $extra = []): array
{
    $forcedAutoload = session_forces_private_node();
    $payload = [
        'ok' => !str_starts_with($statusText, 'ERROR:'),
        'status' => $statusText,
        'status_text' => $statusText,
        'last_status' => $statusText,
        'selected_mode' => (string) ($_SESSION['selected_mode'] ?? 'ASL'),
        'last_mode' => (string) ($_SESSION['last_mode'] ?? ''),
        'last_target' => (string) ($_SESSION['last_target'] ?? ''),
        'pending_target' => (string) ($_SESSION['pending_target'] ?? ''),
        'autoload_dvswitch' => $forcedAutoload || !empty($_SESSION['autoload_dvswitch']),
        'autoload_dvswitch_mode' => (string) ($_SESSION['autoload_dvswitch_mode'] ?? 'transceive'),
        'disconnect_before_connect' => !empty($_SESSION['disconnect_before_connect']),
        'dmr_network' => (string) ($_SESSION['dmr_network'] ?? ''),
        'dmr_ready' => !empty($_SESSION['dmr_ready']),
        'dmr_active_network' => (string) ($_SESSION['dmr_active_network'] ?? ''),
        'dmr_active_target' => (string) ($_SESSION['dmr_active_target'] ?? ''),
        'dvswitch_active_mode' => (string) ($_SESSION['dvswitch_active_mode'] ?? ''),
        'dvswitch_link_active' => !empty($_SESSION['dvswitch_autoloaded']) || !empty($_SESSION['dmr_ready']) || normalize_mode((string) ($_SESSION['last_mode'] ?? '')) === 'YSF',
        'allstar' => direct_allstar_snapshot($dvSwitchNode),
        'system' => [
            'status_text' => $statusText,
            'selected_mode' => (string) ($_SESSION['selected_mode'] ?? 'ASL'),
            'last_mode' => (string) ($_SESSION['last_mode'] ?? ''),
            'last_target' => (string) ($_SESSION['last_target'] ?? ''),
            'pending_target' => (string) ($_SESSION['pending_target'] ?? ''),
            'autoload_dvswitch' => $forcedAutoload || !empty($_SESSION['autoload_dvswitch']),
            'autoload_dvswitch_mode' => (string) ($_SESSION['autoload_dvswitch_mode'] ?? 'transceive'),
            'disconnect_before_connect' => !empty($_SESSION['disconnect_before_connect']),
            'dmr_network' => (string) ($_SESSION['dmr_network'] ?? ''),
            'dmr_ready' => !empty($_SESSION['dmr_ready']),
            'dmr_active_network' => (string) ($_SESSION['dmr_active_network'] ?? ''),
            'dmr_active_target' => (string) ($_SESSION['dmr_active_target'] ?? ''),
            'dvswitch_active_mode' => (string) ($_SESSION['dvswitch_active_mode'] ?? ''),
            'dvswitch_link_active' => !empty($_SESSION['dvswitch_autoloaded']) || !empty($_SESSION['dmr_ready']) || normalize_mode((string) ($_SESSION['last_mode'] ?? '')) === 'YSF',
        ],
    ];

    return array_merge($payload, $extra);
}

$request = request_data();
$action = strtolower(trim((string) ($request['action'] ?? $request['action_type'] ?? '')));
$rawTarget = trim((string) ($request['target'] ?? $request['tgNum'] ?? ''));
$selectedNode = preg_replace('/[^0-9]/', '', (string) ($request['selected_node'] ?? '')) ?? '';
$mode = normalize_mode((string) ($request['mode'] ?? ($_SESSION['selected_mode'] ?? 'ASL')));
$uiMode = normalize_direct_ui_mode((string) ($request['ui_mode'] ?? $mode));

ensure_allstar_tracking_structures();

if (!isset($_SESSION['autoload_dvswitch'])) {
    $_SESSION['autoload_dvswitch'] = true;
}
if (!isset($_SESSION['autoload_dvswitch_mode'])) {
    $_SESSION['autoload_dvswitch_mode'] = 'transceive';
}
if (!isset($_SESSION['disconnect_before_connect'])) {
    $_SESSION['disconnect_before_connect'] = false;
}

$myNode = $config->getString('MYNODE', '');
$dvSwitchNode = $config->getString('DVSWITCH_NODE', '');
$autoloadDvSwitchMode = normalize_autoload_dvswitch_mode($_SESSION['autoload_dvswitch_mode'] ?? 'transceive');
$disconnectBeforeConnect = !empty($_SESSION['disconnect_before_connect']);
$hasRealMyNode = has_real_config_value($myNode);
$hasRealDvSwitchNode = has_real_config_value($dvSwitchNode);

if (!$hasRealMyNode) {
    $_SESSION['last_status'] = 'ERROR: MYNODE NOT CONFIGURED';
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 500);
}

if (!in_array($action, ['connect', 'disconnect', 'disconnect_selected'], true)) {
    $_SESSION['last_status'] = 'ERROR: INVALID ACTION';
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 400);
}

if ($action === 'connect') {
    $digitsOnlyTarget = preg_replace('/[^0-9]/', '', $rawTarget) ?? '';
    if (!in_array($mode, ['ASL', 'ECHO'], true)) {
        $_SESSION['last_status'] = 'ERROR: INVALID DIRECT MODE';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }
    if ($digitsOnlyTarget === '') {
        $_SESSION['last_status'] = 'ERROR: INVALID ALLSTAR NODE';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }

    if ($uiMode === 'ECHO') {
        validate_echolink_target_or_fail($digitsOnlyTarget, static function (string $message, int $statusCode) use ($hasRealDvSwitchNode, $dvSwitchNode): never {
            $_SESSION['last_status'] = $message;
            respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), $statusCode);
        });
    }

    if ($disconnectBeforeConnect) {
        foreach (array_reverse(allstar_tracked_nodes_in_order($hasRealDvSwitchNode ? $dvSwitchNode : null)) as $node) {
            $node = trim((string) $node);
            if ($node === '') {
                continue;
            }
            asterisk_ilink_disconnect($myNode, $node);
            pause_seconds(0.5);
            untrack_allstar_link($node);
        }
    }

    if ($uiMode === 'ECHO') {
        asterisk_reset_echolink_module();
    }

    asterisk_ilink_connect($myNode, $digitsOnlyTarget, $autoloadDvSwitchMode);
    track_allstar_link($digitsOnlyTarget, $autoloadDvSwitchMode, $uiMode);

    $_SESSION['selected_mode'] = $uiMode;
    $_SESSION['last_mode'] = $uiMode;
    $_SESSION['last_target'] = $digitsOnlyTarget;
    $_SESSION['pending_target'] = $digitsOnlyTarget;
    $_SESSION['last_status'] = 'CONNECTED: ' . direct_node_status_label($uiMode) . ' ' . $digitsOnlyTarget;

    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''));
}

if ($action === 'disconnect_selected') {
    if ($selectedNode === '') {
        $_SESSION['last_status'] = 'ERROR: INVALID ALLSTAR NODE';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }
    if ($hasRealDvSwitchNode && $selectedNode === $dvSwitchNode) {
        $_SESSION['last_status'] = 'ERROR: USE DISCONNECT DVSWITCH';
        respond(direct_payload($_SESSION['last_status'], $dvSwitchNode), 409);
    }
    $trackedNodes = allstar_tracked_nodes_in_order($hasRealDvSwitchNode ? $dvSwitchNode : null);
    if (!in_array($selectedNode, $trackedNodes, true)) {
        $_SESSION['last_status'] = 'ERROR: ALLSTAR NODE NOT TRACKED';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 404);
    }

    $selectedUiMode = tracked_allstar_ui_mode($selectedNode);
    asterisk_ilink_disconnect($myNode, $selectedNode);
    pause_seconds(1.0);
    if ($selectedUiMode === 'ECHO') {
        asterisk_reset_echolink_module();
    }
    untrack_allstar_link($selectedNode);
    sync_last_direct_target_from_tracking($hasRealDvSwitchNode ? $dvSwitchNode : null);
    $_SESSION['last_status'] = 'DISCONNECTED: ' . direct_node_status_label($selectedUiMode) . ' ' . $selectedNode;
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''));
}

$trackedNode = last_tracked_allstar_node($hasRealDvSwitchNode ? $dvSwitchNode : null);
if ($trackedNode === '') {
    $_SESSION['last_status'] = 'ERROR: NO DIRECT ALLSTAR NODE TRACKED';
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
}

$trackedUiMode = last_tracked_allstar_ui_mode($hasRealDvSwitchNode ? $dvSwitchNode : null);
$trackedUiMode = tracked_allstar_ui_mode($trackedNode);
asterisk_ilink_disconnect($myNode, $trackedNode);
pause_seconds(1.0);
if ($trackedUiMode === 'ECHO') {
    asterisk_reset_echolink_module();
}
untrack_allstar_link($trackedNode);
sync_last_direct_target_from_tracking($hasRealDvSwitchNode ? $dvSwitchNode : null);
$_SESSION['last_status'] = 'DISCONNECTED: ' . direct_node_status_label($trackedUiMode) . ' ' . $trackedNode;
respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''));
