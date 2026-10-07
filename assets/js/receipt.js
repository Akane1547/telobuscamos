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

			const dateEl = wrapper.querySelector( '#pn-receipt-date' );
			if ( dateEl ) {
				dateEl.textContent = new Date().toLocaleString( 'es-CL' );
			}
		},
	};

	window.SimpleFormReceipt = Receipt;
} )( window );
