<?php
declare(strict_types=1);
namespace ClassicFlix\Repositories;
use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT id, nombre, email, rol, creado_en FROM Usuarios WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM Usuarios WHERE email = ?');
        $query->execute([mb_strtolower(trim($email))]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(string $nombre, string $email, string $passwordHash, string $role = 'usuario'): int
    {
        if (!in_array($role, ['usuario', 'admin'], true)) {
            throw new \InvalidArgumentException('Rol no válido.');
        }
        $query = $this->pdo->prepare('INSERT INTO Usuarios (nombre, email, contrasena, rol) VALUES (?, ?, ?, ?)');
        $query->execute([$nombre, mb_strtolower(trim($email)), $passwordHash, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM Usuarios')->fetchColumn();
    }
}
