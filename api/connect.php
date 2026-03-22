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
        'dmr_network' => (string) ($_SESSION['dmr_network'] ?? ''),
        'dmr_ready' => !empty($_SESSION['dmr_ready']),
    ], $extra);
}

$request = request_data();

$action = strtolower(trim((string) ($request['action'] ?? $request['action_type'] ?? '')));
$rawTarget = trim((string) ($request['target'] ?? $request['tgNum'] ?? ''));
$mode = normalize_mode((string) ($request['mode'] ?? ($_SESSION['selected_mode'] ?? 'BM')));
$autoloadPosted = array_key_exists('autoload_dvswitch', $request);

if ($autoloadPosted) {
    $_SESSION['autoload_dvswitch'] = !empty($request['autoload_dvswitch']);
} elseif (!isset($_SESSION['autoload_dvswitch'])) {
    $_SESSION['autoload_dvswitch'] = true;
}

$_SESSION['selected_mode'] = $mode;

$myNode = $config->getString('MYNODE', '');
$dvSwitchNode = $config->getString('DVSWITCH_NODE', '');
$bmPassword = $config->getString('BM_SelfcarePassword', '');
$tgifPassword = $config->getString('TGIF_HotspotSecurityKey', '');

if ($action === 'remember_autoload') {
    $status = (string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS');
    respond(session_payload($status));
}

if ($action === '') {
    respond(session_payload((string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS')));
}

if ($action !== 'connect' && $action !== 'disconnect') {
    respond(session_payload('ERROR: INVALID ACTION'), 400);
}

if ($myNode === '') {
    $_SESSION['last_status'] = 'ERROR: MYNODE MISSING IN CONFIG';
    respond(session_payload($_SESSION['last_status']), 500);
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
        if ($digitsOnlyTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID ALLSTAR NODE';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        if ($dvSwitchNode !== '') {
            asterisk_rpt_fun($myNode, '*1' . $dvSwitchNode);
            pause_seconds(0.5);
        }

        asterisk_rpt_fun($myNode, '*3' . $digitsOnlyTarget);

        $_SESSION['last_mode'] = 'ASL';
        $_SESSION['last_target'] = $digitsOnlyTarget;
        $_SESSION['pending_target'] = $digitsOnlyTarget;
        unset(
            $_SESSION['dmr_network'],
            $_SESSION['pending_tg'],
            $_SESSION['dmr_ready'],
            $_SESSION['dvswitch_autoloaded']
        );

        $_SESSION['last_status'] = 'CONNECTED: ALLSTAR NODE ' . $digitsOnlyTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if ($mode === 'YSF') {
        if ($dvSwitchNode === '') {
            $_SESSION['last_status'] = 'ERROR: DVSWITCH_NODE MISSING IN CONFIG';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        asterisk_rpt_fun($myNode, '*3' . $dvSwitchNode);
        pause_seconds(0.5);

        dvswitch_mode('YSF');
        pause_seconds(0.5);

        dvswitch_tune($rawTarget);

        $_SESSION['last_mode'] = 'YSF';
        $_SESSION['last_target'] = $rawTarget;
        $_SESSION['pending_target'] = $rawTarget;
        unset(
            $_SESSION['dmr_network'],
            $_SESSION['pending_tg'],
            $_SESSION['dmr_ready'],
            $_SESSION['dvswitch_autoloaded']
        );

        $_SESSION['last_status'] = 'CONNECTED: YSF TARGET ' . $rawTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if ($mode === 'BM' || $mode === 'TGIF') {
        if ($digitsOnlyTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID TG';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        $server = $mode === 'TGIF'
            ? 'tgif.network:62031'
            : '3103.master.brandmeister.network:62031';

        $key = $mode === 'TGIF' ? $tgifPassword : $bmPassword;

        if ($key === '') {
            $_SESSION['last_status'] = 'ERROR: MISSING ' . $mode . ' CONFIG KEY';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $currentNetwork = (string) ($_SESSION['dmr_network'] ?? '');
        $dmrReady = !empty($_SESSION['dmr_ready']);
        $autoload = !empty($_SESSION['autoload_dvswitch']);

        if ($currentNetwork !== $mode || !$dmrReady) {
            if ($autoload && $dvSwitchNode !== '') {
                asterisk_rpt_fun($myNode, '*3' . $dvSwitchNode);
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

            $_SESSION['last_status'] = 'WAITING: ' . $mode . ' READY - CLICK CONNECT AGAIN FOR TG ' . $digitsOnlyTarget;
            respond(session_payload($_SESSION['last_status']));
        }

        dvswitch_tune($digitsOnlyTarget);
        pause_seconds(1.0);

        $_SESSION['last_mode'] = $mode;
        $_SESSION['last_target'] = $digitsOnlyTarget;
        $_SESSION['pending_target'] = $digitsOnlyTarget;
        $_SESSION['pending_tg'] = $digitsOnlyTarget;
        $_SESSION['last_status'] = 'CONNECTED: TG ' . $digitsOnlyTarget . ' (' . $mode . ')';

        respond(session_payload($_SESSION['last_status']));
    }

    $_SESSION['last_status'] = 'ERROR: INVALID MODE';
    respond(session_payload($_SESSION['last_status']), 422);
}

$lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
$lastTarget = trim((string) ($_SESSION['last_target'] ?? ''));
$dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);

if ($lastMode === 'ASL') {
    if ($lastTarget !== '') {
        asterisk_rpt_fun($myNode, '*1' . $lastTarget);
    }
} elseif ($lastMode === 'YSF') {
    if ($dvSwitchNode !== '') {
        asterisk_rpt_fun($myNode, '*1' . $dvSwitchNode);
    }
    dvswitch_tune('disconnect');
} elseif ($lastMode === 'BM' || $lastMode === 'TGIF') {
    dvswitch_tune('disconnect');
    pause_seconds(1.0);

    if ($dvswitchAutoloaded && $dvSwitchNode !== '') {
        asterisk_rpt_fun($myNode, '*1' . $dvSwitchNode);
    }
} else {
    if ($dvSwitchNode !== '') {
        asterisk_rpt_fun($myNode, '*1' . $dvSwitchNode);
    } else {
        asterisk_rpt_fun($myNode, '*76');
    }
    dvswitch_tune('disconnect');
}

unset(
    $_SESSION['last_mode'],
    $_SESSION['last_target'],
    $_SESSION['pending_target'],
    $_SESSION['pending_tg'],
    $_SESSION['dmr_network'],
    $_SESSION['dmr_ready'],
    $_SESSION['dvswitch_autoloaded']
);

$_SESSION['last_status'] = 'DISCONNECTED';

respond(session_payload($_SESSION['last_status']));