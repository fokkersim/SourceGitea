# MantisBt Source/SourceGitea Plugin

## Requirements
At the moment SourceGitea requires the [PHP Curl](https://www.php.net/book.curl) extension, or the ability to execute
system calls ([via shell_exec](https://www.php.net/function.shell-exec)).

## Repository configuration
Make sure the base URL of the Gitea installation has no tailing / and use either user name or organization name depending on the ownder of the repository you want to add.

## Authentication
OAuth authentication is implemented including token timeout check and refresh request via refresh-token. API token authentication planned for future.
1) Add an application to Gitea Settings -> Applications -> Create a new OAuth2 Application and enter
	* Application Name
	* redirect_uri has to be set to https://yourDomain/mantisbt/plugin.php?page=SourceGitea/oauth_authorize&id=<MANTIS_REPO_ID>
	* Press "Create Application"
	* Copy Client Id and Client Secret to the repository configuration page in MantisBt
	* Press "Authenticate" button
3) Be advised, that if you do a "import full" via the repository management page a oauth refresh of the authentication token is not possible anymore, since the mantis repository id changes. Use "import latest" instead, with exactly the same effect. This is due to the MantisBt Source plugin implementation.

## Webhook configuration
Gitea webhooks no not support webhook secrets at the moment. Therefore only the MantisBt API key is used in both automatic and manual webhook creation.
### Automatic creation
Use the button in the repository management of the plugin
### Manual creation
* Webhook type select "Gitea"
* application/json
* POST
* Secret field has to be kept empty
* URL https://yourDomain/mantisbt/plugin.php?page=Source/checkin&api_key=<API_KEY_FROM_MANTIS_VCS_CONFIG_PAGE>
* Secret field in SourceGitea repository configuration has to be kept empty (Gitea does not support secret field in payload)