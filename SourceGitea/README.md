# MantisBt Source/SourceGitea Plugin

## Requirements
At the moment SourceGitea requires the [PHP Curl](https://www.php.net/book.curl) extension, or the ability to execute
system calls ([via shell_exec](https://www.php.net/function.shell-exec)).

## Authentication
OAuth authentication is implemented including token timeout check and refresh request via refresh-token. API token authentication planned for future.

## Webhook configuration

* Webhook type select "Gitea"
* application/json
* POST
* Secret field has to be kept empty
* URL https://yourDomain/mantisbt/plugin.php?page=Source/checkin&api_key=<API_KEY_FROM_MANTIS_VCS_CONFIG_PAGE>
* Secret field in SourceGitea repository configuration has to be kept empty (Gitea does not support secret field in payload)