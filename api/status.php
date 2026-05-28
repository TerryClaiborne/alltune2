<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\App\Support\AppSession::start();

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

    if (in_array($value, ['D-STAR', 'D STAR', 'DSTAR'], true)) {
        return 'DSTAR';
    }

    if (in_array($value, ['P-25', 'P 25', 'P25'], true)) {
        return 'P25';
    }

    if (in_array($value, ['N-XDN', 'N XDN', 'NXDN'], true)) {
        return 'NXDN';
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

function managed_digital_mode_label(string $mode): string
{
    return match (normalize_mode($mode)) {
        'DSTAR' => 'D-Star',
        'P25' => 'P25',
        'NXDN' => 'NXDN',
        default => strtoupper(trim($mode)),
    };
}

function is_managed_digital_mode(string $mode): bool
{
    return in_array(normalize_mode($mode), ['DSTAR', 'P25', 'NXDN'], true);
}

function is_managed_dvswitch_session_mode(string $mode): bool
{
    return in_array(normalize_mode($mode), ['BM', 'TGIF', 'YSF', 'DSTAR', 'P25', 'NXDN'], true);
}

function active_managed_dvswitch_mode(string $lastMode): string
{
    $managedMode = normalize_mode((string) ($_SESSION['managed_dvswitch_mode'] ?? ''));
    if (is_managed_dvswitch_session_mode($managedMode)) {
        return $managedMode;
    }

    return is_managed_dvswitch_session_mode($lastMode) ? normalize_mode($lastMode) : '';
}

function active_managed_dvswitch_target(string $lastTarget): string
{
    $managedTarget = trim((string) ($_SESSION['managed_dvswitch_target'] ?? ''));
    return $managedTarget !== '' ? $managedTarget : trim($lastTarget);
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

function config_flag_enabled(Config $config, string $key): bool
{
    $value = strtolower(trim((string) $config->get($key, '0')));
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
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


function allstar_astdb_candidate_paths(): array
{
    return [
        '/var/lib/asterisk/astdb.txt',
        '/var/www/html/allscan/astdb.txt',
        '/var/www/html/supermon/astdb.txt',
    ];
}

function clean_allstar_display_part(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if ($value === '-' || $value === '--') {
        return '';
    }

    return $value;
}

function allstar_astdb_lookup(string $node): ?array
{
    static $cache = [];
    static $loaded = false;

    $node = trim($node);
    if ($node === '') {
        return null;
    }

    if (!$loaded) {
        $loaded = true;

        foreach (allstar_astdb_candidate_paths() as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $handle = @fopen($path, 'r');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = explode('|', $line);
                if (count($parts) < 2) {
                    continue;
                }

                $entryNode = trim((string) ($parts[0] ?? ''));
                if ($entryNode === '') {
                    continue;
                }

                $call = clean_allstar_display_part((string) ($parts[1] ?? ''));
                $description = clean_allstar_display_part((string) ($parts[2] ?? ''));
                $location = clean_allstar_display_part((string) ($parts[3] ?? ''));

                $displayParts = [];
                if ($call !== '') {
                    $displayParts[] = $call;
                }
                if ($description !== '') {
                    $displayParts[] = $description;
                }

                $full = implode(' - ', $displayParts);
                if ($location !== '') {
                    $full = $full !== '' ? $full . ', ' . $location : $location;
                }

                $cache[$entryNode] = [
                    'call' => $call,
                    'description' => $description,
                    'location' => $location,
                    'short' => $call !== '' ? $call : ($description !== '' ? $description : $location),
                    'full' => $full,
                ];
            }

            fclose($handle);
            break;
        }
    }

    return $cache[$node] ?? null;
}

function enrich_allstar_connected_nodes(array $links): array
{
    foreach ($links as &$link) {
        $node = trim((string) ($link['node'] ?? $link['target'] ?? ''));
        if ($node === '') {
            continue;
        }

        $astdb = allstar_astdb_lookup($node);
        if ($astdb === null) {
            continue;
        }

        $link['display_name'] = $astdb['call'];
        $link['display_description'] = $astdb['description'];
        $link['display_location'] = $astdb['location'];
        $link['display_short'] = $astdb['short'];
        $link['display_full'] = $astdb['full'];
    }
    unset($link);

    return $links;
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

function read_bm_receive_helper_status(): array
{
    $script = dirname(__DIR__) . '/alltune2-bm-receive.sh';

    $fallback = [
        'available' => false,
        'ok' => false,
        'active' => false,
        'target' => '',
        'message' => '',
        'stfu_running' => false,
        'mmdvm_bridge' => '',
        'pid' => '',
        'version' => '',
        'raw_output' => '',
    ];

    if (!is_file($script)) {
        return $fallback;
    }

    $command = 'sudo ' . escapeshellarg($script) . ' status';
    $output = shell_run($command);

    if ($output === '') {
        return array_merge($fallback, [
            'available' => true,
            'message' => 'BM helper returned no output.',
        ]);
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        return array_merge($fallback, [
            'available' => true,
            'message' => 'BM helper returned invalid JSON.',
            'raw_output' => $output,
        ]);
    }

    return [
        'available' => true,
        'ok' => !empty($decoded['ok']),
        'active' => !empty($decoded['active']),
        'target' => trim((string) ($decoded['target'] ?? '')),
        'message' => trim((string) ($decoded['message'] ?? '')),
        'stfu_running' => !empty($decoded['stfu_running']),
        'mmdvm_bridge' => trim((string) ($decoded['mmdvm_bridge'] ?? '')),
        'pid' => trim((string) ($decoded['pid'] ?? '')),
        'version' => trim((string) ($decoded['version'] ?? '')),
        'raw_output' => $output,
    ];
}


function read_tgifd_helper_status(): array
{
    $script = dirname(__DIR__) . '/tgif/alltune2-tgifd-helper.sh';

    $fallback = [
        'available' => false,
        'ok' => false,
        'active' => false,
        'target' => '',
        'message' => '',
        'tgif_running' => false,
        'mmdvm_bridge' => '',
        'analog_bridge' => '',
        'pid' => '',
        'raw_output' => '',
    ];

    if (!is_file($script)) {
        return $fallback;
    }

    $command = 'sudo ' . escapeshellarg($script) . ' status';
    $output = shell_run($command);

    if ($output === '') {
        return array_merge($fallback, [
            'available' => true,
            'message' => 'TGIFD helper returned no output.',
        ]);
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        return array_merge($fallback, [
            'available' => true,
            'message' => 'TGIFD helper returned invalid JSON.',
            'raw_output' => $output,
        ]);
    }

    return [
        'available' => true,
        'ok' => !empty($decoded['ok']),
        'active' => !empty($decoded['active']),
        'target' => trim((string) ($decoded['target'] ?? '')),
        'message' => trim((string) ($decoded['message'] ?? '')),
        'tgif_running' => !empty($decoded['tgif_running']),
        'mmdvm_bridge' => trim((string) ($decoded['mmdvm_bridge'] ?? '')),
        'analog_bridge' => trim((string) ($decoded['analog_bridge'] ?? '')),
        'pid' => trim((string) ($decoded['pid'] ?? '')),
        'raw_output' => $output,
    ];
}


function session_may_have_bm_runtime(string $selectedMode, string $lastMode, string $dmrNetwork, string $dmrActiveNetwork, string $lastStatus): bool
{
    $lastStatus = strtoupper(trim($lastStatus));
    return $selectedMode === 'BM'
        || $lastMode === 'BM'
        || $dmrNetwork === 'BM'
        || $dmrActiveNetwork === 'BM'
        || str_contains($lastStatus, '(BM)')
        || str_contains($lastStatus, ' BM');
}

function session_may_have_tgif_runtime(string $selectedMode, string $lastMode, string $dmrNetwork, string $dmrActiveNetwork, string $lastStatus): bool
{
    $lastStatus = strtoupper(trim($lastStatus));
    return $selectedMode === 'TGIF'
        || $lastMode === 'TGIF'
        || $dmrNetwork === 'TGIF'
        || $dmrActiveNetwork === 'TGIF'
        || str_contains($lastStatus, '(TGIF)')
        || str_contains($lastStatus, ' TGIF');
}

function session_forces_private_node(string $selectedMode, string $lastMode, string $dmrNetwork, string $dmrActiveNetwork, bool $dmrReady, bool $dvswitchAutoloaded): bool
{
    return in_array($selectedMode, ['BM', 'TGIF', 'YSF', 'DSTAR'], true)
        || in_array($lastMode, ['BM', 'TGIF', 'YSF', 'DSTAR', 'P25', 'NXDN'], true)
        || in_array($dmrNetwork, ['BM', 'TGIF'], true)
        || in_array($dmrActiveNetwork, ['BM', 'TGIF'], true)
        || $dmrReady
        || $dvswitchAutoloaded;
}

$favorites = load_favorites_file(dirname(__DIR__) . '/data/favorites.txt');

$selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? 'BM'));
$lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
$lastTarget = trim((string) ($_SESSION['last_target'] ?? ''));
$managedDvSwitchMode = active_managed_dvswitch_mode($lastMode);
$managedDvSwitchTarget = active_managed_dvswitch_target($lastTarget);
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
$dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);
$dvswitchLinkActive = $dvswitchAutoloaded || $dmrReady || $managedDvSwitchMode !== '';

