# ClassicFlix

Una sala de cine clásica con interfaz art déco en negro, marfil y dorado. PHP 8.2 sin framework, MySQL, S3 privado, caché Redis y configuración para EC2.

Los visitantes pueden explorar el catálogo y reproducir tráileres. Las películas completas, favoritos e historial requieren una cuenta; registrar contenidos y cargar archivos requiere ser administrador. La incorporación de películas completas está aprobada para el proyecto académico.

## Empezar en local

Requisitos: PHP 8.2 con `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` y `curl`; Composer; MySQL 8 o MariaDB 10.4+. Redis y una cuenta AWS se necesitan para verificar esas integraciones reales, no para la vista previa.

Desde la carpeta del proyecto:

```powershell
composer install
Copy-Item .env.example .env
```

Edita `.env` con la conexión MySQL y tus servicios. En XAMPP inicia MySQL desde su panel. La aplicación no incluye contraseña ni administrador predeterminados. Mantén `APP_URL` igual a la dirección desde la que abrirás el sitio.

Para una base nueva, con una cuenta MySQL con permiso de creación:

```powershell
php scripts/console.php install
php scripts/console.php create-admin
```

Si tu cuenta no puede crear bases, crea la base vacía con tu administrador MySQL, configura `DB_DATABASE` y ejecuta `php scripts/console.php migrate`. Para una instalación anterior, haz una copia de seguridad y usa ese mismo comando de migración; no reimportes el SQL encima de los datos.

```powershell
php -S localhost:8080 -t Frontend
```

