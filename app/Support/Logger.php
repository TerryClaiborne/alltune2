<?php
declare(strict_types=1);

final class Logger
{
    public static function info(string $message): void
    {
        error_log('[AllTune2] ' . $message);
    }
}