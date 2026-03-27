<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/app/Support/Config.php';

use App\Support\Config;

header('Content-Type: application/json; charset=UTF-8');

$config = new Config(dirname(__DIR__) . '/config.ini');

function respond(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'status' => 'ERROR: JSON ENCODE FAILED',
            'status_text' => 'ERROR: JSON ENCODE FAILED',
            'last_status' => 'ERROR: JSON ENCODE FAILED',
            'json_error' => json_last_error_msg(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo $json;
    exit;
}

function shell_run(string $command): string
{
    $output = shell_exec($command . ' 2>&1');
    return is_string($output) ? trim($output) : '';
}

function asterisk_cli(string $command): string
{
    $full = 'sudo /usr/sbin/asterisk -rx ' . escapeshellarg($command);
    return shell_run($full);
}

function normalize_mode(?string $mode): string
{
    $value = strtoupper(trim((string) $mode));

    if (in_array($value, ['ALLSTAR', 'ALLSTAR LINK', 'ALLSTARLINK'], true)) {
        return 'ASL';
    }

    if (in_array($value, ['ECHO', 'ECHO LINK', 'ECHOLINK', 'EL', 'E/L'], true)) {
        return 'ECHO';
    }

    return $value;
}

function normalize_autoload_dvswitch_mode(mixed $mode): string
{
    $value = strtolower(trim((string) $mode));
    return $value === 'local_monitor' ? 'local_monitor' : 'transceive';
}

function autoload_dvswitch_mode_label(string $mode): string
{
    return normalize_autoload_dvswitch_mode($mode) === 'local_monitor'
        ? 'Local Monitor'
        : 'Transceive';
}

function normalize_link_mode_label(mixed $mode): string
{
    $value = strtolower(trim((string) $mode));
    return $value === 'local_monitor' ? 'Local Monitor' : 'Transceive';
}

function mask_value(string $value): string
{
    $trimmed = trim($value);

    if ($trimmed === '') {
        return '';
    }

    $length = strlen($trimmed);

    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return substr($trimmed, 0, 2) . str_repeat('*', max(0, $length - 4)) . substr($trimmed, -2);
}

function load_favorites_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (!is_array($lines)) {
        return [];
    }

    $favorites = [];

    foreach ($lines as $line) {
        $parts = explode('|', $line);

        $target = trim((string) ($parts[0] ?? ''));
        $name = trim((string) ($parts[1] ?? ''));
        $description = trim((string) ($parts[2] ?? '-'));
        $mode = normalize_mode((string) ($parts[3] ?? 'BM'));

        if ($target === '') {
            continue;
        }

        $favorites[] = [
            'target' => $target,
            'tg' => $target,
            'name' => $name,
            'description' => $description !== '' ? $description : '-',
            'desc' => $description !== '' ? $description : '-',
            'mode' => $mode,
        ];
    }

    return $favorites;
}

function fetch_live_allstar_nodes(string $myNode, string $dvSwitchNode): array
{
    if ($myNode === '') {
        return [];
    }

    $output = asterisk_cli("rpt nodes {$myNode}");
    if ($output === '') {
        return [];
    }

    if (stripos($output, '<NONE>') !== false) {
        return [];
    }

    $nodes = [];

    if (preg_match_all('/\b(\d{3,})\b/', $output, $matches)) {
        foreach ($matches[1] as $node) {
            $node = trim((string) $node);

            if ($node === '' || $node === $myNode || $node === $dvSwitchNode) {
                continue;
            }

            $nodes[$node] = $node;
        }
    }

    return array_values($nodes);
}

function allstar_tracked_nodes_in_order(): array
{
    $ordered = [];
    $seen = [];

    $storedOrder = $_SESSION['allstar_link_order'] ?? [];
    if (is_array($storedOrder)) {
        foreach ($storedOrder as $node) {
            $node = trim((string) $node);
            if ($node === '' || isset($seen[$node])) {
                continue;
            }

            $ordered[] = $node;
            $seen[$node] = true;
        }
    }

    $storedModes = $_SESSION['allstar_link_modes'] ?? [];
    if (is_array($storedModes)) {
        foreach ($storedModes as $node => $mode) {
            $node = trim((string) $node);
            if ($node === '' || isset($seen[$node])) {
                continue;
            }

            $ordered[] = $node;
            $seen[$node] = true;
        }
    }

    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $lastTarget = trim((string) ($_SESSION['last_target'] ?? ''));
    if (($lastMode === 'ASL' || $lastMode === 'ECHO') && $lastTarget !== '' && !isset($seen[$lastTarget])) {
        $ordered[] = $lastTarget;
    }

    return $ordered;
}

