-- Instalación nueva. Para actualizar una instalación: php scripts/console.php migrate
CREATE DATABASE IF NOT EXISTS ClassicFlix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ClassicFlix;

CREATE TABLE IF NOT EXISTS Usuarios (
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(100) NOT NULL,
 email VARCHAR(100) NOT NULL UNIQUE,
 contrasena VARCHAR(255) NOT NULL,
 rol ENUM('usuario','admin') NOT NULL DEFAULT 'usuario',
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Categorias (
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Peliculas (
 id INT AUTO_INCREMENT PRIMARY KEY,
 titulo VARCHAR(255) NOT NULL,
 fechalanzamiento INT NOT NULL,
 genero VARCHAR(100),
 director VARCHAR(100),
 actores VARCHAR(255),
 sinopsis TEXT,
 duracion INT UNSIGNED NOT NULL DEFAULT 0, -- segundos de la película completa
 trailer_duracion INT UNSIGNED NOT NULL DEFAULT 0,
 calificacion DECIMAL(3,1) NOT NULL DEFAULT 0.0,
 categoria_id INT NULL,
 estado ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador',
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_peliculas_categoria FOREIGN KEY (categoria_id) REFERENCES Categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Recursos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 pelicula_id INT NOT NULL,
 tipo ENUM('portada','trailer','pelicula') NOT NULL,
 clave_s3 VARCHAR(512) NOT NULL,
 mime VARCHAR(100) NOT NULL,
 tamano BIGINT UNSIGNED NOT NULL,
 fuente_url VARCHAR(2048) NOT NULL DEFAULT '',
 referencia_derechos TEXT,
 UNIQUE KEY uq_recurso_tipo (pelicula_id,tipo),
 CONSTRAINT fk_recursos_pelicula FOREIGN KEY (pelicula_id) REFERENCES Peliculas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Favoritos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 pelicula_id INT NOT NULL,
 UNIQUE KEY uq_favorito (usuario_id,pelicula_id),
 CONSTRAINT fk_favoritos_usuario FOREIGN KEY (usuario_id) REFERENCES Usuarios(id),
 CONSTRAINT fk_favoritos_pelicula FOREIGN KEY (pelicula_id) REFERENCES Peliculas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Vistas (
 id INT AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 pelicula_id INT NOT NULL,
 fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 playback_token VARCHAR(64) NULL,
 UNIQUE KEY uq_vista_token (usuario_id,playback_token),
 KEY ix_vistas_usuario_fecha (usuario_id,fecha),
 CONSTRAINT fk_vistas_usuario FOREIGN KEY (usuario_id) REFERENCES Usuarios(id),
 CONSTRAINT fk_vistas_pelicula FOREIGN KEY (pelicula_id) REFERENCES Peliculas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las cargas no se convierten en recursos hasta verificar el archivo en S3.
CREATE TABLE IF NOT EXISTS Cargas (
 id CHAR(32) PRIMARY KEY,
 usuario_id INT NOT NULL,
 pelicula_id INT NOT NULL,
 tipo VARCHAR(16) NOT NULL,
 clave_s3 VARCHAR(512) NOT NULL,
 mime VARCHAR(100) NOT NULL,
 tamano BIGINT UNSIGNED NOT NULL,
 multipart_id TEXT NULL,
 part_size INT UNSIGNED NOT NULL DEFAULT 10485760,
 fuente_url TEXT,
 referencia_derechos TEXT,
 estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_cargas_usuario FOREIGN KEY (usuario_id) REFERENCES Usuarios(id),
 CONSTRAINT fk_cargas_pelicula FOREIGN KEY (pelicula_id) REFERENCES Peliculas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
