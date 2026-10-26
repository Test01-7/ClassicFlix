<?php
declare(strict_types=1);
namespace ClassicFlix\Repositories;
use PDO;

final class ResourceRepository
{
    public function __construct(private PDO $pdo) {}

    public function get(int $movieId, string $type): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM Recursos WHERE pelicula_id = ? AND tipo = ?');
        $query->execute([$movieId, $type]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function allForMovie(int $movieId): array
    {
        $query = $this->pdo->prepare('SELECT * FROM Recursos WHERE pelicula_id = ? ORDER BY tipo');
        $query->execute([$movieId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Caller owns the transaction. Returns replaced key for deletion after commit. */
    public function save(array $data): ?string
    {
        $suffix = $this->pdo->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $lock = $this->pdo->prepare('SELECT * FROM Recursos WHERE pelicula_id = ? AND tipo = ?' . $suffix);
        $lock->execute([(int) $data['pelicula_id'], (string) $data['tipo']]);
        $old = $lock->fetch(PDO::FETCH_ASSOC) ?: null;
        $values = [$data['clave_s3'], $data['mime'], $data['tamano'], $data['fuente_url'] ?? '', $data['referencia_derechos'] ?? ''];
        if ($old) {
            $query = $this->pdo->prepare('UPDATE Recursos SET clave_s3 = ?, mime = ?, tamano = ?, fuente_url = ?, referencia_derechos = ? WHERE id = ?');
            $query->execute([...$values, $old['id']]);
        } else {
            $query = $this->pdo->prepare('INSERT INTO Recursos (clave_s3, mime, tamano, fuente_url, referencia_derechos, pelicula_id, tipo) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $query->execute([...$values, $data['pelicula_id'], $data['tipo']]);
        }
        return $old ? (string) $old['clave_s3'] : null;
    }

    public function readyToPublish(int $movieId): bool
    {
        $query = $this->pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN tipo = 'portada' THEN 'portada' WHEN tipo IN ('trailer', 'pelicula') THEN 'video' END) FROM Recursos WHERE pelicula_id = ?");
        $query->execute([$movieId]);
        return (int) $query->fetchColumn() === 2;
    }
}
