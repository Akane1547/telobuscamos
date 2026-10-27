# Cambios

## 5ca32b7 - Feat: el pedido queda ligado a la cuenta del cliente

- **src/Database/Database.php**: `DB_VERSION = 3`. `sf_orders` gana `user_id BIGINT UNSIGNED NOT NULL DEFAULT 0` con índice; dbDelta lo agrega en la instalación que ya estaba activa y los pedidos históricos quedan en 0 (se hicieron sin cuenta, no se inventa un dueño).
- **src/Database/OrderRepository.php**: `create_draft()` guarda `get_current_user_id()`, o sea el servidor y nunca el navegador; el invitado queda en 0. Los datos de contacto siguen siendo el snapshot del pedido (souls.md §7): editar el formulario no toca la cuenta.
- **src/Admin/AdminOrders.php**: el detalle del pedido muestra la cuenta dueña ("Cuenta #12 — correo", "Invitado (sin cuenta)" o "Cuenta #N (ya no existe)"), separada del snapshot de contacto.
- **simple-form.php** y **readme.txt**: versión 1.1.0, con el changelog de la entrega. La versión también es la del cache-busting de `account.css` y del JS del formulario, así que los assets nuevos no quedan viejos en el navegador del cliente.
- **souls.md**: quedan escritas las decisiones que dependían del producto —§48.2 (el login no revela, el registro sí, y por qué se acepta con su límite), §48.9 (qué hace el saneado con un XSS en el nombre) y §48.15 (límite por acción + IP, que frena también la contraseña correcta, y que detrás de un proxy la IP es la del proxy)—.
- **tests/integration/test-account.php**: el pedido creado por una cuenta guarda su `user_id`; el de un invitado queda en 0; los dos pedidos de prueba se borran y se comprueba que no quedan.
- **tests/security/account-security.php**: en 48.18 la sesión es la de A y el POST de `save_step1` declara el `user_id` de B: el pedido guardado sigue siendo de A.

Verificado: `composer test:all` exit 0 (170 unitarias, integración 7 OK + 1 saltada), `composer test:security` 153 pruebas / 0 fallas, `test:front` TODO OK. La migración se aplicó en el sitio (`DB_VERSION` guardada = 3, columna e índice presentes).

## 51cb55b - Fix: hallazgos de las pruebas de seguridad de la cuenta

- **src/Account/Auth.php**: `get_user_by()` devuelve `false`, no `null`. El ternario del login comparaba contra `null`, así que con un correo inexistente hacía `$user->user_login` sobre `false` y el warning de PHP salía impreso en el HTML: la respuesta de un correo que no existe medía distinto que la de uno que sí. Enumeración de cuentas por diferencia de contenido, detectada por la propia prueba. Ahora se comprueba `instanceof WP_User`.
- **src/Account/Pages.php**: `guard_admin()` manda a `/perfil` al cliente sin `edit_posts` que entra a `/wp-admin/` (WordPress le mostraba un escritorio vacío). No toca AJAX ni REST.
- **src/Core/Plugin.php**: `Pages` se instancia también en wp-admin, que es donde corre esa guarda; `Auth` sigue solo en el front.
- **tests/security/account-security.php** (nuevo): 152 pruebas que atacan el sitio por HTTP real, con cookies y nonces de verdad, y miran el estado del servidor con WordPress cargado: autenticación (válida, clave mala, correo inexistente, campos vacíos, correo malformado, espacios), `/perfil` sin sesión, logout y cookie previa al logout, enumeración por contenido y código, `user_id` e IDOR por ruta y query, nonce (sin, vacío, inválido, de otra acción), CSRF con `Origin` externo, XSS reflejado y almacenado en el nombre (perfil y prellenado, cerrando y reabriendo sesión), inyección SQL, teléfono (vacío, texto, HTML, SQL, largo, corto, sin 9, con +56), correo (inválido, con HTML, con espacios, muy largo, puntos seguidos), nombre (vacío, 5.000 caracteres), contraseña (vacía, corta, de 4.000 caracteres, sin fugas en la respuesta), fuerza bruta con el límite medido, sesión y cookie falsificada, cambio A→B en el mismo navegador, `localStorage` (y manipulación de claves sembradas, que no autentican), manipulación de `simple-form` con la cuenta intacta, aislamiento A/B, caché de `/perfil`, open redirect (`redirect_to`/`next`/`return_url`), datos sensibles en respuestas, `role`/`capabilities` ignorados, wp-admin y log de PHP sin contraseñas. Crea sus cuentas y las borra al final, junto con los pedidos que genera.
- **tests/security/run.sh** (nuevo) y **composer.json**: `composer test:security` corre la suite resolviendo el PHP de Local y el socket, después de comprobar que el sitio responde.
- **souls.md** (nuevo): el documento del proyecto en el repo, con el capítulo 48 (pruebas de seguridad), 49 (criterio de aprobación), 50 (mínimas antes de producción) y 51 (regla final). Se excluye del zip en **.distignore**.

