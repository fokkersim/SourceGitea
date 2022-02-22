// Copyright (c) 2019 Damien Regad
// Licensed under the MIT license

/**
 * Namespace for global function used in list_action.js
 */
var SourceGitea = SourceGitea || {};

/**
 * Return MantisBT REST API URL for given endpoint
 * @param {string} endpoint
 * @returns {string} REST API URL
 */
SourceGitea.rest_api = function(endpoint) {
	// Using the full URL (through index.php) to avoid issues on sites
	// where URL rewriting is not working
	return "api/rest/index.php/plugins/SourceGitea/" + endpoint;
};

jQuery(document).ready(function($) {
	$('#hub_app_client_id, #hub_app_secret').change(set_visibility);
	$('#tea_btn_auth_revoke').click(tea_revoke_token);
	$('#tea_webhook_create > button').click(tea_webhook_create);

	// The PHP code initially hides all token authorization elements using the.
	// 'hidden' class, which we need to remove so we can set visibility using
	// show/hide functions
	set_visibility();
	$('.sourcegithub_token, #id_secret_missing').removeClass('hidden');

	function set_visibility() {
		var div_id_secret_missing = $('#id_secret_missing');
		var client_id = $('#hub_app_client_id');
		var secret = $('#hub_app_secret');

		// If Client ID and secret are set and equal to the recorded values
		// for the repository, we hide the information message and display the
		// authorize or revoke button and authorization status as needed.
		if (   client_id.val() !== ''
			&& client_id.val() === client_id.data('original')
			&& secret.val() !== ''
			&& secret.val() === secret.data('original')
		) {
			var div_token_authorized = $('#token_authorized');
			var div_token_missing = $('#token_missing');
			var div_webhook = $('#tea_webhook_create');
			var token = div_token_authorized.children('input');

			div_id_secret_missing.hide();
			if (token.val() !== '') {
				div_token_authorized.add(div_webhook).show();
				div_token_missing.hide();
			} else {
				div_token_authorized.add(div_webhook).hide();
				div_token_missing.show();
			}
		} else {
			div_id_secret_missing.show();
			$('.sourcegithub_token').hide();
		}
	}

	function tea_revoke_token() {
		var repo_id = $('#repo_id').val();

		$.ajax({
			type: 'DELETE',
			url: SourceGitea.rest_api(repo_id + '/token'),
			success: function(data, textStatus, xhr) {
					$('#hub_app_access_token').val('');
					set_visibility();
				}
		});
	}

	function tea_webhook_create() {
		var repo_id = $('#repo_id').val();
		var status_icon = $('#webhook_status > i');
		var status_message = $('#webhook_status > span');

		$.ajax({
			type: 'POST',
			url: SourceGitea.rest_api(repo_id + '/webhook'),
			success: function(data, textStatus, xhr) {
				status_icon.removeClass("fa-exclamation-triangle red").addClass("fa-check green");
				status_message.text(xhr.statusText);
				$('#tea_webhook_create > button').prop("disabled", true);
			},
			error: function(xhr, textStatus, errorThrown) {
				status_icon.removeClass("fa-check green").addClass("fa-exclamation-triangle red");
				console.log(xhr);
				console.log(textStatus);
				console.log(errorThrown);
				var details = JSON.parse(xhr.responseText);
				if (xhr.status === 409) {
					status_message.html(
						'<a href="' + details.web_url + '">' + errorThrown + '</a>'
					);
				} else {
					status_message.text(errorThrown);
				}

				console.error(
					'Webhook creation failed',
					{ error: errorThrown, details: details, request: this.url, x: textStatus }
				);
			}
		});
	}

});
