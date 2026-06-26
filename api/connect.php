<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\App\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/ApiAuthGuard.php';

use App\Support\ApiAuthGuard;
use App\Support\Config;

header('Content-Type: application/json');

$config = new Config(dirname(__DIR__) . '/config.ini');
ApiAuthGuard::requireLoginIfEnabled($config);

$GLOBALS['bm_receive_status_cache'] = null;
$GLOBALS['tgifd_status_cache'] = null;

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

function bm_receive_helper_path(): string
{
    return dirname(__DIR__) . '/bmtd/alltune2-bmtd-helper.sh';
}

function bm_receive_run(string $action, ?string $target = null): array
{
    $helperPath = bm_receive_helper_path();
    $command = 'sudo ' . escapeshellarg($helperPath) . ' ' . escapeshellarg($action);

    if ($target !== null && $target !== '') {
        $command .= ' ' . escapeshellarg($target);
    }

    $output = shell_run($command);
    $decoded = json_decode($output, true);

    if (is_array($decoded)) {
        $GLOBALS['bm_receive_status_cache'] = $decoded;
        return $decoded;
    }

    return [
        'ok' => false,
        'action' => $action,
        'message' => 'BM HELPER RETURNED INVALID JSON',
        'raw_output' => $output,
        'active' => false,
    ];
}

function bm_receive_status(bool $fresh = false): array
{
    if ($fresh) {
        $GLOBALS['bm_receive_status_cache'] = null;
    }

    $cached = $GLOBALS['bm_receive_status_cache'] ?? null;
    if (is_array($cached)) {
        return $cached;
    }
    return bm_receive_run('status');
}

function bm_receive_is_active(): bool
{
    $status = bm_receive_status();
    return !empty($status['active']);
}

function bm_receive_start(string $target): array
{
    return bm_receive_run('start', $target);
}

function bm_receive_tune(string $target): array
{
    return bm_receive_run('tune', $target);
}

function bm_receive_stop(): array
{
    return bm_receive_run('stop');
}


function tgifd_helper_path(): string
{
    return dirname(__DIR__) . '/tgif/alltune2-tgifd-helper.sh';
}

function tgifd_run(string $action, ?string $target = null): array
{
    $helperPath = tgifd_helper_path();
    $command = 'sudo ' . escapeshellarg($helperPath) . ' ' . escapeshellarg($action);

    if ($target !== null && $target !== '') {
        $command .= ' ' . escapeshellarg($target);
    }

    $output = shell_run($command);
    $decoded = json_decode($output, true);

    if (is_array($decoded)) {
        $GLOBALS['tgifd_status_cache'] = $decoded;
        return $decoded;
    }

    return [
        'ok' => false,
        'action' => $action,
        'message' => 'TGIFD HELPER RETURNED INVALID JSON',
        'raw_output' => $output,
        'active' => false,
    ];
}

function tgifd_status(bool $fresh = false): array
{
    if ($fresh) {
        $GLOBALS['tgifd_status_cache'] = null;
    }

    $cached = $GLOBALS['tgifd_status_cache'] ?? null;
    if (is_array($cached)) {
        return $cached;
    }
    return tgifd_run('status');
}

function tgifd_is_active(): bool
{
    $status = tgifd_status();
    return !empty($status['active']);
}

function tgifd_start(string $target): array
{
    return tgifd_run('start', $target);
}

function tgifd_tune(string $target): array
{
    return tgifd_run('tune', $target);
}

function tgifd_stop(): array
{
    return tgifd_run('stop');
}

