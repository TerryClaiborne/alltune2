<?php
declare(strict_types=1);

final class AllStarAction implements ActionInterface
{
    public function connect(string $target): array
    {
        return [
            'ok' => false,
            'network' => 'AllStar',
            'action' => 'connect',
            'target' => trim($target),
            'state' => AppState::FAILED,
            'message' => 'Not implemented',
        ];
    }

    public function disconnect(): array
    {
        return [
            'ok' => false,
            'network' => 'AllStar',
            'action' => 'disconnect',
            'state' => AppState::FAILED,
            'message' => 'Not implemented',
        ];
    }

    public function status(): array
    {
        $connectedNodes = $this->connectedNodes();
        $favorites = $this->favorites();
        $hasConnections = $connectedNodes !== [];

        return [
            'ok' => true,
            'network' => 'AllStar',
            'action' => 'status',
            'state' => $hasConnections ? AppState::CONNECTED : AppState::IDLE,
            'message' => $hasConnections
                ? MessageCatalog::get(AppState::CONNECTED)
                : MessageCatalog::get(AppState::IDLE),
            'connected_nodes' => $connectedNodes,
            'favorites' => $favorites,
            'notes' => $this->notes(),
            'placeholders' => $this->placeholders(),
        ];
    }

    private function connectedNodes(): array
    {
        $localNodes = $this->localNodes();

        if ($localNodes === []) {
            return [];
        }

        $found = [];

        foreach ($localNodes as $localNode) {
            $output = $this->runCommand('asterisk -rx "rpt nodes ' . $localNode . '" 2>/dev/null');

            if ($output === '' || stripos($output, '<NONE>') !== false) {
                continue;
            }

            foreach (preg_split('/\R+/', $output) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (stripos($line, 'CONNECTED NODES') !== false) {
                    continue;
                }

                if (preg_match_all('/\b\d{3,}\b/', $line, $matches)) {
                    foreach ($matches[0] as $candidate) {
                        if (in_array($candidate, $localNodes, true)) {
                            continue;
                        }

                        $found[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function localNodes(): array
    {
        $nodes = [];

        foreach (['MYNODE', 'DVSWITCH_NODE'] as $key) {
            $value = Config::workingConfigValue($key);

            if ($value === '') {
                continue;
            }

            $nodes[] = $value;
        }

        return array_values(array_unique($nodes));
    }

    private function favorites(): array
    {
        $path = '/var/www/html/alltune2/data/allstar_favorites.txt';

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($lines)) {
            return [];
        }

        $favorites = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            $target = $parts[0] ?? '';
            $stationName = $parts[1] ?? '';
            $description = $parts[2] ?? '';
            $location = $parts[3] ?? '';
            $mode = $parts[4] ?? '';

            if ($target === '') {
                continue;
            }

            $previewParts = array_filter(
                [$target, $stationName, $description, $location, $mode],
                static function (string $value): bool {
                    return $value !== '' && $value !== '-';
                }
            );

            $favorites[$target] = [
                'target' => $target,
                'station_name' => $stationName,
                'description' => $description,
                'location' => $location,
                'mode' => $mode,
                'preview' => implode(' - ', $previewParts),
            ];
        }

        return array_values($favorites);
    }

    private function notes(): array
    {
        return [
            MessageCatalog::get('allstar.note.connected_nodes'),
            MessageCatalog::get('allstar.note.favorites'),
            MessageCatalog::get('allstar.note.placeholder'),
        ];
    }

    private function placeholders(): array
    {
        return [
            'connected_nodes_empty' => MessageCatalog::get('allstar.connected_nodes_empty'),
            'favorites_empty' => MessageCatalog::get('allstar.favorites_empty'),
        ];
    }

    private function runCommand(string $command): string
    {
        if (!function_exists('shell_exec')) {
            return '';
        }

        $output = shell_exec($command);

        if (!is_string($output)) {
            return '';
        }

        return trim($output);
    }
}