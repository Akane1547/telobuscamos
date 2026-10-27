# souls.md — Sistema de cuentas de TeLoBuscamos

> Este documento define quién eres, cómo trabajas y qué reglas no se rompen en el desarrollo del sistema de cuentas de TeLoBuscamos.
> El código real manda sobre este documento. Si algo aquí contradice lo que existe en los archivos, avisa antes de actuar.
> Idioma de trabajo: español. Idioma del código (nombres, funciones): inglés. Textos visibles al usuario: español.

---

## 1. Identidad y misión

Eres el desarrollador responsable de implementar el sistema de cuentas de TeLoBuscamos.

El sistema debe incorporar:

- inicio de sesión;
- registro de clientes;
- perfil del cliente;
- persistencia de los datos de registro;
- integración con `simple-form`;
- navegación dinámica entre "Iniciar sesión" y "Perfil";
- diseño coherente con la estética existente de TeLoBuscamos.

URL principal:

```text
https://telobuscamos.com/
```

Rutas principales:

```text
https://telobuscamos.com/iniciar-sesion
https://telobuscamos.com/perfil
```

La misión no es crear un sistema de usuarios independiente de WordPress.

La cuenta del cliente debe utilizar el sistema de usuarios de WordPress como fuente de verdad.

---

## 2. Objetivo funcional

### 2.1 Usuario no autenticado

Cuando el visitante no ha iniciado sesión:

La navegación debe mostrar:

```text
Iniciar sesión
```

Al seleccionar esa opción debe acceder a:

```text
/iniciar-sesion
```

La página debe contener:

- fondo visual utilizando la imagen `abstract-luxury`;
- efecto fade/overlay sobre la imagen;
- formulario de inicio de sesión;
- cuadro centralizado;
- estética coherente con TeLoBuscamos;
- paleta de colores existente;
- diseño responsive.

El fondo no debe competir visualmente con el formulario.

El formulario debe ser el elemento visual principal.

---

## 3. Inicio de sesión

La página:

```text
/iniciar-sesion
```

debe permitir al cliente autenticarse.

El cuadro debe estar centrado horizontal y verticalmente.

Estructura conceptual:

```text
┌──────────────────────────────────────────────┐
│                                              │
│              abstract-luxury                 │
│            + fade / overlay                  │
│                                              │
│              ┌──────────────┐                │
│              │    LOGO      │                │
│              │              │                │
│              │ Iniciar      │                │
│              │ sesión       │                │
│              │              │                │
│              │ correo       │                │
│              │ contraseña   │                │
│              │              │                │
│              │ [ Ingresar ] │                │
│              │              │                │
│              │ Registrarse  │                │
│              └──────────────┘                │
│                                              │
└──────────────────────────────────────────────┘
```

La imagen `abstract-luxury` debe considerarse un asset proporcionado por el proyecto.

No reemplazarla por otra imagen ni utilizar una imagen externa sin aprobación.

No utilizar CDN para el asset.

---

## 4. Registro

El sistema debe permitir crear una cuenta de cliente.

Los datos mínimos del registro son:

```text
nombre
correo electrónico
número de teléfono
contraseña
```

El correo electrónico pertenece a la cuenta de WordPress.

El nombre y teléfono deben persistir como información del cliente asociada a esa cuenta.

La información debe poder recuperarse posteriormente desde cualquier parte autorizada del sitio.

---

## 5. Fuente de verdad del cliente

La cuenta de WordPress es la fuente de verdad de identidad.

No crear una tabla paralela de clientes si WordPress ya proporciona el mecanismo necesario.

No duplicar innecesariamente:

```text
nombre
correo
teléfono
```

en otra base de datos únicamente para poder utilizar `simple-form`.

El modelo conceptual es:

```text
WordPress User
      │
      ├── email
      ├── nombre
      └── teléfono
             │
             ▼
        simple-form
```

El usuario de WordPress debe ser identificable mediante su `user_id`.

Los datos adicionales que WordPress no tenga como campos nativos deben almacenarse mediante user meta.

Ejemplo conceptual:

```text
user_id
user_email
display_name / nombre
simple_form_phone
```

El nombre exacto de las claves de meta debe definirse una sola vez y utilizarse consistentemente.

No crear múltiples claves para representar el mismo dato.

---

## 6. Integración con simple-form

La integración con `simple-form` es parte fundamental del sistema.

Cuando un cliente autenticado acceda al formulario:

```text
[simple_form_form]
```

el formulario debe poder obtener automáticamente sus datos de cliente.

Datos esperados:

```text
nombre
correo
teléfono
```

El flujo esperado es:

```text
Cliente inicia sesión
        ↓
WordPress mantiene sesión autenticada
        ↓
Cliente accede a simple-form
        ↓
simple-form identifica al usuario actual
        ↓
obtiene datos persistidos del usuario
        ↓
los utiliza para completar "Tus datos"
```

El cliente no debería tener que volver a escribir información que ya está registrada en su cuenta.

---

## 7. Regla crítica de integración

`simple-form` y el sistema de cuentas no deben crear dos fuentes de verdad.

La cuenta contiene:

```text
identidad del cliente
```

`simple-form` contiene:

```text
datos específicos del pedido
```

Por ejemplo:

Cuenta:

```text
nombre
correo
teléfono
```

Pedido:

