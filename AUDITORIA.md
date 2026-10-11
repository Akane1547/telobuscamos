# Auditoría del plugin simple-form

Fecha: 2026-10-08 · Rama: `fase-6` · Alcance: código, estructura y seguridad.

## Cómo se hizo

Se leyó el plugin entero (79 archivos versionados, 7.389 líneas PHP y 2.181 de JS/CSS)
y se cruzó con las reglas de `souls.md`. Los patrones peligrosos se buscaron de forma
sistemática (SQL sin `prepare`, entrada sin `wp_unslash`, salida sin escapar, `innerHTML`,
credenciales en logs, capacidades y nonces) y lo que ya está cubierto por pruebas se
verificó ejecutándolas: **140 unitarias y 6 suites de integración en verde**, más las
verificaciones en el navegador de la Fase 6 y la del webhook con pagos reales.

Severidades: **Alta** = corrompe datos, cobra mal o rompe el flujo · **Media** = riesgo
real pero acotado, o deuda que va a doler · **Baja** = higiene, coherencia o cosmética.

---

## 1. Lo que está bien (y conviene no tocar)

- **El monto nunca viene del cliente.** `save_step2` calcula con el snapshot del
  servicio (`Ajax.php:384-398`) y `save_step3` recalcula desde el pedido guardado
  (`Ajax.php:452-457`). El hidden `estimated_price` que manda el formulario se ignora.
- **El estado solo lo mueve la pasarela.** Ningún camino de `save_step3` marca
  `approved`, y `OrderStatus::can_transition()` es el único árbitro.
- **El webhook es serio**: firma verificada, pago confirmado contra la API, transición
  validada e idempotencia por `external_event_id` con clave única. Probado con dos pagos
  reales aprobados y con el reenvío del mismo evento (no se aplica dos veces).
- **SQL**: todas las consultas con valores usan `prepare()`. Los nombres de tabla salen
  de constantes de clase, nunca de entrada.
- **Credenciales**: mandan las constantes de `wp-config.php`; lo guardado se cifra con
  libsodium y una clave derivada de los salts; si no se puede cifrar **no se guarda
  nada** (`PaymentConfig.php:167-177`). Nada de esto viaja al front.
- **Salida y DOM**: cero `innerHTML`, `eval` o `document.write` en todo el JS.
- **Cobertura**: sin el archivo de contorno no se acepta ninguna ubicación (lado seguro).
- **Rate limit y limpieza programada** con pruebas que las ejercitan de verdad (429 real,
  expiración real de un borrador viejo).
- **Los logs no llevan credenciales ni datos personales**: los dos únicos `error_log`
  están detrás de `WP_DEBUG` y escriben conteos y mensajes de la pasarela.

---

## 2. Hallazgos

### Alta

**A-1 · Un pedido ya pagado se puede reescribir desde el formulario.**
`save_step1` y `save_step2` no miran el estado del pedido: con la sesión viva
(el token dura 2 h y el navegador la conserva) se puede volver al paso 1 o 2 y guardar
de nuevo. En un pedido `approved` eso reescribe los datos del cliente y, peor, el
snapshot del servicio, la ubicación y **el monto** (`Ajax.php:306-319` y `388-401`),
dejando la base en desacuerdo con lo que Mercado Pago cobró. `save_step3` sí está
protegido (`Ajax.php:467-473`), así que la asimetría es un descuido, no una decisión.
*Evidencia (medida):* sobre un pedido de prueba en `approved` con monto 15.000, una
llamada a `save_step2` con otra ubicación y radio 20 lo dejó en **810.000** y con las
coordenadas nuevas, sin tocar el estado.
*Arreglo:* rechazar los pasos 1 y 2 cuando `! OrderStatus::is_awaiting( $order['status'] )`
(409), como ya hace el paso 3.
*Resuelto:* `save_step1` y `save_step2` responden **409** cuando el estado no espera
veredicto, y `test-ajax-flow.php` lo prueba: sobre un pedido `approved` el monto, el radio,
la ubicación y los datos del cliente quedan intactos, y un pedido `pending` se sigue
editando (el caso positivo, para que el guard no rompa el flujo).

