# Cambios

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
