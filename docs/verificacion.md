# Registro de verificación

## Comprobaciones efectuadas durante la implementación

- `php tests/run.php`: **154 comprobaciones aprobadas**: 24 de repositorios, 53 de almacenamiento/caché y 77 de aplicación HTTP con cookies y sesiones reales. Las tres suites usan datos aislados, sin conexiones a la infraestructura del propietario.
- El SDK oficial AWS instalado se utilizó para comprobar firmas y serialización de solicitudes con credenciales ficticias y sin efectuar solicitudes a AWS. Se comprobó también la continuidad de catálogo ante fallos de un doble Redis.
- Se recorrieron las ocho pantallas de muestra en el navegador y se revisaron composiciones de escritorio (1440 px) y teléfono (390 px) mediante un servidor de inspección local. Se ajustaron las medidas de etiquetas y campos para lectura móvil.
- `php scripts/test-repositories.php`: 24 comprobaciones aprobadas con SQLite en memoria; favoritos e historial por usuario, tokens idempotentes, borradores privados, búsqueda literal, recursos y estados de carga.
- `php scripts/test-mysql.php`: 25 comprobaciones aprobadas contra **MariaDB 10.4.32** de XAMPP en una instancia desechable, enlazada solo a `127.0.0.1:33317` y con datos en `var/mysql-test`.
- En esa instancia se ejecutó el SQL completo de instalación, la migración dos veces sobre una base nueva y dos veces sobre una estructura anterior con usuarios, películas, favoritos, vistas y reseñas. Se comprobó conservación de datos y la detención sin cambios ante favoritos duplicados.
- Se verificaron consultas PDO, restricciones, búsqueda con `%`/`_`, aislamiento entre usuarios, reemplazo de recursos, bloqueo de filas de carga y selección de cargas antiguas contra MariaDB real.
- El servidor desechable se apagó de forma normal después de las pruebas. No se usaron la base ni el directorio de datos habitual de XAMPP.
- Los archivos PHP de repositorios, migración y consola pasaron revisión sintáctica; las plantillas JSON de IAM, CORS y ciclo de vida pudieron analizarse.

## Pendiente de infraestructura del propietario

La verificación anterior no demuestra MySQL 8 específicamente ni conexiones reales con Redis y AWS. Deben ejecutarse en el entorno final las pruebas de IAM, CORS, cargas y reproducción contra S3, expiración/renovación de firmas, caída/recuperación de Redis, TLS y despliegue EC2 descritas en `aceptacion.md` y `aws.md`.

Las cuentas y títulos usados por las pruebas son ficticios y permanecen únicamente en la base desechable. No hay usuarios ni contenido de muestra sembrados en una base real por la aplicación.