$forcedPrivateNode = session_forces_private_node($selectedMode, $managedDvSwitchMode !== '' ? $managedDvSwitchMode : $lastMode, $dmrNetwork, $dmrActiveNetwork, $dmrReady, $dvswitchAutoloaded);
$autoloadDvSwitch = $autoloadDvSwitch || $forcedPrivateNode;

$bmReceive = session_may_have_bm_runtime($selectedMode, $lastMode, $dmrNetwork, $dmrActiveNetwork, $lastStatus)
    ? read_bm_receive_helper_status()
    : [
        'available' => false,
        'ok' => false,
        'active' => false,
        'target' => '',
        'message' => '',
        'stfu_running' => false,
        'mmdvm_bridge' => '',
        'pid' => '',
        'version' => '',
        'raw_output' => '',
    ];
$tgifdRuntimeHint = is_file(dirname(__DIR__) . '/run/alltune2-tgifd.state')
    || is_file(dirname(__DIR__) . '/run/alltune2-tgifd.pid');

$tgifd = (session_may_have_tgif_runtime($selectedMode, $lastMode, $dmrNetwork, $dmrActiveNetwork, $lastStatus) || $tgifdRuntimeHint)
    ? read_tgifd_helper_status()
    : [
        'available' => false,
        'ok' => false,
        'active' => false,
        'target' => '',
        'message' => '',
        'tgif_running' => false,
        'mmdvm_bridge' => '',
        'analog_bridge' => '',
        'pid' => '',
        'raw_output' => '',
    ];

