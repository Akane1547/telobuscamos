=== Simple Form ===
Contributors: brunope
Tags: formulario, pago, mercadopago, servicios, mapa
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.7
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Formulario de pago en 4 pasos con Mercado Pago, cobertura por mapa y radio de búsqueda.

== Description ==

Simple Form es un formulario de pedido en cuatro pasos pensado para servicios que van a domicilio. El cliente completa sus datos, elige el servicio y marca la ubicación en un mapa, selecciona el medio de pago y confirma.

* **Paso 1 — Tus datos**: nombre, correo, teléfono chileno (+56) y una descripción opcional.
* **Paso 2 — Servicio y ubicación**: servicio a elegir, mapa con el contorno real de Chile y un radio de búsqueda de 100 m a 1 km. El precio se calcula en el servidor (nunca en el navegador).
* **Paso 3 — Medio de pago**: Mercado Pago Checkout Pro.
* **Paso 4 — Confirmación**: recibo con el estado real del pago.

El plugin guarda cada pedido con un snapshot del servicio y del precio, para que editar un servicio no altere pedidos ya hechos. El estado del pago lo decide siempre el servidor: una notificación de Mercado Pago se verifica por firma y se consulta contra la API antes de mover el pedido.

**Administración**: pantalla propia para los servicios (etiqueta, precio base y precio por kilómetro), para las credenciales de pago (cifradas con libsodium, o desde `wp-config.php`) y una pantalla de pedidos de solo lectura.

**Aviso**: al desinstalar el plugin se borran sus propias tablas de pedidos y de eventos de pago.

Usa el shortcode `[simple_form_form]` para mostrar el formulario (un formulario por página).

== Installation ==

1. Sube la carpeta `simple-form` a `wp-content/plugins/` o instala el zip desde *Plugins → Añadir nuevo → Subir plugin*.
2. Actívalo.
3. En *Pedidos → Servicios* define los servicios (etiqueta, precio base y precio por km).
4. En *Pedidos → Pagos* carga las credenciales de Mercado Pago (Access Token y Webhook Secret) y elige el modo sandbox o producción. También puedes definirlas como constantes `SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN` y `SIMPLE_FORM_MERCADO_PAGO_WEBHOOK_SECRET` en `wp-config.php`.
5. Inserta `[simple_form_form]` en la página donde quieras mostrar el formulario.

El webhook de Mercado Pago queda en `https://tu-dominio/wp-json/simple-form/webhook`. Configúralo en el panel de Mercado Pago con esa URL.

== Frequently Asked Questions ==

= ¿Qué pasa si el sitio no tiene HTTPS? =

En modo producción Mercado Pago exige URLs HTTPS para el retorno y las notificaciones. El plugin no guarda credenciales de producción si la URL del sitio no es HTTPS; en modo sandbox sí funciona.

= ¿Los pedidos históricos se alteran al cambiar un servicio? =

No. Cada pedido guarda un snapshot de la etiqueta, el precio base y el precio por km en el momento de la compra.

= ¿Por qué el formulario no muestra medios de pago? =

Porque todavía no se configuró ninguno en *Pedidos → Pagos*. Hasta que no haya un medio habilitado, el formulario no ofrece ninguno.

== Changelog ==

= 1.6.0 =
* **Medios de pago propios**: además de las pasarelas del plugin, el administrador puede agregar medios con un nombre (transferencia bancaria, efectivo, lo que sea) y un "cómo pagar". Se suman a la lista y el cliente los ve en el Paso 3.
* Un medio propio **no cobra online**: el pedido queda pendiente, el recibo muestra cómo pagar y el administrador lo confirma con **Marcar pago recibido** en Pedidos. Un cobro de Mercado Pago no se puede confirmar a mano.
* **Corregido**: con un rango de radio menor a 100 m el Paso 2 nunca se daba por completo (`is_step_complete()` exigía 0,1 km escrito a mano), así que el formulario se trababa al avanzar. Ahora usa el rango configurado.
* **Corregido**: un radio por debajo del mínimo se redondeaba hacia arriba y se aceptaba (5 m pasaba como 10 m).