Decisiones que quedan documentadas y no se cambiaron: el registro sigue diciendo "Ya existe una cuenta con este correo" (con límite de 5 por minuto) porque sin confirmación por correo cualquier alternativa tendría que mentir sobre el resultado; y `sf_orders` todavía no guarda `user_id` (souls.md §37), que es la entrega siguiente.

## d26c12e - Feat: cuenta del cliente (iniciar sesion, registro y perfil)

- **src/Account/Phone.php** (nuevo): el formato del celular chileno en un solo lugar — nueve dígitos internos, `+56 9 1234 5678` al mostrar. `Ajax::normalize_phone()` pasa a delegar acá, así que el registro y el pedido no pueden divergir.
- **src/Account/Customer.php** (nuevo): los datos del cliente salen de la cuenta de WordPress. Correo de `user_email`, nombre de `first_name` y solo el teléfono en una clave propia de user meta (`simple_form_phone`). Sin tabla ni copia paralela.
- **src/Account/Auth.php** (nuevo): inicio de sesión con `wp_signon()` y registro con `wp_insert_user()`, con nonce, límite de intentos y contraseña mínima de 8. Quien se registra queda con la sesión abierta y va a `/perfil`. Los errores de credenciales son genéricos: no enumeran cuentas. Los campos van con prefijo `sf_` porque `name` es query var pública y WordPress la toma del `$_POST` en `parse_request()`, convirtiendo el envío en una búsqueda por slug (404 antes de leer el formulario).
- **src/Account/Pages.php** (nuevo): `/iniciar-sesion` y `/perfil` usan plantillas del plugin y conservan cabecera, pie y tipografía del tema; el enlace "Iniciar sesion" del menú pasa a "Perfil" según `is_user_logged_in()`; la barra de WordPress se oculta al cliente.
- **src/Activation/AccountPages.php** (nuevo): crea la página `/perfil` una sola vez, también en instalaciones que ya estaban activas. El alta va en `wp_loaded`: en `plugins_loaded` todavía no existe `$wp_rewrite` y `wp_insert_post()` muere al calcular el permalink.
- **src/Core/Plugin.php**: instancia el módulo de cuenta solo en el front y agenda el alta de la página.
- **src/Core/Shortcode.php**: el formulario se pinta con nombre, correo y teléfono del cliente autenticado, leídos en el servidor; el invitado no recibe datos prellenados.
- **templates/account-login.php** y **templates/account-profile.php** (nuevos): formulario centrado sobre `abstract-luxury` con overlay CSS, registro en la misma página (`?form=registro`, sin JavaScript) y perfil de solo lectura con cierre de sesión por `wp_logout_url()`.
- **assets/css/account.css** y **assets/img/abstract-luxury.jpg** (nuevos): estilos propios (todo bajo `sf-account`/`sf-profile`, sin tocar el CSS global) sobre la paleta del tema, y el asset guardado dentro del plugin. La textura de 8000 px se redujo a 2000 px para no bajar 2,6 MB al cliente; el fade es CSS.
- **tests/unit/PhoneTest.php** (nuevo): normalización de las formas que escribe el cliente, rechazo de lo que no es celular chileno y los dos formatos de salida.
- **tests/integration/test-account.php** (nuevo): una sola clave de teléfono, datos desde la cuenta, `save()` que no pisa el teléfono bueno con uno inválido, prellenado del formulario con y sin sesión (soltando el cerrojo de un formulario por proceso) y el cambio del enlace del menú. Crea su usuario y lo borra al final.
- **tests/integration/test-admin-screen.php**: una constante definida y vacía no es una fuente de credenciales; la suite ya no la exige como `constant`.

