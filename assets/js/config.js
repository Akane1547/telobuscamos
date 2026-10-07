
( function ( window ) {
	'use strict';

	const STORAGE_KEY = 'sf_session_id';

	// URLs del CDN de Leaflet. Se cargan de forma perezosa (lazy) solo
	// cuando el usuario llega al paso del mapa — así el paso 1 (el que
	// ve el 100% de los visitantes) no paga el costo de esta librería.
	const LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
	const LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

	const SimpleForm = {

		// --- Config del backend (wp_localize_script) ---
		ajaxUrl: window.SimpleFormConfig ? window.SimpleFormConfig.ajaxUrl : '',
		nonce: window.SimpleFormConfig ? window.SimpleFormConfig.nonce : '',
		actions: window.SimpleFormConfig ? window.SimpleFormConfig.actions : {},

		// Centro de mapa por defecto (Santiago) si el navegador no da
		// geolocalización o aún no hay nada guardado en el paso 2.
		defaultCenter: window.SimpleFormConfig && window.SimpleFormConfig.defaultCenter
			? window.SimpleFormConfig.defaultCenter
			: { lat: -33.4489, lng: -70.6693, zoom: 12 },

		// --- Selectores centralizados ---
		selectors: {
			wrapper: '.pn-form-wrapper',
			form: '#pn-payment-form',
			stepperItem: '.pn-stepper__item',
			stepPanel: '.pn-step',
			errorFor: ( field ) => `[data-error-for="${ field }"]`,
			navPrev: '[data-action="prev"]',
			navNext: '[data-action="next"]',
			navSubmit: '[data-action="submit"]',
			feedback: '.pn-form__feedback',
			mapContainer: '#map-plugin',
		},

		// --- Nombres de eventos custom compartidos entre módulos ---
		events: {
			stepVisible: 'sf:step-visible', // detail: { step, wrapper }
		},

		// Promesa cacheada para no inyectar Leaflet dos veces si el
		// usuario va y vuelve entre pasos.
		_leafletPromise: null,

		/**
		 * Carga Leaflet (CSS + JS) bajo demanda. Seguro de llamar varias
		 * veces: la segunda llamada reutiliza la misma promesa.
		 *
		 * @return {Promise<void>}
		 */
		loadLeaflet() {
			if ( window.L ) {
				return Promise.resolve();
			}

			if ( this._leafletPromise ) {
				return this._leafletPromise;
			}

			this._leafletPromise = new Promise( ( resolve, reject ) => {
				if ( ! document.querySelector( `link[href="${ LEAFLET_CSS }"]` ) ) {
					const link = document.createElement( 'link' );
					link.rel = 'stylesheet';
					link.href = LEAFLET_CSS;
					document.head.appendChild( link );
				}

				const script = document.createElement( 'script' );
				script.src = LEAFLET_JS;
				script.async = true;
				script.onload = () => resolve();
				script.onerror = () => reject( new Error( 'No se pudo cargar Leaflet desde el CDN.' ) );
				document.head.appendChild( script );
			} );

			return this._leafletPromise;
		},

		// --- Estado en memoria (una fuente de verdad por carga de página) ---
		state: {
			sessionId: null,
			currentStep: 1,
			progress: null, // último snapshot que devolvió el servidor
		},

		/**
		 * Recupera el session_id persistido en localStorage, si existe.
		 * Esto es SOLO para decirle al servidor "prueba a retomar esta
		 * sesión"; el servidor decide si sigue siendo válida.
		 */
		getStoredSessionId() {
			try {
				return window.localStorage.getItem( STORAGE_KEY );
			} catch ( e ) {
				return null;
			}
		},

		setStoredSessionId( sessionId ) {
			this.state.sessionId = sessionId;
			try {
				window.localStorage.setItem( STORAGE_KEY, sessionId );
			} catch ( e ) {
				// localStorage no disponible (modo privado, etc.) — seguimos igual,
				// solo que no habrá restauración automática al recargar.
			}
			
		},
				clearStoredSessionId() {
			this.state.sessionId = null;
			try {
				window.localStorage.removeItem( STORAGE_KEY );
			} catch ( e ) {}
		},

		/**
		 * Helper único para llamadas AJAX a wp-admin/admin-ajax.php.
		 *
		 * @param {string} action Nombre de la acción registrada en class-ajax.php.
		 * @param {Object} data   Datos adicionales del payload.
		 * @return {Promise<Object>} Respuesta ya parseada (data.data del wp_send_json_*).
		 */
		async request( action, data = {} ) {
			const body = new URLSearchParams( {
				action,
				nonce: this.nonce,
				session_id: this.state.sessionId || '',
				...data,
			} );

			const response = await fetch( this.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body,
			} );

			const json = await response.json();

			if ( ! json.success ) {
				const error = new Error( json.data && json.data.message ? json.data.message : 'Error de validación' );
				error.errors = json.data && json.data.errors ? json.data.errors : {};
				error.status = response.status;
				throw error;
			}

			return json.data;
		},
	};

	window.SimpleForm = SimpleForm;
} )( window );