= 1.5.0 =
* **Medios de pago con alta y baja**: la pantalla pasa a ser una lista de medios con un botón *Añadir medio de pago* (elige entre los proveedores registrados) y un *Quitar* por fila. Guardar deja habilitados exactamente los medios que están en pantalla; dar de baja uno no borra sus claves.
* **Cada medio guarda sus credenciales por modo**: pruebas y comercial tienen su propio juego cifrado. Cambiar de modo y guardar ya no pisa las claves del otro: se puede ir y volver sin volver a escribirlas.
* **Modo comercial**: se elige por medio en el panel. Sin HTTPS la elección y las claves quedan guardadas, pero el cobro real no se activa (Mercado Pago rechaza retornos por HTTP) y el aviso lo explica.
* Las credenciales siguen cifradas con libsodium y el formato viejo se migra solo al activar el plugin.

= 1.4.1 =
* **El rango del radio sí llega al formulario.** `config.js` no publicaba `radius` en `window.SimpleForm`, así que el validador del navegador seguía con el 0.1–1 fijo y rechazaba o aceptaba de más según el rango configurado.
* **Un guardado rechazado ya no borra lo que escribiste**: la pantalla vuelve con los valores del intento y el aviso dice con números qué corregir (por ejemplo, "el paso no puede superar el rango disponible (90 m), y escribiste 100 m"). Antes volvía con los valores guardados y el siguiente intento guardaba esos: parecía que la pantalla no hacía nada.
* **Un borrador guardado con un radio que el rango nuevo ya no permite** se ajusta al máximo y vuelve al Paso 2, con el slider, la etiqueta, el campo oculto y el resumen coincidiendo y el precio recalculado por el servidor.

= 1.4.0 =
* **Menú general SimpleForm**: las secciones dejan de ser menús sueltos y cuelgan de uno solo (Servicios de pago, Medios de pago, Radio de búsqueda, Catálogos del objeto y Pedidos). Los slugs no cambian.
* **Radio de búsqueda configurable**: pantalla nueva para fijar el mínimo, el máximo y el paso del slider del mapa. El formulario, el validador del navegador y la validación del servidor leen el mismo rango: un radio fuera de rango se rechaza con 422 aunque se mande a mano.
* **Buscador de direcciones**: la lista de sugerencias ya no arrastra las de la búsqueda anterior. Al escribir se cierra y solo se muestran las direcciones de la consulta actual; el pin de la última elegida no se toca. El mapa no cambia en nada más.

= 1.3.0 =
* **Paso 1 con el objeto**: "¿Qué tipo de objeto es?", descripción del objeto, tamaño y material. Los tres selects salen de listas que edita el administrador en *Catálogos del objeto*, y llegan al formulario **por AJAX con nonce**: las opciones no viajan en el HTML de la página.
* El servidor guarda el **id** de la lista, no texto libre, y lo valida contra el catálogo antes de guardar (un id inventado se rechaza con 422 y el campo marcado).
* Cada pedido guarda tipo, tamaño y material; el detalle en wp-admin y el correo al administrador muestran qué objeto hay que buscar.

= 1.2.0 =
* **Confirmación por correo al registrarse**: la cuenta queda pendiente y solo se activa al abrir el enlace del correo y elegir la contraseña. Así nadie puede quedarse con una cuenta ajena escribiendo su correo, y la respuesta del formulario ya no delata si el correo existe.
* **Recuperar contraseña** desde la misma página (`/iniciar-sesion?form=recuperar`), con aviso neutro: no dice si la cuenta existe.
* **Perfil editable**: nombre y teléfono se cambian desde `/perfil`, con nonce. El correo no se edita ahí: es el identificador de la cuenta.
* Endurecido el registro: el aviso es el mismo exista o no la cuenta, y el correo del dueño recibe el aviso cuando alguien intenta registrarse con su dirección.

= 1.1.0 =
* Cuenta del cliente: /iniciar-sesion (login y registro en la misma página), /perfil de solo lectura, cierre de sesión con WordPress y navegación que cambia "Iniciar sesión" por "Perfil". El nombre, el correo y el teléfono quedan en la cuenta de WordPress (el teléfono en la meta `simple_form_phone`).
* El formulario de pedido se abre con los datos del cliente autenticado; el invitado lo sigue usando igual.
* Cada pedido guarda el `user_id` de la cuenta que lo hizo, y la pantalla de pedidos lo muestra.
* Pruebas de seguridad del sistema de cuentas (`tests/security/`, 152 pruebas) y endurecimiento: el cliente no entra al escritorio de WordPress y la respuesta de un correo que no existe ya no se distingue de la de uno que sí.

= 1.0.0 =
* Versión inicial: formulario de 4 pasos, cobertura de Chile, radio de búsqueda, pago con Mercado Pago Checkout Pro, webhook con firma e idempotencia, pantalla de pedidos, correos transaccionales y canal de log propio.