function read_local_tg_from_analog_bridge(): string
{
    $path = '/opt/Analog_Bridge/Analog_Bridge.ini';
    if (!is_readable($path)) {
        return '';
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }

    $gateway = '';
    $txTg = '';

    foreach ($lines as $line) {
        $line = preg_replace('/;.*/', '', trim((string) $line)) ?? '';
        if ($line === '' || $line[0] === '[') {
            continue;
        }

        if (preg_match('/^gatewayDmrId\s*=\s*(\d+)$/i', $line, $m)) {
            $gateway = $m[1];
        }

        if (preg_match('/^txTg\s*=\s*(\d+)$/i', $line, $m)) {
            $txTg = $m[1];
        }
    }

    return $gateway !== '' ? $gateway : $txTg;
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

function enforce_dvswitch_link_mode(string $myNode, string $dvSwitchNode, string $autoloadMode): void
{
    if ($myNode === '' || $dvSwitchNode === '') {
        return;
    }

    $requestedMode = normalize_autoload_dvswitch_mode($autoloadMode);
    $previousMode = normalize_autoload_dvswitch_mode((string) ($_SESSION['dvswitch_active_mode'] ?? ''));

    /*
     * TGIFD is sensitive to unnecessary private-node reloads.
     * If Transceive is requested and we were not previously in Local Monitor,
     * leave the helper-created private-node link alone.
     *
     * Still reload when:
     * - Local Monitor is requested
     * - switching back from Local Monitor to Transceive
     */
    if ($requestedMode === 'transceive' && $previousMode !== 'local_monitor') {
        return;
    }

    asterisk_ilink_disconnect($myNode, $dvSwitchNode);
    pause_seconds(0.5);
    load_dvswitch_link($myNode, $dvSwitchNode, $requestedMode);
    pause_seconds(0.5);
}

function dvswitch_tune(string $value): string
{
    $command = '/opt/MMDVM_Bridge/dvswitch.sh tune ' . escapeshellarg($value);
    return shell_run($command);
}

function ensure_mmdvm_bridge_active_for_managed_digital(string $modeLabel): void
{
    $state = trim(shell_run('systemctl is-active mmdvm_bridge.service 2>/dev/null || true'));
    if ($state === 'active') {
        return;
    }

    shell_run('sudo /usr/bin/systemctl start mmdvm_bridge.service >/dev/null 2>&1');

    for ($i = 0; $i < 6; $i++) {
        pause_seconds(0.5);
        $state = trim(shell_run('systemctl is-active mmdvm_bridge.service 2>/dev/null || true'));
        if ($state === 'active') {
            return;
        }
    }

    $_SESSION['last_status'] = 'ERROR: MMDVM_BRIDGE NOT ACTIVE FOR ' . $modeLabel;
    respond(session_payload($_SESSION['last_status'], [
        'mmdvm_bridge' => $state !== '' ? $state : 'inactive',
    ]), 500);
}

function ensure_ysfgateway_active_for_ysf(): void
{
    $state = trim(shell_run('systemctl is-active ysfgateway.service 2>/dev/null || true'));
    if ($state === 'active') {
        return;
    }

    shell_run('sudo /usr/bin/systemctl start ysfgateway.service >/dev/null 2>&1');
    pause_seconds(1.0);

    $state = trim(shell_run('systemctl is-active ysfgateway.service 2>/dev/null || true'));
    if ($state !== 'active') {
        $_SESSION['last_status'] = 'ERROR: YSFGATEWAY NOT ACTIVE FOR YSF';
        respond(session_payload($_SESSION['last_status'], [
            'ysfgateway' => $state !== '' ? $state : 'inactive',
        ]), 500);
    }
}

function stop_ysfgateway_after_ysf_disconnect(): string
{
    $state = trim(shell_run('systemctl is-active ysfgateway.service 2>/dev/null || true'));
    if ($state !== 'active') {
        return '';
    }

    $output = shell_run('sudo /usr/bin/systemctl stop ysfgateway.service 2>&1 || true');
    pause_seconds(1.0);

    return trim($output);
}

function managed_digital_mode_label(string $mode): string
{
    return match (normalize_mode($mode)) {
        'DSTAR' => 'D-STAR',
        'P25' => 'P25',
        'NXDN' => 'NXDN',
        default => strtoupper(trim($mode)),
    };
}

function is_managed_digital_mode(string $mode): bool
{
    return in_array(normalize_mode($mode), ['DSTAR', 'P25', 'NXDN'], true);
}

function gateway_udp_command(string $label, int $port, string $payload): string
{
    $socket = @stream_socket_client(
        'udp://127.0.0.1:' . $port,
        $errno,
        $errstr,
        1.0,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return sprintf(
            '%s gateway cleanup failed on UDP port %d: %s',
            $label,
            $port,
            $errstr !== '' ? $errstr : ('error ' . (string) $errno)
        );
    }

    fwrite($socket, $payload);
    fclose($socket);

    return sprintf('%s gateway cleanup sent: %s to UDP port %d', $label, $payload, $port);
}

function cleanup_all_dvswitch_gateway_links(): string
{
    $messages = [];

    $messages[] = gateway_udp_command('YSF', 6073, 'disconnect');
    $messages[] = gateway_udp_command('P25', 6074, 'TalkGroup0');
    $messages[] = gateway_udp_command('NXDN', 6075, 'TalkGroup0');

    $messages[] = dvswitch_mode('DSTAR');
    pause_seconds(0.5);
    $messages[] = dvswitch_tune('       U');
    pause_seconds(0.5);
    $messages[] = dvswitch_mode('DMR');
    pause_seconds(0.5);
    $messages[] = dvswitch_tune('0');

    return trim(implode(PHP_EOL, array_filter($messages)));
}


function cleanup_previous_managed_gateway_link(string $mode): string
{
    $normalized = normalize_mode($mode);

    if ($normalized === 'YSF') {
        $messages = [];
        $messages[] = gateway_udp_command('YSF', 6073, 'disconnect');
        $messages[] = stop_ysfgateway_after_ysf_disconnect();

        return trim(implode(PHP_EOL, array_filter($messages)));
    }

    if ($normalized === 'P25') {
        return gateway_udp_command('P25', 6074, 'TalkGroup0');
    }

    if ($normalized === 'NXDN') {
        return gateway_udp_command('NXDN', 6075, 'TalkGroup0');
    }

    if ($normalized === 'DSTAR') {
        $messages = [];
        $messages[] = dvswitch_mode('DSTAR');
        pause_seconds(0.5);
        $messages[] = dvswitch_tune('       U');
        pause_seconds(0.5);
        return trim(implode(PHP_EOL, array_filter($messages)));
    }

    return '';
}

function reset_mmdvm_runtime_to_idle_dmr(): string
{
    $messages = [];
    $messages[] = dvswitch_mode('DMR');
    pause_seconds(0.5);
    $messages[] = dvswitch_tune('0');

    return trim(implode(PHP_EOL, array_filter($messages)));
}

function dvswitch_disconnect_mode(string $mode): string
{
    $normalized = normalize_mode($mode);

    if (in_array($normalized, ['YSF', 'P25', 'NXDN'], true)) {
        $messages = [];
        $messages[] = cleanup_previous_managed_gateway_link($normalized);
        $messages[] = reset_mmdvm_runtime_to_idle_dmr();

        return trim(implode(PHP_EOL, array_filter($messages)));
    }

    if ($normalized === 'DSTAR') {
        return trim(cleanup_previous_managed_gateway_link($normalized));
    }

    $output = cleanup_all_dvswitch_gateway_links();

    if ($output !== '') {
        pause_seconds(0.5);
    }

    $output .= PHP_EOL . dvswitch_tune('disconnect');
    return trim($output);
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

    if (in_array($mode, ['ALLSTAR', 'ALLSTAR LINK', 'ALLSTARLINK'], true)) {
        return 'ASL';
    }

    if (in_array($mode, ['ECHO', 'ECHO LINK', 'ECHOLINK', 'EL', 'E/L'], true)) {
        return 'ECHO';
    }

    if (in_array($mode, ['D-STAR', 'D STAR', 'DSTAR'], true)) {
        return 'DSTAR';
    }

    if (in_array($mode, ['P-25', 'P 25', 'P25'], true)) {
        return 'P25';
    }

    if (in_array($mode, ['N-XDN', 'N XDN', 'NXDN'], true)) {
        return 'NXDN';
    }

    return $mode;
}

function normalize_direct_ui_mode(string $mode): string
{
    return normalize_mode($mode) === 'ECHO' ? 'ECHO' : 'ASL';
}

function direct_node_status_label(string $mode): string
{
    return normalize_direct_ui_mode($mode) === 'ECHO'
        ? 'ECHOLINK NODE'
        : 'ALLSTAR NODE';
}

function normalize_autoload_dvswitch_mode(mixed $mode): string
{
    $value = strtolower(trim((string) $mode));
    return $value === 'local_monitor' ? 'local_monitor' : 'transceive';
}

function normalize_bm_target(string $target): string
{
    $value = trim($target);

    if ($value === '') {
        return '';
    }

    if (substr_count($value, '#') > 1) {
        return '';
    }

    if (str_contains($value, '#')) {
        if (!preg_match('/^\d+#$/', $value)) {
            return '';
        }

        return $value;
    }

    if (!preg_match('/^\d+$/', $value)) {
        return '';
    }

    return $value;
}

function normalize_dtmf_code(string $value): string
{
    $value = preg_replace('/\s+/', '', trim($value)) ?? '';
    return trim((string) $value);
}

function validate_dtmf_code(string $value): string
{
    $normalized = normalize_dtmf_code($value);

    if ($normalized === '') {
        return '';
    }

    if (strlen($normalized) > 14) {
        return '';
    }

    if (!preg_match('/^[0-9*#]+$/', $normalized)) {
        return '';
    }

    return $normalized;
}

function dtmf_command_failed(string $output): bool
{
    $normalized = strtoupper(trim($output));

    if ($normalized === '') {
        return false;
    }

    return str_contains($normalized, 'NO SUCH COMMAND')
        || str_contains($normalized, 'INVALID')
        || str_contains($normalized, 'ERROR')
        || str_contains($normalized, 'FAILED')
        || str_contains($normalized, 'USAGE:');
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

function config_flag_enabled(Config $config, string $key): bool
{
    $value = strtolower(trim($config->getString($key, '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function dvswitch_script_available(): bool
{
    return is_file('/opt/MMDVM_Bridge/dvswitch.sh');
}

function normalize_dstar_target(string $target): string
{
    $value = strtoupper(trim($target));

    if ($value === '') {
        return '';
    }

    if (!preg_match('/^[A-Z0-9]{4,16}$/', $value)) {
        return '';
    }

    return $value;
}

function normalize_managed_digital_target(string $mode, string $target): string
{
    $normalizedMode = normalize_mode($mode);

    if ($normalizedMode === 'DSTAR') {
        return normalize_dstar_target($target);
    }

    $value = strtoupper(trim($target));

    if ($value === '') {
        return '';
    }

    if (!preg_match('/^[A-Z0-9._:-]{1,64}$/', $value)) {
        return '';
    }

    return $value;
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

    $node = trim($node);
    if ($node === '') {
        return 'ASL';
    }

    $stored = $_SESSION['allstar_link_ui_modes'][$node] ?? '';
    if (is_string($stored) && $stored !== '') {
        return normalize_direct_ui_mode($stored);
    }

    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $lastTarget = trim((string) ($_SESSION['last_target'] ?? ''));

    if ($lastTarget === $node && in_array($lastMode, ['ASL', 'ECHO'], true)) {
        return normalize_direct_ui_mode($lastMode);
    }

    return 'ASL';
}

function untrack_allstar_link(string $node): void
{
    ensure_allstar_tracking_structures();

    unset($_SESSION['allstar_link_modes'][$node]);
    unset($_SESSION['allstar_link_ui_modes'][$node]);

    $_SESSION['allstar_link_order'] = array_values(array_filter(
        $_SESSION['allstar_link_order'],
        static fn ($value) => trim((string) $value) !== '' && trim((string) $value) !== $node
    ));
}

function clear_allstar_tracking(): void
{
    $_SESSION['allstar_link_modes'] = [];
    $_SESSION['allstar_link_order'] = [];
    $_SESSION['allstar_link_ui_modes'] = [];
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

function last_tracked_allstar_node(?string $excludedNode = null): string
{
    sanitize_allstar_tracking($excludedNode);

    $order = $_SESSION['allstar_link_order'];
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
    $remainingTracked = last_tracked_allstar_node($excludedNode);

    if ($remainingTracked !== '') {
        $_SESSION['last_mode'] = last_tracked_allstar_ui_mode($excludedNode);
        $_SESSION['last_target'] = $remainingTracked;
        $_SESSION['pending_target'] = $remainingTracked;
        return;
    }

    unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
}

function disconnect_selected_dvswitch_link(string $myNode, string $dvSwitchNode): void
{
    if ($dvSwitchNode === '') {
        return;
    }

    if (bm_receive_is_active()) {
        bm_receive_stop();
        pause_seconds(0.5);

        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg']
        );

        clear_dmr_active_state();

        clear_managed_dvswitch_state();
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
        return;
    }

    if (tgifd_is_active()) {
        tgifd_stop();
        pause_seconds(0.5);

        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);

        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg']
        );

        clear_dmr_active_state();

        clear_managed_dvswitch_state();
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
        return;
    }

    $lastMode = active_managed_dvswitch_mode();
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));

    $hasDmrRuntime = $dmrNetwork === 'BM' || $dmrNetwork === 'TGIF';
    $hasYsfRuntime = in_array($lastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true);

    if ($hasDmrRuntime || $hasYsfRuntime) {
        dvswitch_disconnect_mode($lastMode);
        pause_seconds(1.0);
    }

    asterisk_ilink_disconnect($myNode, $dvSwitchNode);
    pause_seconds(0.5);

    unset(
        $_SESSION['dvswitch_autoloaded'],
        $_SESSION['dvswitch_active_mode'],
        $_SESSION['dmr_network'],
        $_SESSION['dmr_ready'],
        $_SESSION['pending_tg']
    );

    clear_dmr_active_state();

    clear_managed_dvswitch_state();

    if ($hasYsfRuntime || $hasDmrRuntime) {
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
    }
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

function is_managed_dvswitch_session_mode(string $mode): bool
{
    return in_array(normalize_mode($mode), ['BM', 'TGIF', 'YSF', 'DSTAR', 'P25', 'NXDN'], true);
}

function set_managed_dvswitch_state(string $mode, string $target): void
{
    $_SESSION['managed_dvswitch_mode'] = normalize_mode($mode);
    $_SESSION['managed_dvswitch_target'] = trim($target);
}

function clear_managed_dvswitch_state(): void
{
    unset(
        $_SESSION['managed_dvswitch_mode'],
        $_SESSION['managed_dvswitch_target']
    );
}

function active_managed_dvswitch_mode(): string
{
    $managedMode = normalize_mode((string) ($_SESSION['managed_dvswitch_mode'] ?? ''));
    if (is_managed_dvswitch_session_mode($managedMode)) {
        return $managedMode;
    }

    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    return is_managed_dvswitch_session_mode($lastMode) ? $lastMode : '';
}

function active_managed_dvswitch_target(): string
{
    $managedTarget = trim((string) ($_SESSION['managed_dvswitch_target'] ?? ''));
    if ($managedTarget !== '') {
        return $managedTarget;
    }

    return trim((string) ($_SESSION['last_target'] ?? ''));
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
        $_SESSION['dvswitch_autoloaded'],
        $_SESSION['dvswitch_active_mode'],
        $_SESSION['managed_dvswitch_mode'],
        $_SESSION['managed_dvswitch_target']
    );

    clear_dmr_active_state();
}

function disconnect_dvswitch_runtime(string $myNode, string $dvSwitchNode): void
{
    if (bm_receive_is_active()) {
        bm_receive_stop();
        pause_seconds(0.5);
        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg'],
            $_SESSION['last_mode'],
            $_SESSION['last_target'],
            $_SESSION['pending_target']
        );
        clear_dmr_active_state();
        clear_managed_dvswitch_state();

        return;
    }

    if (tgifd_is_active()) {
        tgifd_stop();
        pause_seconds(0.5);

        if ($dvSwitchNode !== '') {
            asterisk_ilink_disconnect($myNode, $dvSwitchNode);
            pause_seconds(0.5);
        }

        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg'],
            $_SESSION['last_mode'],
            $_SESSION['last_target'],
            $_SESSION['pending_target']
        );
        clear_dmr_active_state();
        clear_managed_dvswitch_state();

        return;
    }

    $lastMode = active_managed_dvswitch_mode();
    $dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));

    $hasDmrRuntime = $dmrNetwork === 'BM' || $dmrNetwork === 'TGIF';
    $hasYsfRuntime = in_array($lastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true);
    $shouldDisconnectDvSwitchNode = $dvSwitchNode !== '' && (
        $dvswitchAutoloaded ||
        $hasDmrRuntime ||
        $hasYsfRuntime
    );

    if ($hasDmrRuntime || $hasYsfRuntime) {
        dvswitch_disconnect_mode($lastMode);
        pause_seconds(1.0);
    }

    if ($shouldDisconnectDvSwitchNode) {
        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);
    }

    unset(
        $_SESSION['dvswitch_autoloaded'],
        $_SESSION['dvswitch_active_mode'],
        $_SESSION['dmr_network'],
        $_SESSION['dmr_ready'],
        $_SESSION['pending_tg'],
        $_SESSION['last_mode'],
        $_SESSION['last_target'],
        $_SESSION['pending_target']
    );
    clear_dmr_active_state();
    clear_managed_dvswitch_state();
}