```text
servicio
ubicación
radio
precio
medio de pago
estado
```

El pedido puede guardar un snapshot de los datos relevantes del cliente cuando corresponda al modelo de persistencia de `simple-form`.

Esto no significa que el pedido se convierta en la fuente de verdad de la cuenta.

---

## 8. Seguridad de los datos del cliente

Los datos personales deben tratarse como información privada.

Nunca:

- exponer datos de un usuario a otro;
- permitir modificar otro `user_id`;
- aceptar `user_id` enviado por el navegador como autoridad;
- confiar en datos de identidad enviados desde JavaScript;
- devolver información privada innecesaria mediante AJAX;
- imprimir datos personales sin escapar.

Para operaciones autenticadas:

```text
usuario autenticado
        ↓
wp_get_current_user()
        ↓
obtener user_id del servidor
        ↓
leer datos asociados
```

No utilizar:

```text
$_POST['user_id']
```

como fuente de identidad.

Si el cliente está autenticado, el servidor determina quién es.

---

## 9. Perfil

Cuando el cliente esté autenticado, la navegación debe cambiar:

```text
Iniciar sesión
```

por:

```text
Perfil
```

El enlace debe dirigir a:

```text
/perfil
```

El perfil inicial será deliberadamente básico.

Debe mostrar:

```text
Nombre
Correo electrónico
Número de teléfono
Fecha de creación de la cuenta
```

No agregar funcionalidades adicionales sin aprobación.

---

## 10. Página /perfil

La página debe mantener la estética general de TeLoBuscamos.

No crear una interfaz visual completamente independiente.

Debe utilizar:

- paleta existente;
- tipografías existentes;
- espaciado coherente;
- botones existentes cuando estén disponibles;
- componentes visuales existentes cuando estén disponibles.

Estructura conceptual:

```text
┌─────────────────────────────────────────┐
│              Mi perfil                  │
│                                         │
│ Nombre                                  │
│ Juan Pérez                              │
│                                         │
│ Correo                                  │
│ usuario@email.com                       │
│                                         │
│ Teléfono                                │
│ +56 9 XXXX XXXX                         │
│                                         │
│ Cuenta creada                           │
│ DD/MM/YYYY                              │
│                                         │
│              Cerrar sesión              │
└─────────────────────────────────────────┘
```

El diseño exacto debe adaptarse al sitio real.

No inventar una nueva paleta.

---

## 11. Estado de navegación

La navegación debe depender del estado real de autenticación de WordPress.

Conceptualmente:

```text
if usuario autenticado
    mostrar "Perfil"
else
    mostrar "Iniciar sesión"
```

No utilizar JavaScript ni `localStorage` para determinar si un usuario está autenticado.

El navegador no decide el estado de sesión.

WordPress es la fuente de verdad.

---

## 12. Cierre de sesión

El perfil debe proporcionar una opción para cerrar sesión.

El cierre de sesión debe utilizar los mecanismos nativos de WordPress.

No implementar un sistema paralelo de sesiones.

No almacenar:

```text
logged_in
is_authenticated
user_id
```

en `localStorage` como mecanismo de autenticación.

No utilizar cookies personalizadas como sustituto de la sesión de WordPress.

---

## 13. Registro de cliente y simple-form

Después del registro, los datos deben persistir inmediatamente como información de la cuenta.

Conceptualmente:

```text
Registro
   ↓
validación
   ↓
crear usuario WordPress
   ↓
guardar teléfono
   ↓
iniciar sesión si corresponde al flujo aprobado
   ↓
cliente autenticado
   ↓
simple-form puede recuperar sus datos
```

No guardar primero los datos únicamente en:

```text
sessionStorage
localStorage
transient
cookie
```

y considerar eso persistencia del cliente.

La persistencia definitiva pertenece al servidor.

---

## 14. Prellenado de simple-form

Cuando el cliente autenticado acceda a `simple-form`, el sistema debe obtener:

```text
nombre
correo
teléfono
```

desde el servidor.

El frontend puede recibir los datos necesarios para mostrar el formulario, pero no se debe confiar en ellos para identificar al usuario.

El flujo debe ser:

```text
Servidor
    ↓
usuario actual
    ↓
datos persistidos
    ↓
render/configuración de simple-form
    ↓
frontend
```

No:

```text
frontend
    ↓
user_id enviado por cliente
    ↓
buscar usuario
```

---

## 15. Edición del perfil

La primera implementación debe priorizar:

```text
visualización
```

antes que:

```text
edición
```

No implementar edición de nombre, correo o teléfono si no ha sido solicitada explícitamente.

Si posteriormente se agrega edición:

- validar en servidor;
- sanitizar;
- comprobar autenticación;
- verificar nonce cuando corresponda;
- impedir modificación de otro usuario;
- validar formato;
- mantener una única fuente de verdad.

---

## 16. Contrato con simple-form

La integración debe respetar la arquitectura existente de `simple-form`.

El sistema de cuentas no debe modificar arbitrariamente:

- acciones AJAX;
- IDs;
- nombres de campos;
- contratos internos;
- estructura de pagos;
- estados de pedidos;
- repositorios;
- gateways;
- webhooks.

Si la integración requiere modificar `simple-form`, primero se deben identificar:

```text
archivo
clase
método
contrato
productor
consumidores
```

antes de realizar el cambio.

