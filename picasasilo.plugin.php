<?php

/*
 * Picasa API interface
 */
class PicasaAPI
{
	// set query urls
	protected static $QUERY_URLS = array(
																			 'auth' => "https://www.google.com/accounts/AuthSubRequest?scope=http%3A%2F%2Fpicasaweb.google.com%2Fdata%2F&session=1&secure=0&next=",
																			 'auth_sub_session' => "https://www.google.com/accounts/AuthSubSessionToken",
																			 'picasa' => 'http://picasaweb.google.com/data/feed/api/user/default',
																			 'picasa_secure' => 'https://picasaweb.google.com/data/feed/api/user/default');


	/**
	 * Execute a call to the Picasa Service
	 *
	 * @param $url string The destination url
	 * @param $args array Optional Extra arguments
	 * @param $post_data Optional Post data
	 * @return mixed false on error or xml string
	 */
	public function call($url, $args = array(), $post_data = '')
	{
		$token =	Options::get('picasa_token_' . User::identify()->id);

		$default_args = array('method' => 'GET');
		$args = array_merge($default_args, $args);

		$request = new RemoteRequest($url, $args['method']);

		// set authorisation header
 		$request->add_header(array("Authorization" => "AuthSub token=" .	$token));

		// add extra headers
		if ( isset( $args['http_headers'] ) ) {
			foreach($args['http_headers'] as $key => $value) {
				$request->add_header(array($key => $value));
			}
		}

		if($post_data != '')
			$request->set_body($post_data);

		$request->set_timeout(30);

		// execute request
		$result = $request->execute();

		if(Error::is_error($result))
			return $result;
		

		// get the response if executed
		if($request->executed())
			$response = $request->get_response_body();

		if(!$response)
			return Error::raise('API call failure', E_USER_WARNING);


		// parse the result
		try
		{
			$xml = new SimpleXMLElement($response);
			return $xml;
		}
		catch(Exception $e) 
		{
			Session::error('Currently unable to connect to the Picasa API.', 'Picasa API');
			return false;
		}
	}

	/**
	 * Exchange AuthSub token for a session one
	 *
	 * @param string $token The AuthSub token
	 */
	public function exchange_token($token)
	{
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, (PicasaAPI::$QUERY_URLS['auth_sub_session']));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FAILONERROR, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: AuthSub token="' . $token . '"'));

		$result = curl_exec($ch);
		curl_close($ch);

		// store the session token
		$parsed_result = explode("=", $result);
		Options::set('picasa_token_' . User::identify()->id, $parsed_result[1]);
	}

	/*
	 * Return query url for AuthSub request
	 */
	public function get_auth_url()
	{
		return PicasaAPI::$QUERY_URLS['auth'];
	}
}


/**
 * Picasa class
 * 
 * Provides a set of operations for the Picasa API.
 */
class Picasa extends PicasaAPI
{

	/**
	 * Return the list of albums
	 *
	 * @return string xml string representation of the albums
	 */
	public function get_albums()
	{
		$xml = $this->call(PicasaAPI::$QUERY_URLS['picasa'] . "?kind=album&alt=rss&prettyprint=true");
		return $xml;
	}

	/**
	 * Return a list of photos by most recent, album or tag
	 *
	 * @param array $args Possible values are 'album', 'tag'.
	 * @return string xml string representation of the photos
	 */
	public function get_photos($args = array())
	{
		if(empty($args))
		{
			$xml = $this->call(PicasaAPI::$QUERY_URLS['picasa'] . "?kind=photo&max-results=10");
		}
		elseif($args['album'])
		{
			$xml = $this->call(PicasaAPI::$QUERY_URLS['picasa'] . "/albumid/" . $args['album'] . "?prettyprint=true");
		}
		elseif($args['tag'])
		{
			$xml = $this->call(PicasaAPI::$QUERY_URLS['picasa'] . "?kind=photo&tag=" . $args['tag'] . "&prettyprint=true");
		}
		return $xml;
	}

	/**
	 * Return the list of tags
	 *
	 * @return string xml string representation of the tags
	 */
	public function get_tags()
	{
		$xml = $this->call(PicasaAPI::$QUERY_URLS['picasa'] . "?kind=tag");
		return $xml;
	}