function disconnect_only_dvswitch_link(string $myNode, string $dvSwitchNode): void
{
    if ($dvSwitchNode === '') {
        return;
    }

    if (bm_receive_is_active()) {
        bm_receive_stop();
        pause_seconds(0.5);

        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg']
        );

        clear_dmr_active_state();

        clear_managed_dvswitch_state();
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
        return;
    }

    if (tgifd_is_active()) {
        tgifd_stop();
        pause_seconds(0.5);

        if ($dvSwitchNode !== '') {
            asterisk_ilink_disconnect($myNode, $dvSwitchNode);
            pause_seconds(0.5);
        }

        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg']
        );

        clear_dmr_active_state();

        clear_managed_dvswitch_state();
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
        return;
    }

    $lastMode = active_managed_dvswitch_mode();
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
    $dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);

    $hasDmrRuntime = $dmrNetwork === 'BM' || $dmrNetwork === 'TGIF';
    $hasYsfRuntime = in_array($lastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true);

    if ($hasDmrRuntime || $hasYsfRuntime) {
        dvswitch_disconnect_mode($lastMode);
        pause_seconds(1.0);
    }

    /*
     * Disconnect DVSwitch means disconnect the configured private node itself.
     * That must work even when the private node was loaded as a plain AllStar
     * link and no managed BM/TGIF/YSF/D-Star/P25/NXDN session flags are active.
     */
    asterisk_ilink_disconnect($myNode, $dvSwitchNode);
    pause_seconds(0.5);
    untrack_allstar_link($dvSwitchNode);

    unset(
        $_SESSION['dvswitch_autoloaded'],
        $_SESSION['dvswitch_active_mode'],
        $_SESSION['dmr_network'],
        $_SESSION['dmr_ready'],
        $_SESSION['pending_tg']
    );

    clear_dmr_active_state();

    clear_managed_dvswitch_state();

    if ($hasYsfRuntime || $hasDmrRuntime) {
        unset($_SESSION['last_mode'], $_SESSION['last_target'], $_SESSION['pending_target']);
    }
}