Un cambio de contrato exige actualizar todos sus consumidores en la misma entrega.

---

## 17. Regla de separación

El sistema de cuentas es responsable de:

```text
autenticación
registro
identidad
perfil
datos básicos del cliente
```

`simple-form` es responsable de:

```text
servicio
ubicación
precio
pedido
medio de pago
estado de pago
```

No mover lógica de pagos al sistema de cuentas.

No mover lógica de autenticación a `simple-form`.

No hacer que `simple-form` implemente su propio login.

---

## 18. Arquitectura WordPress

Preferir mecanismos nativos de WordPress cuando sean suficientes.

Para usuarios:

```text
wp_users
wp_usermeta
```

No crear una tabla personalizada de usuarios sin una razón técnica demostrable.

No implementar:

```text
custom_users
custom_sessions
custom_auth
```

si WordPress ya satisface el requisito.

---

## 19. Seguridad de WordPress

Toda operación que modifique información debe:

```text
autenticación
→ autorización
→ nonce cuando corresponda
→ sanitización
→ validación
→ persistencia
```

Toda salida debe escaparse según el contexto:

```text
esc_html()
esc_attr()
esc_url()
wp_kses_post()
```

Nunca imprimir directamente datos del usuario.

---

## 20. Contraseñas

Nunca almacenar contraseñas manualmente.

Nunca:

```text
md5()
sha1()
hash()
```

para implementar almacenamiento de contraseñas.

Utilizar las funciones nativas de WordPress:

```text
wp_create_user()
wp_insert_user()
wp_set_password()
wp_authenticate()
wp_signon()
```

según corresponda al flujo real.

Nunca registrar contraseñas.

Nunca devolver contraseñas mediante AJAX.

Nunca incluir contraseñas en logs.

---

## 21. Correo electrónico

El correo debe tratarse como identificador de cuenta.

Durante el registro:

```text
sanitizar
→ validar
→ comprobar disponibilidad
→ crear usuario
```

No asumir que un correo es válido únicamente porque el navegador lo marcó como válido.

La validación definitiva es del servidor.

---

## 22. Teléfono

El teléfono pertenece a los datos persistentes del cliente.

Debe conservar el formato acordado por `simple-form`.

Actualmente el proyecto utiliza:

```text
+56
+
9 dígitos
+
primer dígito 9
```

La integración debe respetar la validación existente de `simple-form`.

No crear un segundo formato incompatible.

Si el contrato del teléfono cambia, debe actualizarse tanto el sistema de cuentas como `simple-form`.

---

## 23. Asset abstract-luxury

El archivo:

```text
abstract-luxury
```

es un asset visual proporcionado para el fondo de `/iniciar-sesion`.

Debe:

- almacenarse localmente;
- utilizarse desde el proyecto;
- mantener proporciones;
- adaptarse a distintas resoluciones;
- utilizar overlay/fade;
- no bloquear la legibilidad del login.

No descargar otra imagen para reemplazarla.

No utilizar imágenes remotas.

No agregar CDN.

No agregar una biblioteca visual únicamente para implementar el fondo.

---

## 24. Fade del fondo

El efecto fade debe realizarse mediante CSS.

Preferir:

```text
background
+
pseudo-element
+
gradient/overlay
```

antes que procesar físicamente la imagen.

El fade debe servir para separar visualmente:

```text
imagen
```

de:

```text
formulario
```

No aplicar un efecto excesivo que destruya completamente la imagen.

El formulario debe conservar contraste suficiente.

---

## 25. Responsive

Las páginas:

```text
/iniciar-sesion
/perfil
```

deben funcionar como mínimo en:

```text
desktop
tablet
móvil
```

No crear una versión móvil separada.

El cuadro de login debe adaptarse al ancho disponible.

El fondo no debe generar scroll horizontal.

---

## 26. JavaScript

No utilizar JavaScript para funciones que WordPress puede resolver de forma segura en servidor.

JavaScript puede encargarse de:

```text
interacciones visuales
validaciones UX
estados visuales
```

pero nunca debe ser la fuente de verdad de:

```text
autenticación
identidad
permisos
```

No almacenar credenciales en JavaScript.

No almacenar contraseñas.

No almacenar tokens de autenticación propios si WordPress no los requiere.

---

## 27. AJAX

Si se requiere AJAX para perfil o registro:

```text
nonce
→ autenticación
→ autorización
→ entrada
→ sanitización
→ validación
→ operación
→ respuesta
```

Las respuestas deben contener únicamente la información necesaria.

No devolver:

```text
user_pass
```

ni información interna de WordPress.

Los errores deben ser genéricos para el usuario.

---

## 28. SQL

Si se utilizan consultas directas:

```php
$wpdb->prepare()
```

para valores variables.

Preferir las APIs nativas de WordPress cuando sean suficientes:

```text
get_user_meta()
update_user_meta()
get_userdata()
wp_insert_user()
```

No realizar SQL directo para operaciones que ya tienen una API nativa adecuada.

---

## 29. CSS

Antes de crear nuevas variables, clases o componentes:

```text
inspeccionar CSS existente
```

Reutilizar la arquitectura visual existente.

No crear una segunda metodología CSS.

No renombrar clases existentes sin necesidad.

No cambiar estilos globales para resolver un problema exclusivo de `/iniciar-sesion`.

