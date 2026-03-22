<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    private string $path;

    /**
     * @var array<string, string>
     */
    private array $values = [];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: dirname(__DIR__, 2) . '/config.ini';
        $this->values = $this->load($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return $default;
    }

    public function requireString(string $key): string
    {
        $value = $this->getString($key, '');

        if ($value === '') {
            throw new \RuntimeException(sprintf('Missing required config key: %s', $key));
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->getString($key, '') !== '';
    }

    /**
     * @return array<string, string>
     */
    private function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);

        if (!is_array($parsed)) {
            throw new \RuntimeException(sprintf('Unable to parse config file: %s', $path));
        }

        $values = [];

        foreach ($parsed as $key => $value) {
            $values[(string) $key] = $this->normalizeValue($value);
        }

        return $values;
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}