function disconnect_all_managed_links(string $myNode, string $dvSwitchNode): void
{
    ensure_allstar_tracking_structures();

    if (bm_receive_is_active()) {
        bm_receive_stop();
        pause_seconds(0.5);
    }

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


function session_may_have_bm_runtime(): bool
{
    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? ''));
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
    $dmrActiveNetwork = normalize_mode((string) ($_SESSION['dmr_active_network'] ?? ''));
    $lastStatus = strtoupper(trim((string) ($_SESSION['last_status'] ?? '')));

    return $lastMode === 'BM'
        || $selectedMode === 'BM'
        || $dmrNetwork === 'BM'
        || $dmrActiveNetwork === 'BM'
        || str_contains($lastStatus, '(BM)')
        || str_contains($lastStatus, ' BM');
}

function session_may_have_tgif_runtime(): bool
{
    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? ''));
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
    $dmrActiveNetwork = normalize_mode((string) ($_SESSION['dmr_active_network'] ?? ''));
    $lastStatus = strtoupper(trim((string) ($_SESSION['last_status'] ?? '')));

    return $lastMode === 'TGIF'
        || $selectedMode === 'TGIF'
        || $dmrNetwork === 'TGIF'
        || $dmrActiveNetwork === 'TGIF'
        || str_contains($lastStatus, '(TGIF)')
        || str_contains($lastStatus, ' TGIF');
}

function session_forces_private_node(): bool
{
    $selectedMode = normalize_mode((string) ($_SESSION['selected_mode'] ?? ''));
    $lastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
    $dmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
    $dmrActiveNetwork = normalize_mode((string) ($_SESSION['dmr_active_network'] ?? ''));

    return in_array($selectedMode, ['BM', 'TGIF', 'YSF', 'DSTAR', 'P25', 'NXDN'], true)
        || in_array($lastMode, ['BM', 'TGIF', 'YSF', 'DSTAR', 'P25', 'NXDN'], true)
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

    foreach (allstar_tracked_nodes_in_order() as $node) {
        $node = trim((string) $node);
        if ($node === '' || ($dvSwitchNode !== '' && $node === $dvSwitchNode) || isset($seen[$node])) {
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
        $mode = (string) ($_SESSION['dvswitch_active_mode'] ?? $_SESSION['autoload_dvswitch_mode'] ?? 'transceive');
        $mode = normalize_autoload_dvswitch_mode($mode);
        $links[] = [
            'node' => $dvSwitchNode,
            'label' => 'Connected Node',
            'link_mode' => $mode,
            'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
            'ui_mode' => 'ASL',
            'is_live' => false,
        ];
    }

    return [
        'state' => count($links) > 0 ? 'Connected: ' . count($links) : 'No links',
        'label' => count($links) > 0 ? 'Connected: ' . count($links) : 'No links',
        'status' => count($links) > 0 ? 'Connected: ' . count($links) : 'No links',
        'connected_nodes_count' => count($links),
        'connected_nodes' => $links,
        'local_nodes' => array_values(array_filter([$dvSwitchNode])),
    ];
}

function session_payload(string $statusText, array $extra = []): array
{
    $bmActive = session_may_have_bm_runtime() ? bm_receive_is_active() : false;
    $tgifActive = session_may_have_tgif_runtime() ? tgifd_is_active() : false;
    $forcedAutoload = session_forces_private_node();

    return array_merge([
        'ok' => !str_starts_with($statusText, 'ERROR:'),
        'status' => $statusText,
        'status_text' => $statusText,
        'last_status' => $statusText,
        'selected_mode' => (string) ($_SESSION['selected_mode'] ?? 'BM'),
        'last_mode' => (string) ($_SESSION['last_mode'] ?? ''),
        'last_target' => (string) ($_SESSION['last_target'] ?? ''),
        'managed_dvswitch_mode' => active_managed_dvswitch_mode(),
        'managed_dvswitch_target' => active_managed_dvswitch_target(),
        'pending_target' => (string) ($_SESSION['pending_target'] ?? ''),
        'pending_tg' => (string) ($_SESSION['pending_tg'] ?? ''),
        'autoload_dvswitch' => $forcedAutoload || !empty($_SESSION['autoload_dvswitch']),
        'autoload_dvswitch_mode' => (string) ($_SESSION['autoload_dvswitch_mode'] ?? 'transceive'),
        'disconnect_before_connect' => !empty($_SESSION['disconnect_before_connect']),
        'dmr_network' => (string) ($_SESSION['dmr_network'] ?? ''),
        'dmr_ready' => !empty($_SESSION['dmr_ready']),
        'dmr_active_network' => (string) ($_SESSION['dmr_active_network'] ?? ''),
        'dmr_active_target' => (string) ($_SESSION['dmr_active_target'] ?? ''),
        'dvswitch_active_mode' => (string) ($_SESSION['dvswitch_active_mode'] ?? ''),
        'bm_receive_active' => $bmActive,
        'tgifd_active' => $tgifActive,
        'dvswitch_link_active' => !empty($_SESSION['dvswitch_autoloaded']) || !empty($_SESSION['dmr_ready']) || active_managed_dvswitch_mode() !== '' || $bmActive || $tgifActive,
    ], $extra);
}

$request = request_data();

$action = strtolower(trim((string) ($request['action'] ?? $request['action_type'] ?? '')));
$rawTarget = trim((string) ($request['target'] ?? $request['tgNum'] ?? ''));
$rawDtmfCode = trim((string) ($request['dtmf_code'] ?? $request['dtmf'] ?? $request['digits'] ?? $request['code'] ?? ''));
$selectedNode = preg_replace('/[^0-9]/', '', (string) ($request['selected_node'] ?? '')) ?? '';
$mode = normalize_mode((string) ($request['mode'] ?? ($_SESSION['selected_mode'] ?? 'BM')));
$uiMode = normalize_mode((string) ($request['ui_mode'] ?? $mode));
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

if ($mode === 'ASL') {
    $_SESSION['selected_mode'] = normalize_direct_ui_mode($uiMode);
} else {
    $_SESSION['selected_mode'] = $mode;
}

if (in_array($mode, ['BM', 'TGIF', 'YSF', 'DSTAR', 'P25', 'NXDN'], true)) {
    $_SESSION['autoload_dvswitch'] = true;
}

$myNode = $config->getString('MYNODE', '');
$dvSwitchNode = $config->getString('DVSWITCH_NODE', '');
$bmPassword = $config->getString('BM_SelfcarePassword', '');
$tgifPassword = $config->getString('TGIF_HotspotSecurityKey', '');
$dstarEnabled = config_flag_enabled($config, 'DSTAR_ENABLED');
$p25Enabled = config_flag_enabled($config, 'P25_ENABLED');
$nxdnEnabled = config_flag_enabled($config, 'NXDN_ENABLED');
$autoloadDvSwitchMode = normalize_autoload_dvswitch_mode($_SESSION['autoload_dvswitch_mode'] ?? 'transceive');
$disconnectBeforeConnect = !empty($_SESSION['disconnect_before_connect']);

$hasRealMyNode = has_real_config_value($myNode);
$hasRealDvSwitchNode = has_real_config_value($dvSwitchNode);
$hasRealBmPassword = has_real_config_value($bmPassword);
$hasRealTgifPassword = has_real_config_value($tgifPassword);
$hasDstarConfigured = $hasRealMyNode && $hasRealDvSwitchNode && $dstarEnabled && dvswitch_script_available();
$hasP25Configured = $hasRealMyNode && $hasRealDvSwitchNode && $p25Enabled && dvswitch_script_available();
$hasNxdnConfigured = $hasRealMyNode && $hasRealDvSwitchNode && $nxdnEnabled && dvswitch_script_available();

$managedDigitalConfigured = [
    'DSTAR' => $hasDstarConfigured,
    'P25' => $hasP25Configured,
    'NXDN' => $hasNxdnConfigured,
];

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
    $action !== 'disconnect_dvswitch' &&
    $action !== 'private_node_lost_cleanup' &&
    $action !== 'send_dtmf'
) {
    respond(session_payload('ERROR: INVALID ACTION'), 400);
}


