# Despliegue a producción — sistema de cuentas

Checklist de la puesta en producción. Lo que se puede verificar desde el repo ya
está hecho y probado; lo que depende del hosting se marca como **servidor**.

## 1. Qué se sube

1. Empaquetar el plugin sin las herramientas de desarrollo:

   ```bash
   # desde la raíz del plugin
   zip -r simple-form-1.2.0.zip . -x@.distignore
   ```

   `.distignore` deja afuera `tests/`, `vendor/`, `deploy/`, `composer.*`, las
   cachés y la documentación interna (`AUDITORIA.md`, `FIX.md`, `souls.md`).

2. Subir el zip a `wp-content/plugins/` y activarlo (o reemplazar la carpeta y
   dejar que WordPress detecte la versión 1.2.0).

3. La base se migra sola: `plugins_loaded` compara `simple_form_db_version` con
   `Database::DB_VERSION` (3) y agrega `sf_orders.user_id` con `dbDelta`. Los
   pedidos históricos quedan con `user_id = 0` (invitados), que es la verdad.

## 2. Servidor: HTTPS real

- El sitio tiene que responder en `https://telobuscamos.com` **antes** de poner
  `mode=production`: Mercado Pago rechaza `back_urls` y webhook en `http://`.
- Certificado válido y `Site Address` / `WordPress Address` con `https://`.

## 3. Servidor: credenciales por constantes

En `wp-config.php`, antes de `/* That's all, stop editing! */`:

```php
define( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN', 'APP_USR-…' );   // producción
define( 'SIMPLE_FORM_MERCADO_PAGO_WEBHOOK_SECRET', '…' );
define( 'SIMPLE_FORM_MERCADO_PAGO_MODE', 'production' );
```

- Las constantes mandan sobre lo guardado en el panel y **no pasan por el
  chequeo de HTTPS**: por eso el punto 2 va primero.
- Comprobación sin imprimir valores: en *Pedidos → Pagos* la pantalla debe decir
  de qué constante sale cada credencial, o desde CLI
  `PaymentConfig::source( 'mercado_pago', 'access_token' )` → `constant`.

## 4. Servidor: webhook definitivo

- URL a configurar en el panel de Mercado Pago:
  `https://telobuscamos.com/wp-json/simple-form/webhook`.
- Eventos: pagos. El endpoint ya verifica `x-signature` con el secreto y es
  idempotente por contenido (`payment:<id>:<status>`).
- Prueba sin cobrar: *Pedidos → Pagos → Simular notificación*, y después mirar
  la tabla de eventos del pedido.

## 5. Cerrar el túnel de desarrollo

- `~/.local/bin/sf-tunnel stop` en la máquina de desarrollo.
- Retirar `wp-content/mu-plugins/sf-dev-tunnel.php` (y su `.txt`) del sitio en
  producción: es solo para probar pagos por túnel.
- Verificar que el sitio no responde en ninguna URL `*.trycloudflare.com`.

## 6. Cuentas: lo que hay que mirar después de desplegar

| Ruta | Qué debe pasar |
|---|---|
| `/iniciar-sesion` | Formulario centrado sobre la textura, sin scroll horizontal en móvil |
| `/iniciar-sesion?form=registro` | Al enviar: aviso neutro "te enviamos un correo", sin sesión |
| correo del registro | Llega el enlace `?sf_confirm=…` (revisar que no caiga en spam) |
| enlace de confirmación | Pide contraseña, activa la cuenta y entra al perfil |
| `/perfil` | Nombre y teléfono editables; correo y fecha solo lectura |
| `/iniciar-sesion?form=recuperar` | Aviso neutro; llega el correo de WordPress con el enlace de reseteo |
| `/mapa` | Con sesión, el formulario abre con nombre, correo y teléfono de la cuenta |
| menú | "Perfil" con sesión, "Iniciar sesion" sin ella |
| `/wp-admin` con cuenta de cliente | Redirige a `/perfil` |

## 7. Correo saliente

El registro depende del correo: si el hosting no envía, nadie puede activar la
cuenta. Antes de abrir el registro al público:

- Configurar un plugin SMTP o el relay del hosting.
- Mandar una prueba real a un correo externo (no al del dominio).
- Revisar SPF/DKIM del dominio: sin eso, el enlace de confirmación cae en spam.
- El filtro `simple_form_email_enabled` apaga todos los correos del plugin; si se
  apaga, **el registro deja de funcionar** (nadie recibe el enlace).

## 8. Operación

- Backups de `wp_users`, `wp_usermeta` y de las tablas `sf_*`.
- Política de retención de pedidos y de cuentas inactivas.
- El log propio del plugin está apagado por defecto; se enciende con
  `SIMPLE_FORM_LOGGING` o el filtro `simple_form_logging_enabled`, y nunca
  escribe contraseñas.

## 9. Verificación final (ejecutada en local)

```text
composer test:all       → 170 unitarias + 8 suites de integración, 0 fallos
composer test:security  → 182 pruebas, 0 fallas
composer test:front     → TODO OK
```

En producción conviene repetir `composer test:security` apuntando al sitio real
(`SF_SECURITY_URL` / `SF_SECURITY_HOST`) **después** de configurar HTTPS y antes
de abrir el registro: crea y borra sus propias cuentas.
