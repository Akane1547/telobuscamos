/**
 * geocode.js
 *
 * Buscador de direcciones del Paso 2. El cliente escribe y este módulo pide las
 * sugerencias al servidor (SimpleForm.actions.geocode): el servidor es quien
 * filtra a Chile y quien cachea. Reglas duras de este módulo:
 *
 *   1. NUNCA decide una ubicación. Al elegir una sugerencia solo avisa con el
 *      evento sf:place-selected; map.js mueve el pin y pide el precio.
 *   2. Los textos visibles salen de SimpleForm.i18n, no de acá.
 *   3. Los datos del servidor se pintan con createElement + textContent.
 *      Nunca innerHTML: la dirección la escribe el cliente.
 *   4. El buscador es una ayuda: si falla, el mapa sigue funcionando con click y
 *      arrastre del pin. Este módulo no bloquea el paso.
 */

( function ( window, document ) {
	'use strict';

	const SF = window.SimpleForm;

	const MIN_CHARS = 3;
	const DEBOUNCE_MS = 400;

	let els = {};
	let requestToken = 0; // descarta respuestas que llegan tarde
	let results = [];
	let activeIndex = -1;
	let bound = false;

	/**
	 * Texto de i18n con respaldo, para que un config viejo no deje la caja muda.
	 *
	 * @param {string} key
	 * @param {string} fallback
	 * @return {string}
	 */
	function t( key, fallback ) {
		return SF.i18n && SF.i18n[ key ] ? SF.i18n[ key ] : fallback;
	}

	/**
	 * Debounce propio: el de map.js no se comparte entre módulos.
	 *
	 * @param {Function} fn
	 * @param {number} wait ms
	 * @return {Function}
	 */
	function debounce( fn, wait ) {
		let timer = null;

		return function ( ...args ) {
			clearTimeout( timer );
			timer = setTimeout( () => fn.apply( this, args ), wait );
		};
	}

	function cacheElements( wrapper ) {
		els = {
			input: wrapper.querySelector( SF.selectors.addressInput ),
			list: wrapper.querySelector( SF.selectors.addressList ),
			status: wrapper.querySelector( SF.selectors.addressStatus ),
			hidden: wrapper.querySelector( SF.selectors.addressValue ),
			error: wrapper.querySelector( SF.selectors.errorFor( 'address' ) ),
		};
	}

	/**
	 * @param {string} text
	 */
	function setStatus( text ) {
		if ( els.status ) {
			els.status.textContent = text;
		}
	}

	function close() {
		if ( ! els.list ) {
			return;
		}

		els.list.hidden = true;
		els.list.textContent = '';
		activeIndex = -1;

		if ( els.input ) {
			els.input.setAttribute( 'aria-expanded', 'false' );
			els.input.removeAttribute( 'aria-activedescendant' );
		}
	}

	function highlight( index ) {
		const options = els.list ? els.list.children : [];

		for ( let i = 0; i < options.length; i++ ) {
			const isActive = i === index;

			options[ i ].setAttribute( 'aria-selected', isActive ? 'true' : 'false' );

			if ( isActive ) {
				els.input.setAttribute( 'aria-activedescendant', options[ i ].id );
			}
		}

		activeIndex = index;
	}

	/**
	 * Pinta la lista de sugerencias con el texto que devolvió el servidor.
	 */
	function render() {
		if ( ! els.list ) {
			return;
		}

		els.list.textContent = '';
		activeIndex = -1;

		if ( 0 === results.length ) {
			close();
			setStatus( t( 'searchNoResults', 'Sin resultados. Probá con calle y comuna, o tocá el mapa.' ) );
			return;
		}

		results.forEach( ( item, index ) => {
			const option = document.createElement( 'li' );

			option.className = 'pn-address__option';
			option.id = 'pn-address-option-' + index;
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			option.textContent = item.label;

			// mousedown y no click: si el input pierde el foco antes, el click
			// no llega nunca a la sugerencia.
			option.addEventListener( 'mousedown', ( event ) => {
				event.preventDefault();
				choose( index );
			} );

			els.list.appendChild( option );
		} );

		els.list.hidden = false;
		els.input.setAttribute( 'aria-expanded', 'true' );
		setStatus( '' );
	}

	/**
	 * Avisa que el cliente eligió una dirección. Acá NO se mueve nada: map.js
	 * escucha el evento y se encarga del pin y del precio.
	 *
	 * @param {number} index
	 */
	function choose( index ) {
		const item = results[ index ];

		if ( ! item ) {
			return;
		}

		// Lo que viaja al servidor es la etiqueta que él mismo devolvió.
		els.input.value = item.label;
		els.hidden.value = item.label;

		if ( els.error ) {
			els.error.textContent = '';
		}

		close();

		document.dispatchEvent( new CustomEvent( SF.events.placeSelected, {
			detail: { lat: item.lat, lng: item.lng, label: item.label },
		} ) );
	}

	/**
	 * @param {string} query
	 */
	async function search( query ) {
		const myToken = ++requestToken;

		setStatus( t( 'searching', 'Buscando direcciones…' ) );

		try {
			const data = await SF.request( SF.actions.geocode, { query } );

			if ( myToken !== requestToken ) {
				return; // hay una consulta más nueva en curso
			}

			results = Array.isArray( data.results ) ? data.results : [];
			render();
		} catch ( error ) {
			if ( myToken !== requestToken ) {
				return;
			}

			results = [];

			// El buscador es una ayuda: si el servidor no responde, el cliente
			// igual puede elegir la ubicación en el mapa.
			setStatus( t( 'searchError', 'No pudimos buscar direcciones. Podés tocar el mapa.' ) );
			close();
		}
	}

	const searchDebounced = debounce( ( query ) => search( query ), DEBOUNCE_MS );

	function onInput() {
		const query = els.input.value.trim();

		// Si escribió algo distinto de lo que había elegido, la dirección
		// guardada ya no corresponde a lo que se ve.
		if ( '' !== els.hidden.value && els.hidden.value !== els.input.value ) {
			els.hidden.value = '';
		}

		results = [];

		if ( query.length < MIN_CHARS ) {
			close();
			setStatus( query.length > 0 ? t( 'searchMore', 'Escribí al menos 3 letras.' ) : '' );

			return;
		}

		searchDebounced( query );
	}

	/**
	 * @param {KeyboardEvent} event
	 */
	function onKeyDown( event ) {
		if ( els.list.hidden || 0 === results.length ) {
			return;
		}

		if ( 'ArrowDown' === event.key ) {
			event.preventDefault();
			highlight( ( activeIndex + 1 ) % results.length );
		} else if ( 'ArrowUp' === event.key ) {
			event.preventDefault();
			highlight( activeIndex <= 0 ? results.length - 1 : activeIndex - 1 );
		} else if ( 'Enter' === event.key && activeIndex >= 0 ) {
			event.preventDefault();
			choose( activeIndex );
		} else if ( 'Escape' === event.key ) {
			close();
		}
	}

	/**
	 * @param {HTMLElement} wrapper
	 */
	function bind( wrapper ) {
		cacheElements( wrapper );

		if ( bound || ! els.input ) {
			return;
		}

		bound = true;

		els.input.addEventListener( 'input', onInput );
		els.input.addEventListener( 'keydown', onKeyDown );
		els.input.addEventListener( 'blur', () => {
			// Con retraso corto: si el click fue en una sugerencia ya se eligió,
			// y si no, se cierra la lista.
			window.setTimeout( close, 150 );
		} );
	}

	/**
	 * @param {CustomEvent} event
	 */
	function onStepVisible( event ) {
		if ( 2 !== event.detail.step ) {
			return;
		}

		bind( event.detail.wrapper );
	}

	document.addEventListener( SF.events.stepVisible, onStepVisible );
} )( window, document );