## 3ffa23d - Feat: empaquetado (readme.txt y .distignore)

- **readme.txt** (nuevo): ficha del plugin en formato de WordPress.org — descripción de los 4 pasos, la cobertura, el pago con Mercado Pago, instalación, preguntas frecuentes y changelog. Incluye el aviso de que desinstalar borra las tablas propias. `Stable tag: 1.0.0`, coherente con la cabecera.
- **.distignore** (nuevo): deja fuera del zip `tests/`, `vendor/`, `deploy/`, `composer.*`, `phpunit.xml.dist`, cachés de PHPUnit, `.gitignore` y la documentación interna (`AUDITORIA.md`, `FIX.md`).

## 9c21c71 - Feat: canal de log propio, apagado por defecto

- **src/Core/Logger.php** (nuevo): un solo punto para los avisos de diagnóstico. Apagado por defecto; se enciende con el filtro `simple_form_logging_enabled` o la constante `SIMPLE_FORM_LOGGING` (que manda sobre el filtro). Escribe con el prefijo `[simple-form][canal]` y nunca credenciales ni datos personales.
- **src/Payments/MercadoPagoGateway.php**, **src/Core/Geocoder.php** y **src/Core/Cleanup.php**: los tres `error_log` pasan por `Logger::log()` con su canal (`mercado_pago`, `geocoder`, `cleanup`); desaparecen los prefijos ad-hoc y la dependencia de `WP_DEBUG`.
- **tests/unit/LoggerTest.php** (nuevo): apagado por defecto (no escribe), encendido por filtro (escribe con el canal) y filtro en falso (sigue apagado), leyendo el log de errores redirigido a un archivo temporal.

## 3f15478 - Feat: correos transaccionales (aprobado y rechazo)

- **src/Mail/Mailer.php** (nuevo): único sitio que envía correos. Se dispara desde `PaymentProcessor` solo cuando una transición cambia de verdad el estado (un reintento del webhook no repite el correo). Un `approved` avisa al comprador (resumen: servicio, radio legible, total CLP entero y fecha) y al administrador (datos de contacto y ubicación, con `Reply-To` al comprador); un `rejected` avisa solo al comprador. El resto de los estados no notifica. Se puede apagar entero con el filtro `simple_form_email_enabled`. El monto va con `number_format` entero (nunca float) y el nombre del remitente se limpia de saltos de línea.
- **src/Payments/PaymentProcessor.php**: tras aplicar una transición, llama a `Mailer::notify()` con el pedido ya movido (estado y `paid_at` aplicados), sin releer de la base.
- **tests/bootstrap.php** y **tests/support/FakeWordPress.php**: doble de `wp_mail` que captura los envíos en memoria (con `reset()` por prueba) para poder asertar destinatarios y contenido sin mandar correos reales.
- **tests/unit/MailerTest.php** (nuevo): 9 pruebas — a quién llega cada correo, el filtro de apagado, la ausencia de envío cuando falta el correo del comprador o del admin, el `Reply-To`, y que el monto y el radio se formatean enteros.

