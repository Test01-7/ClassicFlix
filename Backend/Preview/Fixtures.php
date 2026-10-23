<?php
declare(strict_types=1);

namespace ClassicFlix\Preview;

/** Fictional, read-only presentation data. Never used by repositories. */
final class Fixtures
{
    public static function movies(): array
    {
        $items = [
            [1, 'La última función', 1931, 1, 'Drama', 'Elena Valdés', 'Clara Montes, Emilio Rosas', 'En un antiguo teatro a punto de cerrar, una pianista y un proyeccionista preparan una última función. Entre recuerdos, cartas y una película olvidada, descubren que algunas despedidas también pueden ser un comienzo.', 5340, 118, 'funcion'],
            [2, 'Ciudad de cristal', 1927, 2, 'Ciencia ficción', 'Arturo Beltrán', 'Lucía Vidal, Tomás Ferrer', 'Una arquitecta llega a una ciudad que crece hacia el cielo. Detrás de sus grandes promesas, encuentra una sociedad dividida y una amistad capaz de cambiar la forma de mirar el futuro.', 6120, 135, 'ciudad'],
            [3, 'El expreso de medianoche', 1934, 3, 'Misterio', 'Julián Arce', 'Inés Duarte, Ramón Oliva', 'Un tren atraviesa la noche con un pasajero de más y un destino incierto. Cuando desaparece una carta, una joven periodista descubre que todos los viajeros guardan una historia que preferirían no contar.', 5100, 105, 'expreso'],
            [4, 'Un verano en París', 1932, 4, 'Romance', 'Margot Delacour', 'Alicia Durán, Andrés Legrand', 'Un encuentro junto al Sena cambia los planes de dos viajeros. Con un mapa incompleto y un verano por delante, deciden dejarse llevar por una ciudad donde perderse puede ser la mejor manera de encontrarse.', 5520, 126, 'paris'],
            [5, 'El jardín secreto', 1929, 1, 'Drama', 'Leonor Salvatierra', 'Amelia Soler, Gabriel Ríos', 'Al regresar a la casa de su infancia, una joven encuentra una llave y un jardín que nadie recuerda. Cada puerta que abre revela una parte de la historia familiar que creía conocer.', 4980, 113, 'jardin'],
            [6, 'Bajo la misma luna', 1936, 4, 'Romance', 'Mateo Alcázar', 'Elisa Marín, Víctor Campos', 'Dos desconocidos se encuentran cada noche en una estación costera. Unidos por una promesa y separados por sus propios planes, descubren que hay historias que solo se entienden mirando el mismo cielo.', 5760, 122, 'luna'],
        ];
        return array_map(static function (array $row): array {
            return [
                'id' => $row[0], 'titulo' => $row[1], 'fechalanzamiento' => $row[2], 'categoria_id' => $row[3], 'categoria' => $row[4], 'genero' => $row[4],
                'director' => $row[5], 'actores' => $row[6], 'sinopsis' => $row[7], 'duracion' => $row[8], 'trailer_duracion' => $row[9],
                'poster_url' => \asset('art/poster-' . $row[10] . '.svg'), 'has_poster' => true, 'has_trailer' => true, 'has_movie' => $row[0] !== 5,
                'estado' => $row[0] === 5 ? 'borrador' : 'publicado', 'is_favorite' => in_array($row[0], [1, 4, 6], true),
            ];
        }, $items);
    }

    public static function categories(): array
    {
        return [['id' => 1, 'nombre' => 'Drama'], ['id' => 2, 'nombre' => 'Ciencia ficción'], ['id' => 3, 'nombre' => 'Misterio'], ['id' => 4, 'nombre' => 'Romance']];
    }

    public static function user(): array
    {
        return ['id' => 1, 'nombre' => 'Isabel Moreno', 'email' => 'muestra@example.test', 'rol' => 'admin'];
    }

    public static function stats(): array
    {
        return ['peliculas' => 6, 'usuarios' => 24, 'categorias' => 4];
    }
}