	/**
	 * Create a new album
	 *
	 * @param array $args Album properties such as name, summary etc...
	 * @return mixed An xml string of the result or false on error
	 */
	public function create_album($args)
	{
		$date = explode("/", $args['date']);

		// timestamp has to be multiplied by 1000 as the Picasa API requires milliseconds
		// and mktime returns seconds. Also need to add 1 to the day for some reason
		$timestamp = mktime(0, 0, 0, $date[1], (int)$date[0] + 1, $date[2]) * 1000;

    $entry = "<entry xmlns='http://www.w3.org/2005/Atom'
			xmlns:media='http://search.yahoo.com/mrss/'
			xmlns:gphoto='http://schemas.google.com/photos/2007'>
			<title type='text'>" . $args['name'] . "</title>
			<summary type='text'>" . $args['summary'] . "</summary>
			<gphoto:location>" . $args['location'] . "</gphoto:location>
			<gphoto:access>" . $args['access'] . "</gphoto:access>
			<gphoto:timestamp>" . $timestamp . "</gphoto:timestamp>
			<media:group>
				<media:keywords>" . $args['keywords'] . "</media:keywords>
			</media:group>
			<category scheme='http://schemas.google.com/g/2005#kind'
								term='http://schemas.google.com/photos/2007#album'></category>
		</entry>";
	

		$result = $this->call(PicasaAPI::$QUERY_URLS['picasa'],
													array(
																'method' => 'POST',
																'http_headers' => array('Content-Type' => 'application/atom+xml')),
													$entry);

		if(Error::is_error($result))
			return $result->get();
		else
			return "Album successfuly created";
	}

	/**
	 * Upload a photo
	 *
	 * @param string $file_url Temporary URL of uploaded file
	 * @param string $album The album id
	 * @param string title The title of the photo
	 * @return mixed An xml string of the result or false on error
	 */
	public function upload_photo($file_url, $album, $title, $summary)
	{
		$photo .= 'Media multipart posting' . "\n";
		$photo .= '--END_OF_PART' . "\n";
		$photo .= 'Content-Type: application/atom+xml' . "\n\n"; // need two end of lines

		$photo .= '<entry xmlns="http://www.w3.org/2005/Atom">' . "\n";
		$photo .= '<title>' . $title . '</title>' . "\n";
		$photo .= '<summary>' . $summary . '</summary>' . "\n";
		$photo .= '<category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/photos/2007#photo"/>' . "\n";
		$photo .= '</entry>' . "\n";

		$photo .= '--END_OF_PART' . "\n";
		$photo .= 'Content-Type: image/jpeg' . "\n\n"; // need to end of lines

		$photo .= file_get_contents($file_url);

		$photo .= "\n" . '--END_OF_PART--' . "\n";

	  $result = $this->call(PicasaAPI::$QUERY_URLS['picasa'] . "/album/" . $album,
													array('method' => 'POST',
																'http_headers' => array('Content-Type' => 'multipart/related; boundary="END_OF_PART"',
																										 'MIME-version' => '1.0')),
													$photo);

		if(Error::is_error($result))
			return $result->get();
		else
			return "Photo successfuly uploaded";
	}
}


/**
 * PicasaSilo class
 */
class PicasaSilo extends Plugin implements MediaSilo
{
	const SILO_NAME = 'Picasa';

	public function action_init()
	{
//		Stack::add('admin_stylesheet',  $this->get_url(true) . 'admin.css', 'picasa-silo-admin-css', 'admin');
		Stack::add('admin_stylesheet',  array( $this->get_url(true) . 'admin.css', 'screen' ), 'picasa-silo-admin-css', 'admin');
	}

	public function silo_info()
	{
		if($this->is_auth()) 
			return array('name' => self::SILO_NAME, 'icon' => URL::get_from_filesystem(__FILE__) . '/icon.png');
		else
			return array();
	}

