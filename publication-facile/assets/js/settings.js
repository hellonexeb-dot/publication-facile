/**
 * Publication Facile — réglages : analyse automatique au changement de modèle.
 */
( function () {
	'use strict';
	var select = document.querySelector( '[data-pf-autosubmit]' );
	if ( ! select || ! select.form ) {
		return;
	}
	select.addEventListener( 'change', function () {
		select.form.submit();
	} );
}() );