if ($bmReceive['active']) {
    $dmrNetwork = 'BM';
    $dmrReady = true;
    $dmrActiveNetwork = 'BM';
    $dmrActiveTarget = $bmReceive['target'];
    $dvswitchLinkActive = true;
}

if ($tgifd['active']) {
    $dmrNetwork = 'TGIF';
    $dmrReady = true;
    $dmrActiveNetwork = 'TGIF';
    $dmrActiveTarget = $tgifd['target'];
    $dvswitchLinkActive = true;
    $lastMode = 'TGIF';
    if ($tgifd['target'] !== '') {
        $lastTarget = $tgifd['target'];
        $pendingTarget = $tgifd['target'];
        $lastStatus = 'CONNECTED: TG ' . $tgifd['target'] . ' (TGIF)';
    }
}

$bmState = 'Idle';
$tgifState = 'Idle';
$ysfState = 'Idle';
$dstarState = 'Idle';
$p25State = 'Idle';
$nxdnState = 'Idle';
$allstarState = 'No links';
$bmKeyed = false;
$tgifKeyed = false;
$ysfKeyed = false;
$dstarKeyed = false;
$p25Keyed = false;
$nxdnKeyed = false;
$allstarKeyed = false;

if ($bmReceive['active'] && $bmReceive['target'] !== '') {
    $bmState = 'Connected: TG ' . $bmReceive['target'];
} elseif ($dmrActiveNetwork === 'BM' && $dmrActiveTarget !== '') {
    $bmState = 'Connected: TG ' . $dmrActiveTarget;
} elseif ($dmrNetwork === 'BM' && $dmrReady && str_starts_with(strtoupper($lastStatus), 'WAITING: BM READY')) {
    $bmState = 'Ready';
} elseif ($dmrNetwork === 'BM' && !$dmrReady) {
    $bmState = 'Preparing';
}

