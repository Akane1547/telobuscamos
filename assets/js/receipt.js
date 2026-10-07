/**
 * receipt.js
 *
 * Pinta el paso 4 (confirmación) a partir de los datos ya validados que
 * viven en el transient del servidor (progress.step1, .step2, .step3).
 * No inventa nada del lado del cliente: si el dato no vino del server,
 * se muestra "—".
 */

( function ( window ) {
	'use strict';

	const SF = window.SimpleForm;

	const Receipt = {

		/**
		 * @param {HTMLElement} wrapper
		 * @param {Object} progress Snapshot completo de sesión (step1/step2/step3).
		 */
		render( wrapper, progress ) {
			const step1 = progress.step1 || {};
			const step2 = progress.step2 || {};

			const step3 = progress.step3 || {};

			const price = step3.final_price != null ? step3.final_price : step2.estimated_price;

			const fields = {
				'#pn-receipt-name': step1.name,
				'#pn-receipt-email': step1.email,
				'#pn-receipt-phone': step1.phone,
				'#pn-receipt-description': step1.description || 'Sin descripción',
				'#pn-receipt-service': step2.service_label,
				'#pn-receipt-radius': step2.radius_km ? `${ step2.radius_km } km` : null,
				'#pn-receipt-price': price != null ? `$${ Math.round( price ).toLocaleString( 'es-CL' ) }` : null,
			};

			Object.keys( fields ).forEach( ( sel ) => {
				const el = wrapper.querySelector( sel );
				if ( el ) {
					el.textContent = fields[ sel ] || '—';
				}
			} );

			// La fecha la formatea el servidor; el navegador no la inventa.
			const dateEl = wrapper.querySelector( '#pn-receipt-date' );
			if ( dateEl ) {
				dateEl.textContent = progress.date || '—';
			}

			renderBadge( wrapper, progress );
		},
	};

	/**
	 * Pinta el estado del pedido tal como lo reporta el servidor.
	 *
	 * El texto llega ya traducido desde PHP; aquí solo se elige el modificador
	 * de color, y solo si el estado tiene la forma esperada: la clase nunca se
	 * construye con un valor sin filtrar.
	 *
	 * @param {HTMLElement} wrapper
	 * @param {Object} progress
	 */
	function renderBadge( wrapper, progress ) {
		const badge = wrapper.querySelector( SF.selectors.receiptBadge );

		if ( ! badge ) {
			return;
		}

		const status = progress.status || '';

		badge.textContent = progress.status_label || '—';
		badge.className = 'pn-receipt__badge' + ( /^[a-z_]{1,20}$/.test( status ) ? ' pn-receipt__badge--' + status : '' );
	}

	window.SimpleFormReceipt = Receipt;
} )( window );