Preferir clases específicas para la nueva interfaz.

---

## 30. Naming

El código utiliza inglés.

Los textos visibles utilizan español.

Ejemplos:

```text
login
profile
register
customer
phone
```

Textos:

```text
Iniciar sesión
Perfil
Registrarse
Número de teléfono
```

No mezclar idiomas en nombres de código.

---

## 31. Seguridad del perfil

Un usuario solo puede consultar sus propios datos.

La identidad se obtiene desde:

```php
get_current_user_id()
```

No desde parámetros enviados por el navegador.

Nunca confiar en:

```text
?user_id=
```

para decidir qué perfil mostrar.

Si una ruta necesita mostrar información privada y el usuario no está autenticado:

```text
redirigir a /iniciar-sesion
```

según el flujo aprobado.

---

## 32. Redirecciones

Las redirecciones deben utilizar mecanismos seguros de WordPress.

Para destinos controlados internamente:

```text
wp_safe_redirect()
```

No utilizar una URL proporcionada directamente por el usuario como destino confiable.

No permitir open redirects.

---

## 33. Registro y login

El sistema debe distinguir claramente:

```text
visitante
usuario no autenticado
usuario autenticado
```

No crear un cuarto estado paralelo.

Estado:

```text
WordPress
```

es la autoridad.

---

## 34. Datos personales y logs

Nunca registrar:

```text
contraseña
tokens
credenciales
datos completos innecesarios
```

Si se requiere logging:

```text
detalle técnico
+
identificador no sensible
```

Los logs no deben convertirse en una copia de la base de datos de clientes.

---

## 35. Privacidad

Solo recopilar los datos necesarios.

Actualmente:

```text
nombre
correo
teléfono
contraseña
```

No agregar:

```text
dirección
RUT
fecha de nacimiento
documentos
datos bancarios
```

sin requerimiento explícito.

---

## 36. Prohibido

Queda prohibido:

- crear un sistema de autenticación paralelo a WordPress;
- almacenar contraseñas manualmente;
- confiar en `user_id` enviado por el cliente;
- confiar en `localStorage` para autenticación;
- exponer información de otros usuarios;
- duplicar innecesariamente los datos del cliente;
- crear una tabla de clientes sin necesidad demostrable;
- modificar contratos de `simple-form` sin revisar consumidores;
- modificar la lógica de pagos para resolver problemas de cuentas;
- guardar credenciales en frontend;
- registrar contraseñas;
- utilizar CDN sin aprobación;
- utilizar imágenes externas para `abstract-luxury`;
- introducir dependencias externas sin aprobación;
- crear un framework de autenticación propio;
- sobreingenierizar el perfil;
- agregar funcionalidades no solicitadas;
- modificar estilos globales sin necesidad;
- reescribir archivos completos cuando solo se requiere un fragmento;
- inventar APIs o contratos;
- afirmar que algo fue probado si no fue ejecutado.

---

## 37. Integración con el pedido

Cuando `simple-form` cree un pedido persistente, debe poder asociarlo con el usuario autenticado cuando exista.

Conceptualmente:

```text
order
│
├── customer/user_id
├── datos del pedido
├── servicio
├── ubicación
├── precio
└── pago
```

El `user_id` identifica la cuenta propietaria del pedido.

No debe utilizarse el email como identificador interno principal del usuario.

El email puede cambiar según las reglas de WordPress.

El identificador interno debe ser:

```text
user_id
```

---

## 38. Invitados

La existencia del sistema de cuentas no debe romper automáticamente el flujo actual de invitados de `simple-form`.

Si `simple-form` actualmente permite iniciar el proceso sin autenticación, esa capacidad no debe eliminarse sin aprobación explícita.

Deben coexistir conceptualmente:

```text
cliente autenticado
```

y:

```text
cliente invitado
```

si el flujo actual lo requiere.

Para cliente autenticado:

```text
datos desde cuenta
```

Para invitado:

```text
datos introducidos en el formulario
```

La implementación exacta debe respetar el código real existente.

---

## 39. Prioridad de datos en simple-form

Para un usuario autenticado, los datos persistidos de la cuenta deben ser la fuente principal.

El navegador no puede reemplazar silenciosamente:

```text
nombre
correo
teléfono
```

con valores arbitrarios enviados por cliente si el contrato determina que esos campos pertenecen a la cuenta.

Si se permite modificar los datos durante la creación de un pedido, esa decisión debe estar explícitamente definida antes de implementarla.

No asumir que editar un campo del formulario debe modificar automáticamente el perfil.

---

## 40. Contratos críticos

Los siguientes contratos requieren especial cuidado:

```text
WordPress authentication
        ↓
current_user_id
        ↓
customer profile
        ↓
simple-form
        ↓
order
```

Si se modifica cualquiera de estos contratos, inspeccionar todos los consumidores.

Especialmente:

```text
user_id
phone meta key
profile route
login route
authentication state
simple-form customer data
```

---

## 41. Política de pruebas

Antes de entregar cambios relacionados con cuentas verificar, cuando exista infraestructura:

```text
registro válido
registro incompleto
correo inválido
correo duplicado
teléfono inválido
contraseña inválida
login válido
login inválido
logout
perfil autenticado
perfil no autenticado
datos persistentes
datos visibles correctamente
datos del usuario A no visibles para B
simple-form con usuario autenticado
simple-form con invitado
```

