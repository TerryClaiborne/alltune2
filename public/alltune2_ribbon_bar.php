<?php

declare(strict_types=1);

if (!function_exists('at2r_exec')) {
    function at2r_exec(string $command): string
    {
        if (function_exists('shell_exec')) {
            $output = @shell_exec($command);
            if (is_string($output)) {
                return trim($output);
            }
        }

        if (function_exists('exec')) {
            $lines = [];
            $status = 0;
            @exec($command, $lines, $status);
            if (is_array($lines) && $lines !== []) {
                return trim(implode("\n", $lines));
            }
        }

        return '';
    }
}

if (!function_exists('at2r_read_text')) {
    function at2r_read_text(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $text = @file_get_contents($path);
        if ($text === false) {
            return null;
        }

        return trim($text);
    }
}

if (!function_exists('at2r_format_binary')) {
    function at2r_format_binary(float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0.0, $bytes);
        $unit = 0;

        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        if ($value >= 100) {
            $decimals = 0;
        } elseif ($value >= 10) {
            $decimals = min(1, $precision);
        } else {
            $decimals = $precision;
        }

        return number_format($value, $decimals) . ' ' . $units[$unit];
    }
}

if (!function_exists('at2r_format_bits_rate')) {
    function at2r_format_bits_rate(float $bytesPerSecond): string
    {
        $value = max(0.0, $bytesPerSecond) * 8.0;
        $units = ['b/s', 'Kb/s', 'Mb/s', 'Gb/s'];
        $unit = 0;

        while ($value >= 1000.0 && $unit < count($units) - 1) {
            $value /= 1000.0;
            $unit++;
        }

        if ($value >= 100) {
            $decimals = 0;
        } elseif ($value >= 10) {
            $decimals = 1;
        } else {
            $decimals = 2;
        }

        return number_format($value, $decimals) . ' ' . $units[$unit];
    }
}

if (!function_exists('at2r_format_uptime_short')) {
    function at2r_format_uptime_short(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %02dh', $days, $hours);
        }
        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }
        return sprintf('%d min', max(0, $minutes));
    }
}

if (!function_exists('at2r_format_uptime_long')) {
    function at2r_format_uptime_long(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        $parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        return implode(', ', $parts);
    }
}

if (!function_exists('at2r_host_support_notes')) {
    function at2r_host_support_notes(): array
    {
        $notes = [
            'OS family: ' . PHP_OS_FAMILY,
            'PHP version: ' . PHP_VERSION,
        ];
        if (PHP_OS_FAMILY !== 'Linux') {
            $notes[] = 'This ribbon is designed for Linux /proc, /sys, and df sources.';
        }
        return $notes;
    }
}

if (!function_exists('at2r_hostname')) {
    function at2r_hostname(): string
    {
        $hostname = gethostname();
        if (is_string($hostname) && $hostname !== '') {
            return $hostname;
        }
        $uname = php_uname('n');
        return $uname !== '' ? $uname : 'unknown';
    }
}

if (!function_exists('at2r_primary_ip')) {
    function at2r_primary_ip(): string
    {
        $fromCmd = at2r_exec('hostname -I 2>/dev/null');
        if ($fromCmd !== '') {
            $parts = preg_split('/\s+/', $fromCmd);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($part, '127.') !== 0) {
                        return $part;
                    }
                }
            }
        }

        $ips = @gethostbynamel(at2r_hostname());
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($ip, '127.') !== 0) {
                    return $ip;
                }
            }
        }

        return 'n/a';
    }
}

