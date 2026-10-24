<?php
declare(strict_types=1);
namespace ClassicFlix\Database;

use PDO;
use RuntimeException;

/** Additive migration: it never drops tables, user rows, reviews or existing media. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $root) {}

    public function migrate(): void
    {
        $this->preflight();
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->sqlite();
            return;
        }
        $sql = file_get_contents($this->root . '/BaseDatos.sql');
        if ($sql === false) {
            throw new RuntimeException('No se encontró BaseDatos.sql.');
        }
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);
        foreach (explode(';', (string) $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '' && !preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $statement)) {
                $this->pdo->exec($statement);
            }
        }
        $this->add('Usuarios', 'creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $columns = [
            'genero' => 'VARCHAR(100) NULL', 'director' => 'VARCHAR(100) NULL',
            'actores' => 'VARCHAR(255) NULL', 'sinopsis' => 'TEXT NULL',
            'duracion' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'trailer_duracion' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'calificacion' => 'DECIMAL(3,1) NOT NULL DEFAULT 0.0', 'categoria_id' => 'INT NULL',
            'estado' => "ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador'",
            'creado_en' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'actualizado_en' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];
        foreach ($columns as $column => $type) {
            $this->add('Peliculas', $column, $type);
        }
        $this->add('Vistas', 'playback_token', 'VARCHAR(64) NULL');
        $this->unique('Favoritos', 'uq_favorito', ['usuario_id', 'pelicula_id']);
        $this->unique('Vistas', 'uq_vista_token', ['usuario_id', 'playback_token']);
        $this->add('Resenas', 'valoracion', 'TINYINT UNSIGNED NULL');
        $this->add('Resenas', 'actualizado_en', 'DATETIME NULL');
        $this->unique('Resenas', 'uq_resena_usuario_pelicula', ['usuario_id', 'pelicula_id']);
        $fk = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Peliculas' AND COLUMN_NAME = 'categoria_id' AND REFERENCED_TABLE_NAME = 'Categorias'");
        $fk->execute();
        if ((int) $fk->fetchColumn() === 0) {
            $this->pdo->exec('ALTER TABLE Peliculas ADD CONSTRAINT fk_peliculas_categoria FOREIGN KEY (categoria_id) REFERENCES Categorias(id)');
        }
        $this->seedCategories();
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS Migraciones (version VARCHAR(50) PRIMARY KEY, aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $this->pdo->exec("INSERT IGNORE INTO Migraciones (version) VALUES ('001_capas_y_recursos')");
        $this->pdo->exec("INSERT IGNORE INTO Migraciones (version) VALUES ('002_resenas_y_valoraciones')");
    }

    private function preflight(): void
    {
        if ($this->exists('Resenas')) {
            $duplicates = $this->pdo->query('SELECT usuario_id, pelicula_id, COUNT(*) AS cantidad FROM Resenas GROUP BY usuario_id, pelicula_id HAVING COUNT(*) > 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if ($duplicates) {
                throw new RuntimeException('Hay reseñas duplicadas (usuario ' . $duplicates['usuario_id'] . ', película ' . $duplicates['pelicula_id'] . '). Haz una copia de seguridad y resuelve los duplicados antes de migrar; no se eliminó ninguna opinión.');
            }
        }
        if ($this->exists('Favoritos')) {
            $duplicates = $this->pdo->query('SELECT usuario_id, pelicula_id, COUNT(*) AS cantidad FROM Favoritos GROUP BY usuario_id, pelicula_id HAVING COUNT(*) > 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if ($duplicates) {
                throw new RuntimeException('Hay favoritos duplicados (usuario ' . $duplicates['usuario_id'] . ', película ' . $duplicates['pelicula_id'] . '). Haz una copia de seguridad y resuelve los duplicados antes de migrar; no se eliminó ningún dato.');
            }
        }
    }

    private function exists(string $table): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $query->execute([$table]);
            return (int) $query->fetchColumn() > 0;
        }
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]);
        return (int) $query->fetchColumn() > 0;
    }

    private function add(string $table, string $column, string $type): void
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $query->execute([$table, $column]);
        if ((int) $query->fetchColumn() === 0) {
            // Names and SQL definitions are fixed migration constants, never request input.
            $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
        }
    }

    private function unique(string $table, string $index, array $columns): void
    {
        $query = $this->pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $query->execute([$table]);
        $indexes = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
        }
        if (!in_array($columns, array_values($indexes), true)) {
            $this->pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$index` (" . implode(', ', $columns) . ')');
        }
    }

    private function seedCategories(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO Categorias (nombre) SELECT ? WHERE NOT EXISTS (SELECT 1 FROM Categorias WHERE nombre = ?)');
        foreach (['Drama', 'Comedia', 'Ciencia ficción', 'Suspenso', 'Terror', 'Animación', 'Aventura', 'Documental'] as $name) {
            $insert->execute([$name, $name]);
        }
        // Preserve descriptive genres and map the existing primary genre to a category.
        $rows = $this->pdo->query("SELECT DISTINCT genero FROM Peliculas WHERE genero IS NOT NULL AND genero <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $lookup = $this->pdo->prepare('SELECT id FROM Categorias WHERE nombre = ?');
        $map = $this->pdo->prepare('UPDATE Peliculas SET categoria_id = ? WHERE genero = ? AND categoria_id IS NULL');
        foreach ($rows as $name) {
            $original = (string) $name;
            $name = trim($original);
            if ($name === '') {
                continue;
            }
            $insert->execute([$name, $name]);
            $lookup->execute([$name]);
            $map->execute([(int) $lookup->fetchColumn(), $original]);
        }
    }

    /** SQLite is solely an isolated integration-test harness, never the production default. */
    private function sqlite(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS Usuarios (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, contrasena TEXT NOT NULL, rol TEXT NOT NULL DEFAULT 'usuario', creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS Categorias (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL UNIQUE);
CREATE TABLE IF NOT EXISTS Peliculas (id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT NOT NULL, fechalanzamiento INTEGER NOT NULL, genero TEXT, director TEXT, actores TEXT, sinopsis TEXT, duracion INTEGER NOT NULL DEFAULT 0, trailer_duracion INTEGER NOT NULL DEFAULT 0, calificacion REAL NOT NULL DEFAULT 0, categoria_id INTEGER REFERENCES Categorias(id), estado TEXT NOT NULL DEFAULT 'borrador', creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS Recursos (id INTEGER PRIMARY KEY AUTOINCREMENT, pelicula_id INTEGER NOT NULL REFERENCES Peliculas(id), tipo TEXT NOT NULL, clave_s3 TEXT NOT NULL, mime TEXT NOT NULL, tamano INTEGER NOT NULL, fuente_url TEXT NOT NULL DEFAULT '', referencia_derechos TEXT, UNIQUE(pelicula_id,tipo));
CREATE TABLE IF NOT EXISTS Favoritos (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL REFERENCES Usuarios(id), pelicula_id INTEGER NOT NULL REFERENCES Peliculas(id), UNIQUE(usuario_id,pelicula_id));
CREATE TABLE IF NOT EXISTS Vistas (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL REFERENCES Usuarios(id), pelicula_id INTEGER NOT NULL REFERENCES Peliculas(id), fecha TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, playback_token TEXT, UNIQUE(usuario_id,playback_token));
CREATE TABLE IF NOT EXISTS Resenas (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL REFERENCES Usuarios(id), pelicula_id INTEGER NOT NULL REFERENCES Peliculas(id), resena TEXT NOT NULL, valoracion INTEGER CHECK(valoracion IS NULL OR (valoracion BETWEEN 1 AND 5 AND valoracion = CAST(valoracion AS INTEGER))), fecha TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT);
CREATE TABLE IF NOT EXISTS Cargas (id TEXT PRIMARY KEY, usuario_id INTEGER NOT NULL REFERENCES Usuarios(id), pelicula_id INTEGER NOT NULL REFERENCES Peliculas(id), tipo TEXT NOT NULL, clave_s3 TEXT NOT NULL, mime TEXT NOT NULL, tamano INTEGER NOT NULL, multipart_id TEXT, part_size INTEGER NOT NULL DEFAULT 10485760, fuente_url TEXT, referencia_derechos TEXT, estado TEXT NOT NULL DEFAULT 'pendiente', creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
SQL;
        $this->pdo->exec($sql);
        $reviewColumns = $this->pdo->query('PRAGMA table_info(Resenas)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($reviewColumns, 'name');
        if (!in_array('valoracion', $names, true)) { $this->pdo->exec('ALTER TABLE Resenas ADD COLUMN valoracion INTEGER NULL'); }
        if (!in_array('actualizado_en', $names, true)) { $this->pdo->exec('ALTER TABLE Resenas ADD COLUMN actualizado_en TEXT NULL'); }
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_resena_usuario_pelicula ON Resenas(usuario_id, pelicula_id)');
        $this->seedCategories();
    }
}
