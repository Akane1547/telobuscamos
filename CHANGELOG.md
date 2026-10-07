# Cambios

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
