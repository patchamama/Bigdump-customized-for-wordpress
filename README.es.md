# Bigdump — Edición WordPress

[English](README.md) · [Pendientes y limitaciones](TODO.md)

Herramientas PHP para importar bases de datos, migrar WordPress, administrar un sitio y
obtener reportes de su estado. No requieren Composer, framework ni compilación.

> **Son herramientas de mantenimiento con permisos elevados.** Algunas acciones cambian
> archivos, usuarios y tablas del sitio real. El reporte y su API son de solo lectura;
> eso no convierte al resto del panel en una herramienta de solo lectura.

## Qué archivo usar

| Archivo | Para qué sirve | Alcance |
| --- | --- | --- |
| `cms_tools.php` | Panel autenticado de migración, mantenimiento y reportes | Puede modificar el sitio y su base de datos. Es la implementación principal. |
| `cms_tool.php` | URL compatible, en singular, para consultar el reporte con token | Solo expone el reporte JSON. Necesita `cms_tools.php` en la misma carpeta. |
| `bigdump.php` | Importador escalonado SQL/CSV, con extensiones para WordPress | Distribuye la importación entre peticiones para trabajar con límites de PHP; puede reemplazar tablas. |
| `bigdump_original.php` | Referencia histórica modificada de BigDump 0.36b, con detección de configuración | No es el panel actual ni una alternativa endurecida. No lo publiques como respaldo de seguridad. |

**Disponible en esta rama:** pestaña Reporte, descarga JSON, envío a GitHub privado,
API con token independiente y comentarios internos de `cms_tools.php` en inglés.

**Todavía no disponible:** el flujo completo y verificado de actualización de WordPress
en un entorno realmente aislado. El candidato interrumpido no está integrado. El botón
histórico de instalación y el sandbox existente no sustituyen ese flujo.

Estos cambios no implican una publicación en GitHub. Desplegá archivos de la misma revisión;
una descarga desde `main` puede no contener todavía las funciones de esta rama.

## Inicio y acceso

1. Guardá una copia de los **archivos y de la base de datos** antes de modificar el sitio.
2. Colocá el script elegido junto a `wp-config.php`, según el entorno que vas a administrar.
3. Abrí `cms_tools.php` por **HTTPS** y restringí el acceso administrativo en el servidor.
4. Por defecto, el panel pide `DB_PASSWORD`. Si está vacía, exige crear una contraseña
   propia. Una contraseña propia configurada después tiene prioridad.
5. Para habilitar la API, copiá también `cms_tool.php` a esa misma carpeta.

La contraseña propia se guarda como **hash con salt**, no como texto cifrado recuperable.
La autenticación del panel no es la autenticación de WordPress. El script descubre
configuraciones de WordPress, Joomla, Drupal y MediaWiki, pero las herramientas específicas
de WordPress no se convierten por eso en funciones equivalentes para los otros CMS.

Si renombrás `cms_tools.php`, también necesitás ajustar el `require` del alias. El alias no
sigue automáticamente ese cambio. No mezcles archivos de versiones distintas.

## Reporte del sitio: generación y descarga

1. Entrá a **Reporte** y elegí **Generar reporte**. Abrir la pestaña no ejecuta la auditoría.
2. Revisá el JSON y los contadores antes de compartirlos.
3. Elegí **Descargar JSON**: conserva el reporte mostrado y su fecha, sin volver a generarlo.

El esquema `cms-tools-site-report` incluye versión de esquema/herramienta, fecha UTC,
versiones de PHP, MySQL/MariaDB y WordPress, indicadores de extensiones/configuración PHP,
versiones y actividad de plugins y temas, ajustes seleccionados del sitio, tamaños de
las tablas estándar y cantidades estimadas de filas. También declara alcance y límites.

| Nivel del JSON | Significado |
| --- | --- |
| `critical` | Indicador clasificado como crítico por la regla local. |
| `warning` | Advertencia o condición que conviene revisar. |
| `ok` | La comprobación concreta no detectó la condición señalada; no certifica todo el sitio. |
| `unavailable` | No se pudo comprobar. **Nunca equivale a aprobado.** |

El nombre se obtiene del dominio de `siteurl` en la base de WordPress: por ejemplo,
`mi-dominio.example.json`. No usa el Host del navegador como sustituto. Sin dominio
comprobable se puede descargar `site-report.json`, pero no enviarlo a GitHub.

### Privacidad y límites del reporte

- No exporta contraseñas, tokens, salts, configuración o logs completos, cuentas de
  usuarios, correos ni contenido personal. El resto del panel sí tiene funciones que
  acceden a información sensible: esta exclusión corresponde al reporte.
- No ejecuta WordPress, plugins o temas, ni hace reparaciones o sondeos HTTP.
- La configuración se inspecciona estáticamente. Los comentarios no se interpretan como
  definiciones; valores dinámicos o ambiguos pueden quedar como no disponibles.
- El inventario usa directorios estándar de plugins/temas y límites de lectura/entradas.
  Las activaciones de red, rutas de contenido personalizadas y truncamientos tienen límites.