/*
 * BMTD early disconnect safety:
 * Stop active BMTD before relying on PHP session state.
 */
if ($action === 'disconnect') {
    $bmStatus = bm_receive_status(true);

    if (!empty($bmStatus['active'])) {
        $bmStop = bm_receive_stop();
        pause_seconds(0.5);

        if (empty($bmStop['ok'])) {
            $_SESSION['last_status'] = 'ERROR: FAILED TO STOP BM RECEIVE';
            respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmStop]), 500);
        }

        clear_runtime_targets();
        clear_allstar_tracking();
        $_SESSION['last_status'] = 'DISCONNECTED: BM';
        respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmStop]));
    }
}

/*
 * TGIFD early disconnect safety:
 * Stop active TGIFD before relying on PHP session state.
 */
if ($action === 'disconnect') {
    $tgifStatus = tgifd_status(true);

    if (!empty($tgifStatus['active'])) {
        $tgifStop = tgifd_stop();
        pause_seconds(0.5);

        if (empty($tgifStop['ok'])) {
            $_SESSION['last_status'] = 'ERROR: FAILED TO STOP TGIFD';
            respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifStop]), 500);
        }

        clear_runtime_targets();
        clear_allstar_tracking();
        $_SESSION['last_status'] = 'DISCONNECTED: TGIF';
        respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifStop]));
    }
}

if ($action === 'send_dtmf') {
    $baseStatus = (string) ($_SESSION['last_status'] ?? 'IDLE - NO CONNECTIONS');

    if (!$hasRealMyNode) {
        respond(session_payload($baseStatus, [
            'ok' => false,
            'status' => 'ERROR: MYNODE NOT CONFIGURED',
            'status_text' => 'ERROR: MYNODE NOT CONFIGURED',
            'dtmf_code' => '',
        ]), 500);
    }

    $dtmfCode = validate_dtmf_code($rawDtmfCode);

    if ($dtmfCode === '') {
        respond(session_payload($baseStatus, [
            'ok' => false,
            'status' => 'ERROR: INVALID DTMF CODE',
            'status_text' => 'ERROR: INVALID DTMF CODE',
            'dtmf_code' => normalize_dtmf_code($rawDtmfCode),
        ]), 422);
    }

    $dtmfResult = asterisk_rpt_fun($myNode, $dtmfCode);

    if (dtmf_command_failed($dtmfResult)) {
        respond(session_payload($baseStatus, [
            'ok' => false,
            'status' => 'ERROR: DTMF SEND FAILED',
            'status_text' => 'ERROR: DTMF SEND FAILED',
            'dtmf_code' => $dtmfCode,
            'dtmf_result' => $dtmfResult,
        ]), 500);
    }

    respond(session_payload($baseStatus, [
        'ok' => true,
        'status' => 'DTMF SENT: ' . $dtmfCode,
        'status_text' => 'DTMF SENT: ' . $dtmfCode,
        'dtmf_code' => $dtmfCode,
        'dtmf_result' => $dtmfResult,
    ]));
}

