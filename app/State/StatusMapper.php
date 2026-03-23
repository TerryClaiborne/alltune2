<?php
declare(strict_types=1);

namespace App\State;

final class StatusMapper
{
    /**
     * Normalize scaffold/runtime status into one stable payload shape
     * for the AllTune2 dashboard.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function map(array $payload): array
    {
        $system = self::arrayValue($payload, 'system');
        $config = self::arrayValue($payload, 'config');
        $favorites = self::favoritesValue($payload['favorites'] ?? []);
        $allstar = self::arrayValue($payload, 'allstar');

        $selectedMode = self::normalizeMode(
            self::stringFirst(
                $system['selected_mode'] ?? null,
                $payload['selected_mode'] ?? null,
                'BM'
            )
        );

        $lastMode = self::normalizeMode(
            self::stringFirst(
                $system['last_mode'] ?? null,
                $payload['last_mode'] ?? null,
                ''
            )
        );

        $lastTarget = self::stringFirst(
            $system['last_target'] ?? null,
            $payload['last_target'] ?? null,
            ''
        );

        $pendingTarget = self::stringFirst(
            $system['pending_target'] ?? null,
            $payload['pending_target'] ?? null,
            $payload['pending_tg'] ?? null,
            ''
        );

        $statusText = self::stringFirst(
            $system['status_text'] ?? null,
            $payload['status_text'] ?? null,
            $payload['last_status'] ?? null,
            $payload['status'] ?? null,
            'IDLE - NO CONNECTIONS'
        );

        $dmrNetwork = self::normalizeMode(
            self::stringFirst(
                $system['dmr_network'] ?? null,
                $payload['dmr_network'] ?? null,
                ''
            )
        );

        $dmrReady = self::boolFirst(
            $system['dmr_ready'] ?? null,
            $payload['dmr_ready'] ?? null,
            false
        );

        $autoloadDvSwitch = self::boolFirst(
            $system['autoload_dvswitch'] ?? null,
            $payload['autoload_dvswitch'] ?? null,
            true
        );

        $bm = self::normalizeNetworkCard(
            self::arrayValue(self::arrayValue($payload, 'networks'), 'brandmeister'),
            'BrandMeister',
            self::deriveNetworkState('BM', $statusText, $lastMode, $lastTarget, $dmrNetwork, $dmrReady)
        );

        $tgif = self::normalizeNetworkCard(
            self::arrayValue(self::arrayValue($payload, 'networks'), 'tgif'),
            'TGIF',
            self::deriveNetworkState('TGIF', $statusText, $lastMode, $lastTarget, $dmrNetwork, $dmrReady)
        );

        $ysf = self::normalizeNetworkCard(
            self::arrayValue(self::arrayValue($payload, 'networks'), 'ysf'),
            'YSF',
            self::deriveNetworkState('YSF', $statusText, $lastMode, $lastTarget, $dmrNetwork, $dmrReady)
        );

        $allstarState = self::stringFirst(
            $allstar['label'] ?? null,
            $allstar['state'] ?? null,
            $allstar['status'] ?? null,
            self::deriveAllStarState($lastMode, $lastTarget)
        );

        $connectedNodes = self::connectedNodesValue($allstar['connected_nodes'] ?? []);
        $connectedNodesCount = count($connectedNodes);

        if ($connectedNodesCount === 0 && $lastMode === 'ASL' && $lastTarget !== '') {
            $connectedNodes = [
                [
                    'node' => $lastTarget,
                    'label' => 'Connected Node',
                ],
            ];
            $connectedNodesCount = 1;
        }

        $localNodes = self::stringListValue($allstar['local_nodes'] ?? []);
        if ($localNodes === []) {
            $localNodes = array_values(array_filter([
                self::stringFirst($config['mynode'] ?? null, ''),
                self::stringFirst($config['dvswitch_node'] ?? null, ''),
            ], static fn ($value): bool => $value !== ''));
        }

        return [
            'ok' => self::boolFirst($payload['ok'] ?? null, true),

            'system' => [
                'status_text' => $statusText,
                'selected_mode' => $selectedMode,
                'last_mode' => $lastMode,
                'last_target' => $lastTarget,
                'pending_target' => $pendingTarget,
                'autoload_dvswitch' => $autoloadDvSwitch,
                'dmr_network' => $dmrNetwork,
                'dmr_ready' => $dmrReady,
            ],

            'last_status' => $statusText,
            'status_text' => $statusText,
            'status' => $statusText,

            'selected_mode' => $selectedMode,
            'last_mode' => $lastMode,
            'last_target' => $lastTarget,
            'pending_target' => $pendingTarget,
            'autoload_dvswitch' => $autoloadDvSwitch,
            'dmr_network' => $dmrNetwork,
            'dmr_ready' => $dmrReady,

            'config' => [
                'path' => self::stringFirst($config['path'] ?? null, ''),
                'exists' => self::boolFirst($config['exists'] ?? null, false),
                'mynode' => self::stringFirst($config['mynode'] ?? null, ''),
                'dvswitch_node' => self::stringFirst($config['dvswitch_node'] ?? null, ''),
                'has_bm_password' => self::boolFirst($config['has_bm_password'] ?? null, false),
                'has_tgif_key' => self::boolFirst($config['has_tgif_key'] ?? null, false),
                'bm_password_masked' => self::stringFirst($config['bm_password_masked'] ?? null, ''),
                'tgif_key_masked' => self::stringFirst($config['tgif_key_masked'] ?? null, ''),
            ],

            'favorites' => $favorites,
            'favorites_count' => count($favorites),

            'networks' => [
                'brandmeister' => $bm,
                'tgif' => $tgif,
                'ysf' => $ysf,
            ],

            'brandmeister' => $bm,
            'tgif' => $tgif,
            'ysf' => $ysf,

            'allstar' => [
                'state' => $allstarState,
                'label' => $allstarState,
                'status' => $allstarState,
                'connected_nodes_count' => $connectedNodesCount,
                'connected_nodes' => $connectedNodes,
                'local_nodes' => $localNodes,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $network
     * @return array<string, mixed>
     */
    private static function normalizeNetworkCard(array $network, string $name, string $fallbackState): array
    {
        $state = self::stringFirst(
            $network['label'] ?? null,
            $network['state'] ?? null,
            $network['status'] ?? null,
            $fallbackState
        );

        return [
            'name' => $name,
            'state' => $state,
            'label' => $state,
            'status' => $state,
            'active' => self::boolFirst($network['active'] ?? null, $state !== 'Idle'),
        ];
    }