- No certifica actualizaciones, vulnerabilidades actuales, integridad de todos los archivos,
  TLS real, SEO dinámico ni compatibilidad funcional. Los conteos de tablas son estimaciones.

## Guardar el JSON en GitHub privado

1. Generá y revisá el reporte.
2. Ingresá una URL como `https://github.com/organizacion/repositorio-privado`.
   Se acepta el sufijo `.git`.
3. Usá un token de GitHub con acceso al repositorio y **Contents: lectura y escritura**.
   La organización puede exigir aprobación o autorización SSO adicional.
4. Dejá `wordpress-report` o elegí otra rama de reportes. Confirmá el envío.

Solo se admiten repositorios **privados de github.com con un commit inicial**. La rama
predeterminada del repositorio no se puede usar como destino. Si falta la rama de reporte,
se crea desde la predeterminada. El archivo del dominio se crea o actualiza en su raíz.
La actualización usa el SHA del archivo para detectar cambios concurrentes; no hay reintento
automático. GitHub conserva versiones anteriores en su historial.

Se envía exactamente la copia generada por el servidor, no un JSON arbitrario del cliente.
Tiene **30 minutos de validez para exportar**; otra generación, incluso desde otra pestaña,
invalida la anterior. La copia puede permanecer en el almacenamiento de sesión del hosting
hasta que esa sesión expire. Si la creación de la rama tiene éxito pero falla la escritura,
la rama puede quedar creada: revisá el repositorio antes de reintentar.

El token de GitHub va en el cuerpo de un POST al panel y luego solo a `api.github.com`,
con TLS verificado y sin seguir redirecciones. No se guarda en configuración, sesión,
reporte ni almacenamiento del navegador; el campo se limpia después de cada intento.
Esto no controla lo que hagan extensiones o gestores de contraseñas del navegador.

## API para una aplicación externa

Desde **Reporte**, usá **Crear / rotar token de API** y copiá la URL de una sola vez.
Este token aleatorio es distinto de la contraseña del panel y del token de GitHub.
Solo su hash SHA-256 se conserva en `.migrate_config`.

Ejemplo de URL, con un marcador que debés reemplazar:

```text
https://sitio.example/herramientas/cms_tool.php?action=site_report_api&token=TU_TOKEN_API
```

Preferí la cabecera Bearer para evitar colocar el token en la URL:

```bash
curl --fail --silent --show-error \
  -H 'Authorization: Bearer TU_TOKEN_API' \
  'https://sitio.example/herramientas/cms_tool.php?action=site_report_api'
```

Cada GET válido genera un reporte **nuevo**, sin login interactivo ni cookies de sesión.
El token no habilita administración, descarga SQL, reparaciones, escritura o envío a GitHub.
El alias rechaza otras acciones. No hay CORS para navegadores: una aplicación web de otro
origen debe consultar desde su backend, no exponer el token a través de cookies o JavaScript.

| Resultado | Respuesta |
| --- | --- |
| GET válido por HTTPS | HTTP 200 con el JSON del reporte. |
| Token ausente, inválido o revocado | HTTP 401 con error JSON. |
| Uso del token por HTTP | HTTP 400: se requiere HTTPS. |
| Fallo al guardar creación/rotación/revocación | HTTP 503; no informa éxito ni entrega una URL nueva. |

La gestión requiere login, POST, CSRF y HTTPS. Rotar invalida el token anterior; revocar
inhabilita el acceso cuando la persistencia tiene éxito. **Si revocar falla, tratá el token
anterior como potencialmente activo.** Se verifican escrituras completas, archivo temporal
`0600`, renombrado atómico y lectura final; los fallos no deben fingir una revocación exitosa.

**Precauciones:** una URL con token puede quedar en logs e historial. Preferí Bearer, redactá
queries en los registros y no subas tokens al repositorio. La URL generada usa el Host
aceptado por el servidor: configurá una lista de hosts permitidos y verificá el destino.
Detrás de un proxy TLS, configurá el estado HTTPS confiable del servidor; no alcanza con
mandar una cabecera de forwarding. La URL se limpia al copiarla o abandonar la página.

## Opciones del panel de mantenimiento

Estas son herramientas existentes; no todas fueron recertificadas por las pruebas nuevas.

