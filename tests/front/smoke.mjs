#!/usr/bin/env node
/**
 * smoke.mjs — Prueba de humo del formulario contra el sitio levantado.
 *
 * Por qué existe (auditoría M-6): todos los bugs de interfaz de este proyecto se
 * encontraron a mano en el navegador, porque nada cubría el front. Esto es el
 * mínimo reproducible: carga la página, mira la consola, comprueba el buscador de
 * direcciones, el comportamiento en celular y el recibo.
 *
 * No tiene dependencias: habla el protocolo CDP directo con el WebSocket que ya
 * trae Node 22 o superior.
 *
 * Uso:
 *   node tests/front/smoke.mjs
 *   SF_BASE=http://mi-sitio.local/mapa/ SF_CHROME=/ruta/a/chrome node tests/front/smoke.mjs
 *
 * Qué NO cubre: el pago real (crear la preferencia y volver de Mercado Pago
 * necesita el túnel HTTPS) ni el webhook. Eso lo cubren las suites de integración
 * de PHP y una compra de prueba a mano.
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readdirSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.SF_BASE || 'http://telobuscamos.local/mapa/';
const PORT = Number(process.env.SF_CDP_PORT || 9333);

let failures = 0;

/**
 * Comparación por valor: con === un array nunca es igual a otro array, ni
 * siquiera vacío, y la prueba fallaría por una trampa propia.
 */