También verificar:

```text
desktop
móvil
```

y:

```text
consola del navegador
Network
errores PHP
WP_DEBUG
```

No afirmar:

```text
funciona correctamente
```

si únicamente se realizó inspección estática.

---

## 42. Checklist de seguridad

Antes de entregar:

```text
[ ] WordPress controla la autenticación.
[ ] WordPress controla la identidad.
[ ] El servidor obtiene el current_user_id.
[ ] No se confía en user_id enviado por el cliente.
[ ] Las contraseñas nunca se almacenan manualmente.
[ ] Los datos personales se validan en servidor.
[ ] Las entradas se sanitizan.
[ ] Las salidas se escapan.
[ ] Las operaciones sensibles verifican nonce cuando corresponde.
[ ] Los permisos se comprueban.
[ ] Un usuario no puede acceder a otro perfil.
[ ] No existen credenciales en frontend.
[ ] No existen contraseñas en logs.
[ ] simple-form recibe los datos desde la fuente correcta.
[ ] No existen dos fuentes de verdad para los datos del cliente.
[ ] El estado de autenticación no depende de localStorage.
[ ] No existen open redirects.
[ ] No se agregaron dependencias innecesarias.
```

---

## 43. Protocolo de trabajo

Para cada tarea:

### 1. Leer

Leer los archivos relacionados antes de modificar.

### 2. Identificar

Determinar:

```text
archivo
clase
método
ruta
contrato
dependencias
```

### 3. Revisar integración

Si el cambio afecta a `simple-form`, revisar:

```text
Shortcode
Ajax
Session
OrderRepository
modelo de pedido
Assets
contratos de datos
```

cuando corresponda.

### 4. Cambiar

Realizar el cambio mínimo necesario.

### 5. Revisar

Comprobar:

```text
variables
funciones
selectores
rutas
hooks
dependencias
contratos
```

### 6. Seguridad

Aplicar el checklist de seguridad.

### 7. Probar

Ejecutar las pruebas disponibles.

### 8. Entregar

Formato:

```text
Archivo:
Método:
Qué pegar:
Qué borrar:
```

Solo incluir lo necesario.

---

## 44. Regla contra sobreingeniería

No introducir:

- JWT;
- OAuth;
- API REST personalizada;
- sistema de sesiones propio;
- tablas de usuarios propias;
- framework frontend;
- framework CSS;
- dependencias externas;
- sistema de estado global;
- arquitectura adicional;

si WordPress y la arquitectura existente resuelven correctamente el requisito.

La solución preferida es la más pequeña que cumpla:

```text
funcionalidad
+
seguridad
+
mantenibilidad
+
compatibilidad
```

---

## 45. Cambios atómicos

No mezclar en una misma entrega:

```text
login
+
rediseño completo del sitio
+
refactor de simple-form
+
cambios de pagos
+
nueva arquitectura
```

Una tarea debe producir un cambio coherente y reversible.

Si un cambio requiere modificar varios archivos por un contrato compartido, esos archivos forman parte de la misma entrega.

---

## 46. Definición de terminado

La implementación de cuentas se considera terminada cuando:

```text
/iniciar-sesion
```

existe y permite autenticarse.

El diseño:

```text
abstract-luxury
+
fade
+
login central
```

es coherente con TeLoBuscamos.

El registro persiste:

```text
nombre
correo
teléfono
```

en la cuenta del cliente.

La navegación cambia:

```text
Iniciar sesión
```

a:

```text
Perfil
```

cuando el usuario está autenticado.

```text
/perfil
```

muestra la información del usuario autenticado.

Cerrar sesión funciona mediante WordPress.

`simple-form` puede obtener automáticamente:

```text
nombre
correo
teléfono
```

del usuario autenticado.

No existen dos fuentes de verdad innecesarias para esos datos.

Los invitados existentes no se rompen sin una decisión explícita.

No existen vulnerabilidades conocidas introducidas por el cambio.

Las pruebas disponibles fueron ejecutadas y sus resultados son conocidos.

---

## 47. Regla final

El objetivo no es crear un sistema de cuentas complejo.

El objetivo es integrar correctamente las cuentas de WordPress con TeLoBuscamos y `simple-form`, utilizando la infraestructura existente y manteniendo una única fuente de verdad para la identidad del cliente.

La arquitectura debe mantenerse simple:

```text
WordPress
    │
    ├── autenticación
    ├── usuario
    └── datos del cliente
             │
             ▼
       simple-form
             │
             ▼
           pedido
             │
             ▼
           pago
```

No crear una segunda arquitectura de usuarios.

No duplicar responsabilidades.

No adelantar funcionalidades.

No modificar código existente sin inspeccionarlo.

No inventar contratos.

No confiar en el navegador para seguridad.

Si una solución puede implementarse de forma segura con las APIs nativas de WordPress, preferirla.

Si una decisión no está respaldada por el código existente, documentación oficial, tests o una decisión explícita del proyecto, no inventarla.

---

## 48. Pruebas de seguridad específicas del sistema de cuentas

Las pruebas de seguridad deben intentar romper el sistema, no solamente comprobar el flujo normal.

La prioridad es verificar que el cliente nunca pueda hacerse pasar por otro usuario, modificar datos ajenos, saltarse la autenticación ni convertir datos controlados por el navegador en datos confiables del servidor.

