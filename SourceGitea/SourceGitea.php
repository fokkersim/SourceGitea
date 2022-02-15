<?php

# Copyright (c) 2012 John Reese
# Licensed under the MIT license
# Gitea API documentation https://try.gitea.io/api/swagger

if ( false === include_once( config_get( 'plugin_path' ) . 'Source/MantisSourceGitBasePlugin.class.php' ) ) {
	return;
}

require_once( config_get( 'core_path' ) . 'json_api.php' );

class SourceGiteaPlugin extends MantisSourceGitBasePlugin {

	const PLUGIN_VERSION = '0.0.1';
	const FRAMEWORK_VERSION_REQUIRED = '2.2.0';
	const MANTIS_VERSION = '2.17.1';

	public $linkPullRequest = '/pull/%s';

	public function register() {
		$this->name = plugin_lang_get( 'title' );
		$this->description = plugin_lang_get( 'description' );

		$this->version = self::PLUGIN_VERSION;
		$this->requires = array(
			'MantisCore' => self::MANTIS_VERSION,
			'Source' => self::FRAMEWORK_VERSION_REQUIRED,
		);

		$this->author = 'Andreas Harrer';
		$this->contact = 'alwaysthreegreens@gmail.com';
		$this->url = 'https://github.com/mantisbt-plugins/source-integration/';
	}

	public $type = 'gitea';

