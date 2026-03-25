( function( $ ) {
	var previewRequest = null;
	var previewTimer = null;

	function geopriceScheduleCheckoutPreview() {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( geopriceRefreshCheckoutPreview, 150 );
	}

	function geopriceRefreshCheckoutPreview() {
		if (
			typeof geopriceCheckout === 'undefined' ||
			! geopriceCheckout.previewUrl ||
			typeof pmpro_getCheckoutFormDataForCheckoutLevels !== 'function' ||
			! $( '#pmpro_form' ).length ||
			! $( '#pmpro_level_cost' ).length
		) {
			return;
		}

		if ( previewRequest && previewRequest.readyState !== 4 ) {
			previewRequest.abort();
		}

		previewRequest = $.ajax( {
			url: geopriceCheckout.previewUrl,
			dataType: 'json',
			data: pmpro_getCheckoutFormDataForCheckoutLevels() + '&geoprice_checkout_preview=1',
			success: function( response ) {
				if ( response && response.html ) {
					$( '#pmpro_level_cost' ).html( response.html );
				}
			}
		} );
	}

	$( document ).ready( function() {
		if ( ! $( '#pmpro_form' ).length || ! $( '#pmpro_level_cost' ).length ) {
			return;
		}

		$( document ).on( 'change', '#bcountry', geopriceScheduleCheckoutPreview );

		$( document ).ajaxSuccess( function( event, xhr, settings ) {
			if ( settings && settings.data && settings.data.indexOf( 'action=applydiscountcode' ) !== -1 ) {
				geopriceScheduleCheckoutPreview();
			}
		} );

		if ( $( '#bcountry' ).length ) {
			$( '#bcountry' ).trigger( 'change' );
		} else {
			geopriceScheduleCheckoutPreview();
		}
	} );
} )( jQuery );
