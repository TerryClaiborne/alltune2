<?php
declare(strict_types=1);

final class TGIFAction implements ActionInterface
{
    public function connect(string $target): array
    {
        $target = trim($target);
        $privateNode = $this->privateNode();
        $securityKey = $this->securityKey();

        if ($target === '') {
            return [
                'ok' => false,
                'network' => 'TGIF',
                'action' => 'connect',
                'target' => '',
                'private_node' => $privateNode,
                'state' => AppState::FAILED,
                'message' => 'TGIF target is required',
                'command' => null,
            ];
        }

        if ($privateNode === '') {
            return [
                'ok' => false,
                'network' => 'TGIF',
                'action' => 'connect',
                'target' => $target,
                'private_node' => '',
                'state' => AppState::FAILED,
                'message' => 'TGIF private node is missing from config',
                'command' => null,
            ];
        }

        if ($securityKey === '') {
            return [
                'ok' => false,
                'network' => 'TGIF',
                'action' => 'connect',
                'target' => $target,
                'private_node' => $privateNode,
                'state' => AppState::FAILED,
                'message' => 'TGIF security key is missing from config',
                'command' => null,
            ];
        }

        return [
            'ok' => false,
            'network' => 'TGIF',
            'action' => 'connect',
            'target' => $target,
            'private_node' => $privateNode,
            'state' => AppState::IDLE,
            'message' => 'TGIF connect action scaffold ready',
            'command' => $this->buildConnectCommand($privateNode, $target, $securityKey),
        ];
    }

    public function disconnect(): array
    {
        $privateNode = $this->privateNode();

        if ($privateNode === '') {
            return [
                'ok' => false,
                'network' => 'TGIF',
                'action' => 'disconnect',
                'target' => '',
                'private_node' => '',
                'state' => AppState::FAILED,
                'message' => 'TGIF private node is missing from config',
                'command' => null,
            ];
        }

        return [
            'ok' => false,
            'network' => 'TGIF',
            'action' => 'disconnect',
            'target' => '',
            'private_node' => $privateNode,
            'state' => AppState::IDLE,
            'message' => 'TGIF disconnect action scaffold ready',
            'command' => $this->buildDisconnectCommand($privateNode),
        ];
    }

    public function status(): array
    {
        $privateNode = $this->privateNode();
        $securityKey = $this->securityKey();

        return [
            'ok' => true,
            'network' => 'TGIF',
            'action' => 'status',
            'state' => AppState::IDLE,
            'message' => MessageCatalog::get(AppState::IDLE),
            'mode' => 'TGIF',
            'private_node' => $privateNode,
            'active_target' => '',
            'config_ready' => $privateNode !== '' && $securityKey !== '',
            'connect_command' => ($privateNode !== '' && $securityKey !== '')
                ? $this->buildStatusConnectCommand($privateNode)
                : '',
            'disconnect_command' => $privateNode !== ''
                ? $this->buildDisconnectCommand($privateNode)
                : '',
        ];
    }

    private function privateNode(): string
    {
        $privateNode = Config::workingConfigValue('MYNODE');

        if ($privateNode !== '') {
            return $privateNode;
        }

        return Config::myNode();
    }

    private function securityKey(): string
    {
        $key = Config::workingConfigValue('TGIF_HotspotSecurityKey');

        if ($key !== '') {
            return $key;
        }

        return Config::tgifSecurityKey();
    }

    private function buildConnectCommand(string $privateNode, string $target, string $securityKey): string
    {
        return 'TGIF_CONNECT ' . $privateNode . ' ' . $target . ' ' . $securityKey;
    }

    private function buildStatusConnectCommand(string $privateNode): string
    {
        return 'TGIF_CONNECT ' . $privateNode . ' __TARGET__ [SECURITY_KEY]';
    }

    private function buildDisconnectCommand(string $privateNode): string
    {
        return 'TGIF_DISCONNECT ' . $privateNode;
    }
}