if ($action === 'disconnect_all') {
    if (bm_receive_is_active()) {
        bm_receive_stop();
        pause_seconds(0.5);
    }

    if (tgifd_is_active()) {
        tgifd_stop();
        pause_seconds(0.5);
    }

    shell_run('sudo /usr/bin/systemctl restart asterisk.service');
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

if ($action === 'private_node_lost_cleanup') {
    if ($hasRealMyNode && $hasRealDvSwitchNode) {
        disconnect_only_dvswitch_link($myNode, $dvSwitchNode);
    } else {
        clear_runtime_targets();
        clear_dmr_session_state();
        clear_dmr_active_state();
        clear_managed_dvswitch_state();
        unset(
            $_SESSION['dvswitch_autoloaded'],
            $_SESSION['dvswitch_active_mode'],
            $_SESSION['dmr_network'],
            $_SESSION['dmr_ready'],
            $_SESSION['pending_tg'],
            $_SESSION['last_mode'],
            $_SESSION['last_target'],
            $_SESSION['pending_target']
        );
    }

    $_SESSION['last_status'] = 'IDLE - NO CONNECTIONS';
    respond(session_payload($_SESSION['last_status'], [
        'private_node_loss_cleaned' => true,
    ]));
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

    if ($hasRealDvSwitchNode && $selectedNode === $dvSwitchNode) {
        untrack_allstar_link($selectedNode);
        disconnect_selected_dvswitch_link($myNode, $dvSwitchNode);
        sync_last_direct_target_from_tracking($dvSwitchNode);

        $_SESSION['last_status'] = 'DISCONNECTED: DVSWITCH LINK ' . $dvSwitchNode;
        respond(session_payload($_SESSION['last_status']));
    }

    $trackedModes = $_SESSION['allstar_link_modes'];
    $trackedOrder = $_SESSION['allstar_link_order'];

    $isTrackedDirectNode =
        array_key_exists($selectedNode, $trackedModes) ||
        in_array($selectedNode, $trackedOrder, true);

    if (!$isTrackedDirectNode) {
        $_SESSION['last_status'] = 'ERROR: ALLSTAR NODE NOT TRACKED';
        respond(session_payload($_SESSION['last_status']), 404);
    }

    $selectedUiMode = tracked_allstar_ui_mode($selectedNode);

    asterisk_ilink_disconnect($myNode, $selectedNode);
    pause_seconds(0.5);
    untrack_allstar_link($selectedNode);
    sync_last_direct_target_from_tracking($dvSwitchNode);

    $_SESSION['last_status'] = 'DISCONNECTED: ' . direct_node_status_label($selectedUiMode) . ' ' . $selectedNode;
    respond(session_payload($_SESSION['last_status']));
}

if ($action === 'connect') {
    $digitsOnlyTarget = preg_replace('/[^0-9]/', '', $rawTarget) ?? '';
    $bmTarget = $mode === 'BM' ? normalize_bm_target($rawTarget) : '';
    $directUiMode = $mode === 'ASL' || $mode === 'ECHO'
        ? normalize_direct_ui_mode($uiMode === 'BM' || $uiMode === 'TGIF' || $uiMode === 'YSF' ? $mode : $uiMode)
        : $mode;

    if ($mode === 'ASL' || $mode === 'ECHO') {
        $_SESSION['selected_mode'] = $directUiMode;
    } else {
        $_SESSION['selected_mode'] = $mode;
    }

    if (is_dmr_mode($mode)) {
        $previousModeForTargetFallback = normalize_mode((string) ($_SESSION['last_mode'] ?? $_SESSION['selected_mode'] ?? ''));
        $pendingTarget = (string) ($_SESSION['pending_tg'] ?? '');

        if ($pendingTarget === '' && in_array($previousModeForTargetFallback, ['BM', 'TGIF'], true)) {
            $pendingTarget = (string) ($_SESSION['pending_target'] ?? '');
        }

        $effectiveTarget = $mode === 'BM' ? $bmTarget : $digitsOnlyTarget;

        if ($effectiveTarget === '' && $pendingTarget !== '') {
            $rawTarget = $pendingTarget;
            $digitsOnlyTarget = preg_replace('/[^0-9]/', '', $pendingTarget) ?? '';
            $bmTarget = $mode === 'BM' ? normalize_bm_target($pendingTarget) : '';
        }
    }

    if ($rawTarget === '' && $digitsOnlyTarget === '' && $bmTarget === '') {
        $_SESSION['last_status'] = 'ERROR: INVALID TG / NODE / YSF / DIGITAL TARGET';
        respond(session_payload($_SESSION['last_status']), 422);
    }

    if ($mode === 'ASL' || $mode === 'ECHO') {
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
        track_allstar_link($digitsOnlyTarget, $autoloadDvSwitchMode, $directUiMode);

        $_SESSION['last_mode'] = $directUiMode;
        $_SESSION['last_target'] = $digitsOnlyTarget;
        $_SESSION['pending_target'] = $digitsOnlyTarget;

        $_SESSION['last_status'] = 'CONNECTED: ' . direct_node_status_label($directUiMode) . ' ' . $digitsOnlyTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if ($mode === 'YSF') {
        if (!$hasRealMyNode || !$hasRealDvSwitchNode) {
            $_SESSION['last_status'] = 'ERROR: YSF NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $keepDvSwitchLinkLoaded = false;

        if ($disconnectBeforeConnect) {
            disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
        } elseif (tgifd_is_active()) {
            $tgifStop = tgifd_stop();
            pause_seconds(0.5);

            if (empty($tgifStop['ok'])) {
                $_SESSION['last_status'] = 'ERROR: FAILED TO STOP TGIFD';
                respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifStop]), 500);
            }

            if ($hasRealDvSwitchNode) {
                asterisk_ilink_disconnect($myNode, $dvSwitchNode);
                pause_seconds(0.5);
            }

            clear_runtime_targets();
        } elseif (bm_receive_is_active()) {
            $bmStop = bm_receive_stop();
            pause_seconds(0.5);

            if (empty($bmStop['ok'])) {
                $_SESSION['last_status'] = 'ERROR: FAILED TO STOP BM RECEIVE';
                respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmStop]), 500);
            }

            clear_runtime_targets();
        } else {
            $currentLastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));

            if (in_array($currentLastMode, ['P25', 'NXDN', 'DSTAR'], true)) {
                $cleanupOutput = cleanup_previous_managed_gateway_link($currentLastMode);
                if ($cleanupOutput !== '') {
                    pause_seconds(0.3);
                }
                $keepDvSwitchLinkLoaded = true;
            } elseif ($currentLastMode === 'YSF') {
                $keepDvSwitchLinkLoaded = true;
            }
        }

        if (!$keepDvSwitchLinkLoaded) {
            load_dvswitch_link($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
            $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;
            pause_seconds(0.5);
        } else {
            enforce_dvswitch_link_mode($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
            $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;
        }

        ensure_mmdvm_bridge_active_for_managed_digital('YSF');
        ensure_ysfgateway_active_for_ysf();

        dvswitch_mode('YSF');
        pause_seconds(0.5);

        dvswitch_tune($rawTarget);

        $_SESSION['last_mode'] = 'YSF';
        $_SESSION['last_target'] = $rawTarget;
        $_SESSION['pending_target'] = $rawTarget;
        set_managed_dvswitch_state('YSF', $rawTarget);
        clear_dmr_session_state();
        clear_dmr_active_state();
        $_SESSION['dvswitch_autoloaded'] = true;

        $_SESSION['last_status'] = 'CONNECTED: YSF TARGET ' . $rawTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if (is_managed_digital_mode($mode)) {
        $modeLabel = managed_digital_mode_label($mode);

        if (empty($managedDigitalConfigured[$mode])) {
            $_SESSION['last_status'] = 'ERROR: ' . $modeLabel . ' NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $connectTarget = normalize_managed_digital_target($mode, $rawTarget);

        if ($connectTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID ' . $modeLabel . ' TARGET';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        $currentDmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
        $currentLastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));
        $sameManagedDigitalMode = $currentLastMode === $mode
            && in_array($mode, ['DSTAR', 'P25', 'NXDN'], true)
            && $currentDmrNetwork !== 'BM'
            && $currentDmrNetwork !== 'TGIF'
            && !$disconnectBeforeConnect;

        if ($sameManagedDigitalMode) {
            enforce_dvswitch_link_mode($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
            $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;

            ensure_mmdvm_bridge_active_for_managed_digital($modeLabel);

            dvswitch_mode($mode);
            pause_seconds(0.2);
            dvswitch_tune($connectTarget);

            $_SESSION['last_mode'] = $mode;
            $_SESSION['last_target'] = $connectTarget;
            $_SESSION['pending_target'] = $connectTarget;
            set_managed_dvswitch_state($mode, $connectTarget);
            clear_dmr_session_state();
            clear_dmr_active_state();
            $_SESSION['dvswitch_autoloaded'] = true;

            $_SESSION['last_status'] = 'CONNECTED: ' . $modeLabel . ' TARGET ' . $connectTarget;
            respond(session_payload($_SESSION['last_status']));
        }

        if ($disconnectBeforeConnect) {
            disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
        } elseif (tgifd_is_active()) {
            $tgifStop = tgifd_stop();
            pause_seconds(0.5);

            if (empty($tgifStop['ok'])) {
                $_SESSION['last_status'] = 'ERROR: FAILED TO STOP TGIFD';
                respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifStop]), 500);
            }

            if ($hasRealDvSwitchNode) {
                asterisk_ilink_disconnect($myNode, $dvSwitchNode);
                pause_seconds(0.5);
            }

            clear_runtime_targets();
        } elseif (bm_receive_is_active()) {
            $bmStop = bm_receive_stop();
            pause_seconds(0.5);

            if (empty($bmStop['ok'])) {
                $_SESSION['last_status'] = 'ERROR: FAILED TO STOP BM RECEIVE';
                respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmStop]), 500);
            }

            clear_runtime_targets();
        } else {
            $switchingManagedDigitalMode = in_array($currentLastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true)
                && $currentLastMode !== $mode;

            if ($switchingManagedDigitalMode) {
                $cleanupOutput = cleanup_previous_managed_gateway_link($currentLastMode);
                if ($cleanupOutput !== '') {
                    pause_seconds(0.3);
                }
                $keepDvSwitchLinkLoaded = true;
            } elseif ($currentDmrNetwork === 'BM' || $currentDmrNetwork === 'TGIF' || in_array($currentLastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true)) {
                disconnect_dvswitch_runtime($myNode, $dvSwitchNode);
                clear_runtime_targets();
            }
        }

        if (!$keepDvSwitchLinkLoaded) {
            load_dvswitch_link($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
            $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;
            pause_seconds(0.5);
        } else {
            enforce_dvswitch_link_mode($myNode, $dvSwitchNode, $autoloadDvSwitchMode);
            $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;
        }

        ensure_mmdvm_bridge_active_for_managed_digital($modeLabel);

        dvswitch_mode($mode);
        pause_seconds(0.5);

        dvswitch_tune($connectTarget);

        $_SESSION['last_mode'] = $mode;
        $_SESSION['last_target'] = $connectTarget;
        $_SESSION['pending_target'] = $connectTarget;
        set_managed_dvswitch_state($mode, $connectTarget);
        clear_dmr_session_state();
        clear_dmr_active_state();
        $_SESSION['dvswitch_autoloaded'] = true;

        $_SESSION['last_status'] = 'CONNECTED: ' . $modeLabel . ' TARGET ' . $connectTarget;
        respond(session_payload($_SESSION['last_status']));
    }

    if ($mode === 'BM') {
        if (!$hasRealMyNode || !$hasRealDvSwitchNode || !$hasRealBmPassword) {
            $_SESSION['last_status'] = 'ERROR: BM NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $connectTarget = $bmTarget;

        if ($connectTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID BM TG / PRIVATE';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        if ($disconnectBeforeConnect) {
            disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
        } else {
            $currentDmrNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
            $currentLastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));

            if (!bm_receive_is_active()) {
                if (in_array($currentLastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true)) {
                    $cleanupOutput = cleanup_previous_managed_gateway_link($currentLastMode);
                    if ($cleanupOutput !== '') {
                        pause_seconds(0.3);
                    }
                    clear_runtime_targets();
                } elseif ($currentDmrNetwork === 'TGIF') {
                    disconnect_dvswitch_runtime($myNode, $dvSwitchNode);
                    clear_runtime_targets();
                }
            }
        }

        $bmResult = bm_receive_is_active()
            ? bm_receive_tune($connectTarget)
            : bm_receive_start($connectTarget);

        if (empty($bmResult['ok'])) {
            pause_seconds(0.5);
            $bmStatus = bm_receive_status(true);
            $bmStatusTarget = trim((string) ($bmStatus['target'] ?? ''));

            if (!empty($bmStatus['active']) && ($bmStatusTarget === '' || $bmStatusTarget === $connectTarget)) {
                $bmResult = $bmStatus;
            } else {
                $_SESSION['last_status'] = 'ERROR: BM RECEIVE FAILED';
                respond(session_payload($_SESSION['last_status'], [
                    'bm_receive' => $bmResult,
                    'bm_receive_status' => $bmStatus,
                ]), 500);
            }
        }

        enforce_dvswitch_link_mode($myNode, $dvSwitchNode, $autoloadDvSwitchMode);

        $_SESSION['last_mode'] = 'BM';
        $_SESSION['last_target'] = $connectTarget;
        $_SESSION['pending_target'] = $connectTarget;
        set_managed_dvswitch_state('BM', $connectTarget);
        $_SESSION['pending_tg'] = $connectTarget;
        $_SESSION['dmr_network'] = 'BM';
        $_SESSION['dmr_ready'] = true;
        $_SESSION['dmr_active_network'] = 'BM';
        $_SESSION['dmr_active_target'] = $connectTarget;
        $_SESSION['dvswitch_autoloaded'] = true;
        $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;
        $_SESSION['last_status'] = 'CONNECTED: TG ' . $connectTarget . ' (BM)';

        respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmResult]));
    }

    if ($mode === 'TGIF') {
        if (!$hasRealMyNode || !$hasRealDvSwitchNode) {
            $_SESSION['last_status'] = 'ERROR: TGIF NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $connectTarget = $digitsOnlyTarget;

        if ($connectTarget === '') {
            $_SESSION['last_status'] = 'ERROR: INVALID TG';
            respond(session_payload($_SESSION['last_status']), 422);
        }

        if (!$hasRealTgifPassword) {
            $_SESSION['last_status'] = 'ERROR: TGIF NOT CONFIGURED';
            respond(session_payload($_SESSION['last_status']), 500);
        }

        $tgifWasActive = tgifd_is_active();

        if ($disconnectBeforeConnect) {
            disconnect_managed_links_before_connect($myNode, $dvSwitchNode);
            $tgifWasActive = false;
        } elseif (bm_receive_is_active()) {
            $bmStop = bm_receive_stop();
            pause_seconds(0.5);

            if (empty($bmStop['ok'])) {
                $_SESSION['last_status'] = 'ERROR: FAILED TO STOP BM RECEIVE';
                respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmStop]), 500);
            }

            clear_runtime_targets();
            $tgifWasActive = false;
        } else {
            $currentNetwork = normalize_mode((string) ($_SESSION['dmr_network'] ?? ''));
            $currentLastMode = normalize_mode((string) ($_SESSION['last_mode'] ?? ''));

            if (in_array($currentLastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true)) {
                $cleanupOutput = cleanup_previous_managed_gateway_link($currentLastMode);
                if ($cleanupOutput !== '') {
                    pause_seconds(0.3);
                }
                clear_runtime_targets();
                $tgifWasActive = false;
            } elseif ($currentNetwork === 'BM') {
                disconnect_dvswitch_runtime($myNode, $dvSwitchNode);
                clear_runtime_targets();
                $tgifWasActive = false;
            }
        }

        $tgifResult = $tgifWasActive
            ? tgifd_tune($connectTarget)
            : tgifd_start($connectTarget);

        if (empty($tgifResult['ok'])) {
            pause_seconds(0.5);
            $tgifStatus = tgifd_status(true);
            if (!empty($tgifStatus['active'])) {
                $tgifResult = $tgifStatus;
            } else {
                $_SESSION['last_status'] = 'ERROR: TGIFD FAILED';
                respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifResult]), 500);
            }
        }

        enforce_dvswitch_link_mode($myNode, $dvSwitchNode, $autoloadDvSwitchMode);

        $_SESSION['dvswitch_autoloaded'] = true;
        $_SESSION['dvswitch_active_mode'] = $autoloadDvSwitchMode;

        $_SESSION['last_mode'] = 'TGIF';
        $_SESSION['last_target'] = $connectTarget;
        $_SESSION['pending_target'] = $connectTarget;
        set_managed_dvswitch_state('TGIF', $connectTarget);
        $_SESSION['pending_tg'] = $connectTarget;
        $_SESSION['dmr_network'] = 'TGIF';
        $_SESSION['dmr_ready'] = true;
        $_SESSION['dmr_active_network'] = 'TGIF';
        $_SESSION['dmr_active_target'] = $connectTarget;
        $_SESSION['last_status'] = 'CONNECTED: TG ' . $connectTarget . ' (TGIF)';

        respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifResult]));
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

