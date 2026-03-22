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

function normalize_mode(?string $mode): string
{
    $value = strtoupper(trim((string) $mode));

    if ($value === 'ALLSTAR') {
        return 'ASL';
    }

    return $value;
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

$favorites = load_favorites_file(dirname(__DIR__) . '/data/favorites.txt');

$selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? 'BM'));
$lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
$lastTarget = trim((string) ($_SESSION['last_target'] ?? ''));
$pendingTarget = trim((string) ($_SESSION['pending_target'] ?? $_SESSION['pending_tg'] ?? ''));
$lastStatus = trim((string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS'));
$autoloadDvSwitch = !empty($_SESSION['autoload_dvswitch']);
$dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
$dmrReady = !empty($_SESSION['dmr_ready']);

$bmState = 'Idle';
$tgifState = 'Idle';
$ysfState = 'Idle';
$allstarState = 'No links';

if ($dmrNetwork === 'BM') {
    $bmState = $dmrReady ? 'Ready' : 'Preparing';
}

if ($dmrNetwork === 'TGIF') {
    $tgifState = $dmrReady ? 'Ready' : 'Preparing';
}

if ($lastMode === 'BM' && $lastTarget !== '') {
    $bmState = 'Connected: TG ' . $lastTarget;
}

if ($lastMode === 'TGIF' && $lastTarget !== '') {
    $tgifState = 'Connected: TG ' . $lastTarget;
}

if ($lastMode === 'YSF' && $lastTarget !== '') {
    $ysfState = 'Connected: ' . $lastTarget;
}

if ($lastMode === 'ASL' && $lastTarget !== '') {
    $allstarState = 'Connected: ' . $lastTarget;
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
        'dmr_network' => $dmrNetwork,
        'dmr_ready' => $dmrReady,
    ],

    'selected_mode' => $selectedMode,
    'last_mode' => $lastMode,
    'last_target' => $lastTarget,
    'pending_target' => $pendingTarget,
    'autoload_dvswitch' => $autoloadDvSwitch,
    'dmr_network' => $dmrNetwork,
    'dmr_ready' => $dmrReady,

    'config' => [
        'path' => $config->path(),
        'exists' => $config->exists(),
        'mynode' => $config->getString('MYNODE', ''),
        'dvswitch_node' => $config->getString('DVSWITCH_NODE', ''),
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
            'active' => $lastMode === 'BM' || $dmrNetwork === 'BM',
        ],
        'tgif' => [
            'state' => $tgifState,
            'label' => $tgifState,
            'status' => $tgifState,
            'active' => $lastMode === 'TGIF' || $dmrNetwork === 'TGIF',
        ],
        'ysf' => [
            'state' => $ysfState,
            'label' => $ysfState,
            'status' => $ysfState,
            'active' => $lastMode === 'YSF',
        ],
    ],

    'brandmeister' => [
        'state' => $bmState,
        'label' => $bmState,
        'status' => $bmState,
        'active' => $lastMode === 'BM' || $dmrNetwork === 'BM',
    ],
    'tgif' => [
        'state' => $tgifState,
        'label' => $tgifState,
        'status' => $tgifState,
        'active' => $lastMode === 'TGIF' || $dmrNetwork === 'TGIF',
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
        'connected_nodes_count' => ($lastMode === 'ASL' && $lastTarget !== '') ? 1 : 0,
        'connected_nodes' => ($lastMode === 'ASL' && $lastTarget !== '')
            ? [[
                'node' => $lastTarget,
                'label' => 'Connected Node',
            ]]
            : [],
        'local_nodes' => array_values(array_filter([
            $config->getString('MYNODE', ''),
            $config->getString('DVSWITCH_NODE', ''),
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
            'value' => $dmrNetwork !== ''
                ? $dmrNetwork . ($dmrReady ? ' (Ready)' : ' (Preparing)')
                : '-',
        ],
        [
            'label' => 'DVSwitch Auto-Load',
            'value' => $autoloadDvSwitch
                ? 'Enabled' . ($config->getString('DVSWITCH_NODE', '') !== '' ? ' (' . $config->getString('DVSWITCH_NODE', '') . ')' : '')
                : 'Disabled',
        ],
        [
            'label' => 'Current Status',
            'value' => $lastStatus,
        ],
    ],
];

respond($payload);