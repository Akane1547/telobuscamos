/**
 * Catálogos del objeto que se busca (tipo, tamaño y material).
 *
 * Las opciones no viven en el HTML de la página: se piden al servidor por AJAX,
 * con el nonce del formulario, y se pintan en los selects que llevan
 * `data-catalog="types|sizes|materials"`. Si una lista viene vacía, ese campo se
 * esconde y el paso se completa sin él (el servidor hace lo mismo).
 *
 * Expone `SimpleFormCatalog`:
 *   - `load()`  → Promise<boolean>: una sola petición, cacheada en memoria.
 *   - `items(kind)` → lista cargada (o []).
 *   - `hasOptions(kind)` → la lista tiene opciones (o sea, es obligatorio elegir).
 *   - `error()` → mensaje si la carga falló.
 *
 * @package SimpleForm
 */

( function ( window, document ) {
	'use strict';

	const SF = window.SimpleForm;

	const state = {
		loaded: false,
		lists: {},
		pending: null,
		error: '',
	};

	/**
	 * Lista cargada de un tipo.
	 *
	 * @param {string} kind types|sizes|materials
	 * @return {Array}
	 */
	function items( kind ) {
		return Array.isArray( state.lists[ kind ] ) ? state.lists[ kind ] : [];
	}

	/**
	 * La lista tiene opciones cargadas.
	 *
	 * @param {string} kind
	 * @return {boolean}
	 */
	function hasOptions( kind ) {
		return items( kind ).length > 0;
	}

	/**
	 * Pinta lo que llegó en los selects del formulario.
	 */
	function fill() {
		const wrapper = document.querySelector( SF.selectors.wrapper );

		if ( ! wrapper ) {
			return;
		}

		Object.keys( state.lists ).forEach( ( kind ) => {
			const select = wrapper.querySelector( `select[data-catalog="${ kind }"]` );
			const row = wrapper.querySelector( `[data-catalog-row="${ kind }"]` );

			if ( ! select || ! row ) {
				return;
			}

			const lista = items( kind );

			// Lista vacía: el campo no existe para el cliente.
			if ( 0 === lista.length ) {
				row.hidden = true;
				select.value = '';
				return;
			}

			row.hidden = false;

			const elegido = select.value;

			lista.forEach( ( item ) => {
				const option = document.createElement( 'option' );
				option.value = String( item.id );
				option.textContent = String( item.label );

				select.appendChild( option );
			} );

			// Si ya había un valor (sesión retomada), se respeta.
			if ( elegido ) {
				select.value = elegido;
			}
		} );
	}

	/**
	 * Avisa en el paso 1 que no se pudieron cargar las opciones.
	 */
	function showError() {
		const wrapper = document.querySelector( SF.selectors.wrapper );
		const aviso = wrapper ? wrapper.querySelector( '[data-catalog-error]' ) : null;

		if ( ! aviso ) {
			return;
		}

		aviso.textContent = state.error;
		aviso.hidden = false;
	}

	/**
	 * Pide los catálogos una sola vez.
	 *
	 * @return {Promise<boolean>} true si quedaron cargados.
	 */
	function load() {
		if ( state.loaded ) {
			return Promise.resolve( true );
		}

		if ( state.pending ) {
			return state.pending;
		}

		if ( ! SF || ! SF.actions || ! SF.actions.getCatalogs ) {
			return Promise.resolve( false );
		}

		state.pending = SF.request( SF.actions.getCatalogs )
			.then( ( result ) => {
				state.lists = ( result && result.catalogs ) || {};
				state.loaded = true;
				fill();

				return true;
			} )
			.catch( () => {
				state.error = 'No pudimos cargar las opciones del objeto. Recarga la página para intentarlo de nuevo.';
				showError();

				return false;
			} )
			.finally( () => {
				state.pending = null;
			} );

		return state.pending;
	}

	window.SimpleFormCatalog = {
		load,
		items,
		hasOptions,
		isLoaded: () => state.loaded,
		error: () => state.error,
	};
} )( window, document );