	public function resources( $p_event ) {
		# Only include the javascript when it's actually needed
		parse_str( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ), $t_query );
		if( array_key_exists( 'page', $t_query ) ) {
			$t_page = basename( $t_query['page'] );
			if( $t_page == 'repo_update_page' ) {
				return '<script src="' . plugin_file( 'sourcegitea.js' ) . '"></script>';
			}
		}
		return null;
	}

	public function hooks() {
		return parent::hooks() + array(
			"EVENT_LAYOUT_RESOURCES" => "resources",
			'EVENT_REST_API_ROUTES' => 'routes',
		);
	}

	/**
	 * Add the RESTful routes handled by this plugin.
	 *
	 * @param string $p_event_name The event name
	 * @param array  $p_event_args The event arguments
	 * @return void
	 */
	public function routes( $p_event_name, $p_event_args ) {
		$t_app = $p_event_args['app'];
		$t_plugin = $this;
		$t_app->group(
			plugin_route_group(),
			function() use ( $t_app, $t_plugin ) {
				$t_app->delete( '/{id}/token', [$t_plugin, 'route_token_revoke'] );
				$t_app->post( '/{id}/webhook', [$t_plugin, 'route_webhook'] );
			}
		);
	}

	/**
	 * RESTful route to revoke GitTea application token
	 *
	 * @param Slim\Http\Request  $p_request
	 * @param Slim\Http\Response $p_response
	 * @param array              $p_args
	 *
	 * @return Slim\Http\Response
	 */
	public function route_token_revoke( $p_request, $p_response, $p_args ) {
		# Make sure the given repository exists
		$t_repo_id = isset( $p_args['id'] ) ? $p_args['id'] : $p_request->getParam( 'id' );
		if( !SourceRepo::exists( $t_repo_id ) ) {
			return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Invalid Repository Id' );
		}

		# Check that the repo is of GitTea type
		$t_repo = SourceRepo::load( $t_repo_id );
		if( $t_repo->type != $this->type ) {
			return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, "Id $t_repo_id is not a Gitea repository" );
		}

		# Clear the token
		unset( $t_repo->info['hub_app_access_token'] );
		$t_repo->save();

		return $p_response->withStatus( HTTP_STATUS_NO_CONTENT );
	}

	/**
	 * RESTful route to create GitHub checkin webhook
	 *
	 * @param Slim\Http\Request  $p_request
	 * @param Slim\Http\Response $p_response
	 * @param array              $p_args
	 *
	 * @return Slim\Http\Response
	 */
	public function route_webhook( $p_request, $p_response, $p_args ) {
		plugin_push_current( 'Source' );
		# Make sure the given repository exists
		$t_repo_id = isset( $p_args['id'] ) ? $p_args['id'] : $p_request->getParam( 'id' );
		if( !SourceRepo::exists( $t_repo_id ) ) {
			return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Invalid Repository Id' );
		}
		# Check that the repo is of GitHub type
		$t_repo = SourceRepo::load( $t_repo_id );
		if( $t_repo->type != $this->type ) {
			return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, "Id $t_repo_id is not a Gitea repository" );
		}

		$t_username = $t_repo->info['hub_username'];
		$t_reponame = $t_repo->info['hub_reponame'];

		# GitHub webhook payload URL
		$t_payload_url = config_get( 'path' ) . plugin_page( 'checkin', true )
			. '&api_key=' . plugin_config_get( 'api_key' );
		# Retrieve existing webhooks
		try {
			$t_gitea_api = new \GuzzleHttp\Client();
			# OK UP TO HERE
			#return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, $t_payload_url);
			#$t_api_uri = SourceGiteaPlugin::api_uri( $t_repo, "repos/$t_username/$t_reponame/hooks" );
			#$t_response = $t_gitea_api->get( $t_api_uri );
			$t_response = SourceGiteaPlugin::url_get_json( $t_repo, "repos/$t_username/$t_reponame/hooks" );
		}
		catch( GuzzleHttp\Exception\ClientException $e ) {
			return $e->getResponse();
		}
		
		#$t_hooks = json_decode( (string) $t_response->getBody() );
		$t_hooks = $t_response;

		# Determine if there is already a webhook attached to the plugin's payload URL
		$t_id = false;
		if(!empty( $t_hooks )) {
			foreach( $t_hooks as $t_hook ) {
				if(   $t_hook->type == 'gitea' && $t_hook->config->url == $t_payload_url ) {
					$t_id = $t_hook->id;
					break;
				}
			}

			if( $t_id ) {
				# Webhook already exists for this URL
				# Set the Gitea URL so user can easily link to it
				$f_tea_root = $t_repo->info['tea_root'];
				$t_hook->web_url = "$f_tea_root/$t_username/$t_reponame/settings/hooks/" . $t_hook->id;
				return $p_response
					->withStatus( HTTP_STATUS_CONFLICT,
						plugin_lang_get( 'webhook_exists', 'SourceGitea' ) )
					->withJson( $t_hook );
			}
		}
		# Create new webhook
		#try {
			$t_payload = array(
				'active' => true,
				'branch_filter' => '*',
				'config' => array(
					'url' => $t_payload_url,
					'content_type' => 'json',
				),
				'events' => array(
					'push'
				),
				'type' => 'gitea'
			);
			$t_access_token = $t_repo->info['hub_app_access_token'];
			$f_tea_root = $t_repo->info['tea_root'];
			$t_uri = "$f_tea_root/api/v1/repos/$t_username/$t_reponame/hooks?token=$t_access_token";
			#return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, $t_uri);
			#$t_gitea_response = $t_gitea_api->post( "repos/$t_username/$t_reponame/hooks?token=$t_access_token",
			#	array( GuzzleHttp\RequestOptions::JSON => $t_payload )
			#);
			$data = SourceGiteaPlugin::url_post_json($t_uri, $t_payload);
			#return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, $data);
		#}
		#catch( GuzzleHttp\Exception\ClientException $e ) {
			#return $e->getResponse();
		#}

		return $p_response
			->withStatus( HTTP_STATUS_CREATED,
				plugin_lang_get( 'webhook_success', 'SourceGitea' ) )
			->withHeader('Content-type', 'application/json')
			->write( $data );
	}

	public function show_type() {
		return plugin_lang_get( 'gitea' );
	}

	public function show_changeset( $p_repo, $p_changeset ) {
		$t_ref = substr( $p_changeset->revision, 0, 8 );
		$t_branch = $p_changeset->branch;

		return "$t_branch $t_ref";
	}

	public function show_file( $p_repo, $p_changeset, $p_file ) {
		return  "$p_file->action - $p_file->filename";
	}

	public function url_repo( $p_repo, $p_changeset=null ) {
		if( empty( $p_repo->info ) ) {
			return '';
		}
		$t_tea_root = $p_repo->info['tea_root'];
		$t_username = $p_repo->info['hub_username'];
		$t_reponame = $p_repo->info['hub_reponame'];
		$t_ref = "";

		if ( !is_null( $p_changeset ) ) {
			$t_ref = "/commit/$p_changeset->revision"; # Here /tree/ was replaced by /commit/ since gitea does not provide access this way to the file tree of a commit at the moment.
		}

		return "$t_tea_root/$t_username/$t_reponame$t_ref";
	}

	public function url_changeset( $p_repo, $p_changeset ) {
		$t_tea_root = $p_repo->info['tea_root'];
		$t_username = $p_repo->info['hub_username'];
		$t_reponame = $p_repo->info['hub_reponame'];
		$t_ref = $p_changeset->revision;

		return "$t_tea_root/$t_username/$t_reponame/commit/$t_ref";
	}

	public function url_file( $p_repo, $p_changeset, $p_file ) {
		$t_tea_root = $p_repo->info['tea_root'];
		$t_username = $p_repo->info['hub_username'];
		$t_reponame = $p_repo->info['hub_reponame'];
		$t_ref = $p_changeset->revision;
		$t_filename = $p_file->filename;

		return "$t_tea_root/$t_username/$t_reponame/src/commit/$t_ref/$t_filename"; # Here /tree/ replaced by /src/commit/
	}

	public function url_diff( $p_repo, $p_changeset, $p_file ) {
		$t_tea_root = $p_repo->info['tea_root'];
		$t_username = $p_repo->info['hub_username'];
		$t_reponame = $p_repo->info['hub_reponame'];
		$t_ref = $p_changeset->revision;
		$t_filename = $p_file->filename;

		return "$t_tea_root/$t_username/$t_reponame/commit/$t_ref";
	}

	public function update_repo_form( $p_repo ) {
		$t_tea_root = null;
		$t_hub_username = null;
		$t_hub_reponame = null;
		$t_hub_app_client_id = null;
		$t_hub_app_secret = null;
		$t_hub_app_access_token = null;
		$t_hub_webhook_secret = null;

		if ( isset( $p_repo->info['tea_root'] ) ) {
			$t_tea_root = $p_repo->info['tea_root'];
		}

		if ( isset( $p_repo->info['hub_username'] ) ) {
			$t_hub_username = $p_repo->info['hub_username'];
		}

		if ( isset( $p_repo->info['hub_reponame'] ) ) {
			$t_hub_reponame = $p_repo->info['hub_reponame'];
		}

		if ( isset( $p_repo->info['hub_app_client_id'] ) ) {
			$t_hub_app_client_id = $p_repo->info['hub_app_client_id'];
		}

		if ( isset( $p_repo->info['hub_app_secret'] ) ) {
			$t_hub_app_secret = $p_repo->info['hub_app_secret'];
		}

		if ( isset( $p_repo->info['hub_app_access_token'] ) ) {
			$t_hub_app_access_token = $p_repo->info['hub_app_access_token'];
		}

		if ( isset( $p_repo->info['hub_webhook_secret'] ) ) {
			$t_hub_webhook_secret = $p_repo->info['hub_webhook_secret'];
		}

		if ( isset( $p_repo->info['master_branch'] ) ) {
			$t_master_branch = $p_repo->info['master_branch'];
		} else {
			$t_master_branch = 'master';
		}
?>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'tea_root' ) ?></td>
	<td>
		<input type="text" name="tea_root" maxlength="250" size="40" value="<?php echo string_attribute( $t_tea_root ) ?>"/>
	</td>
