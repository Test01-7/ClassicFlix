# Miniaturas de portadas con AWS Lambda

La integración PHP y el código de la función están versionados en este repositorio. No necesitas modificar PHP con nano en EC2: sube los cambios a GitHub y actualiza el checkout de EC2. El despliegue del código y la capa de Lambda se realiza desde **Lambda → Event Functions**, por separado de `git pull`. Estos archivos no crean ni modifican recursos AWS por sí mismos.

## Archivos incluidos

| Archivo | Uso |
|---|---|
| `lambda/poster-thumbnail/lambda_function.py` | Código para pegar en el editor de Lambda o subir como ZIP. |
| `lambda/poster-thumbnail/function-settings.json` | Referencia de los valores de configuración de la función. No es una plantilla completa de creación de recursos. |
| `lambda/poster-thumbnail/requirements-layer.txt` | Versión de Pillow para construir la capa Linux. |
| `lambda/poster-thumbnail/event-example.json` | Evento de prueba: reemplaza bucket y clave por un original real antes de ejecutarlo. |
| `docs/aws/lambda-execution-policy.json` | Lectura de originales y escritura de miniaturas para el rol de Lambda. |
| `docs/aws/lambda-trust-policy.json` | Confianza de ejecución para el servicio Lambda. |
| `docs/aws/iam-s3-policy.json` | Política de EC2, incluida lectura privada de miniaturas. |
| `scripts/build-lambda.py` | Generación local de ZIP para la función y su capa; no llama a AWS. |

Sustituye `BUCKET_ELEGIDO` por tu bucket en las políticas. Los ejemplos de función y evento usan el bucket previamente configurado `mi-primer-bucket-04-07-2006`, en Ohio (`us-east-2`). Ninguna plantilla contiene claves de acceso, contraseñas ni tokens.

## Flujo y claves

1. El administrador carga una portada desde ClassicFlix mediante una URL temporal de S3.
2. El original se guarda en `classicflix/{id}/portada/{identificador-unico}.jpg`, `.png` o `.webp`.
3. Un evento de creación de S3 invoca Lambda. La función valida bucket, evento, ruta, formato real y límites de la imagen.
4. Pillow corrige la orientación, reduce a un máximo de **400 × 560 píxeles**, conserva proporciones y no agranda imágenes pequeñas. Convierte la transparencia a marfil y guarda JPEG con calidad 80, sin copiar EXIF.
5. La miniatura se guarda en `miniaturas/v1/{id}/{identificador-unico}.jpg`, fuera del prefijo de entrada. Esto evita invocaciones recursivas.
6. PHP comprueba la disponibilidad de esa miniatura y emite una URL firmada; si aún no está lista o falla su consulta, sirve el original con firma temporal. La autorización de publicación y de borradores se comprueba antes de acceder a S3.

Los resultados siguen siendo privados. No se guardan firmas en MySQL/Redis, no se solicitan claves arbitrarias desde el navegador y Lambda no necesita acceso a MySQL ni a EC2. La reducción tiene límite de dimensiones; el peso final depende de la imagen, y una imagen original muy pequeña no necesariamente tendrá menos bytes al convertirla a JPEG.

Las notificaciones repetidas generan la misma clave de salida. Se aplica el `eTag` del evento al leer para ignorar una notificación de una versión ya reemplazada. Los eventos de videos, de otras rutas/buckets y del propio resultado se ignoran. Archivos inválidos no producen miniaturas; errores de conexión/permisos siguen apareciendo como fallos para permitir reintentos y diagnóstico en CloudWatch.

## Preparar los ZIP localmente

Desde la raíz del proyecto, con Python y pip:

```powershell
python scripts/build-lambda.py --layer
```

El comando descarga la versión de Pillow indicada para **Python 3.13, Linux x86_64**, y genera:

- `var/lambda/classicflix-miniaturas-code.zip`: código con `lambda_function.py` en la raíz.
- `var/lambda/classicflix-pillow-python313-x86_64.zip`: capa con la carpeta `python/` en la raíz.

Los ZIP son archivos generados, excluidos de Git por estar en `var/`. Se pueden reconstruir desde las fuentes. Para generar solo el ZIP del código, sin descargar dependencias:

```powershell
python scripts/build-lambda.py
```

El código de Lambda carga Boto3 del runtime; la capa contiene únicamente Pillow, sin dependencias que sustituyan las del SDK.

## Configurar en la consola de AWS

Si la función y el desencadenador ya existen, actualízalos; no crees una segunda regla igual.