Abre [ClassicFlix](http://localhost:8080/index.php?r=/catalogo). El directorio público es **únicamente `Frontend`**. No sirvas la raíz del repositorio: allí están la configuración, el backend y las herramientas. Los enlaces no necesitan reglas especiales de reescritura.

## Revisar el diseño sin servicios

Para abrir directamente la muestra, sin cambiar `.env` ni configurar servicios:

```powershell
php scripts/preview.php
```

Abre `http://127.0.0.1:8080/index.php?r=/preview`. Detén el servidor con Ctrl+C.

También puedes usar el servidor normal. En ese caso:

En `.env`, establece:

```dotenv
APP_ENV=local
APP_PREVIEW_ENABLED=true
```

Abre [la vista previa](http://localhost:8080/index.php?r=/preview). Permite recorrer login, registro, catálogo, detalles, reproductor, favoritos, historial y administración con datos ilustrativos. No crea cuentas reales, modifica datos, carga archivos ni simula una reproducción exitosa. Las rutas normales siguen usando servicios reales; una avería nunca cambia silenciosamente a datos ficticios.

En producción usa `APP_ENV=production` y `APP_PREVIEW_ENABLED=false`.

## Publicar el primer contenido

1. Crea un administrador por consola e inicia sesión con esa cuenta.
2. Guarda los datos del contenido como borrador. Las duraciones se almacenan en **segundos**; revisa la unidad de cada campo del formulario.
3. En edición, carga una portada y un tráiler e introduce la procedencia y referencia de derechos. La película completa es opcional y puede añadirse después.
4. Con portada y tráiler verificados, cambia el estado a publicado y guarda.

Admite MP4 preparado para HTML5 (H.264 y audio AAC cuando exista) y portadas JPEG/PNG/WebP. No transcodifica videos. Límites configurables: 100 MiB por tráiler, 5 MiB por portada y 2 GiB por película. Los archivos van directamente del navegador a S3: **no hace falta elevar los límites de carga de PHP al tamaño de una película**; PHP recibe metadatos y solicitudes de control.

Internet Archive y PDM permiten seleccionar archivos, pero su aparición allí no verifica automáticamente los derechos. Conserva la referencia de la versión elegida y revisa por separado música, subtítulos y portada.

## Reseñas y valoración del público

Cualquier cuenta registrada puede escribir una reseña y elegir de 1 a 5 estrellas para una película publicada, sin necesitar historial de reproducción. Cada cuenta tiene una opinión por película y puede editarla o eliminarla. Las reseñas son texto sin HTML, obligatorio y de hasta 2000 caracteres. Los visitantes ven el nombre público del autor, el texto, la fecha y las estrellas; no ven su correo.

En el panel, **Moderar reseñas** muestra una tabla con las opiniones más recientes de todas las películas, diez por página, y un buscador por nombre público del autor o palabras del texto. Un administrador puede retirar una opinión inapropiada después de confirmar la eliminación; se borran el texto y sus estrellas y se actualiza el promedio público. La búsqueda y página se conservan tras borrar. También puede moderar títulos devueltos a borrador. La acción exige sesión administrativa y CSRF; la muestra la mantiene desactivada. No requiere otra migración.

## Páginas del administrador

El menú abre páginas independientes y señala la sección actual. En celular y pantallas horizontales bajas se convierte en un menú compacto que permanece accesible al desplazarse.

| Página | Uso |
|---|---|
| `/admin` | Resumen y accesos a las tareas. |
| `/admin/peliculas` | Tabla con búsqueda por título; veinte películas por página, incluidos borradores. |
| `/admin/nuevo` | Registro de los datos iniciales. |
| `/admin/editar` | Edición de datos y regreso a la búsqueda de películas; requiere `id`. |
| `/admin/archivos` | Portada y videos de la película elegida; requiere `id`. |
| `/admin/resenas` | Tabla de moderación con búsqueda por autor o texto. |

Se accede mediante `index.php?r=…`; el identificador se añade como parámetro `id`. Los datos y archivos tienen pestañas propias. Las tablas se desplazan dentro de su área en pantallas estrechas y admiten teclado. La muestra reproduce las mismas páginas y búsquedas, con escrituras desactivadas.

Las tarjetas y fichas muestran el promedio con un decimal y el número de valoraciones. El catálogo permite combinar búsqueda, género, valoración mínima y orden por mejor valoradas. El mínimo se aplica al promedio mostrado; los empates se resuelven por cantidad de votos y luego por incorporación más reciente. Sin votos se muestra «Sin valoraciones» y el título se excluye al seleccionar un mínimo.

Para actualizar una instalación existente, haz una copia de seguridad y ejecuta `php scripts/console.php migrate` antes de abrir las páginas nuevas. Las reseñas anteriores conservan su texto y fecha, sin estrellas inventadas. Si existen dos reseñas de la misma cuenta para la misma película, la migración se detiene con un diagnóstico antes de cambiar datos. La columna histórica `calificacion` no se utiliza para calcular las valoraciones del público.

## Arquitectura por capas

- `Frontend`: punto de entrada y recursos públicos.
- `Backend/Views` y `Backend/Controllers`: presentación y control de solicitudes.
- `Backend/Services`: autenticación, catálogo, cargas y reproducción.
- `Backend/Repositories`: consultas PDO preparadas.
- `Backend/Infrastructure`: SDK S3 y Redis.
- `Backend/Support`: configuración, conexiones, sesiones y utilidades.
- `Backend/Database`: migración aditiva.

Redis conserva metadatos durante cinco minutos, sin enlaces firmados ni datos personales. Si falla, se consulta MySQL. Se usan contraseñas con hash, permisos comprobados en servidor y protección CSRF.

## Operación

```powershell
php scripts/console.php check
php scripts/console.php cleanup-uploads
```

La comprobación informa de MySQL, Redis y S3 sin imprimir secretos. Redis deshabilitado no equivale a una integración verificada. Ejecuta la limpieza al menos diariamente; procesa hasta 100 cargas por ejecución y puede repetirse. Configura también el ciclo de vida S3.

Consulta [instalación y migración](docs/instalacion.md), [S3 y EC2](docs/aws.md) y [pruebas de aceptación](docs/aceptacion.md). Las pruebas locales con dobles de servicios o SQLite no acreditan permisos ni reproducción contra AWS real.

Para partir de tu instancia Ubuntu y usar servicios administrados, sigue [despliegue paso a paso con RDS y ElastiCache](docs/despliegue-ubuntu-rds.md).

## Pruebas locales

```powershell
php tests/run.php
php scripts/test-repositories.php
php tests/storage.php
php tests/reviews.php
```

`tests/run.php` reúne cuatro suites locales: repositorios, reseñas/valoraciones, almacenamiento/caché y flujos HTTP con sesiones. Las demás órdenes permiten ejecutarlas por separado. La suite HTTP comprueba registro, autenticación, permisos, publicación, favoritos, historial, opiniones, filtros y vista previa; usa su propia base SQLite y servidores locales temporales. `php tests/run.php --mysql` añade las pruebas contra la instancia MariaDB desechable descrita a continuación; necesita que esa instancia esté iniciada.

La prueba de repositorios usa SQLite en memoria y no lee credenciales de `.env`. La prueba de almacenamiento usa dobles controlados de S3/Redis. `scripts/test-mysql.php` está restringido a un servidor desechable en `127.0.0.1:33317` cuyo datadir sea exactamente `var/mysql-test`; verifica ese dato antes de escribir y no se conecta a tu instalación normal. Consulta [el registro de verificación](docs/verificacion.md) para distinguir lo probado de lo pendiente.
