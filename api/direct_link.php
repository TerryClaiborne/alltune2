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

$GLOBALS['mode_switch_lock_handle'] = null;

function release_mode_switch_lock(): void
{
    $handle = $GLOBALS['mode_switch_lock_handle'] ?? null;
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
    $GLOBALS['mode_switch_lock_handle'] = null;
}

function respond(array $payload, int $statusCode = 200): never
{
    release_mode_switch_lock();
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

function acquire_mode_switch_lock(): mixed
{
    $handle = @fopen(sys_get_temp_dir() . '/alltune2-mode-switch.lock', 'c');
    if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        return null;
    }

    $GLOBALS['mode_switch_lock_handle'] = $handle;
    register_shutdown_function(static function (): void {
        release_mode_switch_lock();
    });
    return $handle;
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
    return shell_run('/usr/bin/timeout 8 sudo /usr/sbin/asterisk -rx ' . escapeshellarg("rpt cmd {$node} ilink 1 {$remoteNode}"));
}

function asterisk_ilink_disconnect_live_client(string $node, string $client): string
{
    return asterisk_rpt_cmd($node, "ilink 11 {$client}");
}

function asterisk_cli(string $command): string
{
    return shell_run('/usr/bin/timeout 5 sudo /usr/sbin/asterisk -rx ' . escapeshellarg($command));
}

function valid_live_allstar_client_name(string $client): bool
{
    return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $client) === 1;
}

function live_allstar_link_names(string $node): array
{
    $names = [];

    $lstats = asterisk_cli("rpt lstats {$node}");
    foreach (preg_split('/\R/', $lstats) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s+/', $line);
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate !== '' && valid_live_allstar_client_name($candidate)) {
            $names[$candidate] = true;
        }
    }

    $nodes = asterisk_cli("rpt nodes {$node}");
    if (preg_match_all('/\b[TRLC]([A-Za-z0-9_.:-]{1,64})\b/', $nodes, $matches) > 0) {
        foreach ($matches[1] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && valid_live_allstar_client_name($candidate)) {
                $names[$candidate] = true;
            }
        }
    }

    return array_keys($names);
}

function live_allstar_link_directions(string $node): array
{
    $directions = [];
    $lstats = asterisk_cli("rpt lstats {$node}");

    foreach (preg_split('/\R/', $lstats) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim((string) $line)) ?: [];
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate === '' || !valid_live_allstar_client_name($candidate)) {
            continue;
        }

        foreach (array_slice($parts, 1) as $part) {
            $direction = strtoupper(trim((string) $part));
            if (in_array($direction, ['IN', 'OUT'], true)) {
                $directions[$candidate] = $direction;
                break;
            }
        }
    }

    return $directions;
}

function live_allstar_link_exists(string $node, string $remote): bool
{
    return in_array($remote, live_allstar_link_names($node), true);
}

function valid_asterisk_iax_channel_name(string $channel): bool
{
    return preg_match('/^IAX2\/[A-Za-z0-9_.:@-]{1,96}$/', $channel) === 1;
}

function live_iax_rpt_channels(string $node): array
{
    $channels = [];
    $output = asterisk_cli('core show channels concise');

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $parts = explode('!', $line);
        $channel = trim((string) ($parts[0] ?? ''));
        $context = strtolower(trim((string) ($parts[1] ?? '')));
        $extension = trim((string) ($parts[2] ?? ''));
        $application = trim((string) ($parts[5] ?? ''));
        $data = trim((string) ($parts[6] ?? ''));

        if (!valid_asterisk_iax_channel_name($channel)) {
            continue;
        }

        if ($application !== 'Rpt') {
            continue;
        }

        $runsThisNode = $data === $node || str_starts_with($data, $node . '|') || $extension === $node;
        if (!$runsThisNode) {
            continue;
        }

        /*
         * Pure iax.conf clients normally arrive through iaxrpt/iax-client
         * contexts. Web Transceiver / phone-portal style clients arrive through
         * allstar-public and are handled by the app_rpt ilink 11 path instead.
         */
        if (!in_array($context, ['iaxrpt', 'iax-client', 'iaxclient'], true)) {
            continue;
        }

        $channels[$channel] = true;
    }

    return array_keys($channels);
}

function live_iax_rpt_channel_exists(string $node, string $channel): bool
{
    return in_array($channel, live_iax_rpt_channels($node), true);
}

function asterisk_channel_request_hangup(string $channel): string
{
    return asterisk_cli('channel request hangup ' . $channel);
}

