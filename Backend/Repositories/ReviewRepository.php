<?php
declare(strict_types=1);
namespace ClassicFlix\Repositories;

use PDO;

final class ReviewRepository
{
    public function __construct(private PDO $pdo) {}

    public function findOwn(int $userId, int $movieId): ?array
    {
        $query = $this->pdo->prepare('SELECT id, resena, valoracion, fecha, actualizado_en FROM Resenas WHERE usuario_id = ? AND pelicula_id = ?');
        $query->execute([$userId, $movieId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function paginate(int $movieId, int $page = 1): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM Resenas WHERE pelicula_id = ?');
        $count->execute([$movieId]);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / 10));
        $page = max(1, min($page, $pages));
        // Only the public author name is selected; emails never enter a public response.
        $query = $this->pdo->prepare('SELECT r.id, r.resena, r.valoracion, r.fecha, r.actualizado_en, u.nombre AS autor FROM Resenas r INNER JOIN Usuarios u ON u.id = r.usuario_id WHERE r.pelicula_id = ? ORDER BY r.fecha DESC, r.id DESC LIMIT ? OFFSET ?');
        $query->bindValue(1, $movieId, PDO::PARAM_INT);
        $query->bindValue(2, 10, PDO::PARAM_INT);
        $query->bindValue(3, ($page - 1) * 10, PDO::PARAM_INT);
        $query->execute();
        return ['items' => $query->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function saveOwn(int $userId, int $movieId, string $text, int $rating): void
    {
        $sql = 'INSERT INTO Resenas(usuario_id, pelicula_id, resena, valoracion, actualizado_en) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ';
        $values = [$userId, $movieId, $text, $rating];
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql .= 'ON CONFLICT(usuario_id, pelicula_id) DO UPDATE SET resena = excluded.resena, valoracion = excluded.valoracion, actualizado_en = CURRENT_TIMESTAMP';
        } else {
            $sql .= 'ON DUPLICATE KEY UPDATE resena = ?, valoracion = ?, actualizado_en = CURRENT_TIMESTAMP';
            array_push($values, $text, $rating);
        }
        $this->pdo->prepare($sql)->execute($values);
    }

    public function moderationPage(int $page = 1, string $search = ''): array
    {
        $from = ' FROM Resenas r INNER JOIN Usuarios u ON u.id = r.usuario_id INNER JOIN Peliculas p ON p.id = r.pelicula_id';
        $where = $search === '' ? '' : " WHERE (u.nombre LIKE ? ESCAPE '!' OR r.resena LIKE ? ESCAPE '!')";
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $params = $search === '' ? [] : [$term, $term];
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / 10));
        $page = max(1, min($page, $pages));
        $query = $this->pdo->prepare('SELECT r.id, r.pelicula_id, r.resena, r.valoracion, r.fecha, u.nombre AS autor, p.titulo AS pelicula' . $from . $where . ' ORDER BY r.fecha DESC, r.id DESC LIMIT ? OFFSET ?');
        foreach ($params as $i => $value) { $query->bindValue($i + 1, $value, PDO::PARAM_STR); }
        $query->bindValue(count($params) + 1, 10, PDO::PARAM_INT);
        $query->bindValue(count($params) + 2, ($page - 1) * 10, PDO::PARAM_INT);
        $query->execute();
        return ['items' => $query->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function deleteById(int $id): bool
    {
        $query = $this->pdo->prepare('DELETE FROM Resenas WHERE id = ?');
        $query->execute([$id]);
        return $query->rowCount() > 0;
    }

    public function deleteOwn(int $userId, int $movieId): bool
    {
        $query = $this->pdo->prepare('DELETE FROM Resenas WHERE usuario_id = ? AND pelicula_id = ?');
        $query->execute([$userId, $movieId]);
        return $query->rowCount() > 0;
    }
}
