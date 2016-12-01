// This JS ensures that at least one block mode is always checked
$( function() {
	var SRTextboxControl = $( '#wpBlockedTextbox' );
	var SRSummaryControl = $( '#wpBlockedSummary' );

	SRTextboxControl.on( 'click', function () {
		if ( !SRTextboxControl.prop( 'checked' ) ) {
			if ( !SRSummaryControl.prop( 'checked' ) ) {
				SRSummaryControl.prop( 'checked', true );
			}
		}
	} );

	$( '#wpBlockedSummary' ).on( 'click', function () {
		if ( !SRSummaryControl.prop( 'checked' ) ) {
			if ( !SRTextboxControl.prop( 'checked' ) ) {
				SRTextboxControl.prop( 'checked', true );
			}
		}
	} );
} );