/**
 * validator.js
 *
 * Validación del lado del cliente, paso por paso. Esto es SOLO para UX
 * (feedback instantáneo); el servidor vuelve a validar todo en
 * class-ajax.php y es quien realmente decide si se puede avanzar.
 */

( function ( window ) {
	'use strict';

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
                errors.name = 'Ingresa tu nombre completo.';
            }

            if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
                errors.email = 'Correo electrónico inválido.';
            }

            if ( ! /^9\d{8}$/.test( phone.replace( /\s+/g, '' ) ) ) {
                errors.phone = 'Ingresa un teléfono chileno válido (9 dígitos, empieza con 9).';
            }

            return { valid: Object.keys( errors ).length === 0, errors };
        },

        /**
         * Valida el Paso 2 (servicio + mapa). El precio NO se valida aquí
         * como verdad; solo se revisa que existan los campos mínimos.
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
            const radius = radiusInput ? parseInt( radiusInput.value, 10 ) : 0;

            if ( ! serviceId ) {
                errors.service_id = 'Selecciona un servicio.';
            }

            if ( ! lat || ! lng ) {
                errors.map_location = 'Selecciona una ubicación en el mapa.';
            }

            if ( ! radius || radius < 1 || radius > 20 ) {
                errors.radius_km = 'El radio debe estar entre 1 y 20 km.';
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
				errors.payment_method = 'Selecciona un medio de pago.';
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