if (!function_exists('at2r_cpu_usage')) {
    function at2r_cpu_usage(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/stat')) {
            return [
                'available' => false,
                'compact' => 'N/A',
                'detail' => 'N/A',
                'load' => 'n/a',
                'cores' => 0,
                'reason' => '/proc/stat is not readable on this system.',
            ];
        }

        $sample = static function (): ?array {
            $line = at2r_read_text('/proc/stat');
            if ($line === null) {
                return null;
            }
            $firstLine = strtok($line, "\n");
            if (!is_string($firstLine) || strpos($firstLine, 'cpu ') !== 0) {
                return null;
            }
            $parts = preg_split('/\s+/', trim($firstLine));
            if (!is_array($parts) || count($parts) < 8) {
                return null;
            }
            $numbers = array_map('floatval', array_slice($parts, 1));
            $idle = ($numbers[3] ?? 0.0) + ($numbers[4] ?? 0.0);
            $total = array_sum($numbers);
            return ['idle' => $idle, 'total' => $total];
        };

        $first = $sample();
        usleep(200000);
        $second = $sample();

        if (!is_array($first) || !is_array($second)) {
            return [
                'available' => false,
                'compact' => 'N/A',
                'detail' => 'N/A',
                'load' => 'n/a',
                'cores' => 0,
                'reason' => 'CPU counters could not be sampled from /proc/stat.',
            ];
        }

        $usage = 0.0;
        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];
        if ($totalDelta > 0) {
            $usage = (1.0 - ($idleDelta / $totalDelta)) * 100.0;
        }

        $load = sys_getloadavg();
        $loadText = is_array($load)
            ? implode(', ', array_map(static function ($v): string {
                return number_format((float) $v, 2);
            }, array_slice($load, 0, 3)))
            : 'n/a';

        $cores = (int) trim(at2r_exec('nproc 2>/dev/null'));
        if ($cores < 1) {
            $cores = 1;
        }

        $usage = max(0.0, min(100.0, $usage));
        return [
            'available' => true,
            'percent' => $usage,
            'compact' => number_format($usage, 0) . '%',
            'detail' => number_format($usage, 2) . '%',
            'load' => $loadText,
            'cores' => $cores,
            'reason' => '',
        ];
    }
}

if (!function_exists('at2r_memory')) {
    function at2r_memory(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/meminfo')) {
            return [
                'available' => false,
                'used' => 0.0,
                'total' => 0.0,
                'available_bytes' => 0.0,
                'compact' => 'N/A',
                'detail_used' => 'N/A',
                'detail_total' => 'N/A',
                'detail_available' => 'N/A',
                'percent' => 'N/A',
                'reason' => '/proc/meminfo is not readable on this system.',
            ];
        }

        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $values = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^([^:]+):\s+(\d+)/', $line, $m)) {
                    $values[$m[1]] = (float) $m[2] * 1024.0;
                }
            }
        }

        $total = $values['MemTotal'] ?? 0.0;
        if ($total <= 0.0) {
            return [
                'available' => false,
                'used' => 0.0,
                'total' => 0.0,
                'available_bytes' => 0.0,
                'compact' => 'N/A',
                'detail_used' => 'N/A',
                'detail_total' => 'N/A',
                'detail_available' => 'N/A',
                'percent' => 'N/A',
                'reason' => 'MemTotal was not found in /proc/meminfo.',
            ];
        }

        $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0.0) + ($values['Buffers'] ?? 0.0) + ($values['Cached'] ?? 0.0));
        $used = max(0.0, $total - $available);
        $percent = ($used / $total) * 100.0;

        return [
            'available' => true,
            'used' => $used,
            'total' => $total,
            'available_bytes' => $available,
            'compact' => at2r_format_binary($used, 2) . ' / ' . at2r_format_binary($total, 2),
            'detail_used' => at2r_format_binary($used, 2),
            'detail_total' => at2r_format_binary($total, 2),
            'detail_available' => at2r_format_binary($available, 2),
            'percent' => number_format($percent, 0) . '%',
            'reason' => '',
        ];
    }
}

