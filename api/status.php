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

function active_dvswitch_mode_label(string $mode): string
{
    $value = strtolower(trim($mode));

    if ($value === 'local_monitor') {
        return 'Local Monitor';
    }

    if ($value === 'transceive') {
        return 'Transceive';
    }

    return '-';
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

function sync_live_allstar_tracking(array $links): void
{
    ensure_allstar_tracking_structures();

    $modes = [];
    $order = [];
    $uiModes = [];

    foreach ($links as $link) {
        $node = trim((string) ($link['node'] ?? ''));
        if ($node === '') {
            continue;
        }

        $order[] = $node;
        $modes[$node] = normalize_autoload_dvswitch_mode((string) ($link['link_mode'] ?? 'local_monitor'));

        $existingUiMode = trim((string) ($_SESSION['allstar_link_ui_modes'][$node] ?? ''));
        if ($existingUiMode !== '') {
            $uiModes[$node] = normalize_mode($existingUiMode) === 'ECHO' ? 'ECHO' : 'ASL';
        } else {
            $uiModes[$node] = ctype_digit($node) && (int) $node >= 3000000 ? 'ECHO' : 'ASL';
        }
    }

    $_SESSION['allstar_link_modes'] = $modes;
    $_SESSION['allstar_link_order'] = $order;
    $_SESSION['allstar_link_ui_modes'] = $uiModes;
}

function ami_config_from_manager_conf(): ?array
{
    $file = '/etc/asterisk/manager.conf';
    if (!is_file($file)) {
        return null;
    }

    $parsed = parse_ini_file($file, true, INI_SCANNER_RAW);
    if (!is_array($parsed)) {
        return null;
    }

    $host = '127.0.0.1';
    $port = '5038';
    $user = '';
    $pass = '';

    foreach ($parsed as $section => $values) {
        if (!is_array($values)) {
            continue;
        }

        if ($section === 'general') {
            if (isset($values['bindaddr']) && trim((string) $values['bindaddr']) !== '') {
                $host = trim((string) $values['bindaddr']);
            }
            if (isset($values['port']) && trim((string) $values['port']) !== '') {
                $port = trim((string) $values['port']);
            }
            continue;
        }

        if ($user === '' && array_key_exists('secret', $values)) {
            $user = trim((string) $section);
            $pass = trim((string) ($values['secret'] ?? ''));
        }
    }

    if ($user === '' || $pass === '') {
        return null;
    }

    return [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
    ];
}

function ami_connect_socket(array $cfg)
{
    if (($cfg['host'] ?? '') === '' || ($cfg['port'] ?? '') === '' || ($cfg['user'] ?? '') === '' || ($cfg['pass'] ?? '') === '') {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen((string) $cfg['host'], (int) $cfg['port'], $errno, $errstr, 5);
    if ($fp === false) {
        return false;
    }

    stream_set_timeout($fp, 5);
    return $fp;
}

function ami_get_response($fp, string $actionId): array|string
{
    $ignore = ['Privilege: Command', 'Command output follows'];
    $response = [];
    $start = time();

    while (time() - $start < 20) {
        $line = fgets($fp);
        if ($line === false) {
            return $response;
        }

        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (strpos($line, 'Response: ') === 0) {
            $response[] = $line;
            continue;
        }

        if ($line === 'ActionID: ' . $actionId) {
            $response[] = $line;

            while (time() - $start < 20) {
                $next = fgets($fp);
                if ($next === false || $next === "\r\n" || $next === "\n") {
                    return $response;
                }

                $next = trim($next);
                if ($next === '' || in_array($next, $ignore, true)) {
                    continue;
                }

                $response[] = $next;
            }
        }
    }

    return $response;
}

function ami_login_socket($fp, array $cfg): bool
{
    $actionId = 'login_' . md5((string) microtime(true));
    $command = "ACTION: LOGIN\r\n"
        . 'USERNAME: ' . $cfg['user'] . "\r\n"
        . 'SECRET: ' . $cfg['pass'] . "\r\n"
        . "EVENTS: 0\r\n"
        . 'ActionID: ' . $actionId . "\r\n\r\n";

    if (@fwrite($fp, $command) === false) {
        return false;
    }

    $response = ami_get_response($fp, $actionId);
    if (!is_array($response) || count($response) < 3) {
        return false;
    }

    return strpos((string) ($response[2] ?? ''), 'Authentication accepted') !== false;
}

function ami_rpt_status($fp, string $node, string $command): array
{
    $actionId = strtolower($command) . '_' . mt_rand();

    $payload = "ACTION: RptStatus\r\n"
        . 'COMMAND: ' . $command . "\r\n"
        . 'NODE: ' . $node . "\r\n"
        . 'ActionID: ' . $actionId . "\r\n\r\n";

    if (@fwrite($fp, $payload) === false) {
        return [];
    }

    $response = ami_get_response($fp, $actionId);
    return is_array($response) ? $response : [];
}

function parse_live_allstar_links_from_ami(array $xstatLines, array $sawStatLines): array
{
    $connections = [];
    $modeCodes = [];
    $keyed = [];

    foreach ($xstatLines as $line) {
        if (preg_match('/Conn:\s+(.*)/', $line, $matches) === 1) {
            $parts = preg_split('/\s+/', trim($matches[1]));
            if (is_array($parts) && isset($parts[0]) && ctype_digit((string) $parts[0])) {
                $node = trim((string) $parts[0]);
                $connections[] = [
                    'node' => $node,
                    'ip' => $parts[1] ?? '',
                    'direction' => isset($parts[5]) ? ($parts[3] ?? '') : ($parts[2] ?? ''),
                    'elapsed' => isset($parts[5]) ? ($parts[4] ?? '') : ($parts[3] ?? ''),
                    'link' => isset($parts[5]) ? ($parts[5] ?? '') : '',
                ];
            }
        }

        if (preg_match('/LinkedNodes:\s+(.*)/', $line, $matches) === 1) {
            $items = preg_split('/,\s*/', trim($matches[1]));
            if (is_array($items)) {
                foreach ($items as $item) {
                    $item = trim((string) $item);
                    if ($item === '' || strlen($item) < 2) {
                        continue;
                    }

                    $code = substr($item, 0, 1);
                    $node = substr($item, 1);

                    if (ctype_digit($node)) {
                        $modeCodes[$node] = $code;
                    }
                }
            }
        }
    }

    foreach ($sawStatLines as $line) {
        if (preg_match('/Conn:\s+(.*)/', $line, $matches) === 1) {
            $parts = preg_split('/\s+/', trim($matches[1]));
            if (is_array($parts) && isset($parts[0]) && ctype_digit((string) $parts[0])) {
                $node = trim((string) $parts[0]);
                $keyed[$node] = [
                    'isKeyed' => $parts[1] ?? '0',
                    'lastKeyed' => $parts[2] ?? '-1',
                    'lastUnkeyed' => $parts[3] ?? '-1',
                ];
            }
        }
    }

    $links = [];
    foreach ($connections as $connection) {
        $node = $connection['node'];
        $modeCode = strtoupper(trim((string) ($modeCodes[$node] ?? '')));
        $linkMode = $modeCode === 'T' ? 'transceive' : 'local_monitor';

        $links[] = [
            'node' => $node,
            'label' => 'Connected Node',
            'link_mode' => $linkMode,
            'mode_label' => $linkMode === 'transceive' ? 'Transceive' : 'Local Monitor',
            'is_live' => true,
            'direction' => $connection['direction'],
            'elapsed' => $connection['elapsed'],
            'keyed' => isset($keyed[$node]) ? (($keyed[$node]['isKeyed'] ?? '0') === '1') : false,
            'last_keyed' => $keyed[$node]['lastKeyed'] ?? '-1',
        ];
    }

    return $links;
}

function fetch_live_allstar_links_via_ami(string $myNode): array
{
    if ($myNode === '') {
        return [
            'available' => false,
            'links' => [],
        ];
    }

    $cfg = ami_config_from_manager_conf();
    if ($cfg === null) {
        return [
            'available' => false,
            'links' => [],
        ];
    }

    $fp = ami_connect_socket($cfg);
    if ($fp === false) {
        return [
            'available' => false,
            'links' => [],
        ];
    }

    try {
        if (!ami_login_socket($fp, $cfg)) {
            return [
                'available' => false,
                'links' => [],
            ];
        }

        $xstat = ami_rpt_status($fp, $myNode, 'XStat');
        $sawStat = ami_rpt_status($fp, $myNode, 'SawStat');

        return [
            'available' => true,
            'links' => parse_live_allstar_links_from_ami($xstat, $sawStat),
        ];
    } finally {
        @fclose($fp);
    }
}

function allstar_tracked_nodes_in_order(): array
{
    ensure_allstar_tracking_structures();

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

function build_tracked_allstar_connected_nodes(
    string $lastMode,
    string $lastTarget,
    string $autoloadDvSwitchMode
): array {
    ensure_allstar_tracking_structures();

    $storedModes = $_SESSION['allstar_link_modes'] ?? [];
    if (!is_array($storedModes)) {
        $storedModes = [];
    }

    $trackedNodes = allstar_tracked_nodes_in_order();
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
            'is_live' => false,
        ];
    }

    if ($connectedNodes === [] && ($lastMode === 'ASL' || $lastMode === 'ECHO') && $lastTarget !== '') {
        $connectedNodes[] = [
            'node' => $lastTarget,
            'label' => 'Connected Node',
            'link_mode' => $autoloadDvSwitchMode,
            'mode_label' => normalize_link_mode_label($autoloadDvSwitchMode),
            'is_live' => false,
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
if (!isset($_SESSION['autoload_dvswitch'])) {
    $_SESSION['autoload_dvswitch'] = true;
}

if (!isset($_SESSION['autoload_dvswitch_mode'])) {
    $_SESSION['autoload_dvswitch_mode'] = 'transceive';
}

if (!isset($_SESSION['disconnect_before_connect'])) {
    $_SESSION['disconnect_before_connect'] = false;
}

ensure_allstar_tracking_structures();

$autoloadDvSwitch = (bool) $_SESSION['autoload_dvswitch'];
$autoloadDvSwitchMode = normalize_autoload_dvswitch_mode($_SESSION['autoload_dvswitch_mode']);
$rawDvSwitchActiveMode = strtolower(trim((string) ($_SESSION['dvswitch_active_mode'] ?? '')));
$dvswitchActiveMode = in_array($rawDvSwitchActiveMode, ['local_monitor', 'transceive'], true)
    ? $rawDvSwitchActiveMode
    : '';
$disconnectBeforeConnect = (bool) $_SESSION['disconnect_before_connect'];
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

$liveAllstar = fetch_live_allstar_links_via_ami($myNode);
if ($liveAllstar['available']) {
    $allstarConnectedNodes = $liveAllstar['links'];
    sync_live_allstar_tracking($allstarConnectedNodes);
} else {
    $allstarConnectedNodes = build_tracked_allstar_connected_nodes(
        $lastMode,
        $lastTarget,
        $autoloadDvSwitchMode
    );
}

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
        'dvswitch_active_mode' => $dvswitchActiveMode,
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
    'dvswitch_active_mode' => $dvswitchActiveMode,
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
            'label' => 'Link Mode',
            'value' => autoload_dvswitch_mode_label($autoloadDvSwitchMode),
        ],
        [
            'label' => 'DVSwitch Active Link Mode',
            'value' => active_dvswitch_mode_label($dvswitchActiveMode),
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