## ccf5cf5 - Feat: pantalla de pedidos en wp-admin, solo lectura

- **src/Admin/AdminOrders.php** (nuevo): menú **Pedidos** (`manage_options`, `dashicons-list-view`). Listado paginado de 20 por página con id, fecha en la zona del sitio, cliente, servicio, radio legible (m/km), monto CLP entero, estado traducido e ID de pago externo; el id enlaza al detalle. Detalle con cliente, snapshot de servicio y precios, ubicación, pago (medio, estado, id, `paid_at`) y la tabla de eventos de pago con su clave de idempotencia. Si el pedido no existe, avisa y ofrece volver. No edita montos ni estados: eso sigue siendo exclusivo del webhook.
- **src/Core/Plugin.php**: instancia `AdminOrders` junto a `AdminServices` y `AdminPayments` dentro de `is_admin()`.

## 7b72bfd - Test: el admin acepta credenciales de la constante o guardadas cifradas

- **tests/integration/test-admin-screen.php**: la suite exigía `source() === 'constant'` y que la pantalla dijera de qué constante sale. Con las credenciales guardadas desde el panel (`source=stored`, cifradas) la comprobación pasa a ser que la fuente sea segura (`constant` o `stored`) y que el render no imprima el secreto: si la fuente es una constante, verifica los avisos de wp-config.php; si es guardada, verifica el campo enmascarado y vacío. El webhook y el resto de las suites vuelven a verde.

## e084158 - Fix: el radio va de 100 m a 1 km y el slider deja de salirse en celular

- **src/Database/Database.php**: `radius_km` pasa de `SMALLINT UNSIGNED` a `DECIMAL(5,2)` y `DB_VERSION` sube a 2. `dbDelta` migra la columna; los pedidos existentes conservan su valor (1.00, 10.00…).
- **src/Core/Ajax.php**: `read_radius_km()` valida de 0,1 a 1 km (100 m a 1 km) y devuelve `float` (0.0 como centinela de inválido); `compute_price()` acepta radio fraccionario y redondea una sola vez para que el monto siga siendo CLP entero; `save_step3` recalcula con `(float)` y deja de truncar el radio a 0 (antes 0,5 km cobraba solo la base).
- **src/Database/OrderRepository.php**: `is_step_complete()` acepta radio >= 0,1 y `to_progress()` entrega `radius_km` como float.
- **src/Payments/MercadoPagoGateway.php**: nuevo `format_radius()` y la descripción de la preferencia queda "Servicio a 500 m" / "Servicio a 1 km" en vez de `%d km` con cast entero.
- **assets/js/config.js**: `SimpleForm.formatRadius()` centraliza la lectura del radio (metros hasta 1 km, después km).
- **assets/js/map.js**: el slider trabaja en metros (100 a 1000) y el círculo usa esos metros; el campo oculto y el payload llevan km decimales.
- **assets/js/{ui,receipt}.js**: el resumen y el recibo pintan el radio con `formatRadius` (antes "0.5 km" crudo).
- **assets/js/validator.js**: la regla del paso 2 pasa a 0,1–1 con `parseFloat` (con `parseInt`, 0,5 km daba 0 y era siempre inválido).
- **assets/js/ui.js** (restauración): al reanudar la sesión, el slider se rellena en metros (`radius_km * 1000`).
- **assets/css/form.css**: en móvil el slider del radio llevaba el pulgar de 26px fuera de su caja; ahora el input va con `min-width: 0` y 14px de margen inline, y el pulgar baja a 24px, así que no se sale ni a 320px.
- **tests/integration/test-ajax-flow.php**: radio 0,5 km y precios nuevos (10.251 con base 10.001 + 500/km), incluida la vista previa, el monto persistido y el `unit_price` de Mercado Pago.
- **tests/front/smoke.mjs**: comprueba rango del slider (100–1000, paso 100), etiqueta inicial "100 m", que la barra no se salga de su caja y que el recibo muestre el radio en metros ("300 m").

