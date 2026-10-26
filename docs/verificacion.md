# Registro de verificación

## Comprobaciones efectuadas durante la implementación (23/10/2026)

- `php tests/run.php`: **295 comprobaciones aprobadas**: 24 de repositorios, 67 de reseñas y valoraciones, 53 de almacenamiento/caché y 151 de aplicación HTTP con cookies y sesiones reales. Las cuatro suites usan datos aislados, sin conexiones a la infraestructura del propietario.
- La separación del panel se comprobó mediante rutas HTTP y vista previa: resumen sin formularios/tablas de otras secciones, películas con búsqueda por título, datos y archivos en páginas propias y tabla de reseñas con búsqueda por autor/texto. Se probaron los permisos de cada ruta y la conservación de búsqueda y paginación al editar o moderar.
- Se revisó el panel en 1440 × 1000, 390 × 844 y 844 × 390: navegación activa, menú compacto persistente, apertura con Enter y cierre con Escape, tablas con desplazamiento interno y ausencia de desbordamiento horizontal de página. Capturas: `var/preview-admin-tabla-resenas.png`, `var/preview-admin-busqueda-peliculas.png` y `var/preview-admin-horizontal.png`.
- La moderación administrativa añade pruebas de permisos en servicio y solicitudes directas, POST/CSRF, identificadores inválidos, paginación, borrado de opiniones ajenas y títulos devueltos a borrador, actualización del agregado e invalidación de la caché. No incorpora cambios de esquema.
- Se revisó «Moderar reseñas» en escritorio y celular, incluida la confirmación mediante teclado y su desactivación en la muestra. Se corrigió el desplazamiento horizontal de la página móvil causado por una etiqueta accesible de la tabla; la tabla y la navegación conservan su desplazamiento interno. Captura: `var/preview-moderacion-escritorio.png`.
- El SDK oficial AWS instalado se utilizó para comprobar firmas y serialización de solicitudes con credenciales ficticias y sin efectuar solicitudes a AWS. Se comprobó también la continuidad de catálogo ante fallos de un doble Redis.
- Se recorrieron las ocho pantallas de muestra en el navegador y se revisaron composiciones de escritorio (1440 px) y teléfono (390 px) mediante un servidor de inspección local. Se ajustaron las medidas de etiquetas y campos para lectura móvil.
- La ampliación de opiniones se revisó en navegador a 1440 y 390 px: ficha, formulario de estrellas, tarjetas y filtros. Se comprobó el foco visible de teclado, el cambio de género conservando mínimo/orden y el formulario desactivado en la muestra. Las capturas locales están en `var/preview-resenas-escritorio.png`, `var/preview-resenas-celular.png` y `var/preview-filtros-escritorio.png`.
- Se comprobaron creación, edición y eliminación propias sin reproducción previa, identidad tomada de la sesión, CSRF, texto escapado, ausencia de correos públicos, paginación de diez opiniones y rechazo de puntuaciones inválidas, textos vacíos/largos y borradores. Se verificaron promedios redondeados, votos, ausencia de valoraciones, filtros combinados, desempates y agregados coherentes en favoritos e historial.
- La caché usa una clave central nueva para los agregados: se comprobó su invalidación al guardar/eliminar opiniones y al actualizar contenido, y la continuidad sin Redis mediante un doble controlado. Las opiniones y datos personales quedan fuera de ella.
- `php scripts/test-repositories.php`: 24 comprobaciones aprobadas con SQLite en memoria; favoritos e historial por usuario, tokens idempotentes, borradores privados, búsqueda literal, recursos y estados de carga.
- `php scripts/test-mysql.php`: **41 comprobaciones aprobadas** contra **MariaDB 10.4.32** de XAMPP en una instancia desechable, enlazada solo a `127.0.0.1:33317` y con datos en `var/mysql-test`. También se ejecutó conjuntamente mediante `php tests/run.php --mysql`, incluidas las búsquedas administrativas con consultas preparadas nativas.
- En esa instancia se ejecutó el SQL completo de instalación, la migración dos veces sobre una base nueva y dos veces sobre una estructura anterior con usuarios, películas, favoritos, vistas y reseñas. Se comprobó conservación de texto/fecha, valoraciones antiguas nulas y la detención sin cambios ante favoritos o reseñas duplicadas. También se verificaron el guardado único, edición, eliminación, promedio y paginación con consultas preparadas nativas de MariaDB.
- Se verificaron consultas PDO, restricciones, búsqueda con `%`/`_`, aislamiento entre usuarios, reemplazo de recursos, bloqueo de filas de carga y selección de cargas antiguas contra MariaDB real.
- El servidor desechable se apagó de forma normal después de las pruebas. No se usaron la base ni el directorio de datos habitual de XAMPP.
- Los archivos PHP de repositorios, migración y consola pasaron revisión sintáctica; las plantillas JSON de IAM, CORS y ciclo de vida pudieron analizarse.

