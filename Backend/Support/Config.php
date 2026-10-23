<?php
declare(strict_types=1);

namespace ClassicFlix\Support;

final class Config
{
    public function __construct(private array $values = []) {}

    public static function load(string $path): self
    {
        $values = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $key)) {
                    continue;
                }
                if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                $values[$key] = $value;
                if (getenv($key) === false) {
                    putenv($key . '=' . $value);
                }
            }
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $external = getenv($key);
        return $external !== false ? $external : ($this->values[$key] ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function require(string $key): string
    {
        $value = (string) $this->get($key, '');
        if ($value === '') {
            throw new \RuntimeException('Falta configurar ' . $key . ' en el entorno.');
        }
        return $value;
    }

    public function previewEnabled(): bool
    {
        return $this->get('APP_ENV', 'production') === 'local' && $this->bool('APP_PREVIEW_ENABLED');
    }
}
