/**
 * validator.js
 *
 * Validación del lado del cliente, paso por paso. Esto es SOLO para UX
 * (feedback instantáneo); el servidor vuelve a validar todo en Ajax.php y es
 * quien realmente decide si se puede avanzar.
 *
 * Los textos salen de SimpleForm.i18n (los manda el servidor): acá no se escribe
 * nada visible. El teléfono se normaliza con la misma regla que el servidor, así
 * el cliente y el servidor aceptan y rechazan lo mismo.
 */

( function ( window ) {
	'use strict';

	const SF = window.SimpleForm;

	/**
	 * Texto de i18n con respaldo, para que un config viejo no deje la validación
	 * sin mensajes.
	 *
	 * @param {string} key
	 * @param {string} fallback
	 * @return {string}
	 */
	function t( key, fallback ) {
		return SF.i18n && SF.i18n[ key ] ? SF.i18n[ key ] : fallback;
	}

	/**
	 * Deja solo los dígitos y descarta el código de país, igual que
	 * Ajax::normalize_phone() del servidor.
	 *
	 * @param {string} valor
	 * @return {string}
	 */
	function soloDigitos( valor ) {
		const digits = String( valor ).replace( /\D+/g, '' );

		return 11 === digits.length && '56' === digits.slice( 0, 2 ) ? digits.slice( 2 ) : digits;
	}

	const Validator = {

		/**
		 * Valida el Paso 1 (datos del cliente).
		 *
		 * @param {HTMLFormElement} form
		 * @return {Object} { valid: boolean, errors: Object }
		 */
		step1( form ) {
			const errors = {};

			const nameInput = form.querySelector( '[name="name"]' );
			const emailInput = form.querySelector( '[name="email"]' );
			const phoneInput = form.querySelector( '[name="phone"]' );

			const name = nameInput ? nameInput.value.trim() : '';
			const email = emailInput ? emailInput.value.trim() : '';
			const phone = phoneInput ? phoneInput.value.trim() : '';

			if ( name.length < 3 ) {
				errors.name = t( 'errName', 'Ingresa tu nombre completo.' );
			}

			if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
				errors.email = t( 'errEmail', 'Correo electrónico inválido.' );
			}

			if ( ! /^9\d{8}$/.test( soloDigitos( phone ) ) ) {
				errors.phone = t( 'errPhone', 'Ingresa un teléfono chileno válido (9 dígitos, empieza con 9).' );
			}

			return { valid: Object.keys( errors ).length === 0, errors };
		},

		/**
		 * Valida el Paso 2 (servicio + mapa). El precio NO se valida aquí como
		 * verdad; solo se revisa que existan los campos mínimos.
		 *
		 * @param {HTMLFormElement} form
		 * @return {Object} { valid: boolean, errors: Object }
		 */
		step2( form ) {
			const errors = {};

			const serviceInput = form.querySelector( '[name="service_id"]' );
			const latInput = form.querySelector( '[name="lat"]' );
			const lngInput = form.querySelector( '[name="lng"]' );
			const radiusInput = form.querySelector( '[name="radius_km"]' );

			const serviceId = serviceInput ? serviceInput.value : '';
			const lat = latInput ? latInput.value : '';
			const lng = lngInput ? lngInput.value : '';
			const radius = radiusInput ? parseFloat( radiusInput.value ) : 0;

			if ( ! serviceId ) {
				errors.service_id = t( 'errService', 'Selecciona un servicio.' );
			}

			if ( ! lat || ! lng ) {
				errors.map_location = t( 'errLocation', 'Selecciona una ubicación en el mapa.' );
			}

			// El radio va de 100 metros a 1 km: parseFloat, no parseInt, porque
			// 0.5 km redondeado a entero sería 0 y quedaría siempre inválido.
			if ( ! radius || radius < 0.1 || radius > 1 ) {
				errors.radius_km = t( 'errRadius', 'El radio debe estar entre 100 metros y 1 km.' );
			}

			return { valid: Object.keys( errors ).length === 0, errors };
		},

		/**
		 * Valida el Paso 3 (medio de pago).
		 *
		 * @param {HTMLFormElement} form
		 * @return {Object} { valid: boolean, errors: Object }
		 */
		step3( form ) {
			const errors = {};
			const selected = form.querySelector( '[name="payment_method"]:checked' );

			if ( ! selected ) {
				errors.payment_method = t( 'errPayment', 'Selecciona un medio de pago.' );
			}

			return { valid: Object.keys( errors ).length === 0, errors };
		},

		/**
		 * Despacha al validador correcto según el número de paso.
		 *
		 * @param {number} step
		 * @param {HTMLFormElement} form
		 * @return {Object}
		 */
		validateStep( step, form ) {
			const fn = this[ `step${ step }` ];

			if ( typeof fn !== 'function' ) {
				return { valid: true, errors: {} };
			}

			return fn.call( this, form );
		},
	};

	window.SimpleFormValidator = Validator;
} )( window );
