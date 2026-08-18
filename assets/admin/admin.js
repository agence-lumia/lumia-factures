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
	 * État réactif consommé par les directives `x-show` du schéma.
	 *
	 * Les valeurs proviennent de PHP au chargement, puis sont tenues à jour
	 * par les `x-model` posés sur chaque champ.
	 */
	function initAlpineState() {
		var values = ( window.fplAdmin && window.fplAdmin.values ) || {};
		var state = {};

		Object.keys( values ).forEach( function ( key ) {
			var value = values[ key ];

			// PHP sérialise les booléens en 1/0 ou "1"/"" selon le contexte.
			// Alpine compare en strict : on normalise à l'entrée pour que les
			// conditions `depends` fonctionnent quelle que soit la source.
			if ( value === '1' || value === 1 ) {
				state[ key ] = true;
			} else if ( value === '' || value === '0' || value === 0 ) {
				state[ key ] = false;
			} else {
				state[ key ] = value;
			}
		} );

		document.addEventListener( 'alpine:init', function () {
			window.Alpine.store( 'fpl', { values: state } );
		} );

		// Le formulaire porte le scope Alpine : on l'expose via x-data pour
		// que chaque champ accède à `values` sans wrapper supplémentaire.
		var form = document.querySelector( '.fpl-form' );

		if ( form ) {
			form.setAttribute( 'x-data', JSON.stringify( { values: state } ) );
		}
	}

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

	$( function () {
		initAlpineState();
		initMediaFields();
		initColorFields();
		initTagInsertion();
	} );
} )( jQuery );