function build_allstar_connected_nodes(
    string $myNode,
    string $dvSwitchNode,
    string $lastMode,
    string $lastTarget,
    string $autoloadDvSwitchMode
): array {
    $storedModes = $_SESSION['allstar_link_modes'] ?? [];
    if (!is_array($storedModes)) {
        $storedModes = [];
    }

    $trackedNodes = allstar_tracked_nodes_in_order();
    $liveNodes = fetch_live_allstar_nodes($myNode, $dvSwitchNode);
    $liveLookup = array_fill_keys($liveNodes, true);

    $connectedNodes = [];

    foreach ($trackedNodes as $node) {
        $mode = null;

        if (isset($storedModes[$node])) {
            $mode = normalize_autoload_dvswitch_mode($storedModes[$node]);
        } elseif (($lastMode === 'ASL' || $lastMode === 'ECHO') && $lastTarget === $node) {
            $mode = normalize_autoload_dvswitch_mode($autoloadDvSwitchMode);
        }

        $connectedNodes[] = [
            'node' => $node,
            'label' => 'Connected Node',
            'link_mode' => $mode ?? '',
            'mode_label' => $mode !== null ? normalize_link_mode_label($mode) : 'Connected',
            'is_live' => isset($liveLookup[$node]),
        ];
    }

    if ($connectedNodes === [] && ($lastMode === 'ASL' || $lastMode === 'ECHO') && $lastTarget !== '') {
        $connectedNodes[] = [
            'node' => $lastTarget,
            'label' => 'Connected Node',
            'link_mode' => $autoloadDvSwitchMode,
            'mode_label' => normalize_link_mode_label($autoloadDvSwitchMode),
            'is_live' => isset($liveLookup[$lastTarget]),
        ];
    }

    return $connectedNodes;
}

$favorites = load_favorites_file(dirname(__DIR__) . '/data/favorites.txt');

$selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? 'BM'));
$lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
$lastTarget = trim((string) ($_SESSION['last_target'] ?? ''));
$pendingTarget = trim((string) ($_SESSION['pending_target'] ?? $_SESSION['pending_tg'] ?? ''));
$lastStatus = trim((string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS'));
$autoloadDvSwitch = !empty($_SESSION['autoload_dvswitch']);
$autoloadDvSwitchMode = normalize_autoload_dvswitch_mode($_SESSION['autoload_dvswitch_mode'] ?? 'transceive');
$disconnectBeforeConnect = !empty($_SESSION['disconnect_before_connect']);
$dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
$dmrReady = !empty($_SESSION['dmr_ready']);
$dmrActiveNetwork = normalize_mode((string) ($_SESSION['dmr_active_network'] ?? ''));
$dmrActiveTarget = trim((string) ($_SESSION['dmr_active_target'] ?? ''));
$myNode = $config->getString('MYNODE', '');
$dvSwitchNode = $config->getString('DVSWITCH_NODE', '');
$dvswitchLinkActive = !empty($_SESSION['dvswitch_autoloaded']) || $dmrReady || $lastMode === 'YSF';

$bmState = 'Idle';
$tgifState = 'Idle';
$ysfState = 'Idle';
$allstarState = 'No links';

if ($dmrActiveNetwork === 'BM' && $dmrActiveTarget !== '') {
    $bmState = 'Connected: TG ' . $dmrActiveTarget;
} elseif ($dmrNetwork === 'BM' && $dmrReady && str_starts_with(strtoupper($lastStatus), 'WAITING: BM READY')) {
    $bmState = 'Ready';
} elseif ($dmrNetwork === 'BM' && !$dmrReady) {
    $bmState = 'Preparing';
}

if ($dmrActiveNetwork === 'TGIF' && $dmrActiveTarget !== '') {
    $tgifState = 'Connected: TG ' . $dmrActiveTarget;
} elseif ($dmrNetwork === 'TGIF' && $dmrReady && str_starts_with(strtoupper($lastStatus), 'WAITING: TGIF READY')) {
    $tgifState = 'Ready';
} elseif ($dmrNetwork === 'TGIF' && !$dmrReady) {
    $tgifState = 'Preparing';
}

if ($lastMode === 'YSF' && $lastTarget !== '') {
    $ysfState = 'Connected: ' . $lastTarget;
}

$allstarConnectedNodes = build_allstar_connected_nodes(
    $myNode,
    $dvSwitchNode,
    $lastMode,
    $lastTarget,
    $autoloadDvSwitchMode
);

if ($allstarConnectedNodes !== []) {
    $allstarState = 'Connected: ' . count($allstarConnectedNodes);
}

$payload = [
    'ok' => true,
    'status' => $lastStatus,
    'status_text' => $lastStatus,
    'last_status' => $lastStatus,

    'system' => [
        'status_text' => $lastStatus,
        'selected_mode' => $selectedMode,
        'last_mode' => $lastMode,
        'last_target' => $lastTarget,
        'pending_target' => $pendingTarget,
        'autoload_dvswitch' => $autoloadDvSwitch,
        'autoload_dvswitch_mode' => $autoloadDvSwitchMode,
        'disconnect_before_connect' => $disconnectBeforeConnect,
        'dmr_network' => $dmrNetwork,
        'dmr_ready' => $dmrReady,
        'dmr_active_network' => $dmrActiveNetwork,
        'dmr_active_target' => $dmrActiveTarget,
        'dvswitch_link_active' => $dvswitchLinkActive,
    ],

    'selected_mode' => $selectedMode,
    'last_mode' => $lastMode,
    'last_target' => $lastTarget,
    'pending_target' => $pendingTarget,
    'autoload_dvswitch' => $autoloadDvSwitch,
    'autoload_dvswitch_mode' => $autoloadDvSwitchMode,
    'disconnect_before_connect' => $disconnectBeforeConnect,
    'dmr_network' => $dmrNetwork,
    'dmr_ready' => $dmrReady,
    'dmr_active_network' => $dmrActiveNetwork,
    'dmr_active_target' => $dmrActiveTarget,
    'dvswitch_link_active' => $dvswitchLinkActive,

    'config' => [
        'path' => $config->path(),
        'exists' => $config->exists(),
        'mynode' => $myNode,
        'dvswitch_node' => $dvSwitchNode,
        'has_bm_password' => $config->has('BM_SelfcarePassword'),
        'has_tgif_key' => $config->has('TGIF_HotspotSecurityKey'),
        'bm_password_masked' => mask_value($config->getString('BM_SelfcarePassword', '')),
        'tgif_key_masked' => mask_value($config->getString('TGIF_HotspotSecurityKey', '')),
    ],

    'favorites' => $favorites,
    'favorites_count' => count($favorites),

    'networks' => [
        'brandmeister' => [
            'state' => $bmState,
            'label' => $bmState,
            'status' => $bmState,
            'active' => $dmrActiveNetwork === 'BM' || ($dmrNetwork === 'BM' && $dmrReady),
        ],
        'tgif' => [
            'state' => $tgifState,
            'label' => $tgifState,
            'status' => $tgifState,
            'active' => $dmrActiveNetwork === 'TGIF' || ($dmrNetwork === 'TGIF' && $dmrReady),
        ],
        'ysf' => [
            'state' => $ysfState,
            'label' => $ysfState,
            'status' => $ysfState,
            'active' => $lastMode === 'YSF',
        ],
        'allstar' => [
            'state' => $allstarState,
            'label' => $allstarState,
            'status' => $allstarState,
            'connected_nodes_count' => count($allstarConnectedNodes),
            'connected_nodes' => $allstarConnectedNodes,
        ],
    ],

    'brandmeister' => [
        'state' => $bmState,
        'label' => $bmState,
        'status' => $bmState,
        'active' => $dmrActiveNetwork === 'BM' || ($dmrNetwork === 'BM' && $dmrReady),
    ],
    'tgif' => [
        'state' => $tgifState,
        'label' => $tgifState,
        'status' => $tgifState,
        'active' => $dmrActiveNetwork === 'TGIF' || ($dmrNetwork === 'TGIF' && $dmrReady),
    ],
    'ysf' => [
        'state' => $ysfState,
        'label' => $ysfState,
        'status' => $ysfState,
        'active' => $lastMode === 'YSF',
    ],

    'allstar' => [
        'state' => $allstarState,
        'label' => $allstarState,
        'status' => $allstarState,
        'connected_nodes_count' => count($allstarConnectedNodes),
        'connected_nodes' => $allstarConnectedNodes,
        'local_nodes' => array_values(array_filter([
            $myNode,
            $dvSwitchNode,
        ])),
    ],

    'activity' => [
        [
            'label' => 'Last Mode',
            'value' => $lastMode !== '' ? $lastMode : '-',
        ],
        [
            'label' => 'Last Target',
            'value' => $lastTarget !== '' ? $lastTarget : '-',
        ],
        [
            'label' => 'Pending Target',
            'value' => $pendingTarget !== '' ? $pendingTarget : '-',
        ],
        [
            'label' => 'DMR Network',
            'value' => $dmrActiveNetwork !== ''
                ? $dmrActiveNetwork . ($dmrActiveTarget !== '' ? ' (TG ' . $dmrActiveTarget . ')' : '')
                : ($dmrNetwork !== ''
                    ? $dmrNetwork . ($dmrReady ? ' (Ready)' : ' (Preparing)')
                    : '-'),
        ],
        [
            'label' => 'DVSwitch Auto-Load',
            'value' => $autoloadDvSwitch
                ? 'Enabled' . ($dvSwitchNode !== '' ? ' (' . $dvSwitchNode . ')' : '')
                : 'Disabled',
        ],
        [
            'label' => 'DVSwitch Auto-Load Mode',
            'value' => autoload_dvswitch_mode_label($autoloadDvSwitchMode),
        ],
        [
            'label' => 'DVSwitch Link Active',
            'value' => $dvswitchLinkActive ? 'Yes' : 'No',
        ],
        [
            'label' => 'Disconnect Before Connect',
            'value' => $disconnectBeforeConnect ? 'Enabled' : 'Disabled',
        ],
        [
            'label' => 'Current Status',
            'value' => $lastStatus,
        ],
    ],
];

respond($payload);