# MantisBt Source/SourceGitea Plugin

## General
This plugin is adapted from [SourceGithub](https://github.com/mantisbt-plugins/source-integration/tree/master/SourceGithub). It is tested with version 2.5.1 of the base plugin [Source](https://github.com/mantisbt-plugins/source-integration/tree/master/Source). It is at a very early stage with header authentication and PHP Curl only.

## Requirements
At the moment SourceGitea requires the [PHP Curl](https://www.php.net/book.curl) extension, or the ability to execute
system calls ([via shell_exec](https://www.php.net/function.shell-exec)).
The base VCS integration plugin [Source](https://github.com/mantisbt-plugins/source-integration/tree/master/Source) is required.
## Repository configuration
Make sure the base URL of the Gitea installation has no tailing / and use either user name or organization name depending on the ownder of the repository you want to add. Make sure not to add heading or tailing spaces to both user/organization name and repository name.

## Authentication
OAuth authentication is implemented including token timeout check and refresh request via refresh-token. API token authentication planned for future.
1) Add an application to Gitea Settings -> Applications -> Create a new OAuth2 Application and enter
	* Application Name
	* redirect_uri has to be set to https://yourDomain/mantisbt/plugin.php?page=SourceGitea/oauth_authorize&id=<MANTIS_REPO_ID>
	* Press "Create Application"
	* Copy Client Id and Client Secret to the repository configuration page in MantisBt
	* Press "Authenticate" button
3) Be advised, that if you do a "import full" via the repository management page a oauth refresh of the authentication token is not possible anymore, since the mantis repository id changes. Use "import latest" instead, with exactly the same effect. This is due to the MantisBt Source plugin implementation.
4) It semms that every repository needs to have its own Gitea OAuth2 Application with corresponding client id and client secret. This is due to the redirect_uri which requires the mantis repository id as a parameter to pass the request code for the access token. Better solutions are highly welcome

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

## Todo

* Implement Gitea Access Token authentication as a choice (simple access token without oAuth2)
* Re-add webhook secrets