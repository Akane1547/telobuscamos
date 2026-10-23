# Etapa 8 — `fix`

Parches de los hallazgos de la auditoría de seguridad del 23-10-2026.

- **Rama:** `fix` (desde `hallazgos`)
- **Alcance del parche:** configuración del sitio y exposición de archivos.
- **Fuera de este repo:** actualizaciones de núcleo/plugins y directivas de nginx/php.ini, que se aplican en el hosting.

## Parcheado

| ID | Hallazgo | Parche |
|---|---|---|
| SF-06 | ZIP del plugin descargable | Se eliminó `wp-content/plugins/simple-form.zip` |
| SF-07 | Enumeración de usuarios | REST `/wp/v2/users` devuelve 404 a visitantes; sitemap de usuarios desactivado; `/author/…` redirige a la portada |
| SF-08 | XML-RPC con multicall y pingback | `xmlrpc.php` responde `403` antes de procesar; `xmlrpc_enabled` en falso |
| SF-09 | Cabeceras ausentes | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`; HSTS solo sobre HTTPS |
| SF-10 | CORS reflectante | La cabecera `Access-Control-Allow-Origin` se retira cuando el origen no es el del sitio |
| SF-11 | Fingerprinting | `X-Powered-By` eliminado; `generator` fuera; `readme.html` y `license.txt` borrados |
| SF-15 | `robots.txt` con ruta interna | Se quitó la línea de `admin-ajax.php` |

El parche vive en `deploy/mu-plugins/sf-hardening.php` y se instala copiándolo a `wp-content/mu-plugins/sf-hardening.php`.

## Pendiente en el hosting

| ID | Hallazgo | Qué falta |
|---|---|---|
| SF-01 | LFI no autenticada (WP < 7.1.2) | Actualizar el núcleo a 7.1.3+ |
| SF-02 | XSS almacenado no autenticado | Ídem |
| SF-03 | Seis CVEs del núcleo | Ídem |
| SF-04 | Elementor < 4.3.5 | Actualizar Elementor |
| SF-05 | Essential Addons desactualizado | Actualizar a 6.8.7 |
| SF-09 | CSP y HSTS | Cabecera CSP adaptada a Elementor y HSTS en nginx (requiere HTTPS) |
| SF-11 | `Server: nginx/1.26.1` | `server_tokens off` en nginx |
| SF-12 | `wp-cron.php` externo | `DISABLE_WP_CRON` + cron del sistema |
| SF-13 | Cookies sin `SameSite` | `proxy_cookie_flags ~ samesite=lax` en nginx |
| SF-14 | ETags con inodo | `etag off` en nginx |

Las actualizaciones de núcleo y plugins no se pueden aplicar desde este entorno: las versiones locales (WordPress 7.1.1, Elementor 4.1.4) se actualizan desde wp-admin o en el hosting real.

## Verificación (23-10-2026)

| Prueba | Resultado |
|---|---|
| `GET /wp-json/wp/v2/users` | `404 rest_no_route` |
| `GET /wp-sitemap-users-1.xml` | `404` |
| `GET /?author=1` / `/author/admin/` | `301` a la portada |
| `POST /xmlrpc.php` | `403 XML-RPC deshabilitado` |
| `GET /wp-content/plugins/simple-form.zip` | `404` |
| `GET /readme.html`, `/license.txt` | `404` |
| Cabeceras de la home | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` |
| CORS con `Origin: https://evil.example` | Sin `Access-Control-Allow-Origin` |
| CORS con el origen propio (local y túnel) | `Access-Control-Allow-Origin` correcto |
| Home y `/mapa/` | `200` |
| AJAX del formulario con nonce | `404` (sin sesión), sin errores |

## Operativo

`SF-16`: el túnel de ngrok sigue arriba para la prueba de pago. Se cierra con `sf-tunnel stop` al terminar.
