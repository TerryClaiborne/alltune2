<?php
declare(strict_types=1);

interface ActionInterface
{
    public function connect(string $target): array;

    public function disconnect(): array;

    public function status(): array;
}