	/**
	* Return directory contents for the silo path
	*
	* @param string $path The path to retrieve the contents of
	* @return array An array of MediaAssets describing the contents of the directory
	*/
	public function silo_dir($path)
	{
		$path_elements = explode("/", $path);
		$type = $path_elements[0];
		$size = Options::get('picasasilo__picasa_size');
		$picasa = new Picasa();

		$results = array();

		switch($type)
		{
		case 'albums':
			{
				$xml = $picasa->get_albums();

				foreach($xml->channel->item as $album)
				{
					$media = $album->children('http://search.yahoo.com/mrss/');
					$photo = $album->children('http://schemas.google.com/photos/2007');

					$props['title'] = (string)$album->title;

					$results[] = new MediaAsset(self::SILO_NAME . '/photos/album/' . $photo->id,
																			true,
																			$props);
				}
			}
			break;

		case 'photos':
			{
				$xml = $picasa->get_photos(array($path_elements[1] => $path_elements[2]));

				foreach($xml->entry as $photo)
				{
					$media = $photo->children('http://search.yahoo.com/mrss/');

					$props['filetype'] = 'picasa';
					$props['thumbnail_url'] = (string)$media->group->thumbnail->attributes()->url;
					$props['title'] = (string)$media->group->title;
					//$props['filetype'] = str_replace("/", "_", $photo->content->attributes()->type);
					//Utils::debug($photo->content->attributes()->type);
					//Add the desired size to the url
					$src = (string)$photo->content->attributes()->src;
					$props['url'] = substr($src,0,strrpos($src,'/'))."/$size".substr($src,strrpos($src,'/'));
					$props['picasa_url'] = $src;
					
					$results[] = new MediaAsset(self::SILO_NAME . '/photos/' . $path_elements[1] . '/' . $media->group->title,
																			false,
																			$props);
					
				}
			}
			break;

		case 'recent':
			{
				$xml = $picasa->get_photos();

				foreach($xml->entry as $photo)
				{
					$media = $photo->children('http://search.yahoo.com/mrss/');

					$props['filetype'] = 'picasa';
					$props['thumbnail_url'] = (string)$media->group->thumbnail->attributes()->url;
					$props['title'] = (string)$media->group->title;
					//$props['filetype'] = str_replace("/", "_", $photo->content->attributes()->type);
					//Add the desired size to the url
					$src = (string)$photo->content->attributes()->src;
					$props['url'] = substr($src,0,strrpos($src,'/'))."/$size".substr($src,strrpos($src,'/'));
					$props['picasa_url'] = $src;

					$results[] = new MediaAsset(self::SILO_NAME . '/photos/' . '/' . $media->group->title,
																			false,
																			$props);
					
				}
			}
			break;

		case 'tags':
			{
				$xml = $picasa->get_tags();

				foreach($xml->entry as $tag)
				{
					$props['title'] = (string)$tag->title;

					$results[] = new MediaAsset(self::SILO_NAME . '/photos/tag/' . (string)$tag->title,
																			true,
																			$props);
				}
			}
			break;

		case '':
			{
				$results[] = new MediaAsset(self::SILO_NAME . '/albums',
																		true,
																		array('title' => 'Albums'));

				$results[] = new MediaAsset(self::SILO_NAME . '/recent',
																		true,
																		array('title' => 'Recently Uploaded'));

				$results[] = new MediaAsset(self::SILO_NAME . '/tags',
																		true,
																		array('title' => 'Tags'));

			}
			break;
		}

		return $results;
	}

 	/**
	* Get the file from the specified path
	*
	* @param string $path The path of the file to retrieve
	* @param array $qualities Qualities that specify the version of the file to retrieve.
	* @return MediaAsset The requested asset
	*/
	public function silo_get($path, $qualities = null)
	{
	}

	/**
	* Get the direct URL of the file of the specified path
	*
	* @param string $path The path of the file to retrieve
	* @param array $qualities Qualities that specify the version of the file to retrieve.
	* @return string The requested url
	*/
	public function silo_url($path, $qualities = null)
	{

	}

	/**
	* Create a new asset instance for the specified path
	*
	* @param string $path The path of the new file to create
	* @return MediaAsset The requested asset
	*/
	public function silo_new($path)
	{
	}

	/**
	* Store the specified media at the specified path
	*
	* @param string $path The path of the file to retrieve
	* @param MediaAsset $ The asset to store
	*/
	public function silo_put($path, $filedata)
	{
	}

	/**
	* Delete the file at the specified path
	*
	* @param string $path The path of the file to retrieve
	*/
	public function silo_delete($path)
	{
	}


