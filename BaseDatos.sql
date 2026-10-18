CREATE DATABASE IF NOT EXISTS 'ClassicFlix';
USE 'ClassicFlix';
CREATE TABLE IF NOT EXISTS 'Peliculas' (
    'id' INT AUTO_INCREMENT PRIMARY KEY,
    'titulo' VARCHAR(255) NOT NULL,
    'fechalanzamiento' INT NOT NULL,
    'genero' VARCHAR(100),
    'director' VARCHAR(100),
);

CREATE TABLE IF NOT EXISTS 'Usuarios' (
    'id' INT AUTO_INCREMENT PRIMARY KEY,
    'nombre' VARCHAR(100) NOT NULL,
    'email' VARCHAR(100) UNIQUE NOT NULL,
    'contrasena' VARCHAR(255) NOT NULL,
    'rol' ENUM('usuario', 'admin') DEFAULT 'usuario',
);

CREATE TABLE IF NOT EXISTS 'Resenas' (
    'id' INT AUTO_INCREMENT PRIMARY KEY,
    'usuario_id' INT NOT NULL,
    'pelicula_id' INT NOT NULL,
    'resena' TEXT NOT NULL,
    'fecha' DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY ('usuario_id') REFERENCES 'Usuarios'('id'),
    FOREIGN KEY ('pelicula_id') REFERENCES 'Peliculas'('id'),
);