## Campos opcionales y publicación sin tráiler — 26 de octubre de 2026

`php tests/run.php`: **319 comprobaciones locales aprobadas** (24 repositorios, 67 reseñas, 62 almacenamiento/caché y 166 HTTP). Se ejecutaron con PHP 8.2.12, SQLite aislado, dobles de S3/Redis y firmas del SDK AWS sin solicitudes remotas. El entorno restringido impidió inicialmente el acceso del SDK a sus archivos y la conexión al servidor temporal; la ejecución autorizada fuera de esa restricción completó las cuatro suites.

Se comprobaron las ocho combinaciones de portada/tráiler/película para publicar, persistencia de procedencia/derechos vacíos, formularios sin obligatoriedad en esos campos, sinopsis vacía y corta, límite de longitud, duración de tráiler vacía e inválida y publicación HTTP con portada/película sin tráiler. La ficha oculta el enlace al tráiler ausente y muestra «Sinopsis no disponible.» cuando corresponde. La reproducción completa y su firma continúan exigiendo sesión.

No hay cambios de esquema ni dependencias. Las pruebas no se conectaron a RDS, EC2 ni S3 reales y no modificaron su configuración.

## Integración local de miniaturas Lambda — 26 de octubre de 2026

- `php tests/run.php`: **334 comprobaciones aprobadas** (24 repositorios, 67 reseñas, 77 almacenamiento/caché y 166 HTTP). Incluye selección y firma de la miniatura, formato esperado, ausencia/objeto vacío/error de S3, desactivación sin HEAD y rechazo de borradores antes de consultar S3.
- `python tests/lambda_thumbnails.py`: **11 pruebas aprobadas**, con Pillow 12.2.0 real y cliente S3 ficticio. Verifica JPEG/PNG/WebP, tamaño/proporciones, transparencia, orientación y retirada de EXIF, claves deterministas, eventos repetidos, codificación URL, límites de bytes/píxeles, archivos inválidos, eventos ignorados y propagación de errores AWS para reintentar.
- `python scripts/build-lambda.py --layer`: generados los ZIP del código y de Pillow CPython 3.13/Linux x86_64. Las plantillas JSON se analizaron correctamente. La compilación de la capa seleccionó la rueda Linux desde PyPI; no instaló ese binario en el Python local ni accedió a AWS.
- No se aplicaron cambios en la cuenta AWS. La importación real de la capa dentro de Lambda, IAM, desencadenador y selección desde el catálogo desplegado deben comprobarse con una portada nueva siguiendo `lambda-miniaturas.md`.

## Pendiente de infraestructura del propietario

La verificación anterior no demuestra MySQL 8 específicamente ni conexiones reales con Redis y AWS. Deben ejecutarse en el entorno final las pruebas de IAM, CORS, cargas y reproducción contra S3, expiración/renovación de firmas, caída/recuperación de Redis, TLS y despliegue EC2 descritas en `aceptacion.md` y `aws.md`.

Las cuentas y títulos usados por las pruebas son ficticios y permanecen únicamente en la base desechable. No hay usuarios ni contenido de muestra sembrados en una base real por la aplicación.