	/**
	* Retrieve a set of highlights from this silo
	* This would include things like recently uploaded assets, or top downloads
	*
	* @return array An array of MediaAssets to highlihgt from this silo
	*/
	public function silo_highlights()
	{
	}

	/**
	* Retrieve the permissions for the current user to access the specified path
	*
	* @param string $path The path to retrieve permissions for
	* @return array An array of permissions constants (MediaSilo::PERM_READ, MediaSilo::PERM_WRITE)
	*/
	public function silo_permissions($path)
	{
	}

	/*
	 * Add Authorisation or De-authorisation actions
	 *
	 * @param array $actions List of plugin actions
	 * @param int $plugin_id Plugin ID
	 * @return array An updated array of plugin actions
	 */
	public function filter_plugin_config($actions, $plugin_id)
	{
		if($plugin_id == $this->plugin_id)
		{
			$picasa_ok = $this->is_auth();

			if($picasa_ok)
				$actions[] = _t('De-Authorize');
			else
				$actions[] = _t('Authorize');
		}
		$actions[] = _t('Configure');

		return $actions;
	}

	/**
	 * Produce a link for the media control bar that causes a specific path to be displayed
	 *
	 * @param string $path The path to display
	 * @param string $title The text to use for the link in the control bar
	 * @return string The link to create
	 */
	public function link_path($path, $title = '')
	{
		if($title == '')
			$title = basename($path);

		return '<a href="#" onclick="habari.media.showdir(\''.$path.'\');return false;">' . $title . '</a>';
	}

	/**
	 * Produce a link for the media control bar that causes a specific panel to be displayed
	 *
	 * @param string $path The path to pass
	 * @param string $path The panel to display
	 * @param string $title The text to use for the link in the control bar
	 * @return string The link to create
	 */
	public function link_panel($path, $panel, $title)
	{
		return '<a href="#" onclick="habari.media.showpanel(\''.$path.'\', \''.$panel.'\');return false;">' . $title . '</a>';
	}

	/**
	 * Provide controls for the media control bar
	 *
	 * @param array $controls Incoming controls from other plugins
	 * @param MediaSilo $silo An instance of a MediaSilo
	 * @param string $path The path to get controls for
	 * @param string $panelname The name of the requested panel, if none then emptystring
	 * @return array The altered $controls array with new (or removed) controls
	 *
	 * @todo This should really use FormUI, but FormUI needs a way to submit forms via ajax
	 */
	public function filter_media_controls($controls, $silo, $path, $panelname)
	{
		$class = __CLASS__;

		if($silo instanceof $class) 
		{
			if(User::identify()->can('upload_media')) 
				$controls[] = $this->link_panel(self::SILO_NAME . '/', 'upload', 'Upload');

			if(User::identify()->can('create_directories'))
				$controls[] = $this->link_panel(self::SILO_NAME . '/', 'new-album', 'Create Album');
		}

		return $controls;
	}

