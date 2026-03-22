<?php
declare(strict_types=1);

final class MainController
{
    public function index(): array
    {
        $manager = new NetworkManager();

        return [
            'app' => Config::appName(),
            'status' => AppState::SCAFFOLD_READY,
            'networks' => $manager->availableNetworks(),
            'adapters' => $manager->adapters(),
        ];
    }
}