</tr>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'hub_username' ) ?></td>
	<td>
		<input type="text" name="hub_username" maxlength="250" size="40" value="<?php echo string_attribute( $t_hub_username ) ?>"/>
	</td>
</tr>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'hub_reponame' ) ?></td>
	<td>
		<input type="text" name="hub_reponame" maxlength="250" size="40" value="<?php echo string_attribute( $t_hub_reponame ) ?>"/>
	</td>
</tr>


<tr>
	<td class="category"><?php echo plugin_lang_get( 'hub_app_client_id' ) ?></td>
	<td>
		<input name="hub_app_client_id" id="hub_app_client_id"
			   type="text" maxlength="250" size="40"
			   value="<?php echo string_attribute( $t_hub_app_client_id ) ?>"
			   data-original="<?php echo string_attribute( $t_hub_app_client_id ) ?>"
		/>
	</td>
</tr>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'hub_app_secret' ) ?></td>
	<td>
		<input name="hub_app_secret" id="hub_app_secret"
			   type="text" maxlength="250" size="40"
			   value="<?php echo string_attribute( $t_hub_app_secret ) ?>"
			   data-original="<?php echo string_attribute( $t_hub_app_secret ) ?>"
		/>
	</td>
</tr>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'hub_app_access_token' ) ?></td>
	<td>
		<div id="id_secret_missing" class="hidden">
			<?php echo plugin_lang_get( 'hub_app_client_id_secret_missing' ); ?>
		</div>

		<div id="token_missing" class="sourcegithub_token hidden">
			<?php
			print_small_button(
				$this->oauth_authorize_uri( $p_repo ),
				plugin_lang_get( 'hub_app_authorize' )
			);
			?>
		</div>

		<div id="token_authorized" class="sourcegithub_token hidden">
			<input name="hub_app_access_token" id="hub_app_access_token"
				   type="hidden" maxlength="250" size="40"
				   value="<?php echo string_attribute( $t_hub_app_access_token ) ?>"
			/>
			<?php echo plugin_lang_get( 'hub_app_authorized' ); ?>&nbsp;
			<button id="btn_auth_revoke" type="button"
					class="btn btn-primary btn-white btn-round btn-sm"
					data-token-set="<?php echo $t_hub_app_access_token ? 'true' : 'false' ?>"
			>
				<?php echo plugin_lang_get( 'hub_app_revoke' ) ?>
			</button>
		</div>

	</td>