	/**
	 * Display the different media panels
	 *
	 * @param string $panel HTML content to be output
	 * @param object $silo Silo object
	 * @param string $path Current silo path
	 * @param string $panelname Curren panel name
	 * @return string The modified panel output
	 */
	public function filter_media_panels($panel, $silo, $path, $panelname)
	{
		$class = __CLASS__;
		if($silo instanceof $class)
		{
			switch($panelname)
			{
			case 'new-album':
				$fullpath = self::SILO_NAME . '/' . $path;

				$form = new FormUI('picasasilo-newalbum');

				$today = date('d/m/Y', time());

				// first column
				$form->append('static', 'col1', '<div class="column">');

				$form->append('text', 'album_name', 'null:unused', 'Album name');
				$form->append('textarea', 'album_summary', 'null:unused', 'Summary');
				$form->append('text', 'album_date', 'null:unused', 'Date');
				$form->album_date->value = $today;

				$form->append('static', 'col2', '</div>');

				// second column
				$form->append('static', 'col3', '<div class="column">');

				$form->append('text', 'album_location', 'null:unused', 'Location');
				$form->append('select', 'album_visibility', 'null:unused', 'Visibility');
				$form->album_visibility->options = array('public' => 'Public', 'private' => 'Anyone with the link', 'protected' => 'Private');

				$form->append('static', 'col4', '</div>');

				// clear columns
				$form->append('static', 'colend', '<br style="clear: both">');

				$form->append('submit', 'create-album', 'Create');

				$form->media_panel($fullpath, $panelname, 'habari.media.forceReload();');
				$form->on_success(array($this, 'create_album'));

				$panel = $form->get();
				return $panel;

				break;

			case 'upload':
				if(isset($_FILES['upload_file']))
				{
					$panel = $this->upload_photo();
				}
				else
				{
					$fullpath = self::SILO_NAME . '/' . $path;
					$action = URL::get('admin_ajax', array('context' => 'media_panel'));
					$picasa = new Picasa();

					// collect album names
					$xml = $picasa->get_albums();
					foreach($xml->channel->item as $album)
					{
						$title = (string)$album->title;
						$options .= "<option value='" . $title . "'>" . $title . "</option>";
					}

					// create a form that sends the request to an IFrame (as done in the Habari Silo)
					$static = <<< PICASA_UPLOAD
						<form enctype="multipart/form-data" method="post" id="picasasilo-upload" target="picasa_upload_frame" action="{$action}" class="span-10" style="margin:0px auto;" target="picasa_upload_frame">

						  <div class="formcontrol"><input type="file" name="upload_file"></div>
  						<div class="formcontrol">
								<label for="upload">Album:</label>
								<select name="upload_album">{$options}</select>
							</div>
							<div class="formcontrol">
								<label for="summary">Summary:</label>
								<textarea name="summary"></textarea>
							</div>
						  <div class="formcontrol"><input type="submit" name="upload" value="Upload"></div>
						  <input type="hidden" name="path" value="{$fullpath}">
						  <input type="hidden" name="panel" value="{$panelname}">
						  
            </form>

						<iframe id="picasa_upload_frame" name="picasa_upload_frame" style="width:1px;height:1px;" onload="picasa_uploaded();">
						</iframe>

						<script type="text/javascript">
								var responsedata;
					      function picasa_uploaded() 
								{
									if(!jQuery('#picasa_upload_frame')[0].contentWindow)return;
									var response = jQuery(jQuery('#picasa_upload_frame')[0].contentWindow.document.body).text();
									if(response) 
									{
										eval('responsedata = ' + response);
										window.setTimeout(picasa_uploaded_complete, 500);
									}
								}

								function picasa_uploaded_complete() 
								{
									habari.media.jsonpanel(responsedata);
								}
						</script>
PICASA_UPLOAD;

					$panel = $static;
				}

				return $panel;
				
				break;
			}
		}
	}

	/**
	 * Create a new album based on values in the form
	 *
	 * @param $form object FormUI object
	 * @return string HTML that displays feedback message
	 */
	public function create_album($form)
	{
		$picasa = new Picasa();

		$args['name'] = $form->album_name;
		$args['summary'] = $form->album_summary;
		$args['date'] = $form->album_date;
		$args['location'] = $form->album_location;
		$args['visibilty'] = $form->album_visibility;

		$result = $picasa->create_album($args);

		return "<span>" . $result . "</span>";
	}

	/**
	 * Upload a photo
	 *
	 * @param $form object FormUI object
	 * @return string HTML that displays feedback message
	 */
	public function upload_photo()
	{
		$temp_file = $_FILES['upload_file']['tmp_name'];
		$album = $_POST['upload_album'];
		$summary = $_POST['summary'];
		$filename = $_FILES['upload_file']['name'];

		$picasa = new Picasa();
		$result = $picasa->upload_photo($temp_file, $album, $filename, $summary);

		if(!$result)
			return "<span>Error uploading photo</span>";
		else
			return "<span>Photo successfuly uploaded</span>";
	}

	/**
	 * Determine if the user has granted authorization to his/her Picasa Account
	 *
	 * @return boolean true if authorised, false otherwise
	 */
	public function is_auth()
	{
		static $authorized = null;

		if(isset($authorized))
			return $authorized;

		$authorized = false;
		$token = Options::get('picasa_token_' . User::identify()->id);

		if($token != null)
			$authorized = true;

		return $authorized;
	}

