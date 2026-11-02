/**
 * admin-payments.js
 *
 * Añadir y quitar medios de pago en la pantalla de administración.
 *
 * Hay dos clases de fila:
 *   - pasarela registrada (Mercado Pago): credenciales por modo. Se ofrece en el
 *     select mientras no esté cargada y se consume al agregarla.
 *   - medio propio (transferencia, efectivo): nombre + "cómo pagar". Se pueden
 *     agregar tantos como se quiera, así que esa opción no se consume.
 *
 * No se guarda nada hasta pulsar el botón de guardar, y al guardar la lista de
 * medios activos es exactamente lo que quedó en pantalla.
 */

( function ( window, document ) {
	'use strict';

	/** Valor del select que crea un medio propio. */
	const MANUAL = '__manual__';

	const select = document.getElementById( 'sf-add-payment' );
	const rows = document.getElementById( 'sf-payment-rows' );

	if ( ! select || ! rows ) {
		return;
	}

	const addBtn = document.querySelector( '[data-add-payment]' );
	const emptyList = document.querySelector( '[data-payments-empty]' );

	let contador = 0;

	/**
	 * @return {boolean}
	 */
	function hayDisponibles() {
		return select.options.length > 0;
	}

	function actualizarEstado() {
		if ( addBtn ) {
			addBtn.disabled = ! hayDisponibles();
		}

		if ( emptyList ) {
			emptyList.hidden = rows.querySelector( '[data-payment]' ) !== null;
		}
	}

	/**
	 * El medio propio se puede repetir; una pasarela no.
	 *
	 * @param {string} id
	 * @return {boolean}
	 */
	function yaEsta( id ) {
		return MANUAL !== id && rows.querySelector( '[data-payment="' + id + '"]' ) !== null;
	}

	/**
	 * Crea una fila de medio propio a partir de la plantilla.
	 */
	function añadirManual() {
		const tpl = document.getElementById( 'sf-payment-tpl-' + MANUAL );

		if ( ! tpl ) {
			return;
		}

		// Clave única para los nombres de los campos de esta fila. El servidor
		// deriva el identificador del nombre, así que la clave es solo del envío.
		const clave = 'nuevo-' + ( ++contador ) + '-' + String( Date.now() ).slice( -5 );
		const contenedor = document.createElement( 'div' );

		contenedor.innerHTML = tpl.innerHTML.replace( /__KEY__/g, clave );

		const fila = contenedor.firstElementChild;

		if ( ! fila ) {
			return;
		}

		rows.appendChild( fila );
		actualizarEstado();

		const nombre = fila.querySelector( 'input[type="text"]' );

		if ( nombre ) {
			nombre.focus();
		}
	}

	/**
	 * @param {string} id
	 */
	function añadirPasarela( id ) {
		const tpl = document.getElementById( 'sf-payment-tpl-' + id );

		if ( ! tpl ) {
			return;
		}

		rows.appendChild( tpl.content.cloneNode( true ) );

		// Una pasarela se agrega una sola vez: sale de la lista mientras esté.
		const opcion = select.querySelector( 'option[value="' + id + '"]' );

		if ( opcion ) {
			opcion.remove();
		}

		actualizarEstado();

		const nueva = rows.querySelector( '[data-payment="' + id + '"]' );
		const primero = nueva ? nueva.querySelector( 'input[type="password"]' ) : null;

		if ( primero ) {
			primero.focus();
		}
	}

	function añadir() {
		const id = select.value;

		if ( ! id || yaEsta( id ) ) {
			return;
		}

		if ( MANUAL === id ) {
			añadirManual();
			return;
		}

		añadirPasarela( id );
	}

	/**
	 * Devuelve una pasarela a la lista de disponibles.
	 *
	 * @param {string} id
	 * @param {string} label
	 */
	function devolverOpcion( id, label ) {
		if ( ! id || select.querySelector( 'option[value="' + id + '"]' ) ) {
			return;
		}

		const opcion = document.createElement( 'option' );

		opcion.value = id;
		opcion.textContent = label;
		select.appendChild( opcion );
	}

	function quitar( boton ) {
		const fila = boton.closest( '[data-payment]' );

		if ( ! fila ) {
			return;
		}

		const esManual = '1' === fila.dataset.manual;

		// Quitar un medio propio lo borra al guardar (a diferencia de las
		// credenciales de una pasarela, que quedan guardadas): se pregunta.
		if ( esManual && ! window.confirm( '¿Quitar este medio de pago? Se borra al guardar.' ) ) {
			return;
		}

		if ( ! esManual ) {
			const titulo = fila.querySelector( 'h2' );
			const label = titulo && titulo.firstChild ? titulo.firstChild.textContent.trim() : fila.dataset.payment;

			devolverOpcion( fila.dataset.payment, label );
		}

		fila.remove();
		actualizarEstado();
	}

	if ( addBtn ) {
		addBtn.addEventListener( 'click', añadir );
	}

	rows.addEventListener( 'click', ( event ) => {
		const boton = event.target.closest( '[data-remove-payment]' );

		if ( boton ) {
			event.preventDefault();
			quitar( boton );
		}
	} );

	actualizarEstado();
} )( window, document );
