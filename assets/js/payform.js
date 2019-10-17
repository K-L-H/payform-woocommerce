jQuery(document).ready(function($){
	if (jQuery('form#order_review').length>0) {

		jQuery('#bambora-payform-bank-payments .bank-button').on('click',function() {
			jQuery('#bambora-payform-bank-payments .bank-button').removeClass('selected');
			jQuery(this).addClass('selected');
			var id = jQuery(this).attr('id');
			var selected = id.replace('bambora-payform-button-','');
			jQuery('#bambora_payform_selected_bank').val(selected);
		});
	}

	jQuery('body').on( 'updated_checkout', function () {
		jQuery('#bambora-payform-bank-payments .bank-button').on('click',function() {
			jQuery('#bambora-payform-bank-payments .bank-button').removeClass('selected');
			jQuery(this).addClass('selected');
			var id = jQuery(this).attr('id');
			var selected = id.replace('bambora-payform-button-','');
			jQuery('#bambora_payform_selected_bank').val(selected);
		});

	});
}(jQuery));