**A-2 · Al retomar una sesión, el mapa pisa la ubicación guardada.**
`restoreSession()` rellena los inputs `lat`/`lng` con lo que devolvió el servidor
(`ui.js:143-149`), pero después `buildMap()` construye el mapa en
`SF.defaultCenter` (Santiago) y hace `storePosition( marker.getLatLng() )`
(`map.js:253-270`), o sea: sobrescribe esos valores con Santiago. El comprador que
recarga a mitad del paso 2 pierde su ubicación sin aviso y, si sigue, el pedido guarda
**la dirección equivocada**. Para un servicio que va a domicilio, eso manda al operador
a otra ciudad. (El precio no cambia: sale del servicio y del radio, no de la
ubicación.) Es el B-08 de `souls.md`, con consecuencia real.
*Evidencia (medida):* con un pedido de prueba guardado en Valparaíso (−33.05 / −71.61),
la página restauró el paso 2 y los campos quedaron en −33.4489 / −70.6693, las
coordenadas de Santiago.
*Arreglo:* si el pedido ya tiene coordenadas, centrar el mapa y el pin ahí; usar
`defaultCenter` solo cuando no hay ninguna.
*Resuelto:* `map.js::storedPosition()` lee las coordenadas guardadas antes de construir el
mapa, y el pin y el círculo del radio arrancan ahí; el centro por defecto queda solo para un
pedido nuevo. Verificado en el navegador: con un pedido en Valparaíso, los campos ahora
quedan en −33.05 / −71.61.

### Media

**M-1 · Los tiles del mapa se piden a OpenStreetMap.**
`map.js:255` carga `https://{s}.tile.openstreetmap.org/...`. Leaflet y la tipografía
están empaquetados por decisión del proyecto ("cero terceros"), pero el mapa sigue
dependiendo de un servicio externo: si su política de uso lo corta, el paso 2 se queda
sin mapa. *Arreglo:* decidir un proveedor de tiles propio o con clave, o documentarlo
como dependencia aceptada.
*Resuelto (decisión):* se acepta como dependencia, por coherencia con el geocodificador
(Photon también es OSM) y porque el tráfico de un negocio local entra en su política de uso.
Queda anotado en `map.js`, en la línea que lo carga. Si el sitio crece, el cambio es esa
línea y hay que elegir proveedor con clave.

**M-2 · No hay guard de estado en las escrituras de los pasos 1 y 2.** Es A-1, listado
aparte porque el arreglo es una sola condición y conviene tratarlo como una unidad.
*Resuelto:* con A-1: es el mismo arreglo en `Ajax.php`, no una tarea aparte.

**M-3 · El secreto se escribe en un campo de texto visible.**
`AdminPayments.php:276` y `:297` usan `type="text"` para el Access Token y la clave
secreta: el valor queda a la vista al tipearlo y expuesto a autocompletado y a
extensiones del navegador. *Arreglo:* `type="password"`, `autocomplete="new-password"`,
`spellcheck="false"`.
*Resuelto:* los dos campos son `type="password"` con `autocomplete="new-password"` y
`spellcheck="false"`; la suite del admin sigue comprobando que la pantalla no imprime
ningún valor.

**M-4 · Guardar credenciales siempre se reporta como falta de HTTPS.**
`handle_save_credentials()` redirige `error=https` pase lo que pase
(`AdminPayments.php:444-447`), pero `PaymentConfig::save()` devuelve `false` por tres
motivos distintos (HTTPS, cifrado no disponible, pasarela desconocida). El admin ve un
diagnóstico equivocado. *Arreglo:* que `save()` devuelva el motivo y la pantalla lo
muestre.
*Resuelto:* `PaymentConfig::save()` devuelve el motivo (`'https'`, `'crypto'`, `'unknown'` o `''`)
y el panel lo usa tal cual: ya tenía un aviso por cada caso, pero el productor mandaba `https`
siempre. Los tests unitarios fijan los tres motivos.

