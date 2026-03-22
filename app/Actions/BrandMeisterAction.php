<?php
declare(strict_types=1);

final class BrandMeisterAction implements ActionInterface
{
    public function connect(string $target): array
    {
        $target = trim($target);
        $privateNode = $this->privateNode();

        if ($target === '') {
            return [
                'ok' => false,
                'network' => 'BrandMeister',
                'action' => 'connect',
                'target' => '',
                'private_node' => $privateNode,
                'state' => AppState::FAILED,
                'message' => 'BrandMeister target is required',
                'command' => null,
            ];
        }

        if ($privateNode === '') {
            return [
                'ok' => false,
                'network' => 'BrandMeister',
                'action' => 'connect',
                'target' => $target,
                'private_node' => '',
                'state' => AppState::FAILED,
                'message' => 'BrandMeister private node is missing from config',
                'command' => null,
            ];
        }

        return [
            'ok' => false,
            'network' => 'BrandMeister',
            'action' => 'connect',
            'target' => $target,
            'private_node' => $privateNode,
            'state' => AppState::IDLE,
            'message' => 'BrandMeister connect action scaffold ready',
            'command' => $this->buildConnectCommand($privateNode, $target),
        ];
    }

    public function disconnect(): array
    {
        $privateNode = $this->privateNode();

        if ($privateNode === '') {
            return [
                'ok' => false,
                'network' => 'BrandMeister',
                'action' => 'disconnect',
                'target' => '',
                'private_node' => '',
                'state' => AppState::FAILED,
                'message' => 'BrandMeister private node is missing from config',
                'command' => null,
            ];
        }

        return [
            'ok' => false,
            'network' => 'BrandMeister',
            'action' => 'disconnect',
            'target' => '',
            'private_node' => $privateNode,
            'state' => AppState::IDLE,
            'message' => 'BrandMeister disconnect action scaffold ready',
            'command' => $this->buildDisconnectCommand($privateNode),
        ];
    }

    public function status(): array
    {
        $privateNode = $this->privateNode();

        return [
            'ok' => true,
            'network' => 'BrandMeister',
            'action' => 'status',
            'state' => AppState::IDLE,
            'message' => MessageCatalog::get(AppState::IDLE),
            'mode' => 'BM',
            'private_node' => $privateNode,
            'active_target' => '',
            'connect_command' => $privateNode !== ''
                ? $this->buildConnectCommand($privateNode, '__TARGET__')
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

    private function buildConnectCommand(string $privateNode, string $target): string
    {
        return 'BM_CONNECT ' . $privateNode . ' ' . $target;
    }

    private function buildDisconnectCommand(string $privateNode): string
    {
        return 'BM_DISCONNECT ' . $privateNode;
    }
}