$trackedAllstarNode = last_tracked_allstar_node($dvSwitchNode);
if ($trackedAllstarNode !== '') {
    if ($hasRealDvSwitchNode && $trackedAllstarNode === $dvSwitchNode) {
        untrack_allstar_link($trackedAllstarNode);
        disconnect_selected_dvswitch_link($myNode, $dvSwitchNode);
        sync_last_direct_target_from_tracking($dvSwitchNode);

        $_SESSION['last_status'] = 'DISCONNECTED: DVSWITCH LINK ' . $dvSwitchNode;
        respond(session_payload($_SESSION['last_status']));
    }

    $trackedUiMode = last_tracked_allstar_ui_mode($dvSwitchNode);

    asterisk_ilink_disconnect($myNode, $trackedAllstarNode);
    pause_seconds(0.5);
    untrack_allstar_link($trackedAllstarNode);
    sync_last_direct_target_from_tracking($dvSwitchNode);

    $_SESSION['last_status'] = 'DISCONNECTED: ' . direct_node_status_label($trackedUiMode) . ' ' . $trackedAllstarNode;
    respond(session_payload($_SESSION['last_status']));
}

$lastMode = active_managed_dvswitch_mode();
$lastTarget = active_managed_dvswitch_target();
$dvswitchAutoloaded = !empty($_SESSION['dvswitch_autoloaded']);