## 8d30dbb - Fix: endurecimiento del sitio (etapa fix)

- **deploy/mu-plugins/sf-hardening.php** (nuevo): bloquea XML-RPC antes de procesar la petición (403), retira `/wp/v2/users` a visitantes y desactiva el sitemap de usuarios, redirige `/author/…` a la portada, añade `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` y `Permissions-Policy` (HSTS solo sobre HTTPS), deja CORS únicamente para el origen propio y elimina `X-Powered-By` y el `generator`. Se instala copiándolo a `wp-content/mu-plugins/`.
- **FIX.md** (nuevo): estado de los 16 hallazgos de la auditoría, lo parcheado y lo que queda en el hosting (actualizaciones de núcleo y plugins, nginx, wp-cron, SameSite).

## 2e82e96 - Fix: layout centrado del formulario y resumen dentro del paso 2

- **src/Core/Shortcode.php**: el `aside.pn-summary` deja el layout lateral y pasa al inicio del panel del paso 2. El resumen solo existe en "Servicio y ubicación"; en los pasos 1, 3 y 4 ya no se pinta.
- **assets/css/form.css**: `.pn-form-layout` pasa de grid `1fr 300px` a una columna; `.pn-form` queda centrado con `max-width: 780px`. El resumen se convierte en una tarjeta horizontal de tres columnas (servicio, radio, precio) arriba del paso 2, y el mapa conserva su altura de 420 px ocupando el ancho del recuadro (`margin: 0 auto`). En móvil el resumen pasa a una columna. Sin cambios de paleta ni tipografía.

## 4379571 - Fix: el botón Siguiente dejaba de responder tras retroceder, y el paso a mostrar deja de saltarse pasos

- **assets/js/ui.js**: `setLoading( wrapper, false )` limpia ahora **todos** los botones de navegación, no solo el activo. `goNext` cambia de paso (`renderCurrentStep`) antes del `finally`, así que al limpiar el botón que se había deshabilitado ya no era el activo —o no había ninguno visible, como en la confirmación—: quedaba `disabled` y con `is-loading` para siempre. Efecto visible: al retroceder desde el paso 3 (o el 1) el "Siguiente" no respondía a ningún clic y no se podía volver a avanzar.
- **assets/js/stepper.js**: el paso a mostrar es el siguiente al que se acaba de guardar, no el `current_step` que devuelve el servidor. Ese campo es el punto de reanudación de la sesión y no baja nunca (`max( 2, current_step )` / `max( 3, current_step )`), así que al retroceder y volver a avanzar el cliente saltaba al paso más lejano ya alcanzado y se saltaba los intermedios.
- **assets/js/map.js**: el `catch` de `requestPrice()` comprueba el token de petición igual que la rama de éxito, para que una respuesta fallida lenta no pise el estado de una más nueva (dejaba el precio en `$0` y el error con el pin en un punto válido).

## e2f1afe - Fix: el resumen y el paso 2 muestran $0 cuando la ubicación no es válida, y el contorno de dibujo pesa 8 veces menos

- **src/Data/chile-boundary.json**: añade `outline`, el contorno de dibujo de Natural Earth 1:50m (31 anillos, 2.006 puntos), junto a `rings`, el de validación de 1:10m (163 anillos, 17.197 puntos). Las `focus_bounds` del mapa salen del contorno de dibujo.
- **src/Core/Coverage.php**: `outline()` (dibujo) y `rings()` (validación), ambos sobre un único `data()` que decodifica el archivo una sola vez por petición; `section()` concentra la lectura y el chequeo de cada sección. Documenta que la validación siempre usa `rings()`.
- **src/Core/Ajax.php**: `get_coverage_area` envía `outline` en lugar de `rings`, así que la respuesta pasa de 333 KB a 38,9 KB (medido en el navegador: 190 ms en caliente). El contorno de validación ya no viaja al navegador.
- **assets/js/map.js**: cada anillo se dibuja envuelto en su propio polígono, porque `L.polygon()` interpreta los anillos siguientes como agujeros del primero y Chile es un multipolígono (continente, Tierra del Fuego, archipiélagos). Además, cuando el servidor rechaza la ubicación, el paso 2 y el resumen quedan en `$0` con el motivo a la vista, en lugar de mantener el monto de la ubicación anterior; al volver a un punto válido el precio se restablece y el error desaparece.

