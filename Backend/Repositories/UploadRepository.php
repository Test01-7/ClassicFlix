<?php
declare(strict_types=1);
namespace ClassicFlix\Repositories;
use PDO;

final class UploadRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): void
    {
        $fields = ['id', 'usuario_id', 'pelicula_id', 'tipo', 'clave_s3', 'mime', 'tamano', 'multipart_id', 'part_size', 'fuente_url', 'referencia_derechos', 'estado'];
        $defaults = ['multipart_id' => null, 'part_size' => 10485760, 'fuente_url' => '', 'referencia_derechos' => '', 'estado' => 'pendiente'];
        $values = [];
        foreach ($fields as $field) {
            $values[] = array_key_exists($field, $data) ? $data[$field] : ($defaults[$field] ?? null);
        }
        $statement = $this->pdo->prepare('INSERT INTO Cargas (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')');
        $statement->execute($values);
    }

    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM Cargas WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findForUpdate(string $id): ?array
    {
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare('SELECT * FROM Cargas WHERE id = ?' . $suffix);
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function setState(string $id, string $state): void
    {
        if (!in_array($state, ['pendiente', 'verificando', 'completada', 'cancelada', 'fallida', 'limpiada'], true)) {
            throw new \InvalidArgumentException('Estado de carga no válido.');
        }
        $statement = $this->pdo->prepare('UPDATE Cargas SET estado = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$state, $id]);
    }

    public function setMultipartId(string $id, ?string $multipart): void
    {
        $statement = $this->pdo->prepare('UPDATE Cargas SET multipart_id = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$multipart, $id]);
    }

    public function stale(int $olderSeconds = 86400): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(3600, $olderSeconds));
        $statement = $this->pdo->prepare("SELECT * FROM Cargas WHERE estado IN ('pendiente', 'verificando', 'cancelada', 'fallida') AND actualizado_en < ? ORDER BY actualizado_en LIMIT 100");
        $statement->execute([$cutoff]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