if (in_array($lastMode, ['YSF', 'DSTAR', 'P25', 'NXDN'], true)) {
    if ($hasRealDvSwitchNode) {
        asterisk_ilink_disconnect($myNode, $dvSwitchNode);
        pause_seconds(0.5);
    }
    dvswitch_disconnect_mode($lastMode);
    clear_runtime_targets();
    $_SESSION['last_status'] = 'DISCONNECTED: ' . managed_digital_mode_label($lastMode);
    respond(session_payload($_SESSION['last_status']));
}

if ($lastMode === 'BM' || $lastMode === 'TGIF') {
    if ($lastMode === 'BM' && bm_receive_is_active()) {
        $bmStop = bm_receive_stop();
        pause_seconds(0.5);

        if (empty($bmStop['ok'])) {
            $_SESSION['last_status'] = 'ERROR: FAILED TO STOP BM RECEIVE';
            respond(session_payload($_SESSION['last_status'], ['bm_receive' => $bmStop]), 500);
        }
    } elseif ($lastMode === 'TGIF' && tgifd_is_active()) {
        $tgifStop = tgifd_stop();
        pause_seconds(0.5);

        if (empty($tgifStop['ok'])) {
            $_SESSION['last_status'] = 'ERROR: FAILED TO STOP TGIFD';
            respond(session_payload($_SESSION['last_status'], ['tgifd' => $tgifStop]), 500);
        }

        if ($dvswitchAutoloaded && $hasRealDvSwitchNode) {
            asterisk_ilink_disconnect($myNode, $dvSwitchNode);
            pause_seconds(0.5);
        }
    } else {
        dvswitch_tune('disconnect');
        pause_seconds(1.0);

        if ($dvswitchAutoloaded && $hasRealDvSwitchNode) {
            asterisk_ilink_disconnect($myNode, $dvSwitchNode);
            pause_seconds(0.5);
        }
    }

    clear_runtime_targets();
    $_SESSION['last_status'] = 'DISCONNECTED: ' . $lastMode;
    respond(session_payload($_SESSION['last_status']));
}

if ($dvswitchAutoloaded && $hasRealDvSwitchNode) {
    asterisk_ilink_disconnect($myNode, $dvSwitchNode);
    pause_seconds(0.5);
    unset($_SESSION['dvswitch_autoloaded']);
    unset($_SESSION['dvswitch_active_mode']);
    clear_dmr_active_state();
    $_SESSION['last_status'] = 'DISCONNECTED: DVSWITCH LINK';
    respond(session_payload($_SESSION['last_status']));
}

/*
 * Normal Disconnect must also clear the configured private/DVSwitch node
 * when that node was loaded by itself as a plain AllStar link. Keep this
 * after managed-mode cleanup so BM/TGIF/YSF/D-Star/P25/NXDN keep their
 * existing disconnect behavior.
 */
if ($hasRealDvSwitchNode) {
    asterisk_ilink_disconnect($myNode, $dvSwitchNode);
    pause_seconds(0.5);
    untrack_allstar_link($dvSwitchNode);
    sync_last_direct_target_from_tracking($dvSwitchNode);

    $_SESSION['last_status'] = 'DISCONNECTED: DVSWITCH LINK ' . $dvSwitchNode;
    respond(session_payload($_SESSION['last_status']));
}

clear_runtime_targets();
clear_allstar_tracking();
$_SESSION['last_status'] = 'DISCONNECTED';

respond(session_payload($_SESSION['last_status']));
