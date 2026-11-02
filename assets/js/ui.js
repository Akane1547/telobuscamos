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

			// Defensivo como el resto del módulo: un tema que quite el área de
			// feedback no puede romper el handler.
			if ( ! el ) {
				return;
			}

			el.textContent = message;
			el.className = 'pn-form__feedback' + ( type ? ` pn-form__feedback--${ type }` : '' );
		},

		/**
		 * Estado de "cargando" en el botón activo (next o submit).
		 *
		 * Al terminar se limpian TODOS los botones de navegación, no solo el
		 * activo: mientras la petición está en vuelo el paso ya cambió
		 * (renderCurrentStep corre antes de este finally), así que el botón que
		 * se deshabilitó dejó de ser el activo y quedaría deshabilitado para
		 * siempre — al retroceder, "Siguiente" no respondería.
		 *
		 * @param {HTMLElement} wrapper
		 * @param {boolean} isLoading
		 */
		setLoading( wrapper, isLoading ) {
			if ( isLoading ) {
				const activeBtn = wrapper.querySelector( `${ selectors.navNext }:not([hidden])` )
					|| wrapper.querySelector( `${ selectors.navSubmit }:not([hidden])` );

				if ( activeBtn ) {
					activeBtn.disabled = true;
					activeBtn.classList.add( 'is-loading' );
				}

				return;
			}

			wrapper.querySelectorAll( `${ selectors.navPrev }, ${ selectors.navNext }, ${ selectors.navSubmit }` ).forEach( ( btn ) => {
				btn.disabled = false;
				btn.classList.remove( 'is-loading' );
			} );
		},

		/**
		 * Precarga los inputs de un paso con datos guardados (al restaurar sesión).
		 *
		 * @param {HTMLFormElement} form
		 * @param {Object} data Datos del paso (tal como los devuelve el servidor).
		 */
		fillStepData( form, data = {} ) {
			let radioAjustado = false;

			Object.keys( data ).forEach( ( key ) => {
				const input = form.querySelector( `[name="${ key }"]` );
				if ( input ) {
					input.value = data[ key ];
				}
			} );
			// Sincroniza slider, etiqueta y campo oculto del radio.
			//
			// Un borrador guardado con el rango viejo puede tener un radio que el
			// rango actual ya no permite (el admin bajó el máximo). El navegador
			// recorta el slider solo, pero el campo oculto y la etiqueta seguirían
			// diciendo lo de antes: se lee de vuelta lo que el slider aceptó y se
			// escribe eso en los tres lados, para que no se pueda enviar un radio
			// que el servidor va a rechazar.
			if ( data.radius_km ) {
				const slider = form.querySelector( '#pn-map-radius' );
				const hidden = form.querySelector( '#pn-radius' );
				const label = form.querySelector( '.map-plugin__radio-value' );
				let km = parseFloat( data.radius_km );

				if ( slider ) {
					// El slider va en metros; lo guardado viene en km.
					slider.value = Math.round( km * 1000 );

					const ajustado = parseFloat( slider.value );

					if ( ajustado > 0 ) {
						radioAjustado = Math.abs( ajustado - Math.round( km * 1000 ) ) > 0.5;
						km = ajustado / 1000;

						if ( hidden ) {
							hidden.value = String( km );
						}
					}
				}

				if ( label ) {
					label.textContent = window.SimpleForm.formatRadius( km );
				}

				// El resumen del costado también muestra el radio: si se ajustó,
				// tiene que decir lo mismo que el slider.
				const resumen = form.querySelector( '#pn-summary-radius' );

				if ( resumen ) {
					resumen.textContent = window.SimpleForm.formatRadius( km );
				}

				// Si el mapa ya está montado, esto dispara el recálculo del
				// precio con el radio ajustado. Si no, el precio lo pide el mapa
				// cuando se muestra el Paso 2.
				if ( slider ) {
					slider.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
			}

			return { radiusAdjusted: radioAjustado };
		},

		/**
		 * Actualiza el resumen lateral (aside) con datos del paso 2.
		 *
		 * @param {HTMLElement} wrapper
		 * @param {Object} step2 { service_label, radius, estimated_price }
		 */
		updateSummary( wrapper, step2 = {} ) {
			// Un paso 2 sin datos no puede romper el avance del formulario.
			step2 = step2 || {};

			const map = {
				'#pn-summary-service': step2.service_label,
				'#pn-summary-radius': step2.radius_km ? window.SimpleForm.formatRadius( step2.radius_km ) : null,
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