| Área | Opciones y alcance |
| --- | --- |
| Exportar/importar DB | SQL mediante CLI o PHP; `.sql`, `.gz`, `.zip`; exportación dividida en ZIP de 1–10 MB y reemplazo de URLs con cuidado de valores serializados. Importar puede reemplazar tablas. |
| Quick update | Reparación **posmigración** de configuración, `siteurl/home`, enlaces y caché; opcionalmente desactiva plugins. No actualiza WordPress core. |
| URLs y dominios | Cambio HTTP/HTTPS, inventario de dominios, enlaces relativos y reemplazo de referencias con comprobaciones de destino según la herramienta. |
| Recuperar assets | Analiza referencias faltantes y permite obtener archivos desde el origen; la recuperación automática puede escribir reglas y archivos. |
| Clean Site | Reconstruye uploads con archivos referenciados y conserva una carpeta anterior/log; no sustituye el backup completo. |
| Optimizar uploads | Organizar, deduplicar y sanear nombres actualizando referencias; puede mover o borrar archivos. |
| Limpieza de disco | Detecta huérfanos y archivos grandes/sospechosos; usa carpetas/logs de recuperación según la operación. |
| Usuarios | Listar, crear, editar roles/correos y cambiar contraseñas sin el administrador de WordPress. |
| Plugins | Listar actividad y activar/desactivar mediante la base de datos. |
| Salud y seguridad | Indicadores de entorno, archivos, tablas, usuarios y exposición; no equivalen al reporte privado ni a un pentest completo. |
| SEO y enlaces | Indexación, robots/sitemap, título/descripción, permalinks, enlaces rotos y accesos a buscadores. |
| PHP y Apache | Diagnósticos y propuestas para `.htaccess`/`.user.ini`; su aplicación modifica archivos del hosting. Un sondeo acotado no garantiza todas las rutas. |
| Rendimiento | Compresión, caché del navegador, caché estática y mediciones/enlaces externos. |
| Backups/snapshots | Exportar archivos, exportar DB y guardar snapshots de configuración son operaciones distintas. |
| Extras | Caché, mantenimiento, debug, reglas WordPress, reparación/optimización de tablas y protección HTTP Basic. |
| Instaladores | WordPress y phpMyAdmin. El instalador histórico no es un actualizador completo de una instalación existente. |
| Interfaz | Español/inglés, tema claro/oscuro, estado de versiones, noticias y actualización de la herramienta; actualizar la herramienta no actualiza WordPress. |

## Sandbox histórico y actualización pendiente

**No lo uses como entorno aislado de actualización.** El sandbox existente comparte archivos
por symlinks y reutiliza credenciales de producción con otro prefijo de tablas. Puede enlazar
`wp-config.php` y escribir después a través de ese enlace. Su limpieza alcanza nombres o
prefijos compartidos. Los smoke tests de plugins no demuestran compatibilidad completa.

El nuevo flujo solicitado sigue pendiente de completar/verificar y de resolver dónde podrán
ejecutarse plugins sin acceso a producción. El candidato interrumpido está fuera de la
implementación principal. No se ejecutó una actualización real ni se certificó el hosting.
La decisión ya tomada es **preparar y recomendar, nunca reemplazar producción automáticamente**.

## Opciones de `bigdump.php`

- Importación escalonada con progreso AJAX para límites de tiempo/memoria de PHP.
- Detección de SQL, GZ y ZIP; soporte CSV. Omite instrucciones de selección/creación de DB
  según el motor y puede eliminar tablas existentes antes de importarlas.
- Lectura de `wp-config.php`, prueba de conexión e indicadores de PHP.
- Reemplazo de URLs y herramientas de usuarios/plugins para WordPress.
- Backup previo mediante CLI o PHP; revisá su resultado antes de continuar.
- Despliegue FTP/FTPS/SFTP, configuración persistida en `bigdump.config.json` y eliminación
  del script al terminar. El transporte depende de las extensiones del hosting.

No es una API de reportes ni una estrategia de actualización/rollback de WordPress.

## Requisitos, backup y seguridad

- PHP 8.0 o superior; MySQL/MariaDB y las extensiones `pdo_mysql`/`mysqli` para las acciones DB.
- ZIP, cURL/HTTPS streams, OpenSSL, CLI MySQL y FTP/SSH son necesarios según la operación.
- HTTPS, permisos de escritura donde corresponda y restricciones de acceso en el servidor.
- Copia separada de **todos los archivos y de la base de datos**, fuera del directorio público,
  con restauración probada. El ZIP de hosting no genera un dump de DB.
- Un snapshot de configuración solo cubre archivos seleccionados y la lista de plugins;
  no incluye todo el contenido, core, temas, plugins, uploads ni una DB completa.
- El JSON y el historial del reporte en GitHub **no permiten restaurar el sitio**.

Protegé `.migrate_config`, temporales `.migrate_config-*`, `bigdump.config.json`, dumps y
backups contra acceso HTTP. El modo `0600` no reemplaza las reglas del servidor. Si dejás
la API disponible, necesitás ambos archivos PHP y protección separada del panel. Si ya no
la necesitás, revocá el token y eliminá scripts y estado sensible; no borres solo la
implementación dejando el alias roto. No subas configuraciones reales al control de versiones.

## Verificación y pendientes

```bash
php -l cms_tools.php
php -l cms_tool.php
php tests/site-report.php
SITE_REPORT_TEST_CURL=1 php tests/site-report.php
php tests/site-report-api.php
node tests/site-report-ui.cjs
node tests/site-report-api-ui.cjs
```

Los tests usan instalaciones temporales y dobles de PDO, archivos y GitHub. Node se usa para
las pruebas de controladores, no como dependencia del sitio. No acreditan TLS real,
permisos del hosting, GitHub real, Apache, todas las funciones históricas ni una actualización
WordPress real. Consultá [TODO.md](TODO.md) para las tareas abiertas, sus límites y prioridades.

Créditos: BigDump original de Alexey Ozerov, GPL; extensiones WordPress de este repositorio.
