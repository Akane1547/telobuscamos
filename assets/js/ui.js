/**
 * ui.js
 *
 * Todo lo que toca el DOM directamente: mostrar/ocultar pasos, marcar
 * el stepper, pintar errores, estados de carga del botón. No sabe nada
 * de AJAX ni de reglas de validación; solo pinta lo que le piden.
 */

( function ( window ) {
	'use strict';

	const { selectors } = window.SimpleForm;

	const UI = {

		/**
		 * Muestra el panel del paso indicado y oculta el resto.
		 *
		 * @param {HTMLElement} wrapper
		 * @param {number} step
		 */
		showStep( wrapper, step ) {
			wrapper.querySelectorAll( selectors.stepPanel ).forEach( ( panel ) => {
				const panelStep = parseInt( panel.dataset.stepPanel, 10 );
				panel.hidden = panelStep !== step;
			} );

			wrapper.querySelectorAll( selectors.stepperItem ).forEach( ( item ) => {
				const itemStep = parseInt( item.dataset.step, 10 );
				item.classList.toggle( 'is-active', itemStep === step );
				item.classList.toggle( 'is-complete', itemStep < step );
			} );

			this.updateNav( wrapper, step );

			// Avisa a quien le interese (map.js, por ejemplo) que este
			// paso ya está visible en el DOM. Desacoplado a propósito:
			// ui.js no sabe que el mapa existe.
			document.dispatchEvent(
				new CustomEvent( window.SimpleForm.events.stepVisible, {
					detail: { step, wrapper },
				} )
			);
		},

		/**
		 * Ajusta qué botones de navegación se ven según el paso actual.
		 *
		 * @param {HTMLElement} wrapper
		 * @param {number} step
		 */
		updateNav( wrapper, step ) {
			const prevBtn = wrapper.querySelector( selectors.navPrev );
			const nextBtn = wrapper.querySelector( selectors.navNext );
			const submitBtn = wrapper.querySelector( selectors.navSubmit );

			const totalSteps = wrapper.querySelectorAll( selectors.stepPanel ).length;
			const isLastActionStep = step === totalSteps - 1; // paso previo a la confirmación (pago)
			const isConfirmationStep = step === totalSteps;

			if ( prevBtn ) {
				prevBtn.hidden = step === 1 || isConfirmationStep;
			}

			if ( nextBtn ) {
				nextBtn.hidden = isLastActionStep || isConfirmationStep;
			}

			if ( submitBtn ) {
				submitBtn.hidden = ! isLastActionStep;
			}
		},

		/**
		 * Pinta (o limpia) los mensajes de error de un paso.
		 *
		 * @param {HTMLElement} form
		 * @param {Object} errors Mapa campo -> mensaje.
		 */
		renderErrors( form, errors = {} ) {
			form.querySelectorAll( '.pn-form__error' ).forEach( ( el ) => {
				el.textContent = '';
			} );

			Object.keys( errors ).forEach( ( field ) => {
				const el = form.querySelector( selectors.errorFor( field ) );
				if ( el ) {
					el.textContent = errors[ field ];
				}
			} );
		},

		/**
		 * Mensaje general (no ligado a un campo específico) en el área de feedback.
		 *
		 * @param {HTMLElement} form
		 * @param {string} message
		 * @param {'error'|'success'|''} type
		 */
		setFeedback( form, message, type = '' ) {
			const el = form.querySelector( selectors.feedback );
			el.textContent = message;
			el.className = 'pn-form__feedback' + ( type ? ` pn-form__feedback--${ type }` : '' );
		},

		/**
		 * Estado de "cargando" en el botón activo (next o submit).
		 *
		 * @param {HTMLElement} wrapper
		 * @param {boolean} isLoading
		 */
		setLoading( wrapper, isLoading ) {
			const activeBtn = wrapper.querySelector( `${ selectors.navNext }:not([hidden])` )
				|| wrapper.querySelector( `${ selectors.navSubmit }:not([hidden])` );

			if ( ! activeBtn ) {
				return;
			}

			activeBtn.disabled = isLoading;
			activeBtn.classList.toggle( 'is-loading', isLoading );
		},

		/**
		 * Precarga los inputs de un paso con datos guardados (al restaurar sesión).
		 *
		 * @param {HTMLFormElement} form
		 * @param {Object} data Datos del paso (tal como los devuelve el servidor).
		 */
		fillStepData( form, data = {} ) {
			Object.keys( data ).forEach( ( key ) => {
				const input = form.querySelector( `[name="${ key }"]` );
				if ( input ) {
					input.value = data[ key ];
				}
			} );
			// Sincroniza slider y etiqueta del radio (el hidden solo guarda el valor).
			if ( data.radius_km ) {
				const slider = form.querySelector( '#pn-map-radius' );
				const label = form.querySelector( '.map-plugin__radio-value' );
				if ( slider ) {
					slider.value = data.radius_km;
				}
				if ( label ) {
					label.textContent = data.radius_km;
				}
			}
		},

		/**
		 * Actualiza el resumen lateral (aside) con datos del paso 2.
		 *
		 * @param {HTMLElement} wrapper
		 * @param {Object} step2 { service_label, radius, estimated_price }
		 */
		updateSummary( wrapper, step2 = {} ) {
			const map = {
				'#pn-summary-service': step2.service_label,
				'#pn-summary-radius': step2.radius_km ? `${ step2.radius_km } km` : null,
				'#pn-summary-price': step2.estimated_price != null ? `$${ Math.round( step2.estimated_price ).toLocaleString( 'es-CL' ) }` : null,
			};

			Object.keys( map ).forEach( ( sel ) => {
				const el = wrapper.querySelector( sel );
				if ( el && map[ sel ] != null ) {
					el.textContent = map[ sel ];
				}
			} );
		},
	};

	// El resumen lateral se actualiza en cuanto el servidor devuelve un precio
	// nuevo (evento emitido por map.js), sin esperar a que se guarde el paso.
	document.addEventListener( window.SimpleForm.events.priceUpdated, ( event ) => {
		UI.updateSummary( event.detail.wrapper, event.detail.step2 );
	} );

	window.SimpleFormUI = UI;
} )( window );
