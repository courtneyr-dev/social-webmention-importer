export default {
	'swi-import-social-responses': {
		isEligible: ( item ) => Boolean( item.swi_review_url ),
		callback: ( [ item ] ) => {
			if ( item?.swi_review_url ) {
				window.location.assign( item.swi_review_url );
			}
		},
	},
};