</tr>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'hub_webhook_secret' ) ?></td>
	<td>
		<!--<input type="text" name="hub_webhook_secret" maxlength="250" size="40" value="<?php echo string_attribute( $t_hub_webhook_secret ) ?>"/> -->
		<div id="webhook_create" class="sourcegithub_token hidden">
			<div class="space-4"></div>
			<div class="space-4"></div>
			<button type="button" class="btn btn-primary btn-white btn-round btn-sm">
				<?php echo plugin_lang_get( 'webhook_create' ); ?>
			</button>

			<span id="webhook_status">
				<i class="ace-icon fa fa-lg"></i>
				<span></span>
			</span>
		</div>
	</td>
</tr>

<tr>
	<td class="category"><?php echo plugin_lang_get( 'master_branch' ) ?></td>
	<td>
		<input type="text" name="master_branch" maxlength="250" size="40" value="<?php echo string_attribute( $t_master_branch ) ?>"/>
	</td>
</tr>
<?php
	}

	public function update_repo( $p_repo ) {
		$f_tea_root = gpc_get_string( 'tea_root' );
		$f_hub_username = gpc_get_string( 'hub_username' );
		$f_hub_reponame = gpc_get_string( 'hub_reponame' );
		$f_hub_app_client_id = gpc_get_string( 'hub_app_client_id' );
		$f_hub_app_secret = gpc_get_string( 'hub_app_secret' );
		#$f_hub_webhook_secret = gpc_get_string( 'hub_webhook_secret' );
		$f_master_branch = gpc_get_string( 'master_branch' );

		# Clear the access token if client id and secret changed
		if(   $p_repo->info['hub_app_client_id'] != $f_hub_app_client_id
			|| $p_repo->info['hub_app_secret'] != $f_hub_app_secret
		) {
			unset($p_repo->info['hub_app_access_token']);
		}

		$this->validate_branch_list( $f_master_branch );

		$p_repo->info['tea_root'] = $f_tea_root;
		$p_repo->info['hub_username'] = $f_hub_username;
		$p_repo->info['hub_reponame'] = $f_hub_reponame;
		$p_repo->info['hub_app_client_id'] = $f_hub_app_client_id;
		$p_repo->info['hub_app_secret'] = $f_hub_app_secret;
		#$p_repo->info['hub_webhook_secret'] = $f_hub_webhook_secret;
		$p_repo->info['master_branch'] = $f_master_branch;

		return $p_repo;
	}

	private function api_uri( $p_repo, $p_path ) {
		$f_tea_root = $p_repo->info['tea_root'];
		$t_uri = "$f_tea_root/api/v1/".$p_path;
		if( isset( $p_repo->info['hub_app_access_token'] ) ) {
			$t_access_token = $p_repo->info['hub_app_access_token'];
			if ( !is_blank( $t_access_token ) ) {
				$t_uri .= '?access_token='. $t_access_token;
			}
		}
		#trigger_error("t_uri = $t_uri", E_USER_ERROR);
		return $t_uri;
	}

	private function api_json_url( $p_repo, $p_url, $p_member = null ) {
		# Gitea API does not support rate limiting
		/*
		static $t_start_time;
		if ( $t_start_time === null ) {
			$t_start_time = microtime( true );
		} else if ( ( microtime( true ) - $t_start_time ) >= 3600.0 ) {
			$t_start_time = microtime( true );
		}

		$t_uri = $this->api_uri( $p_repo, 'rate_limit' );
		$t_json = json_url( $t_uri, 'rate' );

		if ( false !== $t_json && !is_null( $t_json ) ) {
			if ( $t_json->remaining <= 0 ) {
				// do we need to do something here?
			} else if ( $t_json->remaining < ( $t_json->limit / 2 ) ) {
				$t_time_remaining = 3600.0 - ( microtime( true ) - $t_start_time );
				$t_sleep_time = ( $t_time_remaining / $t_json->remaining ) * 1000000;
				usleep( $t_sleep_time );
			}
		}
		*/
		return json_url( $p_url, $p_member );
	}

	public function precommit() {
		# Check if it the payload comes via eponymous form variable (currently not supported by gitea)
		$f_payload = gpc_get_string( 'payload', null );
		if ( is_null( $f_payload ) ) {
			# If empty, retrieve the webhook's payload from the body, this is the std way for gitea webhooks
			$f_payload = file_get_contents( 'php://input' );
			if ( is_null( $f_payload ) ) {
				return;
			}
		}

		if ( false === stripos( $f_payload, 'gitea' ) ) {
			return;
		}

		$t_data = json_decode( $f_payload, true );
		$t_reponame = $t_data['repository']['name'];

		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "SELECT * FROM $t_repo_table WHERE info LIKE " . db_param();
		$t_result = db_query( $t_query, array( '%' . $t_reponame . '%' ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			return;
		}

		while ( $t_row = db_fetch_array( $t_result ) ) {
			$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
			$t_repo->id = $t_row['id'];

			if ( $t_repo->info['hub_reponame'] == $t_reponame ) {
				# Retrieve the payload's signature from the request headers
				# Reference https://docs.gitea.io/en-us/webhooks/
				$t_signature = null;
				if( array_key_exists( 'HTTP_X_HUB_SIGNATURE', $_SERVER ) ) {
					$t_signature = explode( '=', $_SERVER['HTTP_X_HUB_SIGNATURE'] );
					if( $t_signature[0] != 'sha1' ) {
						# Invalid hash - as per docs, only sha1 is supported
						return;
					}
					$t_signature = $t_signature[1];
				}

				# Validate payload against webhook secret: checks OK if
				# - Webhook secret not defined and no signature received from GitHub, OR
				# - Payload's SHA1 hash salted with Webhook secret matches signature
				$t_secret = $t_repo->info['hub_webhook_secret'];
				$t_valid = ( !$t_secret && !$t_signature )
					|| $t_signature == hash_hmac('sha1', $f_payload, $t_secret);
				if( !$t_valid ) {
					# Invalid signature
					return;
				}

				return array( 'repo' => $t_repo, 'data' => $t_data );
			}
		}

		return;
	}

	public function commit( $p_repo, $p_data ) {
		$t_commits = array();

		foreach( $p_data['commits'] as $t_commit ) {
			$t_commits[] = $t_commit['id'];
		}

		$t_refData = explode( '/', $p_data['ref'], 3 );
		$t_branch = $t_refData[2];

		return $this->import_commits( $p_repo, $t_commits, $t_branch );
	}

	public function import_full( $p_repo ) {
		echo '<pre>';
		$t_username = $p_repo->info['hub_username'];
		$t_reponame = $p_repo->info['hub_reponame'];
		$t_branch = $p_repo->info['master_branch'];
		if ( is_blank( $t_branch ) ) {
			$t_branch = 'master';
		}

		if ($t_branch != '*')
		{
			$t_branches = array_map( 'trim', explode( ',', $t_branch ) );
		}
		else
		{
			#$t_uri = $this->api_uri( $p_repo, "repos/$t_username/$t_reponame/branches" );
			#$t_json = $this->api_json_url( $p_repo, $t_uri );
			$t_json = self::url_get_json( $p_repo, "repos/$t_username/$t_reponame/branches" );
			$t_branches = array();
			#trigger_error("t_json = " . implode(",",$t_json), E_USER_ERROR);
			foreach ($t_json as $t_branch)
			{
				$t_branches[] = $t_branch->name;
				echo "Found branch $t_branch->name ... \n";
			}
			#trigger_error("t_branches = " . implode(",", $t_branches), E_USER_ERROR);
		}
		$t_changesets = array();
		#echo "t_branches = " . var_dump($t_branches) . '\n';
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		foreach( $t_branches as $t_branch ) {
			$t_query = "SELECT parent FROM $t_changeset_table
				WHERE repo_id=" . db_param() . ' AND branch=' . db_param() .
				' ORDER BY timestamp ASC';
			$t_result = db_query( $t_query, array( $p_repo->id, $t_branch ), 1 );
			#echo "t_branch = $t_branch\n";
			$t_commits_full = self::url_get_json( $p_repo, "repos/$t_username/$t_reponame/commits" . '?' . http_build_query(array('sha' => $t_branch)) ); # The API also accepts branch names as filter here
			#echo "t_commits_full = " . var_dump($t_commits_full) . '\n';
			$t_commits = array_column($t_commits_full, 'sha');
			if ( db_num_rows( $t_result ) > 0 ) {
				$t_parent = db_result( $t_result );
				echo "Oldest '$t_branch' branch parent: '$t_parent'\n";

				if ( !empty( $t_parent ) ) {
					$t_commits[] = $t_parent;
				}
			}
			$t_changesets = array_merge( $t_changesets, $this->import_commits( $p_repo, $t_commits, $t_branch ) );
		}
		#echo "t_changesets = " . var_dump($t_changesets);
		echo '</pre>';

		return $t_changesets;
	}

	public function import_latest( $p_repo ) {
		return $this->import_full( $p_repo );
	}

	public function import_commits( $p_repo, $p_commit_ids, $p_branch='' ) {
		static $s_parents = array();
		static $s_counter = 0;

		$t_username = $p_repo->info['hub_username'];
		$t_reponame = $p_repo->info['hub_reponame'];

		if ( is_array( $p_commit_ids ) ) {
			$s_parents = array_merge( $s_parents, $p_commit_ids );
		} else {
			$s_parents[] = $p_commit_ids;
		}

		$t_changesets = array();

		while( count( $s_parents ) > 0 && $s_counter < 200 ) {
			$t_commit_id = array_shift( $s_parents );
			echo "Retrieving $t_commit_id ... ";
			#$t_uri = $this->api_uri( $p_repo, "repos/$t_username/$t_reponame/commits/$t_commit_id" );
			#$t_json = $this->api_json_url( $p_repo, $t_uri );
			$t_json = self::url_get_json( $p_repo, "repos/$t_username/$t_reponame/git/commits/$t_commit_id" );
			#echo "t_json (SHA $t_commit_id) = " . var_dump($t_json);
			if ( false === $t_json || is_null( $t_json ) ) {
				# Some error occured retrieving the commit
				echo "failed.\n";
				continue;
			} else if ( !property_exists( $t_json, 'sha' ) ) {
				echo "failed ($t_json->message).\n";
				continue;
			}

			list( $t_changeset, $t_commit_parents ) = $this->json_commit_changeset( $p_repo, $t_json, $p_branch );
			if ( $t_changeset ) {
				$t_changesets[] = $t_changeset;
			}

			$s_parents = array_merge( $s_parents, $t_commit_parents );
		}

		$s_counter = 0;
		return $t_changesets;
	}

	private function json_commit_changeset( $p_repo, $p_json, $p_branch='' ) {

		echo "processing $p_json->sha ... ";
		if ( !SourceChangeset::exists( $p_repo->id, $p_json->sha ) ) {
			$t_parents = array();
			foreach( $p_json->parents as $t_parent ) {
				$t_parents[] = $t_parent->sha;
			}

			$t_changeset = new SourceChangeset(
				$p_repo->id,
				$p_json->sha,
				$p_branch,
				date( 'Y-m-d H:i:s', strtotime( $p_json->commit->author->date ) ),
				$p_json->commit->author->name,
				$p_json->commit->message
			);

			if ( count( $p_json->parents ) > 0 ) {
				$t_parent = $p_json->parents[0];
				$t_changeset->parent = $t_parent->sha;
			}

			$t_changeset->author_email = $p_json->commit->author->email;
			$t_changeset->committer = $p_json->commit->committer->name;
			$t_changeset->committer_email = $p_json->commit->committer->email;

			if ( isset( $p_json->files ) ) {
				foreach ( $p_json->files as $t_file ) {
					/*
					# Gitea API does not give info about modification type (add, mod, rm)
					switch ( $t_file->status ) {
						case 'added':
							$t_changeset->files[] = new SourceFile( 0, '', $t_file->filename, 'add' );
							break;
						case 'modified':
							$t_changeset->files[] = new SourceFile( 0, '', $t_file->filename, 'mod' );
							break;
						case 'removed':
							$t_changeset->files[] = new SourceFile( 0, '', $t_file->filename, 'rm' );
							break;
					}
					*/
					$t_changeset->files[] = new SourceFile( 0, '', $t_file->filename, 'mod' );
				}
			}

			$t_changeset->save();

			echo "saved.\n";
			return array( $t_changeset, $t_parents );
		} else {
			echo "already exists.\n";
			return array( null, array() );
		}
	}

	private function oauth_authorize_uri( $p_repo ) {
		$t_hub_app_client_id = null;
		$t_hub_app_secret = null;
		$t_hub_app_access_token = null;
		$f_tea_root = $p_repo->info['tea_root'];

		if ( isset( $p_repo->info['hub_app_client_id'] ) ) {
			$t_hub_app_client_id = $p_repo->info['hub_app_client_id'];
		}

		if ( isset( $p_repo->info['hub_app_secret'] ) ) {
			$t_hub_app_secret = $p_repo->info['hub_app_secret'];
		}

		if ( !empty( $t_hub_app_client_id ) && !empty( $t_hub_app_secret ) ) {
			$t_redirect_uri = config_get( 'path' )
				. plugin_page( 'oauth_authorize', true ) . '&'
				. http_build_query( array( 'id' => $p_repo->id ) );
			$t_param = array(
				'client_id' => $t_hub_app_client_id,
				'redirect_uri' => $t_redirect_uri,
				'response_type' => 'code'						# Gitea does not support scopes and shall give access to all ressources and organizations of a user
			);

			return "$f_tea_root/login/oauth/authorize?" . http_build_query( $t_param );
		} else {
			return '';
		}
	}

	public static function oauth_get_access_token( $p_repo, $p_code, $p_refresh = false ) {
		# build the GitTea URL & POST data
		$f_tea_root = $p_repo->info['tea_root'];
		$t_url = "$f_tea_root/login/oauth/access_token";
		#trigger_error("oauth_get_access_token", E_USER_ERROR);
		$t_redirect_uri = config_get('path')
			. plugin_page( 'oauth_authorize', true) . '&'
			. http_build_query( array( 'id' => $p_repo->id ) );
		if( $p_refresh === true) {
			$t_post_data = array( 'client_id' => $p_repo->info['hub_app_client_id'],
			'client_secret' => $p_repo->info['hub_app_secret'],
			'refresh_token' => $p_code,
			'grant_type' => 'refresh_token',
			'redirect_uri' => $t_redirect_uri );
		}
		else {
			$t_post_data = array( 'client_id' => $p_repo->info['hub_app_client_id'],
				'client_secret' => $p_repo->info['hub_app_secret'],
				'code' => $p_code,
				'grant_type' => 'authorization_code',
				'redirect_uri' => $t_redirect_uri );
		}
		#trigger_error("url = $t_url, data = ". implode(", ", $t_post_data ), E_USER_ERROR);
		$t_data = self::url_post( $t_url, $t_post_data );
		$t_access_token = '';
		if ( !empty( $t_data ) ) {
			#$t_response = array();
			#parse_str( $t_data, $t_response );
			$t_response = json_decode($t_data, true);
			#trigger_error("t_response = $t_response", E_USER_ERROR);
			if ( array_key_exists( 'access_token', $t_response) === true ) {
				$t_access_token = $t_response['access_token'];
			}
			else {
				#trigger_error("Error get access token failed", E_USER_ERROR);
			}
			if ( array_key_exists( 'expires_in', $t_response) === true ) {
				$p_repo->info['expires_in'] = $t_response['expires_in'];
			}
			if ( array_key_exists('refresh_token', $t_response) === true ) {
				$p_repo->info['refresh_token'] = $t_response['refresh_token'];
			}
		}

		if ( !empty( $t_access_token ) ) {
			if( !array_key_exists( 'hub_app_access_token', $p_repo->info )
				|| $t_access_token != $p_repo->info['hub_app_access_token']
			) {
				$p_repo->info['hub_app_access_token'] = $t_access_token;
				$p_repo->info['authorization_time'] = time();
				$p_repo->save();
			}
			return true;
		} else {
			#trigger_error("t_data = $t_data", E_USER_ERROR);
			return false;
		}
	}

	public static function url_post( $p_url, $p_post_data ) {
		$t_post_data = http_build_query( $p_post_data );

		# Use the PHP cURL extension
		if( function_exists( 'curl_init' ) ) {
			$t_curl = curl_init( $p_url );
			curl_setopt( $t_curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $t_curl, CURLOPT_POST, true );
			curl_setopt( $t_curl, CURLOPT_POSTFIELDS, $t_post_data );

			$t_data = curl_exec( $t_curl );
			curl_close( $t_curl );

			return $t_data;
		} else {
			# Last resort system call
			$t_url = escapeshellarg( $p_url );
			$t_post_data = escapeshellarg( $t_post_data );
			return shell_exec( 'curl ' . $t_url . ' -d ' . $t_post_data );
		}
	}

	public static function url_post_json( $p_url, $p_post_data ) {
		$t_post_data = json_encode($p_post_data);

		# Use the PHP cURL extension
		if( function_exists( 'curl_init' ) ) {
			$t_curl = curl_init( $p_url );
			curl_setopt($t_curl, CURLOPT_HTTPHEADER, array("Content-Type:application/json", "accept: application/json"));
			curl_setopt( $t_curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $t_curl, CURLOPT_POST, true );
			curl_setopt( $t_curl, CURLOPT_POSTFIELDS, $t_post_data );

			$t_data = curl_exec( $t_curl );
			curl_close( $t_curl );

			return $t_data;
		} else {
			# Last resort system call
			$t_url = escapeshellarg( $p_url );
			$t_post_data = escapeshellarg( $t_post_data );
			return shell_exec( 'curl ' . $t_url . ' -d ' . $t_post_data );
		}
	}

	# Check if an oauth access token has timed out and a new one has to be requested via the refresh token
	# @param: Source::repo
	public static function check_oauth_timeout( $p_repo ) {
		if( array_key_exists( 'expires_in', $p_repo->info ) ) {
			$t_expireTime = $p_repo->info['expires_in'];
			if(time() - $p_repo->info['authorization_time'] > $t_expireTime) {
				# Access token expired...
				return true;
			}
			else
			{
				# Access token not expired. Shall be valid
				return false;
			}
		}
		return true;
	}

	# Direct json GET from API with access token via http cURL header
	# @param: Source::repo
	# @param: String url path to the api location
	public static function url_get_json( $p_repo, $p_path ) {
		
		if (self::check_oauth_timeout( $p_repo ) ) {
			echo "Authorization token timed out... renew\n";
			if ( array_key_exists('refresh_token', $p_repo->info) === true ) {
				$t_authorized = self::oauth_get_access_token( $p_repo, $p_repo->info['refresh_token'], true);
				if(!$t_authorized)
				{
					echo "Error access token refresh via oath failed\n";
				}
			}
			else
			{
				echo "Error refresh token not found in repo configuration\n";
			}
		}
		
		$f_tea_root = $p_repo->info['tea_root'];
		$t_uri = "$f_tea_root/api/v1/$p_path";
		if( isset( $p_repo->info['hub_app_access_token'] ) ) {
			$t_access_token = $p_repo->info['hub_app_access_token'];
			$auth_data = array (
				'accept: application/json',
				"Authorization: token $t_access_token",
				'Content-Type: application/json'
			);
		}
		else
		{
			echo "Error access_token not found, check authentication\n";
		}
		# Use the PHP cURL extension
		if( function_exists( 'curl_init' ) ) {
			$t_curl = curl_init( $t_uri );
			curl_setopt($t_curl, CURLOPT_URL, $t_uri);
			curl_setopt($t_curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($t_curl, CURLOPT_HTTPHEADER, $auth_data);	# Pass authentication data in header
			curl_setopt( $t_curl, CURLOPT_POST,  false);
			$result = curl_exec($t_curl);
			curl_close($t_curl);
			$t_obj = json_decode($result);
			return $t_obj;
		} else {
			# Last resort system call
			$t_url = escapeshellarg( $t_uri );
			$t_return = shell_exec( 'curl -X GET' . $t_url .' -H ' . '"accept: application/json"' . ' -H ' . '"' ."Authorization: token ".$t_access_token . '"' .' -H ' . 'Content-Type: application/json' );
			return json_decode($t_return);
		}
	}
}
