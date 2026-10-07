/**
 * stepper.js
 *
 * Orquestador. No valida ni pinta nada por sí mismo: coordina
 * SimpleForm (config/estado), SimpleFormValidator, SimpleFormUI y
 * SimpleFormReceipt, y decide cuándo llamar al servidor.
 *
 * Flujo:
 *   1. Al cargar: pregunta al servidor si hay una sesión previa
 *      (sf_get_progress) y restaura el paso + los datos ya guardados.
 *   2. Al pulsar "Siguiente"/"Pagar ahora": valida en cliente (UX),
 *      si pasa, llama al endpoint AJAX del paso correspondiente.
 *   3. El servidor es quien decide si el paso queda validado; si
 *      responde error, se muestran los errores y no se avanza.
 */

( function ( window, document ) {
	'use strict';

	const SF = window.SimpleForm;
	const UI = window.SimpleFormUI;
	const Validator = window.SimpleFormValidator;
	const Receipt = window.SimpleFormReceipt;

	const STEP_ACTIONS = {
		1: 'saveStep1',
		2: 'saveStep2',
		3: 'saveStep3',
	};

	/**
	 * Recolecta los campos de un panel de paso como objeto plano.
	 *
	 * @param {HTMLElement} form
	 * @param {number} step
	 * @return {Object}
	 */
	function collectStepData( form, step ) {
		const panel = form.querySelector( `[data-step-panel="${ step }"]` );
		const data = {};

		panel.querySelectorAll( '[name]' ).forEach( ( input ) => {
			if ( input.type === 'radio' ) {
				if ( input.checked ) {
					data[ input.name ] = input.value;
				}
				return;
			}
			data[ input.name ] = input.value;
		} );

		return data;
	}

	/**
	 * Alcanzado el paso de confirmación no queda nada que retomar: se
	 * suelta el puntero de sesión para que una recarga no reabra un
	 * pedido ya finalizado.
	 *
	 * @param {HTMLElement} wrapper
	 * @return {boolean} true si el flujo quedó completo.
	 */
	function dropStoredSessionIfCompleted( wrapper ) {
		const totalSteps = wrapper.querySelectorAll( SF.selectors.stepPanel ).length;

		if ( SF.state.currentStep < totalSteps ) {
			return false;
		}

		SF.clearStoredSessionId();
		return true;
	}

	/**
	 * Intenta avanzar desde el paso actual: valida en cliente y, si pasa,
	 * llama al AJAX del servidor.
	 *
	 * @param {HTMLElement} wrapper
	 */
	async function goNext( wrapper ) {
		const form = wrapper.querySelector( SF.selectors.form );
		const currentStep = SF.state.currentStep;

		const clientCheck = Validator.validateStep( currentStep, form );
		UI.renderErrors( form, clientCheck.errors );

		if ( ! clientCheck.valid ) {
			return;
		}

		const action = SF.actions[ STEP_ACTIONS[ currentStep ] ];
		if ( ! action ) {
			return; // no hay endpoint para este paso (ej. paso de confirmación).
		}

		UI.setLoading( wrapper, true );
		UI.setFeedback( form, '' );

		try {
			const payload = collectStepData( form, currentStep );
			const result = await SF.request( action, payload );

			if ( result.session_id ) {
				SF.setStoredSessionId( result.session_id );
			}

			SF.state.progress = result.progress;

			// El paso a mostrar es el siguiente al que se acaba de guardar, no el
			// current_step que devuelve el servidor: ese es el punto de reanudación
			// y no baja nunca, así que al retroceder y volver a avanzar saltaría al
			// paso más lejano ya alcanzado y se saltaría los intermedios.
			const totalSteps = wrapper.querySelectorAll( SF.selectors.stepPanel ).length;
			SF.state.currentStep = Math.min( totalSteps, currentStep + 1 );

			if ( currentStep === 2 ) {
				UI.updateSummary( wrapper, result.progress.step2 );
			}

			dropStoredSessionIfCompleted( wrapper );

			renderCurrentStep( wrapper );
		} catch ( error ) {
			UI.renderErrors( form, error.errors );
			if ( ! error.errors || Object.keys( error.errors ).length === 0 ) {
				UI.setFeedback( form, error.message, 'error' );
			}
		} finally {
			UI.setLoading( wrapper, false );
		}
	}

	/**
	 * Retrocede un paso. Solo mueve UI; no hay "invalidar" en servidor
	 * porque no se pierde nada al ir atrás.
	 *
	 * @param {HTMLElement} wrapper
	 */
	function goPrev( wrapper ) {
		SF.state.currentStep = Math.max( 1, SF.state.currentStep - 1 );
		renderCurrentStep( wrapper );
	}

	/**
	 * Pinta el paso actual del estado en memoria, incluyendo el caso
	 * especial del paso de confirmación (pinta el receipt).
	 *
	 * @param {HTMLElement} wrapper
	 */
	function renderCurrentStep( wrapper ) {
		const step = SF.state.currentStep;
		UI.showStep( wrapper, step );

		const totalSteps = wrapper.querySelectorAll( SF.selectors.stepPanel ).length;
		if ( step === totalSteps && SF.state.progress ) {
			Receipt.render( wrapper, SF.state.progress );
		}
	}

	/**
	 * Restaura una sesión previa (si existe) al cargar la página.
	 * Rellena los campos de los pasos ya completados y salta al paso
	 * correcto — la fuente de verdad de "qué paso toca" es el servidor.
	 *
	 * @param {HTMLElement} wrapper
	 */
	async function restoreSession( wrapper ) {
		const form = wrapper.querySelector( SF.selectors.form );
		const storedId = SF.getStoredSessionId();

		// Sin sesión previa: paso 1 limpio. La sesión se crea en save_step1.
		if ( ! storedId ) {
			renderCurrentStep( wrapper );
			return;
		}

		SF.state.sessionId = storedId;

		try {
			const result = await SF.request( SF.actions.getProgress );

			SF.state.progress = result.progress;
			SF.state.currentStep = result.progress.current_step;

			// La sesión guardada ya había terminado: no se reabre el recibo.
			if ( dropStoredSessionIfCompleted( wrapper ) ) {
				SF.state.progress = null;
				SF.state.currentStep = 1;
				renderCurrentStep( wrapper );
				return;
			}

			if ( result.progress.step1 ) {
				UI.fillStepData( form, result.progress.step1 );
			}
			if ( result.progress.step2 ) {
				UI.fillStepData( form, result.progress.step2 );
				UI.updateSummary( wrapper, result.progress.step2 );
			}

			renderCurrentStep( wrapper );
		} catch ( error ) {
			// Sesión expirada o inválida: se descarta y se empieza de cero.
			SF.clearStoredSessionId();
			renderCurrentStep( wrapper );
		}
	}

	function init() {
		const wrapper = document.querySelector( SF.selectors.wrapper );
		if ( ! wrapper ) {
			return;
		}

		const form = wrapper.querySelector( SF.selectors.form );

		wrapper.querySelector( SF.selectors.navNext ).addEventListener( 'click', () => goNext( wrapper ) );
		wrapper.querySelector( SF.selectors.navPrev ).addEventListener( 'click', () => goPrev( wrapper ) );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			goNext( wrapper ); // el paso de "pago" usa el mismo flujo de validar+guardar.
		} );

		restoreSession( wrapper );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