    private static function deriveNetworkState(
        string $network,
        string $statusText,
        string $lastMode,
        string $lastTarget,
        string $dmrNetwork,
        bool $dmrReady
    ): string {
        if ($network === 'BM' || $network === 'TGIF') {
            if (str_starts_with(strtoupper($statusText), 'WAITING:') && $dmrNetwork === $network) {
                return 'Preparing';
            }

            if ($lastMode === $network && $lastTarget !== '') {
                return 'Connected: TG ' . $lastTarget;
            }

            if ($dmrNetwork === $network && $dmrReady) {
                return 'Ready';
            }

            return 'Idle';
        }

        if ($network === 'YSF') {
            if ($lastMode === 'YSF' && $lastTarget !== '') {
                return 'Connected: ' . $lastTarget;
            }

            return 'Idle';
        }

        return 'Idle';
    }

    private static function deriveAllStarState(string $lastMode, string $lastTarget): string
    {
        if ($lastMode === 'ASL' && $lastTarget !== '') {
            return 'Connected: ' . $lastTarget;
        }

        return 'No links';
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, string>>
     */
    private static function favoritesValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $favorites = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $target = self::stringFirst($item['target'] ?? null, $item['tg'] ?? null, '');
            $name = self::stringFirst($item['name'] ?? null, '');
            $description = self::stringFirst($item['description'] ?? null, $item['desc'] ?? null, '-');
            $mode = self::normalizeMode(self::stringFirst($item['mode'] ?? null, 'BM'));

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

    /**
     * @param mixed $value
     * @return array<int, array<string, string>>
     */
    private static function connectedNodesValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nodes = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $node = self::stringFirst($item['node'] ?? null, '');
            $label = self::stringFirst($item['label'] ?? null, 'Connected Node');

            if ($node === '') {
                continue;
            }

            $nodes[] = [
                'node' => $node,
                'label' => $label,
            ];
        }

        return $nodes;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function stringListValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $string = trim((string) $item);
            if ($string !== '') {
                $items[] = $string;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    private static function normalizeMode(string $mode): string
    {
        $value = strtoupper(trim($mode));

        if ($value === 'ALLSTAR') {
            return 'ASL';
        }

        return $value;
    }

    private static function stringFirst(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private static function boolFirst(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (bool) $value;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));

                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }

                if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                    return false;
                }
            }
        }

        return false;
    }
}