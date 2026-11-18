/* global jQuery, wp, tinymce, pfEditor */
/**
 * Publication Facile — formulaire de rédaction.
 */
( function ( $ ) {
	'use strict';

	var $form = $( '#pf-form' );
	if ( ! $form.length ) {
		return;
	}

	var i18n = pfEditor.i18n;
	var dirty = false;
	var submitting = false;
	var frame = null;

	function syncEditor() {
		if ( window.tinymce ) {
			tinymce.triggerSave();
		}
	}

	function bodyText() {
		syncEditor();
		var html = $( '#pf_body' ).val() || '';
		return $( '<div>' ).html( html ).text().trim();
	}

	function feedback( text, isError ) {
		$( '#pf-feedback' ).text( text || '' ).toggleClass( 'is-error', !! isError );
	}

	function validate() {
		if ( ! $.trim( $( '#pf-title' ).val() ) ) {
			feedback( i18n.missingTitle, true );
			$( '#pf-title' ).trigger( 'focus' );
			return false;
		}
		if ( ! bodyText() ) {
			feedback( i18n.missingBody, true );
			return false;
		}
		if ( ! parseInt( $( '#pf-image-id' ).val(), 10 ) ) {
			feedback( i18n.missingImage, true );
			$( '#pf-image-button' ).trigger( 'focus' );
			return false;
		}
		feedback( '' );
		return true;
	}

	/* Modifications non enregistrées. */
	$form.on( 'input change', 'input, textarea', function () {
		dirty = true;
	} );
	$( document ).on( 'tinymce-editor-init', function ( event, editor ) {
		if ( editor.id === 'pf_body' ) {
			editor.on( 'input change undo redo', function () {
				dirty = true;
			} );
		}
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty && ! submitting ) {
			e.preventDefault();
			e.returnValue = i18n.unsaved;
			return i18n.unsaved;
		}
	} );

	/* Médiathèque. */
	$( '#pf-image-button' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( frame ) {
			frame.open();
			return;
		}
		frame = wp.media( {
			title: i18n.chooseImage,
			button: { text: i18n.useImage },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = ( att.sizes && ( att.sizes.medium_large || att.sizes.large || att.sizes.full ) || att ).url;
			$( '#pf-image-id' ).val( att.id );
			$( '#pf-image-preview' ).attr( 'src', url ).prop( 'hidden', false );
			$( '#pf-image-box' ).addClass( 'has-image' );
			$( '#pf-image-button' ).text( i18n.changeImage );
			if ( ! $.trim( $( '#pf-image-alt' ).val() ) && att.alt ) {
				$( '#pf-image-alt' ).val( att.alt );
			}
			dirty = true;
		} );
		frame.open();
	} );

	/* Envoi : on garde l'action choisie même si le bouton est désactivé. */
	var lastAction = '';
	$form.on( 'click', 'button[name="pf_action"]', function () {
		lastAction = this.value;
	} );

	$form.on( 'submit', function ( e ) {
		syncEditor();
		if ( ! validate() ) {
			e.preventDefault();
			return;
		}
		var action = ( e.originalEvent && e.originalEvent.submitter && e.originalEvent.submitter.value ) || lastAction || 'draft';
		$form.find( 'input[type="hidden"][name="pf_action"]' ).remove();
		$( '<input type="hidden" name="pf_action">' ).val( action ).appendTo( $form );
		submitting = true;
		$form.find( 'button' ).prop( 'disabled', true );
		$form.addClass( 'is-busy' );
	} );

	/* Aperçu : enregistre le brouillon puis l'ouvre dans un nouvel onglet. */
	$( '#pf-preview-button' ).on( 'click', function ( e ) {
		e.preventDefault();
		syncEditor();
		if ( ! validate() ) {
			return;
		}
		// Ouverture immédiate (sinon bloquée par le navigateur), puis redirection.
		var win = window.open( '', '_blank' );
		var $btn = $( this ).prop( 'disabled', true );
		feedback( i18n.previewWait );

		var data = $form.serializeArray().filter( function ( f ) {
			return f.name !== 'action' && f.name !== 'pf_action';
		} );
		data.push( { name: 'action', value: 'pf_preview' } );

		$.post( pfEditor.ajaxUrl, $.param( data ) )
			.done( function ( res ) {
				if ( res && res.success ) {
					$( '#pf-post-id' ).val( res.data.post_id );
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', res.data.edit_url );
					}
					dirty = false;
					feedback( '' );
					if ( win ) {
						win.location = res.data.preview_url;
					} else {
						window.location = res.data.preview_url;
					}
				} else {
					if ( win ) {
						win.close();
					}
					feedback( ( res && res.data && res.data.message ) || i18n.previewError, true );
				}
			} )
			.fail( function ( xhr ) {
				if ( win ) {
					win.close();
				}
				var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				feedback( msg || i18n.previewError, true );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	/* Aperçu demandé sans JavaScript actif au moment de l'envoi. */
	var autoPreview = document.getElementById( 'pf-open-preview' );
	if ( autoPreview ) {
		autoPreview.focus();
	}
}( jQuery ) );