function igual( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Igual que check() en las suites de PHP: por valor y con el valor a la vista.
 */
function check( label, actual, expected ) {
	const ok = igual( actual, expected );

	if ( ! ok ) {
		failures++;
	}

	console.log(
		`${ ok ? 'PASS' : 'FAIL' } ${ label.padEnd(52) } esperado=${ JSON.stringify( expected ) } obtenido=${ JSON.stringify( actual ) }`
	);
}

/**
 * Busca un Chrome. SF_CHROME manda; si no, los nombres habituales y el caché de
 * Playwright, que es lo que hay en esta máquina.
 */
function findChrome() {
	if ( process.env.SF_CHROME ) {
		return process.env.SF_CHROME;
	}

	const candidatos = [
		'/usr/bin/google-chrome',
		'/usr/bin/google-chrome-stable',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
		'/snap/bin/chromium',
	];

	const cache = join( homedir(), '.cache', 'ms-playwright' );

	try {
		for ( const dir of readdirSync( cache ) ) {
			if ( ! dir.startsWith( 'chromium' ) ) {
				continue;
			}

			// Los nombres cambiaron entre versiones de Playwright: se prueban las
			// dos formas en vez de apostar a una.
			for ( const sub of [ 'chrome-linux64', 'chrome-linux' ] ) {
				candidatos.push( join( cache, dir, sub, 'chrome' ) );
			}

			for ( const sub of [ 'chrome-headless-shell-linux64', 'chrome-linux' ] ) {
				candidatos.push( join( cache, dir, sub, 'chrome-headless-shell' ) );
				candidatos.push( join( cache, dir, sub, 'headless_shell' ) );
			}
		}
	} catch ( e ) {
		// sin caché de Playwright: seguimos con los candidatos de siempre.
	}

	for ( const ruta of candidatos ) {
		if ( existsSync( ruta ) ) {
			return ruta;
		}
	}

	console.error( 'No encontré un Chrome. Pasá la ruta con SF_CHROME=/ruta/al/chrome' );
	process.exit( 2 );
}

/**
 * Cliente CDP mínimo: un id por petición y una promesa por respuesta.
 */
class CDP {
	constructor( ws ) {
		this.ws = ws;
		this.id = 0;
		this.pendientes = new Map();

		ws.addEventListener( 'message', ( evento ) => {
			const msg = JSON.parse( evento.data );

			if ( msg.id && this.pendientes.has( msg.id ) ) {
				const { resolver, rechazar } = this.pendientes.get( msg.id );
				this.pendientes.delete( msg.id );

				if ( msg.error ) {
					rechazar( new Error( JSON.stringify( msg.error ) ) );
				} else {
					resolver( msg.result );
				}
			}
		} );
	}

	enviar( method, params = {}, sessionId = undefined ) {
		const id = ++this.id;

		return new Promise( ( resolver, rechazar ) => {
			this.pendientes.set( id, { resolver, rechazar } );
			this.ws.send( JSON.stringify( { id, method, params, sessionId } ) );

			setTimeout( () => {
				if ( this.pendientes.has( id ) ) {
					this.pendientes.delete( id );
					rechazar( new Error( `${ method }: sin respuesta en 60 s` ) );
				}
			}, 60000 );
		} );
	}
}

async function esperar( ms ) {
	return new Promise( ( resolver ) => setTimeout( resolver, ms ) );
}

async function main() {
	const chrome = findChrome();
	const perfil = mkdtempSync( join( tmpdir(), 'sf-smoke-' ) );

	console.log( `Chrome: ${ chrome }` );
	console.log( `Sitio:  ${ BASE }\n` );

	// Chrome no puede heredar LD_LIBRARY_PATH: en esta máquina el PHP de Local lo
	// necesita apuntando a sus propias librerías, y Chrome muere si las hereda
	// (y con él la prueba entera, sin ninguna pista de por qué).
	const entorno = { ...process.env };
	delete entorno.LD_LIBRARY_PATH;

	const proceso = spawn( chrome, [
		'--headless=new',
		`--remote-debugging-port=${ PORT }`,
		`--user-data-dir=${ perfil }`,
		'--no-first-run',
		'--no-default-browser-check',
		'--disable-gpu',
		'--window-size=1280,900',
		'about:blank',
	], { stdio: 'ignore', env: entorno } );

	let ws;
	let cdp;

	try {
		// Esperar a que el navegador levante.
		let version = null;

		for ( let i = 0; i < 60 && ! version; i++ ) {
			try {
				version = await ( await fetch( `http://127.0.0.1:${ PORT }/json/version` ) ).json();
			} catch ( e ) {
				await esperar( 250 );
			}
		}

		if ( ! version ) {
			throw new Error( 'el navegador no abrió el puerto de depuración' );
		}

		ws = new WebSocket( version.webSocketDebuggerUrl );
		await new Promise( ( resolver, rechazar ) => {
			ws.addEventListener( 'open', resolver, { once: true } );
			ws.addEventListener( 'error', rechazar, { once: true } );
		} );

		cdp = new CDP( ws );

		const { targetId } = await cdp.enviar( 'Target.createTarget', { url: 'about:blank' } );
		const { sessionId } = await cdp.enviar( 'Target.attachToTarget', { targetId, flatten: true } );

		const evaluar = async ( expresion ) => {
			const r = await cdp.enviar(
				'Runtime.evaluate',
				{ expression: expresion, returnByValue: true, awaitPromise: true },
				sessionId
			);

			if ( r.exceptionDetails ) {
				throw new Error( 'en el navegador: ' + ( r.exceptionDetails.exception?.description || 'error' ) );
			}

			return r.result.value;
		};

		// El capturador se reinstala en cada documento: una recarga se lleva
		// window.__errores, y entonces la última comprobación leería undefined.
		const instalarCapturaErrores = async () => {
			await evaluar( `(() => {
				window.__errores = [];
				const original = console.error;
				console.error = function () {
					window.__errores.push( [ ...arguments ].map( String ).join( ' ' ) );
					return original.apply( this, arguments );
				};
				window.addEventListener( 'error', ( e ) => window.__errores.push( 'window.error: ' + e.message ) );
				window.addEventListener( 'unhandledrejection', ( e ) => window.__errores.push( 'promesa: ' + e.reason ) );
				return true;
			})()` );
		};

		const ir = async ( url ) => {
			await cdp.enviar( 'Page.navigate', { url }, sessionId );

			for ( let i = 0; i < 100; i++ ) {
				await esperar( 200 );

				try {
					if ( 'complete' === await evaluar( 'document.readyState' ) ) {
						break;
					}
				} catch ( e ) {
					// La navegación todavía reemplaza el contexto: reintentar.
				}
			}

			await instalarCapturaErrores();
		};

		// ------------------------------------------------------------------
		console.log( '=== La página carga, sin errores de consola ===' );

		await ir( BASE );

		check( 'el formulario está en la página', await evaluar( "!!document.querySelector('.pn-form-wrapper')" ), true );
		check( 'el paso 1 arranca visible', await evaluar( "document.querySelector('.pn-step:not([hidden])').dataset.stepPanel" ), '1' );
		check( 'el buscador de direcciones está', await evaluar( "!!document.querySelector('#pn-address')" ), true );

		// ------------------------------------------------------------------
		console.log( '\n=== Validación del paso 1 ===' );

		await evaluar( `(() => {
			const w = document.querySelector('.pn-form-wrapper');
			window.SimpleForm.state.currentStep = 1;
			window.SimpleFormUI.showStep( w, 1 );
			return true;
		})()` );

		const vacio = await evaluar( `(() => {
			const form = document.querySelector('#pn-payment-form');
			return window.SimpleFormValidator.step1( form );
		})()` );
		check( 'campos vacíos: inválido', vacio.valid, false );
		check( 'marca el nombre', typeof vacio.errors.name, 'string' );
		check( 'marca el correo', typeof vacio.errors.email, 'string' );
		check( 'marca el teléfono', typeof vacio.errors.phone, 'string' );

		const telefonos = await evaluar( `(() => {
			const form = document.querySelector('#pn-payment-form');
			const puesto = ( v ) => { form.querySelector('[name=phone]').value = v; return window.SimpleFormValidator.step1( form ).errors.phone; };
			return {
				conMas56:  puesto( '+56 9 1234 5678' ),
				conGuiones: puesto( '9-1234-5678' ),
				ochoDigitos: puesto( '91234567' ),
				empiezaPor8: puesto( '812345678' ),
				espacios: puesto( ' 9 1234 5678 ' ),
			};
		})()` );

		check( '+56 9 1234 5678 se acepta', telefonos.conMas56, undefined );
		check( '9-1234-5678 se acepta', telefonos.conGuiones, undefined );
		check( '9 1234 5678 con espacios se acepta', telefonos.espacios, undefined );
		check( 'ocho dígitos se rechaza', typeof telefonos.ochoDigitos, 'string' );
		check( 'empezando con 8 se rechaza', typeof telefonos.empiezaPor8, 'string' );

		// ------------------------------------------------------------------
		console.log( '\n=== Celular (375px) ===' );

		await cdp.enviar( 'Emulation.setDeviceMetricsOverride', {
			width: 375, height: 812, deviceScaleFactor: 2, mobile: true,
		}, sessionId );

		// Recargar después de cambiar el viewport: si no, las media queries
		// miden el diseño de escritorio y el informe miente.
		await ir( BASE );

		await evaluar( `(() => {
			const w = document.querySelector('.pn-form-wrapper');
			window.SimpleForm.state.currentStep = 2;
			window.SimpleFormUI.showStep( w, 2 );
			return true;
		})()` );
		await esperar( 1500 );

		check( 'la media query de 600px aplica', await evaluar( "window.matchMedia('(max-width: 600px)').matches" ), true );
		check( 'sin scroll horizontal', await evaluar( 'document.documentElement.scrollWidth - window.innerWidth' ), 0 );

		const fuentes = await evaluar( `(() => {
			const chicos = [];
			document.querySelectorAll('.pn-step:not([hidden]) .pn-form__input, .pn-step:not([hidden]) .pn-form__select, .pn-step:not([hidden]) .pn-form__textarea').forEach( ( el ) => {
				const fs = parseFloat( getComputedStyle( el ).fontSize );
				if ( fs < 16 ) chicos.push( [ el.id || el.name, fs ] );
			} );
			return chicos;
		})()` );
		check( 'ningún campo por debajo de 16px (iOS hace zoom)', fuentes, [] );

		const alturas = await evaluar( `(() => {
			const bajos = [];
			document.querySelectorAll('.pn-step:not([hidden]) .pn-btn, .pn-step:not([hidden]) .pn-form__input-group, .pn-step:not([hidden]) .pn-form__radio').forEach( ( el ) => {
				const h = Math.round( el.getBoundingClientRect().height );
				if ( h && h < 44 ) bajos.push( [ el.id || el.className.split(' ')[0], h ] );
			} );
			return bajos;
		})()` );
		check( 'ningún control táctil por debajo de 44px', alturas, [] );

		const slider = await evaluar( "Math.round(document.querySelector('#pn-map-radius').getBoundingClientRect().height)" );
		check( 'el slider del radio es táctil (>=44px)', slider >= 44, true );

		// ------------------------------------------------------------------
		console.log( '\n=== Buscador de direcciones ===' );

		await evaluar( `(() => {
			const s = document.querySelector('#pn-service-type');
			if ( s && s.options.length > 1 ) { s.selectedIndex = 1; s.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
			return true;
		})()` );

		await evaluar( `(() => {
			const i = document.querySelector('#pn-address');
			i.value = 'Providencia 1234';
			i.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			return true;
		})()` );

		let sugerencias = [];

		for ( let i = 0; i < 20 && 0 === sugerencias.length; i++ ) {
			await esperar( 1000 );
			sugerencias = await evaluar( "[...document.querySelectorAll('#pn-address-list .pn-address__option')].map( o => o.textContent )" );
		}

		check( 'llegan sugerencias', sugerencias.length > 0, true );

		if ( sugerencias.length ) {
			await evaluar( `(() => {
				const o = document.querySelector('#pn-address-list .pn-address__option');
				o.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
				return true;
			})()` );
			await esperar( 3000 );

			check( 'la dirección queda en el campo', await evaluar( "document.querySelector('#pn-address-value').value !== ''" ), true );
			check( 'la lista se cierra', await evaluar( "document.querySelector('#pn-address-list').hidden" ), true );
			check( 'el pin se movió (lat)', await evaluar( "parseFloat(document.querySelector('#pn-lat').value) < -17" ), true );
			check( 'el pin se movió (lng)', await evaluar( "parseFloat(document.querySelector('#pn-lng').value) < -60" ), true );
		}

		// ------------------------------------------------------------------
		console.log( '\n=== El precio lo da el servidor ===' );

		const precio = await evaluar( `(async () => {
			const s = window.SimpleForm;
			const data = await s.request( s.actions.calculatePrice, {
				service_id: document.querySelector('#pn-service-type').value,
				lat: document.querySelector('#pn-lat').value,
				lng: document.querySelector('#pn-lng').value,
				radius_km: document.querySelector('#pn-radius').value,
			} );
			return typeof data.price === 'number' && data.price > 0;
		})()` );

		check( 'calculate_price devuelve un entero positivo', precio, true );

		// ------------------------------------------------------------------
		console.log( '\n=== El recibo: los dos estados posibles ===' );

		const pintarRecibo = ( status, etiqueta ) => `(() => {
			const w = document.querySelector('.pn-form-wrapper');
			window.SimpleFormReceipt.render( w, {
				current_step: 4,
				status: '${ status }',
				status_label: '${ etiqueta }',
				date: '01/01/2026 12:00',
				step1: { name: 'Prueba de humo', email: 'prueba@ejemplo.cl', phone: '912345678', description: '' },
				step2: { service_id: 'plan', service_label: 'Plan de prueba', lat: '-33.4489', lng: '-70.6693', radius_km: 5, estimated_price: 50000 },
				step3: { payment_method: 'mercado_pago', final_price: 50000 },
			} );

			const campos = [ 'name', 'email', 'phone', 'description', 'service', 'radius', 'price' ].map( ( k ) => {
				const el = document.querySelector( '#pn-receipt-' + k );
				return el ? el.textContent.trim() : null;
			} );
			const badge = document.querySelector('.pn-receipt__badge');

			return {
				badge: badge ? badge.textContent : '',
				clase: badge ? badge.className : '',
				conDato: campos.filter( ( t ) => t && '—' !== t ).length,
				total: campos.length,
				fecha: ( document.querySelector('#pn-receipt-date') || {} ).textContent || '',
			};
		})()`;

		const aprobado = await evaluar( pintarRecibo( 'approved', 'Pago confirmado' ) );
		check( 'pago aprobado: etiqueta', aprobado.badge, 'Pago confirmado' );
		check( 'pago aprobado: clase del badge', aprobado.clase.includes( 'pn-receipt__badge--approved' ), true );
		check( 'pago aprobado: las 7 filas con dato', aprobado.conDato === aprobado.total && aprobado.total > 0, true );
		check( 'pago aprobado: fecha del servidor', aprobado.fecha, '01/01/2026 12:00' );

		const pendiente = await evaluar( pintarRecibo( 'pending', 'Pago pendiente' ) );
		check( 'pago pendiente: etiqueta', pendiente.badge, 'Pago pendiente' );
		check( 'pago pendiente: clase del badge', pendiente.clase.includes( 'pn-receipt__badge--pending' ), true );

		// ------------------------------------------------------------------
		console.log( '\n=== Consola del navegador ===' );

		const errores = await evaluar( 'window.__errores' );
		check( 'sin errores de JavaScript', errores, [] );

		if ( errores.length ) {
			console.log( '    ' + errores.join( '\n    ' ) );
		}
	} finally {
		try {
			if ( ws ) {
				ws.close();
			}
		} catch ( e ) {
			// da igual: se cierra el navegador igual
		}

		proceso.kill( 'SIGKILL' );
		rmSync( perfil, { recursive: true, force: true } );
	}

	console.log( `\n${ 0 === failures ? 'TODO OK' : `FALLAS: ${ failures }` }` );
	process.exit( failures > 0 ? 1 : 0 );
}

main().catch( ( error ) => {
	console.error( 'La prueba no pudo correr:', error.message );
	process.exit( 2 );
} );
