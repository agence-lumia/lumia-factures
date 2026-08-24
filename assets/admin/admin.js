/**
 * Facture PDF Lumia — scripts d'administration.
 *
 * Trois responsabilités seulement : l'état réactif partagé avec Alpine pour
 * l'affichage conditionnel, le sélecteur de média WordPress, et le sélecteur
 * de couleur. Tout le reste est rendu côté PHP.
 */
( function ( $ ) {
	'use strict';

	/**
	 * Sélecteur de média WordPress.
	 */
	function initMediaFields() {
		$( document ).on( 'click', '.fpl-media__select', function ( event ) {
			event.preventDefault();

			var $wrapper = $( this ).closest( '.fpl-media' );
			var frame = wp.media( {
				title: window.fplAdmin.i18n.selectImage,
				button: { text: window.fplAdmin.i18n.useImage },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.url;

				// On préfère une taille intermédiaire quand elle existe :
				// inutile de charger un original de 4000 px dans un aperçu.
				if ( attachment.sizes && attachment.sizes.medium ) {
					url = attachment.sizes.medium.url;
				}

				$wrapper.find( '.fpl-media__input' ).val( attachment.id );
				$wrapper.find( '.fpl-media__preview' ).show().find( 'img' ).attr( 'src', url );
				$wrapper.find( '.fpl-media__remove' ).show();
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.fpl-media__remove', function ( event ) {
			event.preventDefault();

			var $wrapper = $( this ).closest( '.fpl-media' );

			$wrapper.find( '.fpl-media__input' ).val( 0 );
			$wrapper.find( '.fpl-media__preview' ).hide();
			$( this ).hide();
		} );
	}

	/**
	 * Sélecteur de couleur natif WordPress.
	 */
	function initColorFields() {
		if ( ! $.fn.wpColorPicker ) {
			return;
		}

		$( '.fpl-color-picker' ).wpColorPicker( {
			change: function ( event, ui ) {
				var input = event.target;

				// wpColorPicker écrit la valeur sans déclencher d'événement
				// natif : Alpine ne verrait rien passer sans ce relais.
				input.value = ui.color.toString();
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} );
	}

	/**
	 * Insertion d'un tag de numérotation dans le champ Format.
	 *
	 * Le tag s'insère à la position du curseur plutôt qu'en fin de champ :
	 * construire `FA-{year}-{number:4}` demande sinon de retaper la fin à
	 * chaque insertion.
	 */
	function initTagInsertion() {
		function insert( element ) {
			var input = document.getElementById( 'fpl-format' );

			if ( ! input ) {
				return;
			}

			var tag = element.textContent.trim();
			var start = input.selectionStart !== null ? input.selectionStart : input.value.length;
			var end = input.selectionEnd !== null ? input.selectionEnd : input.value.length;

			input.value = input.value.slice( 0, start ) + tag + input.value.slice( end );
			input.focus();
			input.setSelectionRange( start + tag.length, start + tag.length );

			// Alpine suit la valeur via x-model : sans événement natif, l'état
			// réactif resterait sur l'ancienne saisie.
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

			element.classList.add( 'fpl-tag--copied' );
			window.setTimeout( function () {
				element.classList.remove( 'fpl-tag--copied' );
			}, 600 );
		}

		$( document ).on( 'click', '.fpl-tag', function () {
			insert( this );
		} );

		$( document ).on( 'keydown', '.fpl-tag', function ( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				insert( this );
			}
		} );
	}


	/**
	 * Signale les modifications non enregistrées.
	 *
	 * Deux mécanismes complémentaires : l'indicateur dans la barre
	 * d'enregistrement (via Alpine) et l'avertissement natif du navigateur à
	 * la fermeture. Sur une page à plus de cent champs, quitter sans
	 * enregistrer est une erreur facile et coûteuse.
	 */
	function initDirtyTracking() {
		var form = document.querySelector( '.fpl-form' );

		if ( ! form ) {
			return;
		}

		var dirty = false;

		function markDirty() {
			if ( dirty ) {
				return;
			}

			dirty = true;

			if ( form._x_dataStack && form._x_dataStack[0] ) {
				form._x_dataStack[0].dirty = true;
			}
		}

		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );

		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! dirty ) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		} );
	}

	/**
	 * Somme des largeurs de colonnes du tableau produits.
	 *
	 * Les largeurs sont exprimées en pourcentage. Rien n'oblige à tomber sur
	 * 100 — le moteur redistribue l'écart — mais s'en éloigner beaucoup donne
	 * des colonnes aux proportions inattendues, sans que rien ne l'indique
	 * dans le PDF. L'indicateur rend le total visible pendant le réglage.
	 */
	function initColumnWidthSum() {
		var section = document.getElementById( 'fpl-section-columns' );

		if ( ! section ) {
			return;
		}

		var indicator = document.createElement( 'div' );
		indicator.className = 'fpl-widthsum';
		section.appendChild( indicator );

		function widthInputs() {
			return Array.prototype.slice.call(
				section.querySelectorAll( 'input[type="number"]' )
			).filter( function ( input ) {
				return /_width\]$/.test( input.name );
			} );
		}

		function isColumnEnabled( widthInput ) {
			var key = widthInput.name.replace( /_width\]$/, '_enabled]' );
			var toggle = section.querySelector( 'input[type="checkbox"][name="' + key + '"]' );

			return ! toggle || toggle.checked;
		}

		function refresh() {
			var total = 0;

			widthInputs().forEach( function ( input ) {
				if ( isColumnEnabled( input ) ) {
					total += parseFloat( input.value ) || 0;
				}
			} );

			total = Math.round( total * 10 ) / 10;

			var over = Math.abs( total - 100 ) > 5;

			indicator.className = 'fpl-widthsum' + ( over ? ' fpl-widthsum--over' : '' );
			indicator.textContent = over
				? 'Total des colonnes actives : ' + total + ' % — un total proche de 100 % donne des proportions plus prévisibles.'
				: 'Total des colonnes actives : ' + total + ' %';
		}

		section.addEventListener( 'input', refresh );
		section.addEventListener( 'change', refresh );

		refresh();
	}


	/**
	 * Aperçu HTML en direct.
	 *
	 * Envoie les valeurs non enregistrées du formulaire à un endpoint qui
	 * rend le même gabarit que le PDF. Le délai d'attente évite de déclencher
	 * un rendu à chaque frappe dans un champ texte.
	 */
	function initLivePreview() {
		var iframe = document.getElementById( 'fpl-preview-iframe' );

		if ( ! iframe || ! window.fplAdmin || ! window.fplAdmin.preview ) {
			return;
		}

		var loading = document.getElementById( 'fpl-preview-loading' );
		var source = document.getElementById( 'fpl-preview-source' );
		var orderInput = document.getElementById( 'fpl-preview-order' );
		var orderMirror = document.getElementById( 'fpl-preview-order-mirror' );
		var timer = null;
		var pending = null;

		function orderId() {
			return orderInput && orderInput.value ? parseInt( orderInput.value, 10 ) || 0 : 0;
		}

		function render() {
			var data = new FormData();

			data.append( 'action', window.fplAdmin.preview.action );
			data.append( 'nonce', window.fplAdmin.preview.nonce );
			data.append( 'tab', window.fplAdmin.tab );
			data.append( 'order_id', String( orderId() ) );

			// Les réglages en cours d'édition sont transmis tels quels : le
			// serveur les nettoie avant de les appliquer, le temps du rendu.
			var form = document.querySelector( '.fpl-form' );

            if ( form ) {
				new FormData( form ).forEach( function ( value, key ) {
					if ( key.indexOf( 'fpl[' ) === 0 ) {
						data.append( key, value );
					}
				} );
			}

			if ( loading ) {
				loading.style.display = 'flex';
			}

			// Une requête en cours est abandonnée dès qu'une nouvelle part :
			// sans cela, une réponse lente pourrait écraser un rendu plus récent.
			if ( pending && pending.abort ) {
				pending.abort();
			}

			var controller = window.AbortController ? new AbortController() : null;
			pending = controller;

			fetch( window.fplAdmin.preview.url, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
				signal: controller ? controller.signal : undefined
			} )
				.then( function ( response ) {
					// La réponse est lue en texte plutôt qu'en JSON direct :
					// une erreur fatale PHP produit du HTML, pas du JSON, et
					// `response.json()` échouerait avec un message générique
					// qui ne dit rien de la cause réelle. Lire en texte permet
					// de distinguer les deux cas et de garder la réponse brute
					// pour la console.
					return response.text().then( function ( text ) {
						return { status: response.status, text: text };
					} );
				} )
				.then( function ( raw ) {
					var payload;

					try {
						payload = JSON.parse( raw.text );
					} catch ( parseError ) {
						console.error( 'Aperçu Facture PDF Lumia — réponse non-JSON (HTTP ' + raw.status + ') :', raw.text );
						throw new Error( 'Réponse inattendue du serveur (HTTP ' + raw.status + '). Détail dans la console (F12).' );
					}

					if ( ! payload || ! payload.success ) {
						var message = payload && payload.data && payload.data.message
							? payload.data.message
							: 'Échec de la génération (HTTP ' + raw.status + ').';

						throw new Error( message );
					}

					var doc = iframe.contentWindow.document;

					doc.open();
					doc.write( payload.data.html );
					doc.close();

					if ( source ) {
						source.textContent = payload.data.source || '';
					}
				} )
				.catch( function ( error ) {
					if ( error && error.name === 'AbortError' ) {
						return;
					}

					if ( source ) {
						source.textContent = error && error.message ? error.message : 'Aperçu indisponible';
					}
				} )
				.finally( function () {
					if ( loading ) {
						loading.style.display = 'none';
					}

					pending = null;
				} );
		}

		function schedule() {
			window.clearTimeout( timer );
			timer = window.setTimeout( render, 400 );
		}

		if ( orderInput ) {
			orderInput.addEventListener( 'input', function () {
				if ( orderMirror ) {
					orderMirror.value = String( orderId() );
				}

				schedule();
			} );
		}

		var refresh = document.getElementById( 'fpl-preview-refresh' );

		if ( refresh ) {
			refresh.addEventListener( 'click', render );
		}

		render();
	}

	$( function () {
		initMediaFields();
		initColorFields();
		initTagInsertion();
		initDirtyTracking();
		initColumnWidthSum();
		initLivePreview();
	} );
} )( jQuery );
