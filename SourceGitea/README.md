# MantisBt Source/SourceGitea Plugin

## Webhook configuration

* Webhook type select "Gitea"
* application/json
* POST
* Secret field has to be kept empty
* URL https://yourDomain/mantisbt/plugin.php?page=Source/checkin&api_key=af506cf83547ea2d5abcdd9c
* Secret field in SourceGitea repository configuration has to be kept empty (Gitea does not support secret field in payload)