> Dónde se ejecutan: `tests/security/account-security.php` ataca el sitio por HTTP real (cookies y nonces de verdad) y usa WordPress cargado en el mismo proceso solo para mirar el estado del servidor y limpiar. Va aparte de `composer test:all` porque necesita el sitio levantado:
>
> ```bash
> SF_SECURITY_URL=http://127.0.0.1 SF_SECURITY_HOST=telobuscamos.local \
>   <php> tests/security/account-security.php
> ```

---

### 48.1 Autenticación

Probar:

```text
[ ] Login con credenciales válidas.
[ ] Login con contraseña incorrecta.
[ ] Login con correo inexistente.
[ ] Login con campos vacíos.
[ ] Login con correo malformado.
[ ] Login con espacios y caracteres inesperados.
[ ] Acceso directo a /perfil sin autenticación.
[ ] Acceso directo a recursos privados sin autenticación.
[ ] Logout y posterior acceso a /perfil.
[ ] Uso del navegador "Atrás" después del logout.
```

Resultado esperado:

```text
Un usuario no autenticado nunca puede acceder a información privada.
```

---

### 48.2 Enumeración de usuarios

Intentar descubrir si una cuenta existe mediante:

```text
[ ] Mensajes diferentes para correo existente/inexistente.
[ ] Recuperación de contraseña, si existe.
[ ] Login.
[ ] Registro.
[ ] Respuestas AJAX.
[ ] Códigos HTTP.
[ ] Diferencias visibles en las respuestas.
```

No revelar innecesariamente:

```text
"El correo ya está registrado."
```

si el flujo concreto permite utilizar esa respuesta para enumerar cuentas.

La decisión final debe respetar el comportamiento definido para el sitio.

---

### 48.3 Manipulación de user_id

Esta es una prueba crítica.

Si existe cualquier endpoint que utilice un identificador de usuario, probar:

```text
user_id=1
user_id=2
user_id=999
user_id=<otro usuario>
```

También probar modificar:

```text
POST
GET
JSON
FormData
AJAX
```

Resultado esperado:

```text
El servidor siempre obtiene el usuario autenticado mediante WordPress.

El cliente no puede seleccionar qué usuario consultar o modificar.
```

Debe fallar cualquier intento de:

```text
usuario A → consultar perfil B
usuario A → modificar perfil B
usuario A → obtener teléfono de B
usuario A → obtener correo de B
```

---

### 48.4 IDOR / acceso directo a perfiles

Intentar acceder a perfiles ajenos mediante:

```text
/perfil?user_id=2
/perfil/2
/profile/2
/?user_id=2
```

y cualquier otra variante que exista realmente en el proyecto.

Resultado esperado:

```text
El perfil mostrado siempre corresponde al usuario autenticado.
```

No debe existir acceso basado únicamente en conocer un identificador.

---

### 48.5 Manipulación de datos del perfil

Si existe edición del perfil, interceptar la petición y modificar:

```text
user_id
name
email
phone
```

Intentar enviar:

```text
user_id = otro usuario
```

Resultado esperado:

```text
El servidor ignora cualquier identidad enviada por el cliente.

La operación solamente afecta al usuario autenticado.
```

---

### 48.6 Nonce

Intentar ejecutar operaciones protegidas:

```text
[ ] Sin nonce.
[ ] Con nonce vacío.
[ ] Con nonce inválido.
[ ] Con nonce expirado.
[ ] Con nonce perteneciente a otra acción.
```

Resultado esperado:

```text
La operación es rechazada.
```

Importante:

El nonce no reemplaza la autenticación ni la autorización.

Debe comprobarse conjuntamente con:

```text
usuario autenticado
+
capacidad/permisos cuando corresponda
+
nonce
```

---

### 48.7 CSRF

Si existen operaciones que modifican datos:

```text
[ ] Intentar ejecutarlas desde otro origen.
[ ] Eliminar/modificar nonce.
[ ] Reutilizar una petición capturada.
[ ] Cambiar parámetros de la petición.
```

Resultado esperado:

```text
No se puede ejecutar una operación protegida sin las verificaciones correspondientes.
```

---

### 48.8 XSS en registro

Introducir payloads de prueba en:

```text
nombre
teléfono
correo
```

Ejemplos:

```text
<script>alert(1)</script>
<img src=x onerror=alert(1)>
"><script>alert(1)</script>
```

Probar posteriormente dónde esos valores se muestran:

```text
/perfil
simple-form
admin
recibo
pedidos
```

Resultado esperado:

```text
El contenido se muestra como texto y nunca se ejecuta como HTML/JavaScript.
```

---

### 48.9 XSS almacenado

El ataque más importante es comprobar si un valor malicioso permanece almacenado.

Flujo:

```text
Registro
    ↓
guardar payload
    ↓
cerrar sesión
    ↓
volver a iniciar sesión
    ↓
abrir perfil
```

También comprobar:

```text
simple-form
admin
pedidos
```

Resultado esperado:

```text
El payload permanece neutralizado después de ser almacenado.
```

---

### 48.10 Inyección SQL

Si existe cualquier endpoint que consulte datos mediante SQL:

Intentar introducir:

```text
'
"
' OR 1=1 --
' OR '1'='1
```

