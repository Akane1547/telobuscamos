# Cambios

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
