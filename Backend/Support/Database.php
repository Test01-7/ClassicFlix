<?php
declare(strict_types=1);

namespace ClassicFlix\Support;

use PDO;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private Config $config, ?PDO $connection = null)
    {
        $this->connection = $connection;
    }

    public function pdo(): PDO
    {
        if ($this->connection === null) {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false];
            if ($ca = $this->config->get('DB_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->get('DB_HOST', '127.0.0.1'), (int) $this->config->get('DB_PORT', 3306),
                $this->config->get('DB_DATABASE', 'ClassicFlix'));
            $this->connection = new PDO($dsn, (string) $this->config->get('DB_USERNAME', 'classicflix'),
                (string) $this->config->get('DB_PASSWORD', ''), $options);
            $this->connection->exec("SET time_zone = '+00:00'");
        }
        return $this->connection;
    }
}
