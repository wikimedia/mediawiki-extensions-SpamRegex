$( () => {
	// This JS ensures that at least one block mode is always checked
	const SRTextboxControl = $( '#wpBlockedTextbox' ),
		SRSummaryControl = $( '#wpBlockedSummary' );

	SRTextboxControl.on( 'click', () => {
		if ( !SRTextboxControl.prop( 'checked' ) ) {
			if ( !SRSummaryControl.prop( 'checked' ) ) {
				SRSummaryControl.prop( 'checked', true );
			}
		}
	} );

	$( '#wpBlockedSummary' ).on( 'click', () => {
		if ( !SRSummaryControl.prop( 'checked' ) ) {
			if ( !SRTextboxControl.prop( 'checked' ) ) {
				SRTextboxControl.prop( 'checked', true );
			}
		}
	} );

	// Handle clicks on the "remove" link for users w/ JS enabled
	$( '.spamregex-entry ul li a.external' ).on( 'click', function ( e ) {
		// Don't bother following the link since we have JS, d'oh...
		e.preventDefault();

		// Wow, this is nasty.
		// 1) Get the href (no-JS unblocking form URL),
		// 2) split it along the ampersands,
		// 3) get the last part (text=<phrase to unblock>)
		// 4) and remove "text=" from it, which leaves us with only the phrase to unblock
		const thisElement = $( this ),
			phrase = thisElement.attr( 'href' ).split( /&/ ).pop().replace( /text=/, '' );

		( new mw.Api() ).postWithToken( 'csrf', {
			action: 'spamregex',
			format: 'json',
			do: 'delete',
			phrase: phrase
		} ).done( ( data ) => {
			if ( data.spamregex.result === 'ok' ) {
				thisElement.parent().parent().parent().fadeOut( 500 );
			} else if ( data.spamregex.result === 'error' ) {
				alert( mw.msg( 'spamregex-error-unblocking' ) );
			}
		} );
	} );
} );