if ($tgifd['active'] && $tgifd['target'] !== '') {
    $tgifState = 'Connected: TG ' . $tgifd['target'];
} elseif ($dmrActiveNetwork === 'TGIF' && $dmrActiveTarget !== '') {
    $tgifState = 'Connected: TG ' . $dmrActiveTarget;
} elseif ($dmrNetwork === 'TGIF' && $dmrReady && str_starts_with(strtoupper($lastStatus), 'WAITING: TGIF READY')) {
    $tgifState = 'Ready';
} elseif ($dmrNetwork === 'TGIF' && !$dmrReady) {
    $tgifState = 'Preparing';
}

if ($managedDvSwitchMode === 'YSF' && $managedDvSwitchTarget !== '') {
    $ysfState = 'Connected: ' . $managedDvSwitchTarget;
}

if ($managedDvSwitchMode === 'DSTAR' && $managedDvSwitchTarget !== '') {
    $dstarState = 'Connected: ' . $managedDvSwitchTarget;
}

if ($managedDvSwitchMode === 'P25' && $managedDvSwitchTarget !== '') {
    $p25State = 'Connected: ' . $managedDvSwitchTarget;
}

if ($managedDvSwitchMode === 'NXDN' && $managedDvSwitchTarget !== '') {
    $nxdnState = 'Connected: ' . $managedDvSwitchTarget;
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

$allstarConnectedNodes = enrich_allstar_connected_nodes($allstarConnectedNodes);

if ($allstarConnectedNodes !== []) {
    $allstarState = 'Connected: ' . count($allstarConnectedNodes);
}

$dvswitchNodeKeyed = false;

foreach ($allstarConnectedNodes as $link) {
    $lastKeyedRaw = trim((string) ($link['last_keyed'] ?? '-1'));
    $recentlyKeyed = preg_match('/^-?\\d+$/', $lastKeyedRaw) === 1
        && (int) $lastKeyedRaw >= 0
        && (int) $lastKeyedRaw <= 5;

    $isKeyed = !empty($link['keyed']) || $recentlyKeyed;
    if ($isKeyed) {
        $allstarKeyed = true;
    }

    $linkNode = trim((string) ($link['node'] ?? ''));
    if ($dvSwitchNode !== '' && $linkNode === $dvSwitchNode && $isKeyed) {
        $dvswitchNodeKeyed = true;
    }
}

$bmActive = $dmrActiveNetwork === 'BM' || ($dmrNetwork === 'BM' && $dmrReady) || $bmReceive['active'];
$tgifActive = $dmrActiveNetwork === 'TGIF' || ($dmrNetwork === 'TGIF' && $dmrReady) || $tgifd['active'];
$ysfActive = $managedDvSwitchMode === 'YSF' && $managedDvSwitchTarget !== '';
$dstarActive = $managedDvSwitchMode === 'DSTAR' && $managedDvSwitchTarget !== '';
$p25Active = $managedDvSwitchMode === 'P25' && $managedDvSwitchTarget !== '';
$nxdnActive = $managedDvSwitchMode === 'NXDN' && $managedDvSwitchTarget !== '';

$bmKeyed = $bmActive && $dvswitchNodeKeyed;
$tgifKeyed = $tgifActive && $dvswitchNodeKeyed;
$ysfKeyed = $ysfActive && $dvswitchNodeKeyed;
$dstarKeyed = $dstarActive && $dvswitchNodeKeyed;
$p25Keyed = $p25Active && $dvswitchNodeKeyed;
$nxdnKeyed = $nxdnActive && $dvswitchNodeKeyed;


$activity = [];

if ($lastMode !== '') {
    $activity[] = [
        'label' => 'Last Mode',
        'value' => $lastMode,
    ];
}

if ($lastTarget !== '') {
    $activity[] = [
        'label' => 'Last Target',
        'value' => $lastTarget,
    ];
}

if ($pendingTarget !== '') {
    $activity[] = [
        'label' => 'Pending Target',
        'value' => $pendingTarget,
    ];
}

$dmrActivityValue = $bmReceive['active']
    ? 'BM (TG ' . $bmReceive['target'] . ' Receive)'
    : ($dmrActiveNetwork !== ''
        ? $dmrActiveNetwork . ($dmrActiveTarget !== '' ? ' (TG ' . $dmrActiveTarget . ')' : '')
        : ($dmrNetwork !== ''
            ? $dmrNetwork . ($dmrReady ? ' (Ready)' : ' (Preparing)')
            : ''));

if ($dmrActivityValue !== '') {
    $activity[] = [
        'label' => 'DMR Network',
        'value' => $dmrActivityValue,
    ];
} elseif (is_managed_digital_mode($managedDvSwitchMode) && $managedDvSwitchTarget !== '') {
    $activity[] = [
        'label' => 'Digital Network',
        'value' => managed_digital_mode_label($managedDvSwitchMode) . ' (' . $managedDvSwitchTarget . ')',
    ];
}

$activity[] = [
    'label' => 'DVSwitch Auto-Load',
    'value' => $autoloadDvSwitch
        ? 'Enabled' . ($dvSwitchNode !== '' ? ' (' . $dvSwitchNode . ')' : '')
        : 'Disabled',
];

$activity[] = [
    'label' => 'Link Mode',
    'value' => autoload_dvswitch_mode_label($autoloadDvSwitchMode),
];

if ($dvswitchActiveMode !== '') {
    $activity[] = [
        'label' => 'DVSwitch Active Link Mode',
        'value' => active_dvswitch_mode_label($dvswitchActiveMode),
    ];
}

$activity[] = [
    'label' => 'DVSwitch Link Active',
    'value' => $dvswitchLinkActive ? 'Yes' : 'No',
];

$activity[] = [
    'label' => 'Disconnect Before Connect',
    'value' => $disconnectBeforeConnect ? 'Enabled' : 'Disabled',
];

$activity[] = [
    'label' => 'Current Status',
    'value' => $lastStatus,
];

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
        'managed_dvswitch_mode' => $managedDvSwitchMode,
        'managed_dvswitch_target' => $managedDvSwitchTarget,
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

    'bm_receive' => [
        'available' => $bmReceive['available'],
        'ok' => $bmReceive['ok'],
        'active' => $bmReceive['active'],
        'target' => $bmReceive['target'],
        'message' => $bmReceive['message'],
        'stfu_running' => $bmReceive['stfu_running'],
        'mmdvm_bridge' => $bmReceive['mmdvm_bridge'],
        'pid' => $bmReceive['pid'],
        'version' => $bmReceive['version'],
    ],


    'tgifd' => [
        'available' => $tgifd['available'],
        'ok' => $tgifd['ok'],
        'active' => $tgifd['active'],
        'target' => $tgifd['target'],
        'message' => $tgifd['message'],
        'tgif_running' => $tgifd['tgif_running'],
        'mmdvm_bridge' => $tgifd['mmdvm_bridge'],
        'analog_bridge' => $tgifd['analog_bridge'],
        'pid' => $tgifd['pid'],
    ],

    'config' => [
        'path' => $config->path(),
        'exists' => $config->exists(),
        'mynode' => $myNode,
        'dvswitch_node' => $dvSwitchNode,
        'has_bm_password' => $config->has('BM_SelfcarePassword'),
        'has_tgif_key' => $config->has('TGIF_HotspotSecurityKey'),
        'dstar_enabled' => config_flag_enabled($config, 'DSTAR_ENABLED'),
        'p25_enabled' => config_flag_enabled($config, 'P25_ENABLED'),
        'nxdn_enabled' => config_flag_enabled($config, 'NXDN_ENABLED'),
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
            'active' => $bmActive,
            'receive_mode_active' => $bmReceive['active'],
            'receive_mode_target' => $bmReceive['target'],
            'keyed' => $bmKeyed,
        ],
        'tgif' => [
            'state' => $tgifState,
            'label' => $tgifState,
            'status' => $tgifState,
            'active' => $tgifActive,
            'keyed' => $tgifKeyed,
        ],
        'ysf' => [
            'state' => $ysfState,
            'label' => $ysfState,
            'status' => $ysfState,
            'active' => $ysfActive,
            'keyed' => $ysfKeyed,
        ],
        'dstar' => [
            'state' => $dstarState,
            'label' => $dstarState,
            'status' => $dstarState,
            'active' => $dstarActive,
            'keyed' => $dstarKeyed,
        ],
        'p25' => [
            'state' => $p25State,
            'label' => $p25State,
            'status' => $p25State,
            'active' => $p25Active,
            'keyed' => $p25Keyed,
        ],
        'nxdn' => [
            'state' => $nxdnState,
            'label' => $nxdnState,
            'status' => $nxdnState,
            'active' => $nxdnActive,
            'keyed' => $nxdnKeyed,
        ],
        'allstar' => [
            'state' => $allstarState,
            'label' => $allstarState,
            'status' => $allstarState,
            'connected_nodes_count' => count($allstarConnectedNodes),
            'connected_nodes' => $allstarConnectedNodes,
            'keyed' => $allstarKeyed,
        ],
    ],

    'brandmeister' => [
        'state' => $bmState,
        'label' => $bmState,
        'status' => $bmState,
        'active' => $bmActive,
        'receive_mode_active' => $bmReceive['active'],
        'receive_mode_target' => $bmReceive['target'],
        'keyed' => $bmKeyed,
    ],
    'tgif' => [
        'state' => $tgifState,
        'label' => $tgifState,
        'status' => $tgifState,
        'active' => $tgifActive,
        'keyed' => $tgifKeyed,
    ],
    'ysf' => [
        'state' => $ysfState,
        'label' => $ysfState,
        'status' => $ysfState,
        'active' => $ysfActive,
        'keyed' => $ysfKeyed,
    ],
    'dstar' => [
        'state' => $dstarState,
        'label' => $dstarState,
        'status' => $dstarState,
        'active' => $dstarActive,
        'keyed' => $dstarKeyed,
    ],
    'p25' => [
        'state' => $p25State,
        'label' => $p25State,
        'status' => $p25State,
        'active' => $p25Active,
        'keyed' => $p25Keyed,
    ],
    'nxdn' => [
        'state' => $nxdnState,
        'label' => $nxdnState,
        'status' => $nxdnState,
        'active' => $nxdnActive,
        'keyed' => $nxdnKeyed,
    ],

    'allstar' => [
        'state' => $allstarState,
        'label' => $allstarState,
        'status' => $allstarState,
        'connected_nodes_count' => count($allstarConnectedNodes),
        'connected_nodes' => $allstarConnectedNodes,
        'keyed' => $allstarKeyed,
        'local_nodes' => array_values(array_filter([
            $myNode,
            $dvSwitchNode,
        ])),
    ],

    'activity' => $activity,
];

respond($payload);
