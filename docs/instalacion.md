# Instalación, configuración y migración

## Configuración local

Usa PHP 8.2 de XAMPP o equivalente. `php -m` debe incluir PDO, pdo_mysql, mbstring, fileinfo, curl, openssl y session. Las pruebas aisladas usan pdo_sqlite. Instala Composer y ejecuta `composer install`. Las dependencias quedan fijadas en el archivo lock cuando esté disponible; `vendor` no se publica como código fuente.

Copia `.env.example` a `.env`, conserva este último fuera del repositorio y configura:

| Grupo | Variables |
|---|---|
| Aplicación | `APP_ENV`, `APP_URL`, `APP_PREVIEW_ENABLED`, `APP_TIMEZONE` |
| MySQL | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSL_CA` |
| AWS | `AWS_REGION`, `AWS_BUCKET`; perfil/credenciales temporales en local y rol IAM en EC2 |
| Redis | `REDIS_ENABLED`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DATABASE`, `REDIS_TLS`, `REDIS_TIMEOUT` |
| Caché | `CATALOG_CACHE_TTL` (300 segundos) |
| Reproducción | `MEDIA_TRAILER_TTL` (900), `MEDIA_MOVIE_TTL` (3600), `MEDIA_POSTER_TTL` (900) |
| Cargas | `UPLOAD_TRAILER_MAX_BYTES` (104857600), `UPLOAD_POSTER_MAX_BYTES` (5242880), `UPLOAD_MOVIE_MAX_BYTES` (2147483648) |

Los valores están en bytes o segundos según su nombre. Puedes envolver valores con espacios, `#` o `=` entre comillas; no incluyas comentarios al final de un valor. Las variables exportadas del sistema prevalecen sobre `.env`.

`AWS_ENDPOINT` y `AWS_USE_PATH_STYLE` sirven para servicios compatibles de desarrollo; no acreditan comportamiento de AWS real. La cadena de credenciales del SDK admite perfiles locales, variables y rol EC2. No uses credenciales raíz de AWS.

## Instalación nueva

Inicia MySQL. `php scripts/console.php install` crea la base configurada en `DB_DATABASE`, tablas y categorías iniciales. No crea usuarios ficticios. Ejecuta `php scripts/console.php create-admin`: solicita nombre, correo y contraseña confirmada. La contraseña se lee oculta en una terminal compatible; también admite los cuatro valores por entrada estándar para automatización segura. No recibe contraseñas por argumentos ni las imprime.

Si la cuenta MySQL solo tiene permisos sobre una base existente, usa `migrate`. La cuenta web de producción necesita SELECT, INSERT, UPDATE y DELETE; reserva CREATE/ALTER para una cuenta temporal de instalación y migración.

`BaseDatos.sql` sirve como alternativa para una base nueva llamada ClassicFlix. La consola es preferible cuando cambias el nombre. Ejecuta también `migrate` después de importar el SQL para incorporar categorías iniciales.

## Actualizar la estructura anterior

1. Haz una copia de seguridad verificable.
2. Configura el nombre exacto y las credenciales de esa base.
3. Ejecuta `php scripts/console.php migrate`.
4. Revisa categorías, duraciones y cuentas antes de publicar.

La migración crea tablas faltantes y agrega columnas/índices; no elimina usuarios, películas, favoritos, vistas ni reseñas. Es repetible. Si hay favoritos duplicados, se detiene antes de cambiar el esquema y señala los identificadores; resuelve los duplicados manualmente con respaldo disponible y repite. MySQL hace commits implícitos en DDL: ante un fallo, conserva los cambios aditivos realizados y puedes repetir una vez corregida la causa.

Las instalaciones nuevas no crean la columna `imagen`: las portadas viven en `Recursos`/S3. En una base anterior que ya la tenga, la migración la deja intacta y **no transforma `imagen` en una ruta S3 confiable**. Las películas previas quedan en borrador hasta cargar y verificar una portada y al menos un video (tráiler o película completa). Los valores anteriores de `duracion` no se multiplican automáticamente: si guardabas minutos, corrígelos al editar. Se conservan géneros y se vinculan a categorías.

La sinopsis, el enlace de origen y la referencia de derechos son opcionales. La duración del tráiler puede dejarse vacía (se guarda como 0). Este ajuste de validaciones y publicación no cambia el esquema ni requiere una migración adicional. Consulta [actualizar el código en EC2](despliegue-ubuntu-rds.md#13-actualizar-el-código-desde-github) para conservar la configuración existente.

### Incorporar reseñas y valoraciones

La migración `002_resenas_y_valoraciones` crea `Resenas` si no existe y agrega `valoracion` y `actualizado_en` a una tabla anterior. Ejecuta `php scripts/console.php migrate` antes de usar esta ampliación. Es repetible y no borra opiniones, usuarios ni contenidos.

Las reseñas anteriores conservan texto y fecha y quedan con puntuación nula hasta que su autor elija sus estrellas al editar. No participan en el promedio mientras no tengan voto. La columna histórica `Peliculas.calificacion` se conserva, pero el promedio público procede exclusivamente de las valoraciones en `Resenas`.

Solo se admite una opinión por usuario y película. Si existen duplicados anteriores, el diagnóstico identifica la pareja afectada y la migración se detiene antes de modificar el esquema; revisa y resuelve esos datos explícitamente con una copia de seguridad disponible. No se elige ni se elimina automáticamente ninguna reseña.

Contraseñas antiguas en texto plano no se convierten ni se aceptan como hashes. Crea un administrador nuevo por consola y registra cuentas nuevas si fuera necesario; no hay accesos especiales ni contraseñas predeterminadas.

## Servidor local y raíz pública

```powershell
php -S localhost:8080 -t Frontend
```

Para Apache/XAMPP usa un VirtualHost con DocumentRoot igual a la ruta absoluta de `Classicflix/Frontend`. Reinicia Apache desde XAMPP tras cambiar su configuración. No añadas alias que expongan el repositorio. Los accesos anteriores de login y administración llevan al controlador central.

PHP conserva las sesiones en `var/sessions`, fuera del directorio público. El servidor local lo crea cuando tiene permisos; con Apache crea ese directorio y habilita escritura para el usuario de PHP únicamente allí, sin conceder escritura a todo el código.

La vista de muestra exige simultáneamente `APP_ENV=local` y `APP_PREVIEW_ENABLED=true`. No cambia las rutas reales ni concede permisos administrativos para ellas.

## Mantenimiento

`php scripts/console.php check` informa sobre conexiones y devuelve un código distinto de cero si algún servicio no está verificado. `REDIS_ENABLED=false` permite trabajar con MySQL pero no demuestra Redis.

Ejecuta `php scripts/console.php cleanup-uploads` diariamente. Revisa cargas pendientes, en verificación, fallidas y canceladas con más de 24 horas; nunca debe borrar claves ya asociadas como recursos. Cada pasada procesa hasta 100 filas. Complementa el comando con el ciclo de vida S3 para multiparts incompletos.