## b419843 - Feat: el área de cobertura es el contorno real de Chile

- **src/Data/chile-boundary.json** (nuevo): contorno de Chile de Natural Earth 1:10m (dominio público), 163 anillos y 17.197 puntos, sin simplificar, en pares `[lat, lng]` como los usa Leaflet, más las bounds para encuadrar la vista.
- **src/Core/Coverage.php** (nuevo): `contains()` resuelve la cobertura con *point-in-polygon* (ray casting) sobre todos los anillos y, si el punto cae fuera, acepta cualquier ubicación a menos de `TOLERANCE_KM` (2 km) del borde dibujado: el contorno viene generalizado y con 1:10m Punta Arenas queda a 0,66 km del borde del dato. `rings()` y `bounds()` alimentan el mapa. Una consulta cuesta ~0,9 ms.
- **src/Core/Ajax.php**: `get_coverage_area` devuelve `type: polygon` con los anillos y las bounds; `save_step2` y `calculate_price` validan con `Coverage::contains()`; desaparecen el círculo fijo de 50 km, `distance_km()` y `is_within_coverage()`. El endpoint fija `serialize_precision` en -1 porque php-fpm lo trae en 17 y cada coordenada se imprimía con 17 dígitos: 680 KB de respuesta que ahora son 333 KB.
- **assets/js/map.js**: dibuja el polígono que decide el servidor, encuadra la vista con sus bounds y ya no pide el área en cada movimiento del pin (el área es el país, no sigue al marcador).
- **src/Core/Assets.php** y **assets/js/config.js**: la vista inicial y el pin de partida quedan en coordenadas de país con zoom 4; el encuadre definitivo lo impone el contorno.
- **src/Data/index.php** (nuevo): guardia "Silence is golden" del directorio.

## 463cf3f - Fix: el recibo muestra el estado y la fecha reales, y el resumen se actualiza en vivo

- **src/Payments/OrderStatus.php**: nuevo `label()`, la etiqueta traducida de cada estado, para que el front no tenga que decidir textos.
- **src/Database/OrderRepository.php**: `to_progress()` incorpora `status`, `status_label` y `date`; `receipt_date()` formatea en la zona horaria y con el formato del sitio, usando `paid_at` si existe y `created_at` mientras no haya pago.
- **src/Core/Shortcode.php**: el badge nace en "Pago pendiente" en lugar de "Pago confirmado", para que un fallo de JS no anuncie un pago inexistente.
- **assets/js/receipt.js**: pinta el badge desde `status`/`status_label` (validando la forma del estado antes de usarlo como clase) y la fecha desde el servidor; deja de generar la fecha en el navegador.
- **assets/js/map.js**: emite `sf:price-updated` en cuanto el servidor confirma un precio.
- **assets/js/ui.js**: escucha ese evento y refresca el resumen lateral sin esperar a que se guarde el paso.
- **assets/js/config.js**: selector `receiptBadge` y evento `priceUpdated`.
- **assets/css/form.css**: modificadores del badge por estado; el color base pasa a ser el de `pending` y `--approved` conserva el verde original.

## afa3787 - Fix: planes administrables en el admin y assets sin CDN de terceros