**M-5 · El teléfono no se valida en el servidor.**
`save_step1` solo comprueba que no venga vacío (`Ajax.php:295`). El cliente sí aplica
`/^9\d{8}$/` (`validator.js:39`), pero el servidor acepta cualquier cosa: entra un
`+56` pegado, letras o un número de otro país y queda en el pedido y en el recibo.
*Arreglo:* normalizar (quitar espacios y el `+56`) y validar 9 dígitos que empiecen con 9.

**M-6 · El front no tiene pruebas automatizadas.**
Todo el JS se verificó a mano en el navegador (y así se encontraron A-2 y las dos
regresiones de la Fase 6), pero nada lo cubre en CI. Los fallos de UI de esta sesión se
detectaron por suerte y a mano. *Arreglo:* al menos un guion reproducible de los flujos
(1→4, retorno aprobado, retorno pendiente) que se pueda correr con el sitio arriba.

**M-7 · Las cadenas visibles del front están escritas en el JS.**
`validator.js` (todos los mensajes de error) y `stepper.js`/`ui.js` en lo que se agregó
después. `souls.md` §10 dice que salen de `SimpleFormConfig.i18n`; la Fase 6 implementó
`i18n` para lo nuevo, así que hoy conviven los dos criterios. *Arreglo:* mover los
mensajes de `validator.js` a `i18n` y usar la misma convención.

### Baja

- **B-1 · `simple_form_payment_credentials` es una opción autoload.** El criptograma se
  carga en cada petición y viaja en `alloptions`. Es cifrado, así que el impacto es bajo;
  `update_option( ..., false )` lo saca (`PaymentConfig.php:181`).
- **B-2 · `sanitize_text_field` sobre credenciales.** Para un Access Token de Mercado
  Pago (`APP_USR-…`) no rompe, pero es un sanitizador pensado para texto visible:
  conviene un saneo neutro (recortar y rechazar espacios internos) para no depender de
  que el emisor use solo caracteres seguros (`AdminPayments.php:440`).
- **B-3 · Código muerto.** `PaymentEventRepository::find_by_order()` sin consumidores y
  `OrderStatus::is_final()` sin uso desde la Fase 6. Se pueden dejar como API interna o
  borrar; hoy no los llama nadie.
  *Resuelto (decisión):* se mantienen los dos, y no por descuido. `find_by_order()` es la
  lectura del historial de eventos de un pedido (el consumidor que falta es la pantalla de
  pedidos) e `is_final()` es la invariante que usan las pruebas de la máquina de estados
  para comprobar que el grafo es coherente. Los dos tienen pruebas propias.
- **B-4 · El error de `setFeedback` no es defensivo.** `ui.js:100-102` hace
  `el.textContent` sin comprobar que el elemento exista, a diferencia del resto del
  archivo. Con un tema que quite `.pn-form__feedback` se rompe el handler.
- **B-5 · `estimated_price` viaja al servidor y se ignora.** Es ruido de contrato: el
  formulario manda un precio que el servidor no lee (`Shortcode.php:166`).
- **B-6 · Comentario desactualizado en `map.js`.** Dice que los errores solo van a
  consola, pero desde hace varias fases también se pintan en el formulario
  (`map.js:18-19` contra `:155`).
- **B-7 · Mensajes del nonce sin traducir.** `Ajax.php:66` devuelve una cadena en
  español fija, sin `__()`, a diferencia del resto.
- **B-8 · Códigos HTTP desparejos.** `save_step2` responde **403** cuando el paso 1 está
  incompleto (`Ajax.php:360`): es validación, no permiso (422 como el resto).