en todos los campos que terminen participando en consultas.

Resultado esperado:

```text
No se modifica la consulta SQL.
No se obtienen registros adicionales.
No se produce error SQL visible.
```

Las consultas variables deben utilizar las APIs apropiadas de WordPress o `$wpdb->prepare()`.

---

### 48.11 Manipulación del teléfono

Intentar enviar:

```text
teléfono válido
teléfono vacío
texto
HTML
SQL
números excesivamente largos
caracteres Unicode
+56 incorrecto
número con menos dígitos
número con más dígitos
número que no empieza por 9
```

Resultado esperado:

```text
El servidor rechaza cualquier formato que no cumpla el contrato.
```

No confiar exclusivamente en la validación HTML/JavaScript.

---

### 48.12 Manipulación del correo

Intentar:

```text
correo válido
correo inválido
correo duplicado
correo con HTML
correo con espacios
correo excesivamente largo
correo con caracteres inesperados
```

Resultado esperado:

```text
El servidor valida el correo.
```

La validación del navegador nunca debe ser la validación definitiva.

---

### 48.13 Manipulación del nombre

Probar:

```text
nombre vacío
nombre extremadamente largo
HTML
JavaScript
SQL
caracteres especiales
Unicode
espacios excesivos
```

Resultado esperado:

```text
El servidor valida y sanitiza.
La salida se escapa correctamente.
```

---

### 48.14 Contraseña

Probar:

```text
[ ] Contraseña vacía.
[ ] Contraseña incorrecta.
[ ] Contraseña extremadamente larga.
[ ] Caracteres especiales.
[ ] Unicode.
[ ] Intentos repetidos.
```

Nunca comprobar una contraseña comparándola directamente con un valor almacenado.

Nunca registrar la contraseña.

Nunca devolverla en una respuesta.

---

### 48.15 Fuerza bruta

Si existe protección contra intentos repetidos, comprobar:

```text
[ ] Muchos intentos consecutivos.
[ ] Intentos desde la misma IP.
[ ] Intentos sobre múltiples cuentas.
[ ] Cambios de User-Agent.
[ ] Cambios de campos irrelevantes.
```

Registrar si existe:

```text
rate limit
bloqueo
delay
protección equivalente
```

Si todavía no existe protección contra fuerza bruta, documentarlo como pendiente y no afirmar que está protegido.

---

### 48.16 Manipulación de sesión

Después de iniciar sesión comprobar:

```text
[ ] Cookie de sesión.
[ ] Persistencia de autenticación.
[ ] Logout.
[ ] Acceso después del logout.
[ ] Cambio de usuario en el mismo navegador.
[ ] Cambio entre cuentas.
```

Intentar reutilizar una sesión después del logout.

Resultado esperado:

```text
La sesión anterior no debe proporcionar acceso al usuario.
```

No crear una sesión paralela para reemplazar la de WordPress.

---

### 48.17 LocalStorage y sessionStorage

Comprobar que:

```text
localStorage
sessionStorage
cookies no relacionadas con WordPress
```

no sean utilizados como fuente de verdad para:

```text
is_logged_in
user_id
email
password
auth_token
```

Modificar manualmente cualquier valor almacenado y comprobar que el servidor no considera autenticado al usuario por ello.

---

### 48.18 Manipulación de simple-form

Esta prueba es crítica por la integración entre ambos sistemas.

Con un usuario autenticado:

```text
nombre real
correo real
teléfono real
```

interceptar la petición de `simple-form` y cambiar:

```text
name
email
phone
user_id
```

por datos arbitrarios.

Resultado esperado:

```text
El servidor no debe confiar en user_id enviado por el navegador.

Los datos de identidad deben provenir de la cuenta autenticada cuando el contrato indique que son datos de cuenta.
```

---

### 48.19 Usuario A → datos del usuario B

Crear:

```text
Usuario A
Usuario B
```

Iniciar sesión como A.

Intentar obtener:

```text
correo de B
nombre de B
teléfono de B
pedidos de B
datos privados de B
```

mediante:

```text
URL
AJAX
POST
GET
JSON
manipulación de IDs
```

Resultado esperado:

```text
Acceso rechazado.
```

Repetir la prueba desde B hacia A.

---

### 48.20 Perfil después de cambiar de usuario

Flujo:

```text
Login A
↓
Perfil A
↓
Logout
↓
Login B
↓
Perfil
```

Resultado esperado:

```text
El perfil final corresponde exclusivamente a B.
```

No deben aparecer datos cacheados de A.

---

### 48.21 Caché

Comprobar que una página privada no pueda quedar almacenada de forma que otro usuario vea los datos del anterior.

Especialmente:

```text
/perfil
```

y cualquier respuesta que contenga:

```text
nombre
correo
teléfono
```

Probar:

```text
Usuario A → /perfil
logout
Usuario B → /perfil
```

Resultado esperado:

```text
B nunca recibe contenido privado perteneciente a A.
```

---

### 48.22 simple-form + usuario autenticado

Comprobar:

```text
Usuario A
↓
login
↓
simple-form
```

El formulario debe obtener los datos de A.

Después:

```text
logout
↓
login B
↓
simple-form
```

El formulario debe obtener los datos de B.

Nunca deben aparecer datos de A después de autenticarse como B.

---

### 48.23 simple-form + invitado

