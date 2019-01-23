jQuery(function($) {


	// clone fields
	var $elFieldset = $('.marketing-sync-fieldset');
	$('.marketing-sync-add-field').click(function() {
		$elFieldset.find('.marketing-sync-user-field:last').clone().appendTo($elFieldset);
	});


});
