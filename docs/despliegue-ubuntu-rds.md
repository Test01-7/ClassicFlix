# Desplegar ClassicFlix: EC2 Ubuntu, RDS, ElastiCache y S3

Guía preparada el 24/10/2026 para una EC2 existente. Describe operaciones que tú realizarás en tu cuenta: no se han creado recursos ni comprobado conexiones con tu AWS.

Esta guía está adaptada a tu **Ubuntu 26.04 LTS**. Sus repositorios incluyen PHP 8.5, según las [notas oficiales de Ubuntu](https://documentation.ubuntu.com/release-notes/26.04/changes-since-previous-interim/). Conserva ese sistema y utiliza los paquetes oficiales disponibles; no hace falta instalar específicamente PHP 8.2.

El proyecto declara `php: ^8.2`, que admite PHP 8.5, y los requisitos PHP de las dependencias fijadas en `composer.lock` también lo permiten. Esto es compatibilidad declarada: las pruebas locales se ejecutaron con PHP 8.2, no con el PHP 8.5 de tu instancia. Comprueba la plataforma, las conexiones y los flujos reales antes de dar por terminado el despliegue.

## 1. Qué alojará cada servicio

| Servicio | Contenido |
|---|---|
| EC2 Ubuntu + Apache + PHP | Código PHP, CSS, JavaScript, configuración y sesiones. |
| RDS for MySQL | Usuarios, catálogo, recursos, favoritos, historial y reseñas. |
| ElastiCache for Redis OSS | Metadatos del catálogo durante cinco minutos. |
| S3 privado | Portadas, tráileres y películas. |

El navegador accede a EC2 por HTTPS. EC2 accede a RDS y Redis por la red privada; firma las autorizaciones de S3 mediante su rol IAM. Las cargas y videos van directamente entre navegador y S3 usando enlaces firmados.

RDS, ElastiCache, S3 y la dirección pública de EC2 tienen su propia facturación. Consulta la estimación de tu región y los créditos de tu cuenta antes de crear los servicios; no presupongas que la tarea estará cubierta por una capa gratuita. [Precios RDS](https://aws.amazon.com/rds/mysql/pricing/) y [ElastiCache](https://aws.amazon.com/elasticache/pricing/).

## 2. Revisar la EC2 y preparar la red

En **EC2 → Instancias → tu instancia**, anota región, VPC, subred y grupo de seguridad. Conserva esa región y esa VPC para RDS y ElastiCache. La ruta de esta guía publica Apache directamente desde una EC2 con IP pública y salida a Internet; una instancia privada requiere otra entrada, por ejemplo un balanceador.

En esa VPC, prepara dos subredes privadas en zonas de disponibilidad diferentes, con rangos que no se superpongan con los existentes. Úsalas para los grupos de subredes de RDS y ElastiCache. No necesitan una ruta pública para recibir las conexiones privadas de EC2. RDS exige normalmente subredes en al menos dos zonas incluso al crear una instancia Single-AZ. [Red de RDS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_VPC.WorkingWithRDSInstanceinaVPC.html).

Crea o ajusta estos grupos de seguridad:

| Grupo | Entrada permitida | Origen |
|---|---|---|
| `classicflix-web` (EC2) | TCP 22 | Tu IP pública, con `/32`. |
| `classicflix-web` | TCP 80 y 443 | Público para el sitio y la validación/redirect HTTPS. |
| `classicflix-db` (RDS) | TCP 3306 | **Grupo** `classicflix-web`. |
| `classicflix-cache` (Redis) | TCP 6379 | **Grupo** `classicflix-web`. |

Para 3306 y 6379, selecciona como origen el identificador del grupo de EC2, no `0.0.0.0/0` ni tu IP doméstica. EC2 necesita salida a los servicios privados, DNS y HTTPS para S3 y las descargas. Si mantienes la salida predeterminada del grupo, no necesitas abrir una regla de entrada por cada conexión saliente. Revisa también los otros grupos ya asociados: sus permisos se suman. [Grupos de seguridad RDS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Overview.RDSSecurityGroups.html).

## 3. Crear RDS MySQL

En **RDS → Databases → Create database**:

1. Selecciona creación estándar y **MySQL**, con una versión 8.4 LTS disponible y con soporte estándar en tu región. La verificación previa del proyecto fue con MariaDB local; la instalación y pruebas funcionales en tu RDS siguen siendo necesarias. [Versiones de RDS MySQL](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/MySQL.Concepts.VersionMgmt.html).
2. Identificador sugerido: `classicflix-db`. Para la demostración, una instancia Single-AZ y una clase pequeña disponible pueden ser suficientes; revisa la estimación antes de confirmar.
3. Usa autenticación por contraseña. Guarda el usuario administrador de RDS y su contraseña en tu gestor de contraseñas, no en el chat.
4. VPC: la de tu EC2. Grupo de subredes: el preparado anteriormente. **Public access: No**. Grupo de seguridad: `classicflix-db`.
5. En configuración adicional, nombre inicial de base: **`ClassicFlix`**. Esto crea una base vacía, no las tablas de la aplicación.
6. Mantén cifrado y copias de seguridad, eligiendo la retención según tu proyecto.
7. Al quedar disponible, copia el **endpoint DNS** y puerto 3306.

El administrador de RDS es una cuenta de base de datos. Es diferente del administrador de la web, que crearás posteriormente por consola.

## 4. Crear ElastiCache compatible con esta versión

En **ElastiCache → Redis OSS**, crea una implementación **basada en nodos** con configuración propia:

- Modo clúster **desactivado** (`Cluster mode disabled`).
- VPC y grupo de subredes preparados; grupo de seguridad `classicflix-cache`.
- Cifrado en tránsito activado y exigido; cifrado en reposo activado.
- Control de acceso **AUTH Default User**, con un token/contraseña guardado en tu gestor.
- Para una demostración, un nodo sin réplicas reduce recursos; una réplica y conmutación automática mejoran disponibilidad a cambio de más recursos.

En esta entrega, el cliente utiliza una conexión Redis normal con contraseña. Otras configuraciones, como modo clúster, IAM o un usuario ACL distinto de `default`, necesitan adaptar el cliente/configuración. Usa el endpoint de escritura: **Primary endpoint** si hay réplicas, o el endpoint del nodo si solo hay uno. No uses el Reader endpoint, porque la aplicación también escribe la caché. [Creación](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/SubnetGroups.designing-cluster-pre.redis.html), [endpoints](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/Endpoints.html) y [AUTH](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/auth.html).

## 5. Crear S3 y dar acceso a EC2

1. En S3 crea un bucket de propósito general, con nombre único y en la misma región.
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

La consulta `apt-cache policy` debe mostrar candidatos instalables para esos paquetes; si alguno no tiene candidato, revisa los repositorios antes de continuar. Espera PHP 8.5 en una instalación estándar de Ubuntu 26.04 y comprueba las extensiones PDO MySQL, mbstring, fileinfo, curl y openssl. Instala los paquetes sin fijar una revisión antigua para recibir las actualizaciones disponibles. No instales MySQL Server ni Redis Server en EC2: usarás los servicios administrados. [PHP en Ubuntu](https://ubuntu.com/server/docs/how-to/web-services/install-php/).

## 7. Transferir el proyecto

Si aún tienes cambios locales sin subir a un repositorio, prepara un paquete con los archivos necesarios. Desde **PowerShell en tu PC**, situado en la carpeta del proyecto:

```powershell
tar -czf classicflix-deploy.tar.gz Backend Frontend scripts docs composer.json composer.lock BaseDatos.sql .env.example README.md
scp -i "C:\ruta\tu-clave.pem" .\classicflix-deploy.tar.gz ubuntu@IP_PUBLICA_EC2:~/
```

Sustituye los ejemplos por tu clave local e IP. La clave privada permanece en tu PC. El paquete no incluye `.env`, sesiones, pruebas locales, dependencias ni datos de muestra de ejecución. No lo guardes dentro de `Frontend`.

De nuevo **en Ubuntu**, usando el usuario `ubuntu` de una instalación estándar:

```bash
sudo install -d -o ubuntu -g www-data -m 2750 /var/www/Classicflix
tar -xzf ~/classicflix-deploy.tar.gz -C /var/www/Classicflix
cd /var/www/Classicflix
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
cp .env.example .env
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

REDIS_ENABLED=true
REDIS_HOST=ENDPOINT_REAL_DE_ESCRITURA
REDIS_PORT=6379
REDIS_PASSWORD="TOKEN_AUTH_REDIS"
REDIS_DATABASE=0
REDIS_TLS=true
REDIS_TIMEOUT=2
CATALOG_CACHE_TTL=300
```

Los endpoints no llevan `https://`, `tls://` ni puerto incluido. Deja vacíos `AWS_PROFILE`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN` y `AWS_ENDPOINT`; EC2 obtendrá permisos de su rol. Este código no descarga contraseñas automáticamente de Secrets Manager ni genera tokens IAM para RDS/Redis.

El uso de `DB_SSL_CA` exige validación del certificado del servidor; utiliza el endpoint DNS original de RDS. [Certificados RDS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html).

## 9. Crear tablas y administrador de ClassicFlix

Con `DB_USERNAME` temporalmente configurado con el administrador RDS, y la base vacía `ClassicFlix` creada en el paso 3:

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

El `%` corresponde a la cuenta SQL; la entrada de red sigue limitada al grupo de EC2. Cambia `.env` a `DB_USERNAME=classicflix_app` y su contraseña. Las futuras migraciones se ejecutan temporalmente con la cuenta que tenga permisos DDL, no desde la cuenta web. [Conexión MySQL TLS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_ConnectToInstanceSSL.CLI.html).

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

Ambos deben indicar MySQL, Redis y S3 disponibles. La segunda ejecución comprueba acceso bajo la identidad de Apache; un resultado positivo no prueba todavía CORS, archivos ni reproducción.

Abre `https://TU_DOMINIO/index.php?r=/catalogo` y verifica registro, login, navegación administrativa, reseñas y filtros. Registra un borrador y carga portada/tráiler desde el panel; añade la película, publica y comprueba reproducción como visitante y usuario. Prueba también una carga multipart y cancelación. La muestra `/preview` debe devolver 404 en producción. Revisa [aceptación](aceptacion.md) para el resto de comprobaciones reales.

Programa la limpieza diaria usando `sudo crontab -u www-data -e` y añade:

```cron
15 3 * * * /usr/bin/php /var/www/Classicflix/scripts/console.php cleanup-uploads
```

La hora corresponde a la zona del servidor/cron. Aplica también el ciclo de vida de S3 y conserva las copias de RDS.

## 12. Si algo falla

| Resultado | Revisar |
|---|---|
| MySQL no disponible | Endpoint, base existente, credenciales, CA, misma VPC y regla 3306 desde el grupo EC2. |
| Redis no disponible | Endpoint de escritura, token AUTH, TLS, modo clúster desactivado y regla 6379 desde EC2. |
| S3 no disponible | Región/bucket, rol asociado a la instancia y nombre sustituido en la política IAM. |
| Consola funciona y Apache falla | Lectura de `.env`, dependencias y CA; escritura en `var/sessions`; rol EC2 disponible para el proceso web. |
| S3 disponible pero el navegador no carga | CORS para el origen exacto, encabezados/ETag, permisos y región. |
| Login vuelve sin sesión | HTTPS real y `APP_URL`, cookies seguras y permisos de sesiones. |
| Página de error 503 | Registro `/var/log/apache2/classicflix-error.log` y comprobación de conexiones. No publiques secretos ni enlaces firmados al compartir diagnósticos. |

Esta guía prepara una primera instalación con una sola EC2. Alta disponibilidad de la aplicación, despliegues automatizados y balanceadores quedan como ampliaciones; no son necesarios para ejecutar esta entrega académica.