if (!function_exists('at2r_temp_celsius_from_raw')) {
    function at2r_temp_celsius_from_raw(float $raw): float
    {
        $candidates = [];
        if ($raw >= 1000.0) {
            $candidates[] = $raw / 1000.0;
            $candidates[] = $raw / 100.0;
        } else {
            $candidates[] = $raw;
        }

        foreach ($candidates as $candidate) {
            if ($candidate >= 10.0 && $candidate <= 120.0) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}

if (!function_exists('at2r_temperature')) {
    function at2r_temperature(): array
    {
        $paths = glob('/sys/class/thermal/thermal_zone*/temp') ?: [];
        $zones = [];
        $chosen = null;

        foreach ($paths as $path) {
            $rawText = at2r_read_text($path);
            if ($rawText === null || !is_numeric($rawText)) {
                continue;
            }
            $raw = (float) $rawText;
            $celsius = at2r_temp_celsius_from_raw($raw);
            $fahrenheit = ($celsius * 9.0 / 5.0) + 32.0;
            $typePath = dirname($path) . '/type';
            $zones[] = [
                'path' => $path,
                'type' => at2r_read_text($typePath) ?? basename(dirname($path)),
                'raw' => $rawText,
                'c' => $celsius,
                'f' => $fahrenheit,
            ];
            if ($chosen === null || $celsius > $chosen['c']) {
                $chosen = ['c' => $celsius, 'f' => $fahrenheit, 'raw' => $rawText, 'path' => $path];
            }
        }

        if ($chosen === null) {
            return [
                'available' => false,
                'compact' => 'N/A',
                'detail' => ['No readable /sys/class/thermal/thermal_zone*/temp source was found.'],
            ];
        }

        $detail = [];
        $detail[] = 'Chosen reading: ' . number_format($chosen['f'], 2) . "\u{00B0}F / " . number_format($chosen['c'], 2) . "\u{00B0}C";
        $detail[] = 'Raw: ' . $chosen['raw'];
        $detail[] = 'Source: ' . $chosen['path'];
        foreach ($zones as $zone) {
            $detail[] = $zone['type'] . ': ' . number_format($zone['f'], 2) . "\u{00B0}F / " . number_format($zone['c'], 2) . "\u{00B0}C (raw " . $zone['raw'] . ')';
        }

        return [
            'available' => true,
            'compact' => number_format($chosen['f'], 1) . "\u{00B0}F / " . number_format($chosen['c'], 2) . "\u{00B0}C",
            'detail' => $detail,
        ];
    }
}

if (!function_exists('at2r_uptime')) {
    function at2r_uptime(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/uptime')) {
            return [
                'available' => false,
                'seconds' => 0,
                'compact' => 'N/A',
                'detail' => 'N/A',
                'reason' => '/proc/uptime is not readable on this system.',
            ];
        }

        $raw = at2r_read_text('/proc/uptime');
        $seconds = 0;
        if ($raw !== null) {
            $parts = preg_split('/\s+/', trim($raw));
            if (is_array($parts) && isset($parts[0]) && is_numeric($parts[0])) {
                $seconds = (int) floor((float) $parts[0]);
            }
        }

        if ($seconds < 0) {
            $seconds = 0;
        }

        return [
            'available' => true,
            'seconds' => $seconds,
            'compact' => at2r_format_uptime_short($seconds),
            'detail' => at2r_format_uptime_long($seconds),
            'reason' => '',
        ];
    }
}

if (!function_exists('at2r_default_interface')) {
    function at2r_default_interface(): ?string
    {
        if (!is_readable('/proc/net/route')) {
            $dirs = glob('/sys/class/net/*') ?: [];
            foreach ($dirs as $dir) {
                $iface = basename($dir);
                if ($iface !== 'lo') {
                    return $iface;
                }
            }
            return null;
        }

        $routeLines = @file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($routeLines)) {
            foreach ($routeLines as $index => $line) {
                if ($index === 0) {
                    continue;
                }
                $parts = preg_split('/\s+/', trim($line));
                if (!is_array($parts) || count($parts) < 2) {
                    continue;
                }
                if (($parts[1] ?? '') === '00000000' && ($parts[0] ?? '') !== 'lo') {
                    return $parts[0];
                }
            }
        }

        $dirs = glob('/sys/class/net/*') ?: [];
        foreach ($dirs as $dir) {
            $iface = basename($dir);
            if ($iface === 'lo') {
                continue;
            }
            $state = at2r_read_text($dir . '/operstate') ?? '';
            if ($state === 'up' || $state === 'unknown') {
                return $iface;
            }
        }

        foreach ($dirs as $dir) {
            $iface = basename($dir);
            if ($iface !== 'lo') {
                return $iface;
            }
        }

        return null;
    }
}