	/**
	 * Perform plugin operation
	 *
	 * @param int $plugin_id The plugin id
	 * @param string $action The action to perform
	 */
	public function action_plugin_ui($plugin_id, $action)
	{
		$confirm_url = rawurlencode(URL::get('admin',
																				 array('page' => 'plugins',
																							 'configure' => $this->plugin_id(),
																							 'configaction' => 'Confirm')) . '#plugin_options');

		$picasa = new Picasa();
		$auth_url = $picasa->get_auth_url() . $confirm_url;

		$deauth_url = URL::get('admin',
													 array('page' => 'plugins',
																 'configure' => $this->plugin_id(),
																 'configaction' => 'De-Authorize')) . '#plugin_options';

		if($plugin_id == $this->plugin_id)
		{
			switch($action)
			{
			case _t('Authorize'):
				if($this->is_auth())
				{
					echo "<p>"._t("This installation has already been authorized to access your Picasa account.")."</p>";
					echo "<p><a href='" . $deauth_url . "' title='"._t("De-Authorize")."'>"._t("De-Authorize access to your Picasa account")."</p>";					
				}
				else
				{
					echo "<p>"._t("You have not authorized access to your Picasa account")."</p>";
					echo "<p><a href='" . $auth_url . "' target='_blank'>"._t("Authorize")."</a> "._t("your Habari installation to access your Picasa account")."</p>";
				}
				break;

			case 'Confirm':
				{
					if(!isset($_GET['token']))
					{
						echo "<p>"._t("Your account has not been authorized access to this installation.")."<p>";
						echo "<p><a href='" . $auth_url . "' target='_blank'>"._t("Authorize")."</a> "._t("your Habari installation to access your Picasa account.")."</p>";
					}
					else
					{
						$token = $_GET['token'];
						$picasa->exchange_token($token);

						echo "<p>"._t("Your authorization was successful.")."</p>";
						echo "<p><a href='" . $deauth_url . "' title='". _t("De-Authorize") . "'>" . _t("De-Authorize access to your Picasa account") . "</p>";
					}
				}
				break;

			case _t('De-Authorize'):
				{
					Options::delete('picasa_token_' . User::identify()->id, $token);

					echo "<p>"._t("De-Authorization successful. This installation will no longer be able to access your Picasa account.")."</p>";
					echo "<p><a href='" . $auth_url . "' title='"._t("Authorize")."' target='_blank'>"._t("Authorize")."</a> "._t("your Habari installation to access your Picasa account")."</p>";
				}
				break;
			case _t('Configure') :
				$ui = new FormUI( strtolower( get_class( $this ) ) );
				$ui->append( 'select', 'picasa_size','option:picasasilo__picasa_size', _t( 'Default size for images in Posts:' ) );
				//I did not _t() the following as it should be replaced. Picasa supports all sizes up to original size.
				$ui->picasa_size->options = array( 's75' => 'Square (75x75)', 's100' => 'Thumbnail (100px)', 's240' => 'Small (240px)', 's500' => 'Medium (500px)', 's1024' => 'Large (1024px)', '' => 'Original Size' );
				$ui->append('submit', 'save', _t( 'Save' ) );
				$ui->set_option('success_message', _t('Options saved'));
				$ui->out();
				break;
			}
		}
	}

		public function action_admin_footer($theme) 
	{
		echo <<< PICASA
				<script type="text/javascript">
				
				habari.media.output.picasa = 
				{
				  insert_image: function(fileindex, fileobj)
					{
						habari.editor.insertSelection('<a href="' + fileobj.picasa_url  + '"><img class="picasaimg" src="' + fileobj.url + '" /></a>');
					}
				}

        habari.media.preview.picasa = function(fileindex, fileobj) 
				{
					//this does not work yet!
					var stats = '';
					var out = '';

					// CRAP
					// out += '<a href="#" onclick="habari.media.showdir(\'Picasa/photos/' + fileobj.picasa_id[0]  + '\'); return false;">';
					
					out += '<div class="mediatitle">' + fileobj.title + '</div>';
					out += '<img src="' + fileobj.thumbnail_url + '" /><div class="mediastats"> ' + stats + '</div>';
					out += '</a>';
					return out;
				}
    </script>
PICASA;
	}
}


?> 
