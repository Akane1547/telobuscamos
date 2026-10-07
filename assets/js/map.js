/**
 * map.js
 *
 * Integración con Leaflet para el Paso 2. Reglas duras de este módulo:
 *
 *   1. NUNCA calcula precio en el cliente. Cada cambio de ubicación o
 *      radio dispara (con debounce) una llamada AJAX a
 *      SimpleForm.actions.calculatePrice; el número que se muestra
 *      siempre viene del servidor.
 *   2. El "área de cobertura" que se dibuja también la decide el
 *      servidor (SimpleForm.actions.getCoverageArea) — el cliente solo
 *      la pinta con Leaflet, no decide su forma ni tamaño.
 *   3. El mapa se inicializa recién cuando el Paso 2 se hace visible
 *      (evento SimpleForm.events.stepVisible), nunca antes. Iniciar
 *      Leaflet dentro de un contenedor con "hidden" o display:none es
 *      la causa típica de los tiles grises: Leaflet mide 0x0px y no
 *      vuelve a recalcular solo.
 *   4. Los errores solo van a console.error — no se le muestran al
 *      usuario en este módulo (así se pidió: revisar en consola).
 */

( function ( window, document ) {
	'use strict';

	const SF = window.SimpleForm;

	let mapa = null;
	let marker = null;
	let radiusCircle = null;   // círculo que representa el radio elegido por el usuario
	let coverageArea = null; // contorno del área de cobertura que informa el servidor
	let mapInitialized = false;
	let priceRequestToken = 0; // evita que una respuesta vieja pise a una más nueva

	let els = {};

	let currentWrapper = null;

	/**
	 * Debounce genérico: evita disparar una llamada AJAX en cada pixel
	 * que se arrastra el slider o el marcador.
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
		currentWrapper = wrapper;

		els = {
			radiusInput: wrapper.querySelector( '#pn-map-radius' ),
			radiusValueEl: wrapper.querySelector( '.map-plugin__radio-value' ),
			priceValueEl: wrapper.querySelector( '#pn-price-value' ),
			serviceSelect: wrapper.querySelector( '#pn-service-type' ),
			latHidden: wrapper.querySelector( '#pn-lat' ),
			lngHidden: wrapper.querySelector( '#pn-lng' ),
			radiusHidden: wrapper.querySelector( '#pn-radius' ),
			priceHidden: wrapper.querySelector( '#pn-price' ),
			mapError: wrapper.querySelector( SF.selectors.errorFor( 'map_location' ) ),
		};
	}

	/**
	 * Guarda las coordenadas crudas del marcador en los hidden inputs.
	 * Esto NO es un "cálculo": es solo leer dónde quedó el pin. El
	 * precio sigue viniendo del servidor.
	 *
	 * @param {Object} latlng { lat, lng }
	 */
	function storePosition( latlng ) {
		els.latHidden.value = latlng.lat;
		els.lngHidden.value = latlng.lng;
	}

	/**
	 * Refresca el texto del radio y el círculo visual del radio elegido.
	 * Puramente presentacional — el precio real llega aparte.
	 */
	function updateRadiusDisplay() {
		const km = parseFloat( els.radiusInput.value ) || 1;
		els.radiusValueEl.textContent = km;
		els.radiusHidden.value = km;

		if ( radiusCircle ) {
			radiusCircle.setRadius( km * 1000 );
		}
	}

	/**
	 * Pide el precio al servidor con los datos actuales (servicio,
	 * posición, radio) y pinta la respuesta. Cualquier error se
	 * registra en consola y no se toca el precio mostrado.
	 */
	async function requestPrice() {
		const serviceId = els.serviceSelect ? els.serviceSelect.value : '';
		const lat = els.latHidden.value;
		const lng = els.lngHidden.value;
		const radius = els.radiusHidden.value;

		if ( ! serviceId || ! lat || ! lng ) {
			return; // aún no hay suficientes datos para pedir precio.
		}

		const myToken = ++priceRequestToken;

		try {
			const result = await SF.request( SF.actions.calculatePrice, {
				service_id: serviceId,
				lat,
				lng,
				radius_km: radius,
			} );
			
			if ( myToken !== priceRequestToken ) {
				return; // llegó una respuesta vieja después de una más nueva; se ignora.
			}

			els.priceValueEl.textContent = '$' + Math.round( result.price ).toLocaleString( 'es-CL' );
			els.priceHidden.value = result.price;

			if ( els.mapError ) {
				els.mapError.textContent = '';
			}

			// El resumen lateral se actualiza en cuanto el servidor confirma el
			// precio, sin esperar a que el usuario pulse Siguiente.
			document.dispatchEvent(
				new CustomEvent( SF.events.priceUpdated, {
					detail: {
						wrapper: currentWrapper,
						step2: {
							service_label: selectedServiceLabel(),
							radius_km: parseInt( els.radiusHidden.value, 10 ) || 0,
							estimated_price: result.price,
						},
					},
				} )
			);
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( '[simple-form] Error calculando precio:', error.message, error.errors || '' );
			if ( els.mapError ) {
				els.mapError.textContent = error.message;
			}
		}
	}

	/**
	 * Texto del plan elegido, que es lo que muestra el resumen.
	 *
	 * @return {string}
	 */
	function selectedServiceLabel() {
		if ( ! els.serviceSelect ) {
			return '';
		}

		const option = els.serviceSelect.options[ els.serviceSelect.selectedIndex ];

		return option ? option.textContent.trim() : '';
	}

	const requestPriceDebounced = debounce( requestPrice, 400 );

	/**
	 * Pinta el área de cobertura que decide el servidor: el contorno de Chile.
	 *
	 * Se pide una sola vez, al construir el mapa. El área ya no sigue al pin:
	 * es el país, no un radio alrededor del marcador.
	 */
	async function requestCoverageArea() {
		try {
			const result = await SF.request( SF.actions.getCoverageArea );

			if ( coverageArea ) {
				mapa.removeLayer( coverageArea );
			}

			if ( 'polygon' !== result.type || ! result.rings || ! result.rings.length ) {
				// eslint-disable-next-line no-console
				console.error( '[simple-form] El servidor no devolvió un área de cobertura válida.' );
				return;
			}

			coverageArea = window.L.polygon( result.rings, {
				color: '#1a1a1a',
				weight: 1,
				dashArray: '4 6',
				fillOpacity: 0.03,
				interactive: false,
			} ).addTo( mapa );

			// La vista inicial la fija el contorno real, no una constante del front.
			if ( result.bounds && result.bounds.south != null ) {
				mapa.fitBounds( [
					[ result.bounds.south, result.bounds.west ],
					[ result.bounds.north, result.bounds.east ],
				] );
			}
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( '[simple-form] Error obteniendo área de cobertura:', error.message );
		}
	}

	const requestCoverageAreaDebounced = debounce( requestCoverageArea, 200 );

	/**
	 * Construye el mapa por primera vez. Solo se llama cuando el
	 * contenedor ya está visible (ver initOnStepVisible).
	 *
	 * @param {HTMLElement} wrapper
	 */
	function buildMap( wrapper ) {
		const container = wrapper.querySelector( SF.selectors.mapContainer );
		if ( ! container ) {
			return;
		}

		const center = SF.defaultCenter;

		mapa = window.L.map( container ).setView( [ center.lat, center.lng ], center.zoom );

		window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap contributors',
			maxZoom: 19,
		} ).addTo( mapa );

		marker = window.L.marker( [ center.lat, center.lng ], { draggable: true } ).addTo( mapa );

		radiusCircle = window.L.circle( [ center.lat, center.lng ], {
			radius: ( parseFloat( els.radiusInput.value ) || 1 ) * 1000,
			color: '#f5b800',
			fillColor: '#f5b800',
			fillOpacity: 0.15,
			weight: 2,
		} ).addTo( mapa );

		storePosition( marker.getLatLng() );
		updateRadiusDisplay();
		requestCoverageAreaDebounced();

		marker.on( 'drag', ( e ) => {
			storePosition( e.target.getLatLng() );
			if ( radiusCircle ) {
				radiusCircle.setLatLng( e.target.getLatLng() );
			}
		} );

		marker.on( 'dragend', ( e ) => {
			const latlng = e.target.getLatLng();
			storePosition( latlng );
			mapa.panTo( latlng );
			requestPriceDebounced();
		} );

		mapa.on( 'click', ( e ) => {
			marker.setLatLng( e.latlng );
			storePosition( e.latlng );
			if ( radiusCircle ) {
				radiusCircle.setLatLng( e.latlng );
			}
			requestPriceDebounced();
		} );

		if ( els.radiusInput ) {
			els.radiusInput.addEventListener( 'input', () => {
				updateRadiusDisplay();
				requestPriceDebounced();
			} );
		}

		if ( els.serviceSelect ) {
			els.serviceSelect.addEventListener( 'change', requestPrice );
		}

		mapInitialized = true;
		requestPrice();
	}

	/**
	 * Handler del evento sf:step-visible. Solo actúa cuando el paso
	 * visible es el 2 (mapa). La primera vez construye el mapa; las
	 * siguientes solo re-mide el contenedor con invalidateSize(), que
	 * es lo que arregla los tiles grises al volver a mostrar un mapa
	 * que estuvo oculto.
	 *
	 * @param {CustomEvent} event
	 */
	function onStepVisible( event ) {
		const { step, wrapper } = event.detail;

		if ( 2 !== step ) {
			return;
		}

		cacheElements( wrapper );

		if ( ! mapInitialized ) {
			SF.loadLeaflet()
				.then( () => buildMap( wrapper ) )
				.catch( ( error ) => {
					// eslint-disable-next-line no-console
					console.error( '[simple-form] No se pudo cargar Leaflet:', error.message );
				} );
			return;
		}

		// El mapa ya existe: solo necesita re-medirse porque su
		// contenedor estuvo con hidden/display:none.
		requestAnimationFrame( () => {
			if ( mapa ) {
				mapa.invalidateSize();
			}
		} );
	}

	document.addEventListener( SF.events.stepVisible, onStepVisible );
} )( window, document );