if (!function_exists('at2r_network')) {
    function at2r_network(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/sys/class/net')) {
            return [
                'available' => false,
                'interface' => 'n/a',
                'rx_bytes' => 0.0,
                'tx_bytes' => 0.0,
                'rx_rate' => 0.0,
                'tx_rate' => 0.0,
                'rx_compact' => 'N/A',
                'tx_compact' => 'N/A',
                'reason' => '/sys/class/net is not available on this system.',
            ];
        }

        $iface = at2r_default_interface();
        if ($iface === null || $iface === '') {
            return [
                'available' => false,
                'interface' => 'n/a',
                'rx_bytes' => 0.0,
                'tx_bytes' => 0.0,
                'rx_rate' => 0.0,
                'tx_rate' => 0.0,
                'rx_compact' => 'N/A',
                'tx_compact' => 'N/A',
                'reason' => 'No active non-loopback network interface was found.',
            ];
        }

        $rxPath = '/sys/class/net/' . $iface . '/statistics/rx_bytes';
        $txPath = '/sys/class/net/' . $iface . '/statistics/tx_bytes';
        $rxText = at2r_read_text($rxPath);
        $txText = at2r_read_text($txPath);

        if ($rxText === null || $txText === null || !is_numeric($rxText) || !is_numeric($txText)) {
            return [
                'available' => false,
                'interface' => $iface,
                'rx_bytes' => 0.0,
                'tx_bytes' => 0.0,
                'rx_rate' => 0.0,
                'tx_rate' => 0.0,
                'rx_compact' => 'N/A',
                'tx_compact' => 'N/A',
                'reason' => 'Network byte counters are not readable for interface ' . $iface . '.',
            ];
        }

        $rx = (float) $rxText;
        $tx = (float) $txText;

        $cacheFile = sys_get_temp_dir() . '/alltune2_ribbon_net_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $iface) . '.json';
        $now = microtime(true);
        $previous = null;

        if (is_readable($cacheFile)) {
            $decoded = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($decoded)) {
                $previous = $decoded;
            }
        }

        $rxRate = 0.0;
        $txRate = 0.0;
        if (is_array($previous)) {
            $dt = $now - (float) ($previous['time'] ?? 0.0);
            if ($dt > 0.10) {
                $prevRx = (float) ($previous['rx'] ?? 0.0);
                $prevTx = (float) ($previous['tx'] ?? 0.0);
                $rxDelta = $rx - $prevRx;
                $txDelta = $tx - $prevTx;
                if ($rxDelta >= 0.0) {
                    $rxRate = $rxDelta / $dt;
                }
                if ($txDelta >= 0.0) {
                    $txRate = $txDelta / $dt;
                }
            }
        }

        @file_put_contents($cacheFile, json_encode([
            'time' => $now,
            'rx' => $rx,
            'tx' => $tx,
        ]));

        return [
            'available' => true,
            'interface' => $iface,
            'rx_bytes' => $rx,
            'tx_bytes' => $tx,
            'rx_rate' => $rxRate,
            'tx_rate' => $txRate,
            'rx_compact' => at2r_format_bits_rate($rxRate),
            'tx_compact' => at2r_format_bits_rate($txRate),
            'reason' => '',
        ];
    }
}

if (!function_exists('at2r_df_info')) {
    function at2r_df_info(string $path, string $label): array
    {
        $result = [
            'label' => $label,
            'path' => $path,
            'available' => false,
            'compact' => 'N/A',
            'detail' => [
                'Path: ' . $path,
                'Disk data is not available.',
            ],
        ];

        if (!file_exists($path)) {
            $result['detail'][] = 'Reason: Path not present on this system.';
            return $result;
        }

        $output = at2r_exec('df -kP ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($output === '') {
            $result['detail'][] = 'Reason: df -kP returned no data.';
            return $result;
        }

        $lines = preg_split('/\R+/', trim($output));
        if (!is_array($lines) || count($lines) < 2) {
            $result['detail'][] = 'Reason: df output could not be parsed.';
            return $result;
        }

        $line = trim((string) $lines[1]);
        $parts = preg_split('/\s+/', $line, 6);
        if (!is_array($parts) || count($parts) < 6) {
            $result['detail'][] = 'Reason: df output format was unexpected.';
            $result['detail'][] = 'Raw df: ' . $line;
            return $result;
        }

        [$filesystem, $blocks, $used, $available, $usePercent, $mounted] = $parts;
        $blocksBytes = (float) $blocks * 1024.0;
        $usedBytes = (float) $used * 1024.0;
        $availableBytes = (float) $available * 1024.0;

        $result['available'] = true;
        $result['compact'] = $usePercent;
        $result['detail'] = [
            'Path: ' . $path,
            'Filesystem: ' . $filesystem,
            'Mounted on: ' . $mounted,
            'Used: ' . at2r_format_binary($usedBytes, 2),
            'Available: ' . at2r_format_binary($availableBytes, 2),
            'Total: ' . at2r_format_binary($blocksBytes, 2),
            'Use: ' . $usePercent,
            'Raw df: ' . $line,
        ];
        return $result;
    }
}

