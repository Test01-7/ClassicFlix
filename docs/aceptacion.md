# Pruebas de aceptación

Usa cuentas y archivos de prueba propios. Anota el entorno de cada resultado; un servicio simulado no acredita AWS real.

| Área | Resultado esperado |
|---|---|
| Registro | Datos válidos crean cuenta normal; duplicados/contraseñas inválidas muestran errores. Enviar rol administrativo no concede privilegios. |
| Sesión | Contraseña incorrecta no autentica; login renueva sesión; logout exige POST/CSRF y corta acceso a películas. |
| Retorno | Login vuelve al contenido solicitado. Un parámetro de retorno externo no redirige fuera del sitio. |
| Catálogo | Buscar título/director/actores y filtrar categoría funciona sin cuenta; borradores no aparecen ni se reproducen por ID. |
| Administración | Visitantes y usuarios normales no abren el panel, guardan datos ni autorizan/completan cargas mediante endpoints directos. |
| Favoritos | Persisten por usuario, se agregan/quitan sin duplicados y otra cuenta no accede a ellos. |
| Historial | Comenzar reproducción registra una vista; pausar/continuar o reenviar token no duplica. Abrir la página no registra. |
| Archivos | JPEG/PNG/WebP y MP4 válidos dentro del límite se aceptan; tipo real/tamaño incorrecto se rechazan antes de publicar. |
| Multipart | Muestra progreso, reintenta partes y finaliza. Cancelar/fallar no publica recursos parciales. |
| Publicación | Necesita tráiler/portada verificados; película completa puede añadirse después. Subir no publica borradores automáticamente. |
| S3 privado | Objeto sin firma denegado; firmado reproduce y admite Range. Sin sesión no se firma la película. |
| Renovación | TTL reducido: pausa/avance después del vencimiento renueva acceso conservando posición. Sesión vencida solicita login. |
| Redis | Cachea metadatos, invalida al editar/publicar y permite MySQL ante caída. No guarda enlaces firmados ni datos personales. |
| Vista previa | Sin servicios, claramente identificada, sin mutaciones. No se habilita en producción aunque el indicador sea true. |
| Adaptabilidad | Pantallas legibles a 390 y 1440 px, sin desbordar y navegables por teclado. |
| Raíz pública | No se sirven secretos, SQL, backend, vendor ni herramientas CLI. No hay contraseñas predeterminadas. |

Los errores no deben mostrar secretos, trazas internas ni enlaces firmados. Las comprobaciones manuales pendientes no se marcarán como aprobadas por ejecutar pruebas de lógica locales.