- **src/Admin/AdminServices.php**: el admin permite añadir y eliminar filas de planes (los índices no se reutilizan, así que borrar no renumera), guarda las tarifas en CLP entero (`absint` + `round`, `step="1"`), descarta filas sin ID, IDs duplicados, filas que no son arrays y valores no numéricos, y encola su JS solo en su pantalla.
- **assets/js/admin-services.js** (nuevo): clona la fila modelo, sustituye el marcador `__NEXT__` por el índice correspondiente y elimina filas. La fila modelo vive fuera del formulario, así que nunca se envía.
- **assets/fonts/poppins-*.woff2** y **assets/css/form.css**: Poppins empaquetada (5 pesos, subconjunto latin) y se elimina el `@import` a Google Fonts.
- **assets/vendor/leaflet/**: Leaflet 1.9.4 empaquetado con su CSS y sus imágenes.
- **assets/js/config.js**: Leaflet se carga desde las URLs que entrega el servidor; desaparecen las constantes del CDN.
- **src/Core/Assets.php**: `SimpleFormConfig` publica las URLs de Leaflet y `asset_version()` pasa a público y estático para que el admin reuse la regla de cache-busting.

## 51876b0 - Fase 2: pasos validados contra la base de datos

- **src/Core/Session.php**: el transient deja de guardar datos del cliente y pasa a ser un puntero hacia el `draft_id` del pedido (2 horas renovables por actividad); desaparecen `save_step()`, `get()` y `previous_steps_valid()`. Se conserva `user_id` para la comprobación de propiedad entre usuarios autenticados.
- **src/Database/OrderRepository.php** (nuevo): `create_draft()`, `find_by_draft_id()`, `update()`, `is_step_complete()` —la validación de pasos anteriores leída de la fila, en lugar de los `stepN_valid` del transient— y `to_progress()`, que arma los shapes `step1`/`step2`/`step3` que ya consumen `stepper.js`, `ui.js` y `receipt.js`.
- **src/Core/Ajax.php**: `save_step1/2/3` y `get_progress` escriben y leen `sf_orders`; el monto se recalcula siempre con el snapshot guardado y se persiste en CLP entero (`compute_price()` deja de devolver `float`); `save_step3` deja el pedido en `pending` pasando por `OrderStatus::can_transition()`, así que `approved` no puede volver a `pending` (409); los mensajes de error que faltaban quedan internacionalizados.

## 7d0189a - Fase 1: uninstall.php (paso 4)

- **uninstall.php**: al desinstalar borra `wp_sf_orders` y `wp_sf_payment_events`, las opciones `simple_form_services` y `simple_form_db_version`, y los transients de sesión `_transient(_timeout)_sf_client_session_*`; guardia `WP_UNINSTALL_PLUGIN` e inclusión manual de `Database` porque en ese contexto no existe el autoloader del plugin (así los nombres de tabla no se duplican).

## 3be3da9 - Fase 1: esquema propio (Database, sf_orders y sf_payment_events)

- **src/Database/Database.php**: clase `SimpleForm\Database\Database` con `table_name()`, `create_tables()` vía `dbDelta()` y `maybe_upgrade()`; define `sf_orders` y `sf_payment_events` con los campos de souls.md §12.2, montos CLP en `BIGINT` (nunca float), fechas UTC, snapshot de servicio y precios, y `UNIQUE` en `draft_id` y `external_event_id` (clave de idempotencia).
- **src/Activation/Activate.php**: `create_tables()` deja de ser un placeholder y delega en `Database::create_tables()`, para que la activación y la migración en caliente usen la misma definición.
- **src/Core/Plugin.php**: en `plugins_loaded` se comprueba `simple_form_db_version` contra `Database::DB_VERSION` y se migra si no coinciden.
- **src/Database/index.php**: guardia "Silence is golden" del directorio.
- **src/Database/class-database.php** (eliminado): esqueleto inalcanzable por el autoloader (namespace `SimpleForm\Core` en `src/Database/`) y roto (`$table` y `$charset` sin definir, `require` de `upgrade.php` sin la "s").

## 5e48677 - Fase 1: máquina de estados del pedido (OrderStatus)

- **src/Payments/OrderStatus.php**: los 9 estados internos del pedido y un único mapa de transiciones del que salen `all()`, `is_valid()`, `is_final()` y `can_transition()`; `approved` no vuelve a `pending` y `draft` no puede saltar a `approved`, así que `save_step3` no tiene forma de marcar un pedido como pagado.
- **src/Payments/index.php**: guardia "Silence is golden" del directorio nuevo, igual que en `Core/` y `Activation/`.

## ef0bb86 - Inicial: plugin simple-form (raíz = plugin)

- **simple-form.php**: cabecera y versión única, constantes (PATH/URL/BASENAME), autoload PSR-4 propio y hooks de activación/desactivación; arranque en `plugins_loaded`.
- **index.php**: guardia "Silence is golden".
- **assets/index.php, assets/css/index.php, assets/js/index.php, src/index.php, src/Core/index.php, src/Activation/index.php**: guardias "Silence is golden".
- **assets/css/form.css**: estilos del formulario de 4 pasos, stepper, resumen lateral y recibo; prefijos `pn-*`, `map-plugin__*` y variables `--pn-*`.
- **assets/js/config.js**: núcleo del front — selectores centralizados, eventos `sf:*`, estado en memoria, `request()` único para AJAX, `sf_session_id` en localStorage y carga diferida de Leaflet.
- **assets/js/validator.js**: validación de cliente por paso (solo UX; el servidor revalida); textos visibles aún hardcodeados.
- **assets/js/ui.js**: única capa que toca el DOM — `showStep`, `updateNav`, `renderErrors`, `setFeedback`, `setLoading`, `fillStepData`, `updateSummary`; emite `sf:step-visible`.
- **assets/js/receipt.js**: pinta el paso 4 desde `progress`; la fecha se genera en el navegador y el badge "Pago confirmado" es fijo (pendiente Fase 6).
- **assets/js/map.js**: Leaflet bajo demanda, pin arrastrable, círculo de radio y área de cobertura desde el servidor; el precio nunca se calcula en cliente; debounce + token anti-respuestas viejas.
- **assets/js/stepper.js**: orquestador del flujo — restaura sesión, valida y guarda por paso; libera el puntero de sesión al llegar al paso 4 (B-07).
- **src/Core/Plugin.php**: singleton que instancia `Assets`, `Ajax`, `Shortcode` y, solo en wp-admin, `AdminServices`.
- **src/Core/Assets.php**: registra CSS/JS con dependencias encadenadas, expone `SimpleFormConfig` (ajaxUrl, nonce, acciones) y encola desde el shortcode.
- **src/Core/Shortcode.php**: `[simple_form_form]` — HTML de los 4 pasos, `select` de servicios y un solo formulario por página.
- **src/Core/Ajax.php**: 6 endpoints con nonce; sanitización de entrada, validación de pasos, precio y cobertura calculados en servidor; `is_numeric()` antes de castear lat/lng y radio (B-05) y transient creado recién con la entrada validada (B-06).
- **src/Core/Session.php**: sesión en transient `sf_client_session_*` (2 h renovables) con `create`, `get`, `save_step`, `previous_steps_valid` y `sanitize_id`.
- **src/Admin/AdminServices.php**: pantalla "Servicios de pago" en `simple_form_services`; `manage_options` + `check_admin_referer` y redirección a URL fija.
- **src/Activation/Activate.php**: comprobación de PHP 7.4 y `create_tables()` aún vacío.
- **src/Activation/Deactivate.php**: `deactivate()` faltante — su ausencia provocaba un fatal al desactivar el plugin.
- **src/Database/class-database.php**: esqueleto incompleto de `Database`; nombre de archivo y namespace no coinciden con el autoloader y `create_table()` tiene `$table`/`$charset` sin definir y el `require` de `upgrade.php` sin la "s"; queda para Fase 1.