1. Abre **Lambda → Event Functions → classicflix-miniaturas**, en `us-east-2`.
2. Usa **Python 3.13**, arquitectura **x86_64**, handler **lambda_function.lambda_handler**, memoria **512 MB** y tiempo máximo **30 segundos**. No necesita una URL de función ni conexión a una VPC para este flujo.
3. En **Código → Upload from / Cargar desde → .zip**, selecciona `classicflix-miniaturas-code.zip`. También puedes pegar el contenido del archivo Python en el editor y pulsar **Deploy**.
4. En **Lambda → Layers / Capas**, crea o actualiza `classicflix-pillow` subiendo el ZIP de la capa, con arquitectura x86_64 y runtime Python 3.13. Añade esa versión de la capa a la función. Si ya tiene una capa compatible de Pillow, puedes conservarla.
5. En **Configuración → Variables de entorno**, configura `SOURCE_BUCKET` con el nombre exacto del bucket. El código mantiene el bucket del ejemplo como valor predeterminado para compatibilidad con la configuración explicada anteriormente.
6. En el rol de ejecución de Lambda, conserva `AWSLambdaBasicExecutionRole` para CloudWatch y añade la política `lambda-execution-policy.json` adaptada al bucket. Si reutilizas `rol-lamda-s3-ses2026`, revisa su confianza con `lambda-trust-policy.json`; conserva las políticas que necesiten sus otras funciones.
7. En el rol **de EC2**, añade el permiso `s3:GetObject` para `arn:aws:s3:::TU_BUCKET/miniaturas/v1/*`, incluido en `iam-s3-policy.json`. Actualizar el JSON local no cambia el rol real de AWS.

### Desencadenador y alerta anterior

En la función, añade un desencadenador **S3**, selecciona el bucket, **todos los eventos de creación de objetos**, prefijo **`classicflix/`** y sufijo **vacío**. La consola Lambda añade el permiso para que ese bucket invoque la función. Una sola notificación cubre JPEG, PNG y WebP: el código ignora los videos.

El bucket ya tenía una alerta independiente (`alerta-subida-imagenS3-2026`). Si acordaste separarla por carpetas, esa alerta debe tener el prefijo `alertas/` y sus imágenes manuales se cargan ahí. Una regla anterior sin filtros se solapa con `classicflix/` e impide crear la nueva. Conserva su destino y sus otras necesidades; cambiar solo el nombre de una regla no elimina el solapamiento. No añadas una notificación desde S3 y otra idéntica desde Lambda.

El destino de salida `miniaturas/` debe permanecer fuera del prefijo que dispara la función. Conserva el bloqueo del acceso público y el cifrado SSE-S3. Si tu bucket utiliza SSE-KMS, deberás configurar también los permisos de su clave; las políticas de ejemplo corresponden a SSE-S3.

## Actualizar EC2 sin nano

Primero sube los archivos modificados y nuevos a GitHub. Comprueba si hay modificaciones manuales en EC2 (`git status --short`); si aplicaste el ejemplo anterior de PHP allí, conserva e integra ese trabajo antes de resolver un conflicto. Después, con el checkout listo para actualizar:

```bash
cd /var/www/Classicflix
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
sudo systemctl reload apache2
```

Ejecuta cada comando solo si el anterior terminó correctamente. Conserva el `.env` actual: no hay cambios de esquema, nuevas dependencias Composer ni variables obligatorias adicionales en EC2. Las miniaturas están habilitadas por defecto; `POSTER_THUMBNAILS_ENABLED=false` permite desactivar su selección si lo necesitas. El nombre de bucket sigue proviniendo de `AWS_BUCKET` en PHP.

El código del catálogo usa `/portada`, por lo que las tarjetas existentes seleccionan la miniatura automáticamente. Las demás vistas que usan ese mismo recurso también la aprovechan.

## Verificación

Pruebas locales:

```powershell
php tests/run.php
python tests/lambda_thumbnails.py
```

La prueba Python necesita Pillow instalado en el Python local (`python -m pip install -r lambda/poster-thumbnail/requirements-layer.txt`). Utiliza Pillow real y un cliente S3 ficticio; no necesita credenciales ni Boto3 instalado. La suite PHP comprueba firma, selección, caída a original, controles de acceso y continuidad de las demás funcionalidades.

En AWS, sube una portada **nueva** desde ClassicFlix y comprueba:

1. Aparece el original y luego su JPEG en `miniaturas/v1/`.
2. **Lambda → Monitor → CloudWatch Logs** muestra su clave y los tamaños del original/resultado sin errores.
3. Al recargar el catálogo, la imagen se redirige a una URL firmada de `miniaturas/v1/` y se muestra correctamente.
4. Abrir la URL del objeto sin firma da acceso denegado.
5. La alerta separada sigue funcionando al cargar una imagen manualmente dentro de `alertas/`.

Las portadas anteriores no disparan el evento retroactivamente. Puedes volver a cargarlas desde ClassicFlix o probar el evento de ejemplo sustituyendo la clave por un original existente. Generar un ZIP o pasar pruebas locales no confirma la configuración real de AWS.

Las portadas reemplazadas obtienen un identificador nuevo y no reutilizan una miniatura anterior. Las miniaturas antiguas no se borran automáticamente en esta ampliación; se pueden retirar cuando su original ya no esté asociado a contenido. No apliques una expiración general sobre miniaturas activas.

Referencias: [S3 y Lambda](https://docs.aws.amazon.com/lambda/latest/dg/with-s3.html), [capas Python](https://docs.aws.amazon.com/lambda/latest/dg/python-layers.html), [filtros de eventos](https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-how-to-filtering.html).