function wait_for_iax_rpt_channel_gone(string $node, string $channel, float $timeoutSeconds = 2.5): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        if (!live_iax_rpt_channel_exists($node, $channel)) {
            return true;
        }

        pause_seconds(0.15);
    } while (microtime(true) < $deadline);

    return !live_iax_rpt_channel_exists($node, $channel);
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

function echolink_module_info(): string
{
    return shell_run('/usr/bin/timeout 5 sudo /usr/sbin/asterisk -rx ' . escapeshellarg('module show like echolink'));
}

function echolink_module_use_count(): int
{
    $output = echolink_module_info();

    if (preg_match('/^chan_echolink\.so\s+.*?\s+(\d+)\s+Running\s+/m', $output, $match)) {
        return (int) $match[1];
    }

    return 0;
}

function echolink_module_is_loaded(): bool
{
    return stripos(echolink_module_info(), 'chan_echolink.so') !== false;
}

function echolink_ensure_module_loaded(): void
{
    if (!echolink_module_is_loaded()) {
        shell_run('/usr/bin/timeout 8 sudo /usr/sbin/asterisk -rx ' . escapeshellarg('module load chan_echolink.so'));
        pause_seconds(2.0);
    }
}

function asterisk_reset_echolink_module(): void
{
    shell_run('/usr/bin/timeout 8 sudo /usr/sbin/asterisk -rx ' . escapeshellarg('module unload chan_echolink.so'));
    pause_seconds(1.0);
    shell_run('/usr/bin/timeout 8 sudo /usr/sbin/asterisk -rx ' . escapeshellarg('module load chan_echolink.so'));
    pause_seconds(2.0);
}

