/**
 * admin-services.js
 *
 * Añadir y eliminar filas de planes en la pantalla Servicios de pago.
 *
 * Solo manipula el DOM: no calcula precios ni valida nada, eso es del
 * servidor. Los índices de los nombres de campo nunca se reutilizan, así
 * que borrar una fila no obliga a renumerar las demás (el guardado de PHP
 * recorre el array sin importarle los huecos).
 */

( function ( window, document ) {
	'use strict';

	const TABLE = '#sf-services-table';
	const MODEL = '[data-service-row-model]';
	const ADD = '#sf-add-service';
	const REMOVE = '[data-remove-service]';

	function nextIndex( table ) {
		return parseInt( table.getAttribute( 'data-next-index' ), 10 ) || 0;
	}

	/**
	 * Clona la fila modelo y le pone el índice que le toca.
	 *
	 * @param {HTMLTableRowElement} model
	 * @param {string} marker Marcador que llevan los nombres en la fila modelo.
	 * @param {number} index
	 * @return {HTMLTableRowElement}
	 */
	function buildRow( model, marker, index ) {
		const row = model.cloneNode( true );

		row.removeAttribute( 'data-service-row-model' );
		row.removeAttribute( 'hidden' );
		row.setAttribute( 'data-service-row', '1' );

		row.querySelectorAll( '[name]' ).forEach( ( input ) => {
			input.name = input.name.replace( marker, String( index ) );
			input.value = '';
		} );

		return row;
	}

	function init() {
		const table = document.querySelector( TABLE );
		const model = document.querySelector( MODEL );
		const addButton = document.querySelector( ADD );

		if ( ! table || ! model || ! addButton ) {
			return;
		}

		const tbody = table.querySelector( 'tbody' );
		const marker = model.getAttribute( 'data-service-row-model' );

		if ( ! tbody || ! marker ) {
			return;
		}

		addButton.addEventListener( 'click', () => {
			const index = nextIndex( table );
			const row = buildRow( model, marker, index );

			tbody.appendChild( row );
			table.setAttribute( 'data-next-index', String( index + 1 ) );

			const first = row.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
		} );

		tbody.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( REMOVE );

			if ( ! button ) {
				return;
			}

			const row = button.closest( 'tr' );

			if ( row ) {
				row.remove();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
