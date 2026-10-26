# S3 privado y EC2

La guía prepara recursos para tu cuenta; el código no crea buckets, instancias ni servicios administrados. Sustituye `BUCKET_ELEGIDO`, `https://cine.ejemplo.com` y las rutas de ejemplo antes de aplicar plantillas.

Para reutilizar tus recursos, sigue la [guía con EC2, RDS y S3, con caché opcional](despliegue-ubuntu-rds.md). La consigna compartida exige MySQL y S3, pero no ElastiCache ni RDS como productos concretos; tu RDS ya satisface la parte MySQL. El ejemplo de despliegue comienza con Redis desactivado.

## Bucket y permisos

1. Crea el bucket en `AWS_REGION` y conserva **Block Public Access** activado por completo.
2. Usa Object Ownership **Bucket owner enforced**, con ACL deshabilitadas. No se usa `public-read`.
3. Conserva cifrado predeterminado SSE-S3. Si eliges SSE-KMS, configura aparte los permisos de la clave; estas plantillas corresponden a SSE-S3.
4. Configura `AWS_BUCKET` y asocia a EC2 un rol IAM con la política adjunta.
5. Aplica CORS al origen exacto de la aplicación, exponiendo ETag. Agrega `http://localhost:8080` solo para desarrollo.
6. Aborta multiparts incompletos tras un día mediante ciclo de vida. **No** agregues expiración general de objetos: borraría contenidos publicados.

Plantillas: [IAM](aws/iam-s3-policy.json), [CORS](aws/s3-cors.json), [ciclo de vida](aws/s3-lifecycle.json) y [Apache](aws/apache-classicflix.conf).

IAM limita las cargas/lecturas originales al prefijo `classicflix/`, añade lectura de miniaturas generadas en `miniaturas/v1/` y permite HeadBucket mediante ListBucket. No concede s3:* ni cambios de políticas/ACL. Crea la confianza para `ec2.amazonaws.com` y asocia el instance profile desde IAM/EC2. En EC2 deja las claves vacías y usa el rol; en local usa perfil AWS o credenciales temporales con permisos equivalentes.

Para reducir portadas automáticamente, consulta [Lambda y miniaturas](lambda-miniaturas.md). El código, configuración de referencia y permisos están versionados; el despliegue de la función y su capa se realiza desde la consola Lambda. Los permisos del rol Lambda son independientes de los del rol EC2.

CORS permite GET/HEAD y PUT del navegador, incluyendo `If-None-Match`. Las cargas usan escrituras condicionales para impedir que reutilizar una autorización sobrescriba un objeto ya verificado; el navegador debe enviar los encabezados devueltos por PHP. Iniciar/completar/abortar multipart son operaciones del backend SDK; las credenciales AWS no llegan al navegador. CORS no otorga lectura: sin firma un objeto privado debe devolver AccessDenied.

## Archivos y acceso

MySQL guarda metadatos y claves; S3 guarda portada, tráiler y película con claves únicas. PHP autoriza por contenido y tipo: tráiler para visitantes y película solo con sesión. Los enlaces se generan al usarlos; no se guardan en MySQL ni en caché. Un enlace firmado permite acceso a quien lo tenga hasta vencer: no es DRM ni impide conservar el archivo.

El reproductor renueva el acceso comprobando nuevamente la sesión y conservando la posición. La validez efectiva también depende de las credenciales del rol: prueba pausa, avance y reconexión.

## Despliegue y red

Usa Apache, PHP 8.2+, extensiones requeridas y Composer. Copia el proyecto fuera de cualquier directorio servido y ejecuta `composer install --no-dev --optimize-autoloader`. Configura DocumentRoot solo en `Frontend`; la plantilla bloquea listados y archivos ocultos. El usuario web necesita lectura sobre el código, no escritura para videos. Crea `var/sessions` fuera de la raíz pública y concede al usuario de PHP escritura únicamente en ese directorio para las sesiones; conserva el código y las dependencias sin escritura para el proceso web.

Configura un certificado HTTPS válido, `APP_URL=https://...`, `APP_ENV=production`, `APP_PREVIEW_ENABLED=false` y el origen CORS. Si hay un balanceador/proxy, verifica las cookies seguras y el esquema que recibe PHP; aplica HTTPS también al tramo interno o configura explícitamente un proxy confiable. No confíes en encabezados de cualquier cliente.

| Servicio | Entrada |
|---|---|
| Web | HTTPS 443 público; HTTP 80 solo redirige |
| EC2 | SSH restringido a tu IP o Systems Manager |
| MySQL | 3306 desde el grupo de la aplicación, nunca Internet |
| Redis | 6379 desde el grupo de la aplicación, nunca Internet |

MySQL/Redis pueden estar en servidores privados propios o servicios administrados. Configura hosts, contraseñas y TLS. MySQL TLS usa `DB_SSL_CA`; Redis TLS usa `REDIS_TLS=true` si el servidor lo requiere. No expongas esos endpoints como URL del sitio.

Mantén `.env` fuera del repositorio y con permisos mínimos. Puedes inyectar secretos como variables, que prevalecen sobre el archivo. Ejecuta migraciones con una cuenta separada y retira permisos DDL de la cuenta web.

## Operación y prueba real

Programa diariamente `php /ruta/Classicflix/scripts/console.php cleanup-uploads` con una identidad que tenga acceso al rol y a MySQL. Repite si hay más de 100 cargas antiguas. No ejecutes limpiezas desde una base de prueba que apunte al bucket/prefijo de producción.

Antes de dar por listo el despliegue:

- Ejecuta `php scripts/console.php check` y verifica MySQL y S3; Redis también si está habilitado. El comando actual devuelve código 1 cuando Redis está desactivado y lo reporta sin verificar: no confundirlo con un fallo de MySQL/S3 ni con una integración Redis probada.
- Prueba cargas simples y multipart, cancelación y recuperación de una parte fallida.
- Verifica reproducción con solicitudes Range, avance/retroceso y renovación de enlaces.
- Abre un objeto sin firma y confirma el rechazo.
- Confirma que visitantes/usuarios normales no puedan firmar cargas ni administrar.
- Interrumpe Redis en pruebas y verifica el catálogo desde MySQL; restablécelo y comprueba invalidación.
- Confirma que no se sirvan `.env`, `Backend`, `vendor`, SQL ni scripts y que `/preview` no exista en producción.

Estas comprobaciones necesitan infraestructura real. Clientes S3 simulados y SQLite validan lógica, no IAM, CORS, Redis real ni reproducción contra AWS.

Referencias: [URLs firmadas PHP](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-presigned-url.html), [multipart](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpuoverview.html), [roles EC2](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_roles_use_switch-role-ec2.html).