function echolink_wait_for_idle(int $timeoutSeconds = 8): bool
{
    $deadline = microtime(true) + max(1, $timeoutSeconds);

    do {
        if (echolink_module_use_count() === 0) {
            return true;
        }

        pause_seconds(0.5);
    } while (microtime(true) < $deadline);

    return echolink_module_use_count() === 0;
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

function normalize_echolink_target(string $target): string
{
    $digits = preg_replace('/[^0-9]/', '', $target) ?? '';

    if ($digits === '') {
        return '';
    }

    return $digits;
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

function direct_node_is_echolink(string $node, string $uiMode = ''): bool
{
    if (normalize_direct_ui_mode($uiMode) === 'ECHO') {
        return true;
    }

    $node = preg_replace('/[^0-9]/', '', trim($node)) ?? '';
    return preg_match('/^3\d{6}$/', $node) === 1;
}

function live_echolink_link_directions(string $myNode): array
{
    $links = [];
    foreach (live_allstar_link_directions($myNode) as $node => $direction) {
        if (preg_match('/^3\d{6}$/', (string) $node) === 1) {
            $links[(string) $node] = $direction;
        }
    }
    return $links;
}

function live_echolink_nodes(string $myNode): array
{
    return array_values(array_filter(
        array_map('strval', live_allstar_link_names($myNode)),
        static fn (string $node): bool => preg_match('/^3\d{6}$/', $node) === 1
    ));
}

function reset_echolink_module_if_confirmed_idle(string $myNode): void
{
    /* If either live check is uncertain, leave cleanup to the next connect. */
    if (live_echolink_nodes($myNode) !== [] || echolink_module_use_count() !== 0) {
        return;
    }

    asterisk_reset_echolink_module();
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
$selectedLiveClient = trim((string) ($request['selected_client'] ?? $request['selected_iax_client'] ?? $request['selected_node'] ?? ''));
$selectedIaxChannel = trim((string) ($request['selected_channel'] ?? $request['selected_iax_channel'] ?? ''));
$selectedIaxRowNode = trim((string) ($request['selected_row_node'] ?? ''));
$requestedLinkMode = strtolower(trim((string) ($request['link_mode'] ?? '')));
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

if (!in_array($action, ['connect', 'disconnect', 'disconnect_selected', 'disconnect_live_client', 'disconnect_iax_channel', 'switch_mode'], true)) {
    $_SESSION['last_status'] = 'ERROR: INVALID ACTION';
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 400);
}

if ($action === 'switch_mode') {
    if ($selectedNode === '' || !in_array($requestedLinkMode, ['transceive', 'local_monitor'], true)) {
        respond(direct_payload('ERROR: INVALID MODE SWITCH', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }
    if ($hasRealDvSwitchNode && $selectedNode === $dvSwitchNode) {
        respond(direct_payload('ERROR: USE DVSWITCH MODE SWITCH', $dvSwitchNode), 409);
    }

    $modeSwitchLock = acquire_mode_switch_lock();
    if (!is_resource($modeSwitchLock)) {
        respond(direct_payload('ERROR: ANOTHER MODE SWITCH IS IN PROGRESS', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
    }

    $liveDirectNodes = array_map('strval', live_allstar_link_names($myNode));
    if (!in_array($selectedNode, $liveDirectNodes, true)) {
        respond(direct_payload('ERROR: DIRECT NODE IS NOT CONNECTED', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
    }

    $selectedUiMode = ($uiMode === 'ECHO' || preg_match('/^3\d{6}$/', $selectedNode) === 1)
        ? 'ECHO'
        : 'ASL';
    $selectedDirection = '';

    if ($selectedUiMode === 'ECHO') {
        $echoDirections = live_echolink_link_directions($myNode);
        $selectedDirection = strtoupper(trim((string) ($echoDirections[$selectedNode] ?? '')));
        if (!in_array($selectedDirection, ['IN', 'OUT'], true)) {
            respond(direct_payload('ERROR: ECHOLINK DIRECTION NOT VERIFIED - REFRESH AND TRY AGAIN', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
        }

        if ($selectedDirection === 'OUT') {
            $otherEchoLinks = array_values(array_filter(
                array_keys($echoDirections),
                static fn (mixed $node): bool => (string) $node !== $selectedNode
            ));
            if ($otherEchoLinks !== []) {
                respond(direct_payload('ERROR: ECHOLINK SWITCH BLOCKED - ANOTHER LINK IS ACTIVE', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
            }

            asterisk_reset_echolink_module();
            echolink_ensure_module_loaded();
            asterisk_ilink_connect($myNode, $selectedNode, $requestedLinkMode);
        } else {
            /* Inbound callers are unlimited; change only this local link. */
            pause_seconds(1.0);
            asterisk_ilink_connect($myNode, $selectedNode, $requestedLinkMode);
            pause_seconds(2.0);
        }
    } else {
        asterisk_ilink_connect($myNode, $selectedNode, $requestedLinkMode);
    }

    track_allstar_link($selectedNode, $requestedLinkMode, $selectedUiMode);
    $modeLabel = $requestedLinkMode === 'local_monitor' ? 'Local Monitor' : 'Transceive';
    $_SESSION['last_status'] = 'SWITCHED: ' . direct_node_status_label($selectedUiMode) . ' ' . $selectedNode . ' TO ' . strtoupper($modeLabel);
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : '', [
        'selected_node' => $selectedNode,
        'link_mode' => $requestedLinkMode,
        'mode_label' => $modeLabel,
        'ui_mode' => $selectedUiMode,
        'link_direction' => $selectedDirection,
    ]));
}

if ($action === 'connect') {
    $digitsOnlyTarget = preg_replace('/[^0-9]/', '', $rawTarget) ?? '';
    if (!in_array($mode, ['ASL', 'ECHO'], true)) {
        $_SESSION['last_status'] = 'ERROR: INVALID DIRECT MODE';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }

    if ($uiMode === 'ECHO') {
        $digitsOnlyTarget = normalize_echolink_target($digitsOnlyTarget);
    }

    if ($digitsOnlyTarget === '') {
        $_SESSION['last_status'] = $uiMode === 'ECHO' ? 'ERROR: INVALID ECHOLINK NODE' : 'ERROR: INVALID ALLSTAR NODE';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }

    if ($uiMode === 'ECHO' && !preg_match('/^3\d{6}$/', $digitsOnlyTarget)) {
        $_SESSION['last_status'] = 'ERROR: INVALID ECHOLINK NODE';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }

    $echoDirections = [];
    if ($uiMode === 'ECHO') {
        $modeSwitchLock = acquire_mode_switch_lock();
        if (!is_resource($modeSwitchLock)) {
            respond(direct_payload('ERROR: ANOTHER MODE SWITCH IS IN PROGRESS', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
        }

        $echoDirections = live_echolink_link_directions($myNode);
        $liveEchoNodes = live_echolink_nodes($myNode);
        if ($liveEchoNodes !== [] && count($echoDirections) < count($liveEchoNodes)) {
            $_SESSION['last_status'] = 'ERROR: ECHOLINK DIRECTION NOT VERIFIED - OUTBOUND CONNECT BLOCKED';
            respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
        }
        if (in_array('IN', $echoDirections, true)) {
            $_SESSION['last_status'] = 'ERROR: INBOUND ECHOLINK ACTIVE - OUTBOUND CONNECT WOULD INTERRUPT IT';
            respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
        }

        foreach ($echoDirections as $node => $direction) {
            if ($direction !== 'OUT' || (string) $node === $digitsOnlyTarget) {
                continue;
            }
            if (!$disconnectBeforeConnect) {
                $_SESSION['last_status'] = 'ERROR: ECHOLINK OUTBOUND LINK ALREADY ACTIVE';
                respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
            }
            /* The common Disconnect Before Connect loop performs the safe drop. */
        }
    }

    if ($disconnectBeforeConnect) {
        foreach (array_reverse(allstar_tracked_nodes_in_order($hasRealDvSwitchNode ? $dvSwitchNode : null)) as $node) {
            $node = trim((string) $node);
            if ($node === '') {
                continue;
            }

            $isEchoLink = direct_node_is_echolink($node, tracked_allstar_ui_mode($node));
            $echoDirection = '';
            if ($isEchoLink) {
                if (!is_resource($GLOBALS['mode_switch_lock_handle'] ?? null) && !is_resource(acquire_mode_switch_lock())) {
                    respond(direct_payload('ERROR: ANOTHER ECHOLINK OPERATION IS IN PROGRESS', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
                }
                $echoDirection = strtoupper(trim((string) (live_echolink_link_directions($myNode)[$node] ?? '')));
            }

            asterisk_ilink_disconnect($myNode, $node);
            pause_seconds($isEchoLink ? 1.0 : 0.5);
            if ($isEchoLink && $echoDirection === 'OUT' && $uiMode !== 'ECHO') {
                reset_echolink_module_if_confirmed_idle($myNode);
            }
            untrack_allstar_link($node);
        }
    }

    if ($uiMode === 'ECHO') {
        asterisk_reset_echolink_module();
        echolink_ensure_module_loaded();
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


if ($action === 'disconnect_iax_channel') {
    if ($selectedIaxChannel === '' || !valid_asterisk_iax_channel_name($selectedIaxChannel)) {
        $_SESSION['last_status'] = 'ERROR: INVALID IAX CHANNEL';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }

    $liveIaxChannels = live_iax_rpt_channels($myNode);
    $disconnectChannel = '';
    $alreadyGone = false;

    if (in_array($selectedIaxChannel, $liveIaxChannels, true)) {
        $disconnectChannel = $selectedIaxChannel;
    } elseif (count($liveIaxChannels) === 0) {
        /*
         * A fast status refresh can briefly show a row that Asterisk has
         * already torn down. Treat that as success from the row button's
         * point of view instead of making Terry click again.
         */
        $alreadyGone = true;
    } elseif (count($liveIaxChannels) === 1) {
        /*
         * The exact channel name can roll while the same single true-IAX
         * client is still the only candidate. Disconnect that one safe match.
         */
        $disconnectChannel = (string) $liveIaxChannels[0];
    } else {
        $_SESSION['last_status'] = 'ERROR: MULTIPLE IAX CHANNELS - REFRESH AND USE ROW DISCONNECT';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
    }

    if ($disconnectChannel !== '') {
        asterisk_channel_request_hangup($disconnectChannel);
        wait_for_iax_rpt_channel_gone($myNode, $disconnectChannel);
    }

    if ($selectedIaxRowNode !== '') {
        untrack_allstar_link($selectedIaxRowNode);
    }
    untrack_allstar_link($selectedIaxChannel);
    if ($disconnectChannel !== '' && $disconnectChannel !== $selectedIaxChannel) {
        untrack_allstar_link($disconnectChannel);
    }
    sync_last_direct_target_from_tracking($hasRealDvSwitchNode ? $dvSwitchNode : null);

    $_SESSION['last_status'] = 'DISCONNECTED: IAX CHANNEL';
    $payload = direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : '');
    $payload['iax_requested_channel'] = $selectedIaxChannel;
    $payload['iax_disconnected_channel'] = $disconnectChannel;
    $payload['iax_disconnect_already_gone'] = $alreadyGone;
    respond($payload);
}

if ($action === 'disconnect_live_client') {
    if ($selectedLiveClient === '' || !valid_live_allstar_client_name($selectedLiveClient)) {
        $_SESSION['last_status'] = 'ERROR: INVALID LIVE IAX CLIENT';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 422);
    }

    if (ctype_digit($selectedLiveClient)) {
        $_SESSION['last_status'] = 'ERROR: USE ALLSTAR ROW DISCONNECT';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
    }

    if ($hasRealDvSwitchNode && $selectedLiveClient === $dvSwitchNode) {
        $_SESSION['last_status'] = 'ERROR: USE DISCONNECT DVSWITCH';
        respond(direct_payload($_SESSION['last_status'], $dvSwitchNode), 409);
    }

    /*
     * The row already came from status/live Asterisk data. Do not reject a
     * row click solely because a second live preflight briefly misses the
     * same Web Transceiver / app_rpt client during polling.
     */
    asterisk_ilink_disconnect_live_client($myNode, $selectedLiveClient);
    pause_seconds(1.0);

    untrack_allstar_link($selectedLiveClient);
    sync_last_direct_target_from_tracking($hasRealDvSwitchNode ? $dvSwitchNode : null);

    $_SESSION['last_status'] = 'DISCONNECTED: IAX CLIENT ' . $selectedLiveClient;
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
    $selectedUiMode = tracked_allstar_ui_mode($selectedNode);
    if ($selectedUiMode === 'ASL' && preg_match('/^3\d{6}$/', $selectedNode) === 1) {
        $selectedUiMode = 'ECHO';
    }
    $remainingEchoNodes = [];
    $selectedEchoDirection = '';
    if ($selectedUiMode === 'ECHO') {
        $echoDisconnectLock = acquire_mode_switch_lock();
        if (!is_resource($echoDisconnectLock)) {
            respond(direct_payload('ERROR: ANOTHER ECHOLINK OPERATION IS IN PROGRESS', $dvSwitchNode), 409);
        }
        $echoDirections = live_echolink_link_directions($myNode);
        $selectedEchoDirection = strtoupper(trim((string) ($echoDirections[$selectedNode] ?? '')));
        $remainingEchoNodes = array_values(array_filter(
            array_keys($echoDirections),
            static fn (mixed $node): bool => (string) $node !== $selectedNode
        ));
    }
    asterisk_ilink_disconnect($myNode, $selectedNode);
    pause_seconds(1.0);
    if ($selectedUiMode === 'ECHO' && $selectedEchoDirection === 'OUT' && $remainingEchoNodes === []) {
        reset_echolink_module_if_confirmed_idle($myNode);
    }
    untrack_allstar_link($selectedNode);
    sync_last_direct_target_from_tracking($hasRealDvSwitchNode ? $dvSwitchNode : null);
    $_SESSION['last_status'] = 'DISCONNECTED: ' . direct_node_status_label($selectedUiMode) . ' ' . $selectedNode;
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''));
}

$trackedNode = last_tracked_allstar_node($hasRealDvSwitchNode ? $dvSwitchNode : null);
if ($trackedNode === '') {
    if (live_iax_rpt_channels($myNode) !== []) {
        $_SESSION['last_status'] = 'ERROR: TRUE IAX CLIENT - USE ROW DISCONNECT';
        respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
    }

    $_SESSION['last_status'] = 'ERROR: NO DIRECT ALLSTAR NODE TRACKED';
    respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
}

$trackedUiMode = tracked_allstar_ui_mode($trackedNode);
$remainingEchoNodes = [];
$trackedEchoDirection = '';
if ($trackedUiMode === 'ECHO' || preg_match('/^3\d{6}$/', $trackedNode) === 1) {
    $trackedUiMode = 'ECHO';
    $echoDisconnectLock = acquire_mode_switch_lock();
    if (!is_resource($echoDisconnectLock)) {
        respond(direct_payload('ERROR: ANOTHER ECHOLINK OPERATION IS IN PROGRESS', $hasRealDvSwitchNode ? $dvSwitchNode : ''), 409);
    }
    $echoDirections = live_echolink_link_directions($myNode);
    $trackedEchoDirection = strtoupper(trim((string) ($echoDirections[$trackedNode] ?? '')));
    $remainingEchoNodes = array_values(array_filter(
        array_keys($echoDirections),
        static fn (mixed $node): bool => (string) $node !== $trackedNode
    ));
}
asterisk_ilink_disconnect($myNode, $trackedNode);
pause_seconds(1.0);
if ($trackedUiMode === 'ECHO' && $trackedEchoDirection === 'OUT' && $remainingEchoNodes === []) {
    reset_echolink_module_if_confirmed_idle($myNode);
}
untrack_allstar_link($trackedNode);
sync_last_direct_target_from_tracking($hasRealDvSwitchNode ? $dvSwitchNode : null);
$_SESSION['last_status'] = 'DISCONNECTED: ' . direct_node_status_label($trackedUiMode) . ' ' . $trackedNode;
respond(direct_payload($_SESSION['last_status'], $hasRealDvSwitchNode ? $dvSwitchNode : ''));
