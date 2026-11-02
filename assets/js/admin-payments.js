/**
 * admin-payments.js
 *
 * Añadir y quitar medios de pago en la pantalla de administración.
 *
 * El servidor pinta una plantilla por cada medio registrado que todavía no está
 * cargado. "Añadir" clona esa plantilla; "Quitar" saca la fila (y devuelve el
 * medio a la lista de disponibles). No se guarda nada hasta pulsar el botón de
 * guardar, y al guardar la lista de medios activos es exactamente lo que quedó
 * en pantalla.
 *
 * No hay índices que renumerar: cada medio aparece una sola vez y su nombre de
 * campo es el id de la pasarela.
 */

( function ( window, document ) {
	'use strict';

	const select = document.getElementById( 'sf-add-payment' );
	const rows = document.getElementById( 'sf-payment-rows' );

	if ( ! select || ! rows ) {
		return;
	}

	const addBtn = document.querySelector( '[data-add-payment]' );
	const emptyHint = document.querySelector( '[data-add-empty]' );
	const emptyList = document.querySelector( '[data-payments-empty]' );

	/**
	 * ¿Quedan medios sin cargar?
	 *
	 * @return {boolean}
	 */
	function hayDisponibles() {
		return select.options.length > 0;
	}

	function actualizarEstado() {
		if ( addBtn ) {
			addBtn.disabled = ! hayDisponibles();
		}

		if ( emptyHint ) {
			emptyHint.hidden = hayDisponibles();
		}

		if ( emptyList ) {
			emptyList.hidden = rows.querySelector( '[data-payment]' ) !== null;
		}
	}

	/**
	 * @param {string} id
	 * @return {boolean}
	 */
	function yaEsta( id ) {
		return rows.querySelector( '[data-payment="' + id + '"]' ) !== null;
	}

	function añadir() {
		const id = select.value;

		if ( ! id || yaEsta( id ) ) {
			return;
		}

		const tpl = document.getElementById( 'sf-payment-tpl-' + id );

		if ( ! tpl ) {
			return;
		}

		const nodo = tpl.content.cloneNode( true );
		rows.appendChild( nodo );

		// El medio deja de estar disponible mientras esté cargado.
		const opcion = select.querySelector( 'option[value="' + id + '"]' );

		if ( opcion ) {
			opcion.remove();
		}

		actualizarEstado();

		const nueva = rows.querySelector( '[data-payment="' + id + '"]' );

		if ( nueva ) {
			const primero = nueva.querySelector( 'input[type="password"]' );

			if ( primero ) {
				primero.focus();
			}
		}
	}

	/**
	 * Devuelve un medio a la lista de disponibles.
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

		devolverOpcion( fila.dataset.payment, fila.querySelector( 'h2' ) ? fila.querySelector( 'h2' ).firstChild.textContent.trim() : fila.dataset.payment );
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