if (!function_exists('at2r_sessions')) {
    function at2r_sessions(): array
    {
        $output = at2r_exec('who 2>/dev/null');
        $lines = $output === '' ? [] : preg_split('/\R+/', trim($output));
        $lines = is_array($lines) ? array_values(array_filter($lines, static function ($line): bool {
            return trim((string) $line) !== '';
        })) : [];

        $users = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim((string) $line));
            if (is_array($parts) && isset($parts[0])) {
                $user = $parts[0];
                $users[$user] = ($users[$user] ?? 0) + 1;
            }
        }

        $summary = [];
        foreach ($users as $user => $count) {
            $summary[] = $user . '(x' . $count . ')';
        }

        return [
            'count' => count($lines),
            'summary' => $summary === [] ? 'No active who sessions' : implode(', ', $summary),
        ];
    }
}

if (!function_exists('at2r_collect')) {
    function at2r_collect(): array
    {
        $hostname = at2r_hostname();
        $cpu = at2r_cpu_usage();
        $memory = at2r_memory();
        $temp = at2r_temperature();
        $uptime = at2r_uptime();
        $network = at2r_network();
        $sessions = at2r_sessions();
        $serverTime = date('m-d-Y h:i A');
        $timezone = date_default_timezone_get();

        $disks = [
            at2r_df_info('/', '/'),
            at2r_df_info('/tmp', '/tmp'),
            at2r_df_info('/var/log/apache2', 'apache2'),
            at2r_df_info('/var/log/asterisk', 'asterisk'),
            at2r_df_info('/boot/firmware', 'firmware'),
            at2r_df_info('/var/tmp', 'vartmp'),
        ];

        $supportNotes = at2r_host_support_notes();

        $pills = [];
        $pills[] = [
            'key' => 'node',
            'label' => 'Node',
            'value' => $hostname,
            'icon' => 'host',
            'detail' => array_merge([
                'Hostname: ' . $hostname,
                'Primary IP: ' . at2r_primary_ip(),
                'Interface: ' . $network['interface'],
                'Sessions: ' . $sessions['summary'],
            ], $supportNotes),
        ];
        $pills[] = [
            'key' => 'time',
            'label' => 'Time',
            'value' => $serverTime,
            'icon' => 'time',
            'detail' => [
                'Server time: ' . $serverTime,
                'Timezone: ' . $timezone,
                'Browser local time updates every minute in the ribbon.',
            ],
        ];

        $pills[] = [
            'key' => 'cpu',
            'label' => 'CPU',
            'value' => $cpu['compact'],
            'icon' => 'cpu',
            'na' => !$cpu['available'],
            'detail' => $cpu['available']
                ? [
                    'CPU usage: ' . $cpu['detail'],
                    'Load average: ' . $cpu['load'],
                    'CPU cores: ' . $cpu['cores'],
                ]
                : [
                    'CPU usage: N/A',
                    'Reason: ' . $cpu['reason'],
                ],
        ];
        $pills[] = [
            'key' => 'ram',
            'label' => 'RAM',
            'value' => $memory['compact'],
            'icon' => 'ram',
            'na' => !$memory['available'],
            'detail' => $memory['available']
                ? [
                    'Used: ' . $memory['detail_used'],
                    'Total: ' . $memory['detail_total'],
                    'Available: ' . $memory['detail_available'],
                    'Use: ' . $memory['percent'],
                ]
                : [
                    'RAM: N/A',
                    'Reason: ' . $memory['reason'],
                ],
        ];
        $pills[] = [
            'key' => 'up',
            'label' => 'Up',
            'value' => $network['tx_compact'],
            'icon' => 'up',
            'na' => !$network['available'],
            'detail' => $network['available']
                ? [
                    'Interface: ' . $network['interface'],
                    'Current upload: ' . $network['tx_compact'],
                    'TX total: ' . at2r_format_binary($network['tx_bytes'], 2),
                ]
                : [
                    'Upload: N/A',
                    'Interface: ' . $network['interface'],
                    'Reason: ' . $network['reason'],
                ],
        ];
        $pills[] = [
            'key' => 'down',
            'label' => 'Down',
            'value' => $network['rx_compact'],
            'icon' => 'down',
            'na' => !$network['available'],
            'detail' => $network['available']
                ? [
                    'Interface: ' . $network['interface'],
                    'Current download: ' . $network['rx_compact'],
                    'RX total: ' . at2r_format_binary($network['rx_bytes'], 2),
                ]
                : [
                    'Download: N/A',
                    'Interface: ' . $network['interface'],
                    'Reason: ' . $network['reason'],
                ],
        ];
        $pills[] = [
            'key' => 'temp',
            'label' => 'Temp',
            'value' => $temp['compact'],
            'icon' => 'temp',
            'na' => !$temp['available'],
            'detail' => $temp['detail'],
        ];
        $pills[] = [
            'key' => 'uptime',
            'label' => 'Uptime',
            'value' => $uptime['compact'],
            'icon' => 'clock',
            'na' => !$uptime['available'],
            'detail' => $uptime['available']
                ? [
                    'System uptime: ' . $uptime['detail'],
                ]
                : [
                    'Uptime: N/A',
                    'Reason: ' . $uptime['reason'],
                ],
        ];

        foreach ($disks as $disk) {
            $pills[] = [
                'key' => 'disk_' . preg_replace('/[^a-z0-9]+/i', '_', $disk['label']),
                'label' => $disk['label'],
                'value' => $disk['compact'],
                'icon' => 'disk',
                'na' => !$disk['available'],
                'detail' => $disk['detail'],
            ];
        }

        return [
            'generated_at' => time(),
            'server_time' => $serverTime,
            'timezone' => $timezone,
            'pills' => $pills,
        ];
    }
}

