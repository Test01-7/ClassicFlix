# Desplegar ClassicFlix: EC2 Ubuntu, RDS y S3

Guía preparada el 24/10/2026 para una EC2 existente. Describe operaciones que tú realizarás en tu cuenta: no se han creado recursos ni comprobado conexiones con tu AWS.

Actualizada según tu aclaración: tienes EC2, un bucket S3 y una instancia RDS MySQL llamada **`tiendadigitaldb`**. El recurso que confundiste con Redis era RDS; no se ha confirmado un Redis existente. **No necesitas crear Redis ni ElastiCache para la consigna compartida**. Aquí reutilizamos tu RDS y S3; la caché queda opcional y el ejemplo la desactiva.

Esta guía está adaptada a tu **Ubuntu 26.04 LTS**. Sus repositorios incluyen PHP 8.5, según las [notas oficiales de Ubuntu](https://documentation.ubuntu.com/release-notes/26.04/changes-since-previous-interim/). Conserva ese sistema y utiliza los paquetes oficiales disponibles; no hace falta instalar específicamente PHP 8.2.

El proyecto declara `php: ^8.2`, que admite PHP 8.5, y los requisitos PHP de las dependencias fijadas en `composer.lock` también lo permiten. Esto es compatibilidad declarada: las pruebas locales se ejecutaron con PHP 8.2, no con el PHP 8.5 de tu instancia. Comprueba la plataforma, las conexiones y los flujos reales antes de dar por terminado el despliegue.

## 1. Qué alojará cada servicio

| Servicio | Contenido |
|---|---|
| EC2 Ubuntu + Apache + PHP | Código PHP, CSS, JavaScript, configuración y sesiones. |
| RDS for MySQL | Usuarios, catálogo, recursos, favoritos, historial y reseñas. |
| Redis, opcional y no confirmado | Metadatos del catálogo durante cinco minutos, si lo incorporas después. |
| S3 privado existente | Portadas y tráileres para la entrega. |

El navegador accede a EC2 por HTTPS. EC2 accede a RDS por la red privada y firma las autorizaciones de S3 mediante su rol IAM. Las cargas y videos van directamente entre navegador y S3 usando enlaces firmados. Si incorporas Redis, puede estar en esa misma EC2 o en otro servidor privado.

No hace falta agregar otro servicio de caché a los recursos que ya tienes. Revisa la facturación de tus recursos actuales y los créditos de tu cuenta. [Precios RDS](https://aws.amazon.com/rds/mysql/pricing/).

## 2. Revisar la EC2 y preparar la red

En **EC2 → Instancias → tu instancia**, anota región, VPC, subred y grupo de seguridad. Comprueba la conectividad privada con tu RDS existente. La ruta de esta guía publica Apache directamente desde una EC2 con IP pública y salida a Internet; una instancia privada requiere otra entrada, por ejemplo un balanceador.

Tu RDS ya tiene un grupo de subredes: revísalo en lugar de crear otro servicio o cambiarlo sin necesidad. Para una instalación nueva de RDS, prepara un grupo con subredes privadas en al menos dos zonas. Redis instalado en la misma EC2 no necesita un grupo de subredes adicional. [Red de RDS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_VPC.WorkingWithRDSInstanceinaVPC.html).

Crea o ajusta estos grupos de seguridad:

| Grupo | Entrada permitida | Origen |
|---|---|---|
| `classicflix-web` (EC2) | TCP 22 | Tu IP pública, con `/32`. |
| `classicflix-web` | TCP 80 y 443 | Público para el sitio y la validación/redirect HTTPS. |
| `classicflix-db` (RDS) | TCP 3306 | **Grupo** `classicflix-web`. |
| Redis, solo si se añade en otro servidor | TCP 6379 | **Grupo** `classicflix-web`. |

Para servicios en otro servidor, permite 3306/6379 desde el grupo de EC2, no desde `0.0.0.0/0`. Si Redis está en la misma EC2, utiliza `127.0.0.1` y no abras 6379 a Internet. EC2 necesita salida a los servicios privados, DNS y HTTPS para S3 y las descargas. Revisa también los otros grupos ya asociados: sus permisos se suman. [Grupos de seguridad RDS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Overview.RDSSecurityGroups.html).

## 3. Reutilizar tu MySQL en RDS

Como ya tienes RDS, no crees otra instancia. Anota su endpoint DNS, puerto, nombre de base, usuario y método de conexión. Comprueba el acceso desde EC2 y conserva cualquier dato existente. Los pasos siguientes se aplican solo si en otro entorno necesitas crear un RDS desde cero.

**`tiendadigitaldb` identifica la instancia RDS, no necesariamente una base SQL.** Entra en esa instancia y abre **Conectividad y seguridad** para obtener el endpoint DNS y el puerto. Usa ese endpoint en `DB_HOST`. Dentro de la instancia, crea una base separada llamada `ClassicFlix` y usa ese nombre exacto en `DB_DATABASE`; no instales las tablas de ClassicFlix sobre la base de otro proyecto. No es necesario cambiar el identificador de RDS. [Conexión a RDS MySQL](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_ConnectToInstance.html).

En **RDS → Databases → Create database**:

1. Selecciona creación estándar y **MySQL**, con una versión 8.4 LTS disponible y con soporte estándar en tu región. La verificación previa del proyecto fue con MariaDB local; la instalación y pruebas funcionales en tu RDS siguen siendo necesarias. [Versiones de RDS MySQL](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/MySQL.Concepts.VersionMgmt.html).
2. Identificador sugerido: `classicflix-db`. Para la demostración, una instancia Single-AZ y una clase pequeña disponible pueden ser suficientes; revisa la estimación antes de confirmar.
3. Usa autenticación por contraseña. Guarda el usuario administrador de RDS y su contraseña en tu gestor de contraseñas, no en el chat.
4. VPC: la de tu EC2. Grupo de subredes: el preparado anteriormente. **Public access: No**. Grupo de seguridad: `classicflix-db`.
5. En configuración adicional, nombre inicial de base: **`ClassicFlix`**. Esto crea una base vacía, no las tablas de la aplicación.
6. Mantén cifrado y copias de seguridad, eligiendo la retención según tu proyecto.
7. Al quedar disponible, copia el **endpoint DNS** y puerto 3306.

El administrador de RDS es una cuenta de base de datos. Es diferente del administrador de la web, que crearás posteriormente por consola.

## 4. Redis opcional; iniciar sin caché

Redis es un servidor de caché; ElastiCache es un servicio administrado de AWS que puede alojarlo. Ambos son diferentes de RDS, que aloja tu MySQL. Para esta entrega inicia con `REDIS_ENABLED=false`. Si luego quieres añadir Redis, las instrucciones siguientes explican la conexión. [Qué es ElastiCache](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/WhatIs.html).

- Si Redis corre en la misma EC2, usa `REDIS_HOST=127.0.0.1` y el puerto de esa instalación.
- Si corre en otro servidor, usa su dirección privada accesible desde EC2. `localhost` en EC2 no apunta al Redis de tu PC.
- Configura `REDIS_PASSWORD` solo con la contraseña real de esa instalación, si exige autenticación.
- Usa `REDIS_TLS=true` cuando ese servidor tenga TLS; para un Redis local sin TLS usa `false`.
- Mantén `REDIS_DATABASE=0`. El cliente actual trabaja con una conexión Redis sin modo clúster.

También puedes ejecutar la web sin caché estableciendo `REDIS_ENABLED=false`; el catálogo consulta MySQL. El comando `console.php check` de esta versión es estricto con las integraciones: con Redis desactivado lo muestra como «sin verificar» y devuelve código 1, aunque la web pueda consultar MySQL. Esto no acredita una conexión Redis.

La consigna compartida no exige caché, por lo que basta comprobar MySQL, S3 y las funciones de la web si decides dejar Redis desactivado. Su implementación permanece disponible para una incorporación posterior.

## 5. Reutilizar S3 y dar acceso a EC2

1. Selecciona el bucket que ya tienes; no necesitas otro. Anota su región para `AWS_REGION`.
2. Mantén las cuatro opciones de **Block Public Access** y ACL desactivadas (`Bucket owner enforced`). Conserva cifrado SSE-S3 para usar las plantillas existentes.
3. En IAM crea una política usando [iam-s3-policy.json](aws/iam-s3-policy.json), reemplazando las dos apariciones de `BUCKET_ELEGIDO` por tu nombre real.
4. Crea un rol con entidad de confianza **AWS service → EC2** y adjunta esa política.
5. En la instancia: **Actions → Security → Modify IAM role**, selecciona ese rol. Si ya tiene uno necesario para otras funciones, añade la política al rol adecuado en vez de sustituirlo sin revisar sus permisos.
6. En el bucket, **Permissions → CORS**, pega [s3-cors.json](aws/s3-cors.json), cambiando `https://cine.ejemplo.com` por el origen real, sin rutas ni barra final.
7. Configura una regla de ciclo de vida para abortar cargas multipart incompletas tras un día, limitada al prefijo `classicflix/`. [s3-lifecycle.json](aws/s3-lifecycle.json) contiene la definición; no añadas una regla que expire todos los videos.

No actives alojamiento de sitio estático: PHP se ejecuta en EC2. No necesitas publicar el bucket ni introducir claves AWS permanentes en la instancia. Los enlaces firmados permiten las operaciones autorizadas desde el navegador; no restrinjas todas las solicitudes S3 exclusivamente a la VPC, porque impedirías esas cargas y reproducciones externas. [Roles EC2](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_roles_use_switch-role-ec2.html) y [CORS S3](https://docs.aws.amazon.com/AmazonS3/latest/userguide/enabling-cors-examples.html).

## 6. Instalar Apache, PHP y Composer en Ubuntu 26.04

Entra mediante SSH o el método de conexión que ya uses para EC2. Los comandos siguientes se ejecutan **dentro de Ubuntu**, no en PowerShell:

```bash
lsb_release -ds
sudo apt update
apt-cache policy php libapache2-mod-php composer mysql-client
sudo apt install apache2 php libapache2-mod-php php-cli php-mysql php-mbstring php-curl php-xml php-zip composer unzip ca-certificates curl mysql-client
php -v
php -m
```

La consulta `apt-cache policy` debe mostrar candidatos instalables para esos paquetes; si alguno no tiene candidato, revisa los repositorios antes de continuar. Espera PHP 8.5 en una instalación estándar de Ubuntu 26.04 y comprueba las extensiones PDO MySQL, mbstring, fileinfo, curl y openssl. No necesitas instalar MySQL Server porque ya tienes RDS; tampoco necesitas Redis para esta ruta de despliegue. [PHP en Ubuntu](https://ubuntu.com/server/docs/how-to/web-services/install-php/).

## 7. Transferir el proyecto

### Opción A: clonar desde GitHub

El remoto configurado en el proyecto es `https://github.com/Test01-7/ClassicFlix.git`. Para una primera descarga, ejecuta en la terminal **de tu EC2 Ubuntu**:

```bash
sudo apt update
sudo apt install git
sudo install -d -o ubuntu -g www-data -m 2750 /var/www/Classicflix
git clone https://github.com/Test01-7/ClassicFlix.git /var/www/Classicflix
```

La carpeta destino debe estar vacía para clonar. Si ya contiene una instalación, no la borres ni fuerces la descarga: revisa primero qué archivos y configuración tiene. Ejecuta Git y Composer con el usuario `ubuntu`, no con `sudo`. Si usas otro usuario de despliegue, sustituye `ubuntu` por ese usuario en la preparación del directorio.

`git clone` obtiene lo que esté publicado en GitHub; no incluye cambios que solo estén en tu PC. Si el repositorio es privado, necesitarás autenticar el acceso mediante una clave SSH/deploy key o un token con acceso al repositorio; no incrustes tokens en la URL ni los compartas en el chat. [Clonación desde GitHub](https://docs.github.com/en/repositories/creating-and-managing-repositories/cloning-a-repository).

El repositorio completo irá en `/var/www/Classicflix`; Apache seguirá sirviendo **solo `/var/www/Classicflix/Frontend`**, nunca `.git`, `Backend`, `.env` ni el resto de la raíz.

### Opción B: transferir un paquete sin GitHub

Si aún tienes cambios locales sin subir al repositorio, puedes preparar un paquete con los archivos necesarios. Desde **PowerShell en tu PC**, situado en la carpeta del proyecto:

```powershell
tar -czf classicflix-deploy.tar.gz Backend Frontend scripts docs composer.json composer.lock BaseDatos.sql .env.example README.md
scp -i "C:\ruta\tu-clave.pem" .\classicflix-deploy.tar.gz ubuntu@IP_PUBLICA_EC2:~/
```

Sustituye los ejemplos por tu clave local e IP. La clave privada permanece en tu PC. El paquete no incluye `.env`, sesiones, pruebas locales, dependencias ni datos de muestra de ejecución. No lo guardes dentro de `Frontend`.

De nuevo **en Ubuntu**, usando el usuario `ubuntu` de una instalación estándar:

```bash
sudo install -d -o ubuntu -g www-data -m 2750 /var/www/Classicflix
tar -xzf ~/classicflix-deploy.tar.gz -C /var/www/Classicflix
```

### Instalar dependencias y preparar configuración

Después de descargar el proyecto por **una** de las dos opciones, continúa en EC2. Apache, PHP y Composer deben estar instalados como indica el paso 6:

```bash
cd /var/www/Classicflix
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
test -f .env || cp .env.example .env
chmod 640 .env
sudo install -d -o ubuntu -g www-data -m 750 var
sudo install -d -o www-data -g www-data -m 700 var/sessions
```

Con este directorio preparado con grupo heredado `www-data`, Apache puede leer el código y `.env`; solo el directorio de sesiones necesita escritura para el proceso web. No uses `chmod 777` ni concedas escritura a Apache sobre todo el proyecto.

## 8. Certificado RDS y configuración

Descarga en EC2 la CA publicada por AWS; aquí se usa el paquete global de las regiones comerciales:

```bash
sudo curl --fail --show-error --location https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem --output /etc/ssl/certs/rds-global-bundle.pem
cd /var/www/Classicflix
nano .env
```

Sustituye los valores del archivo, conservando los límites y vencimientos que ya incluye `.env.example`. Ejemplo para el sitio final con HTTPS:

```dotenv
APP_ENV=production
APP_URL=https://cine.tudominio.com
APP_PREVIEW_ENABLED=false
APP_TIMEZONE=America/Lima

DB_HOST=ENDPOINT_REAL_RDS
DB_PORT=3306
DB_DATABASE=ClassicFlix
DB_USERNAME=USUARIO_TEMPORAL_DE_INSTALACION
DB_PASSWORD="CONTRASENA_RDS"
DB_SSL_CA=/etc/ssl/certs/rds-global-bundle.pem

AWS_REGION=REGION_DE_TUS_RECURSOS
AWS_BUCKET=NOMBRE_REAL_DEL_BUCKET

REDIS_ENABLED=false
CATALOG_CACHE_TTL=300
```

Los endpoints no llevan `https://`, `tls://` ni puerto incluido. Deja vacíos `AWS_PROFILE`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN` y `AWS_ENDPOINT`; EC2 obtendrá permisos de su rol. Este código no descarga contraseñas automáticamente de Secrets Manager ni genera tokens IAM para RDS/Redis.

Con Redis desactivado no necesitas rellenar host ni contraseña de caché. Si lo incorporas después, cambia a `REDIS_ENABLED=true` y adapta host, puerto, contraseña y TLS al servidor según el paso 4.

El uso de `DB_SSL_CA` exige validación del certificado del servidor; utiliza el endpoint DNS original de RDS. [Certificados RDS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html).

## 9. Crear tablas y administrador de ClassicFlix

### Si quieres importar la estructura desde HeidiSQL

HeidiSQL se ejecuta en tu PC y envía el SQL a RDS. Puedes usar tu sesión RDS existente; comprueba que el servidor es el endpoint de `tiendadigitaldb`, no MySQL local. Si RDS solo acepta conexiones privadas desde EC2, crea una sesión **MariaDB/MySQL (SSH tunnel)**: en la configuración MySQL usa el endpoint RDS, puerto 3306 y credenciales SQL; en la pestaña SSH usa la IP pública de EC2, puerto 22, usuario `ubuntu`, tu clave privada local y un puerto local libre como 3307. HeidiSQL reciente admite `ssh.exe`; `plink.exe` puede requerir una clave en formato PPK. No envíes la clave ni las contraseñas al chat. [Conexiones y túneles HeidiSQL](https://www.heidisql.com/help.php#connecting).

Para una base **nueva**:

1. En una pestaña de consulta ejecuta `CREATE DATABASE IF NOT EXISTS ClassicFlix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`.
2. Actualiza el árbol y selecciona `ClassicFlix`.
3. En **Archivo → Ejecutar archivo SQL** selecciona el `BaseDatos.sql` de este proyecto, con codificación **UTF-8**. Al confirmar se ejecutan las sentencias; el archivo también incluye `CREATE DATABASE IF NOT EXISTS ClassicFlix` y `USE ClassicFlix`.
4. Comprueba que aparecen `Usuarios`, `Categorias`, `Peliculas`, `Recursos`, `Favoritos`, `Vistas`, `Resenas` y `Cargas`.
5. Continúa en EC2 con `migrate` y `create-admin`, indicados a continuación: la migración añade las categorías iniciales y registra las versiones del esquema. El SQL por sí solo no crea una cuenta administrativa de la web ni carga videos.

Si `ClassicFlix` ya contiene una instalación anterior, haz respaldo y utiliza la migración en lugar de importar el esquema nuevo sobre esa instalación. Las otras bases de la instancia RDS no deben borrarse ni seleccionarse como destino de las tablas. [Importación SQL en HeidiSQL](https://www.heidisql.com/help.php#importing).

### Completar la instalación desde EC2

Para una base nueva, con `DB_USERNAME` temporalmente configurado con una cuenta con permisos de migración y la base vacía `ClassicFlix` existente:

```bash
cd /var/www/Classicflix
php scripts/console.php migrate
php scripts/console.php create-admin
```

`create-admin` pide nombre, correo y contraseña de la cuenta administrativa de la **web**. No lo confundas con la contraseña RDS ni copies las cuentas ficticias de la muestra. Si ya hay información real, respáldala antes de migrar.

Después crea una cuenta SQL solo para la aplicación. Desde EC2, el siguiente cliente solicita la contraseña RDS de forma interactiva:

```bash
MYSQL_HISTFILE=/dev/null mysql --host=ENDPOINT_REAL_RDS --port=3306 --user=ADMIN_RDS --password --ssl-mode=VERIFY_IDENTITY --ssl-ca=/etc/ssl/certs/rds-global-bundle.pem
```

Dentro del cliente MySQL, reemplaza la contraseña de ejemplo por una diferente y robusta:

```sql
CREATE USER 'classicflix_app'@'%' IDENTIFIED BY 'REEMPLAZAR_CON_UNA_CONTRASENA_PROPIA' REQUIRE SSL;
GRANT SELECT, INSERT, UPDATE, DELETE ON ClassicFlix.* TO 'classicflix_app'@'%';
EXIT;
```

El `%` corresponde a la cuenta SQL; la entrada de red sigue limitada al grupo de EC2. Si ya tienes una cuenta de aplicación con estos permisos, reutilízala en lugar de crearla de nuevo. Cambia `.env` a esa cuenta y su contraseña. Las futuras migraciones se ejecutan temporalmente con la cuenta que tenga permisos DDL, no desde la cuenta web. [Conexión MySQL TLS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_ConnectToInstanceSSL.CLI.html).

## 10. Apache y HTTPS

La única raíz pública será **`/var/www/Classicflix/Frontend`**. No apuntes Apache a `/var/www/Classicflix`.

Para iniciar el sitio HTTP y permitir emitir el certificado, crea `/etc/apache2/sites-available/classicflix.conf`, reemplazando el dominio:

```apache
<VirtualHost *:80>
    ServerName cine.tudominio.com
    DocumentRoot /var/www/Classicflix/Frontend
    <Directory /var/www/Classicflix/Frontend>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    ErrorLog ${APACHE_LOG_DIR}/classicflix-error.log
    CustomLog ${APACHE_LOG_DIR}/classicflix-access.log combined
</VirtualHost>
```

Activa el sitio y valida antes de recargar:

```bash
sudo a2ensite classicflix.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Si la instancia es exclusivamente para esta web y aún usa el sitio predeterminado, deshabilítalo con `sudo a2dissite 000-default.conf`, valida y recarga. No deshabilites otros sitios existentes sin identificarlos.

Con un dominio, configura su registro A hacia la IP pública estable de EC2. Cuando el dominio resuelva correctamente y llegue al puerto 80, instala Certbot siguiendo sus [instrucciones oficiales para Apache](https://certbot.eff.org/instructions?ws=apache&os=snap); en una instalación nueva con Snap:

```bash
sudo snap install --classic certbot
sudo /snap/bin/certbot --apache -d cine.tudominio.com
sudo /snap/bin/certbot renew --dry-run
```

Elige redirigir HTTP a HTTPS. La plantilla [Apache definitiva](aws/apache-classicflix.conf) también sirve como referencia, pero no actives rutas de certificados que todavía no existen.

Si aún no tienes dominio, puedes comprobar Apache por IP como etapa inicial; el nombre del ejemplo debe adaptarse. Para la publicación con cuentas, resuelve primero un acceso HTTPS con certificado válido. `APP_URL` y el origen permitido en CORS deben coincidir con la dirección realmente usada. Una configuración `https://...` no funcionará correctamente para autenticarte entrando por `http://IP`, porque las cookies se configuran como seguras.

## 11. Comprobar y publicar contenido

Desde el proyecto en EC2, con el usuario SQL definitivo:

```bash
php scripts/console.php check
sudo -u www-data php /var/www/Classicflix/scripts/console.php check
```

Ambos deben indicar MySQL y S3 disponibles; Redis también si decides utilizarlo. Para la caché desactivada, ten en cuenta el comportamiento estricto descrito en el paso 4. La segunda ejecución comprueba acceso bajo la identidad de Apache; un resultado positivo no prueba todavía CORS, archivos ni reproducción.

Abre `https://TU_DOMINIO/index.php?r=/catalogo` y verifica registro, login, favoritos, historial, administración, reseñas y filtros. Para cumplir la consigna compartida, registra un borrador, carga **solo portada y tráiler**, publica y comprueba reproducción HTML5 desde S3. No necesitas cargar películas completas; la ampliación aprobada previamente permanece como opción. Prueba también una carga multipart y cancelación. La muestra `/preview` debe devolver 404 en producción. Revisa [aceptación](aceptacion.md) para el resto de comprobaciones reales.

Programa la limpieza diaria usando `sudo crontab -u www-data -e` y añade:

```cron
15 3 * * * /usr/bin/php /var/www/Classicflix/scripts/console.php cleanup-uploads
```

La hora corresponde a la zona del servidor/cron. Aplica también el ciclo de vida de S3 y conserva las copias de RDS.

## 12. Si algo falla

| Resultado | Revisar |
|---|---|
| MySQL no disponible | Endpoint, base existente, credenciales, CA, misma VPC y regla 3306 desde el grupo EC2. |
| Redis no disponible | Host de tu servidor existente, contraseña/TLS si los utiliza y acceso local o privado desde EC2. No hace falta crear ElastiCache. |
| S3 no disponible | Región/bucket, rol asociado a la instancia y nombre sustituido en la política IAM. |
| Consola funciona y Apache falla | Lectura de `.env`, dependencias y CA; escritura en `var/sessions`; rol EC2 disponible para el proceso web. |
| S3 disponible pero el navegador no carga | CORS para el origen exacto, encabezados/ETag, permisos y región. |
| Login vuelve sin sesión | HTTPS real y `APP_URL`, cookies seguras y permisos de sesiones. |
| Página de error 503 | Registro `/var/log/apache2/classicflix-error.log` y comprobación de conexiones. No publiques secretos ni enlaces firmados al compartir diagnósticos. |

## 13. Actualizar el código desde GitHub

Una actualización del repositorio no obliga a recrear Apache, RDS, el bucket, IAM, CORS ni la función Lambda y su desencadenador. Conserva el `.env` de EC2: está excluido del repositorio, contiene los valores de ese entorno y no debe sustituirse por `.env.example` durante una actualización. Los datos de RDS y objetos S3 permanecen en sus servicios.

Primero sube los cambios del proyecto a GitHub. En la terminal de EC2:

```bash
cd /var/www/Classicflix
git status --short
git diff --name-only
```

Si aparecen archivos editados directamente en EC2, intégralos y guárdalos en el repositorio antes de continuar. Esto incluye los ajustes manuales de `S3Storage.php` y `MediaService.php` para miniaturas Lambda, si los aplicaste: bajar otra versión no debe hacerte perder esa integración. Conserva también una copia local de esos cambios antes de resolver conflictos. Los comandos siguientes presuponen que las modificaciones locales ya están resueltas.

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
```

Ejecuta cada comando solo si el anterior terminó correctamente. `--ff-only` detiene la actualización si las ramas tienen cambios divergentes, para que puedas revisarlos. Composer instala las dependencias del archivo `composer.lock` de esa versión.

Para el cambio que hace opcionales sinopsis/procedencia y permite portada con película sin tráiler no hay cambios de esquema: no necesitas importar SQL ni volver a crear cuentas. En otras versiones, si se anuncian cambios de esquema, haz respaldo de RDS y ejecuta `php scripts/console.php migrate`. Si se añaden variables, agrégalas al `.env` existente sin sustituirlo completo.

Después de actualizar el código PHP puedes recargar Apache para liberar su caché de código:

```bash
sudo systemctl reload apache2
```

Recarga el navegador con Ctrl + Shift + R y comprueba login, edición, publicación y reproducción. Una función editada desde la consola Lambda se despliega por separado: `git pull` en EC2 no actualiza el código ni las capas de Lambda.

Esta guía prepara una primera instalación con una sola EC2. Alta disponibilidad de la aplicación, despliegues automatizados y balanceadores quedan como ampliaciones; no son necesarios para ejecutar esta entrega académica.