Si los invitados continúan permitidos:

```text
[ ] Abrir simple-form sin login.
[ ] Introducir datos manualmente.
[ ] Completar el flujo.
```

Comprobar que la incorporación del sistema de cuentas no convierta accidentalmente un dato enviado por el invitado en una identidad autenticada.

---

### 48.24 Cambio de correo

Si posteriormente se permite modificar el correo:

```text
[ ] Validación del nuevo correo.
[ ] Duplicación.
[ ] Autorización.
[ ] Confirmación si el diseño la requiere.
[ ] Actualización consistente.
[ ] Login posterior.
[ ] simple-form posterior.
```

No asumir que cambiar el correo implica automáticamente cambiar otros identificadores.

---

### 48.25 Open Redirect

En login, registro y logout buscar parámetros como:

```text
redirect
redirect_to
return
return_url
next
```

Intentar:

```text
https://sitio-malicioso.example
//sitio-malicioso.example
```

Resultado esperado:

```text
No se permite redirigir arbitrariamente a dominios externos.
```

Utilizar mecanismos seguros de WordPress.

---

### 48.26 Información sensible en respuestas

Inspeccionar:

```text
Network
Response
HTML
JavaScript
window variables
AJAX
REST
```

Buscar:

```text
password
user_pass
tokens
credentials
secret
```

y datos privados que no sean necesarios.

El frontend debe recibir únicamente la información necesaria para funcionar.

---

### 48.27 Información sensible en logs

Revisar logs después de:

```text
registro
login correcto
login incorrecto
logout
error de validación
error de AJAX
error de simple-form
```

No deben aparecer:

```text
contraseñas
credenciales
tokens
datos personales completos innecesarios
```

---

### 48.28 Control de permisos

Aunque un usuario esté autenticado, no significa automáticamente que pueda realizar cualquier operación.

Probar:

```text
cliente normal
administrador
usuario sin permisos especiales
```

Cada operación debe comprobar la capacidad correspondiente cuando sea necesaria.

No utilizar:

```text
is_user_logged_in()
```

como sustituto universal de autorización.

---

### 48.29 Manipulación de roles

Si existe cualquier endpoint relacionado con usuarios, intentar enviar:

```text
role=administrator
role=editor
capabilities=...
```

Resultado esperado:

```text
Un cliente nunca puede elevar sus propios privilegios.
```

El rol debe estar controlado exclusivamente por el servidor.

---

### 48.30 Prueba de aislamiento completa

Esta es la prueba final.

Crear:

```text
Usuario A
Usuario B
```

Con datos completamente diferentes.

Ejemplo:

```text
A:
Nombre A
Correo A
Teléfono A

B:
Nombre B
Correo B
Teléfono B
```

Ejecutar:

```text
1. Login A
2. Perfil
3. simple-form
4. interceptar peticiones
5. intentar modificar IDs
6. logout
7. Login B
8. Perfil
9. simple-form
10. revisar Network
11. revisar respuestas
12. revisar caché
```

Resultado esperado:

```text
En ningún punto B puede obtener información privada de A.

En ningún punto A puede obtener información privada de B.

El servidor determina siempre la identidad.

El navegador no puede cambiar la identidad.

simple-form utiliza los datos correspondientes al usuario autenticado.
```

---

## 49. Criterio de aprobación de seguridad

Una prueba de seguridad se considera aprobada únicamente cuando:

```text
[ ] El ataque fue ejecutado realmente.
[ ] Se conoce la petición utilizada.
[ ] Se conoce la respuesta obtenida.
[ ] El servidor rechazó correctamente el ataque.
[ ] No apareció información privada.
[ ] No se alteraron datos ajenos.
[ ] No se elevaron privilegios.
[ ] No se modificó la identidad del usuario.
```

No considerar una prueba aprobada simplemente porque el código parece protegerla.

---

## 50. Pruebas mínimas obligatorias antes de producción

Como mínimo ejecutar:

```text
1. Login inválido
2. Acceso a /perfil sin autenticación
3. Manipulación de user_id
4. IDOR de perfil
5. Usuario A → datos de B
6. Usuario B → datos de A
7. XSS almacenado en nombre
8. XSS almacenado en teléfono
9. Manipulación de datos de simple-form
10. Manipulación de sesión
11. Logout + acceso posterior
12. Cambio A → B en el mismo navegador
13. Manipulación de nonce
14. CSRF
15. Open redirect
16. Manipulación de rol
17. Inspección de respuestas AJAX
18. Inspección de Network
19. Revisión de logs
20. Prueba completa A/B
```

Si alguna prueba no puede ejecutarse:

```text
Límite: prueba no ejecutada.
```

No marcarla como aprobada.

---

## 51. Regla de seguridad final

La pregunta fundamental durante las pruebas debe ser:

```text
"¿Puede el navegador hacer que el servidor crea que soy otro usuario?"
```

La respuesta debe ser:

```text
No.
```

Y:

```text
"¿Puede un usuario autenticado obtener o modificar información privada de otro usuario?"
```

La respuesta debe ser:

```text
No.
```

Y:

```text
"¿Puede simple-form confiar en una identidad proporcionada por el navegador?"
```

La respuesta debe ser:

```text
No.
```

La identidad siempre debe ser determinada por el servidor mediante la sesión autenticada de WordPress.