- **B-9 · Sin tope de filas en servicios.** `handle_save()` guarda tantas filas como
  lleguen en un array autoload (`AdminServices.php:184-208`). Es solo-admin, pero un
  envío enorme infla la opción.
- **B-10 · `service_prices()` pasa por `float` innecesariamente.** La opción ya guarda
  enteros (`AdminServices.php:203-204` con `absint()`), así que el `(int) round((float) …)`
  de `Ajax.php:138-143` no aporta y contradice el "nunca float para dinero".
- **B-11 · El payload guardado puede cortarse a mitad de JSON.**
  `PaymentEventRepository::payload()` recorta a 65.535 caracteres
  (`PaymentEventRepository.php:147`): si algún día se guarda un payload grande, queda un
  JSON inválido en la columna.
  *Resuelto:* un payload que no entra ya no se corta: se guarda
  `{"truncated":true,"bytes":N,"reason":"payload_too_large"}`, que es JSON válido y dice
  lo único que se puede afirmar sin inventar datos.
- **B-12 · `ini_set('serialize_precision')` es best-effort.** Si el hosting lo prohíbe,
  `get_coverage_area` responde el doble de grande (`Ajax.php:271`).
- **B-13 · Estilo e indentación mezclados.** `Ajax.php` (líneas 91, 223, 363, 368),
  `validator.js` (a partir de la línea 78) y `config.js` (línea 122). Cosmético, pero
  ensucia los diffs.
- **B-14 · Desinstalar borra los pedidos.** `uninstall.php` hace `DROP TABLE` sobre
  `sf_orders` y `sf_payment_events`: es la decisión tomada, pero un desinstalar por
  error se lleva el historial de pagos. Conviene que la pantalla de plugins lo advierta
  en la descripción del plugin.
- **B-15 · El nonce no está atado a la sesión.** Es la limitación conocida de WordPress
  (S-03 de `souls.md`): una página cacheada puede servir el nonce de otro visitante. El
  rate limit y la validación en servidor lo compensan; queda documentado.
  *Resuelto (aceptado):* no se puede arreglar sin salir del mecanismo de nonces de
  WordPress. Lo que protege de verdad ya está: el nonce no decide nada, cada endpoint
  valida su entrada, el monto y el estado los calcula el servidor y el rate limit corta el
  abuso. Queda como limitación conocida, no como pendiente.

---

## 3. Orden propuesto de resolución

1. ✅ **A-1** — hecho: guard de estado en los pasos 1 y 2, con prueba de integración que
   reproduce la vulnerabilidad medida y cubre el caso positivo.
2. ✅ **A-2** — hecho: el mapa respeta la ubicación guardada; verificado en el navegador
   contra el mismo caso que lo destapó.
3. ✅ **M-3 y M-4** — hecho: campos `type=password` con `autocomplete=new-password`, y el panel
   dice el motivo real del fallo (antes culpaba siempre al HTTPS).
4. **M-5** (teléfono en servidor) y **B-8** (códigos HTTP) — cierran la validación.
5. **M-6** (guion reproducible de los flujos del front) — antes de tocar más el JS.
6. **M-1** (tiles) — decisión de producto, no de código: hay que elegir proveedor o
   aceptar la dependencia por escrito.
7. **B-1 a B-15** — se agrupan en una pasada de higiene cuando el resto esté cerrado.

## 4. Fuera de alcance de esta auditoría

- La política de datos y retención (S-04) sigue en conversación con el cliente.
- No se auditó la infraestructura del sitio (Local, túneles ngrok/cloudflared, `wp-config.php`),
  solo el plugin.
- La verificación de la firma del webhook se hizo contra la simulación del panel y contra
  notificaciones firmadas por nosotros; queda pendiente ver una notificación real de
  Mercado Pago aceptada sin intervención (los reintentos no volvieron a llegar).
