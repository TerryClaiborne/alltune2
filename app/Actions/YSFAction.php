<?php
declare(strict_types=1);

final class YSFAction implements ActionInterface
{
    public function connect(string $target): array
    {
        $target = trim($target);
        $privateNode = $this->privateNode();

        if ($target === '') {
            return [
                'ok' => false,
                'network' => 'YSF',
                'action' => 'connect',
                'target' => '',
                'private_node' => $privateNode,
                'state' => AppState::FAILED,
                'message' => 'YSF target is required',
                'command' => null,
            ];
        }

        if ($privateNode === '') {
            return [
                'ok' => false,
                'network' => 'YSF',
                'action' => 'connect',
                'target' => $target,
                'private_node' => '',
                'state' => AppState::FAILED,
                'message' => 'YSF private node is missing from config',
                'command' => null,
            ];
        }

        return [
            'ok' => false,
            'network' => 'YSF',
            'action' => 'connect',
            'target' => $target,
            'private_node' => $privateNode,
            'state' => AppState::IDLE,
            'message' => 'YSF connect action scaffold ready',
            'command' => $this->buildConnectCommand($privateNode, $target),
        ];
    }

    public function disconnect(): array
    {
        $privateNode = $this->privateNode();

        if ($privateNode === '') {
            return [
                'ok' => false,
                'network' => 'YSF',
                'action' => 'disconnect',
                'target' => '',
                'private_node' => '',
                'state' => AppState::FAILED,
                'message' => 'YSF private node is missing from config',
                'command' => null,
            ];
        }

        return [
            'ok' => false,
            'network' => 'YSF',
            'action' => 'disconnect',
            'target' => '',
            'private_node' => $privateNode,
            'state' => AppState::IDLE,
            'message' => 'YSF disconnect action scaffold ready',
            'command' => $this->buildDisconnectCommand($privateNode),
        ];
    }

    public function status(): array
    {
        $privateNode = $this->privateNode();

        return [
            'ok' => true,
            'network' => 'YSF',
            'action' => 'status',
            'state' => AppState::IDLE,
            'message' => MessageCatalog::get(AppState::IDLE),
            'mode' => 'YSF',
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
        return 'YSF_CONNECT ' . $privateNode . ' ' . $target;
    }

    private function buildDisconnectCommand(string $privateNode): string
    {
        return 'YSF_DISCONNECT ' . $privateNode;
    }
}