if (isset($_GET['alltune2_ribbon_ajax']) && $_GET['alltune2_ribbon_ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(at2r_collect(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$initial = at2r_collect();
$instanceId = 'at2r_' . substr(md5((string) __FILE__), 0, 10);
$selfUrl = htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8');
$initialJson = json_encode($initial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div id="<?= $instanceId ?>" class="at2r-wrap" data-endpoint="<?= $selfUrl ?>?alltune2_ribbon_ajax=1">
    <style>
        #<?= $instanceId ?>.at2r-wrap {
            position: relative;
            width: 100%;
            margin: 10px 0 12px;
            padding: 6px 8px;
            box-sizing: border-box;
            border: 1px solid rgba(170, 92, 255, 0.38);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(28, 10, 44, 0.96), rgba(18, 7, 30, 0.96));
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.02) inset;
            overflow: hidden;
            font-family: Arial, Helvetica, sans-serif;
        }
        #<?= $instanceId ?> .at2r-row {
            display: flex;
            align-items: center;
            gap: 3px;
            flex-wrap: nowrap;
            white-space: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: thin;
            scrollbar-color: rgba(178, 123, 255, 0.55) transparent;
            padding-bottom: 1px;
        }
        #<?= $instanceId ?> .at2r-row::-webkit-scrollbar {
            height: 5px;
        }
        #<?= $instanceId ?> .at2r-row::-webkit-scrollbar-thumb {
            background: rgba(178, 123, 255, 0.55);
            border-radius: 999px;
        }
        #<?= $instanceId ?> .at2r-pill {
            appearance: none;
            border: 1px solid rgba(161, 118, 221, 0.40);
            background: linear-gradient(180deg, rgba(44, 17, 72, 0.96), rgba(24, 9, 38, 0.98));
            color: #f7f0ff;
            height: 24px;
            min-height: 24px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0 7px;
            font-size: 11px;
            line-height: 1;
            cursor: pointer;
            box-shadow: none;
            flex: 0 0 auto;
            max-width: none;
        }
        #<?= $instanceId ?> .at2r-pill:hover,
        #<?= $instanceId ?> .at2r-pill.at2r-open {
            border-color: rgba(217, 159, 255, 0.80);
            background: linear-gradient(180deg, rgba(70, 25, 107, 0.98), rgba(34, 11, 55, 1));
        }
        #<?= $instanceId ?> .at2r-pill.at2r-na .at2r-value {
            color: #ffd98d;
        }
        #<?= $instanceId ?> .at2r-icon {
            width: 12px;
            height: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #8de4ff;
            flex: 0 0 12px;
        }
        #<?= $instanceId ?> .at2r-icon svg {
            width: 12px;
            height: 12px;
            display: block;
            fill: currentColor;
        }
        #<?= $instanceId ?> .at2r-icon.up { color: #57eeb1; }
        #<?= $instanceId ?> .at2r-icon.down { color: #7bc2ff; }
        #<?= $instanceId ?> .at2r-icon.temp { color: #ffb16c; }
        #<?= $instanceId ?> .at2r-icon.disk { color: #d7c6ff; }
        #<?= $instanceId ?> .at2r-label {
            color: #f4a6ff;
            font-weight: 700;
        }
        #<?= $instanceId ?> .at2r-value {
            color: #ffffff;
            font-weight: 600;
        }
        #<?= $instanceId ?> .at2r-time .at2r-value {
            letter-spacing: 0.1px;
        }
        #<?= $instanceId ?> .at2r-popup {
            position: fixed;
            z-index: 99999;
            min-width: 240px;
            max-width: 320px;
            border: 1px solid rgba(205, 150, 255, 0.65);
            border-radius: 10px;
            background: linear-gradient(180deg, rgba(34, 11, 54, 0.99), rgba(18, 7, 30, 0.99));
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
            padding: 10px 11px;
            color: #f8eeff;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.35;
        }
        #<?= $instanceId ?> .at2r-popup[hidden] {
            display: none;
        }
        #<?= $instanceId ?> .at2r-popup-title {
            margin: 0 0 6px;
            font-size: 13px;
            font-weight: 700;
            color: #ffb3ff;
        }
        #<?= $instanceId ?> .at2r-popup-line {
            margin: 0 0 4px;
            color: #f7f1ff;
            word-break: break-word;
        }
        #<?= $instanceId ?> .at2r-popup-line:last-child {
            margin-bottom: 0;
        }
        @media (max-width: 980px) {
            #<?= $instanceId ?>.at2r-wrap {
                margin-top: 8px;
            }
        }
    </style>

    <div class="at2r-row" aria-label="AllTune2 compact ribbon bar"></div>
    <div class="at2r-popup" hidden></div>

    <script>
        (function () {
            const root = document.getElementById(<?= json_encode($instanceId) ?>);
            if (!root) {
                return;
            }

            const initialData = <?= $initialJson ?>;
            const row = root.querySelector('.at2r-row');
            const popup = root.querySelector('.at2r-popup');
            const endpoint = root.getAttribute('data-endpoint');
            let state = initialData;
            let openKey = null;
            let currentButton = null;
            let localClock = new Date();

            const icons = {
                host: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H9.5L7 13.5 4.5 11H2a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm2 2a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm3 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/></svg>',
                cpu: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5 1h2v2h2V1h2v2h1.5A1.5 1.5 0 0 1 14 4.5V6h2v2h-2v2h2v2h-2v1.5A1.5 1.5 0 0 1 12.5 15H11v2H9v-2H7v2H5v-2H3.5A1.5 1.5 0 0 1 2 13.5V12H0v-2h2V8H0V6h2V4.5A1.5 1.5 0 0 1 3.5 3H5V1Zm-.5 4A.5.5 0 0 0 4 5.5v5a.5.5 0 0 0 .5.5h7a.5.5 0 0 0 .5-.5v-5a.5.5 0 0 0-.5-.5h-7Z"/></svg>',
                ram: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 4h12v7H2V4Zm1 1v5h10V5H3Zm1 7h1v2H4v-2Zm3 0h2v2H7v-2Zm4 0h1v2h-1v-2Z"/></svg>',
                up: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2 3 7h3v5h4V7h3L8 2Zm-5 11h10v1H3v-1Z"/></svg>',
                down: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 14 13 9h-3V4H6v5H3l5 5ZM3 2h10v1H3V2Z"/></svg>',
                temp: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9 9.59V3.5a2 2 0 1 0-4 0v6.09a3.5 3.5 0 1 0 4 0ZM7 2.5a1 1 0 1 1 2 0V10l.29.21a2.5 2.5 0 1 1-2.58 0L7 10V2.5Z"/></svg>',
                clock: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1Zm0 1a6 6 0 1 1 0 12A6 6 0 0 1 8 2Zm-.5 2h1v4.29l2.5 2.5-.71.71L7.5 8.71V4Z"/></svg>',
                disk: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm0 2v6h12V5H2Zm2 4.25a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5Zm2.5 0a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5Z"/></svg>',
                time: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1Zm.5 3v4h3v1h-4V4h1Z"/></svg>'
            };

            function escapeHtml(text) {
                return String(text)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatDate(dateObj) {
                const pad = (value) => String(value).padStart(2, '0');
                let hours = dateObj.getHours();
                const minutes = pad(dateObj.getMinutes());
                                const ampm = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12;
                if (hours === 0) {
                    hours = 12;
                }
                return pad(dateObj.getMonth() + 1) + '-' + pad(dateObj.getDate()) + '-' + dateObj.getFullYear() + ' ' + pad(hours) + ':' + minutes + ' ' + ampm;
            }

            function popupHtml(pill) {
                const lines = Array.isArray(pill.detail) ? pill.detail : [];
                return '<div class="at2r-popup-title">' + escapeHtml(pill.label) + '</div>' +
                    lines.map((line) => '<div class="at2r-popup-line">' + escapeHtml(line) + '</div>').join('');
            }

            function closePopup() {
                popup.hidden = true;
                popup.innerHTML = '';
                if (currentButton) {
                    currentButton.classList.remove('at2r-open');
                }
                currentButton = null;
                openKey = null;
            }

            function openPopup(button, pill) {
                if (openKey === pill.key && currentButton === button && !popup.hidden) {
                    closePopup();
                    return;
                }

                popup.innerHTML = popupHtml(pill);
                popup.hidden = false;
                if (currentButton) {
                    currentButton.classList.remove('at2r-open');
                }
                currentButton = button;
                currentButton.classList.add('at2r-open');
                openKey = pill.key;

                const rect = button.getBoundingClientRect();
                const popupWidth = Math.min(320, Math.max(240, popup.offsetWidth || 260));
                let left = rect.left;
                let top = rect.bottom + 6;

                if ((left + popupWidth) > (window.innerWidth - 8)) {
                    left = window.innerWidth - popupWidth - 8;
                }
                if (left < 8) {
                    left = 8;
                }

                popup.style.left = left + 'px';
                popup.style.top = top + 'px';
            }

            function render(data) {
                state = data;
                row.innerHTML = '';
                data.pills.forEach((pill) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'at2r-pill at2r-' + pill.key;
                    if (pill.key === 'time') {
                        button.classList.add('at2r-time');
                    }
                    if (pill.na) {
                        button.classList.add('at2r-na');
                    }
                    button.dataset.key = pill.key;
                    const iconClass = pill.icon === 'up' || pill.icon === 'down' || pill.icon === 'temp' || pill.icon === 'disk' ? ' ' + pill.icon : '';
                    const displayValue = pill.key === 'time' ? formatDate(localClock) : pill.value;
                    button.innerHTML = '<span class="at2r-icon' + iconClass + '">' + (icons[pill.icon] || icons.disk) + '</span>' +
                        '<span class="at2r-label">' + escapeHtml(pill.label) + '</span>' +
                        '<span class="at2r-value">' + escapeHtml(displayValue) + '</span>';
                    button.addEventListener('click', function (event) {
                        event.stopPropagation();
                        openPopup(button, pill);
                    });
                    row.appendChild(button);
                });

                if (openKey) {
                    const refreshed = data.pills.find((pill) => pill.key === openKey);
                    const button = row.querySelector('.at2r-pill[data-key="' + CSS.escape(openKey) + '"]');
                    if (refreshed && button) {
                        openPopup(button, refreshed);
                    } else {
                        closePopup();
                    }
                }
            }

            function updateClockOnly() {
                const timePill = row.querySelector('.at2r-pill[data-key="time"] .at2r-value');
                if (!timePill) {
                    return;
                }
                localClock = new Date(localClock.getTime() + 60000);
                timePill.textContent = formatDate(localClock);
                if (openKey === 'time' && currentButton) {
                    const pill = state.pills.find((item) => item.key === 'time');
                    if (pill) {
                        const updated = Object.assign({}, pill, {
                            value: formatDate(localClock),
                            detail: [
                                'Browser local time: ' + formatDate(localClock),
                                'Server time at last poll: ' + state.server_time,
                                'Timezone: ' + state.timezone
                            ]
                        });
                        popup.innerHTML = popupHtml(updated);
                    }
                }
            }

            async function refresh() {
                try {
                    const response = await fetch(endpoint, {
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) {
                        return;
                    }
                    const data = await response.json();
                    localClock = new Date();
                    render(data);
                } catch (error) {
                    // Keep last good values.
                }
            }

            document.addEventListener('click', function (event) {
                if (!root.contains(event.target) && !popup.contains(event.target)) {
                    closePopup();
                }
            });
            window.addEventListener('resize', function () {
                if (openKey && currentButton) {
                    const pill = state.pills.find((item) => item.key === openKey);
                    if (pill) {
                        openPopup(currentButton, pill);
                    }
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closePopup();
                }
            });

            localClock = new Date();
            render(initialData);
            window.setInterval(updateClockOnly, 60000);
            window.setInterval(refresh, 2500);
            refresh();
        }());
    </script>
</div>
