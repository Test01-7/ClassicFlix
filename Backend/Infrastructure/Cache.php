<?php
declare(strict_types=1);

namespace ClassicFlix\Infrastructure;

use ClassicFlix\Support\Config;

/** Redis is an optimization: failures must never take the catalog offline. */
final class Cache
{
    private ?object $client;
    private bool $unavailable = false;

    public function __construct(private readonly Config $config, ?object $client = null)
    {
        $this->client = $client;
    }

    private function client(): ?object
    {
        if ($this->unavailable || !$this->config->bool('REDIS_ENABLED', false)) {
            return null;
        }
        if ($this->client !== null) {
            return $this->client;
        }
        if (!class_exists(\Predis\Client::class)) {
            $this->unavailable = true;
            return null;
        }
        $parameters = [
            'scheme' => $this->config->bool('REDIS_TLS', false) ? 'tls' : 'tcp',
            'host' => (string) $this->config->get('REDIS_HOST', '127.0.0.1'),
            'port' => (int) $this->config->get('REDIS_PORT', 6379),
            'database' => (int) $this->config->get('REDIS_DATABASE', 0),
            'timeout' => (float) $this->config->get('REDIS_TIMEOUT', 0.5),
            'read_write_timeout' => (float) $this->config->get('REDIS_TIMEOUT', 0.5),
        ];
        if ($password = $this->config->get('REDIS_PASSWORD', '')) {
            $parameters['password'] = (string) $password;
        }
        if ($this->config->bool('REDIS_TLS', false)) {
            $parameters['ssl'] = ['verify_peer' => true, 'verify_peer_name' => true];
        }
        return $this->client = new \Predis\Client($parameters);
    }

    public function remember(string $key, callable $loader, int $ttl = 300): mixed
    {
        try {
            $client = $this->client();
            $cached = $client?->get($key);
            if (is_string($cached)) {
                return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable) {
            $this->unavailable = true;
        }
        // Do not catch loader exceptions: database failures must remain visible.
        $value = $loader();
        try {
            $this->client()?->setex($key, max(1, $ttl), json_encode($value, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            $this->unavailable = true;
        }
        return $value;
    }

    public function forget(string $key): void
    {
        try {
            $this->client()?->del([$key]);
        } catch (\Throwable) {
            $this->unavailable = true;
        }
    }

    public function ping(): bool
    {
        try {
            $client = $this->client();
            return $client !== null && strtoupper((string) $client->ping()) === 'PONG';
        } catch (\Throwable) {
            $this->unavailable = true;
            return false;
        }
    }
}
