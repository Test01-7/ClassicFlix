<?php
declare(strict_types=1);
namespace ClassicFlix\Repositories;
use PDO;
use PDOException;

final class MovieRepository
{
    private const SELECT = 'SELECT p.*, c.nombre AS categoria, rp.clave_s3 AS poster_key,
        rt.id AS trailer_resource_id, rm.id AS movie_resource_id FROM Peliculas p
        LEFT JOIN Categorias c ON c.id = p.categoria_id
        LEFT JOIN Recursos rp ON rp.pelicula_id = p.id AND rp.tipo = \'portada\'
        LEFT JOIN Recursos rt ON rt.pelicula_id = p.id AND rt.tipo = \'trailer\'
        LEFT JOIN Recursos rm ON rm.pelicula_id = p.id AND rm.tipo = \'pelicula\' ';

    public function __construct(private PDO $pdo) {}

    public function all(string $query = '', ?int $categoryId = null, bool $publishedOnly = true): array
    {
        $conditions = [];
        $params = [];
        if ($publishedOnly) {
            $conditions[] = "p.estado = 'publicado'";
        }
        if ($query !== '') {
            $conditions[] = "(p.titulo LIKE ? ESCAPE '!' OR p.director LIKE ? ESCAPE '!' OR p.actores LIKE ? ESCAPE '!')";
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            array_push($params, $term, $term, $term);
        }
        if ($categoryId !== null) {
            $conditions[] = 'p.categoria_id = ?';
            $params[] = $categoryId;
        }
        return $this->rows(self::SELECT . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') . ' ORDER BY p.id DESC', $params);
    }

    public function find(int $id, bool $publishedOnly = true): ?array
    {
        $rows = $this->rows(self::SELECT . ' WHERE p.id = ?' . ($publishedOnly ? " AND p.estado = 'publicado'" : ''), [$id]);
        return $rows[0] ?? null;
    }

    public function categories(): array
    {
        return $this->pdo->query('SELECT id, nombre FROM Categorias ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $fields = ['titulo', 'fechalanzamiento', 'genero', 'director', 'actores', 'sinopsis', 'duracion', 'trailer_duracion', 'categoria_id'];
        $defaults = ['genero' => '', 'director' => '', 'actores' => '', 'sinopsis' => '', 'duracion' => 0, 'trailer_duracion' => 0, 'categoria_id' => null];
        $values = [];
        foreach ($fields as $field) {
            $values[] = $data[$field] ?? ($defaults[$field] ?? '');
        }
        if ($id !== null) {
            if ($this->find($id, false) === null) {
                throw new \InvalidArgumentException('El contenido no existe.');
            }
            $assignments = implode(', ', array_map(static fn(string $field): string => "$field = ?", $fields));
            $statement = $this->pdo->prepare("UPDATE Peliculas SET $assignments, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?");
            $statement->execute([...$values, $id]);
            return $id;
        }
        $statement = $this->pdo->prepare('INSERT INTO Peliculas (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')');
        $statement->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function setState(int $movieId, string $state): void
    {
        if (!in_array($state, ['borrador', 'publicado'], true)) {
            throw new \InvalidArgumentException('Estado no válido.');
        }
        $statement = $this->pdo->prepare('UPDATE Peliculas SET estado = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$state, $movieId]);
    }

    public function favorites(int $userId): array
    {
        return $this->rows(self::SELECT . " INNER JOIN Favoritos f ON f.pelicula_id = p.id WHERE f.usuario_id = ? AND p.estado = 'publicado' ORDER BY f.id DESC", [$userId]);
    }

    public function history(int $userId): array
    {
        $sql = str_replace('SELECT p.*', 'SELECT h.viewed_at, p.*', self::SELECT);
        return $this->rows($sql . " INNER JOIN (SELECT pelicula_id, MAX(fecha) AS viewed_at FROM Vistas WHERE usuario_id = ? GROUP BY pelicula_id) h ON h.pelicula_id = p.id WHERE p.estado = 'publicado' ORDER BY h.viewed_at DESC, p.id DESC", [$userId]);
    }

    public function isFavorite(int $userId, int $movieId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM Favoritos WHERE usuario_id = ? AND pelicula_id = ?');
        $statement->execute([$userId, $movieId]);
        return $statement->fetchColumn() !== false;
    }

    public function toggleFavorite(int $userId, int $movieId): bool
    {
        if ($this->isFavorite($userId, $movieId)) {
            $statement = $this->pdo->prepare('DELETE FROM Favoritos WHERE usuario_id = ? AND pelicula_id = ?');
            $statement->execute([$userId, $movieId]);
            return false;
        }
        try {
            $statement = $this->pdo->prepare('INSERT INTO Favoritos (usuario_id, pelicula_id) VALUES (?, ?)');
            $statement->execute([$userId, $movieId]);
        } catch (PDOException $exception) {
            if (!$this->isFavorite($userId, $movieId)) {
                throw $exception;
            }
        }
        return true;
    }

    public function recordView(int $userId, int $movieId, string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/D', $token)) {
            throw new \InvalidArgumentException('Identificador de reproducción no válido.');
        }
        try {
            $statement = $this->pdo->prepare('INSERT INTO Vistas (usuario_id, pelicula_id, playback_token) VALUES (?, ?, ?)');
            $statement->execute([$userId, $movieId, $token]);
        } catch (PDOException $exception) {
            $existing = $this->pdo->prepare('SELECT pelicula_id FROM Vistas WHERE usuario_id = ? AND playback_token = ?');
            $existing->execute([$userId, $token]);
            if ((int) $existing->fetchColumn() !== $movieId) {
                throw $exception;
            }
        }
    }

    public function stats(): array
    {
        return [
            'peliculas' => (int) $this->pdo->query('SELECT COUNT(*) FROM Peliculas')->fetchColumn(),
            'usuarios' => (int) $this->pdo->query('SELECT COUNT(*) FROM Usuarios')->fetchColumn(),
            'categorias' => (int) $this->pdo->query('SELECT COUNT(*) FROM Categorias')->fetchColumn(),
        ];
    }

    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(static function(array $row): array {
            $row['has_trailer'] = $row['trailer_resource_id'] !== null;
            $row['has_movie'] = $row['movie_resource_id'] !== null;
            unset($row['trailer_resource_id'], $row['movie_resource_id']);
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
