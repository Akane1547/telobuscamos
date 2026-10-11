/**
 * geocode.js
 *
 * Buscador de direcciones del Paso 2. Reglas duras de este módulo:
 *
 *   1. NUNCA decide una ubicación ni un monto. Al elegir una sugerencia solo
 *      avisa con el evento sf:place-selected; map.js mueve el pin y el servidor
 *      recalcula el precio.
 *   2. Consulta al proveedor DIRECTO desde el navegador, no por nuestro
 *      servidor. Medido: PHP abre una conexión TLS nueva en cada petición y no
 *      puede reusarla, así que el handshake costaba ~870 ms de los ~1,2 s de
 *      cada búsqueda. El navegador sí reusa la conexión y respeta el
 *      Cache-Control: max-age=3600 que manda el proveedor: la primera consulta
 *      baja a ~0,6 s y repetir la misma dirección es instantáneo. Si la red
 *      bloquea al proveedor, cae al endpoint propio (plan B).
 *   3. El filtro de Chile se aplica acá, que es presentación: el servidor vuelve
 *      a validar las coordenadas contra el polígono real al guardar el paso.
 *   4. Los datos del proveedor se pintan con createElement + textContent.
 *      Nunca innerHTML: la dirección la escribe el cliente.
 *   5. El buscador es una ayuda: si todo falla, el mapa sigue funcionando con
 *      click y arrastre del pin. Este módulo no bloquea el paso.
 */

( function ( window, document ) {
	'use strict';

	const SF = window.SimpleForm;

	const MIN_CHARS = 3;
	const DEBOUNCE_MS = 400;

	let els = {};
	let requestToken = 0; // descarta respuestas que llegan tarde
	let abortController = null; // corta la consulta anterior al proveedor
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

	/**
	 * Etiqueta de una sugerencia: "Avenida Providencia 1234, Providencia".
	 *
	 * Es la misma idea que Geocoder::label() del servidor (que la usa en el plan
	 * B): calle y altura, más la comuna para desambiguar. La región se omite a
	 * propósito: repetirla en cada sugerencia solo agrega ruido.
	 *
	 * @param {Object} props
	 * @return {string}
	 */
	function label( props ) {
		const parts = [];
		const street = props.street ? String( props.street ).trim() : '';
		const number = props.housenumber ? String( props.housenumber ).trim() : '';

		if ( street ) {
			// En Chile hay calles sin numeración cargada en OSM: se muestra la
			// calle sola antes que inventar una altura.
			parts.push( number ? street + ' ' + number : street );
		} else if ( props.name && String( props.name ).trim() ) {
			parts.push( String( props.name ).trim() );
		}

		if ( ! parts.length ) {
			return '';
		}

		const comuna = [ 'district', 'city', 'county' ]
			.map( ( key ) => ( props[ key ] ? String( props[ key ] ).trim() : '' ) )
			.filter( ( value ) => value )[ 0 ];

		if ( comuna && comuna !== parts[ 0 ] ) {
			parts.push( comuna );
		}

		return parts.join( ', ' );
	}

	/**
	 * Traduce una feature del proveedor a lo que pinta el buscador. Deja afuera
	 * todo lo que no sea de Chile: el proveedor devuelve el mundo ordenado por
	 * relevancia.
	 *
	 * @param {Object} feature
	 * @return {{label: string, lat: number, lng: number}|null}
	 */
	function toPlace( feature ) {
		const props = feature && feature.properties ? feature.properties : {};

		if ( 'CL' !== props.countrycode ) {
			return null;
		}

		const coords = feature.geometry && feature.geometry.coordinates ? feature.geometry.coordinates : [];

		// GeoJSON: [ longitud, latitud ].
		if ( ! isFinite( coords[ 0 ] ) || ! isFinite( coords[ 1 ] ) ) {
			return null;
		}

		const texto = label( props );

		if ( ! texto ) {
			return null;
		}

		return { label: texto, lat: coords[ 1 ], lng: coords[ 0 ] };
	}

	/**
	 * Consulta al proveedor. El navegador reusa la conexión y su caché; el
	 * AbortController corta la búsqueda anterior, así una respuesta vieja no
	 * compite con la nueva.
	 *
	 * @param {string} query
	 * @return {Promise<Array>}
	 */
	async function fromProvider( query ) {
		const geocoder = SF.geocoder || {};
		const url = geocoder.url
			+ '?q=' + encodeURIComponent( query )
			+ '&limit=' + ( geocoder.limit || 5 )
			+ '&bbox=' + ( geocoder.bbox || '' );

		if ( abortController ) {
			abortController.abort();
		}

		abortController = new window.AbortController();

		const response = await window.fetch( url, {
			signal: abortController.signal,
			credentials: 'omit', // el proveedor no tiene por qué ver las cookies del sitio
		} );

		if ( ! response.ok ) {
			throw new Error( 'HTTP ' + response.status );
		}

		const data = await response.json();
		const features = Array.isArray( data.features ) ? data.features : [];

		return features.map( toPlace ).filter( ( place ) => null !== place );
	}

	/**
	 * Pide las direcciones al proveedor y, si eso falla (una red que lo bloquea,
	 * un CORS inesperado, lo que sea), al endpoint propio.
	 *
	 * @param {string} query
	 * @return {Promise<Array>}
	 */
	async function fetchPlaces( query ) {
		if ( SF.geocoder && SF.geocoder.url ) {
			try {
				return await fromProvider( query );
			} catch ( error ) {
				// Sigue: el servidor es el plan B.
			}
		}

		const data = await SF.request( SF.actions.geocode, { query } );

		return Array.isArray( data.results ) ? data.results : [];
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
			const places = await fetchPlaces( query );

			if ( myToken !== requestToken ) {
				return; // hay una consulta más nueva en curso
			}

			results = places;
			render();
		} catch ( error ) {
			if ( myToken !== requestToken ) {
				return;
			}

			results = [];

			// El buscador es una ayuda: si nada responde, el cliente igual puede
			// elegir la ubicación en el mapa.
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
	 * Adelanta DNS + TCP + TLS del proveedor mientras el cliente mira el mapa.
	 * Sin esto la primera consulta paga el handshake completo (~2,9 s medidos en
	 * frío).
	 */
	function preconnect() {
		const origin = SF.geocoder ? SF.geocoder.origin : '';

		if ( ! origin || document.querySelector( 'link[rel="preconnect"][href="' + origin + '"]' ) ) {
			return;
		}

		const link = document.createElement( 'link' );

		link.rel = 'preconnect';
		link.href = origin;
		link.crossOrigin = 'anonymous';
		document.head.appendChild( link );
	}

	/**
	 * @param {CustomEvent} event
	 */
	function onStepVisible( event ) {
		if ( 2 !== event.detail.step ) {
			return;
		}

		preconnect();
		bind( event.detail.wrapper );
	}

	document.addEventListener( SF.events.stepVisible, onStepVisible );
} )( window, document );
