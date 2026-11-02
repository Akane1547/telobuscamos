/**
 * admin-catalogs.js
 *
 * Añadir y eliminar filas en la pantalla Catálogos del objeto (tipo, tamaño y
 * material). Igual que el de servicios: solo manipula el DOM, no valida ni
 * guarda nada (eso es del servidor), y los índices no se reutilizan para que
 * borrar una fila no obligue a renumerar.
 */

( function ( window, document ) {
	'use strict';

	const ROW = '[data-catalog-row]';
	const MODEL = '[data-catalog-row-model]';
	const ADD = '[data-add-catalog]';
	const REMOVE = '[data-remove-catalog]';

	function nextIndex( table ) {
		return parseInt( table.getAttribute( 'data-next-index' ), 10 ) || 0;
	}

	/**
	 * Clona la fila modelo de su lista y le pone el índice que le toca.
	 *
	 * @param {HTMLTableRowElement} model
	 * @param {string} marker
	 * @param {number} index
	 * @return {HTMLTableRowElement}
	 */
	function buildRow( model, marker, index ) {
		const row = model.cloneNode( true );

		row.removeAttribute( 'data-catalog-row-model' );
		row.removeAttribute( 'hidden' );
		row.setAttribute( 'data-catalog-row', '1' );

		row.querySelectorAll( '[name]' ).forEach( ( input ) => {
			input.name = input.name.replace( marker, String( index ) );
			input.value = '';
		} );

		return row;
	}

	function init() {
		document.querySelectorAll( ADD ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const kind = button.getAttribute( 'data-add-catalog' );
				const table = document.querySelector( `[data-catalog-list="${ kind }"]` );
				const model = document.querySelector( `${ MODEL }` );
				const models = document.querySelectorAll( MODEL );

				if ( ! table || ! models.length ) {
					return;
				}

				// Cada lista tiene su propia fila modelo: la del mismo tipo.
				let modelRow = null;

				models.forEach( ( candidate ) => {
					if ( candidate.querySelector( `[name^="catalogs[${ kind }]"]` ) ) {
						modelRow = candidate;
					}
				} );

				if ( ! modelRow ) {
					return;
				}

				const tbody = table.querySelector( 'tbody' );

				if ( ! tbody ) {
					return;
				}

				const index = nextIndex( table );
				const row = buildRow( modelRow, modelRow.getAttribute( 'data-catalog-row-model' ), index );

				tbody.appendChild( row );
				table.setAttribute( 'data-next-index', String( index + 1 ) );

				const first = row.querySelector( 'input' );

				if ( first ) {
					first.focus();
				}
			} );
		} );

		document.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( REMOVE );

			if ( ! button ) {
				return;
			}

			const row = button.closest( ROW );

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
