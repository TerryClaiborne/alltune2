<?php
declare(strict_types=1);

final class NetworkManager
{
    public function availableNetworks(): array
    {
        return array_keys($this->adapters());
    }

    public function adapters(): array
    {
        return [
            'BrandMeister' => [
                'name' => 'BrandMeister',
                'action' => new BrandMeisterAction(),
            ],
            'TGIF' => [
                'name' => 'TGIF',
                'action' => new TGIFAction(),
            ],
            'AllStar' => [
                'name' => 'AllStar',
                'action' => new AllStarAction(),
            ],
            'YSF' => [
                'name' => 'YSF',
                'action' => new YSFAction(),
            ],
        ];
    }

    public function adapter(string $network): ?array
    {
        $adapters = $this->adapters();

        return $adapters[$network] ?? null;
    }

    public function actions(): array
    {
        $actions = [];

        foreach ($this->adapters() as $network => $adapter) {
            if (($adapter['action'] ?? null) instanceof ActionInterface) {
                $actions[$network] = $adapter['action'];
            }
        }

        return $actions;
    }

    public function action(string $network): ?ActionInterface
    {
        $adapter = $this->adapter($network);

        if (!$adapter) {
            return null;
        }

        $action = $adapter['action'] ?? null;

        return $action instanceof ActionInterface ? $action : null;
    }
}